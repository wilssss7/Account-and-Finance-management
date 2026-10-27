<?php
/**
 * payment-verification.php - Payment Verification (module 11)
 * Authorized staff verify or reject recorded payments. Statuses: pending, verified, rejected.
 * Only verified payments reduce invoice balances. A user cannot verify a payment they recorded themselves.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('payments.verify');

$pdo = db();
$me  = current_user();

/** @return string message describing the outcome */
function review_payment(int $paymentId, string $result, string $notes, array $me): string
{
    $pdo = db();
    $p = db_one('SELECT p.*, i.invoice_number, i.status AS invoice_status
                 FROM payments p JOIN invoices i ON i.invoice_id = p.invoice_id
                 WHERE p.payment_id = ? FOR UPDATE', [$paymentId]);
    if (!$p) {
        throw new RuntimeException('Payment not found.');
    }
    if ($p['status'] !== 'pending') {
        throw new RuntimeException($p['payment_number'] . ' was already ' . $p['status'] . ' and can no longer be changed.');
    }
    require_open_period_id((int)$p['period_id']);

    if ($result === 'verified') {
        if ((int)$p['recorded_by'] === (int)$me['id']) {
            throw new RuntimeException('You recorded ' . $p['payment_number'] . ', so another authorized user must verify it.');
        }
        if ($p['invoice_status'] === 'cancelled') {
            throw new RuntimeException('The invoice for this payment is cancelled. Reject the payment instead.');
        }
        $bal = invoice_balance((int)$p['invoice_id']);
        if ((float)$p['amount'] > (float)$bal['outstanding_balance'] + 0.004) {
            throw new RuntimeException('Verifying would exceed the invoice balance of ' . peso($bal['outstanding_balance']) . '. Reject this payment or review the invoice.');
        }
    } elseif (mb_strlen($notes) < 3) {
        throw new RuntimeException('Enter a reason when rejecting a payment.');
    }

    $pdo->prepare('UPDATE payments SET status = ? WHERE payment_id = ?')->execute([$result, $paymentId]);
    $pdo->prepare('INSERT INTO payment_verifications (payment_id, verified_by, result, notes) VALUES (?, ?, ?, ?)')
        ->execute([$paymentId, (int)$me['id'], $result, $notes !== '' ? $notes : null]);
    if ($result === 'verified') {
        refresh_invoice_status((int)$p['invoice_id']);
    }
    audit_log($result === 'verified' ? 'payment_verified' : 'payment_rejected', 'payments', $paymentId,
        ['status' => 'pending'], ['status' => $result, 'payment_number' => $p['payment_number'], 'invoice' => $p['invoice_number'], 'amount' => $p['amount']],
        $notes !== '' ? $notes : null);

    $after = $result === 'verified' ? invoice_balance((int)$p['invoice_id']) : null;
    return $result === 'verified'
        ? $p['payment_number'] . ' verified. ' . $p['invoice_number'] . ' balance is now ' . peso($after['outstanding_balance']) . '.'
        : $p['payment_number'] . ' was rejected and does not affect the invoice balance.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!in_array($action, ['verify', 'reject'], true)) {
            throw new RuntimeException('Unknown action.');
        }
        $pdo->beginTransaction();
        $msg = review_payment((int)($_POST['payment_id'] ?? 0), $action === 'verify' ? 'verified' : 'rejected',
            clean_text($_POST['notes'] ?? '', 255), $me);
        $pdo->commit();
        set_flash($action === 'verify' ? 'success' : 'warning', $msg);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The action could not be completed. Please try again.');
    }
    $ret = ltrim((string)($_POST['return'] ?? ''), '?');
    redirect('payment-verification.php' . ($ret !== '' ? '?' . $ret : ''));
}

/* ------------------------------ Filters ------------------------------ */
$stat   = (string)($_GET['status'] ?? 'pending');
$method = (string)($_GET['method'] ?? '');
$q      = clean_text($_GET['q'] ?? '', 60);
$from   = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$to     = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
$page   = current_page_number();
$perPage = 15;

