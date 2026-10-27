<?php
/**
 * discounts.php - Discount Approval and Recording (module 16)
 * Discounts are fixed pesos or a percentage of the invoice subtotal.
 * Rule (financial_settings "discount_manager_approval_threshold"): discounts up to the threshold are approved
 * automatically; larger discounts wait for a manager. Nobody can approve their own request.
 * Decision notes are kept in the audit trail, and every request stays in the history.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission(['discounts.request', 'discounts.approve']);

$pdo = db();
$me  = current_user();
$canRequest = has_permission('discounts.request');
$canApprove = has_permission('discounts.approve');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        $pdo->beginTransaction();

        if ($action === 'request') {
            if (!$canRequest) { throw new RuntimeException('Your role cannot request discounts.'); }
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $type      = (string)($_POST['discount_type'] ?? '');
            $value     = parse_money($_POST['value'] ?? '');
            $reason    = clean_text($_POST['reason'] ?? '', 255);
            if (!in_array($type, ['fixed', 'percentage'], true)) { throw new RuntimeException('Choose a discount type.'); }
            if ($value === null) { throw new RuntimeException('Enter a valid discount value.'); }
            if ($type === 'percentage' && $value > 100) { throw new RuntimeException('A percentage discount cannot be more than 100%.'); }
            if (mb_strlen($reason) < 5) { throw new RuntimeException('Enter the reason for the discount (at least 5 characters).'); }

            $inv = db_one('SELECT invoice_id, invoice_number, subtotal, period_id, status FROM invoices WHERE invoice_id = ? FOR UPDATE', [$invoiceId]);
            if (!$inv || in_array($inv['status'], ['draft', 'cancelled'], true)) { throw new RuntimeException('Choose an issued invoice that is not cancelled.'); }
            require_open_period_id((int)$inv['period_id']);
            $bal = invoice_balance($invoiceId);
            $amount = $type === 'fixed' ? $value : round((float)$inv['subtotal'] * $value / 100, 2);
            if ($amount <= 0) { throw new RuntimeException('The discount amount must be greater than zero.'); }
            if ($amount > (float)$bal['outstanding_balance'] + 0.004) {
                throw new RuntimeException('The discount (' . peso($amount) . ') is more than the unpaid balance of ' . peso($bal['outstanding_balance']) . '.');
            }
            $threshold = (float)get_setting('discount_manager_approval_threshold', '300');
            $auto = $amount <= $threshold;

            $pdo->prepare('INSERT INTO discounts (invoice_id, discount_type, value, amount, reason, status, requested_by, approved_by, approved_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$invoiceId, $type, $value, $amount, $reason, $auto ? 'approved' : 'pending', (int)$me['id'],
                           $auto ? (int)$me['id'] : null, $auto ? date('Y-m-d H:i:s') : null]);
            $id = (int)$pdo->lastInsertId();
            if ($auto) {
                $t = recalc_invoice_total($invoiceId);
                audit_log('discount_auto_approved', 'discounts', $id, null, ['invoice' => $inv['invoice_number'], 'amount' => $amount, 'new_total' => $t['total']],
                    'Within the ' . peso($threshold) . ' approval threshold. ' . $reason);
                $msg = 'Discount of ' . peso($amount) . ' was approved automatically (within the approval threshold).';
            } else {
                audit_log('discount_requested', 'discounts', $id, null, ['invoice' => $inv['invoice_number'], 'amount' => $amount], $reason);
                $msg = 'Discount of ' . peso($amount) . ' is above ' . peso($threshold) . ' and was sent to a manager for approval.';
            }
            $pdo->commit();
            set_flash('success', $msg);

        } elseif ($action === 'approve' || $action === 'reject') {
            if (!$canApprove) { throw new RuntimeException('Your role cannot approve discounts.'); }
            $notes = clean_text($_POST['notes'] ?? '', 255);
            $d = db_one('SELECT d.*, i.invoice_number, i.period_id, i.status AS invoice_status FROM discounts d
                         JOIN invoices i ON i.invoice_id = d.invoice_id WHERE d.discount_id = ? FOR UPDATE', [(int)($_POST['discount_id'] ?? 0)]);
            if (!$d) { throw new RuntimeException('Discount not found.'); }
            if ($d['status'] !== 'pending') { throw new RuntimeException('This discount was already ' . $d['status'] . '.'); }
            require_open_period_id((int)$d['period_id']);
            if ($action === 'approve') {
                if ((int)$d['requested_by'] === (int)$me['id']) { throw new RuntimeException('You requested this discount, so another manager must approve it.'); }
                if ($d['invoice_status'] === 'cancelled') { throw new RuntimeException('The invoice is cancelled. Reject this discount.'); }
                $bal = invoice_balance((int)$d['invoice_id']);
                if ((float)$d['amount'] > (float)$bal['outstanding_balance'] + 0.004) {
                    throw new RuntimeException('Approving would make the invoice overpaid. The unpaid balance is only ' . peso($bal['outstanding_balance']) . '.');
                }
                $pdo->prepare("UPDATE discounts SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE discount_id = ?")->execute([(int)$me['id'], (int)$d['discount_id']]);
                $t = recalc_invoice_total((int)$d['invoice_id']);
                audit_log('discount_approved', 'discounts', $d['discount_id'], ['status' => 'pending'],
                    ['invoice' => $d['invoice_number'], 'amount' => $d['amount'], 'new_total' => $t['total']], $notes !== '' ? $notes : $d['reason']);
                $msg = 'Discount approved. ' . $d['invoice_number'] . ' total is now ' . peso($t['total']) . '.';
            } else {
                if (mb_strlen($notes) < 3) { throw new RuntimeException('Enter a reason for rejecting the discount.'); }
                $pdo->prepare("UPDATE discounts SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE discount_id = ?")->execute([(int)$me['id'], (int)$d['discount_id']]);
                audit_log('discount_rejected', 'discounts', $d['discount_id'], ['status' => 'pending'], ['status' => 'rejected', 'invoice' => $d['invoice_number']], $notes);
                $msg = 'Discount rejected.';
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
    redirect('discounts.php' . ($ret !== '' ? '?' . $ret : ''));
}

/* ------------------------------ Filters ------------------------------ */
$stat = (string)($_GET['status'] ?? '');
$q    = clean_text($_GET['q'] ?? '', 60);
$from = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$to   = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
$page = current_page_number();
$perPage = 15;
$where = ['1 = 1']; $params = [];
if (in_array($stat, ['pending', 'approved', 'rejected'], true)) { $where[] = 'd.status = ?'; $params[] = $stat; }
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(i.invoice_number LIKE ? OR g.full_name LIKE ? OR d.reason LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($from !== '') { $where[] = 'DATE(d.created_at) >= ?'; $params[] = $from; }
if ($to !== '')   { $where[] = 'DATE(d.created_at) <= ?'; $params[] = $to; }
$fromSql = 'FROM discounts d JOIN invoices i ON i.invoice_id = d.invoice_id JOIN guests g ON g.guest_id = i.guest_id
            JOIN users ur ON ur.user_id = d.requested_by LEFT JOIN users ua ON ua.user_id = d.approved_by WHERE ' . implode(' AND ', $where);
$total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
$rows = db_all("SELECT d.*, i.invoice_number, g.full_name, ur.full_name AS requester, ua.full_name AS approver $fromSql
                ORDER BY d.status = 'pending' DESC, d.created_at DESC, d.discount_id DESC", $params, $perPage, ($page - 1) * $perPage);
$pend = db_one("SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS s FROM discounts WHERE status = 'pending'");
$threshold = (float)get_setting('discount_manager_approval_threshold', '300');

$invoices = $canRequest ? db_all("SELECT invoice_id, invoice_number, guest_name, outstanding_balance FROM (
        SELECT b.invoice_id, b.invoice_number, g.full_name AS guest_name, b.outstanding_balance
        FROM v_invoice_balances b JOIN guests g ON g.guest_id = b.guest_id
        WHERE b.payment_status IN ('unpaid', 'partially_paid', 'overdue')) t WHERE outstanding_balance > 0 ORDER BY invoice_number") : [];

$pageTitle = 'Discounts';
include __DIR__ . '/includes/header.php';
$returnQs = qs();
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
        <span class="summary-chip"><strong><?= (int)$pend['c'] ?></strong> pending (<?= e(peso($pend['s'])) ?>)</span>
        <span class="summary-chip">Manager approval above <strong><?= e(peso($threshold)) ?></strong></span>
    </div>
    <?php if ($canRequest): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#discountModal"><i class="bi bi-plus-lg me-1"></i>Request discount</button><?php endif; ?>
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
        <thead><tr><th>Invoice / guest</th><th>Discount</th><th class="amount">Amount</th><th>Reason</th><th>Requested</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No discounts recorded.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $own = (int)$r['requested_by'] === (int)$me['id']; ?>
            <tr>
                <td><a href="<?= e(url('invoices.php?view=' . (int)$r['invoice_id'])) ?>"><strong><?= e($r['invoice_number']) ?></strong></a><div class="small text-muted"><?= e($r['full_name']) ?></div></td>
                <td><?= $r['discount_type'] === 'percentage' ? e(rtrim(rtrim(number_format((float)$r['value'], 2), '0'), '.')) . '% of subtotal' : 'Fixed amount' ?></td>
                <td class="amount"><strong><?= e(peso($r['amount'])) ?></strong></td>
                <td class="small"><?= e($r['reason']) ?></td>
                <td class="small"><?= e($r['requester']) ?><div class="text-muted"><?= e(format_date($r['created_at'], 'M d, Y g:i A')) ?></div></td>
                <td><?= status_badge($r['status']) ?>
                    <?php if ($r['approver']): ?><div class="small text-muted"><?= e($r['approver']) ?>, <?= e(format_date($r['approved_at'], 'M d g:i A')) ?></div><?php endif; ?></td>
                <td class="text-end">
                    <?php if ($r['status'] === 'pending' && $canApprove): ?>
                        <button class="btn btn-outline-primary btn-sm decide-btn" data-bs-toggle="modal" data-bs-target="#decideModal"
                            data-id="<?= (int)$r['discount_id'] ?>" data-own="<?= $own ? '1' : '0' ?>"
                            data-desc="<?= e($r['invoice_number'] . ' - ' . peso($r['amount']) . ' - ' . $r['reason']) ?>">Review</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<div class="small text-muted mt-2"><?= $total ?> discount(s)</div>
<?= render_pager($total, $page, $perPage) ?>

<?php if ($canRequest): ?>
<div class="modal fade" id="discountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="request"><input type="hidden" name="return" value="<?= e($returnQs) ?>">
        <div class="modal-header"><h5 class="modal-title">Request discount</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <?php if (!$invoices): ?><div class="alert alert-info mb-0">No invoices with an unpaid balance.</div><?php else: ?>
            <div class="row g-3">
                <div class="col-12"><label class="form-label">Invoice</label>
                    <select name="invoice_id" class="form-select" required><option value="">- select invoice -</option>
                        <?php foreach ($invoices as $iv): ?><option value="<?= (int)$iv['invoice_id'] ?>"><?= e($iv['invoice_number'] . ' - ' . $iv['guest_name'] . ' - unpaid ' . peso($iv['outstanding_balance'])) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label">Type</label>
                    <select name="discount_type" class="form-select" required><option value="fixed">Fixed amount (&#8369;)</option><option value="percentage">Percentage of subtotal (%)</option></select></div>
                <div class="col-6"><label class="form-label">Value</label><input type="number" name="value" step="0.01" min="0.01" class="form-control" required></div>
                <div class="col-12"><label class="form-label">Reason</label><input type="text" name="reason" maxlength="255" class="form-control" required></div>
            </div>
            <div class="form-text mt-2">Discounts up to <?= e(peso($threshold)) ?> are approved automatically. Larger ones need a manager.</div>
            <?php endif; ?>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><?php if ($invoices): ?><button class="btn btn-primary" type="submit">Submit</button><?php endif; ?></div>
    </form></div>
</div>
<?php endif; ?>

<?php if ($canApprove): ?>
<div class="modal fade" id="decideModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($returnQs) ?>"><input type="hidden" name="discount_id" id="dId">
        <div class="modal-header"><h5 class="modal-title">Review discount</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <p class="fw-semibold" id="dDesc"></p>
            <div class="alert alert-warning small d-none" id="dOwn">You requested this discount, so another manager must approve it. You can still reject it.</div>
            <label class="form-label" for="dNotes">Decision notes</label>
            <textarea class="form-control" name="notes" id="dNotes" rows="3" maxlength="255" placeholder="Required when rejecting"></textarea>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-danger" type="submit" name="action" value="reject">Reject</button>
            <button class="btn btn-success" type="submit" name="action" value="approve" id="dApprove">Approve</button>
        </div>
    </form></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.decide-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('dId').value = b.dataset.id;
            document.getElementById('dDesc').textContent = b.dataset.desc;
            document.getElementById('dNotes').value = '';
            var own = b.dataset.own === '1';
            document.getElementById('dOwn').classList.toggle('d-none', !own);
            document.getElementById('dApprove').disabled = own;
        });
    });
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
