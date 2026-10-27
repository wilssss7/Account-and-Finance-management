<?php
/**
 * audit-trail.php - Financial Audit Trail (module 31)
 * Read-only list of important financial actions: who, when, which record, what changed and why.
 * There is no edit or delete anywhere: database triggers refuse any UPDATE or DELETE on audit_logs.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('audit.view');

$action = clean_text($_GET['action'] ?? '', 40);
$userId = (int)($_GET['user'] ?? 0);
$table  = clean_text($_GET['table'] ?? '', 60);
$q      = clean_text($_GET['q'] ?? '', 60);
$from   = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$to     = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
$hideAuth = !isset($_GET['auth']);
$page = current_page_number();
$perPage = 25;

$where = ['1 = 1']; $params = [];
if ($action !== '') { $where[] = 'a.action_type = ?'; $params[] = $action; }
if ($userId > 0) { $where[] = 'a.user_id = ?'; $params[] = $userId; }
if ($table !== '') { $where[] = 'a.table_name = ?'; $params[] = $table; }
if ($q !== '') { $like = '%' . $q . '%'; $where[] = '(a.reason LIKE ? OR a.record_id LIKE ? OR a.new_values LIKE ? OR a.old_values LIKE ?)'; array_push($params, $like, $like, $like, $like); }
if ($from !== '') { $where[] = 'DATE(a.created_at) >= ?'; $params[] = $from; }
if ($to !== '')   { $where[] = 'DATE(a.created_at) <= ?'; $params[] = $to; }
if ($hideAuth) { $where[] = "a.action_type NOT IN ('login', 'logout')"; }
$fromSql = 'FROM audit_logs a LEFT JOIN users u ON u.user_id = a.user_id WHERE ' . implode(' AND ', $where);
$total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
$rows = db_all("SELECT a.*, COALESCE(u.full_name, 'System / unknown') AS who $fromSql ORDER BY a.created_at DESC, a.log_id DESC", $params, $perPage, ($page - 1) * $perPage);
$actions = array_column(db_all('SELECT DISTINCT action_type FROM audit_logs ORDER BY action_type'), 'action_type');
$tables  = array_column(db_all("SELECT DISTINCT table_name FROM audit_logs WHERE table_name IS NOT NULL ORDER BY table_name"), 'table_name');
$users   = db_all('SELECT user_id, full_name FROM users ORDER BY full_name');

/** Pretty-prints stored JSON; falls back to the raw text. */
function pretty_values(?string $v): string
{
    if ($v === null || $v === '') { return ''; }
    $d = json_decode($v, true);
    if (!is_array($d)) { return e($v); }
    $out = '';
    foreach ($d as $k => $val) {
        $out .= '<div><span class="text-muted">' . e((string)$k) . ':</span> ' . e(is_array($val) ? json_encode($val) : (string)$val) . '</div>';
    }
    return $out;
}

$pageTitle = 'Audit Trail';
include __DIR__ . '/includes/header.php';
?>
<div class="alert alert-info py-2 small"><i class="bi bi-lock me-1"></i>The audit trail is read-only. Entries cannot be edited or deleted by anyone, including administrators (the database refuses it).</div>

