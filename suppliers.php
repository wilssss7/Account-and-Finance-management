<?php
/**
 * suppliers.php - Supplier Invoice Management (module 20)
 * Tab 1: supplier invoices (supplier, invoice number, dates, amount, payment status).
 * Tab 2: the supplier directory.
 * A supplier invoice is NOT an expense until it is paid (see purchase-payments.php).
 * Invoices are never deleted; an unpaid one can be cancelled with a reason.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('suppliers.manage');

$pdo = db();
$me  = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $tabBack = in_array($action, ['add_supplier', 'edit_supplier', 'toggle_supplier'], true) ? '?tab=suppliers' : '';
    try {
        $pdo->beginTransaction();

        if ($action === 'add_invoice') {
            $supplierId = (int)($_POST['supplier_id'] ?? 0);
            $catId   = (int)($_POST['category_id'] ?? 0);
            $number  = clean_text($_POST['invoice_number'] ?? '', 40);
            $invDate = (string)($_POST['invoice_date'] ?? '');
            $dueDate = (string)($_POST['due_date'] ?? '');
            $amount  = parse_money($_POST['amount'] ?? '');
            $notes   = clean_text($_POST['notes'] ?? '', 255);
            $sup = db_one('SELECT supplier_id, is_active FROM suppliers WHERE supplier_id = ?', [$supplierId]);
            if (!$sup || !$sup['is_active']) { throw new RuntimeException('Choose an active supplier.'); }
            if ($number === '') { throw new RuntimeException('Enter the supplier\'s invoice number.'); }
            if (!valid_date($invDate) || $invDate > date('Y-m-d')) { throw new RuntimeException('The invoice date must be a valid date that is not in the future.'); }
            if (!valid_date($dueDate) || $dueDate < $invDate) { throw new RuntimeException('The due date must be on or after the invoice date.'); }
            if ($amount === null) { throw new RuntimeException('Enter a valid invoice amount.'); }
            if ($catId > 0 && db_value('SELECT COUNT(*) FROM expense_categories WHERE category_id = ?', [$catId]) == 0) { throw new RuntimeException('Choose a valid category.'); }
            if (db_value('SELECT COUNT(*) FROM supplier_invoices WHERE supplier_id = ? AND invoice_number = ?', [$supplierId, $number]) > 0) {
                throw new RuntimeException('This supplier invoice number was already recorded.');
            }
            $pdo->prepare("INSERT INTO supplier_invoices (supplier_id, category_id, invoice_number, invoice_date, due_date, amount, status, notes, recorded_by)
                           VALUES (?, ?, ?, ?, ?, ?, 'unpaid', ?, ?)")
                ->execute([$supplierId, $catId ?: null, $number, $invDate, $dueDate, $amount, $notes !== '' ? $notes : null, (int)$me['id']]);
            $id = (int)$pdo->lastInsertId();
            refresh_supplier_invoice_status($id);
            audit_log('supplier_invoice_recorded', 'supplier_invoices', $id, null, ['invoice_number' => $number, 'amount' => $amount, 'due_date' => $dueDate]);
            $pdo->commit();
            set_flash('success', "Supplier invoice $number was recorded.");

        } elseif ($action === 'cancel_invoice') {
            $reason = clean_text($_POST['reason'] ?? '', 255);
            if (mb_strlen($reason) < 5) { throw new RuntimeException('Enter the cancellation reason (at least 5 characters).'); }
            $si = db_one('SELECT * FROM supplier_invoices WHERE supplier_invoice_id = ? FOR UPDATE', [(int)($_POST['supplier_invoice_id'] ?? 0)]);
            if (!$si) { throw new RuntimeException('Supplier invoice not found.'); }
            if ($si['status'] === 'cancelled') { throw new RuntimeException('This invoice is already cancelled.'); }
            if (db_value('SELECT COUNT(*) FROM supplier_payments WHERE supplier_invoice_id = ?', [(int)$si['supplier_invoice_id']]) > 0) {
                throw new RuntimeException('An invoice with payments cannot be cancelled.');
            }
            $notes = trim(($si['notes'] ? $si['notes'] . ' | ' : '') . 'Cancelled: ' . $reason);
            $pdo->prepare("UPDATE supplier_invoices SET status = 'cancelled', notes = ? WHERE supplier_invoice_id = ?")->execute([mb_substr($notes, 0, 255), (int)$si['supplier_invoice_id']]);
            audit_log('supplier_invoice_cancelled', 'supplier_invoices', $si['supplier_invoice_id'], ['status' => $si['status']], ['status' => 'cancelled'], $reason);
            $pdo->commit();
            set_flash('success', 'Supplier invoice ' . $si['invoice_number'] . ' was cancelled.');

        } elseif ($action === 'add_supplier' || $action === 'edit_supplier') {
            $id      = (int)($_POST['supplier_id'] ?? 0);
            $name    = clean_text($_POST['supplier_name'] ?? '', 120);
            $contact = clean_text($_POST['contact_person'] ?? '', 100);
            $phone   = clean_text($_POST['phone'] ?? '', 30);
            $email   = clean_text($_POST['email'] ?? '', 120);
            $address = clean_text($_POST['address'] ?? '', 255);
            if (mb_strlen($name) < 2) { throw new RuntimeException('Enter the supplier name.'); }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('Enter a valid email address or leave it blank.'); }
            $dup = db_value('SELECT COUNT(*) FROM suppliers WHERE LOWER(supplier_name) = LOWER(?) AND supplier_id <> ?', [$name, $id]);
            if ($dup > 0) { throw new RuntimeException('A supplier with this name already exists.'); }
            $vals = [$name, $contact ?: null, $phone ?: null, $email ?: null, $address ?: null];
            if ($action === 'add_supplier') {
                $pdo->prepare('INSERT INTO suppliers (supplier_name, contact_person, phone, email, address) VALUES (?, ?, ?, ?, ?)')->execute($vals);
                audit_log('supplier_added', 'suppliers', (int)$pdo->lastInsertId(), null, ['supplier_name' => $name]);
                $msg = "Supplier \"$name\" was added.";
            } else {
                if (db_value('SELECT COUNT(*) FROM suppliers WHERE supplier_id = ?', [$id]) == 0) { throw new RuntimeException('Supplier not found.'); }
                $pdo->prepare('UPDATE suppliers SET supplier_name = ?, contact_person = ?, phone = ?, email = ?, address = ? WHERE supplier_id = ?')->execute([...$vals, $id]);
                audit_log('supplier_updated', 'suppliers', $id, null, ['supplier_name' => $name]);
                $msg = "Supplier \"$name\" was updated.";
            }
            $pdo->commit();
            set_flash('success', $msg);

        } elseif ($action === 'toggle_supplier') {
            $s = db_one('SELECT supplier_id, supplier_name, is_active FROM suppliers WHERE supplier_id = ? FOR UPDATE', [(int)($_POST['supplier_id'] ?? 0)]);
            if (!$s) { throw new RuntimeException('Supplier not found.'); }
            $new = $s['is_active'] ? 0 : 1;
            $pdo->prepare('UPDATE suppliers SET is_active = ? WHERE supplier_id = ?')->execute([$new, (int)$s['supplier_id']]);
            audit_log($new ? 'supplier_activated' : 'supplier_deactivated', 'suppliers', $s['supplier_id'], ['is_active' => $s['is_active']], ['is_active' => $new]);
            $pdo->commit();
            set_flash('success', $s['supplier_name'] . ($new ? ' is active again.' : ' was deactivated. Its history is kept.'));
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The action could not be completed. Please try again.');
    }
    $ret = ltrim((string)($_POST['return'] ?? ''), '?');
    redirect('suppliers.php' . ($ret !== '' ? '?' . $ret : $tabBack));
}

$tab = (($_GET['tab'] ?? '') === 'suppliers') ? 'suppliers' : 'invoices';
$q = clean_text($_GET['q'] ?? '', 60);
$page = current_page_number();
$perPage = 15;
$suppliers = db_all('SELECT * FROM suppliers ORDER BY is_active DESC, supplier_name');
$cats = db_all('SELECT category_id, name FROM expense_categories ORDER BY name');

if ($tab === 'invoices') {
    $stat = (string)($_GET['status'] ?? '');
    $supFilter = (int)($_GET['supplier'] ?? 0);
    $from = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
    $to   = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
    $where = ['1 = 1']; $params = [];
    if ($q !== '') { $like = '%' . $q . '%'; $where[] = '(b.invoice_number LIKE ? OR b.supplier_name LIKE ?)'; array_push($params, $like, $like); }
    if (in_array($stat, ['unpaid', 'partially_paid', 'paid', 'overdue', 'cancelled'], true)) { $where[] = 'b.payment_status = ?'; $params[] = $stat; }
    if ($supFilter > 0) { $where[] = 'b.supplier_id = ?'; $params[] = $supFilter; }
    if ($from !== '') { $where[] = 'b.invoice_date >= ?'; $params[] = $from; }
    if ($to !== '')   { $where[] = 'b.invoice_date <= ?'; $params[] = $to; }
    $fromSql = 'FROM v_supplier_balances b WHERE ' . implode(' AND ', $where);
    $total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
    $sums = db_one("SELECT COALESCE(SUM(CASE WHEN b.payment_status <> 'cancelled' THEN b.amount END), 0) AS invoiced,
                           COALESCE(SUM(CASE WHEN b.payment_status NOT IN ('cancelled', 'paid') THEN b.balance END), 0) AS owed $fromSql", $params);
    $rows = db_all("SELECT b.* $fromSql ORDER BY b.due_date DESC, b.supplier_invoice_id DESC", $params, $perPage, ($page - 1) * $perPage);
}

$pageTitle = 'Suppliers and Supplier Invoices';
include __DIR__ . '/includes/header.php';
$returnQs = qs();
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <ul class="nav nav-tabs-soft nav border-0">
        <li class="nav-item"><a class="nav-link <?= $tab === 'invoices' ? 'active' : '' ?>" href="<?= e(url('suppliers.php')) ?>">Supplier invoices</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab === 'suppliers' ? 'active' : '' ?>" href="<?= e(url('suppliers.php?tab=suppliers')) ?>">Suppliers</a></li>
    </ul>
    <?php if ($tab === 'invoices'): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#invModal"><i class="bi bi-plus-lg me-1"></i>Record supplier invoice</button>
    <?php else: ?>
        <button class="btn btn-primary supplier-btn" data-bs-toggle="modal" data-bs-target="#supplierModal" data-mode="add"><i class="bi bi-plus-lg me-1"></i>Add supplier</button>
    <?php endif; ?>
</div>

<?php if ($tab === 'invoices'): ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <span class="summary-chip"><strong><?= $total ?></strong> invoice(s)</span>
    <span class="summary-chip">Invoiced <strong><?= e(peso($sums['invoiced'])) ?></strong></span>
    <span class="summary-chip">Still owed <strong><?= e(peso($sums['owed'])) ?></strong></span>
</div>
<form method="get" class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small mb-1">Search</label><input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Supplier or invoice number"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Supplier</label>
            <select name="supplier" class="form-select form-select-sm"><option value="0">All</option>
                <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['supplier_id'] ?>" <?= $supFilter === (int)$s['supplier_id'] ? 'selected' : '' ?>><?= e($s['supplier_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm"><option value="">All</option>
                <?php foreach (['unpaid', 'partially_paid', 'paid', 'overdue', 'cancelled'] as $s): ?><option value="<?= e($s) ?>" <?= $stat === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Invoiced from</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Invoiced to</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <div class="col-md-1 d-grid"><button class="btn btn-primary btn-sm" type="submit">Go</button></div>
    </div>
</form>
<div class="app-card"><div class="table-responsive">
    <table class="table table-hover">
        <thead><tr><th>Supplier / invoice</th><th>Invoice date</th><th>Due date</th><th class="amount">Amount</th><th class="amount">Paid</th><th class="amount">Balance</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No supplier invoices match your filters.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $cx = $r['payment_status'] === 'cancelled'; ?>
            <tr class="<?= $cx ? 'row-locked' : '' ?>">
                <td><strong><?= e($r['supplier_name']) ?></strong><div class="small text-muted"><?= e($r['invoice_number']) ?></div></td>
                <td><?= e(format_date($r['invoice_date'])) ?></td>
                <td><?= e(format_date($r['due_date'])) ?><?php $late = days_past_due($r['due_date']); if ($late > 0 && !in_array($r['payment_status'], ['paid', 'cancelled'], true)): ?><div class="small value-negative"><?= $late ?> day(s) late</div><?php endif; ?></td>
                <td class="amount"><?= e(peso($r['amount'])) ?></td>
                <td class="amount"><?= e(peso($r['amount_paid'])) ?></td>
                <td class="amount"><strong><?= $cx ? '-' : e(peso($r['balance'])) ?></strong></td>
                <td><?= status_badge($r['payment_status']) ?></td>
                <td class="text-end text-nowrap">
                    <?php if (!$cx && $r['payment_status'] !== 'paid' && has_permission('purchase_payments.manage')): ?>
                        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('purchase-payments.php?invoice=' . (int)$r['supplier_invoice_id'])) ?>">Pay</a><?php endif; ?>
                    <?php if (!$cx && (float)$r['amount_paid'] == 0): ?>
                        <button class="btn btn-outline-secondary btn-sm cx-btn" data-bs-toggle="modal" data-bs-target="#cxModal" data-id="<?= (int)$r['supplier_invoice_id'] ?>" data-num="<?= e($r['invoice_number']) ?>">Cancel</button><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<?= render_pager($total, $page, $perPage) ?>

<div class="modal fade" id="invModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_invoice">
        <div class="modal-header"><h5 class="modal-title">Record supplier invoice</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-12"><label class="form-label">Supplier</label>
                <select name="supplier_id" class="form-select" required><option value="">- select supplier -</option>
                    <?php foreach ($suppliers as $s): if (!$s['is_active']) { continue; } ?><option value="<?= (int)$s['supplier_id'] ?>"><?= e($s['supplier_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label">Invoice number</label><input type="text" name="invoice_number" maxlength="40" class="form-control" required></div>
            <div class="col-6"><label class="form-label">Amount (&#8369;)</label><input type="number" name="amount" step="0.01" min="0.01" class="form-control" required></div>
            <div class="col-6"><label class="form-label">Invoice date</label><input type="date" name="invoice_date" id="siDate" class="form-control" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
            <div class="col-6"><label class="form-label">Due date</label><input type="date" name="due_date" id="siDue" class="form-control" value="<?= e(date('Y-m-d', strtotime('+30 days'))) ?>" required></div>
            <div class="col-12"><label class="form-label">Category (optional)</label>
                <select name="category_id" class="form-select"><option value="0">- none -</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['category_id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label">Notes (optional)</label><input type="text" name="notes" maxlength="255" class="form-control"></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save invoice</button></div>
    </form></div>
</div>
<div class="modal fade" id="cxModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="cancel_invoice"><input type="hidden" name="return" value="<?= e($returnQs) ?>"><input type="hidden" name="supplier_invoice_id" id="cxId">
        <div class="modal-header"><h5 class="modal-title">Cancel invoice <span id="cxNum"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><p class="small text-muted">Only unpaid invoices can be cancelled. The record is kept.</p><label class="form-label">Reason</label><textarea name="reason" class="form-control" rows="3" maxlength="255" required></textarea></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep invoice</button><button class="btn btn-danger" type="submit">Cancel invoice</button></div>
    </form></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.cx-btn').forEach(function (b) {
        b.addEventListener('click', function () { document.getElementById('cxId').value = b.dataset.id; document.getElementById('cxNum').textContent = b.dataset.num; });
    });
    var d = document.getElementById('siDate'), due = document.getElementById('siDue');
    d.addEventListener('change', function () { due.min = d.value; });
});
</script>

<?php else: ?>
<div class="app-card"><div class="table-responsive">
    <table class="table table-hover">
        <thead><tr><th>Supplier</th><th>Contact</th><th>Phone / email</th><th>Address</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$suppliers): ?><tr><td colspan="6" class="text-center text-muted py-4">No suppliers yet.</td></tr><?php endif; ?>
        <?php foreach ($suppliers as $s): ?>
            <tr class="<?= $s['is_active'] ? '' : 'row-locked' ?>">
                <td><strong><?= e($s['supplier_name']) ?></strong></td>
                <td><?= e($s['contact_person'] ?? '-') ?></td>
                <td class="small"><?= e($s['phone'] ?? '-') ?><div class="text-muted"><?= e($s['email'] ?? '') ?></div></td>
                <td class="small"><?= e($s['address'] ?? '-') ?></td>
                <td><?= $s['is_active'] ? '<span class="badge badge-soft badge-sage">Active</span>' : '<span class="badge badge-soft badge-khaki">Inactive</span>' ?></td>
                <td class="text-end text-nowrap">
                    <button class="btn btn-outline-primary btn-sm supplier-btn" data-bs-toggle="modal" data-bs-target="#supplierModal" data-mode="edit"
                        data-id="<?= (int)$s['supplier_id'] ?>" data-name="<?= e($s['supplier_name']) ?>" data-contact="<?= e($s['contact_person'] ?? '') ?>"
                        data-phone="<?= e($s['phone'] ?? '') ?>" data-email="<?= e($s['email'] ?? '') ?>" data-address="<?= e($s['address'] ?? '') ?>">Edit</button>
                    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_supplier"><input type="hidden" name="supplier_id" value="<?= (int)$s['supplier_id'] ?>">
                        <button class="btn btn-outline-secondary btn-sm" type="submit" data-confirm="<?= $s['is_active'] ? 'Deactivate this supplier? Existing invoices are kept.' : 'Activate this supplier again?' ?>"><?= $s['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<div class="modal fade" id="supplierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" id="spAction" value="add_supplier"><input type="hidden" name="supplier_id" id="spId" value="0">
        <div class="modal-header"><h5 class="modal-title" id="spTitle">Add supplier</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-12"><label class="form-label">Supplier name</label><input type="text" name="supplier_name" id="spName" maxlength="120" class="form-control" required></div>
            <div class="col-6"><label class="form-label">Contact person</label><input type="text" name="contact_person" id="spContact" maxlength="100" class="form-control"></div>
            <div class="col-6"><label class="form-label">Phone</label><input type="text" name="phone" id="spPhone" maxlength="30" class="form-control"></div>
            <div class="col-12"><label class="form-label">Email</label><input type="email" name="email" id="spEmail" maxlength="120" class="form-control"></div>
            <div class="col-12"><label class="form-label">Address</label><input type="text" name="address" id="spAddress" maxlength="255" class="form-control"></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save supplier</button></div>
    </form></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.supplier-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            var edit = b.dataset.mode === 'edit';
            document.getElementById('spAction').value = edit ? 'edit_supplier' : 'add_supplier';
            document.getElementById('spTitle').textContent = edit ? 'Edit supplier' : 'Add supplier';
            document.getElementById('spId').value = edit ? b.dataset.id : '0';
            document.getElementById('spName').value = edit ? b.dataset.name : '';
            document.getElementById('spContact').value = edit ? b.dataset.contact : '';
            document.getElementById('spPhone').value = edit ? b.dataset.phone : '';
            document.getElementById('spEmail').value = edit ? b.dataset.email : '';
            document.getElementById('spAddress').value = edit ? b.dataset.address : '';
        });
    });
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
