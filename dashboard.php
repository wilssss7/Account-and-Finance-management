<?php
/**
 * dashboard.php - Accounts and Finance Dashboard (module 1)
 * Every figure comes from live database queries (views v_revenue_events, v_expense_events, v_invoice_balances).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';

require_login();
require_permission('dashboard.view');

$pdo = db();
$incPayroll = has_permission('payroll.view'); // payroll amounts are restricted

/** Sum of recognized revenue or expenses between two dates (inclusive). */
function range_sum(PDO $pdo, string $kind, string $from, string $to, bool $incPayroll): float
{
    if ($kind === 'revenue') {
        $sql = 'SELECT COALESCE(SUM(amount), 0) FROM v_revenue_events WHERE event_date BETWEEN ? AND ?';
    } else {
        $sql = 'SELECT COALESCE(SUM(amount), 0) FROM v_expense_events WHERE event_date BETWEEN ? AND ?';
        if (!$incPayroll) {
            $sql .= " AND source <> 'payroll'";
        }
    }
    $st = $pdo->prepare($sql);
    $st->execute([$from, $to]);
    return (float)$st->fetchColumn();
}

/* ---- Which month to show: current month, or the latest month that has data ---- */
$latest = $pdo->query(
    'SELECT MAX(d) FROM (SELECT MAX(event_date) AS d FROM v_revenue_events
                         UNION ALL SELECT MAX(event_date) FROM v_expense_events) t'
)->fetchColumn();
$thisMonth = date('Y-m');
$defaultMonth = ($latest && substr((string)$latest, 0, 7) < $thisMonth) ? substr((string)$latest, 0, 7) : $thisMonth;
$month = (string)($_GET['month'] ?? $defaultMonth);
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = $defaultMonth;
}
$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$refDate    = ($month === $thisMonth) ? date('Y-m-d') : $monthEnd;
$weekStart  = date('Y-m-d', strtotime('monday this week', strtotime($refDate)));
$weekEnd    = date('Y-m-d', strtotime($weekStart . ' +6 days'));

/* ---- Headline figures for the selected month ---- */
$revenue  = range_sum($pdo, 'revenue', $monthStart, $monthEnd, $incPayroll);
$expenses = range_sum($pdo, 'expense', $monthStart, $monthEnd, $incPayroll);
$net      = $revenue - $expenses;

$out = $pdo->query(
    "SELECT COUNT(*) AS c, COALESCE(SUM(outstanding_balance), 0) AS s,
            COALESCE(SUM(CASE WHEN payment_status = 'overdue' THEN outstanding_balance ELSE 0 END), 0) AS overdue
     FROM v_invoice_balances WHERE payment_status IN ('unpaid', 'partially_paid', 'overdue')"
)->fetch();

$pendPay = $pdo->query("SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS s FROM payments WHERE status = 'pending'")->fetch();
$pendRefunds = (int)$pdo->query("SELECT COUNT(*) FROM refunds WHERE status = 'pending'")->fetchColumn();
$pendDiscounts = (int)$pdo->query("SELECT COUNT(*) FROM discounts WHERE status = 'pending'")->fetchColumn();
$pendAdjust = (int)$pdo->query("SELECT COUNT(*) FROM billing_adjustments WHERE status = 'pending'")->fetchColumn();
$pendApprovals = $pendRefunds + $pendDiscounts + $pendAdjust;

/* ---- Daily / weekly / monthly summary ---- */
$summaries = [
    ['Day',   $refDate,    $refDate,   format_date($refDate)],
    ['Week',  $weekStart,  $weekEnd,   format_date($weekStart, 'M d') . ' - ' . format_date($weekEnd, 'M d')],
    ['Month', $monthStart, $monthEnd,  format_date($monthStart, 'F Y')],
];
foreach ($summaries as &$s) {
    $s[4] = range_sum($pdo, 'revenue', $s[1], $s[2], $incPayroll);
    $s[5] = range_sum($pdo, 'expense', $s[1], $s[2], $incPayroll);
}
unset($s);

