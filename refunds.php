<?php
/**
 * refunds.php - Refund Request and Processing (module 15)
 * Flow: request -> manager approval -> completion (with the payout reference).
 * The original payment or deposit is never deleted:
 *  - a completed payment refund is subtracted from "payments received" (v_invoice_balances) and from revenue;
 *  - a completed deposit refund increases guest_deposits.amount_refunded.
 * Nobody can approve a refund they requested.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission(['refunds.request', 'refunds.approve']);

$pdo = db();
$me  = current_user();
$canRequest = has_permission('refunds.request');
$canApprove = has_permission('refunds.approve');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        $pdo->beginTransaction();

        if ($action === 'request') {
            if (!$canRequest) { throw new RuntimeException('Your role cannot request refunds.'); }
            $source = (string)($_POST['source'] ?? '');
            $amount = parse_money($_POST['amount'] ?? '');
            $reason = clean_text($_POST['reason'] ?? '', 255);
            if (!preg_match('/^(payment|deposit):(\d+)$/', $source, $m)) { throw new RuntimeException('Choose the payment or deposit to refund.'); }
            if ($amount === null) { throw new RuntimeException('Enter a valid refund amount.'); }
            if (mb_strlen($reason) < 5) { throw new RuntimeException('Enter the reason for the refund (at least 5 characters).'); }
            $sourceId = (int)$m[2];

            if ($m[1] === 'payment') {
                $p = db_one('SELECT payment_id, payment_number, invoice_id, status FROM payments WHERE payment_id = ? FOR UPDATE', [$sourceId]);
                if (!$p || $p['status'] !== 'verified') { throw new RuntimeException('Only verified payments can be refunded.'); }
                $room = payment_refundable($sourceId);
                if ($amount > $room + 0.004) { throw new RuntimeException('Only ' . peso($room) . ' of this payment can still be refunded.'); }
                $pdo->prepare("INSERT INTO refunds (refund_number, payment_id, deposit_id, invoice_id, amount, reason, status, requested_by) VALUES (?, ?, NULL, ?, ?, ?, 'pending', ?)")
                    ->execute([next_doc_number('REF'), $sourceId, (int)$p['invoice_id'], $amount, $reason, (int)$me['id']]);
                $label = $p['payment_number'];
            } else {
                $d = db_one('SELECT deposit_id, deposit_number FROM guest_deposits WHERE deposit_id = ? FOR UPDATE', [$sourceId]);
                if (!$d) { throw new RuntimeException('Deposit not found.'); }
                $room = deposit_available($sourceId);
                if ($amount > $room + 0.004) { throw new RuntimeException('Only ' . peso($room) . ' of this deposit is available to refund.'); }
                $pdo->prepare("INSERT INTO refunds (refund_number, payment_id, deposit_id, invoice_id, amount, reason, status, requested_by) VALUES (?, NULL, ?, NULL, ?, ?, 'pending', ?)")
                    ->execute([next_doc_number('REF'), $sourceId, $amount, $reason, (int)$me['id']]);
                $label = $d['deposit_number'];
            }
            audit_log('refund_requested', 'refunds', (int)$pdo->lastInsertId(), null, ['source' => $label, 'amount' => $amount], $reason);
            $pdo->commit();
            set_flash('success', 'Refund request for ' . peso($amount) . ' (' . $label . ') was sent for manager approval.');

        } elseif (in_array($action, ['approve', 'reject', 'complete'], true)) {
            if (!$canApprove) { throw new RuntimeException('Your role cannot approve or complete refunds.'); }
            $notes = clean_text($_POST['notes'] ?? '', 255);
            $r = db_one('SELECT * FROM refunds WHERE refund_id = ? FOR UPDATE', [(int)($_POST['refund_id'] ?? 0)]);
            if (!$r) { throw new RuntimeException('Refund not found.'); }

            if ($action === 'approve') {
                if ($r['status'] !== 'pending') { throw new RuntimeException('Only pending refunds can be approved.'); }
                if ((int)$r['requested_by'] === (int)$me['id']) { throw new RuntimeException('You requested this refund, so another manager must approve it.'); }
                $pdo->prepare("UPDATE refunds SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE refund_id = ?")->execute([(int)$me['id'], (int)$r['refund_id']]);
                audit_log('refund_approved', 'refunds', $r['refund_id'], ['status' => 'pending'], ['status' => 'approved', 'amount' => $r['amount']], $notes !== '' ? $notes : $r['reason']);
                $msg = $r['refund_number'] . ' approved. Complete it once the money has been returned to the guest.';
            } elseif ($action === 'reject') {
                if ($r['status'] !== 'pending') { throw new RuntimeException('Only pending refunds can be rejected.'); }
                if (mb_strlen($notes) < 3) { throw new RuntimeException('Enter a reason for rejecting the refund.'); }
                $pdo->prepare("UPDATE refunds SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE refund_id = ?")->execute([(int)$me['id'], (int)$r['refund_id']]);
                audit_log('refund_rejected', 'refunds', $r['refund_id'], ['status' => 'pending'], ['status' => 'rejected'], $notes);
                $msg = $r['refund_number'] . ' was rejected.';
            } else { // complete
                if ($r['status'] !== 'approved') { throw new RuntimeException('Only approved refunds can be completed.'); }
                $ref = clean_text($_POST['reference_number'] ?? '', 60);
                if (mb_strlen($ref) < 3) { throw new RuntimeException('Enter the payout reference (receipt, transfer or voucher number).'); }
                require_open_period(date('Y-m-d')); // completion is dated today and must fall in an open period
                if ($r['payment_id']) {
                    $p = db_one('SELECT payment_id, amount, invoice_id FROM payments WHERE payment_id = ? FOR UPDATE', [(int)$r['payment_id']]);
                    $done = (float)db_value("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE payment_id = ? AND status = 'completed'", [(int)$r['payment_id']]);
                    if ($done + (float)$r['amount'] > (float)$p['amount'] + 0.004) { throw new RuntimeException('Completed refunds would exceed the original payment.'); }
                    $pdo->prepare("UPDATE refunds SET status = 'completed', completed_at = NOW(), reference_number = ? WHERE refund_id = ?")->execute([$ref, (int)$r['refund_id']]);
                    refresh_invoice_status((int)$p['invoice_id']);
                } else {
                    $d = db_one('SELECT * FROM guest_deposits WHERE deposit_id = ? FOR UPDATE', [(int)$r['deposit_id']]);
                    if ((float)$r['amount'] > (float)$d['remaining_amount'] + 0.004) { throw new RuntimeException('The deposit no longer has enough remaining balance for this refund.'); }
                    $newRefunded = round((float)$d['amount_refunded'] + (float)$r['amount'], 2);
                    $pdo->prepare('UPDATE guest_deposits SET amount_refunded = ?, status = ? WHERE deposit_id = ?')
                        ->execute([$newRefunded, deposit_status((float)$d['amount_received'], (float)$d['amount_applied'], $newRefunded), (int)$d['deposit_id']]);
                    $pdo->prepare("UPDATE refunds SET status = 'completed', completed_at = NOW(), reference_number = ? WHERE refund_id = ?")->execute([$ref, (int)$r['refund_id']]);
                }
                audit_log('refund_completed', 'refunds', $r['refund_id'], ['status' => 'approved'], ['status' => 'completed', 'amount' => $r['amount'], 'reference' => $ref], $notes !== '' ? $notes : null);
                $msg = $r['refund_number'] . ' completed. The original record was kept.';
            }
            $pdo->commit();
            set_flash($action === 'reject' ? 'warning' : 'success', $msg);
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The action could not be completed. Please try again.');
    }
    $ret = ltrim((string)($_POST['return'] ?? ''), '?');
    redirect('refunds.php' . ($ret !== '' ? '?' . $ret : ''));
}

/* ------------------------------ Filters ------------------------------ */
$stat = (string)($_GET['status'] ?? '');
$type = (string)($_GET['type'] ?? '');
$q    = clean_text($_GET['q'] ?? '', 60);
$from = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$to   = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
$page = current_page_number();
$perPage = 15;
$where = ['1 = 1']; $params = [];
if (in_array($stat, ['pending', 'approved', 'rejected', 'completed'], true)) { $where[] = 'r.status = ?'; $params[] = $stat; }
if ($type === 'payment') { $where[] = 'r.payment_id IS NOT NULL'; } elseif ($type === 'deposit') { $where[] = 'r.deposit_id IS NOT NULL'; }
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(r.refund_number LIKE ? OR g.full_name LIKE ? OR p.payment_number LIKE ? OR d.deposit_number LIKE ? OR r.reason LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($from !== '') { $where[] = 'DATE(r.created_at) >= ?'; $params[] = $from; }
if ($to !== '')   { $where[] = 'DATE(r.created_at) <= ?'; $params[] = $to; }
$fromSql = 'FROM refunds r
    LEFT JOIN payments p ON p.payment_id = r.payment_id
    LEFT JOIN invoices i ON i.invoice_id = p.invoice_id
    LEFT JOIN guest_deposits d ON d.deposit_id = r.deposit_id
    LEFT JOIN guests g ON g.guest_id = COALESCE(i.guest_id, d.guest_id)
    JOIN users ur ON ur.user_id = r.requested_by
    LEFT JOIN users ua ON ua.user_id = r.approved_by
    WHERE ' . implode(' AND ', $where);
$total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
$sums = db_one("SELECT COALESCE(SUM(CASE WHEN r.status = 'completed' THEN r.amount END), 0) AS done,
                       COALESCE(SUM(CASE WHEN r.status IN ('pending', 'approved') THEN r.amount END), 0) AS open_amt $fromSql", $params);
$rows = db_all("SELECT r.*, p.payment_number, i.invoice_number, d.deposit_number, g.full_name, ur.full_name AS requester, ua.full_name AS approver $fromSql
                ORDER BY FIELD(r.status, 'pending', 'approved', 'completed', 'rejected'), r.created_at DESC, r.refund_id DESC", $params, $perPage, ($page - 1) * $perPage);

$prePayment = (int)($_GET['payment'] ?? 0);
$preDeposit = (int)($_GET['deposit'] ?? 0);
$payRefundable = $canRequest ? db_all("SELECT t.* FROM (
        SELECT p.payment_id, p.payment_number, i.invoice_number, g.full_name,
               p.amount - COALESCE((SELECT SUM(r.amount) FROM refunds r WHERE r.payment_id = p.payment_id AND r.status IN ('pending', 'approved', 'completed')), 0) AS refundable
        FROM payments p JOIN invoices i ON i.invoice_id = p.invoice_id JOIN guests g ON g.guest_id = i.guest_id WHERE p.status = 'verified') t
    WHERE t.refundable > 0 ORDER BY t.payment_number") : [];
$depRefundable = $canRequest ? db_all("SELECT t.* FROM (
        SELECT d.deposit_id, d.deposit_number, g.full_name,
               d.remaining_amount - COALESCE((SELECT SUM(r.amount) FROM refunds r WHERE r.deposit_id = d.deposit_id AND r.status IN ('pending', 'approved')), 0) AS available
        FROM guest_deposits d JOIN guests g ON g.guest_id = d.guest_id) t
    WHERE t.available > 0 ORDER BY t.deposit_number") : [];

$pageTitle = 'Refunds';
include __DIR__ . '/includes/header.php';
$returnQs = qs();
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
        <span class="summary-chip"><strong><?= $total ?></strong> refund(s)</span>
        <span class="summary-chip">Open <strong><?= e(peso($sums['open_amt'])) ?></strong></span>
        <span class="summary-chip">Completed <strong><?= e(peso($sums['done'])) ?></strong></span>
    </div>
    <?php if ($canRequest): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#refundModal"><i class="bi bi-plus-lg me-1"></i>Request refund</button><?php endif; ?>
</div>

<form method="get" class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small mb-1">Search</label><input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Refund, guest, payment, deposit"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm"><option value="">All</option>
                <?php foreach (['pending', 'approved', 'completed', 'rejected'] as $s): ?><option value="<?= e($s) ?>" <?= $stat === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Type</label>
            <select name="type" class="form-select form-select-sm"><option value="">All</option>
                <option value="payment" <?= $type === 'payment' ? 'selected' : '' ?>>Payment refund</option><option value="deposit" <?= $type === 'deposit' ? 'selected' : '' ?>>Deposit refund</option></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <div class="col-md-1 d-grid"><button class="btn btn-primary btn-sm" type="submit">Go</button></div>
    </div>
</form>

<div class="app-card"><div class="table-responsive">
    <table class="table table-hover">
        <thead><tr><th>Refund</th><th>Source / guest</th><th class="amount">Amount</th><th>Reason</th><th>Requested</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No refunds recorded.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $own = (int)$r['requested_by'] === (int)$me['id']; ?>
            <tr>
                <td><strong><?= e($r['refund_number']) ?></strong><?php if ($r['reference_number']): ?><div class="small text-muted">Ref: <?= e($r['reference_number']) ?></div><?php endif; ?></td>
                <td><?= $r['payment_id'] ? 'Payment ' . e($r['payment_number']) . '<div class="small text-muted">' . e($r['invoice_number']) . '</div>' : 'Deposit ' . e($r['deposit_number']) ?>
                    <div class="small text-muted"><?= e($r['full_name']) ?></div></td>
                <td class="amount"><strong><?= e(peso($r['amount'])) ?></strong></td>
                <td class="small"><?= e($r['reason']) ?></td>
                <td class="small"><?= e($r['requester']) ?><div class="text-muted"><?= e(format_date($r['created_at'], 'M d, Y g:i A')) ?></div></td>
                <td><?= status_badge($r['status']) ?>
                    <?php if ($r['approver']): ?><div class="small text-muted"><?= e($r['approver']) ?>, <?= e(format_date($r['approved_at'], 'M d g:i A')) ?></div><?php endif; ?>
                    <?php if ($r['completed_at']): ?><div class="small text-muted">Paid out <?= e(format_date($r['completed_at'], 'M d g:i A')) ?></div><?php endif; ?></td>
                <td class="text-end">
                    <?php if ($canApprove && $r['status'] === 'pending'): ?>
                        <button class="btn btn-outline-primary btn-sm act-btn" data-bs-toggle="modal" data-bs-target="#actModal" data-mode="decide"
                            data-id="<?= (int)$r['refund_id'] ?>" data-own="<?= $own ? '1' : '0' ?>"
                            data-desc="<?= e($r['refund_number'] . ' - ' . peso($r['amount']) . ' - ' . $r['reason']) ?>">Review</button>
                    <?php elseif ($canApprove && $r['status'] === 'approved'): ?>
                        <button class="btn btn-secondary btn-sm act-btn" data-bs-toggle="modal" data-bs-target="#actModal" data-mode="complete"
                            data-id="<?= (int)$r['refund_id'] ?>" data-own="0"
                            data-desc="<?= e($r['refund_number'] . ' - ' . peso($r['amount'])) ?>">Complete</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<div class="small text-muted mt-2"><?= $total ?> refund(s)</div>
<?= render_pager($total, $page, $perPage) ?>

<?php if ($canRequest): ?>
<div class="modal fade" id="refundModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="request"><input type="hidden" name="return" value="<?= e($returnQs) ?>">
        <div class="modal-header"><h5 class="modal-title">Request refund</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <?php if (!$payRefundable && !$depRefundable): ?><div class="alert alert-info mb-0">Nothing is available to refund.</div><?php else: ?>
            <div class="mb-3"><label class="form-label">Refund from</label>
                <select name="source" id="rfSource" class="form-select" required><option value="">- select payment or deposit -</option>
                    <?php if ($payRefundable): ?><optgroup label="Verified payments">
                        <?php foreach ($payRefundable as $p): ?><option value="payment:<?= (int)$p['payment_id'] ?>" data-max="<?= e(number_format((float)$p['refundable'], 2, '.', '')) ?>" <?= $prePayment === (int)$p['payment_id'] ? 'selected' : '' ?>><?= e($p['payment_number'] . ' - ' . $p['invoice_number'] . ' - ' . $p['full_name'] . ' - refundable ' . peso($p['refundable'])) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                    <?php if ($depRefundable): ?><optgroup label="Guest deposits">
                        <?php foreach ($depRefundable as $d): ?><option value="deposit:<?= (int)$d['deposit_id'] ?>" data-max="<?= e(number_format((float)$d['available'], 2, '.', '')) ?>" <?= $preDeposit === (int)$d['deposit_id'] ? 'selected' : '' ?>><?= e($d['deposit_number'] . ' - ' . $d['full_name'] . ' - available ' . peso($d['available'])) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                </select></div>
            <div class="mb-3"><label class="form-label">Amount (&#8369;)</label><input type="number" name="amount" id="rfAmount" step="0.01" min="0.01" class="form-control" required></div>
            <div><label class="form-label">Reason</label><input type="text" name="reason" maxlength="255" class="form-control" required></div>
            <div class="form-text mt-2">A manager must approve the refund. If the guest keeps being billed for the service, request a discount or adjustment first.</div>
            <?php endif; ?>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><?php if ($payRefundable || $depRefundable): ?><button class="btn btn-primary" type="submit">Submit request</button><?php endif; ?></div>
    </form></div>
</div>
<?php endif; ?>

<?php if ($canApprove): ?>
<div class="modal fade" id="actModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($returnQs) ?>"><input type="hidden" name="refund_id" id="acId">
        <div class="modal-header"><h5 class="modal-title" id="acTitle">Review refund</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <p class="fw-semibold" id="acDesc"></p>
            <div class="alert alert-warning small d-none" id="acOwn">You requested this refund, so another manager must approve it. You can still reject it.</div>
            <div id="acRefBox" class="mb-3 d-none"><label class="form-label">Payout reference</label>
                <input type="text" name="reference_number" id="acRef" maxlength="60" class="form-control" placeholder="Receipt, bank transfer or GCash reference" disabled></div>
            <label class="form-label" for="acNotes">Notes</label>
            <textarea class="form-control" name="notes" id="acNotes" rows="2" maxlength="255" placeholder="Required when rejecting"></textarea>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            <button class="btn btn-danger" type="submit" name="action" value="reject" id="acReject">Reject</button>
            <button class="btn btn-success" type="submit" name="action" value="approve" id="acApprove">Approve</button>
            <button class="btn btn-primary d-none" type="submit" name="action" value="complete" id="acComplete">Mark as completed</button>
        </div>
    </form></div>
</div>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var src = document.getElementById('rfSource'), amt = document.getElementById('rfAmount');
    if (src) {
        var fill = function () { var o = src.options[src.selectedIndex]; if (o && o.dataset.max) { amt.value = o.dataset.max; amt.max = o.dataset.max; } };
        src.addEventListener('change', fill);
        <?php if ($prePayment > 0 || $preDeposit > 0): ?>fill(); new bootstrap.Modal(document.getElementById('refundModal')).show();<?php endif; ?>
    }
    document.querySelectorAll('.act-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            var complete = b.dataset.mode === 'complete', own = b.dataset.own === '1';
            document.getElementById('acId').value = b.dataset.id;
            document.getElementById('acDesc').textContent = b.dataset.desc;
            document.getElementById('acNotes').value = '';
            document.getElementById('acTitle').textContent = complete ? 'Complete refund' : 'Review refund';
            document.getElementById('acRefBox').classList.toggle('d-none', !complete);
            document.getElementById('acRef').disabled = !complete;
            document.getElementById('acRef').required = complete;
            document.getElementById('acReject').classList.toggle('d-none', complete);
            document.getElementById('acApprove').classList.toggle('d-none', complete);
            document.getElementById('acComplete').classList.toggle('d-none', !complete);
            document.getElementById('acOwn').classList.toggle('d-none', !own);
            document.getElementById('acApprove').disabled = own;
        });
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
