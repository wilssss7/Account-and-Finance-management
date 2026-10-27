<?php
/**
 * accounts-payable.php - Accounts Payable Tracking (module 21)
 * Amounts owed to suppliers: unpaid, partially paid, paid and overdue invoices, with ageing.
 * Read-only view of v_supplier_balances; invoices are recorded in suppliers.php and paid in purchase-payments.php.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('payables.view');

$stat = (string)($_GET['status'] ?? 'open');
$supFilter = (int)($_GET['supplier'] ?? 0);
$q    = clean_text($_GET['q'] ?? '', 60);
$from = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$to   = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
$page = current_page_number();
$perPage = 15;

$where = ["b.payment_status <> 'cancelled'"]; $params = [];
if ($stat === 'open') { $where[] = "b.payment_status IN ('unpaid', 'partially_paid', 'overdue')"; }
elseif (in_array($stat, ['unpaid', 'partially_paid', 'paid', 'overdue'], true)) { $where[] = 'b.payment_status = ?'; $params[] = $stat; }
else { $stat = 'all'; }
if ($supFilter > 0) { $where[] = 'b.supplier_id = ?'; $params[] = $supFilter; }
if ($q !== '') { $like = '%' . $q . '%'; $where[] = '(b.supplier_name LIKE ? OR b.invoice_number LIKE ?)'; array_push($params, $like, $like); }
if ($from !== '') { $where[] = 'b.due_date >= ?'; $params[] = $from; }
if ($to !== '')   { $where[] = 'b.due_date <= ?'; $params[] = $to; }
$fromSql = 'FROM v_supplier_balances b WHERE ' . implode(' AND ', $where);
$total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
$rows = db_all("SELECT b.* $fromSql ORDER BY b.due_date, b.supplier_invoice_id", $params, $perPage, ($page - 1) * $perPage);

$open = "b.payment_status IN ('unpaid', 'partially_paid', 'overdue') AND b.balance > 0";
$totals = db_one("SELECT COALESCE(SUM(b.balance), 0) AS payable,
        COALESCE(SUM(CASE WHEN b.payment_status = 'overdue' THEN b.balance END), 0) AS overdue,
        COALESCE(SUM(CASE WHEN b.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN b.balance END), 0) AS due_week,
        COUNT(*) AS n FROM v_supplier_balances b WHERE $open");
$aging = db_one("SELECT
        COALESCE(SUM(CASE WHEN b.due_date >= CURDATE() THEN b.balance END), 0) AS current_amt,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), b.due_date) BETWEEN 1 AND 30 THEN b.balance END), 0) AS d30,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), b.due_date) BETWEEN 31 AND 60 THEN b.balance END), 0) AS d60,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), b.due_date) BETWEEN 61 AND 90 THEN b.balance END), 0) AS d90,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), b.due_date) > 90 THEN b.balance END), 0) AS d90p
        FROM v_supplier_balances b WHERE $open");
$bySupplier = db_all("SELECT b.supplier_id, b.supplier_name, COUNT(*) AS n, SUM(b.balance) AS owed,
        COALESCE(SUM(CASE WHEN b.payment_status = 'overdue' THEN b.balance END), 0) AS overdue
        FROM v_supplier_balances b WHERE $open GROUP BY b.supplier_id, b.supplier_name ORDER BY owed DESC");
$suppliers = db_all('SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name');

$pageTitle = 'Accounts Payable';
include __DIR__ . '/includes/header.php';
$canPay = has_permission('purchase_payments.manage');
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="app-card stat-card"><div class="stat-label">Total payable</div><div class="stat-value"><?= e(peso($totals['payable'])) ?></div><div class="stat-note"><?= (int)$totals['n'] ?> open invoice(s)</div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card stat-card"><div class="stat-label">Overdue</div><div class="stat-value value-negative"><?= e(peso($totals['overdue'])) ?></div><div class="stat-note">Past the due date</div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card stat-card"><div class="stat-label">Due in 7 days</div><div class="stat-value"><?= e(peso($totals['due_week'])) ?></div><div class="stat-note">Plan the cash needed</div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card stat-card"><div class="stat-label">Suppliers owed</div><div class="stat-value"><?= count($bySupplier) ?></div><div class="stat-note">With an open balance</div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7"><div class="app-card h-100"><div class="card-header"><h3 class="card-title-serif">Ageing of payables</h3></div>
        <div class="table-responsive"><table class="table"><thead><tr><th class="amount">Not yet due</th><th class="amount">1-30 days</th><th class="amount">31-60 days</th><th class="amount">61-90 days</th><th class="amount">Over 90</th></tr></thead>
            <tbody><tr><td class="amount"><?= e(peso($aging['current_amt'])) ?></td><td class="amount"><?= e(peso($aging['d30'])) ?></td><td class="amount"><?= e(peso($aging['d60'])) ?></td>
                <td class="amount"><?= e(peso($aging['d90'])) ?></td><td class="amount value-negative"><?= e(peso($aging['d90p'])) ?></td></tr></tbody></table></div></div></div>
    <div class="col-lg-5"><div class="app-card h-100"><div class="card-header"><h3 class="card-title-serif">Owed by supplier</h3></div>
        <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Supplier</th><th class="amount">Owed</th><th class="amount">Overdue</th></tr></thead><tbody>
            <?php if (!$bySupplier): ?><tr><td colspan="3" class="text-center text-muted py-3">Nothing is owed.</td></tr><?php endif; ?>
            <?php foreach ($bySupplier as $s): ?><tr><td><a href="<?= e(url('accounts-payable.php?supplier=' . (int)$s['supplier_id'])) ?>"><?= e($s['supplier_name']) ?></a><div class="small text-muted"><?= (int)$s['n'] ?> invoice(s)</div></td>
                <td class="amount"><strong><?= e(peso($s['owed'])) ?></strong></td><td class="amount"><?= e(peso($s['overdue'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
</div>

<form method="get" class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small mb-1">Search</label><input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Supplier or invoice number"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Supplier</label>
            <select name="supplier" class="form-select form-select-sm"><option value="0">All</option>
                <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['supplier_id'] ?>" <?= $supFilter === (int)$s['supplier_id'] ? 'selected' : '' ?>><?= e($s['supplier_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <?php foreach (['open' => 'All open', 'unpaid' => 'Unpaid', 'partially_paid' => 'Partially paid', 'overdue' => 'Overdue', 'paid' => 'Paid', 'all' => 'Everything'] as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= $stat === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Due from</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Due to</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <div class="col-md-1 d-grid"><button class="btn btn-primary btn-sm" type="submit">Go</button></div>
    </div>
</form>

<div class="app-card"><div class="table-responsive">
    <table class="table table-hover">
        <thead><tr><th>Supplier / invoice</th><th>Invoice date</th><th>Due date</th><th class="amount">Amount</th><th class="amount">Paid</th><th class="amount">Balance</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No payables match your filters.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $late = days_past_due($r['due_date']); ?>
            <tr>
                <td><strong><?= e($r['supplier_name']) ?></strong><div class="small text-muted"><?= e($r['invoice_number']) ?></div></td>
                <td><?= e(format_date($r['invoice_date'])) ?></td>
                <td><?= e(format_date($r['due_date'])) ?><?php if ($late > 0 && $r['payment_status'] !== 'paid'): ?><div class="small value-negative"><?= $late ?> day(s) late</div><?php endif; ?></td>
                <td class="amount"><?= e(peso($r['amount'])) ?></td>
                <td class="amount"><?= e(peso($r['amount_paid'])) ?></td>
                <td class="amount"><strong><?= e(peso($r['balance'])) ?></strong></td>
                <td><?= status_badge($r['payment_status']) ?></td>
                <td class="text-end"><?php if ($canPay && $r['payment_status'] !== 'paid'): ?><a class="btn btn-outline-primary btn-sm" href="<?= e(url('purchase-payments.php?invoice=' . (int)$r['supplier_invoice_id'])) ?>">Record payment</a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<div class="small text-muted mt-2"><?= $total ?> invoice(s)</div>
<?= render_pager($total, $page, $perPage) ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
