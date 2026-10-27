<?php
/**
 * billing-adjustments.php - Billing Adjustments (module 17)
 * Corrects billing errors without touching the original charges: an adjustment request records the reason,
 * the amount and a snapshot of the invoice total before the change. Every adjustment needs manager approval,
 * and nobody can approve their own request. Approved adjustments change the invoice total; the history is kept.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission(['adjustments.request', 'adjustments.approve']);

$pdo = db();
$me  = current_user();
$canRequest = has_permission('adjustments.request');
$canApprove = has_permission('adjustments.approve');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        $pdo->beginTransaction();

        if ($action === 'request') {
            if (!$canRequest) { throw new RuntimeException('Your role cannot request adjustments.'); }
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $type      = (string)($_POST['adjustment_type'] ?? '');
            $amount    = parse_money($_POST['amount'] ?? '');
            $reason    = clean_text($_POST['reason'] ?? '', 255);
            if (!in_array($type, ['increase', 'decrease'], true)) { throw new RuntimeException('Choose increase or decrease.'); }
            if ($amount === null) { throw new RuntimeException('Enter a valid adjustment amount.'); }
            if (mb_strlen($reason) < 5) { throw new RuntimeException('Explain the billing error (at least 5 characters).'); }
            $inv = db_one('SELECT invoice_id, invoice_number, total_amount, period_id, status FROM invoices WHERE invoice_id = ? FOR UPDATE', [$invoiceId]);
            if (!$inv || in_array($inv['status'], ['draft', 'cancelled'], true)) { throw new RuntimeException('Choose an issued invoice that is not cancelled.'); }
            require_open_period_id((int)$inv['period_id']);
            if ($type === 'decrease') {
                $bal = invoice_balance($invoiceId);
                if ($amount > (float)$bal['outstanding_balance'] + 0.004) {
                    throw new RuntimeException('A decrease cannot be more than the unpaid balance (' . peso($bal['outstanding_balance']) . '). Use a refund for money already paid.');
                }
            }
            $pdo->prepare("INSERT INTO billing_adjustments (invoice_id, adjustment_type, amount, reason, invoice_total_before, status, requested_by)
                           VALUES (?, ?, ?, ?, ?, 'pending', ?)")->execute([$invoiceId, $type, $amount, $reason, $inv['total_amount'], (int)$me['id']]);
            $id = (int)$pdo->lastInsertId();
            audit_log('adjustment_requested', 'billing_adjustments', $id, null,
                ['invoice' => $inv['invoice_number'], 'type' => $type, 'amount' => $amount, 'total_before' => $inv['total_amount']], $reason);
            $pdo->commit();
            set_flash('success', 'Adjustment request was sent for manager approval.');

        } elseif ($action === 'approve' || $action === 'reject') {
            if (!$canApprove) { throw new RuntimeException('Your role cannot approve adjustments.'); }
            $notes = clean_text($_POST['notes'] ?? '', 255);
            $a = db_one('SELECT a.*, i.invoice_number, i.period_id, i.status AS invoice_status FROM billing_adjustments a
                         JOIN invoices i ON i.invoice_id = a.invoice_id WHERE a.adjustment_id = ? FOR UPDATE', [(int)($_POST['adjustment_id'] ?? 0)]);
            if (!$a) { throw new RuntimeException('Adjustment not found.'); }
            if ($a['status'] !== 'pending') { throw new RuntimeException('This adjustment was already ' . $a['status'] . '.'); }
            require_open_period_id((int)$a['period_id']);
            if ($action === 'approve') {
                if ((int)$a['requested_by'] === (int)$me['id']) { throw new RuntimeException('You requested this adjustment, so another manager must approve it.'); }
                if ($a['invoice_status'] === 'cancelled') { throw new RuntimeException('The invoice is cancelled. Reject this adjustment.'); }
                if ($a['adjustment_type'] === 'decrease') {
                    $bal = invoice_balance((int)$a['invoice_id']);
                    if ((float)$a['amount'] > (float)$bal['outstanding_balance'] + 0.004) {
                        throw new RuntimeException('Approving would make the invoice overpaid. The unpaid balance is only ' . peso($bal['outstanding_balance']) . '.');
                    }
                }
                $pdo->prepare("UPDATE billing_adjustments SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE adjustment_id = ?")->execute([(int)$me['id'], (int)$a['adjustment_id']]);
                $t = recalc_invoice_total((int)$a['invoice_id']);
                audit_log('adjustment_approved', 'billing_adjustments', $a['adjustment_id'], ['total_amount' => $a['invoice_total_before']],
                    ['invoice' => $a['invoice_number'], 'type' => $a['adjustment_type'], 'amount' => $a['amount'], 'total_amount' => $t['total']], $notes !== '' ? $notes : $a['reason']);
                $msg = 'Adjustment approved. ' . $a['invoice_number'] . ' total is now ' . peso($t['total']) . '.';
            } else {
                if (mb_strlen($notes) < 3) { throw new RuntimeException('Enter a reason for rejecting the adjustment.'); }
                $pdo->prepare("UPDATE billing_adjustments SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE adjustment_id = ?")->execute([(int)$me['id'], (int)$a['adjustment_id']]);
                audit_log('adjustment_rejected', 'billing_adjustments', $a['adjustment_id'], ['status' => 'pending'], ['status' => 'rejected', 'invoice' => $a['invoice_number']], $notes);
                $msg = 'Adjustment rejected. The invoice was not changed.';
            }
            $pdo->commit();
            set_flash($action === 'approve' ? 'success' : 'warning', $msg);
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The action could not be completed. Please try again.');
    }
    $ret = ltrim((string)($_POST['return'] ?? ''), '?');
    redirect('billing-adjustments.php' . ($ret !== '' ? '?' . $ret : ''));
}

/* ------------------------------ Filters ------------------------------ */
$stat = (string)($_GET['status'] ?? '');
$q    = clean_text($_GET['q'] ?? '', 60);
$from = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$to   = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
$page = current_page_number();
$perPage = 15;
$where = ['1 = 1']; $params = [];
if (in_array($stat, ['pending', 'approved', 'rejected'], true)) { $where[] = 'a.status = ?'; $params[] = $stat; }
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(i.invoice_number LIKE ? OR g.full_name LIKE ? OR a.reason LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($from !== '') { $where[] = 'DATE(a.created_at) >= ?'; $params[] = $from; }
if ($to !== '')   { $where[] = 'DATE(a.created_at) <= ?'; $params[] = $to; }
$fromSql = 'FROM billing_adjustments a JOIN invoices i ON i.invoice_id = a.invoice_id JOIN guests g ON g.guest_id = i.guest_id
            JOIN users ur ON ur.user_id = a.requested_by LEFT JOIN users ua ON ua.user_id = a.approved_by WHERE ' . implode(' AND ', $where);
$total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
$rows = db_all("SELECT a.*, i.invoice_number, i.total_amount AS current_total, g.full_name, ur.full_name AS requester, ua.full_name AS approver $fromSql
                ORDER BY a.status = 'pending' DESC, a.created_at DESC, a.adjustment_id DESC", $params, $perPage, ($page - 1) * $perPage);
$pend = db_one("SELECT COUNT(*) AS c FROM billing_adjustments WHERE status = 'pending'");

$invoices = $canRequest ? db_all("SELECT b.invoice_id, b.invoice_number, g.full_name AS guest_name, b.total_amount
        FROM v_invoice_balances b JOIN guests g ON g.guest_id = b.guest_id
        WHERE b.payment_status IN ('unpaid', 'partially_paid', 'overdue', 'paid') ORDER BY b.invoice_number") : [];

$pageTitle = 'Billing Adjustments';
include __DIR__ . '/includes/header.php';
$returnQs = qs();
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <span class="summary-chip"><strong><?= (int)$pend['c'] ?></strong> pending approval</span>
    <?php if ($canRequest): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#adjModal"><i class="bi bi-plus-lg me-1"></i>Request adjustment</button><?php endif; ?>
</div>

<form method="get" class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label small mb-1">Search</label><input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Invoice, guest or reason"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm"><option value="">All</option>
                <?php foreach (['pending', 'approved', 'rejected'] as $s): ?><option value="<?= e($s) ?>" <?= $stat === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2 d-grid"><button class="btn btn-primary btn-sm" type="submit">Filter</button></div>
    </div>
</form>

<div class="app-card"><div class="table-responsive">
    <table class="table table-hover">
        <thead><tr><th>Invoice / guest</th><th>Adjustment</th><th class="amount">Total before</th><th>Reason</th><th>Requested</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No billing adjustments recorded.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $own = (int)$r['requested_by'] === (int)$me['id'];
            $signed = ($r['adjustment_type'] === 'increase' ? '+' : '-') . peso($r['amount']); ?>
            <tr>
                <td><a href="<?= e(url('invoices.php?view=' . (int)$r['invoice_id'])) ?>"><strong><?= e($r['invoice_number']) ?></strong></a><div class="small text-muted"><?= e($r['full_name']) ?></div></td>
                <td><strong class="<?= $r['adjustment_type'] === 'increase' ? '' : 'value-negative' ?>"><?= e($signed) ?></strong><div class="small text-muted"><?= e(ucfirst($r['adjustment_type'])) ?></div></td>
                <td class="amount"><?= e(peso($r['invoice_total_before'])) ?><?php if ($r['status'] === 'approved'): ?><div class="small text-muted">now <?= e(peso($r['current_total'])) ?></div><?php endif; ?></td>
                <td class="small"><?= e($r['reason']) ?></td>
                <td class="small"><?= e($r['requester']) ?><div class="text-muted"><?= e(format_date($r['created_at'], 'M d, Y g:i A')) ?></div></td>
                <td><?= status_badge($r['status']) ?>
                    <?php if ($r['approver']): ?><div class="small text-muted"><?= e($r['approver']) ?>, <?= e(format_date($r['approved_at'], 'M d g:i A')) ?></div><?php endif; ?></td>
                <td class="text-end">
                    <?php if ($r['status'] === 'pending' && $canApprove): ?>
                        <button class="btn btn-outline-primary btn-sm decide-btn" data-bs-toggle="modal" data-bs-target="#decideModal"
                            data-id="<?= (int)$r['adjustment_id'] ?>" data-own="<?= $own ? '1' : '0' ?>"
                            data-desc="<?= e($r['invoice_number'] . ' - ' . $signed . ' - ' . $r['reason']) ?>">Review</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<div class="small text-muted mt-2"><?= $total ?> adjustment(s)</div>
<?= render_pager($total, $page, $perPage) ?>

<?php if ($canRequest): ?>
<div class="modal fade" id="adjModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="request"><input type="hidden" name="return" value="<?= e($returnQs) ?>">
        <div class="modal-header"><h5 class="modal-title">Request billing adjustment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-12"><label class="form-label">Invoice</label>
                    <select name="invoice_id" class="form-select" required><option value="">- select invoice -</option>
                        <?php foreach ($invoices as $iv): ?><option value="<?= (int)$iv['invoice_id'] ?>"><?= e($iv['invoice_number'] . ' - ' . $iv['guest_name'] . ' - total ' . peso($iv['total_amount'])) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label">Type</label>
                    <select name="adjustment_type" class="form-select" required><option value="decrease">Decrease (overcharge)</option><option value="increase">Increase (missed charge)</option></select></div>
                <div class="col-6"><label class="form-label">Amount (&#8369;)</label><input type="number" name="amount" step="0.01" min="0.01" class="form-control" required></div>
                <div class="col-12"><label class="form-label">Reason for the correction</label><input type="text" name="reason" maxlength="255" class="form-control" required></div>
            </div>
            <div class="form-text mt-2">The original charges and invoice total are kept. The adjustment is applied only after a manager approves it.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Submit request</button></div>
    </form></div>
</div>
<?php endif; ?>

<?php if ($canApprove): ?>
<div class="modal fade" id="decideModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($returnQs) ?>"><input type="hidden" name="adjustment_id" id="aId">
        <div class="modal-header"><h5 class="modal-title">Review adjustment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <p class="fw-semibold" id="aDesc"></p>
            <div class="alert alert-warning small d-none" id="aOwn">You requested this adjustment, so another manager must approve it. You can still reject it.</div>
            <label class="form-label" for="aNotes">Decision notes</label>
            <textarea class="form-control" name="notes" id="aNotes" rows="3" maxlength="255" placeholder="Required when rejecting"></textarea>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-danger" type="submit" name="action" value="reject">Reject</button>
            <button class="btn btn-success" type="submit" name="action" value="approve" id="aApprove">Approve</button>
        </div>
    </form></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.decide-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('aId').value = b.dataset.id;
            document.getElementById('aDesc').textContent = b.dataset.desc;
            document.getElementById('aNotes').value = '';
            var own = b.dataset.own === '1';
            document.getElementById('aOwn').classList.toggle('d-none', !own);
            document.getElementById('aApprove').disabled = own;
        });
    });
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
