<?php
/**
 * expenses.php - Expense Management (module 19)
 * Operating expenses (utilities, maintenance, supplies, marketing, housekeeping ...).
 * Expenses are never deleted: a mistaken entry is voided with a reason, and voided expenses are excluded from reports.
 * Supplier purchases are NOT entered here; they are recognized when paid (purchase-payments.php), so nothing is counted twice.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('expenses.manage');

$pdo = db();
$me  = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        $pdo->beginTransaction();

        if ($action === 'record') {
            $catId  = (int)($_POST['category_id'] ?? 0);
            $date   = (string)($_POST['expense_date'] ?? '');
            $amount = parse_money($_POST['amount'] ?? '');
            $desc   = clean_text($_POST['description'] ?? '', 255);
            $ref    = clean_text($_POST['reference_number'] ?? '', 60);
            if (db_value('SELECT COUNT(*) FROM expense_categories WHERE category_id = ?', [$catId]) == 0) { throw new RuntimeException('Choose an expense category.'); }
            if (!valid_date($date) || $date > date('Y-m-d')) { throw new RuntimeException('The expense date must be a valid date that is not in the future.'); }
            if ($amount === null) { throw new RuntimeException('Enter a valid expense amount.'); }
            if (mb_strlen($desc) < 3) { throw new RuntimeException('Enter a description of the expense.'); }
            $periodId = require_open_period($date);
            $number = next_doc_number('EXP', (int)date('Y', strtotime($date)));
            $pdo->prepare("INSERT INTO expenses (expense_number, category_id, period_id, expense_date, amount, description, reference_number, status, recorded_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 'recorded', ?)")
                ->execute([$number, $catId, $periodId, $date, $amount, $desc, $ref !== '' ? $ref : null, (int)$me['id']]);
            audit_log('expense_recorded', 'expenses', (int)$pdo->lastInsertId(), null, ['expense_number' => $number, 'amount' => $amount, 'description' => $desc]);
            $pdo->commit();
            set_flash('success', "Expense $number of " . peso($amount) . ' was recorded.');

        } elseif ($action === 'void') {
            $reason = clean_text($_POST['reason'] ?? '', 255);
            if (mb_strlen($reason) < 5) { throw new RuntimeException('Enter the reason for voiding the expense (at least 5 characters).'); }
            $x = db_one('SELECT * FROM expenses WHERE expense_id = ? FOR UPDATE', [(int)($_POST['expense_id'] ?? 0)]);
            if (!$x) { throw new RuntimeException('Expense not found.'); }
            if ($x['status'] === 'void') { throw new RuntimeException('This expense is already void.'); }
            require_open_period_id((int)$x['period_id']);
            $pdo->prepare("UPDATE expenses SET status = 'void', void_reason = ? WHERE expense_id = ?")->execute([$reason, (int)$x['expense_id']]);
            audit_log('expense_voided', 'expenses', $x['expense_id'], ['status' => 'recorded', 'amount' => $x['amount']], ['status' => 'void'], $reason);
            $pdo->commit();
            set_flash('success', $x['expense_number'] . ' was voided. It stays in the records but is excluded from totals.');

        } elseif ($action === 'add_category') {
            $name = clean_text($_POST['name'] ?? '', 80);
            $desc = clean_text($_POST['description'] ?? '', 255);
            if (mb_strlen($name) < 2) { throw new RuntimeException('Enter the category name.'); }
            if (db_value('SELECT COUNT(*) FROM expense_categories WHERE LOWER(name) = LOWER(?)', [$name]) > 0) { throw new RuntimeException('That category already exists.'); }
            $pdo->prepare('INSERT INTO expense_categories (name, description) VALUES (?, ?)')->execute([$name, $desc !== '' ? $desc : null]);
            audit_log('expense_category_added', 'expense_categories', (int)$pdo->lastInsertId(), null, ['name' => $name]);
            $pdo->commit();
            set_flash('success', "Category \"$name\" was added.");
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The action could not be completed. Please try again.');
    }
    $ret = ltrim((string)($_POST['return'] ?? ''), '?');
    redirect('expenses.php' . ($ret !== '' ? '?' . $ret : ''));
}

/* ------------------------------ Filters ------------------------------ */
$cat  = (int)($_GET['category'] ?? 0);
$stat = (string)($_GET['status'] ?? 'recorded');
$q    = clean_text($_GET['q'] ?? '', 60);
$from = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$to   = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
$page = current_page_number();
$perPage = 15;
$where = ['1 = 1']; $params = [];
if ($cat > 0) { $where[] = 'x.category_id = ?'; $params[] = $cat; }
if (in_array($stat, ['recorded', 'void'], true)) { $where[] = 'x.status = ?'; $params[] = $stat; } else { $stat = 'all'; }
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(x.expense_number LIKE ? OR x.description LIKE ? OR x.reference_number LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($from !== '') { $where[] = 'x.expense_date >= ?'; $params[] = $from; }
if ($to !== '')   { $where[] = 'x.expense_date <= ?'; $params[] = $to; }
$fromSql = 'FROM expenses x JOIN expense_categories c ON c.category_id = x.category_id JOIN users u ON u.user_id = x.recorded_by WHERE ' . implode(' AND ', $where);
$total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
$sum = (float)db_value("SELECT COALESCE(SUM(CASE WHEN x.status = 'recorded' THEN x.amount END), 0) $fromSql", $params);
$rows = db_all("SELECT x.*, c.name AS category, u.full_name AS recorder $fromSql ORDER BY x.expense_date DESC, x.expense_id DESC", $params, $perPage, ($page - 1) * $perPage);
$cats = db_all('SELECT category_id, name FROM expense_categories ORDER BY name');

if (($_GET['export'] ?? '') === 'csv') {
    require_permission('exports.generate');
    $data = db_all("SELECT x.expense_number, x.expense_date, c.name AS category, x.description, x.reference_number, x.amount, x.status, u.full_name AS recorder, x.void_reason
                    $fromSql ORDER BY x.expense_date DESC, x.expense_id DESC", $params);
    $rows = []; $live = [];
    foreach ($data as $d) {
        $row = [$d['expense_number'], $d['expense_date'], $d['category'], $d['description'], $d['reference_number'], $d['amount'], csv_label($d['status']), $d['recorder'], $d['void_reason']];
        $rows[] = $row;
        if ($d['status'] === 'recorded') { $live[] = $row; }
    }
    $catLabel = $cat > 0 ? (string)db_value('SELECT name FROM expense_categories WHERE category_id = ?', [$cat]) : '';
    csv_download('expenses_report', 'Expenses Report', ['Search' => $q, 'Category' => $catLabel, 'Status' => $stat === 'all' ? '' : csv_label($stat), 'From' => $from, 'To' => $to],
        [['Expense No.', 'text'], ['Date', 'date'], ['Category', 'text'], ['Description', 'text'], ['Reference No.', 'text'], ['Amount (PHP)', 'money', true], ['Status', 'text'],
         ['Recorded by', 'text'], ['Void reason', 'text']], $rows, $live, 'TOTAL (recorded expenses only)');
}

$pageTitle = 'Expenses';
include __DIR__ . '/includes/header.php';
$returnQs = qs();
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
        <span class="summary-chip"><strong><?= $total ?></strong> record(s)</span>
        <span class="summary-chip">Recorded total <strong><?= e(peso($sum)) ?></strong></span>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#catModal"><i class="bi bi-tags me-1"></i>New category</button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#expenseModal"><i class="bi bi-plus-lg me-1"></i>Record expense</button>
    </div>
</div>

<div class="mb-3 no-print"><?= render_export_menu('?' . qs(['export' => 'csv', 'page' => '']), has_permission('exports.generate')) ?></div>
<form method="get" class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small mb-1">Search</label><input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Number, description, reference"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Category</label>
            <select name="category" class="form-select form-select-sm"><option value="0">All</option>
                <?php foreach ($cats as $c): ?><option value="<?= (int)$c['category_id'] ?>" <?= $cat === (int)$c['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <?php foreach (['recorded' => 'Recorded', 'void' => 'Void', 'all' => 'All'] as $k => $v): ?><option value="<?= e($k) ?>" <?= $stat === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <div class="col-md-1 d-grid"><button class="btn btn-primary btn-sm" type="submit">Go</button></div>
    </div>
</form>

<div class="app-card"><div class="table-responsive">
    <table class="table table-hover">
        <thead><tr><th>Expense</th><th>Category</th><th>Description</th><th>Reference</th><th class="amount">Amount</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No expenses match your filters.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= $r['status'] === 'void' ? 'row-locked' : '' ?>">
                <td><strong><?= e($r['expense_number']) ?></strong><div class="small text-muted"><?= e(format_date($r['expense_date'])) ?> &middot; <?= e($r['recorder']) ?></div></td>
                <td><?= e($r['category']) ?></td>
                <td><?= e($r['description']) ?><?php if ($r['void_reason']): ?><div class="small value-negative">Void: <?= e($r['void_reason']) ?></div><?php endif; ?></td>
                <td><?= e($r['reference_number'] ?? '-') ?></td>
                <td class="amount"><strong><?= e(peso($r['amount'])) ?></strong></td>
                <td><?= status_badge($r['status']) ?></td>
                <td class="text-end"><?php if ($r['status'] === 'recorded'): ?>
                    <button class="btn btn-outline-secondary btn-sm void-btn" data-bs-toggle="modal" data-bs-target="#voidModal" data-id="<?= (int)$r['expense_id'] ?>" data-num="<?= e($r['expense_number']) ?>">Void</button><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<?= render_pager($total, $page, $perPage) ?>

<div class="modal fade" id="expenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="record"><input type="hidden" name="return" value="<?= e($returnQs) ?>">
        <div class="modal-header"><h5 class="modal-title">Record expense</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-6"><label class="form-label">Category</label>
                <select name="category_id" class="form-select" required><option value="">- select -</option>
                    <?php foreach ($cats as $c): ?><option value="<?= (int)$c['category_id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label">Date</label><input type="date" name="expense_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
            <div class="col-6"><label class="form-label">Amount (&#8369;)</label><input type="number" name="amount" step="0.01" min="0.01" class="form-control" required></div>
            <div class="col-6"><label class="form-label">Supporting reference</label><input type="text" name="reference_number" maxlength="60" class="form-control" placeholder="Receipt / bill number"></div>
            <div class="col-12"><label class="form-label">Description</label><input type="text" name="description" maxlength="255" class="form-control" required></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save expense</button></div>
    </form></div>
</div>

<div class="modal fade" id="catModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_category">
        <div class="modal-header"><h5 class="modal-title">New expense category</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="mb-3"><label class="form-label">Name</label><input type="text" name="name" maxlength="80" class="form-control" required></div>
            <div><label class="form-label">Description (optional)</label><input type="text" name="description" maxlength="255" class="form-control"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Add category</button></div>
    </form></div>
</div>

<div class="modal fade" id="voidModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="return" value="<?= e($returnQs) ?>"><input type="hidden" name="expense_id" id="vxId">
        <div class="modal-header"><h5 class="modal-title">Void expense <span id="vxNum"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><p class="small text-muted">The expense stays in the records, marked void, and is left out of reports and budgets.</p>
            <label class="form-label">Reason</label><textarea name="reason" class="form-control" rows="3" maxlength="255" required></textarea></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep expense</button><button class="btn btn-danger" type="submit">Void expense</button></div>
    </form></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.void-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('vxId').value = b.dataset.id;
            document.getElementById('vxNum').textContent = b.dataset.num;
        });
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