$where = ['1 = 1']; $params = [];
if (in_array($stat, ['pending', 'verified', 'rejected'], true)) { $where[] = 'p.status = ?'; $params[] = $stat; } else { $stat = 'all'; }
if (isset(PAYMENT_METHODS[$method])) { $where[] = 'p.payment_method = ?'; $params[] = $method; }
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(p.payment_number LIKE ? OR p.reference_number LIKE ? OR i.invoice_number LIKE ? OR g.full_name LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
if ($from !== '') { $where[] = 'p.payment_date >= ?'; $params[] = $from; }
if ($to !== '')   { $where[] = 'p.payment_date <= ?'; $params[] = $to; }
$fromSql = 'FROM payments p JOIN invoices i ON i.invoice_id = p.invoice_id JOIN guests g ON g.guest_id = i.guest_id
            JOIN users ur ON ur.user_id = p.recorded_by
            LEFT JOIN payment_verifications pv ON pv.payment_id = p.payment_id
            LEFT JOIN users uv ON uv.user_id = pv.verified_by
            WHERE ' . implode(' AND ', $where);

$total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
$rows = db_all("SELECT p.*, i.invoice_number, g.full_name, ur.full_name AS recorder, pv.notes AS v_notes, pv.verified_at, uv.full_name AS verifier
                $fromSql ORDER BY p.status = 'pending' DESC, p.payment_date DESC, p.payment_id DESC", $params, $perPage, ($page - 1) * $perPage);
$pend = db_one("SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS s FROM payments WHERE status = 'pending'");

$pageTitle = 'Payment Verification';
include __DIR__ . '/includes/header.php';
$returnQs = qs();
?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <span class="summary-chip"><strong><?= (int)$pend['c'] ?></strong> pending</span>
    <span class="summary-chip">Awaiting <strong><?= e(peso($pend['s'])) ?></strong></span>
</div>

<form method="get" class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small mb-1">Search</label>
            <input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Payment, reference, invoice, guest"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <?php foreach (['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= $stat === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Method</label>
            <select name="method" class="form-select form-select-sm"><option value="">All</option>
                <?php foreach (PAYMENT_METHODS as $k => $v): ?><option value="<?= e($k) ?>" <?= $method === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <div class="col-md-1 d-grid"><button class="btn btn-primary btn-sm" type="submit">Filter</button></div>
    </div>
</form>

<div class="app-card">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Payment</th><th>Invoice / guest</th><th>Method and details</th><th>Reference</th><th class="amount">Amount</th><th>Recorded by</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No payments to show.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): $own = (int)$r['recorded_by'] === (int)$me['id']; ?>
                <tr>
                    <td><strong><?= e($r['payment_number']) ?></strong><div class="small text-muted"><?= e(format_date($r['payment_date'])) ?></div></td>
                    <td><a href="<?= e(url('invoices.php?view=' . (int)$r['invoice_id'])) ?>"><?= e($r['invoice_number']) ?></a><div class="small text-muted"><?= e($r['full_name']) ?></div></td>
                    <td><?= e(PAYMENT_METHODS[$r['payment_method']] ?? $r['payment_method']) ?>
                        <div class="small text-muted">
                            <?php if ($r['payment_method'] === 'cash'): ?>Cashier: <?= e($r['cashier_name']) ?>
                            <?php elseif ($r['payment_method'] === 'card'): ?><?= e($r['card_type']) ?><?= $r['card_last4'] ? ' ending ' . e($r['card_last4']) : '' ?>
                            <?php elseif ($r['payment_method'] === 'bank_transfer'): ?><?= e($r['bank_name']) ?><?php endif; ?></div></td>
                    <td><?= e($r['reference_number'] ?? '-') ?></td>
                    <td class="amount"><strong><?= e(peso($r['amount'])) ?></strong></td>
                    <td class="small"><?= e($r['recorder']) ?></td>
                    <td><?= status_badge($r['status']) ?>
                        <?php if ($r['verifier']): ?><div class="small text-muted"><?= e($r['verifier']) ?>, <?= e(format_date($r['verified_at'], 'M d g:i A')) ?></div><?php endif; ?>
                        <?php if ($r['v_notes']): ?><div class="small"><?= e($r['v_notes']) ?></div><?php endif; ?></td>
                    <td class="text-end">
                        <?php if ($r['status'] === 'pending'): ?>
                            <button class="btn btn-outline-primary btn-sm review-btn" data-bs-toggle="modal" data-bs-target="#verifyModal"
                                data-id="<?= (int)$r['payment_id'] ?>" data-own="<?= $own ? '1' : '0' ?>"
                                data-desc="<?= e($r['payment_number'] . ' - ' . $r['invoice_number'] . ' - ' . peso($r['amount']) . ' (' . (PAYMENT_METHODS[$r['payment_method']] ?? '') . ')') ?>">Review</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="small text-muted mt-2"><?= $total ?> payment(s)</div>
<?= render_pager($total, $page, $perPage) ?>

<div class="modal fade" id="verifyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e($returnQs) ?>">
        <input type="hidden" name="payment_id" id="vId">
        <div class="modal-header"><h5 class="modal-title">Verify payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <p class="fw-semibold" id="vDesc"></p>
            <div class="alert alert-warning small d-none" id="vOwn">You recorded this payment, so another authorized user must verify it. You can still reject it.</div>
            <label class="form-label" for="vNotes">Verification notes</label>
            <textarea class="form-control" name="notes" id="vNotes" rows="3" maxlength="255" placeholder="Required when rejecting (for example: reference not found on the statement)"></textarea>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-danger" type="submit" name="action" value="reject"><i class="bi bi-x-lg me-1"></i>Reject</button>
            <button class="btn btn-success" type="submit" name="action" value="verify" id="vVerifyBtn"><i class="bi bi-check-lg me-1"></i>Verify</button>
        </div>
    </form></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.review-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('vId').value = b.dataset.id;
            document.getElementById('vDesc').textContent = b.dataset.desc;
            document.getElementById('vNotes').value = '';
            var own = b.dataset.own === '1';
            document.getElementById('vOwn').classList.toggle('d-none', !own);
            document.getElementById('vVerifyBtn').disabled = own;
        });
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