<form method="get" class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small mb-1">Search</label><input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Reason, record or values"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Action</label>
            <select name="action" class="form-select form-select-sm"><option value="">All</option>
                <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $a))) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">User</label>
            <select name="user" class="form-select form-select-sm"><option value="0">All</option>
                <?php foreach ($users as $u): ?><option value="<?= (int)$u['user_id'] ?>" <?= $userId === (int)$u['user_id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Record type</label>
            <select name="table" class="form-select form-select-sm"><option value="">All</option>
                <?php foreach ($tables as $t): ?><option value="<?= e($t) ?>" <?= $table === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="auth" id="auth" value="1" <?= !$hideAuth ? 'checked' : '' ?>><label class="form-check-label small" for="auth">Include logins and logouts</label></div></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <div class="col-md-2 d-grid"><button class="btn btn-primary btn-sm" type="submit">Filter</button></div>
    </div>
</form>

<div class="app-card"><div class="table-responsive">
    <table class="table table-hover">
        <thead><tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th>Reason</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No audit entries match your filters.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="text-nowrap small"><?= e(format_date($r['created_at'], 'M d, Y')) ?><div class="text-muted"><?= e(format_date($r['created_at'], 'g:i:s A')) ?></div></td>
                <td><?= e($r['who']) ?></td>
                <td><span class="badge badge-soft badge-pink"><?= e(ucfirst(str_replace('_', ' ', $r['action_type']))) ?></span></td>
                <td class="small"><?= e($r['table_name'] ?? '-') ?><?= $r['record_id'] !== null ? '<div class="text-muted">#' . e($r['record_id']) . '</div>' : '' ?></td>
                <td class="small text-break" style="max-width:320px"><?= e($r['reason'] ?? '') ?></td>
                <td class="text-end">
                    <button type="button" class="btn btn-outline-primary btn-sm audit-view" data-bs-toggle="modal" data-bs-target="#auditModal"
                        data-id="<?= (int)$r['log_id'] ?>"
                        data-action="<?= e(ucfirst(str_replace('_', ' ', $r['action_type']))) ?>"
                        data-when="<?= e(format_date($r['created_at'], 'F d, Y g:i:s A')) ?>"
                        data-user="<?= e($r['who']) ?>"
                        data-table="<?= e($r['table_name'] ?? '') ?>"
                        data-record="<?= e((string)($r['record_id'] ?? '')) ?>"
                        data-reason="<?= e($r['reason'] ?? '') ?>"
                        data-ip="<?= e($r['ip_address'] ?? '') ?>"
                        data-old="<?= e($r['old_values'] ?? '') ?>"
                        data-new="<?= e($r['new_values'] ?? '') ?>"><i class="bi bi-eye me-1"></i>View</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<div class="small text-muted mt-2"><?= $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?></div>
<?= render_pager($total, $page, $perPage) ?>

<div class="modal fade" id="auditModal" tabindex="-1" aria-labelledby="auditModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="auditModalTitle">Audit entry <span class="text-muted" id="amNumber"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <dl class="audit-dl" id="amFields"></dl>
                <div id="amBefore" class="d-none mt-3"></div>
                <div id="amAfter" class="d-none mt-3"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var fields = document.getElementById('amFields'), before = document.getElementById('amBefore'), after = document.getElementById('amAfter');

    function row(parent, label, value) {
        var dt = document.createElement('dt'), dd = document.createElement('dd');
        dt.textContent = label; dd.textContent = value;      // textContent keeps every value as plain text (safe)
        parent.appendChild(dt); parent.appendChild(dd);
    }
    function section(box, title, raw) {
        box.innerHTML = ''; box.classList.add('d-none');
        if (!raw) { return; }
        var h = document.createElement('h6'); h.className = 'audit-section-title'; h.textContent = title; box.appendChild(h);
        var data = null;
        try { data = JSON.parse(raw); } catch (e) { data = null; }
        if (data && typeof data === 'object' && !Array.isArray(data)) {
            var dl = document.createElement('dl'); dl.className = 'audit-dl';
            Object.keys(data).forEach(function (k) {
                var v = data[k];
                row(dl, k.replace(/_/g, ' '), (v !== null && typeof v === 'object') ? JSON.stringify(v, null, 2) : String(v));
            });
            box.appendChild(dl);
        } else {
            var pre = document.createElement('pre'); pre.className = 'audit-pre'; pre.textContent = raw; box.appendChild(pre);
        }
        box.classList.remove('d-none');
    }
    document.querySelectorAll('.audit-view').forEach(function (b) {
        b.addEventListener('click', function () {
            var d = b.dataset;
            document.getElementById('amNumber').textContent = '#' + d.id;
            fields.innerHTML = '';
            row(fields, 'Action', d.action);
            row(fields, 'Date and time', d.when);
            row(fields, 'User', d.user);
            row(fields, 'Record type', d.table || '-');
            row(fields, 'Record ID', d.record || '-');
            row(fields, 'Reason', d.reason || '-');
            row(fields, 'IP address', d.ip || '-');
            section(before, 'Before the change', d.old);
            section(after, 'After the change', d.new);
        });
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