/* ---- Chart data: last 6 months ---- */
$trendFrom = date('Y-m-01', strtotime($monthStart . ' -5 months'));
$revByMonth = $pdo->prepare("SELECT DATE_FORMAT(event_date, '%Y-%m') AS ym, SUM(amount) AS t FROM v_revenue_events
                             WHERE event_date BETWEEN ? AND ? GROUP BY ym");
$revByMonth->execute([$trendFrom, $monthEnd]);
$revMap = $revByMonth->fetchAll(PDO::FETCH_KEY_PAIR);

$expSql = "SELECT DATE_FORMAT(event_date, '%Y-%m') AS ym, SUM(amount) AS t FROM v_expense_events WHERE event_date BETWEEN ? AND ?"
        . ($incPayroll ? '' : " AND source <> 'payroll'") . ' GROUP BY ym';
$expByMonth = $pdo->prepare($expSql);
$expByMonth->execute([$trendFrom, $monthEnd]);
$expMap = $expByMonth->fetchAll(PDO::FETCH_KEY_PAIR);

$trend = ['labels' => [], 'revenue' => [], 'expenses' => []];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime($monthStart . " -$i months"));
    $trend['labels'][]   = date('M Y', strtotime($ym . '-01'));
    $trend['revenue'][]  = round((float)($revMap[$ym] ?? 0), 2);
    $trend['expenses'][] = round((float)($expMap[$ym] ?? 0), 2);
}

