<?php
/**
 * folio-verification.php - Guest Folio Charge Verification (module 3)
 * Review room, restaurant, minibar and other charges; verify or flag them with notes.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('folio.verify');

$pdo = db();
$me  = current_user();

/** Verifies or flags one charge. Throws RuntimeException for rule violations. */
function review_charge(int $chargeId, string $result, string $notes, int $userId): string
{
    $pdo = db();
    $c = db_one('SELECT c.*, f.status AS folio_status, f.folio_number FROM folio_charges c
                 JOIN guest_folios f ON f.folio_id = c.folio_id WHERE c.charge_id = ? FOR UPDATE', [$chargeId]);
    if (!$c) {
        throw new RuntimeException('Charge not found.');
    }
    $onInvoice = db_value("SELECT COUNT(*) FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
                           WHERE ii.folio_charge_id = ? AND i.status <> 'cancelled'", [$chargeId]);
    if ($onInvoice > 0 || !in_array($c['folio_status'], ['open', 'verified'], true)) {
        throw new RuntimeException('Charge in ' . $c['folio_number'] . ' is already invoiced and is locked.');
    }
    if ($result === 'flagged' && mb_strlen($notes) < 3) {
        throw new RuntimeException('Enter a note explaining why the charge is flagged.');
    }
    $old = $c['verification_status'];
    $pdo->prepare('UPDATE folio_charges SET verification_status = ?, verification_notes = ?, verified_by = ?, verified_at = NOW()
                   WHERE charge_id = ?')->execute([$result, $notes !== '' ? $notes : null, $userId, $chargeId]);

    // Folio becomes "verified" only when every charge is verified.
    $notVerified = db_value("SELECT COUNT(*) FROM folio_charges WHERE folio_id = ? AND verification_status <> 'verified'", [(int)$c['folio_id']]);
    $newFolio = $notVerified == 0 ? 'verified' : 'open';
    if ($newFolio !== $c['folio_status']) {
        $pdo->prepare('UPDATE guest_folios SET status = ? WHERE folio_id = ?')->execute([$newFolio, (int)$c['folio_id']]);
    }
    audit_log($result === 'verified' ? 'folio_charge_verified' : 'folio_charge_flagged', 'folio_charges', $chargeId,
        ['status' => $old], ['status' => $result, 'description' => $c['description'], 'amount' => $c['amount']], $notes !== '' ? $notes : null);
    return $c['description'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $notes  = clean_text($_POST['notes'] ?? '', 255);
    try {
        $pdo->beginTransaction();
        if ($action === 'verify' || $action === 'flag') {
            $name = review_charge((int)($_POST['charge_id'] ?? 0), $action === 'verify' ? 'verified' : 'flagged', $notes, (int)$me['id']);
            $pdo->commit();
            set_flash($action === 'verify' ? 'success' : 'warning', ($action === 'verify' ? 'Verified: ' : 'Flagged: ') . $name);
        } elseif ($action === 'bulk_verify') {
            $ids = array_filter(array_map('intval', (array)($_POST['charge_ids'] ?? [])), static fn($v) => $v > 0);
            if (!$ids) { throw new RuntimeException('Select at least one charge to verify.'); }
            $n = 0;
            foreach ($ids as $id) { review_charge($id, 'verified', '', (int)$me['id']); $n++; }
            $pdo->commit();
            set_flash('success', "$n charge(s) verified.");
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The action could not be completed. Please try again.');
    }
    redirect('folio-verification.php' . (($_POST['return'] ?? '') !== '' ? '?' . ltrim((string)$_POST['return'], '?') : ''));
}

/* ------------------------------ Filters ------------------------------ */
$status  = (string)($_GET['status'] ?? 'pending');
$cat     = (string)($_GET['category'] ?? '');
$q       = clean_text($_GET['q'] ?? '', 60);
$folioId = (int)($_GET['folio'] ?? 0);
$page    = current_page_number();
$perPage = 20;

$where = ['1 = 1'];
$params = [];
if (in_array($status, ['pending', 'verified', 'flagged'], true)) { $where[] = 'c.verification_status = ?'; $params[] = $status; }
else { $status = 'all'; }
if (isset(CHARGE_CATEGORIES[$cat])) { $where[] = 'c.category = ?'; $params[] = $cat; }
if ($folioId > 0) { $where[] = 'c.folio_id = ?'; $params[] = $folioId; }
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(f.folio_number LIKE ? OR g.full_name LIKE ? OR c.description LIKE ?)';
    array_push($params, $like, $like, $like);
}
$fromSql = 'FROM folio_charges c JOIN guest_folios f ON f.folio_id = c.folio_id JOIN guests g ON g.guest_id = f.guest_id
            LEFT JOIN users u ON u.user_id = c.verified_by WHERE ' . implode(' AND ', $where);

$total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
$rows = db_all("SELECT c.*, f.folio_number, f.status AS folio_status, g.full_name, u.full_name AS verifier,
                (SELECT COUNT(*) FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
                 WHERE ii.folio_charge_id = c.charge_id AND i.status <> 'cancelled') AS on_invoice
                $fromSql ORDER BY c.verification_status = 'pending' DESC, c.charge_date DESC, c.charge_id DESC", $params, $perPage, ($page - 1) * $perPage);

$counts = db_one("SELECT SUM(verification_status = 'pending') AS p, SUM(verification_status = 'flagged') AS f,
                         SUM(verification_status = 'verified') AS v FROM folio_charges");

$pageTitle = 'Folio Charge Verification';
include __DIR__ . '/includes/header.php';
$returnQs = qs();
?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <span class="summary-chip"><strong><?= (int)$counts['p'] ?></strong> pending</span>
    <span class="summary-chip"><strong><?= (int)$counts['f'] ?></strong> flagged</span>
    <span class="summary-chip"><strong><?= (int)$counts['v'] ?></strong> verified</span>
</div>

<form method="get" class="filter-bar mb-3">
    <?php if ($folioId > 0): ?><input type="hidden" name="folio" value="<?= (int)$folioId ?>"><?php endif; ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label small mb-1">Search</label>
            <input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Guest, folio or description"></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <?php foreach (['pending' => 'Pending', 'flagged' => 'Flagged', 'verified' => 'Verified', 'all' => 'All'] as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1">Category</label>
            <select name="category" class="form-select form-select-sm"><option value="">All</option>
                <?php foreach (CHARGE_CATEGORIES as $k => $v): ?><option value="<?= e($k) ?>" <?= $cat === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2 d-grid"><button class="btn btn-primary btn-sm" type="submit">Filter</button></div>
    </div>
</form>

<form method="post" id="bulkForm">
    <?= csrf_field() ?>
    <input type="hidden" name="return" value="<?= e($returnQs) ?>">
    <div class="app-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title-serif">Charges</h3>
            <button class="btn btn-secondary btn-sm" type="submit" name="action" value="bulk_verify" data-confirm="Verify all selected charges?"><i class="bi bi-check2-all me-1"></i>Verify selected</button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th style="width:36px"><input class="form-check-input" type="checkbox" id="checkAll" aria-label="Select all"></th>
                    <th>Folio / guest</th><th>Date</th><th>Category</th><th>Description</th><th class="amount">Amount</th><th>Status</th><th>Notes</th><th></th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No charges to show.</td></tr><?php endif; ?>
                <?php foreach ($rows as $r): $locked = $r['on_invoice'] > 0 || !in_array($r['folio_status'], ['open', 'verified'], true); ?>
                    <tr class="<?= $locked ? 'row-locked' : '' ?>">
                        <td><?php if (!$locked && $r['verification_status'] !== 'verified'): ?>
                            <input class="form-check-input row-check" type="checkbox" name="charge_ids[]" value="<?= (int)$r['charge_id'] ?>"><?php endif; ?></td>
                        <td><a href="<?= e(url('guest-billing.php?folio=' . (int)$r['folio_id'])) ?>"><?= e($r['folio_number']) ?></a><div class="small text-muted"><?= e($r['full_name']) ?></div></td>
                        <td><?= e(format_date($r['charge_date'])) ?></td>
                        <td><?= e(CHARGE_CATEGORIES[$r['category']] ?? $r['category']) ?></td>
                        <td><?= e($r['description']) ?><div class="small text-muted">Qty <?= e(rtrim(rtrim(number_format((float)$r['quantity'], 2), '0'), '.')) ?> &times; <?= e(peso($r['unit_price'])) ?></div></td>
                        <td class="amount"><?= e(peso($r['amount'])) ?></td>
                        <td><?= status_badge($r['verification_status']) ?></td>
                        <td class="small"><?= e($r['verification_notes'] ?? '') ?>
                            <?php if ($r['verifier']): ?><div class="text-muted">by <?= e($r['verifier']) ?>, <?= e(format_date($r['verified_at'], 'M d g:i A')) ?></div><?php endif; ?></td>
                        <td class="text-end">
                            <?php if ($locked): ?><span class="small text-muted"><i class="bi bi-lock"></i> invoiced</span>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline-primary btn-sm review-btn" data-bs-toggle="modal" data-bs-target="#reviewModal"
                                    data-id="<?= (int)$r['charge_id'] ?>" data-desc="<?= e($r['description'] . ' - ' . peso($r['amount'])) ?>"
                                    data-notes="<?= e($r['verification_notes'] ?? '') ?>">Review</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>
<div class="small text-muted mt-2"><?= $total ?> charge(s)</div>
<?= render_pager($total, $page, $perPage) ?>

<div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e($returnQs) ?>">
        <input type="hidden" name="charge_id" id="rvId">
        <div class="modal-header"><h5 class="modal-title">Review charge</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <p class="fw-semibold" id="rvDesc"></p>
            <label class="form-label" for="rvNotes">Verification notes</label>
            <textarea class="form-control" name="notes" id="rvNotes" rows="3" maxlength="255" placeholder="Required when flagging a charge"></textarea>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-danger" type="submit" name="action" value="flag"><i class="bi bi-flag me-1"></i>Flag as incorrect</button>
            <button class="btn btn-success" type="submit" name="action" value="verify"><i class="bi bi-check-lg me-1"></i>Verify</button>
        </div>
    </form></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.review-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('rvId').value = b.dataset.id;
            document.getElementById('rvDesc').textContent = b.dataset.desc;
            document.getElementById('rvNotes').value = b.dataset.notes || '';
        });
    });
    var all = document.getElementById('checkAll');
    if (all) { all.addEventListener('change', function () {
        document.querySelectorAll('.row-check').forEach(function (c) { c.checked = all.checked; });
    }); }
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
