<?php
/**
 * payment-reconciliation.php - Bank and Payment-Provider Reconciliation (module 30)
 * Compares the payments recorded in the system with settlement statements from banks, card acquirers, GCash and Maya.
 * Statement lines are entered by hand or imported from a CSV file. There is NO automatic bank or wallet connection.
 * Matching rule: same method + same reference number as a VERIFIED payment. Amounts are then compared:
 *   matched = same amount, discrepant = different amount, unmatched = no verified payment with that reference.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('reconciliation.payment');

const RECON_PROVIDERS = ['bank_transfer' => 'Bank transfer', 'card' => 'Card', 'gcash' => 'GCash', 'maya' => 'Maya'];

$pdo = db();
$me  = current_user();

/** @return array{0:?int,1:?float,2:string} [payment_id, recorded_amount, status] */
function match_line(string $provider, string $ref, float $amount): array
{
    $p = db_one("SELECT payment_id, amount FROM payments
                 WHERE status = 'verified' AND payment_method = ? AND LOWER(reference_number) = LOWER(?)
                   AND payment_id NOT IN (SELECT payment_id FROM payment_reconciliations WHERE payment_id IS NOT NULL) LIMIT 1", [$provider, $ref]);
    if ($p === null) {
        return [null, null, 'unmatched'];
    }
    return [(int)$p['payment_id'], (float)$p['amount'], abs((float)$p['amount'] - $amount) < 0.005 ? 'matched' : 'discrepant'];
}

/** Inserts a statement line (skips exact duplicates). Returns the status or null when skipped. */
function add_statement_line(string $provider, string $ref, string $date, float $amount, string $notes, int $userId): ?string
{
    if (db_value('SELECT COUNT(*) FROM payment_reconciliations WHERE provider = ? AND statement_reference = ? AND statement_date = ? AND statement_amount = ?',
        [$provider, $ref, $date, number_format($amount, 2, '.', '')]) > 0) {
        return null;
    }
    [$payId, $recorded, $status] = match_line($provider, $ref, $amount);
    db()->prepare('INSERT INTO payment_reconciliations (provider, statement_reference, statement_date, statement_amount, payment_id, recorded_amount, status, notes, recorded_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$provider, $ref, $date, $amount, $payId, $recorded, $status, $notes !== '' ? $notes : null, $userId]);
    return $status;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        $pdo->beginTransaction();

        if ($action === 'add_line') {
            $provider = (string)($_POST['provider'] ?? '');
            $ref   = clean_text($_POST['statement_reference'] ?? '', 60);
            $date  = (string)($_POST['statement_date'] ?? '');
            $amount = parse_money($_POST['statement_amount'] ?? '');
            if (!isset(RECON_PROVIDERS[$provider])) { throw new RuntimeException('Choose the provider.'); }
            if ($ref === '') { throw new RuntimeException('Enter the statement reference number.'); }
            if (!valid_date($date) || $date > date('Y-m-d')) { throw new RuntimeException('The statement date must be valid and not in the future.'); }
            if ($amount === null) { throw new RuntimeException('Enter a valid statement amount.'); }
            $status = add_statement_line($provider, $ref, $date, $amount, clean_text($_POST['notes'] ?? '', 255), (int)$me['id']);
            if ($status === null) { throw new RuntimeException('This statement line was already entered.'); }
            audit_log('statement_line_added', 'payment_reconciliations', (int)$pdo->lastInsertId(), null, ['provider' => $provider, 'reference' => $ref, 'amount' => $amount, 'status' => $status]);
            $pdo->commit();
            set_flash($status === 'matched' ? 'success' : 'warning', 'Statement line saved: ' . $status . '.');

        } elseif ($action === 'import_csv') {
            $f = $_FILES['csv'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { throw new RuntimeException('Choose a CSV file to import.'); }
            if ($f['size'] > 1048576) { throw new RuntimeException('The file is larger than 1 MB.'); }
            if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'csv') { throw new RuntimeException('Only .csv files can be imported.'); }
            $h = fopen($f['tmp_name'], 'r');
            if ($h === false) { throw new RuntimeException('The file could not be read.'); }
            $counts = ['matched' => 0, 'discrepant' => 0, 'unmatched' => 0, 'skipped' => 0];
            $errors = []; $line = 0; $rowsRead = 0;
            while (($cells = fgetcsv($h, 2000, ',', '"', '\\')) !== false) {
                $line++;
                if ($cells === [null] || count($cells) < 4) { if ($cells !== [null]) { $errors[] = "Line $line: needs 4 columns."; } continue; }
                if ($line === 1) { $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cells[0]); }
                $prov = strtolower(str_replace([' ', '-'], '_', trim((string)$cells[0])));
                if ($line === 1 && !isset(RECON_PROVIDERS[$prov])) { continue; } // header row
                if (++$rowsRead > 500) { $errors[] = 'Only the first 500 rows were imported.'; break; }
                $ref = clean_text($cells[1], 60); $date = trim((string)$cells[2]); $amt = parse_money($cells[3]);
                if (!isset(RECON_PROVIDERS[$prov]) || $ref === '' || !valid_date($date) || $date > date('Y-m-d') || $amt === null) {
                    if (count($errors) < 5) { $errors[] = "Line $line: invalid provider, reference, date (YYYY-MM-DD) or amount."; }
                    $counts['skipped']++; continue;
                }
                $st = add_statement_line($prov, $ref, $date, $amt, '', (int)$me['id']);
                if ($st === null) { $counts['skipped']++; } else { $counts[$st]++; }
            }
            fclose($h);
            if (array_sum($counts) - $counts['skipped'] === 0 && $counts['skipped'] > 0 && !$errors) { $errors[] = 'Every line was already imported.'; }
            audit_log('statement_imported', 'payment_reconciliations', null, null, $counts, 'CSV import: ' . mb_substr((string)$f['name'], 0, 80));
            $pdo->commit();
            set_flash($counts['skipped'] > 0 || $errors ? 'warning' : 'success',
                "Import finished: {$counts['matched']} matched, {$counts['discrepant']} discrepant, {$counts['unmatched']} unmatched, {$counts['skipped']} skipped." . ($errors ? ' ' . implode(' ', array_slice($errors, 0, 5)) : ''));

        } elseif ($action === 'rematch') {
            $n = 0;
            foreach (db_all("SELECT * FROM payment_reconciliations WHERE status = 'unmatched' AND payment_id IS NULL FOR UPDATE") as $r) {
                [$payId, $recorded, $status] = match_line($r['provider'], $r['statement_reference'], (float)$r['statement_amount']);
                if ($payId !== null) {
                    $pdo->prepare('UPDATE payment_reconciliations SET payment_id = ?, recorded_amount = ?, status = ? WHERE recon_id = ?')->execute([$payId, $recorded, $status, (int)$r['recon_id']]);
                    $n++;
                }
            }
            audit_log('statements_rematched', 'payment_reconciliations', null, null, ['newly_matched' => $n]);
            $pdo->commit();
            set_flash($n > 0 ? 'success' : 'info', $n > 0 ? "$n statement line(s) were matched." : 'No new matches were found. A payment must be verified before it can be matched.');

        } elseif ($action === 'match_manual') {
            $r = db_one('SELECT * FROM payment_reconciliations WHERE recon_id = ? FOR UPDATE', [(int)($_POST['recon_id'] ?? 0)]);
            if (!$r || $r['payment_id'] !== null) { throw new RuntimeException('Choose an unmatched statement line.'); }
            $p = db_one("SELECT payment_id, amount, payment_method FROM payments WHERE payment_id = ? AND status = 'verified' FOR UPDATE", [(int)($_POST['payment_id'] ?? 0)]);
            if (!$p) { throw new RuntimeException('Choose a verified payment.'); }
            if ($p['payment_method'] !== $r['provider']) { throw new RuntimeException('The payment method does not match the provider of the statement line.'); }
            if (db_value('SELECT COUNT(*) FROM payment_reconciliations WHERE payment_id = ?', [(int)$p['payment_id']]) > 0) { throw new RuntimeException('That payment is already matched to another statement line.'); }
            $status = abs((float)$p['amount'] - (float)$r['statement_amount']) < 0.005 ? 'matched' : 'discrepant';
            $pdo->prepare('UPDATE payment_reconciliations SET payment_id = ?, recorded_amount = ?, status = ? WHERE recon_id = ?')->execute([(int)$p['payment_id'], $p['amount'], $status, (int)$r['recon_id']]);
            audit_log('statement_matched_manually', 'payment_reconciliations', $r['recon_id'], ['status' => 'unmatched'], ['payment_id' => $p['payment_id'], 'status' => $status]);
            $pdo->commit();
            set_flash($status === 'matched' ? 'success' : 'warning', 'The line was matched manually: ' . $status . '.');

        } elseif ($action === 'resolve') {
            $note = clean_text($_POST['notes'] ?? '', 200);
            if (mb_strlen($note) < 5) { throw new RuntimeException('Explain the variance (at least 5 characters).'); }
            $r = db_one('SELECT * FROM payment_reconciliations WHERE recon_id = ? FOR UPDATE', [(int)($_POST['recon_id'] ?? 0)]);
            if (!$r || $r['status'] !== 'discrepant') { throw new RuntimeException('Only discrepant lines can be resolved.'); }
            $newNotes = mb_substr(trim(($r['notes'] ? $r['notes'] . ' | ' : '') . 'Resolved: ' . $note), 0, 255);
            $pdo->prepare("UPDATE payment_reconciliations SET status = 'matched', notes = ? WHERE recon_id = ?")->execute([$newNotes, (int)$r['recon_id']]);
            audit_log('statement_variance_resolved', 'payment_reconciliations', $r['recon_id'], ['status' => 'discrepant', 'variance' => $r['variance']], ['status' => 'matched'], $note);
            $pdo->commit();
            set_flash('success', 'The variance of ' . peso($r['variance']) . ' was accepted and documented.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The action could not be completed. Please try again.');
    }
    $ret = ltrim((string)($_POST['return'] ?? ''), '?');
    redirect('payment-reconciliation.php' . ($ret !== '' ? '?' . $ret : ''));
}

/* ------------------------------ Filters ------------------------------ */
$prov = (string)($_GET['provider'] ?? '');
$stat = (string)($_GET['status'] ?? '');
$q    = clean_text($_GET['q'] ?? '', 60);
$from = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$to   = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
$page = current_page_number();
$perPage = 15;
$where = ['1 = 1']; $params = [];
if (isset(RECON_PROVIDERS[$prov])) { $where[] = 'r.provider = ?'; $params[] = $prov; }
if (in_array($stat, ['matched', 'unmatched', 'discrepant'], true)) { $where[] = 'r.status = ?'; $params[] = $stat; }
if ($q !== '') { $like = '%' . $q . '%'; $where[] = '(r.statement_reference LIKE ? OR p.payment_number LIKE ? OR r.notes LIKE ?)'; array_push($params, $like, $like, $like); }
if ($from !== '') { $where[] = 'r.statement_date >= ?'; $params[] = $from; }
if ($to !== '')   { $where[] = 'r.statement_date <= ?'; $params[] = $to; }
$fromSql = 'FROM payment_reconciliations r LEFT JOIN payments p ON p.payment_id = r.payment_id LEFT JOIN invoices i ON i.invoice_id = p.invoice_id WHERE ' . implode(' AND ', $where);
$total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
$sums = db_one("SELECT COALESCE(SUM(r.status = 'matched'), 0) AS m, COALESCE(SUM(r.status = 'unmatched'), 0) AS u, COALESCE(SUM(r.status = 'discrepant'), 0) AS d,
                       COALESCE(SUM(r.variance), 0) AS variance $fromSql", $params);
$rows = db_all("SELECT r.*, p.payment_number, i.invoice_number $fromSql ORDER BY FIELD(r.status, 'discrepant', 'unmatched', 'matched'), r.statement_date DESC, r.recon_id DESC", $params, $perPage, ($page - 1) * $perPage);
$candidates = db_all("SELECT p.payment_id, p.payment_number, p.payment_method, p.amount, p.reference_number, p.payment_date, i.invoice_number
                      FROM payments p JOIN invoices i ON i.invoice_id = p.invoice_id
                      WHERE p.status = 'verified' AND p.payment_method <> 'cash'
                        AND p.payment_id NOT IN (SELECT payment_id FROM payment_reconciliations WHERE payment_id IS NOT NULL)
                      ORDER BY p.payment_date DESC");
$unmatchedPayments = count($candidates);

$pageTitle = 'Payment Reconciliation';
include __DIR__ . '/includes/header.php';
$returnQs = qs();
?>
<div class="alert alert-info py-2 small"><i class="bi bi-info-circle me-1"></i>Statement lines are entered manually or imported from a CSV file. There is no live connection to any bank, card processor, GCash or Maya.</div>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
        <span class="summary-chip">Matched <strong><?= (int)$sums['m'] ?></strong></span>
        <span class="summary-chip">Unmatched <strong><?= (int)$sums['u'] ?></strong></span>
        <span class="summary-chip">Discrepant <strong><?= (int)$sums['d'] ?></strong></span>
        <span class="summary-chip">Total variance <strong><?= e(peso($sums['variance'])) ?></strong></span>
        <span class="summary-chip">Verified payments with no statement line <strong><?= $unmatchedPayments ?></strong></span>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="rematch"><input type="hidden" name="return" value="<?= e($returnQs) ?>">
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Match again</button></form>
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#importModal"><i class="bi bi-upload me-1"></i>Import CSV</button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#lineModal"><i class="bi bi-plus-lg me-1"></i>Add statement line</button>
    </div>
</div>

<form method="get" class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small mb-1">Search</label><input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Reference, payment or notes"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Provider</label>
            <select name="provider" class="form-select form-select-sm"><option value="">All</option><?php foreach (RECON_PROVIDERS as $k => $v): ?><option value="<?= e($k) ?>" <?= $prov === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm"><option value="">All</option><?php foreach (['matched', 'unmatched', 'discrepant'] as $s): ?><option value="<?= e($s) ?>" <?= $stat === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <div class="col-md-1 d-grid"><button class="btn btn-primary btn-sm" type="submit">Go</button></div>
    </div>
</form>

<div class="app-card"><div class="table-responsive">
    <table class="table table-hover">
        <thead><tr><th>Statement line</th><th class="amount">Statement</th><th>Matched payment</th><th class="amount">Recorded</th><th class="amount">Variance</th><th>Status</th><th>Notes</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No statement lines yet. Add one or import a CSV file.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= e($r['statement_reference']) ?></strong><div class="small text-muted"><?= e(RECON_PROVIDERS[$r['provider']] ?? $r['provider']) ?> &middot; <?= e(format_date($r['statement_date'])) ?></div></td>
                <td class="amount"><?= e(peso($r['statement_amount'])) ?></td>
                <td><?= $r['payment_number'] ? e($r['payment_number']) . '<div class="small text-muted">' . e($r['invoice_number']) . '</div>' : '<span class="text-muted">-</span>' ?></td>
                <td class="amount"><?= $r['recorded_amount'] !== null ? e(peso($r['recorded_amount'])) : '-' ?></td>
                <td class="amount <?= $r['variance'] !== null && abs((float)$r['variance']) > 0.004 ? 'value-negative' : '' ?>"><?= $r['variance'] !== null ? e(peso($r['variance'])) : '-' ?></td>
                <td><?= status_badge($r['status']) ?></td>
                <td class="small"><?= e($r['notes'] ?? '') ?></td>
                <td class="text-end text-nowrap">
                    <?php if ($r['status'] === 'unmatched'): ?>
                        <button class="btn btn-outline-primary btn-sm match-btn" data-bs-toggle="modal" data-bs-target="#matchModal" data-id="<?= (int)$r['recon_id'] ?>" data-provider="<?= e($r['provider']) ?>"
                            data-desc="<?= e($r['statement_reference'] . ' - ' . peso($r['statement_amount'])) ?>">Match</button>
                    <?php elseif ($r['status'] === 'discrepant'): ?>
                        <button class="btn btn-outline-primary btn-sm resolve-btn" data-bs-toggle="modal" data-bs-target="#resolveModal" data-id="<?= (int)$r['recon_id'] ?>"
                            data-desc="<?= e($r['statement_reference'] . ' - variance ' . peso($r['variance'])) ?>">Resolve</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<?= render_pager($total, $page, $perPage) ?>

<div class="modal fade" id="lineModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_line"><input type="hidden" name="return" value="<?= e($returnQs) ?>">
        <div class="modal-header"><h5 class="modal-title">Add statement line</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-6"><label class="form-label">Provider</label><select name="provider" class="form-select" required><?php foreach (RECON_PROVIDERS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label">Statement date</label><input type="date" name="statement_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
            <div class="col-6"><label class="form-label">Reference number</label><input type="text" name="statement_reference" maxlength="60" class="form-control" required></div>
            <div class="col-6"><label class="form-label">Amount (&#8369;)</label><input type="number" name="statement_amount" step="0.01" min="0.01" class="form-control" required></div>
            <div class="col-12"><label class="form-label">Notes (optional)</label><input type="text" name="notes" maxlength="255" class="form-control"></div>
        </div><div class="form-text mt-2">The system matches the line to a verified payment that has the same method and reference number.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save line</button></div>
    </form></div>
</div>

<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" enctype="multipart/form-data" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="import_csv"><input type="hidden" name="return" value="<?= e($returnQs) ?>">
        <div class="modal-header"><h5 class="modal-title">Import statement CSV</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <p class="small">One line per transaction with four columns:</p>
            <pre class="small p-2 rounded" style="background:#F7F0E6">provider,reference,date,amount
gcash,GC-883412,2026-10-05,10919.36
bank_transfer,BT-20261014-77,2026-10-15,5692.64</pre>
            <p class="small text-muted">Providers: bank_transfer, card, gcash, maya. Dates are YYYY-MM-DD. The header row is optional. Maximum 500 lines and 1 MB. Duplicate lines are skipped.</p>
            <input type="file" name="csv" accept=".csv,text/csv" class="form-control" required>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Import</button></div>
    </form></div>
</div>

<div class="modal fade" id="matchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="match_manual"><input type="hidden" name="return" value="<?= e($returnQs) ?>"><input type="hidden" name="recon_id" id="mId">
        <div class="modal-header"><h5 class="modal-title">Match statement line</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><p class="fw-semibold" id="mDesc"></p>
            <label class="form-label">Verified payment (same method)</label>
            <select name="payment_id" id="mPay" class="form-select" required><option value="">- select payment -</option>
                <?php foreach ($candidates as $c): ?><option value="<?= (int)$c['payment_id'] ?>" data-method="<?= e($c['payment_method']) ?>"><?= e($c['payment_number'] . ' - ' . $c['invoice_number'] . ' - ' . peso($c['amount']) . ' - ref ' . ($c['reference_number'] ?? '-') . ' - ' . format_date($c['payment_date'], 'M d')) ?></option><?php endforeach; ?></select>
            <div class="form-text">If the payment is still pending, verify it first in Payment Verification, then use "Match again".</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Match</button></div>
    </form></div>
</div>

<div class="modal fade" id="resolveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="resolve"><input type="hidden" name="return" value="<?= e($returnQs) ?>"><input type="hidden" name="recon_id" id="rId">
        <div class="modal-header"><h5 class="modal-title">Resolve variance</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><p class="fw-semibold" id="rDesc"></p><label class="form-label">Explanation (for example: merchant fee deducted)</label><textarea name="notes" class="form-control" rows="3" maxlength="200" required></textarea></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Accept variance</button></div>
    </form></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sel = document.getElementById('mPay');
    document.querySelectorAll('.match-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('mId').value = b.dataset.id; document.getElementById('mDesc').textContent = b.dataset.desc; sel.value = '';
            Array.prototype.forEach.call(sel.options, function (o) { if (o.dataset.method) { o.hidden = o.dataset.method !== b.dataset.provider; } });
        });
    });
    document.querySelectorAll('.resolve-btn').forEach(function (b) {
        b.addEventListener('click', function () { document.getElementById('rId').value = b.dataset.id; document.getElementById('rDesc').textContent = b.dataset.desc; });
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
