<?php
/**
 * cash-reconciliation.php - Daily Cash Reconciliation (module 29)
 * Expected cash for a cashier and a day = verified cash payments + cash deposits - cash paid to suppliers
 * (all recorded by that cashier on that date). The cashier's counted cash is compared with it.
 * Discrepancy = actual counted cash - expected cash. Pending (unverified) cash payments are not counted.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('reconciliation.cash');

$pdo = db();
$me  = current_user();

function expected_cash(string $date, int $cashierId): array
{
    $pay = (float)db_value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_method = 'cash' AND status = 'verified' AND payment_date = ? AND recorded_by = ?", [$date, $cashierId]);
    $dep = (float)db_value("SELECT COALESCE(SUM(amount_received), 0) FROM guest_deposits WHERE payment_method = 'cash' AND deposit_date = ? AND recorded_by = ?", [$date, $cashierId]);
    $out = (float)db_value("SELECT COALESCE(SUM(amount), 0) FROM supplier_payments WHERE payment_method = 'cash' AND payment_date = ? AND recorded_by = ?", [$date, $cashierId]);
    $pending = (int)db_value("SELECT COUNT(*) FROM payments WHERE payment_method = 'cash' AND status = 'pending' AND payment_date = ? AND recorded_by = ?", [$date, $cashierId]);
    return ['payments' => $pay, 'deposits' => $dep, 'supplier' => $out, 'expected' => round($pay + $dep - $out, 2), 'pending' => $pending];
}

// Small JSON endpoint used by the form to show the expected cash before saving
if (($_GET['ajax'] ?? '') === 'expected') {
    header('Content-Type: application/json');
    $d = (string)($_GET['date'] ?? ''); $c = (int)($_GET['cashier'] ?? 0);
    if (!valid_date($d) || $c <= 0) { echo json_encode(['error' => 'Choose a date and a cashier.']); exit; }
    $x = expected_cash($d, $c);
    $x['exists'] = db_value('SELECT COUNT(*) FROM cash_reconciliations WHERE recon_date = ? AND cashier_id = ?', [$d, $c]) > 0;
    echo json_encode($x);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        $pdo->beginTransaction();
        if ($action === 'record') {
            $date    = (string)($_POST['recon_date'] ?? '');
            $cashier = (int)($_POST['cashier_id'] ?? 0);
            $actual  = parse_money($_POST['actual_cash'] ?? '', true);
            $explain = clean_text($_POST['explanation'] ?? '', 255);
            if (!valid_date($date) || $date > date('Y-m-d')) { throw new RuntimeException('The date must be valid and not in the future.'); }
            if (db_value('SELECT COUNT(*) FROM users WHERE user_id = ? AND is_active = 1', [$cashier]) == 0) { throw new RuntimeException('Choose the cashier.'); }
            if ($actual === null) { throw new RuntimeException('Enter the counted cash (use 0 if there was none).'); }
            if (db_value('SELECT COUNT(*) FROM cash_reconciliations WHERE recon_date = ? AND cashier_id = ?', [$date, $cashier]) > 0) {
                throw new RuntimeException('A reconciliation for this cashier and date already exists.');
            }
            require_open_period($date);
            $x = expected_cash($date, $cashier);
            $diff = round($actual - $x['expected'], 2);
            $status = $diff == 0 ? 'balanced' : (mb_strlen($explain) >= 5 ? 'explained' : 'discrepancy');
            $pdo->prepare('INSERT INTO cash_reconciliations (recon_date, cashier_id, expected_cash, actual_cash, status, explanation, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$date, $cashier, $x['expected'], $actual, $status, $explain !== '' ? $explain : null, (int)$me['id']]);
            audit_log('cash_reconciliation_recorded', 'cash_reconciliations', (int)$pdo->lastInsertId(), null,
                ['date' => $date, 'expected' => $x['expected'], 'actual' => $actual, 'difference' => $diff, 'status' => $status], $explain !== '' ? $explain : null);
            $pdo->commit();
            set_flash($diff == 0 ? 'success' : 'warning', $diff == 0 ? 'The cash balances exactly.' : 'Difference of ' . peso($diff) . ' recorded' . ($status === 'discrepancy' ? '. Add an explanation when you have one.' : ' with an explanation.'));

        } elseif ($action === 'explain') {
            $explain = clean_text($_POST['explanation'] ?? '', 255);
            if (mb_strlen($explain) < 5) { throw new RuntimeException('Enter an explanation (at least 5 characters).'); }
            $r = db_one('SELECT * FROM cash_reconciliations WHERE recon_id = ? FOR UPDATE', [(int)($_POST['recon_id'] ?? 0)]);
            if (!$r) { throw new RuntimeException('Reconciliation not found.'); }
            if ($r['status'] === 'balanced') { throw new RuntimeException('This reconciliation has no discrepancy to explain.'); }
            require_open_period($r['recon_date']);
            $pdo->prepare("UPDATE cash_reconciliations SET explanation = ?, status = 'explained' WHERE recon_id = ?")->execute([$explain, (int)$r['recon_id']]);
            audit_log('cash_discrepancy_explained', 'cash_reconciliations', $r['recon_id'], ['status' => $r['status'], 'explanation' => $r['explanation']], ['status' => 'explained'], $explain);
            $pdo->commit();
            set_flash('success', 'The explanation was saved.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The action could not be completed. Please try again.');
    }
    $ret = ltrim((string)($_POST['return'] ?? ''), '?');
    redirect('cash-reconciliation.php' . ($ret !== '' ? '?' . $ret : ''));
}

$stat = (string)($_GET['status'] ?? '');
$cashierF = (int)($_GET['cashier'] ?? 0);
$from = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$to   = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
$page = current_page_number();
$perPage = 15;
$where = ['1 = 1']; $params = [];
if (in_array($stat, ['balanced', 'discrepancy', 'explained'], true)) { $where[] = 'c.status = ?'; $params[] = $stat; }
if ($cashierF > 0) { $where[] = 'c.cashier_id = ?'; $params[] = $cashierF; }
if ($from !== '') { $where[] = 'c.recon_date >= ?'; $params[] = $from; }
if ($to !== '')   { $where[] = 'c.recon_date <= ?'; $params[] = $to; }
$fromSql = 'FROM cash_reconciliations c JOIN users u ON u.user_id = c.cashier_id JOIN users r ON r.user_id = c.recorded_by WHERE ' . implode(' AND ', $where);
$total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
$sums = db_one("SELECT COALESCE(SUM(c.discrepancy), 0) AS net_diff, COALESCE(SUM(c.status = 'discrepancy'), 0) AS unresolved $fromSql", $params);
$rows = db_all("SELECT c.*, u.full_name AS cashier, r.full_name AS recorder $fromSql ORDER BY c.recon_date DESC, c.recon_id DESC", $params, $perPage, ($page - 1) * $perPage);
$cashiers = db_all('SELECT user_id, full_name FROM users WHERE is_active = 1 ORDER BY full_name');

$pageTitle = 'Cash Reconciliation';
include __DIR__ . '/includes/header.php';
$returnQs = qs();
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
        <span class="summary-chip"><strong><?= $total ?></strong> reconciliation(s)</span>
        <span class="summary-chip">Net difference <strong class="<?= (float)$sums['net_diff'] < 0 ? 'value-negative' : '' ?>"><?= e(peso($sums['net_diff'])) ?></strong></span>
        <span class="summary-chip">Unexplained <strong><?= (int)$sums['unresolved'] ?></strong></span>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#reconModal"><i class="bi bi-plus-lg me-1"></i>Reconcile cash</button>
</div>

<form method="get" class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Cashier</label>
            <select name="cashier" class="form-select form-select-sm"><option value="0">All</option>
                <?php foreach ($cashiers as $c): ?><option value="<?= (int)$c['user_id'] ?>" <?= $cashierF === (int)$c['user_id'] ? 'selected' : '' ?>><?= e($c['full_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm"><option value="">All</option>
                <?php foreach (['balanced', 'discrepancy', 'explained'] as $s): ?><option value="<?= e($s) ?>" <?= $stat === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <div class="col-md-2 d-grid"><button class="btn btn-primary btn-sm" type="submit">Filter</button></div>
    </div>
</form>

<div class="app-card"><div class="table-responsive">
    <table class="table table-hover">
        <thead><tr><th>Date / cashier</th><th class="amount">Expected cash</th><th class="amount">Counted cash</th><th class="amount">Difference</th><th>Status</th><th>Explanation</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No reconciliations match your filters.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $d = (float)$r['discrepancy']; ?>
            <tr>
                <td><strong><?= e(format_date($r['recon_date'])) ?></strong><div class="small text-muted"><?= e($r['cashier']) ?></div></td>
                <td class="amount"><?= e(peso($r['expected_cash'])) ?></td>
                <td class="amount"><?= e(peso($r['actual_cash'])) ?></td>
                <td class="amount"><strong class="<?= $d < 0 ? 'value-negative' : ($d > 0 ? '' : 'value-positive') ?>"><?= $d > 0 ? '+' : '' ?><?= e(peso($d)) ?></strong><div class="small text-muted"><?= $d < 0 ? 'short' : ($d > 0 ? 'over' : 'balanced') ?></div></td>
                <td><?= status_badge($r['status']) ?></td>
                <td class="small"><?= e($r['explanation'] ?? '') ?><div class="text-muted">Recorded by <?= e($r['recorder']) ?></div></td>
                <td class="text-end"><?php if ($r['status'] !== 'balanced'): ?>
                    <button class="btn btn-outline-primary btn-sm ex-btn" data-bs-toggle="modal" data-bs-target="#explainModal" data-id="<?= (int)$r['recon_id'] ?>" data-text="<?= e($r['explanation'] ?? '') ?>"><?= $r['status'] === 'discrepancy' ? 'Explain' : 'Edit note' ?></button><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<?= render_pager($total, $page, $perPage) ?>

<div class="modal fade" id="reconModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="record"><input type="hidden" name="return" value="<?= e($returnQs) ?>">
        <div class="modal-header"><h5 class="modal-title">Daily cash reconciliation</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-6"><label class="form-label">Date</label><input type="date" name="recon_date" id="rcDate" class="form-control" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
            <div class="col-6"><label class="form-label">Cashier</label>
                <select name="cashier_id" id="rcCashier" class="form-select" required>
                    <?php foreach ($cashiers as $c): ?><option value="<?= (int)$c['user_id'] ?>" <?= (int)$c['user_id'] === (int)$me['id'] ? 'selected' : '' ?>><?= e($c['full_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><div class="p-3 rounded" style="background:rgba(195,176,145,.22)" id="rcInfo"><span class="text-muted">Choose a date and cashier to see the expected cash.</span></div></div>
            <div class="col-6"><label class="form-label">Counted cash (&#8369;)</label><input type="number" name="actual_cash" id="rcActual" step="0.01" min="0" class="form-control" required></div>
            <div class="col-6"><label class="form-label">Difference</label><div class="form-control" id="rcDiff" style="background:#F7F0E6">-</div></div>
            <div class="col-12"><label class="form-label">Explanation (if there is a difference)</label><input type="text" name="explanation" maxlength="255" class="form-control"></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save reconciliation</button></div>
    </form></div>
</div>

<div class="modal fade" id="explainModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="explain"><input type="hidden" name="return" value="<?= e($returnQs) ?>"><input type="hidden" name="recon_id" id="exId">
        <div class="modal-header"><h5 class="modal-title">Explain the difference</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><textarea name="explanation" id="exText" class="form-control" rows="3" maxlength="255" required></textarea></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save explanation</button></div>
    </form></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var d = document.getElementById('rcDate'), c = document.getElementById('rcCashier'), info = document.getElementById('rcInfo'),
        act = document.getElementById('rcActual'), diff = document.getElementById('rcDiff'), expected = null;
    function money(n) { return '\u20B1' + Number(n).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
    function showDiff() {
        if (expected === null || act.value === '') { diff.textContent = '-'; return; }
        var v = parseFloat(act.value) - expected; diff.textContent = (v > 0 ? '+' : '') + money(v) + (v < 0 ? ' (short)' : v > 0 ? ' (over)' : ' (balanced)');
    }
    function load() {
        if (!d.value || !c.value) { return; }
        fetch('cash-reconciliation.php?ajax=expected&date=' + encodeURIComponent(d.value) + '&cashier=' + encodeURIComponent(c.value), {credentials: 'same-origin'})
            .then(function (r) { return r.json(); }).then(function (x) {
                if (x.error) { info.textContent = x.error; expected = null; return; }
                expected = x.expected;
                info.innerHTML = '<div class="d-flex justify-content-between"><span>Verified cash payments</span><strong>' + money(x.payments) + '</strong></div>' +
                    '<div class="d-flex justify-content-between"><span>Cash deposits received</span><strong>' + money(x.deposits) + '</strong></div>' +
                    '<div class="d-flex justify-content-between"><span>Cash paid to suppliers</span><strong>-' + money(x.supplier) + '</strong></div>' +
                    '<hr class="my-2"><div class="d-flex justify-content-between"><span>Expected cash</span><strong>' + money(x.expected) + '</strong></div>' +
                    (x.pending > 0 ? '<div class="small mt-2 value-negative">' + x.pending + ' cash payment(s) are still pending verification and are not counted.</div>' : '') +
                    (x.exists ? '<div class="small mt-2 value-negative">A reconciliation already exists for this cashier and date.</div>' : '');
                showDiff();
            }).catch(function () { info.textContent = 'Could not load the expected cash.'; });
    }
    d.addEventListener('change', load); c.addEventListener('change', load); act.addEventListener('input', showDiff);
    document.getElementById('reconModal').addEventListener('shown.bs.modal', load);
    document.querySelectorAll('.ex-btn').forEach(function (b) {
        b.addEventListener('click', function () { document.getElementById('exId').value = b.dataset.id; document.getElementById('exText').value = b.dataset.text; });
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