$mst = $pdo->prepare('SELECT payment_method, SUM(amount) AS t FROM v_revenue_events
                      WHERE event_date BETWEEN ? AND ? GROUP BY payment_method HAVING t > 0 ORDER BY t DESC');
$mst->execute([$monthStart, $monthEnd]);
$methods = ['labels' => [], 'values' => []];
foreach ($mst->fetchAll() as $row) {
    $methods['labels'][] = ucwords(str_replace('_', ' ', $row['payment_method']));
    $methods['values'][] = round((float)$row['t'], 2);
}

$catSql = "SELECT CASE WHEN e.source = 'payroll' THEN 'Payroll' ELSE COALESCE(c.name, 'Uncategorized') END AS label, SUM(e.amount) AS t
           FROM v_expense_events e LEFT JOIN expense_categories c ON c.category_id = e.category_id
           WHERE e.event_date BETWEEN ? AND ?" . ($incPayroll ? '' : " AND e.source <> 'payroll'") . '
           GROUP BY label ORDER BY t DESC';
$cst = $pdo->prepare($catSql);
$cst->execute([$monthStart, $monthEnd]);
$categories = ['labels' => [], 'values' => []];
foreach ($cst->fetchAll() as $row) {
    $categories['labels'][] = $row['label'];
    $categories['values'][] = round((float)$row['t'], 2);
}

$chartData = ['trend' => $trend, 'methods' => $methods, 'categories' => $categories];

/* ---- Recent activity ---- */
$activities = $pdo->query(
    "SELECT a.action_type, a.table_name, a.record_id, a.reason, a.created_at, COALESCE(u.full_name, 'System') AS who
     FROM audit_logs a LEFT JOIN users u ON u.user_id = a.user_id
     WHERE a.action_type NOT IN ('login', 'logout', 'login_failed')
     ORDER BY a.created_at DESC, a.log_id DESC LIMIT 8"
)->fetchAll();

$recentPayments = $pdo->query(
    'SELECT p.payment_number, p.amount, p.payment_method, p.status, p.payment_date, i.invoice_number
     FROM payments p JOIN invoices i ON i.invoice_id = p.invoice_id
     ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 6'
)->fetchAll();

$pageTitle = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Financial overview</h2>
        <div class="text-muted small">Showing <?= e(format_date($monthStart, 'F Y')) ?>
            <?php if (!$incPayroll): ?> &middot; payroll amounts are excluded for your role<?php endif; ?></div>
    </div>
    <form method="get" class="d-flex align-items-end gap-2">
        <div>
            <label for="month" class="form-label small mb-1">Month</label>
            <input type="month" class="form-control form-control-sm" id="month" name="month" value="<?= e($month) ?>">
        </div>
        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
    </form>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-4">
        <div class="app-card stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="stat-label">Total revenue</div><div class="stat-value"><?= e(peso($revenue)) ?></div></div>
                <span class="stat-icon"><i class="bi bi-graph-up-arrow"></i></span>
            </div>
            <div class="stat-note mt-1">Verified payments less completed refunds</div>
        </div>
    </div>
    <div class="col-6 col-xl-4">
        <div class="app-card stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="stat-label">Total expenses</div><div class="stat-value"><?= e(peso($expenses)) ?></div></div>
                <span class="stat-icon"><i class="bi bi-wallet2"></i></span>
            </div>
            <div class="stat-note mt-1">Operating, supplier payments<?= $incPayroll ? ' and payroll' : '' ?></div>
        </div>
    </div>
    <div class="col-6 col-xl-4">
        <div class="app-card stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="stat-label">Net financial result</div>
                    <div class="stat-value <?= $net >= 0 ? 'value-positive' : 'value-negative' ?>"><?= e(peso($net)) ?></div></div>
                <span class="stat-icon"><i class="bi bi-calculator"></i></span>
            </div>
            <div class="stat-note mt-1">Revenue minus expenses</div>
        </div>
    </div>
    <div class="col-6 col-xl-4">
        <div class="app-card stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="stat-label">Outstanding balances</div><div class="stat-value"><?= e(peso($out['s'])) ?></div></div>
                <span class="stat-icon"><i class="bi bi-hourglass-split"></i></span>
            </div>
            <div class="stat-note mt-1"><?= (int)$out['c'] ?> open invoice(s) &middot; <span class="value-negative"><?= e(peso($out['overdue'])) ?> overdue</span></div>
        </div>
    </div>
    <div class="col-6 col-xl-4">
        <div class="app-card stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="stat-label">Pending payments</div><div class="stat-value"><?= (int)$pendPay['c'] ?></div></div>
                <span class="stat-icon"><i class="bi bi-credit-card"></i></span>
            </div>
            <div class="stat-note mt-1"><?= e(peso($pendPay['s'])) ?> awaiting verification</div>
        </div>
    </div>
    <div class="col-6 col-xl-4">
        <div class="app-card stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="stat-label">Pending approvals</div><div class="stat-value"><?= $pendApprovals ?></div></div>
                <span class="stat-icon"><i class="bi bi-check2-square"></i></span>
            </div>
            <div class="stat-note mt-1"><?= $pendRefunds ?> refund(s) &middot; <?= $pendDiscounts ?> discount(s) &middot; <?= $pendAdjust ?> adjustment(s)</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="app-card h-100">
            <div class="card-header"><h3 class="card-title-serif">Revenue and expenses, last 6 months</h3></div>
            <div class="card-body"><div class="chart-box"><canvas id="trendChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="app-card h-100">
            <div class="card-header"><h3 class="card-title-serif">Summary</h3></div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Period</th><th class="amount">Revenue</th><th class="amount">Expenses</th><th class="amount">Net</th></tr></thead>
                    <tbody>
                    <?php foreach ($summaries as $s): $n = $s[4] - $s[5]; ?>
                        <tr>
                            <td><strong><?= e($s[0]) ?></strong><div class="small text-muted"><?= e($s[3]) ?></div></td>
                            <td class="amount"><?= e(peso($s[4])) ?></td>
                            <td class="amount"><?= e(peso($s[5])) ?></td>
                            <td class="amount <?= $n >= 0 ? 'value-positive' : 'value-negative' ?>"><?= e(peso($n)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="app-card h-100">
            <div class="card-header"><h3 class="card-title-serif">Revenue by payment method</h3></div>
            <div class="card-body"><div class="chart-box"><canvas id="methodChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="app-card h-100">
            <div class="card-header"><h3 class="card-title-serif">Expenses by category</h3></div>
            <div class="card-body"><div class="chart-box"><canvas id="categoryChart"></canvas></div></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="app-card h-100">
            <div class="card-header"><h3 class="card-title-serif">Recent financial activities</h3></div>
            <div class="card-body">
                <?php if (!$activities): ?>
                    <div class="text-muted">No activity recorded yet.</div>
                <?php else: ?>
                    <ul class="activity-list">
                        <?php foreach ($activities as $a): ?>
                            <li>
                                <span class="activity-dot"><i class="bi bi-journal-check"></i></span>
                                <div>
                                    <div class="activity-title"><?= e(ucfirst(str_replace('_', ' ', $a['action_type']))) ?></div>
                                    <div class="activity-meta"><?= e($a['who']) ?> &middot; <?= e(format_date($a['created_at'], 'M d, Y g:i A')) ?>
                                        <?php if ($a['reason']): ?> &middot; <?= e($a['reason']) ?><?php endif; ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="app-card h-100">
            <div class="card-header"><h3 class="card-title-serif">Recent payments</h3></div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Payment</th><th>Invoice</th><th>Method</th><th class="amount">Amount</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$recentPayments): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No payments recorded yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recentPayments as $p): ?>
                        <tr>
                            <td><?= e($p['payment_number']) ?><div class="small text-muted"><?= e(format_date($p['payment_date'])) ?></div></td>
                            <td><?= e($p['invoice_number']) ?></td>
                            <td><?= e(ucwords(str_replace('_', ' ', $p['payment_method']))) ?></td>
                            <td class="amount"><?= e(peso($p['amount'])) ?></td>
                            <td><?= status_badge($p['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>window.DASH = <?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
