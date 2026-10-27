<?php
/**
 * budgets.php - Budget Monitoring (module 34)
 * Monthly and yearly budgets per expense category, compared with ACTUAL eligible expenses:
 * operating expenses (not void) + supplier payments, grouped by category (v_expense_events).
 * Payroll has no category, so it is not part of category budgets.
 * Budget remaining = budget amount - actual eligible expenses.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('budgets.manage');
require_revision2();
$canApproveBudget = has_permission('budgets.approve');
$nearPct = max(50, min(99, (int)get_setting('budget_near_limit_percent', '90')));

function budget_back(array $b): string
{
    return $b['period_type'] === 'monthly' ? '?type=monthly&month=' . sprintf('%04d-%02d', $b['budget_year'], $b['budget_month']) : '?type=yearly&year=' . $b['budget_year'];
}
/** Actual eligible spending of a budget's category in its period (same rule as the page and the notifications). */
function budget_actual(array $b): float
{
    $from = $b['period_type'] === 'monthly' ? sprintf('%04d-%02d-01', $b['budget_year'], $b['budget_month']) : $b['budget_year'] . '-01-01';
    $to   = $b['period_type'] === 'monthly' ? date('Y-m-t', strtotime($from)) : $b['budget_year'] . '-12-31';
    return (float)db_value('SELECT COALESCE(SUM(amount), 0) FROM v_expense_events WHERE category_id = ? AND event_date BETWEEN ? AND ?', [(int)$b['category_id'], $from, $to]);
}

