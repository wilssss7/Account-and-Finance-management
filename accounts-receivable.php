<?php
/**
 * accounts-receivable.php - Accounts Receivable Tracking (module 22)
 * Amounts owed by guests, corporate clients and other customers, from v_accounts_receivable / v_invoice_balances.
 * Tab "By customer" (with a drill-down per customer) and tab "Open invoices". Includes ageing by due date.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('receivables.view');

$tab  = (($_GET['tab'] ?? '') === 'invoices') ? 'invoices' : 'customers';
$q    = clean_text($_GET['q'] ?? '', 60);
$type = (string)($_GET['type'] ?? '');
$page = current_page_number();
$perPage = 15;
$guestId = (int)($_GET['guest'] ?? 0);
if ($guestId > 0) { $tab = 'invoices'; }

$open = "b.payment_status IN ('unpaid', 'partially_paid', 'overdue') AND b.outstanding_balance > 0";
$totals = db_one("SELECT COALESCE(SUM(b.outstanding_balance), 0) AS receivable,
        COALESCE(SUM(CASE WHEN b.payment_status = 'overdue' THEN b.outstanding_balance END), 0) AS overdue,
        COUNT(*) AS n, COUNT(DISTINCT b.guest_id) AS customers FROM v_invoice_balances b WHERE $open");
$aging = db_one("SELECT
        COALESCE(SUM(CASE WHEN b.due_date >= CURDATE() THEN b.outstanding_balance END), 0) AS current_amt,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), b.due_date) BETWEEN 1 AND 30 THEN b.outstanding_balance END), 0) AS d30,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), b.due_date) BETWEEN 31 AND 60 THEN b.outstanding_balance END), 0) AS d60,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), b.due_date) BETWEEN 61 AND 90 THEN b.outstanding_balance END), 0) AS d90,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), b.due_date) > 90 THEN b.outstanding_balance END), 0) AS d90p
        FROM v_invoice_balances b WHERE $open");

if ($tab === 'customers') {
    $overdueOnly = isset($_GET['overdue']);
    $where = ['1 = 1']; $params = [];
    if ($q !== '') { $like = '%' . $q . '%'; $where[] = '(a.full_name LIKE ? OR a.guest_code LIKE ? OR a.company_name LIKE ?)'; array_push($params, $like, $like, $like); }
    if (in_array($type, ['individual', 'corporate'], true)) { $where[] = 'a.guest_type = ?'; $params[] = $type; }
    if ($overdueOnly) { $where[] = 'a.overdue_amount > 0'; }
    $fromSql = 'FROM v_accounts_receivable a WHERE ' . implode(' AND ', $where);
    $total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
    $rows = db_all("SELECT a.* $fromSql ORDER BY a.total_receivable DESC", $params, $perPage, ($page - 1) * $perPage);
} else {
    $stat = (string)($_GET['status'] ?? '');
    $where = [$open]; $params = [];
    if ($guestId > 0) { $where[] = 'b.guest_id = ?'; $params[] = $guestId; }
    if ($q !== '') { $like = '%' . $q . '%'; $where[] = '(b.invoice_number LIKE ? OR g.full_name LIKE ? OR g.company_name LIKE ?)'; array_push($params, $like, $like, $like); }
    if (in_array($type, ['individual', 'corporate'], true)) { $where[] = 'g.guest_type = ?'; $params[] = $type; }
    if (in_array($stat, ['unpaid', 'partially_paid', 'overdue'], true)) { $where[] = 'b.payment_status = ?'; $params[] = $stat; }
    $fromSql = 'FROM v_invoice_balances b JOIN guests g ON g.guest_id = b.guest_id WHERE ' . implode(' AND ', $where);
    $total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
    $rows = db_all("SELECT b.*, g.full_name, g.guest_code, g.company_name, g.guest_type $fromSql ORDER BY b.due_date, b.invoice_id", $params, $perPage, ($page - 1) * $perPage);
}
$custName = $guestId > 0 ? db_value('SELECT full_name FROM guests WHERE guest_id = ?', [$guestId]) : null;

$pageTitle = 'Accounts Receivable';
include __DIR__ . '/includes/header.php';
$canPay = has_permission('payments.record');
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="app-card stat-card"><div class="stat-label">Total receivable</div><div class="stat-value"><?= e(peso($totals['receivable'])) ?></div><div class="stat-note"><?= (int)$totals['n'] ?> open invoice(s)</div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card stat-card"><div class="stat-label">Overdue</div><div class="stat-value value-negative"><?= e(peso($totals['overdue'])) ?></div><div class="stat-note">Past the due date</div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card stat-card"><div class="stat-label">Customers owing</div><div class="stat-value"><?= (int)$totals['customers'] ?></div><div class="stat-note">Guests and corporate clients</div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card stat-card"><div class="stat-label">Not yet due</div><div class="stat-value"><?= e(peso($aging['current_amt'])) ?></div><div class="stat-note">Within terms</div></div></div>
</div>
<div class="app-card mb-3"><div class="card-header"><h3 class="card-title-serif">Ageing of receivables</h3></div>
    <div class="table-responsive"><table class="table"><thead><tr><th class="amount">Not yet due</th><th class="amount">1-30 days</th><th class="amount">31-60 days</th><th class="amount">61-90 days</th><th class="amount">Over 90</th></tr></thead>
        <tbody><tr><td class="amount"><?= e(peso($aging['current_amt'])) ?></td><td class="amount"><?= e(peso($aging['d30'])) ?></td><td class="amount"><?= e(peso($aging['d60'])) ?></td>
            <td class="amount"><?= e(peso($aging['d90'])) ?></td><td class="amount value-negative"><?= e(peso($aging['d90p'])) ?></td></tr></tbody></table></div></div>

<ul class="nav nav-tabs-soft nav border-0 mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 'customers' ? 'active' : '' ?>" href="<?= e(url('accounts-receivable.php')) ?>">By customer</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'invoices' ? 'active' : '' ?>" href="<?= e(url('accounts-receivable.php?tab=invoices')) ?>">Open invoices</a></li>
</ul>
<?php if ($custName): ?><div class="alert alert-info py-2">Showing open invoices of <strong><?= e($custName) ?></strong>. <a href="<?= e(url('accounts-receivable.php?tab=invoices')) ?>">Show all customers</a></div><?php endif; ?>

<form method="get" class="filter-bar mb-3">
    <?php if ($tab === 'invoices'): ?><input type="hidden" name="tab" value="invoices"><?php endif; ?>
    <?php if ($guestId > 0): ?><input type="hidden" name="guest" value="<?= (int)$guestId ?>"><?php endif; ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label small mb-1">Search</label><input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="<?= $tab === 'customers' ? 'Customer, code or company' : 'Invoice, customer or company' ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Customer type</label>
            <select name="type" class="form-select form-select-sm"><option value="">All</option>
                <option value="individual" <?= $type === 'individual' ? 'selected' : '' ?>>Guests</option><option value="corporate" <?= $type === 'corporate' ? 'selected' : '' ?>>Corporate clients</option></select></div>
        <?php if ($tab === 'customers'): ?>
            <div class="col-6 col-md-3"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="overdue" id="ovd" value="1" <?= isset($_GET['overdue']) ? 'checked' : '' ?>><label class="form-check-label" for="ovd">Only customers with overdue amounts</label></div></div>
        <?php else: ?>
            <div class="col-6 col-md-3"><label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm"><option value="">All open</option>
                    <?php foreach (['unpaid' => 'Unpaid', 'partially_paid' => 'Partially paid', 'overdue' => 'Overdue'] as $k => $v): ?><option value="<?= e($k) ?>" <?= $stat === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="col-md-2 d-grid"><button class="btn btn-primary btn-sm" type="submit">Filter</button></div>
    </div>
</form>

<div class="app-card"><div class="table-responsive">
<?php if ($tab === 'customers'): ?>
    <table class="table table-hover">
        <thead><tr><th>Customer</th><th>Type</th><th class="text-center">Open invoices</th><th class="amount">Receivable</th><th class="amount">Overdue</th><th>Earliest due</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No customers owe money. Every invoice is settled.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= e($r['full_name']) ?></strong><div class="small text-muted"><?= e($r['guest_code']) ?><?= $r['company_name'] ? ' &middot; ' . e($r['company_name']) : '' ?></div></td>
                <td><?= $r['guest_type'] === 'corporate' ? '<span class="badge badge-soft badge-pink">Corporate</span>' : '<span class="badge badge-soft badge-khaki">Guest</span>' ?></td>
                <td class="text-center"><?= (int)$r['open_invoices'] ?></td>
                <td class="amount"><strong><?= e(peso($r['total_receivable'])) ?></strong></td>
                <td class="amount <?= (float)$r['overdue_amount'] > 0 ? 'value-negative' : '' ?>"><?= e(peso($r['overdue_amount'])) ?></td>
                <td><?= e(format_date($r['earliest_due_date'])) ?></td>
                <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="<?= e(url('accounts-receivable.php?guest=' . (int)$r['guest_id'])) ?>">View invoices</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <table class="table table-hover">
        <thead><tr><th>Invoice</th><th>Customer</th><th>Due date</th><th class="amount">Total</th><th class="amount">Paid</th><th class="amount">Balance</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No open invoices match your filters.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $late = days_past_due($r['due_date']); ?>
            <tr>
                <td><?php if (has_permission('invoices.manage')): ?><a href="<?= e(url('invoices.php?view=' . (int)$r['invoice_id'])) ?>"><strong><?= e($r['invoice_number']) ?></strong></a><?php else: ?><strong><?= e($r['invoice_number']) ?></strong><?php endif; ?></td>
                <td><?= e($r['full_name']) ?><div class="small text-muted"><?= e($r['guest_code']) ?><?= $r['company_name'] ? ' &middot; ' . e($r['company_name']) : '' ?></div></td>
                <td><?= e(format_date($r['due_date'])) ?><?php if ($late > 0): ?><div class="small value-negative"><?= $late ?> day(s) late</div><?php endif; ?></td>
                <td class="amount"><?= e(peso($r['total_amount'])) ?></td>
                <td class="amount"><?= e(peso($r['verified_paid'] + $r['deposit_applied'])) ?></td>
                <td class="amount"><strong><?= e(peso($r['outstanding_balance'])) ?></strong></td>
                <td><?= status_badge($r['payment_status']) ?></td>
                <td class="text-end"><?php if ($canPay): ?><a class="btn btn-outline-primary btn-sm" href="<?= e(url('payments.php?invoice=' . (int)$r['invoice_id'])) ?>">Record payment</a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div></div>
<div class="small text-muted mt-2"><?= $total ?> record(s)</div>
<?= render_pager($total, $page, $perPage) ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
