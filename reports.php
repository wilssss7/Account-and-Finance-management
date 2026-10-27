<?php
/**
 * reports.php - Revenue Reports (26), Expense Reports (27), Profit and Loss (28), Financial Report Exports (33)
 *
 * Reporting rules (also stored in financial_settings and shown on the page):
 *  - Revenue is on a cash basis: verified payments on their payment date, less completed refunds of payments.
 *    Deposits are not revenue until applied to an invoice; a deposit refund does not reduce revenue.
 *  - Expenses: operating expenses on the expense date (void ones excluded), supplier invoices only when paid
 *    (supplier payment date), payroll on the pay-period end date (only for roles allowed to see payroll).
 *  - Net financial result = recognized revenue - recognized expenses.
 * Exports: CSV download, plus a print layout (use the browser's "Save as PDF" for a PDF copy).
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('reports.view');

$canExport  = has_permission('exports.generate');
$incPayroll = has_permission('payroll.view');
$me = current_user();

/* ------------------------------ Parameters ------------------------------ */
$report = in_array($_GET['report'] ?? '', ['revenue', 'expenses', 'pnl'], true) ? $_GET['report'] : 'revenue';
$gran   = in_array($_GET['gran'] ?? '', ['day', 'week', 'month', 'year'], true) ? $_GET['gran'] : 'month';

$latest = db_value('SELECT MAX(d) FROM (SELECT MAX(event_date) AS d FROM v_revenue_events UNION ALL SELECT MAX(event_date) FROM v_expense_events) t');
$thisMonth = date('Y-m');
$defMonth = ($latest && substr((string)$latest, 0, 7) < $thisMonth) ? substr((string)$latest, 0, 7) : $thisMonth;
$from = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : $defMonth . '-01';
$to   = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : date('Y-m-t', strtotime($defMonth . '-01'));
if ($to < $from) { $to = $from; }
$method   = (string)($_GET['method'] ?? '');
$source   = (string)($_GET['source'] ?? '');
$catId    = (int)($_GET['category'] ?? 0);
$supId    = (int)($_GET['supplier'] ?? 0);
if (!isset(PAYMENT_METHODS[$method])) { $method = ''; }
if (!in_array($source, ['guest', 'corporate', 'other'], true)) { $source = ''; }

/* ------------------------------ Helpers ------------------------------ */
function bucket_sql(string $col, string $gran): string
{
    return match ($gran) {
        'day'   => "DATE_FORMAT($col, '%Y-%m-%d')",
        'week'  => "DATE_FORMAT(DATE_SUB($col, INTERVAL WEEKDAY($col) DAY), '%Y-%m-%d')",
        'year'  => "DATE_FORMAT($col, '%Y')",
        default => "DATE_FORMAT($col, '%Y-%m')",
    };
}
function bucket_label(string $b, string $gran): string
{
    return match ($gran) {
        'day'   => format_date($b),
        'week'  => 'Week of ' . format_date($b),
        'year'  => $b,
        default => date('F Y', strtotime($b . '-01')),
    };
}
/** Builds a table definition; sums the money columns into a footer when $footerLabel is given. */
function make_table(string $heading, array $headers, array $rows, array $money, ?string $footerLabel = null, ?string $note = null): array
{
    $footer = null;
    if ($footerLabel !== null) {
        $footer = array_fill(0, count($headers), '');
        $footer[0] = $footerLabel;
        foreach ($money as $i) {
            $footer[$i] = round(array_sum(array_map(static fn($r) => (float)$r[$i], $rows)), 2);
        }
    }
    return ['heading' => $heading, 'headers' => $headers, 'rows' => $rows, 'money' => $money, 'footer' => $footer, 'note' => $note];
}
function expense_union(bool $incPayroll): string
{
    $sql = "SELECT x.expense_date AS d, x.category_id, 'operating' AS source, x.amount, NULL AS supplier_id FROM expenses x WHERE x.status = 'recorded'
            UNION ALL
            SELECT sp.payment_date, si.category_id, 'supplier_payment', sp.amount, si.supplier_id
              FROM supplier_payments sp JOIN supplier_invoices si ON si.supplier_invoice_id = sp.supplier_invoice_id";
    if ($incPayroll) {
        $sql .= " UNION ALL SELECT pr.pay_period_end, NULL, 'payroll', pr.total_amount, NULL FROM payroll_expenses pr WHERE pr.status = 'recorded'";
        if (revision2_ready()) {   // automated payroll that has been PAID (gross pay, on the pay-period end date)
            $sql .= " UNION ALL SELECT pp.end_date, NULL, 'payroll', r.gross_pay, NULL FROM payroll_records r JOIN payroll_periods pp ON pp.payroll_period_id = r.payroll_period_id WHERE r.status = 'paid'";
        }
    }
    return "($sql) t";
}