$pdo = db();
$me  = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $back = '';
    try {
        $pdo->beginTransaction();
        if ($action === 'save_budget') {
            $catId  = (int)($_POST['category_id'] ?? 0);
            $type   = (string)($_POST['period_type'] ?? '');
            $year   = (int)($_POST['budget_year'] ?? 0);
            $month  = $type === 'monthly' ? (int)($_POST['budget_month'] ?? 0) : 0;
            $amount = parse_money($_POST['amount'] ?? '');
            $notes  = clean_text($_POST['notes'] ?? '', 255);
            if (db_value('SELECT COUNT(*) FROM expense_categories WHERE category_id = ?', [$catId]) == 0) { throw new RuntimeException('Choose an expense category.'); }
            if (!in_array($type, ['monthly', 'yearly'], true)) { throw new RuntimeException('Choose monthly or yearly.'); }
            if ($year < 2000 || $year > 2100) { throw new RuntimeException('Enter a valid year.'); }
            if ($type === 'monthly' && ($month < 1 || $month > 12)) { throw new RuntimeException('Choose the month.'); }
            if ($amount === null) { throw new RuntimeException('Enter a valid budget amount.'); }
            $old = db_one('SELECT * FROM budgets WHERE category_id = ? AND period_type = ? AND budget_year = ? AND budget_month = ? FOR UPDATE', [$catId, $type, $year, $month]);
            if ($old) {
                // An existing budget is not overwritten: the change becomes an adjustment request that a manager approves.
                if (abs((float)$old['amount'] - $amount) < 0.005) { throw new RuntimeException('That is already the allocated amount.'); }
                if (mb_strlen($notes) < 10) { throw new RuntimeException('A budget already exists for this category and period. To change it, explain the reason in Notes (at least 10 characters); the change then waits for approval.'); }
                if (db_value("SELECT COUNT(*) FROM budget_adjustments WHERE budget_id = ? AND status = 'pending'", [(int)$old['budget_id']]) > 0) { throw new RuntimeException('This budget already has a change waiting for approval.'); }
                $pdo->prepare('INSERT INTO budget_adjustments (budget_id, old_amount, new_amount, reason, requested_by) VALUES (?, ?, ?, ?, ?)')->execute([(int)$old['budget_id'], $old['amount'], $amount, $notes, (int)$me['id']]);
                audit_log('budget_adjustment_requested', 'budget_adjustments', (int)$pdo->lastInsertId(), ['amount' => $old['amount']], ['proposed_amount' => $amount], $notes);
                $msg = 'A budget already exists for this category and period, so your change was sent for approval. The current budget stays in force until it is approved.';
            } else {
                $pdo->prepare('INSERT INTO budgets (category_id, period_type, budget_year, budget_month, amount, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$catId, $type, $year, $month, $amount, $notes !== '' ? $notes : null, (int)$me['id']]);
                audit_log('budget_created', 'budgets', (int)$pdo->lastInsertId(), null, ['category_id' => $catId, 'type' => $type, 'year' => $year, 'month' => $month, 'amount' => $amount]);
                $msg = 'The budget was created.';
            }
            $pdo->commit();
            set_flash('success', $msg);
            $back = $type === 'monthly' ? '?type=monthly&month=' . sprintf('%04d-%02d', $year, $month) : '?type=yearly&year=' . $year;

        } elseif ($action === 'delete_budget') {
            $b = db_one('SELECT * FROM budgets WHERE budget_id = ? FOR UPDATE', [(int)($_POST['budget_id'] ?? 0)]);
            if (!$b) { throw new RuntimeException('Budget not found.'); }
            if (db_value('SELECT COUNT(*) FROM budget_adjustments WHERE budget_id = ?', [(int)$b['budget_id']]) > 0 || db_value('SELECT COUNT(*) FROM budget_reviews WHERE budget_id = ?', [(int)$b['budget_id']]) > 0) {
                throw new RuntimeException('This budget has review or adjustment history, so it is kept.');
            }
            $pdo->prepare('DELETE FROM budgets WHERE budget_id = ?')->execute([(int)$b['budget_id']]);
            audit_log('budget_deleted', 'budgets', $b['budget_id'], ['category_id' => $b['category_id'], 'amount' => $b['amount'], 'type' => $b['period_type'], 'year' => $b['budget_year'], 'month' => $b['budget_month']]);
            $pdo->commit();
            set_flash('success', 'The budget was removed. Expenses are not affected.');
            $back = $b['period_type'] === 'monthly' ? '?type=monthly&month=' . sprintf('%04d-%02d', $b['budget_year'], $b['budget_month']) : '?type=yearly&year=' . $b['budget_year'];
        } elseif ($action === 'request_adjustment') {
            $b = db_one('SELECT * FROM budgets WHERE budget_id = ? FOR UPDATE', [(int)($_POST['budget_id'] ?? 0)]);
            $new = parse_money($_POST['new_amount'] ?? '');
            $reason = clean_text($_POST['reason'] ?? '', 255);
            if (!$b) { throw new RuntimeException('Budget not found.'); }
            if ($new === null) { throw new RuntimeException('Enter the proposed budget amount.'); }
            if (mb_strlen($reason) < 10) { throw new RuntimeException('Explain why the budget should change (at least 10 characters).'); }
            if (abs((float)$b['amount'] - $new) < 0.005) { throw new RuntimeException('The proposed amount is the same as the current budget.'); }
            if (db_value("SELECT COUNT(*) FROM budget_adjustments WHERE budget_id = ? AND status = 'pending'", [(int)$b['budget_id']]) > 0) { throw new RuntimeException('This budget already has a change waiting for approval.'); }
            $pdo->prepare('INSERT INTO budget_adjustments (budget_id, old_amount, new_amount, reason, requested_by) VALUES (?, ?, ?, ?, ?)')->execute([(int)$b['budget_id'], $b['amount'], $new, $reason, (int)$me['id']]);
            audit_log('budget_adjustment_requested', 'budget_adjustments', (int)$pdo->lastInsertId(), ['amount' => $b['amount']], ['proposed_amount' => $new], $reason);
            $pdo->commit();
            set_flash('success', 'The budget change was sent for approval. The current budget stays in force until a manager approves it.');
            $back = budget_back($b);

        } elseif ($action === 'decide_adjustment') {
            if (!$canApproveBudget) { throw new RuntimeException('Your role cannot approve budget changes.'); }
            $j = db_one('SELECT * FROM budget_adjustments WHERE adjustment_id = ? FOR UPDATE', [(int)($_POST['adjustment_id'] ?? 0)]);
            if (!$j) { throw new RuntimeException('Request not found.'); }
            if ($j['status'] !== 'pending') { throw new RuntimeException('This request was already ' . $j['status'] . '.'); }
            $b = db_one('SELECT * FROM budgets WHERE budget_id = ? FOR UPDATE', [(int)$j['budget_id']]);
            $decision = (string)($_POST['decision'] ?? '');
            $note = clean_text($_POST['note'] ?? '', 255);
            if ($decision === 'approve') {
                if ((int)$j['requested_by'] === (int)$me['id']) { throw new RuntimeException('You requested this change, so another authorized person must approve it.'); }
                if (abs((float)$b['amount'] - (float)$j['old_amount']) > 0.004) { throw new RuntimeException('The budget changed after this request was made. Reject it and submit a new request.'); }
                $pdo->prepare('UPDATE budgets SET amount = ? WHERE budget_id = ?')->execute([$j['new_amount'], (int)$b['budget_id']]);
                $pdo->prepare("UPDATE budget_adjustments SET status = 'approved', decided_by = ?, decided_at = NOW(), decision_note = ? WHERE adjustment_id = ?")->execute([(int)$me['id'], $note !== '' ? $note : null, (int)$j['adjustment_id']]);
                audit_log('budget_adjustment_approved', 'budgets', $b['budget_id'], ['amount' => $j['old_amount']], ['amount' => $j['new_amount']], $j['reason'] . ($note !== '' ? ' | Decision: ' . $note : ''));
                $msg = 'Budget changed from ' . peso($j['old_amount']) . ' to ' . peso($j['new_amount']) . '. No expense, payment or report record was changed.';
            } elseif ($decision === 'reject') {
                if (mb_strlen($note) < 3) { throw new RuntimeException('Enter a reason for rejecting the change.'); }
                $pdo->prepare("UPDATE budget_adjustments SET status = 'rejected', decided_by = ?, decided_at = NOW(), decision_note = ? WHERE adjustment_id = ?")->execute([(int)$me['id'], $note, (int)$j['adjustment_id']]);
                audit_log('budget_adjustment_rejected', 'budgets', $b['budget_id'], ['proposed_amount' => $j['new_amount']], ['amount' => $b['amount']], $note);
                $msg = 'The change was rejected. The budget stays at ' . peso($b['amount']) . '.';
            } else {
                throw new RuntimeException('Choose approve or reject.');
            }
            $pdo->commit();
            set_flash($decision === 'approve' ? 'success' : 'warning', $msg);
            $back = budget_back($b);

        } elseif ($action === 'record_review') {
            $b = db_one('SELECT * FROM budgets WHERE budget_id = ? FOR UPDATE', [(int)($_POST['budget_id'] ?? 0)]);
            $why = clean_text($_POST['explanation'] ?? '', 255);
            $kind = (string)($_POST['action_kind'] ?? '');
            $details = clean_text($_POST['action_details'] ?? '', 150);
            if (!$b) { throw new RuntimeException('Budget not found.'); }
            if (mb_strlen($why) < 10) { throw new RuntimeException('Explain the shortfall or the reason for the review (at least 10 characters).'); }
            if (!in_array($kind, ['Monitoring only', 'Spending reduced or frozen', 'Budget change requested', 'Other'], true)) { throw new RuntimeException('Choose the action taken.'); }
            $actual = budget_actual($b);
            $over = $actual > (float)$b['amount'] ? round($actual - (float)$b['amount'], 2) : 0.0;
            $taken = $kind . ($details !== '' ? ' - ' . $details : '');
            $pdo->prepare('INSERT INTO budget_reviews (budget_id, actual_at_review, overspent_by, explanation, action_taken, reviewed_by) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([(int)$b['budget_id'], round($actual, 2), $over, $why, $taken, (int)$me['id']]);
            audit_log('budget_reviewed', 'budgets', $b['budget_id'], null, ['actual_spending' => round($actual, 2), 'allocated' => $b['amount'], 'overspent_by' => $over, 'action_taken' => $taken], $why);
            $pdo->commit();
            set_flash('success', 'The review was recorded. The budget amount and the expenses were not changed.');
            $back = budget_back($b);
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The action could not be completed. Please try again.');
    }
    redirect('budgets.php' . $back);
}

/* ---------------------------- Which period to show ---------------------------- */
$type = (($_GET['type'] ?? '') === 'yearly') ? 'yearly' : 'monthly';
$month = (string)($_GET['month'] ?? '');
$year  = (int)($_GET['year'] ?? 0);
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m');
    if (db_value("SELECT COUNT(*) FROM budgets WHERE period_type = 'monthly' AND budget_year = ? AND budget_month = ?", [(int)date('Y'), (int)date('n')]) == 0) {
        $last = db_one("SELECT budget_year, budget_month FROM budgets WHERE period_type = 'monthly' ORDER BY budget_year DESC, budget_month DESC LIMIT 1");
        if ($last) { $month = sprintf('%04d-%02d', $last['budget_year'], $last['budget_month']); }
    }
}
if ($year < 2000 || $year > 2100) { $year = (int)date('Y'); }
if ($type === 'monthly') {
    $bYear = (int)substr($month, 0, 4); $bMonth = (int)substr($month, 5, 2);
    $start = $month . '-01'; $end = date('Y-m-t', strtotime($start)); $label = date('F Y', strtotime($start));
} else {
    $bYear = $year; $bMonth = 0; $start = $year . '-01-01'; $end = $year . '-12-31'; $label = (string)$year;
}

$budgets = db_all('SELECT b.*, c.name AS category FROM budgets b JOIN expense_categories c ON c.category_id = b.category_id
                   WHERE b.period_type = ? AND b.budget_year = ? AND b.budget_month = ? ORDER BY c.name', [$type, $bYear, $bMonth]);
$actualMap = db_all('SELECT e.category_id, SUM(e.amount) AS actual FROM v_expense_events e WHERE e.event_date BETWEEN ? AND ? AND e.category_id IS NOT NULL GROUP BY e.category_id', [$start, $end]);
$actual = array_column($actualMap, 'actual', 'category_id');
$cats = db_all('SELECT category_id, name FROM expense_categories ORDER BY name');
$catName = array_column($cats, 'name', 'category_id');

$reviews = []; $pendingAdj = [];
if ($budgets) {
    $ids = array_map('intval', array_column($budgets, 'budget_id'));
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (db_all("SELECT rv.*, u.full_name FROM budget_reviews rv JOIN users u ON u.user_id = rv.reviewed_by WHERE rv.budget_id IN ($in) ORDER BY rv.reviewed_at DESC, rv.review_id DESC", $ids) as $rv) {
        if (!isset($reviews[$rv['budget_id']])) { $reviews[$rv['budget_id']] = $rv; }
    }
    foreach (db_all("SELECT budget_id FROM budget_adjustments WHERE status = 'pending' AND budget_id IN ($in)", $ids) as $pa) { $pendingAdj[$pa['budget_id']] = true; }
}
$adjRows = db_all("SELECT j.*, c.name AS category, b.period_type, b.budget_year, b.budget_month, ur.full_name AS requester, ud.full_name AS decider
                   FROM budget_adjustments j JOIN budgets b ON b.budget_id = j.budget_id JOIN expense_categories c ON c.category_id = b.category_id
                   JOIN users ur ON ur.user_id = j.requested_by LEFT JOIN users ud ON ud.user_id = j.decided_by
                   ORDER BY (j.status = 'pending') DESC, j.requested_at DESC, j.adjustment_id DESC", [], 20);
$lines = []; $totBudget = 0.0; $totActual = 0.0; $over = []; $near = [];
$fmtOverPct = static fn(float $p): string => $p >= 0.1 ? number_format($p, 1) : '<0.1';
foreach ($budgets as $b) {
    $a = (float)($actual[$b['category_id']] ?? 0);
    $rem = round((float)$b['amount'] - $a, 2);
    $pct = (float)$b['amount'] > 0 ? round($a / (float)$b['amount'] * 100, 1) : 0;
    // Overspending = actual spending above the allocated budget (exactly at the limit is NOT overspending)
    $pctRaw  = (float)$b['amount'] > 0 ? $a / (float)$b['amount'] * 100 : 0.0;      // exact percentage (used for the status)
    $overBy  = $a > (float)$b['amount'] ? round($a - (float)$b['amount'], 2) : 0.0;
    $overPct = (float)$b['amount'] > 0 ? round($overBy / (float)$b['amount'] * 100, 1) : 0.0;
    $lines[] = ['id' => (int)$b['budget_id'], 'category' => $b['category'], 'budget' => (float)$b['amount'], 'actual' => $a, 'remaining' => max(0.0, $rem),
                'pct' => $pct, 'near' => ($overBy == 0.0 && $pctRaw >= $nearPct), 'over_by' => $overBy, 'over_pct' => $overPct, 'notes' => $b['notes'], 'budgeted' => true];
    $totBudget += (float)$b['amount']; $totActual += $a;
    if ($overBy > 0) {
        $over[] = $b['category'] . ': ' . peso($overBy) . ' over the ' . peso($b['amount']) . ' budget (' . $fmtOverPct($overPct) . '% over)';
    } elseif ($pctRaw >= $nearPct) {
        $near[] = $b['category'] . ' at ' . number_format($pct, 1) . '% of ' . peso($b['amount']) . ' (' . ($rem > 0 ? peso($rem) . ' left' : 'limit reached, nothing left') . ')';
    }
    unset($actual[$b['category_id']]);
}
$unbudgeted = [];
foreach ($actual as $cid => $a) { $unbudgeted[] = ['category' => $catName[$cid] ?? 'Category ' . $cid, 'actual' => (float)$a]; }

$pageTitle = 'Budget Monitoring';
include __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
    <form method="get" class="d-flex flex-wrap align-items-end gap-2">
        <div><label class="form-label small mb-1">View</label><select name="type" id="viewType" class="form-select form-select-sm"><option value="monthly" <?= $type === 'monthly' ? 'selected' : '' ?>>Monthly</option><option value="yearly" <?= $type === 'yearly' ? 'selected' : '' ?>>Yearly</option></select></div>
        <div id="monthBox" class="<?= $type === 'yearly' ? 'd-none' : '' ?>"><label class="form-label small mb-1">Month</label><input type="month" name="month" value="<?= e($month) ?>" class="form-control form-control-sm"></div>
        <div id="yearBox" class="<?= $type === 'monthly' ? 'd-none' : '' ?>"><label class="form-label small mb-1">Year</label><input type="number" name="year" value="<?= (int)$year ?>" min="2000" max="2100" class="form-control form-control-sm"></div>
        <button class="btn btn-primary btn-sm" type="submit">Show</button>
    </form>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#budgetModal"><i class="bi bi-plus-lg me-1"></i>Set budget</button>
</div>

<?php if ($over): ?>
    <div class="alert alert-danger"><strong><i class="bi bi-exclamation-octagon me-1"></i>Overspending alert &ndash; <?= e($label) ?></strong>
        <ul class="mb-0 mt-1"><?php foreach ($over as $o): ?><li><?= e($o) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($near): ?>
    <div class="alert alert-warning"><strong><i class="bi bi-exclamation-triangle me-1"></i>Approaching the budget limit &ndash; <?= e($label) ?></strong>
        <ul class="mb-0 mt-1"><?php foreach ($near as $n): ?><li><?= e($n) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="app-card stat-card"><div class="stat-label">Total budget</div><div class="stat-value"><?= e(peso($totBudget)) ?></div><div class="stat-note"><?= e($label) ?> &middot; <?= count($lines) ?> categor<?= count($lines) === 1 ? 'y' : 'ies' ?></div></div></div>
    <div class="col-md-4"><div class="app-card stat-card"><div class="stat-label">Actual expenses (budgeted categories)</div><div class="stat-value"><?= e(peso($totActual)) ?></div><div class="stat-note">Operating expenses and supplier payments</div></div></div>
    <div class="col-md-4"><div class="app-card stat-card"><div class="stat-label">Remaining</div><div class="stat-value <?= $totBudget - $totActual < 0 ? 'value-negative' : 'value-positive' ?>"><?= e(peso($totBudget - $totActual)) ?></div><div class="stat-note"><?= $totBudget > 0 ? e(number_format($totActual / $totBudget * 100, 1)) . '% used' : 'No budget set' ?></div></div></div>
</div>

<div class="app-card"><div class="table-responsive">
    <table class="table table-hover">
        <thead><tr><th>Category</th><th class="amount">Allocated budget</th><th class="amount">Actual spending</th><th class="amount">Remaining budget</th><th class="amount">Overspent by</th><th style="min-width:160px">Utilization</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$lines && !$unbudgeted): ?><tr><td colspan="8" class="text-center text-muted py-4">No budgets set for <?= e($label) ?>. Use "Set budget" to create one.</td></tr><?php endif; ?>
        <?php foreach ($lines as $l):
            $cls = $l['over_by'] > 0 ? 'over' : ($l['near'] ? 'warn' : ''); ?>
            <tr>
                <td><strong><?= e($l['category']) ?></strong><?php if ($l['notes']): ?><div class="small text-muted"><?= e($l['notes']) ?></div><?php endif; ?></td>
                <td class="amount"><?= e(peso($l['budget'])) ?></td>
                <td class="amount"><?= e(peso($l['actual'])) ?></td>
                <td class="amount"><strong><?= e(peso($l['remaining'])) ?></strong></td>
                <td class="amount"><?php if ($l['over_by'] > 0): ?><strong class="value-negative"><?= e(peso($l['over_by'])) ?></strong><div class="small value-negative"><?= e($fmtOverPct($l['over_pct'])) ?>% over</div><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                <td><div class="budget-track" role="progressbar" aria-valuenow="<?= e((string)min(100, $l['pct'])) ?>" aria-valuemin="0" aria-valuemax="100"><div class="budget-fill <?= $cls ?>" style="width:<?= e((string)min(100, $l['pct'])) ?>%"></div></div>
                    <div class="small text-muted mt-1"><?= e(number_format($l['pct'], 1)) ?>%</div></td>
                <td><?php if ($l['over_by'] > 0): ?><span class="badge badge-soft badge-terracotta">Over Budget</span>
                    <?php elseif ($l['near']): ?><span class="badge badge-soft badge-khaki">Near Budget Limit</span>
                    <?php else: ?><span class="badge badge-soft badge-sage">Within Budget</span><?php endif; ?>
                    <?php if ($l['over_by'] == 0 && $l['remaining'] == 0): ?><div class="small text-muted">limit reached</div><?php endif; ?>
                    <?php if (!empty($pendingAdj[$l['id']])): ?><div><span class="badge badge-soft badge-pink">Change awaiting approval</span></div><?php endif; ?>
                    <?php if (!empty($reviews[$l['id']])): $rv = $reviews[$l['id']]; ?><div class="small text-muted mt-1" title="<?= e($rv['explanation']) ?>">Reviewed by <?= e($rv['full_name']) ?>, <?= e(format_date($rv['reviewed_at'], 'M d')) ?>: <?= e(mb_strimwidth($rv['action_taken'], 0, 40, '...')) ?></div><?php endif; ?></td>
                <td class="text-end text-nowrap">
                    <?php if ($l['over_by'] > 0 || $l['near']): ?>
                        <button class="btn btn-outline-primary btn-sm rv-btn" data-bs-toggle="modal" data-bs-target="#reviewModal" data-id="<?= (int)$l['id'] ?>"
                            data-desc="<?= e($l['category'] . ' - allocated ' . peso($l['budget']) . ', spent ' . peso($l['actual']) . ($l['over_by'] > 0 ? ', over by ' . peso($l['over_by']) : '')) ?>">Review</button>
                    <?php endif; ?>
                    <?php if (empty($pendingAdj[$l['id']])): ?>
                        <button class="btn btn-outline-secondary btn-sm adj-btn" data-bs-toggle="modal" data-bs-target="#adjModal" data-id="<?= (int)$l['id'] ?>" data-amount="<?= e(number_format($l['budget'], 2, '.', '')) ?>"
                            data-desc="<?= e($l['category'] . ' - current budget ' . peso($l['budget'])) ?>">Request change</button>
                    <?php endif; ?>
                    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete_budget"><input type="hidden" name="budget_id" value="<?= (int)$l['id'] ?>">
                        <button class="btn btn-outline-secondary btn-sm" type="submit" data-confirm="Remove this budget? Expenses are not affected." title="Remove (only possible while it has no review or change history)"><i class="bi bi-x-lg"></i></button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($unbudgeted as $u): ?>
            <tr class="row-locked"><td><strong><?= e($u['category']) ?></strong></td><td class="amount">-</td><td class="amount"><?= e(peso($u['actual'])) ?></td><td class="amount">-</td><td class="amount">-</td><td class="small text-muted">No budget set</td><td><span class="badge badge-soft badge-khaki">Unbudgeted</span></td><td></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<div class="small text-muted mt-2"><strong>Within Budget</strong> below <?= (int)$nearPct ?>% &middot; <strong>Near Budget Limit</strong> from <?= (int)$nearPct ?>% up to and including 100% &middot; <strong>Over Budget</strong> above 100%. Nothing is changed automatically when a budget is exceeded: expenses can still be recorded, and a person must review the budget. Voided expenses are excluded. Payroll is not part of category budgets.</div>

<div class="app-card mt-4">
    <div class="card-header"><h3 class="card-title-serif">Budget change requests and history</h3></div>
    <div class="table-responsive"><table class="table table-hover align-middle">
        <thead><tr><th>Budget</th><th class="amount">Original</th><th class="amount">Proposed</th><th>Reason</th><th>Requested</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$adjRows): ?><tr><td colspan="7" class="text-center text-muted py-4">No budget changes have been requested. Changes to an existing budget always need an approval and keep their history.</td></tr><?php endif; ?>
        <?php foreach ($adjRows as $j): $own = (int)$j['requested_by'] === (int)$me['id']; ?>
            <tr><td><strong><?= e($j['category']) ?></strong><div class="small text-muted"><?= $j['period_type'] === 'monthly' ? e(date('F Y', mktime(0, 0, 0, (int)$j['budget_month'], 1, (int)$j['budget_year']))) : e((string)$j['budget_year']) ?></div></td>
                <td class="amount"><?= e(peso($j['old_amount'])) ?></td><td class="amount"><strong><?= e(peso($j['new_amount'])) ?></strong></td>
                <td class="small text-break" style="max-width:280px"><?= e($j['reason']) ?><?php if ($j['decision_note']): ?><div class="text-muted">Decision: <?= e($j['decision_note']) ?></div><?php endif; ?></td>
                <td class="small"><?= e($j['requester']) ?><div class="text-muted"><?= e(format_date($j['requested_at'], 'M d, Y g:i A')) ?></div></td>
                <td><?= status_badge($j['status']) ?><?php if ($j['decider']): ?><div class="small text-muted"><?= e($j['decider']) ?>, <?= e(format_date($j['decided_at'], 'M d')) ?></div><?php endif; ?></td>
                <td class="text-end"><?php if ($j['status'] === 'pending' && $canApproveBudget): ?>
                    <button class="btn btn-outline-primary btn-sm dec-btn" data-bs-toggle="modal" data-bs-target="#decideModal" data-id="<?= (int)$j['adjustment_id'] ?>" data-own="<?= $own ? '1' : '0' ?>"
                        data-desc="<?= e($j['category'] . ': ' . peso($j['old_amount']) . ' to ' . peso($j['new_amount']) . ' - ' . $j['reason']) ?>">Review</button><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>
<div class="small text-muted mt-2"><strong>When a budget runs out:</strong> expenses can still be recorded (nothing is blocked). The page and the notification bell warn, a person records a review (explanation and action taken), and any increase is only a <em>request</em> that another authorized person (managers and administrators) must approve. Approving a change updates the allocated amount only; no expense, payment or report record is altered, and every step is kept in the audit trail.</div>

<div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form method="post" class="modal-content">
    <?= csrf_field() ?><input type="hidden" name="action" value="record_review"><input type="hidden" name="budget_id" id="rvId">
    <div class="modal-header"><h5 class="modal-title">Review budget shortfall</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><p class="fw-semibold" id="rvDesc"></p><div class="row g-3">
        <div class="col-12"><label class="form-label">Explanation (why is spending at or above the budget?)</label><textarea name="explanation" class="form-control" rows="3" maxlength="255" required></textarea></div>
        <div class="col-md-6"><label class="form-label">Action taken</label><select name="action_kind" class="form-select" required><option value="">- select -</option><option>Monitoring only</option><option>Spending reduced or frozen</option><option>Budget change requested</option><option>Other</option></select></div>
        <div class="col-md-6"><label class="form-label">Details (optional)</label><input type="text" name="action_details" maxlength="150" class="form-control"></div>
    </div><div class="form-text mt-2">Recording a review does not change the budget or any expense.</div></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save review</button></div>
</form></div></div>
<div class="modal fade" id="adjModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form method="post" class="modal-content">
    <?= csrf_field() ?><input type="hidden" name="action" value="request_adjustment"><input type="hidden" name="budget_id" id="ajId">
    <div class="modal-header"><h5 class="modal-title">Request a budget change</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><p class="fw-semibold" id="ajDesc"></p><div class="row g-3">
        <div class="col-md-6"><label class="form-label">Proposed budget (&#8369;)</label><input type="number" step="0.01" min="0.01" name="new_amount" id="ajAmount" class="form-control" required></div>
        <div class="col-12"><label class="form-label">Reason</label><textarea name="reason" class="form-control" rows="3" maxlength="255" required></textarea></div>
    </div><div class="form-text mt-2">The current budget stays in force until an authorized person approves this request.</div></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Send for approval</button></div>
</form></div></div>
<?php if ($canApproveBudget): ?>
<div class="modal fade" id="decideModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form method="post" class="modal-content">
    <?= csrf_field() ?><input type="hidden" name="action" value="decide_adjustment"><input type="hidden" name="adjustment_id" id="dcId">
    <div class="modal-header"><h5 class="modal-title">Decide on budget change</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><p class="fw-semibold" id="dcDesc"></p>
        <div class="alert alert-warning small d-none" id="dcOwn">You requested this change, so another authorized person must approve it. You can still reject it.</div>
        <label class="form-label">Decision note</label><textarea name="note" class="form-control" rows="2" maxlength="255" placeholder="Required when rejecting"></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger" type="submit" name="decision" value="reject">Reject</button><button class="btn btn-success" type="submit" name="decision" value="approve" id="dcApprove">Approve</button></div>
</form></div></div>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.rv-btn').forEach(function (b) { b.addEventListener('click', function () { document.getElementById('rvId').value = b.dataset.id; document.getElementById('rvDesc').textContent = b.dataset.desc; }); });
    document.querySelectorAll('.adj-btn').forEach(function (b) { b.addEventListener('click', function () { document.getElementById('ajId').value = b.dataset.id; document.getElementById('ajDesc').textContent = b.dataset.desc; document.getElementById('ajAmount').value = b.dataset.amount; }); });
    document.querySelectorAll('.dec-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            var own = b.dataset.own === '1';
            document.getElementById('dcId').value = b.dataset.id; document.getElementById('dcDesc').textContent = b.dataset.desc;
            document.getElementById('dcOwn').classList.toggle('d-none', !own); document.getElementById('dcApprove').disabled = own;
        });
    });
});
</script>

