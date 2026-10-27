<?php
/**
 * payroll-expenses.php - Payroll Expense Recording (module 24)
 * Records payroll totals by pay period and department. RESTRICTED: only roles with "payroll.view" can open this page
 * and only "payroll.manage" can record or void entries. The system stores department totals, not individual salaries.
 * Payroll is recognized on the pay-period end date. Entries are never deleted; a mistake is voided with a reason.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('payroll.view');

$pdo = db();
$me  = current_user();
$canManage = has_permission('payroll.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!$canManage) { throw new RuntimeException('Your role cannot record or change payroll entries.'); }
        $pdo->beginTransaction();

        if ($action === 'record') {
            $start = (string)($_POST['pay_period_start'] ?? '');
            $end   = (string)($_POST['pay_period_end'] ?? '');
            $dept  = clean_text($_POST['department'] ?? '', 80);
            $amount = parse_money($_POST['total_amount'] ?? '');
            $notes = clean_text($_POST['notes'] ?? '', 255);
            if (!valid_date($start) || !valid_date($end)) { throw new RuntimeException('Enter valid pay period dates.'); }
            if ($end < $start) { throw new RuntimeException('The pay period end date cannot be before the start date.'); }
            if ($end > date('Y-m-d')) { throw new RuntimeException('Payroll can only be recorded after the pay period has ended.'); }
            if ((strtotime($end) - strtotime($start)) / 86400 > 62) { throw new RuntimeException('A pay period cannot be longer than 62 days.'); }
            if (mb_strlen($dept) < 2) { throw new RuntimeException('Enter the department.'); }
            if ($amount === null) { throw new RuntimeException('Enter a valid payroll total.'); }
            $overlap = db_value("SELECT COUNT(*) FROM payroll_expenses WHERE status = 'recorded' AND LOWER(department) = LOWER(?) AND pay_period_start <= ? AND pay_period_end >= ?", [$dept, $end, $start]);
            if ($overlap > 0) { throw new RuntimeException('Payroll for this department already covers part of that pay period. Void the old entry first if it is wrong.'); }
            $periodId = require_open_period($end);
            $pdo->prepare('INSERT INTO payroll_expenses (period_id, pay_period_start, pay_period_end, department, total_amount, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$periodId, $start, $end, $dept, $amount, $notes !== '' ? $notes : null, (int)$me['id']]);
            audit_log('payroll_recorded', 'payroll_expenses', (int)$pdo->lastInsertId(), null, ['department' => $dept, 'period' => "$start to $end", 'total' => $amount]);
            $pdo->commit();
            set_flash('success', "Payroll for $dept (" . format_date($start, 'M d') . ' - ' . format_date($end, 'M d, Y') . ') of ' . peso($amount) . ' was recorded.');

        } elseif ($action === 'void') {
            $reason = clean_text($_POST['reason'] ?? '', 255);
            if (mb_strlen($reason) < 5) { throw new RuntimeException('Enter the reason for voiding the entry (at least 5 characters).'); }
            $p = db_one('SELECT * FROM payroll_expenses WHERE payroll_id = ? FOR UPDATE', [(int)($_POST['payroll_id'] ?? 0)]);
            if (!$p) { throw new RuntimeException('Payroll entry not found.'); }
            if ($p['status'] === 'void') { throw new RuntimeException('This entry is already void.'); }
            require_open_period_id((int)$p['period_id']);
            $pdo->prepare("UPDATE payroll_expenses SET status = 'void', void_reason = ? WHERE payroll_id = ?")->execute([$reason, (int)$p['payroll_id']]);
            audit_log('payroll_voided', 'payroll_expenses', $p['payroll_id'], ['status' => 'recorded', 'total' => $p['total_amount']], ['status' => 'void'], $reason);
            $pdo->commit();
            set_flash('success', 'The payroll entry for ' . $p['department'] . ' was voided and is excluded from totals.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The action could not be completed. Please try again.');
    }
    $ret = ltrim((string)($_POST['return'] ?? ''), '?');
    redirect('payroll-expenses.php' . ($ret !== '' ? '?' . $ret : ''));
}

// Access to payroll data is logged (at most once every 30 minutes per session)
if (time() - (int)($_SESSION['payroll_view_logged'] ?? 0) > 1800) {
    audit_log('payroll_viewed', 'payroll_expenses', null, null, null, 'Payroll page opened');
    $_SESSION['payroll_view_logged'] = time();
}

/* ------------------------------ Filters ------------------------------ */
$dept = clean_text($_GET['department'] ?? '', 80);
$stat = (string)($_GET['status'] ?? 'recorded');
$q    = clean_text($_GET['q'] ?? '', 60);
$from = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$to   = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
$page = current_page_number();
$perPage = 15;
$where = ['1 = 1']; $params = [];
if ($dept !== '') { $where[] = 'p.department = ?'; $params[] = $dept; }
if (in_array($stat, ['recorded', 'void'], true)) { $where[] = 'p.status = ?'; $params[] = $stat; } else { $stat = 'all'; }
if ($q !== '') { $like = '%' . $q . '%'; $where[] = '(p.department LIKE ? OR p.notes LIKE ?)'; array_push($params, $like, $like); }
if ($from !== '') { $where[] = 'p.pay_period_end >= ?'; $params[] = $from; }
if ($to !== '')   { $where[] = 'p.pay_period_end <= ?'; $params[] = $to; }
$whereSql = implode(' AND ', $where);
$fromSql = "FROM payroll_expenses p JOIN users u ON u.user_id = p.recorded_by WHERE $whereSql";
$total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
$sum = (float)db_value("SELECT COALESCE(SUM(CASE WHEN p.status = 'recorded' THEN p.total_amount END), 0) $fromSql", $params);
$rows = db_all("SELECT p.*, u.full_name AS recorder $fromSql ORDER BY p.pay_period_end DESC, p.payroll_id DESC", $params, $perPage, ($page - 1) * $perPage);
$byDept = db_all("SELECT p.department, COUNT(*) AS n, SUM(p.total_amount) AS total FROM payroll_expenses p
                  WHERE $whereSql AND p.status = 'recorded' GROUP BY p.department ORDER BY total DESC", $params);
$departments = array_column(db_all('SELECT DISTINCT department FROM payroll_expenses ORDER BY department'), 'department');

$pageTitle = 'Payroll Expenses';
include __DIR__ . '/includes/header.php';
$returnQs = qs();
?>
<div class="alert alert-warning py-2 small"><i class="bi bi-shield-lock me-1"></i>Restricted information. Access to this page is recorded in the audit trail.</div>
<div class="alert alert-info py-2 small">This page keeps department totals. Payroll calculated from each employee's own pay rate is in <a href="<?= e(url('payroll.php')) ?>">Payroll</a>.</div>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
        <span class="summary-chip"><strong><?= $total ?></strong> entr<?= $total === 1 ? 'y' : 'ies' ?></span>
        <span class="summary-chip">Recorded payroll <strong><?= e(peso($sum)) ?></strong></span>
    </div>
    <?php if ($canManage): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#payrollModal"><i class="bi bi-plus-lg me-1"></i>Record payroll</button><?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-4"><div class="app-card h-100"><div class="card-header"><h3 class="card-title-serif">By department</h3></div>
        <div class="table-responsive"><table class="table"><tbody>
            <?php if (!$byDept): ?><tr><td class="text-center text-muted py-3">No payroll recorded.</td></tr><?php endif; ?>
            <?php foreach ($byDept as $d): ?><tr><td><?= e($d['department']) ?><div class="small text-muted"><?= (int)$d['n'] ?> entr<?= (int)$d['n'] === 1 ? 'y' : 'ies' ?></div></td><td class="amount"><strong><?= e(peso($d['total'])) ?></strong></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <div class="col-lg-8">
        <form method="get" class="filter-bar h-100">
            <div class="row g-2 align-items-end">
                <div class="col-md-6"><label class="form-label small mb-1">Search</label><input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Department or notes"></div>
                <div class="col-6 col-md-3"><label class="form-label small mb-1">Department</label>
                    <select name="department" class="form-select form-select-sm"><option value="">All</option>
                        <?php foreach ($departments as $d): ?><option <?= $dept === $d ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-md-3"><label class="form-label small mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm"><?php foreach (['recorded' => 'Recorded', 'void' => 'Void', 'all' => 'All'] as $k => $v): ?><option value="<?= e($k) ?>" <?= $stat === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-md-4"><label class="form-label small mb-1">Period ending from</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
                <div class="col-6 col-md-4"><label class="form-label small mb-1">Period ending to</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
                <div class="col-md-4 d-grid"><button class="btn btn-primary btn-sm" type="submit">Filter</button></div>
            </div>
        </form>
    </div>
</div>

<div class="app-card"><div class="table-responsive">
    <table class="table table-hover">
        <thead><tr><th>Pay period</th><th>Department</th><th class="amount">Total</th><th>Notes</th><th>Recorded by</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No payroll entries match your filters.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= $r['status'] === 'void' ? 'row-locked' : '' ?>">
                <td><?= e(format_date($r['pay_period_start'], 'M d')) ?> - <?= e(format_date($r['pay_period_end'], 'M d, Y')) ?></td>
                <td><strong><?= e($r['department']) ?></strong></td>
                <td class="amount"><strong><?= e(peso($r['total_amount'])) ?></strong></td>
                <td class="small"><?= e($r['notes'] ?? '') ?><?php if ($r['void_reason']): ?><div class="value-negative">Void: <?= e($r['void_reason']) ?></div><?php endif; ?></td>
                <td class="small"><?= e($r['recorder']) ?></td>
                <td><?= status_badge($r['status']) ?></td>
                <td class="text-end"><?php if ($canManage && $r['status'] === 'recorded'): ?>
                    <button class="btn btn-outline-secondary btn-sm void-btn" data-bs-toggle="modal" data-bs-target="#voidModal" data-id="<?= (int)$r['payroll_id'] ?>" data-desc="<?= e($r['department'] . ' - ' . peso($r['total_amount'])) ?>">Void</button><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<?= render_pager($total, $page, $perPage) ?>

<?php if ($canManage): ?>
<div class="modal fade" id="payrollModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="record"><input type="hidden" name="return" value="<?= e($returnQs) ?>">
        <div class="modal-header"><h5 class="modal-title">Record payroll expense</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-6"><label class="form-label">Pay period start</label><input type="date" name="pay_period_start" class="form-control" max="<?= e(date('Y-m-d')) ?>" required></div>
            <div class="col-6"><label class="form-label">Pay period end</label><input type="date" name="pay_period_end" class="form-control" max="<?= e(date('Y-m-d')) ?>" required></div>
            <div class="col-6"><label class="form-label">Department</label><input type="text" name="department" list="deptList" maxlength="80" class="form-control" required>
                <datalist id="deptList"><?php foreach ($departments as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist></div>
            <div class="col-6"><label class="form-label">Total payroll (&#8369;)</label><input type="number" name="total_amount" step="0.01" min="0.01" class="form-control" required></div>
            <div class="col-12"><label class="form-label">Notes (optional)</label><input type="text" name="notes" maxlength="255" class="form-control"></div>
        </div><div class="form-text mt-2">Enter the department total only. Individual salaries are not stored in this system.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save payroll</button></div>
    </form></div>
</div>
<div class="modal fade" id="voidModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="return" value="<?= e($returnQs) ?>"><input type="hidden" name="payroll_id" id="pvId">
        <div class="modal-header"><h5 class="modal-title">Void payroll entry</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><p class="fw-semibold" id="pvDesc"></p><p class="small text-muted">The entry stays in the records, marked void, and is left out of expense totals.</p>
            <label class="form-label">Reason</label><textarea name="reason" class="form-control" rows="3" maxlength="255" required></textarea></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep entry</button><button class="btn btn-danger" type="submit">Void entry</button></div>
    </form></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.void-btn').forEach(function (b) {
        b.addEventListener('click', function () { document.getElementById('pvId').value = b.dataset.id; document.getElementById('pvDesc').textContent = b.dataset.desc; });
    });
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