/* ------------------------------ Build the report ------------------------------ */
$tables = [];
$notes  = [];
if ($report === 'revenue') {
    $title = 'Revenue Report';
    $w = 'e.event_date BETWEEN ? AND ?'; $p = [$from, $to];
    if ($method !== '') { $w .= ' AND e.payment_method = ?'; $p[] = $method; }
    if ($source !== '') { $w .= ' AND i.customer_type = ?'; $p[] = $source; }
    $b = bucket_sql('e.event_date', $gran);
    $rows = [];
    foreach (db_all("SELECT $b AS bucket,
            COALESCE(SUM(CASE WHEN e.source = 'payment' THEN e.amount ELSE 0 END), 0) AS pay,
            COALESCE(SUM(CASE WHEN e.source = 'refund' THEN -e.amount ELSE 0 END), 0) AS ref, COALESCE(SUM(e.amount), 0) AS net
            FROM v_revenue_events e JOIN invoices i ON i.invoice_id = e.invoice_id WHERE $w GROUP BY bucket ORDER BY bucket", $p) as $r) {
        $rows[] = [bucket_label($r['bucket'], $gran), (float)$r['pay'], (float)$r['ref'], (float)$r['net']];
    }
    $tables[] = make_table('Revenue by ' . $gran, ['Period', 'Payments received', 'Refunds', 'Net revenue'], $rows, [1, 2, 3], 'Total');

    $rows = [];
    foreach (db_all("SELECT e.payment_method, COALESCE(SUM(e.amount), 0) AS net FROM v_revenue_events e JOIN invoices i ON i.invoice_id = e.invoice_id
            WHERE $w GROUP BY e.payment_method ORDER BY net DESC", $p) as $r) {
        $rows[] = [PAYMENT_METHODS[$r['payment_method']] ?? $r['payment_method'], (float)$r['net']];
    }
    $tables[] = make_table('Net revenue by payment method', ['Payment method', 'Net revenue'], $rows, [1], 'Total');

    $w2 = "i.status <> 'cancelled' AND i.issue_date BETWEEN ? AND ?"; $p2 = [$from, $to];
    if ($source !== '') { $w2 .= ' AND i.customer_type = ?'; $p2[] = $source; }
    $rows = [];
    foreach (db_all("SELECT c.category, SUM(ii.line_total) AS billed FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
            JOIN folio_charges c ON c.charge_id = ii.folio_charge_id WHERE $w2 GROUP BY c.category ORDER BY billed DESC", $p2) as $r) {
        $rows[] = [CHARGE_CATEGORIES[$r['category']] ?? $r['category'], (float)$r['billed']];
    }
    $tables[] = make_table('Billed charges by category (invoices issued in the period)', ['Category', 'Billed amount'], $rows, [1], 'Total',
        'Billed amounts are before tax and service charge and are not cash received. They show where revenue comes from.');
    $notes[] = 'Revenue is cash basis: verified payments less completed refunds of payments. Tax and service charge collected are included.';

} elseif ($report === 'expenses') {
    $title = 'Expense Report';
    $u = expense_union($incPayroll);
    $w = 't.d BETWEEN ? AND ?'; $p = [$from, $to];
    if ($catId > 0) { $w .= ' AND t.category_id = ?'; $p[] = $catId; }
    if ($supId > 0) { $w .= ' AND t.supplier_id = ?'; $p[] = $supId; }
    $b = bucket_sql('t.d', $gran);
    $rows = [];
    foreach (db_all("SELECT $b AS bucket, SUM(t.amount) AS total FROM $u WHERE $w GROUP BY bucket ORDER BY bucket", $p) as $r) { $rows[] = [bucket_label($r['bucket'], $gran), (float)$r['total']]; }
    $tables[] = make_table('Expenses by ' . $gran, ['Period', 'Expenses'], $rows, [1], 'Total');

    $rows = [];
    foreach (db_all("SELECT CASE WHEN t.source = 'payroll' THEN 'Payroll' ELSE COALESCE(c.name, 'Uncategorized') END AS label, SUM(t.amount) AS total
            FROM $u LEFT JOIN expense_categories c ON c.category_id = t.category_id WHERE $w GROUP BY label ORDER BY total DESC", $p) as $r) { $rows[] = [$r['label'], (float)$r['total']]; }
    $tables[] = make_table('Expenses by category', ['Category', 'Expenses'], $rows, [1], 'Total');

    $rows = [];
    foreach (db_all("SELECT s.supplier_name, SUM(t.amount) AS total FROM $u JOIN suppliers s ON s.supplier_id = t.supplier_id WHERE $w GROUP BY s.supplier_name ORDER BY total DESC", $p) as $r) { $rows[] = [$r['supplier_name'], (float)$r['total']]; }
    $tables[] = make_table('Supplier payments by supplier', ['Supplier', 'Paid'], $rows, [1], 'Total');
    $notes[] = 'Operating expenses on the expense date; supplier invoices count when paid; voided entries are excluded.';
    if (!$incPayroll) { $notes[] = 'Payroll is restricted and is not included for your role.'; }

} else {
    $title = 'Profit and Loss Statement';
    $rows = [];
    foreach (db_all('SELECT e.payment_method, COALESCE(SUM(e.amount), 0) AS net FROM v_revenue_events e WHERE e.event_date BETWEEN ? AND ? GROUP BY e.payment_method ORDER BY net DESC', [$from, $to]) as $r) {
        $rows[] = [PAYMENT_METHODS[$r['payment_method']] ?? $r['payment_method'], (float)$r['net']];
    }
    $income = make_table('Income', ['Source', 'Amount'], $rows, [1], 'Total income (net revenue)');
    $u = expense_union($incPayroll);
    $rows = [];
    foreach (db_all("SELECT CASE WHEN t.source = 'payroll' THEN 'Payroll' ELSE COALESCE(c.name, 'Uncategorized') END AS label, SUM(t.amount) AS total
            FROM $u LEFT JOIN expense_categories c ON c.category_id = t.category_id WHERE t.d BETWEEN ? AND ? GROUP BY label ORDER BY total DESC", [$from, $to]) as $r) { $rows[] = [$r['label'], (float)$r['total']]; }
    $expense = make_table('Expenses', ['Category', 'Amount'], $rows, [1], 'Total expenses');
    $net = round($income['footer'][1] - $expense['footer'][1], 2);
    $tables = [$income, $expense, make_table('Result', ['Item', 'Amount'], [['Net financial result (income - expenses)', $net]], [1])];
    $notes[] = 'Income and expenses follow the reporting rules below. The result is not a tax or audited statement.';
    if (!$incPayroll) { $notes[] = 'Payroll is restricted and is NOT included for your role, so the result may be overstated.'; }
}

/* ------------------------------ CSV export: one flat, Excel-friendly table per report ------------------------------ */
if (($_GET['export'] ?? '') === 'csv') {
    if (!$canExport) { require_permission('exports.generate'); }
    $catName = $catId > 0 ? (string)db_value('SELECT name FROM expense_categories WHERE category_id = ?', [$catId]) : '';
    $supName = $supId > 0 ? (string)db_value('SELECT supplier_name FROM suppliers WHERE supplier_id = ?', [$supId]) : '';

    if ($report === 'revenue') {
        // every verified payment and completed refund in the period (the same rule as the screen), refunds as negative amounts
        $wP = "p.status = 'verified' AND p.payment_date BETWEEN ? AND ?"; $pP = [$from, $to];
        $wR = "r.status = 'completed' AND r.payment_id IS NOT NULL AND DATE(r.completed_at) BETWEEN ? AND ?"; $pR = [$from, $to];
        if ($method !== '') { $wP .= ' AND p.payment_method = ?'; $pP[] = $method; $wR .= ' AND p.payment_method = ?'; $pR[] = $method; }
        if ($source !== '') { $wP .= ' AND i.customer_type = ?'; $pP[] = $source; $wR .= ' AND i.customer_type = ?'; $pR[] = $source; }
        $data = db_all("SELECT p.payment_date AS d, 'Payment' AS kind, p.payment_number AS doc_no,
                               (SELECT rc.receipt_number FROM receipts rc WHERE rc.payment_id = p.payment_id) AS receipt_no,
                               i.invoice_number, g.guest_code, g.full_name, i.customer_type, p.payment_method, p.reference_number AS ref, p.amount AS amount
                        FROM payments p JOIN invoices i ON i.invoice_id = p.invoice_id JOIN guests g ON g.guest_id = i.guest_id WHERE $wP
                        UNION ALL
                        SELECT DATE(r.completed_at), 'Refund', r.refund_number, NULL, i.invoice_number, g.guest_code, g.full_name, i.customer_type, p.payment_method, r.reference_number, -r.amount
                        FROM refunds r JOIN payments p ON p.payment_id = r.payment_id JOIN invoices i ON i.invoice_id = p.invoice_id JOIN guests g ON g.guest_id = i.guest_id WHERE $wR
                        ORDER BY 1, 3", array_merge($pP, $pR));
        $rows = [];
        foreach ($data as $d) {
            $rows[] = [$d['d'], $d['kind'], $d['doc_no'], $d['receipt_no'], $d['invoice_number'], $d['guest_code'], $d['full_name'], csv_label($d['customer_type']),
                       PAYMENT_METHODS[$d['payment_method']] ?? csv_label($d['payment_method']), $d['ref'], $d['amount']];
        }
        csv_download('revenue_report', 'Revenue Report', ['Reporting period' => "$from to $to", 'Payment method' => $method !== '' ? PAYMENT_METHODS[$method] : '', 'Revenue source' => csv_label($source)],
            [['Date', 'date'], ['Transaction type', 'text'], ['Payment / refund No.', 'text'], ['Receipt No.', 'text'], ['Invoice No.', 'text'], ['Guest ID', 'text'], ['Guest name', 'text'],
             ['Customer type', 'text'], ['Payment method', 'text'], ['Reference No.', 'text'], ['Amount (PHP)', 'money', true]], $rows, null, 'NET REVENUE TOTAL');
    }

    if ($report === 'expenses') {
        $parts = []; $pp = [];
        if ($supId === 0) {                                                      // operating expenses (not tied to a supplier)
            $w = "x.status = 'recorded' AND x.expense_date BETWEEN ? AND ?"; $q1 = [$from, $to];
            if ($catId > 0) { $w .= ' AND x.category_id = ?'; $q1[] = $catId; }
            $parts[] = "SELECT x.expense_date AS d, 'Operating expense' AS kind, x.expense_number AS doc_no, c.name AS category, NULL AS supplier, x.description AS descr, x.reference_number AS ref, NULL AS method, x.amount AS amount
                        FROM expenses x JOIN expense_categories c ON c.category_id = x.category_id WHERE $w";
            $pp = array_merge($pp, $q1);
        }
        $w = 'sp.payment_date BETWEEN ? AND ?'; $q2 = [$from, $to];                // supplier payments
        if ($catId > 0) { $w .= ' AND si.category_id = ?'; $q2[] = $catId; }
        if ($supId > 0) { $w .= ' AND si.supplier_id = ?'; $q2[] = $supId; }
        $parts[] = "SELECT sp.payment_date AS d, 'Supplier payment' AS kind, si.invoice_number AS doc_no, COALESCE(c.name, 'Uncategorized') AS category, s.supplier_name AS supplier,
                           CONCAT('Payment for supplier invoice ', si.invoice_number) AS descr, sp.reference_number AS ref, sp.payment_method AS method, sp.amount AS amount
                    FROM supplier_payments sp JOIN supplier_invoices si ON si.supplier_invoice_id = sp.supplier_invoice_id JOIN suppliers s ON s.supplier_id = si.supplier_id
                    LEFT JOIN expense_categories c ON c.category_id = si.category_id WHERE $w";
        $pp = array_merge($pp, $q2);
        if ($incPayroll && $catId === 0 && $supId === 0) {                       // payroll: department totals only, never individual salaries
            $parts[] = "SELECT pr.pay_period_end AS d, 'Payroll (department total)' AS kind, CONCAT(pr.pay_period_start, ' to ', pr.pay_period_end) AS doc_no, 'Payroll' AS category, NULL AS supplier, pr.department AS descr, NULL AS ref, NULL AS method, pr.total_amount AS amount
                        FROM payroll_expenses pr WHERE pr.status = 'recorded' AND pr.pay_period_end BETWEEN ? AND ?";
            $pp = array_merge($pp, [$from, $to]);
            if (revision2_ready()) {
                $parts[] = "SELECT pp.end_date AS d, 'Payroll (paid)' AS kind, pp.period_label AS doc_no, 'Payroll' AS category, NULL AS supplier, CONCAT(e.department, ' - gross pay') AS descr, NULL AS ref, NULL AS method, SUM(r.gross_pay) AS amount
                            FROM payroll_records r JOIN payroll_periods pp ON pp.payroll_period_id = r.payroll_period_id JOIN employees e ON e.employee_id = r.employee_id
                            WHERE r.status = 'paid' AND pp.end_date BETWEEN ? AND ? GROUP BY pp.payroll_period_id, pp.end_date, pp.period_label, e.department";
                $pp = array_merge($pp, [$from, $to]);
            }
        }
        $data = db_all(implode(' UNION ALL ', array_map(static fn($x) => "($x)", $parts)) . ' ORDER BY 1, 3', $pp);
        $rows = [];
        foreach ($data as $d) {
            $rows[] = [$d['d'], $d['kind'], $d['doc_no'], $d['category'], $d['supplier'], $d['descr'], $d['ref'],
                       $d['method'] === null ? null : (SUPPLIER_PAYMENT_METHODS[$d['method']] ?? csv_label($d['method'])), $d['amount']];
        }
        csv_download('expense_report', 'Expense Report', ['Reporting period' => "$from to $to", 'Category' => $catName, 'Supplier' => $supName,
                'Note' => $incPayroll ? '' : 'Payroll is restricted and not included'],
            [['Date', 'date'], ['Expense type', 'text'], ['Document / invoice No.', 'text'], ['Category', 'text'], ['Supplier', 'text'], ['Description', 'text'],
             ['Reference No.', 'text'], ['Payment method', 'text'], ['Amount (PHP)', 'money', true]], $rows, null, 'TOTAL EXPENSES');
    }

    // profit and loss: a statement, not a transaction list -> Section / Line item / Amount
    $rows = [];
    foreach ($tables[0]['rows'] as $r) { $rows[] = ['Income', $r[0], $r[1]]; }
    $rows[] = ['Income', 'TOTAL INCOME (net revenue)', $tables[0]['footer'][1]];
    foreach ($tables[1]['rows'] as $r) { $rows[] = ['Expenses', $r[0], $r[1]]; }
    $rows[] = ['Expenses', 'TOTAL EXPENSES', $tables[1]['footer'][1]];
    $rows[] = ['Result', 'NET FINANCIAL RESULT (income - expenses)', $tables[2]['rows'][0][1]];
    csv_download('profit_and_loss_report', 'Profit and Loss Statement', ['Reporting period' => "$from to $to", 'Note' => $incPayroll ? '' : 'Payroll is restricted and NOT included'],
        [['Section', 'text'], ['Line item', 'text'], ['Amount (PHP)', 'money']], $rows);
}

$cats = db_all('SELECT category_id, name FROM expense_categories ORDER BY name');
$suppliers = db_all('SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name');
$pageTitle = 'Reports';
include __DIR__ . '/includes/header.php';
$exportQs = qs(['export' => 'csv']);
?>
<form method="get" class="filter-bar mb-3 no-print" id="reportForm">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Report</label>
            <select name="report" id="rpType" class="form-select form-select-sm">
                <option value="revenue" <?= $report === 'revenue' ? 'selected' : '' ?>>Revenue report</option>
                <option value="expenses" <?= $report === 'expenses' ? 'selected' : '' ?>>Expense report</option>
                <option value="pnl" <?= $report === 'pnl' ? 'selected' : '' ?>>Profit and loss</option></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm" required></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm" required></div>
        <div class="col-6 col-md-2 rp-opt" data-for="revenue expenses"><label class="form-label small mb-1">Group by</label>
            <select name="gran" class="form-select form-select-sm"><?php foreach (['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly', 'year' => 'Yearly'] as $k => $v): ?><option value="<?= e($k) ?>" <?= $gran === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3 rp-opt" data-for="revenue"><label class="form-label small mb-1">Payment method</label>
            <select name="method" class="form-select form-select-sm"><option value="">All</option><?php foreach (PAYMENT_METHODS as $k => $v): ?><option value="<?= e($k) ?>" <?= $method === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3 rp-opt" data-for="revenue"><label class="form-label small mb-1">Revenue source</label>
            <select name="source" class="form-select form-select-sm"><option value="">All</option><option value="guest" <?= $source === 'guest' ? 'selected' : '' ?>>Guests</option><option value="corporate" <?= $source === 'corporate' ? 'selected' : '' ?>>Corporate clients</option><option value="other" <?= $source === 'other' ? 'selected' : '' ?>>Other</option></select></div>
        <div class="col-6 col-md-3 rp-opt" data-for="expenses"><label class="form-label small mb-1">Category</label>
            <select name="category" class="form-select form-select-sm"><option value="0">All</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['category_id'] ?>" <?= $catId === (int)$c['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3 rp-opt" data-for="expenses"><label class="form-label small mb-1">Supplier</label>
            <select name="supplier" class="form-select form-select-sm"><option value="0">All</option><?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['supplier_id'] ?>" <?= $supId === (int)$s['supplier_id'] ? 'selected' : '' ?>><?= e($s['supplier_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-auto d-flex gap-2"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Generate</button></div>
    </div>
</form>

<div class="mb-3 no-print"><?= render_export_menu('?' . $exportQs, $canExport) ?></div>

<div class="app-card"><div class="card-body">
    <div class="mb-3 pb-3" style="border-bottom:1px solid var(--line)">
        <?= render_document_brand() ?>
        <h2 class="h4 mb-1"><?= e($title) ?></h2>
        <div class="small text-muted">Reporting period: <?= e(format_date($from)) ?> to <?= e(format_date($to)) ?> &middot; Generated <?= e(date('M d, Y g:i A')) ?> by <?= e($me['name']) ?></div>
    </div>
    <?php foreach ($tables as $t): ?>
        <h3 class="card-title-serif mt-3 mb-2"><?= e($t['heading']) ?></h3>
        <?php if ($t['note']): ?><div class="small text-muted mb-2"><?= e($t['note']) ?></div><?php endif; ?>
        <div class="table-responsive mb-2"><table class="table table-sm">
            <thead><tr><?php foreach ($t['headers'] as $i => $h): ?><th class="<?= in_array($i, $t['money'], true) ? 'amount' : '' ?>"><?= e($h) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php if (!$t['rows']): ?><tr><td colspan="<?= count($t['headers']) ?>" class="text-center text-muted py-3">No data for this selection.</td></tr><?php endif; ?>
            <?php foreach ($t['rows'] as $row): ?><tr><?php foreach ($row as $i => $c): ?><td class="<?= in_array($i, $t['money'], true) ? 'amount' : '' ?>"><?= in_array($i, $t['money'], true) ? e(peso($c)) : e($c) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
            </tbody>
            <?php if ($t['footer']): ?><tfoot><tr class="fw-bold"><?php foreach ($t['footer'] as $i => $c): ?><td class="<?= in_array($i, $t['money'], true) ? 'amount' : '' ?>"><?= in_array($i, $t['money'], true) ? e(peso($c)) : e($c) ?></td><?php endforeach; ?></tr></tfoot><?php endif; ?>
        </table></div>
    <?php endforeach; ?>
    <?php foreach ($notes as $n): ?><div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i><?= e($n) ?></div><?php endforeach; ?>
    <div class="small mt-3 pt-3" style="border-top:1px solid var(--line)">
        <strong>Revenue rule:</strong> <?= e(get_setting('revenue_recognition_rule')) ?><br>
        <strong>Expense rule:</strong> <?= e(get_setting('expense_recognition_rule')) ?>
    </div>
</div></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sel = document.getElementById('rpType');
    function sync() { document.querySelectorAll('.rp-opt').forEach(function (el) { el.classList.toggle('d-none', el.dataset.for.split(' ').indexOf(sel.value) === -1); }); }
    sel.addEventListener('change', sync); sync();
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