<div class="modal fade" id="budgetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_budget">
        <div class="modal-header"><h5 class="modal-title">Set budget</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-12"><label class="form-label">Category</label><select name="category_id" class="form-select" required><option value="">- select -</option>
                <?php foreach ($cats as $c): ?><option value="<?= (int)$c['category_id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-4"><label class="form-label">Type</label><select name="period_type" id="bType" class="form-select" required><option value="monthly" <?= $type === 'monthly' ? 'selected' : '' ?>>Monthly</option><option value="yearly" <?= $type === 'yearly' ? 'selected' : '' ?>>Yearly</option></select></div>
            <div class="col-4"><label class="form-label">Year</label><input type="number" name="budget_year" min="2000" max="2100" class="form-control" value="<?= (int)$bYear ?>" required></div>
            <div class="col-4" id="bMonthBox"><label class="form-label">Month</label><select name="budget_month" class="form-select">
                <?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m === (int)($bMonth ?: date('n')) ? 'selected' : '' ?>><?= e(date('F', mktime(0, 0, 0, $m, 1))) ?></option><?php endfor; ?></select></div>
            <div class="col-6"><label class="form-label">Budget amount (&#8369;)</label><input type="number" name="amount" step="0.01" min="0.01" class="form-control" required></div>
            <div class="col-6"><label class="form-label">Notes (optional)</label><input type="text" name="notes" maxlength="255" class="form-control"></div>
        </div><div class="form-text mt-2">Saving a budget for a category and period that already has one replaces its amount.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save budget</button></div>
    </form></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var vt = document.getElementById('viewType'), mb = document.getElementById('monthBox'), yb = document.getElementById('yearBox');
    vt.addEventListener('change', function () { mb.classList.toggle('d-none', vt.value === 'yearly'); yb.classList.toggle('d-none', vt.value === 'monthly'); });
    var bt = document.getElementById('bType'), bm = document.getElementById('bMonthBox');
    function syncB() { bm.classList.toggle('d-none', bt.value === 'yearly'); }
    bt.addEventListener('change', syncB); syncB();
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
