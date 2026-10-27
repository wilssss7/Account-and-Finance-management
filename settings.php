<?php
/**
 * settings.php - Users and Roles (role-based access control administration)
 * Finance Administrators manage user accounts and the permissions of each role.
 * Passwords are stored with password_hash(); this page never displays or logs them.
 * Permission changes apply to a user the next time that user logs in.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('users.manage');

$pdo = db();
$me  = current_user();
$adminRoleId = (int)db_value("SELECT role_id FROM roles WHERE role_name = 'Finance Administrator'");

function check_password_rules(string $pw, string $confirm): void
{
    if ($pw !== $confirm) { throw new RuntimeException('The two passwords do not match.'); }
    if (strlen($pw) < 8 || strlen($pw) > 72) { throw new RuntimeException('The password must be 8 to 72 characters long.'); }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) { throw new RuntimeException('The password must contain at least one letter and one number.'); }
}

/** Throws if removing admin rights/activity from $userId would leave no active administrator. */
function ensure_other_admin(int $userId, int $adminRoleId): void
{
    $others = db_value('SELECT COUNT(*) FROM users WHERE role_id = ? AND is_active = 1 AND user_id <> ?', [$adminRoleId, $userId]);
    if ($others == 0) { throw new RuntimeException('At least one active Finance Administrator must remain.'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $backTab = $action === 'save_permissions' ? '?tab=roles&role=' . (int)($_POST['role_id'] ?? 0) : '';
    try {
        $pdo->beginTransaction();

        if ($action === 'add_user') {
            $name = clean_text($_POST['full_name'] ?? '', 120);
            $username = trim((string)($_POST['username'] ?? ''));
            $email = clean_text($_POST['email'] ?? '', 120);
            $roleId = (int)($_POST['role_id'] ?? 0);
            if (mb_strlen($name) < 2) { throw new RuntimeException('Enter the full name.'); }
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) { throw new RuntimeException('The username must be 3-50 characters: letters, numbers, dot, dash or underscore.'); }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('Enter a valid email address.'); }
            if (db_value('SELECT COUNT(*) FROM roles WHERE role_id = ?', [$roleId]) == 0) { throw new RuntimeException('Choose a role.'); }
            if (db_value('SELECT COUNT(*) FROM users WHERE LOWER(username) = LOWER(?)', [$username]) > 0) { throw new RuntimeException('That username is already taken.'); }
            if (db_value('SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(?)', [$email]) > 0) { throw new RuntimeException('That email address is already used.'); }
            check_password_rules((string)($_POST['password'] ?? ''), (string)($_POST['password_confirm'] ?? ''));
            $pdo->prepare('INSERT INTO users (role_id, full_name, username, email, password_hash) VALUES (?, ?, ?, ?, ?)')
                ->execute([$roleId, $name, $username, $email, password_hash((string)$_POST['password'], PASSWORD_DEFAULT)]);
            audit_log('user_created', 'users', (int)$pdo->lastInsertId(), null, ['username' => $username, 'role_id' => $roleId]);
            $pdo->commit();
            set_flash('success', "User $username was created.");

        } elseif ($action === 'edit_user') {
            $u = db_one('SELECT * FROM users WHERE user_id = ? FOR UPDATE', [(int)($_POST['user_id'] ?? 0)]);
            if (!$u) { throw new RuntimeException('User not found.'); }
            $name = clean_text($_POST['full_name'] ?? '', 120);
            $email = clean_text($_POST['email'] ?? '', 120);
            $roleId = (int)($_POST['role_id'] ?? 0);
            $active = isset($_POST['is_active']) ? 1 : 0;
            if (mb_strlen($name) < 2) { throw new RuntimeException('Enter the full name.'); }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('Enter a valid email address.'); }
            if (db_value('SELECT COUNT(*) FROM roles WHERE role_id = ?', [$roleId]) == 0) { throw new RuntimeException('Choose a role.'); }
            if (db_value('SELECT COUNT(*) FROM users WHERE LOWER(email) = LOWER(?) AND user_id <> ?', [$email, (int)$u['user_id']]) > 0) { throw new RuntimeException('That email address is already used.'); }
            if ((int)$u['user_id'] === (int)$me['id'] && ($roleId !== (int)$u['role_id'] || !$active)) {
                throw new RuntimeException('You cannot change your own role or deactivate your own account. Ask another administrator.');
            }
            if ((int)$u['role_id'] === $adminRoleId && (int)$u['is_active'] === 1 && ($roleId !== $adminRoleId || !$active)) { ensure_other_admin((int)$u['user_id'], $adminRoleId); }
            $pdo->prepare('UPDATE users SET full_name = ?, email = ?, role_id = ?, is_active = ? WHERE user_id = ?')->execute([$name, $email, $roleId, $active, (int)$u['user_id']]);
            audit_log('user_updated', 'users', $u['user_id'], ['role_id' => $u['role_id'], 'is_active' => $u['is_active'], 'email' => $u['email']], ['role_id' => $roleId, 'is_active' => $active, 'email' => $email]);
            $pdo->commit();
            set_flash('success', 'User ' . $u['username'] . ' was updated. Role changes apply at the next login.');

        } elseif ($action === 'reset_password') {
            $u = db_one('SELECT user_id, username FROM users WHERE user_id = ? FOR UPDATE', [(int)($_POST['user_id'] ?? 0)]);
            if (!$u) { throw new RuntimeException('User not found.'); }
            check_password_rules((string)($_POST['password'] ?? ''), (string)($_POST['password_confirm'] ?? ''));
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')->execute([password_hash((string)$_POST['password'], PASSWORD_DEFAULT), (int)$u['user_id']]);
            audit_log('password_reset', 'users', $u['user_id'], null, null, 'Password changed for ' . $u['username']);
            $pdo->commit();
            set_flash('success', 'The password for ' . $u['username'] . ' was changed.');

        } elseif ($action === 'save_permissions') {
            $roleId = (int)($_POST['role_id'] ?? 0);
            $role = db_one('SELECT * FROM roles WHERE role_id = ? FOR UPDATE', [$roleId]);
            if (!$role) { throw new RuntimeException('Role not found.'); }
            if ($roleId === $adminRoleId) { throw new RuntimeException('The Finance Administrator role always has every permission and cannot be changed.'); }
            $valid = array_column(db_all('SELECT permission_id, permission_key FROM permissions'), 'permission_key', 'permission_id');
            $new = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['permissions'] ?? [])), static fn($id) => isset($valid[$id]))));
            $oldIds = array_map('intval', array_column(db_all('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$roleId]), 'permission_id'));
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
            $ins = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
            foreach ($new as $pid) { $ins->execute([$roleId, $pid]); }
            $added = array_map(static fn($i) => $valid[$i], array_diff($new, $oldIds));
            $removed = array_map(static fn($i) => $valid[$i], array_diff($oldIds, $new));
            audit_log('role_permissions_changed', 'roles', $roleId, ['removed' => array_values($removed)], ['added' => array_values($added)], 'Role: ' . $role['role_name']);
            $pdo->commit();
            set_flash('success', 'Permissions for ' . $role['role_name'] . ' were saved (' . count($added) . ' added, ' . count($removed) . ' removed). Users get them at their next login.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The action could not be completed. Please try again.');
    }
    redirect('settings.php' . $backTab);
}

$tab = (($_GET['tab'] ?? '') === 'roles') ? 'roles' : 'users';
$roles = db_all('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.role_id) AS user_count FROM roles r ORDER BY r.role_id');
if ($tab === 'users') {
    $users = db_all('SELECT u.*, r.role_name FROM users u JOIN roles r ON r.role_id = u.role_id ORDER BY u.is_active DESC, u.full_name');
} else {
    $roleId = (int)($_GET['role'] ?? 0);
    $role = null;
    foreach ($roles as $r) { if ((int)$r['role_id'] === $roleId) { $role = $r; } }
    $role = $role ?? $roles[0];
    $roleId = (int)$role['role_id'];
    $perms = db_all('SELECT * FROM permissions ORDER BY permission_key');
    $have = array_map('intval', array_column(db_all('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$roleId]), 'permission_id'));
    $groups = [];
    foreach ($perms as $p) { $groups[explode('.', $p['permission_key'])[0]][] = $p; }
    $groupLabels = ['dashboard' => 'Dashboard', 'billing' => 'Guest billing', 'folio' => 'Folio verification', 'invoices' => 'Invoices', 'payments' => 'Payments', 'deposits' => 'Deposits',
        'refunds' => 'Refunds', 'discounts' => 'Discounts', 'adjustments' => 'Billing adjustments', 'receipts' => 'Receipts', 'expenses' => 'Expenses', 'suppliers' => 'Suppliers',
        'payables' => 'Accounts payable', 'receivables' => 'Accounts receivable', 'purchase_payments' => 'Purchase payments', 'payroll' => 'Payroll (restricted)', 'settings' => 'Settings',
        'reports' => 'Reports', 'exports' => 'Exports', 'reconciliation' => 'Reconciliation', 'audit' => 'Audit trail', 'period' => 'Period closing', 'budgets' => 'Budgets', 'users' => 'Users and roles'];
}

$pageTitle = 'Users and Roles';
include __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <ul class="nav nav-tabs-soft nav border-0">
        <li class="nav-item"><a class="nav-link <?= $tab === 'users' ? 'active' : '' ?>" href="<?= e(url('settings.php')) ?>">Users</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab === 'roles' ? 'active' : '' ?>" href="<?= e(url('settings.php?tab=roles')) ?>">Role permissions</a></li>
    </ul>
    <?php if ($tab === 'users'): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-person-plus me-1"></i>Add user</button><?php endif; ?>
</div>

<?php if ($tab === 'users'): ?>
<div class="app-card"><div class="table-responsive">
    <table class="table table-hover">
        <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr class="<?= $u['is_active'] ? '' : 'row-locked' ?>">
                <td><strong><?= e($u['full_name']) ?></strong><div class="small text-muted"><?= e($u['email']) ?></div></td>
                <td><?= e($u['username']) ?></td>
                <td><span class="badge badge-soft badge-pink"><?= e($u['role_name']) ?></span></td>
                <td><?= $u['is_active'] ? '<span class="badge badge-soft badge-sage">Active</span>' : '<span class="badge badge-soft badge-terracotta">Inactive</span>' ?></td>
                <td class="small"><?= $u['last_login'] ? e(format_date($u['last_login'], 'M d, Y g:i A')) : 'Never' ?></td>
                <td class="text-end text-nowrap">
                    <button class="btn btn-outline-primary btn-sm edit-btn" data-bs-toggle="modal" data-bs-target="#editModal" data-id="<?= (int)$u['user_id'] ?>" data-name="<?= e($u['full_name']) ?>"
                        data-email="<?= e($u['email']) ?>" data-role="<?= (int)$u['role_id'] ?>" data-active="<?= (int)$u['is_active'] ?>" data-self="<?= (int)$u['user_id'] === (int)$me['id'] ? '1' : '0' ?>">Edit</button>
                    <button class="btn btn-outline-secondary btn-sm pw-btn" data-bs-toggle="modal" data-bs-target="#pwModal" data-id="<?= (int)$u['user_id'] ?>" data-name="<?= e($u['username']) ?>">Password</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>

<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_user">
        <div class="modal-header"><h5 class="modal-title">Add user</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-12"><label class="form-label">Full name</label><input type="text" name="full_name" maxlength="120" class="form-control" required></div>
            <div class="col-6"><label class="form-label">Username</label><input type="text" name="username" maxlength="50" pattern="[A-Za-z0-9._\-]{3,50}" class="form-control" required></div>
            <div class="col-6"><label class="form-label">Role</label><select name="role_id" class="form-select" required><?php foreach ($roles as $r): ?><option value="<?= (int)$r['role_id'] ?>"><?= e($r['role_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label">Email</label><input type="email" name="email" maxlength="120" class="form-control" required></div>
            <div class="col-6"><label class="form-label">Password</label><input type="password" name="password" minlength="8" maxlength="72" class="form-control" autocomplete="new-password" required></div>
            <div class="col-6"><label class="form-label">Confirm password</label><input type="password" name="password_confirm" minlength="8" maxlength="72" class="form-control" autocomplete="new-password" required></div>
        </div><div class="form-text mt-2">At least 8 characters with a letter and a number.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Create user</button></div>
    </form></div>
</div>
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="edit_user"><input type="hidden" name="user_id" id="euId">
        <div class="modal-header"><h5 class="modal-title">Edit user</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-12"><label class="form-label">Full name</label><input type="text" name="full_name" id="euName" maxlength="120" class="form-control" required></div>
            <div class="col-12"><label class="form-label">Email</label><input type="email" name="email" id="euEmail" maxlength="120" class="form-control" required></div>
            <div class="col-6"><label class="form-label">Role</label><select name="role_id" id="euRole" class="form-select" required><?php foreach ($roles as $r): ?><option value="<?= (int)$r['role_id'] ?>"><?= e($r['role_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="euActive"><label class="form-check-label" for="euActive">Account is active</label></div></div>
        </div><div class="alert alert-warning small mt-3 mb-0 d-none" id="euSelf">This is your own account. You cannot change your role or deactivate it.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save changes</button></div>
    </form></div>
</div>
<div class="modal fade" id="pwModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" id="pwId">
        <div class="modal-header"><h5 class="modal-title">Change password for <span id="pwName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-6"><label class="form-label">New password</label><input type="password" name="password" minlength="8" maxlength="72" class="form-control" autocomplete="new-password" required></div>
            <div class="col-6"><label class="form-label">Confirm</label><input type="password" name="password_confirm" minlength="8" maxlength="72" class="form-control" autocomplete="new-password" required></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Change password</button></div>
    </form></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.edit-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('euId').value = b.dataset.id; document.getElementById('euName').value = b.dataset.name;
            document.getElementById('euEmail').value = b.dataset.email; document.getElementById('euRole').value = b.dataset.role;
            document.getElementById('euActive').checked = b.dataset.active === '1';
            var self = b.dataset.self === '1';
            document.getElementById('euSelf').classList.toggle('d-none', !self);
        });
    });
    document.querySelectorAll('.pw-btn').forEach(function (b) {
        b.addEventListener('click', function () { document.getElementById('pwId').value = b.dataset.id; document.getElementById('pwName').textContent = b.dataset.name; });
    });
});
</script>

<?php else: ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($roles as $r): ?>
        <a class="btn <?= (int)$r['role_id'] === $roleId ? 'btn-primary' : 'btn-outline-secondary' ?> btn-sm" href="<?= e(url('settings.php?tab=roles&role=' . (int)$r['role_id'])) ?>"><?= e($r['role_name']) ?> <span class="opacity-75">(<?= (int)$r['user_count'] ?>)</span></a>
    <?php endforeach; ?>
</div>
<?php $locked = $roleId === $adminRoleId; ?>
<form method="post" class="app-card">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_permissions"><input type="hidden" name="role_id" value="<?= (int)$roleId ?>">
    <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title-serif"><?= e($role['role_name']) ?></h3>
        <?php if (!$locked): ?><button class="btn btn-primary btn-sm" type="submit">Save permissions</button><?php endif; ?></div>
    <div class="card-body">
        <p class="small text-muted"><?= e($role['description'] ?? '') ?> <?= $locked ? 'This role always has every permission and cannot be changed.' : 'Changes apply to users with this role at their next login.' ?></p>
        <div class="row g-3">
            <?php foreach ($groups as $g => $items): ?>
                <div class="col-md-6 col-xl-4"><div class="p-3 rounded h-100" style="background:#FFFCF6;border:1px solid var(--line)">
                    <div class="fw-semibold mb-2"><?= e($groupLabels[$g] ?? ucfirst($g)) ?></div>
                    <?php foreach ($items as $p): ?>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="permissions[]" value="<?= (int)$p['permission_id'] ?>" id="p<?= (int)$p['permission_id'] ?>"
                            <?= in_array((int)$p['permission_id'], $have, true) ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
                            <label class="form-check-label small" for="p<?= (int)$p['permission_id'] ?>"><?= e($p['description'] ?? $p['permission_key']) ?><span class="text-muted d-block" style="font-size:.7rem"><?= e($p['permission_key']) ?></span></label></div>
                    <?php endforeach; ?>
                </div></div>
            <?php endforeach; ?>
        </div>
    </div>
</form>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
