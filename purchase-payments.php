<?php
/**
 * purchase-payments.php - Purchase Payment Tracking (module 23)
 * Records payments to suppliers against a supplier invoice and calculates outstanding supplier balances.
 * A supplier payment is when the purchase is recognized as an expense. Payments are never deleted.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('purchase_payments.manage');

$pdo = db();
$me  = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $siId = (int)($_POST['supplier_invoice_id'] ?? 0);
    try {
        if ((string)($_POST['action'] ?? '') !== 'record') { throw new RuntimeException('Unknown action.'); }
        $amount = parse_money($_POST['amount'] ?? '');
        $date   = (string)($_POST['payment_date'] ?? '');
        $method = (string)($_POST['payment_method'] ?? '');
        $ref    = clean_text($_POST['reference_number'] ?? '', 60);
        if (!isset(SUPPLIER_PAYMENT_METHODS[$method])) { throw new RuntimeException('Choose a payment method.'); }
        if ($amount === null) { throw new RuntimeException('Enter a valid payment amount greater than zero.'); }
        if (!valid_date($date) || $date > date('Y-m-d')) { throw new RuntimeException('The payment date must be a valid date that is not in the future.'); }
        if ($method !== 'cash' && $ref === '') { throw new RuntimeException('Enter the reference number (transfer, check or transaction number).'); }
        if ($ref !== '' && preg_match('/^[\d\s-]+$/', $ref) && strlen(preg_replace('/\D/', '', $ref)) >= 13) {
            throw new RuntimeException('The reference looks like a full card number. Never enter card numbers.');
        }

        $pdo->beginTransaction();
        $si = db_one('SELECT * FROM supplier_invoices WHERE supplier_invoice_id = ? FOR UPDATE', [$siId]);
        if (!$si || $si['status'] === 'cancelled') { throw new RuntimeException('Choose a supplier invoice that is not cancelled.'); }
        if ($date < $si['invoice_date']) { throw new RuntimeException('The payment date cannot be earlier than the invoice date (' . format_date($si['invoice_date']) . ').'); }
        $balance = (float)db_value('SELECT balance FROM v_supplier_balances WHERE supplier_invoice_id = ?', [$siId]);
        if ($balance <= 0) { throw new RuntimeException('This supplier invoice is already fully paid.'); }
        if ($amount > $balance + 0.004) { throw new RuntimeException('The amount exceeds the outstanding balance of ' . peso($balance) . '.'); }
        if ($method !== 'cash' && db_value('SELECT COUNT(*) FROM supplier_payments WHERE payment_method = ? AND reference_number = ?', [$method, $ref]) > 0) {
            throw new RuntimeException('This reference number was already used for another supplier payment.');
        }
        $periodId = require_open_period($date);
        $pdo->prepare('INSERT INTO supplier_payments (supplier_invoice_id, period_id, amount, payment_date, payment_method, reference_number, recorded_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$siId, $periodId, $amount, $date, $method, $ref !== '' ? $ref : null, (int)$me['id']]);
        $payId = (int)$pdo->lastInsertId();
        refresh_supplier_invoice_status($siId);
        audit_log('supplier_payment_recorded', 'supplier_payments', $payId, null,
            ['supplier_invoice' => $si['invoice_number'], 'amount' => $amount, 'method' => $method, 'balance_after' => round($balance - $amount, 2)]);
        $pdo->commit();
        set_flash('success', 'Payment of ' . peso($amount) . ' to supplier invoice ' . $si['invoice_number'] . ' was recorded. Balance: ' . peso($balance - $amount) . '.');
        redirect('purchase-payments.php');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The payment could not be saved. Please try again.');
        redirect('purchase-payments.php' . ($siId > 0 ? '?invoice=' . $siId : ''));
    }
}

$tab  = (($_GET['tab'] ?? '') === 'balances') ? 'balances' : 'payments';
$q    = clean_text($_GET['q'] ?? '', 60);
$page = current_page_number();
$perPage = 15;
$prefill = (int)($_GET['invoice'] ?? 0);

if ($tab === 'payments') {
    $method = (string)($_GET['method'] ?? '');
    $from = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
    $to   = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
    $where = ['1 = 1']; $params = [];
    if ($q !== '') { $like = '%' . $q . '%'; $where[] = '(s.supplier_name LIKE ? OR si.invoice_number LIKE ? OR sp.reference_number LIKE ?)'; array_push($params, $like, $like, $like); }
    if (isset(SUPPLIER_PAYMENT_METHODS[$method])) { $where[] = 'sp.payment_method = ?'; $params[] = $method; }
    if ($from !== '') { $where[] = 'sp.payment_date >= ?'; $params[] = $from; }
    if ($to !== '')   { $where[] = 'sp.payment_date <= ?'; $params[] = $to; }
    $fromSql = 'FROM supplier_payments sp JOIN supplier_invoices si ON si.supplier_invoice_id = sp.supplier_invoice_id
                JOIN suppliers s ON s.supplier_id = si.supplier_id JOIN users u ON u.user_id = sp.recorded_by WHERE ' . implode(' AND ', $where);
    $total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
    $paidSum = (float)db_value("SELECT COALESCE(SUM(sp.amount), 0) $fromSql", $params);
    $rows = db_all("SELECT sp.*, si.invoice_number, s.supplier_name, u.full_name AS recorder $fromSql ORDER BY sp.payment_date DESC, sp.sp_payment_id DESC", $params, $perPage, ($page - 1) * $perPage);
} else {
    $where = ["b.payment_status <> 'cancelled'"]; $params = [];
    if ($q !== '') { $like = '%' . $q . '%'; $where[] = 'b.supplier_name LIKE ?'; $params[] = $like; }
    $sql = 'SELECT b.supplier_id, b.supplier_name, COUNT(*) AS invoices, SUM(b.amount) AS invoiced, SUM(b.amount_paid) AS paid, SUM(b.balance) AS balance,
                   COALESCE(SUM(CASE WHEN b.payment_status = \'overdue\' THEN b.balance END), 0) AS overdue
            FROM v_supplier_balances b WHERE ' . implode(' AND ', $where) . ' GROUP BY b.supplier_id, b.supplier_name ORDER BY balance DESC';
    $rows = db_all($sql, $params);
    $total = count($rows);
}

$payable = db_all("SELECT supplier_invoice_id, supplier_name, invoice_number, balance, due_date FROM v_supplier_balances
                   WHERE payment_status IN ('unpaid', 'partially_paid', 'overdue') AND balance > 0 ORDER BY due_date, supplier_invoice_id");

$pageTitle = 'Purchase Payments';
include __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <ul class="nav nav-tabs-soft nav border-0">
        <li class="nav-item"><a class="nav-link <?= $tab === 'payments' ? 'active' : '' ?>" href="<?= e(url('purchase-payments.php')) ?>">Payments made</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab === 'balances' ? 'active' : '' ?>" href="<?= e(url('purchase-payments.php?tab=balances')) ?>">Supplier balances</a></li>
    </ul>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#spModal"><i class="bi bi-plus-lg me-1"></i>Record supplier payment</button>
</div>

<form method="get" class="filter-bar mb-3">
    <?php if ($tab === 'balances'): ?><input type="hidden" name="tab" value="balances"><?php endif; ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label small mb-1">Search</label><input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="<?= $tab === 'payments' ? 'Supplier, invoice or reference' : 'Supplier name' ?>"></div>
        <?php if ($tab === 'payments'): ?>
            <div class="col-6 col-md-2"><label class="form-label small mb-1">Method</label>
                <select name="method" class="form-select form-select-sm"><option value="">All</option>
                    <?php foreach (SUPPLIER_PAYMENT_METHODS as $k => $v): ?><option value="<?= e($k) ?>" <?= $method === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-1">Paid from</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-1">Paid to</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <?php endif; ?>
        <div class="col-6 col-md-2 d-grid"><button class="btn btn-primary btn-sm" type="submit">Filter</button></div>
    </div>
</form>

<div class="app-card"><div class="table-responsive">
<?php if ($tab === 'payments'): ?>
    <table class="table table-hover">
        <thead><tr><th>Date</th><th>Supplier / invoice</th><th>Method</th><th>Reference</th><th class="amount">Amount</th><th>Recorded by</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No supplier payments match your filters.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr><td><?= e(format_date($r['payment_date'])) ?></td>
                <td><strong><?= e($r['supplier_name']) ?></strong><div class="small text-muted"><?= e($r['invoice_number']) ?></div></td>
                <td><?= e(SUPPLIER_PAYMENT_METHODS[$r['payment_method']] ?? $r['payment_method']) ?></td>
                <td><?= e($r['reference_number'] ?? '-') ?></td>
                <td class="amount"><strong><?= e(peso($r['amount'])) ?></strong></td>
                <td class="small"><?= e($r['recorder']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <table class="table table-hover">
        <thead><tr><th>Supplier</th><th class="text-center">Invoices</th><th class="amount">Invoiced</th><th class="amount">Paid</th><th class="amount">Outstanding</th><th class="amount">Overdue</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No supplier invoices yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr><td><strong><?= e($r['supplier_name']) ?></strong></td><td class="text-center"><?= (int)$r['invoices'] ?></td>
                <td class="amount"><?= e(peso($r['invoiced'])) ?></td><td class="amount"><?= e(peso($r['paid'])) ?></td>
                <td class="amount"><strong><?= e(peso($r['balance'])) ?></strong></td>
                <td class="amount <?= (float)$r['overdue'] > 0 ? 'value-negative' : '' ?>"><?= e(peso($r['overdue'])) ?></td>
                <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="<?= e(url('suppliers.php?supplier=' . (int)$r['supplier_id'])) ?>">Invoices</a></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div></div>
<div class="d-flex justify-content-between small text-muted mt-2"><span><?= $total ?> record(s)</span><?php if ($tab === 'payments'): ?><span>Total paid (filtered): <strong><?= e(peso($paidSum)) ?></strong></span><?php endif; ?></div>
<?php if ($tab === 'payments') { echo render_pager($total, $page, $perPage); } ?>

<div class="modal fade" id="spModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="record">
        <div class="modal-header"><h5 class="modal-title">Record supplier payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <?php if (!$payable): ?><div class="alert alert-info mb-0">No supplier invoices are waiting for payment.</div><?php else: ?>
            <div class="row g-3">
                <div class="col-12"><label class="form-label">Supplier invoice</label>
                    <select name="supplier_invoice_id" id="spInv" class="form-select" required><option value="">- select invoice -</option>
                        <?php foreach ($payable as $p): ?><option value="<?= (int)$p['supplier_invoice_id'] ?>" data-balance="<?= e(number_format((float)$p['balance'], 2, '.', '')) ?>" <?= $prefill === (int)$p['supplier_invoice_id'] ? 'selected' : '' ?>>
                            <?= e($p['supplier_name'] . ' - ' . $p['invoice_number'] . ' - balance ' . peso($p['balance']) . ' - due ' . format_date($p['due_date'], 'M d')) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label">Amount (&#8369;)</label><input type="number" name="amount" id="spAmt" step="0.01" min="0.01" class="form-control" required></div>
                <div class="col-6"><label class="form-label">Payment date</label><input type="date" name="payment_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
                <div class="col-6"><label class="form-label">Method</label>
                    <select name="payment_method" class="form-select" required><?php foreach (SUPPLIER_PAYMENT_METHODS as $k => $v): ?><option value="<?= e($k) ?>" <?= $k === 'bank_transfer' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label">Reference</label><input type="text" name="reference_number" maxlength="60" class="form-control" placeholder="Required unless cash"></div>
            </div>
            <div class="form-text mt-2">Partial payments are allowed. The invoice becomes paid when its balance reaches zero.</div>
            <?php endif; ?>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><?php if ($payable): ?><button class="btn btn-primary" type="submit">Save payment</button><?php endif; ?></div>
    </form></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var inv = document.getElementById('spInv'), amt = document.getElementById('spAmt');
    if (!inv) { return; }
    function fill() { var o = inv.options[inv.selectedIndex]; if (o && o.dataset.balance) { amt.value = o.dataset.balance; amt.max = o.dataset.balance; } }
    inv.addEventListener('change', fill);
    <?php if ($prefill > 0): ?>fill(); new bootstrap.Modal(document.getElementById('spModal')).show();<?php endif; ?>
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
