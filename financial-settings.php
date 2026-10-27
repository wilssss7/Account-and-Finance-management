<?php
/**
 * financial-settings.php - Tax and Service Charge Configuration (module 25)
 * Rates are kept as a history. Each invoice stores the rates applied when it was issued, so changing a rate
 * never alters existing invoices. The rate used for a date is the latest ACTIVE rate effective on or before it.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('settings.manage');
require_revision2();

$pdo = db();
$me  = current_user();

/** Editable general settings: key => [label, validator type, max/limits]. */
$editable = [
    'hotel_name'                          => ['Hotel name', 'text', 120],
    'hotel_address'                       => ['Hotel address', 'text', 255],
    'hotel_tin'                           => ['Hotel TIN', 'text', 30],
    'invoice_due_days'                    => ['Default invoice due days', 'int', 120],
    'discount_manager_approval_threshold' => ['Discount manager-approval threshold (PHP)', 'money', 0],
    'billing_rounding_mode'     => ['How partial hours are billed', 'enum', ['ceil_hour' => 'Round up to the next full hour', 'ceil_half_hour' => 'Round up to the next half hour', 'exact_minutes' => 'Charge by the exact minute']],
    'billing_grace_minutes'     => ['Grace minutes (extra minutes not charged)', 'int', 120],
    'billing_minimum_hours'     => ['Minimum billable hours (0 = none)', 'decimal', 72],
    'billing_rules_approved'    => ['Hourly billing rules approved', 'bool', 0],
    'payroll_periods_per_month' => ['Payroll: pay periods per month (0 = not set)', 'int', 4],
    'budget_near_limit_percent' => ['Budget: "Near Budget Limit" starts at (%)', 'int', 99],
];
$groups = [
    'general' => ['hotel_name', 'hotel_address', 'hotel_tin', 'invoice_due_days', 'discount_manager_approval_threshold'],
    'billing' => ['billing_rounding_mode', 'billing_grace_minutes', 'billing_minimum_hours', 'billing_rules_approved'],
    'other'   => ['payroll_periods_per_month', 'budget_near_limit_percent'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        $pdo->beginTransaction();

        if ($action === 'add_rate') {
            $type = (string)($_POST['rate_type'] ?? '');
            $name = clean_text($_POST['rate_name'] ?? '', 60);
            $pct  = parse_money($_POST['rate_percent'] ?? '', true);
            $eff  = (string)($_POST['effective_from'] ?? '');
            if (!in_array($type, ['tax', 'service_charge'], true)) { throw new RuntimeException('Choose tax or service charge.'); }
            if ($name === '') { throw new RuntimeException('Enter a name for the rate (for example VAT).'); }
            if ($pct === null || $pct > 100) { throw new RuntimeException('The rate must be a percentage from 0 to 100.'); }
            if (!valid_date($eff)) { throw new RuntimeException('Enter a valid effective date.'); }
            if (db_value('SELECT COUNT(*) FROM tax_service_rates WHERE rate_type = ? AND effective_from = ? AND is_active = 1', [$type, $eff]) > 0) {
                throw new RuntimeException('An active ' . str_replace('_', ' ', $type) . ' rate already starts on that date. Deactivate it first or pick another date.');
            }
            $pdo->prepare('INSERT INTO tax_service_rates (rate_type, rate_name, rate_percent, effective_from, is_active, created_by) VALUES (?, ?, ?, ?, 1, ?)')
                ->execute([$type, $name, $pct, $eff, (int)$me['id']]);
            audit_log('rate_added', 'tax_service_rates', (int)$pdo->lastInsertId(), null, ['type' => $type, 'name' => $name, 'percent' => $pct, 'effective_from' => $eff],
                'Existing invoices keep the rates they were issued with.');
            $pdo->commit();
            set_flash('success', "$name ($pct%) takes effect on " . format_date($eff) . '. Existing invoices are not changed.');

        } elseif ($action === 'toggle_rate') {
            $r = db_one('SELECT * FROM tax_service_rates WHERE rate_id = ? FOR UPDATE', [(int)($_POST['rate_id'] ?? 0)]);
            if (!$r) { throw new RuntimeException('Rate not found.'); }
            if ($r['is_active']) {
                $others = db_value('SELECT COUNT(*) FROM tax_service_rates WHERE rate_type = ? AND is_active = 1 AND rate_id <> ?', [$r['rate_type'], (int)$r['rate_id']]);
                if ($others == 0) { throw new RuntimeException('At least one active ' . str_replace('_', ' ', $r['rate_type']) . ' rate must remain, or invoices would be issued with 0%.'); }
            }
            $new = $r['is_active'] ? 0 : 1;
            $pdo->prepare('UPDATE tax_service_rates SET is_active = ? WHERE rate_id = ?')->execute([$new, (int)$r['rate_id']]);
            audit_log($new ? 'rate_activated' : 'rate_deactivated', 'tax_service_rates', $r['rate_id'], ['is_active' => $r['is_active']], ['is_active' => $new]);
            $pdo->commit();
            set_flash('success', 'The rate was ' . ($new ? 'activated.' : 'deactivated. Its history is kept.'));

        } elseif ($action === 'save_settings') {
            $changes = [];
            $group = (string)($_POST['group'] ?? 'general');
            foreach ($groups[$group] ?? [] as $key) {
                [$label, $kind, $limit] = $editable[$key];
                $raw = $_POST[$key] ?? '';
                if ($kind === 'text') {
                    $val = clean_text($raw, $limit);
                    if ($val === '') { throw new RuntimeException("$label cannot be empty."); }
                } elseif ($kind === 'int') {
                    if (!preg_match('/^\d{1,3}$/', trim((string)$raw)) || (int)$raw > $limit) { throw new RuntimeException("$label must be a whole number from 0 to $limit."); }
                    $val = (string)(int)$raw;
                    if ($key === 'budget_near_limit_percent' && (int)$val < 50) { throw new RuntimeException("$label must be between 50 and 99."); }
                } elseif ($kind === 'enum') {
                    $val = (string)$raw;
                    if (!isset($limit[$val])) { throw new RuntimeException("Choose a valid option for: $label."); }
                } elseif ($kind === 'bool') {
                    $val = isset($_POST[$key]) ? '1' : '0';
                } elseif ($kind === 'decimal') {
                    $m = parse_money($raw, true);
                    if ($m === null || $m > $limit) { throw new RuntimeException("$label must be an amount from 0 to $limit."); }
                    $val = number_format($m, 2, '.', '');
                } else {
                    $m = parse_money($raw, true);
                    if ($m === null) { throw new RuntimeException("$label must be a valid amount."); }
                    $val = number_format($m, 2, '.', '');
                }
                $old = get_setting($key);
                if ($old !== $val) {
                    $pdo->prepare('UPDATE financial_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?')->execute([$val, (int)$me['id'], $key]);
                    $changes[$key] = ['old' => $old, 'new' => $val];
                }
            }
            if ($changes) { audit_log('settings_changed', 'financial_settings', null, null, $changes); }
            $pdo->commit();
            set_flash($changes ? 'success' : 'info', $changes ? count($changes) . ' setting(s) saved.' : 'No changes to save.');
        } elseif ($action === 'save_room_rates') {
            $posted = is_array($_POST['hourly_rate'] ?? null) ? $_POST['hourly_rate'] : [];
            $changes = [];
            foreach (db_all('SELECT room_id, room_number, hourly_rate FROM rooms FOR UPDATE') as $rm) {
                if (!array_key_exists($rm['room_id'], $posted)) { continue; }
                $raw = trim((string)$posted[$rm['room_id']]);
                if ($raw === '') { $new = null; }
                else {
                    $new = parse_money($raw);
                    if ($new === null) { throw new RuntimeException('Room ' . $rm['room_number'] . ': enter a valid hourly rate, or leave it empty.'); }
                }
                $old = $rm['hourly_rate'] === null ? null : (float)$rm['hourly_rate'];
                if ($old !== $new) {
                    $pdo->prepare('UPDATE rooms SET hourly_rate = ? WHERE room_id = ?')->execute([$new, (int)$rm['room_id']]);
                    $changes['Room ' . $rm['room_number']] = ['old' => $old, 'new' => $new];
                }
            }
            if ($changes) { audit_log('room_rates_changed', 'rooms', null, null, $changes, 'Guest bills already created keep the rate saved at check-in.'); }
            $pdo->commit();
            set_flash($changes ? 'success' : 'info', $changes ? count($changes) . ' room rate(s) saved. Bills already created keep the rate saved at check-in.' : 'No changes to save.');

        } elseif ($action === 'add_price') {
            $cat  = (string)($_POST['category'] ?? '');
            $name = clean_text($_POST['item_name'] ?? '', 120);
            $price = parse_money($_POST['unit_price'] ?? '', true);
            if (!isset(CHARGE_CATEGORIES[$cat]) || $cat === 'room') { throw new RuntimeException('Choose a service category. Rooms are charged by the hour.'); }
            if (mb_strlen($name) < 2) { throw new RuntimeException('Enter the item name.'); }
            if ($price === null) { throw new RuntimeException('Enter a valid price.'); }
            if (db_value('SELECT COUNT(*) FROM service_prices WHERE category = ? AND LOWER(item_name) = LOWER(?)', [$cat, $name]) > 0) { throw new RuntimeException('That item is already in the price list.'); }
            $pdo->prepare('INSERT INTO service_prices (category, item_name, unit_price, is_taxable, created_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$cat, $name, $price, isset($_POST['is_taxable']) ? 1 : 0, (int)$me['id']]);
            audit_log('service_price_added', 'service_prices', (int)$pdo->lastInsertId(), null, ['category' => $cat, 'item' => $name, 'price' => $price]);
            $pdo->commit();
            set_flash('success', "\"$name\" was added to the price list.");

        } elseif ($action === 'update_price') {
            $row = db_one('SELECT * FROM service_prices WHERE price_id = ? FOR UPDATE', [(int)($_POST['price_id'] ?? 0)]);
            $price = parse_money($_POST['unit_price'] ?? '', true);
            if (!$row) { throw new RuntimeException('Price not found.'); }
            if ($price === null) { throw new RuntimeException('Enter a valid price.'); }
            $taxable = isset($_POST['is_taxable']) ? 1 : 0;
            $pdo->prepare('UPDATE service_prices SET unit_price = ?, is_taxable = ? WHERE price_id = ?')->execute([$price, $taxable, (int)$row['price_id']]);
            audit_log('service_price_changed', 'service_prices', $row['price_id'], ['price' => $row['unit_price'], 'taxable' => $row['is_taxable']], ['price' => $price, 'taxable' => $taxable], 'Charges already on guest bills keep their price.');
            $pdo->commit();
            set_flash('success', 'The price was updated. Charges already on guest bills keep the price they were added with.');

        } elseif ($action === 'toggle_price') {
            $row = db_one('SELECT * FROM service_prices WHERE price_id = ? FOR UPDATE', [(int)($_POST['price_id'] ?? 0)]);
            if (!$row) { throw new RuntimeException('Price not found.'); }
            $new = $row['is_active'] ? 0 : 1;
            $pdo->prepare('UPDATE service_prices SET is_active = ? WHERE price_id = ?')->execute([$new, (int)$row['price_id']]);
            audit_log($new ? 'service_price_activated' : 'service_price_deactivated', 'service_prices', $row['price_id'], ['is_active' => $row['is_active']], ['is_active' => $new]);
            $pdo->commit();
            set_flash('success', 'The item was ' . ($new ? 'activated.' : 'removed from the price list (its history is kept).'));
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The action could not be completed. Please try again.');
    }
    redirect('financial-settings.php');
}

$today = date('Y-m-d');
$rates = db_all('SELECT r.*, u.full_name AS creator FROM tax_service_rates r LEFT JOIN users u ON u.user_id = r.created_by
                 ORDER BY r.rate_type, r.effective_from DESC, r.rate_id DESC');
$currentIds = [];
foreach (['tax', 'service_charge'] as $t) {
    $currentIds[$t] = db_value('SELECT rate_id FROM tax_service_rates WHERE rate_type = ? AND is_active = 1 AND effective_from <= ? ORDER BY effective_from DESC, rate_id DESC LIMIT 1', [$t, $today]);
}
$curTax = active_rate('tax', $today);
$curSvc = active_rate('service_charge', $today);

$rooms = db_all('SELECT * FROM rooms ORDER BY room_number');
$prices = db_all('SELECT * FROM service_prices ORDER BY is_active DESC, category, item_name');

$pageTitle = 'Tax and Charges Settings';
include __DIR__ . '/includes/header.php';
?>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="app-card stat-card"><div class="stat-label">Tax rate today</div><div class="stat-value"><?= e(rtrim(rtrim(number_format($curTax, 2), '0'), '.')) ?>%</div><div class="stat-note">Applied to taxable charges plus service charge</div></div></div>
    <div class="col-md-4"><div class="app-card stat-card"><div class="stat-label">Service charge today</div><div class="stat-value"><?= e(rtrim(rtrim(number_format($curSvc, 2), '0'), '.')) ?>%</div><div class="stat-note">Applied to taxable charges</div></div></div>
    <div class="col-md-4"><div class="app-card stat-card"><div class="stat-label">Calculation rule</div><div class="small mt-1"><?= e(get_setting('tax_computation_rule')) ?></div><div class="stat-note mt-1">Invoice total = subtotal + service + tax - discounts &plusmn; adjustments</div></div></div>
</div>

<div class="app-card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title-serif">Rate history</h3>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#rateModal"><i class="bi bi-plus-lg me-1"></i>Add new rate</button></div>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Type</th><th>Name</th><th class="amount">Rate</th><th>Effective from</th><th>Status</th><th>Created by</th><th></th></tr></thead><tbody>
        <?php foreach ($rates as $r): $isCur = (int)$currentIds[$r['rate_type']] === (int)$r['rate_id']; ?>
            <tr>
                <td><?= $r['rate_type'] === 'tax' ? 'Tax' : 'Service charge' ?></td><td><strong><?= e($r['rate_name']) ?></strong></td>
                <td class="amount"><?= e(rtrim(rtrim(number_format((float)$r['rate_percent'], 2), '0'), '.')) ?>%</td>
                <td><?= e(format_date($r['effective_from'])) ?></td>
                <td><?php if ($isCur): ?><span class="badge badge-soft badge-sage">In use today</span><?php elseif ($r['is_active']): ?><span class="badge badge-soft badge-khaki">Active</span><?php else: ?><span class="badge badge-soft badge-terracotta">Inactive</span><?php endif; ?></td>
                <td class="small"><?= e($r['creator'] ?? '-') ?></td>
                <td class="text-end"><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_rate"><input type="hidden" name="rate_id" value="<?= (int)$r['rate_id'] ?>">
                    <button class="btn btn-outline-secondary btn-sm" type="submit" data-confirm="<?= $r['is_active'] ? 'Deactivate this rate? Invoices already issued are not changed.' : 'Activate this rate again?' ?>"><?= $r['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <div class="card-body pt-2 small text-muted">Rates are never deleted. Each invoice stores the rates it was issued with, so a new rate only affects invoices issued on or after its effective date.</div>
</div>

<?php
/** Prints one settings field. */
$field = static function (string $key) use ($editable): void {
    [$label, $kind, $limit] = $editable[$key];
    $val = get_setting($key); $id = 's_' . $key;
    echo '<div class="' . ($key === 'hotel_address' ? 'col-md-8' : 'col-md-6 col-lg-4') . '"><label class="form-label" for="' . e($id) . '">' . e($label) . '</label>';
    if ($kind === 'enum') {
        echo '<select class="form-select" id="' . e($id) . '" name="' . e($key) . '">';
        foreach ($limit as $k => $t) { echo '<option value="' . e($k) . '"' . ($val === $k ? ' selected' : '') . '>' . e($t) . '</option>'; }
        echo '</select>';
    } elseif ($kind === 'bool') {
        echo '<div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="' . e($key) . '" id="' . e($id) . '" value="1"' . ($val === '1' ? ' checked' : '') . '><label class="form-check-label" for="' . e($id) . '">Yes, approved</label></div>';
    } else {
        $attr = $kind === 'int' ? 'type="number" min="0" max="' . (int)$limit . '"' : ($kind === 'text' ? 'type="text" maxlength="' . (int)$limit . '"' : 'type="number" step="0.01" min="0"');
        echo '<input class="form-control" id="' . e($id) . '" name="' . e($key) . '" value="' . e($val) . '" ' . $attr . ' required>';
    }
    echo '</div>';
};
?>
<div class="app-card mb-3">
    <div class="card-header"><h3 class="card-title-serif">General financial settings</h3></div>
    <div class="card-body"><form method="post" class="row g-3">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_settings"><input type="hidden" name="group" value="general">
        <?php foreach ($groups['general'] as $k) { $field($k); } ?>
        <div class="col-12"><button class="btn btn-primary" type="submit">Save settings</button></div>
    </form></div>
</div>

<div class="app-card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title-serif">Hourly billing rules</h3>
        <?= get_setting('billing_rules_approved') === '1' ? '<span class="badge badge-soft badge-sage">Approved</span>' : '<span class="badge badge-soft badge-terracotta">Needs approval</span>' ?></div>
    <div class="card-body">
        <?php if (get_setting('billing_rules_approved') !== '1'): ?><div class="alert alert-warning small">These values are <strong>proposed, not approved</strong>. They are only a starting point so billing can work. Confirm them with your professor, change them if needed, then tick <em>approved</em>. Guest bills show a warning until then.</div><?php endif; ?>
        <form method="post" class="row g-3">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_settings"><input type="hidden" name="group" value="billing">
            <?php foreach ($groups['billing'] as $k) { $field($k); } ?>
            <div class="col-12 small text-muted">A stay is always billed at least one unit. Early or late checkout is billed on the real time. Rates are entered <strong>before tax</strong>; service charge and tax are added once when the bill or invoice is calculated, so tax is never counted twice.</div>
            <div class="col-12"><button class="btn btn-primary" type="submit">Save billing rules</button></div>
        </form>
    </div>
</div>

<div class="app-card mb-3">
    <div class="card-header"><h3 class="card-title-serif">Room hourly rates</h3></div>
    <div class="card-body">
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_room_rates">
            <div class="table-responsive"><table class="table table-sm align-middle">
                <thead><tr><th>Room</th><th>Type</th><th class="amount">Price per night (reference)</th><th style="min-width:180px">Hourly rate (&#8369;, before tax)</th></tr></thead>
                <tbody>
                <?php foreach ($rooms as $rm): ?>
                    <tr><td><strong><?= e($rm['room_number']) ?></strong></td><td><?= e($rm['room_type']) ?></td><td class="amount"><?= e(peso($rm['rate_per_night'])) ?></td>
                        <td><input type="number" step="0.01" min="0.01" class="form-control form-control-sm" name="hourly_rate[<?= (int)$rm['room_id'] ?>]" value="<?= $rm['hourly_rate'] === null ? '' : e(number_format((float)$rm['hourly_rate'], 2, '.', '')) ?>" placeholder="Not set"></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="small text-muted mb-2">Rooms with no hourly rate cannot be billed. The system does not guess a rate from the nightly price. Bills already created keep the rate saved at check-in.</div>
            <button class="btn btn-primary" type="submit">Save hourly rates</button>
        </form>
    </div>
</div>

<div class="app-card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center"><h3 class="card-title-serif">Service price list</h3>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#priceModal"><i class="bi bi-plus-lg me-1"></i>Add item</button></div>
    <div class="table-responsive"><table class="table table-hover align-middle">
        <thead><tr><th>Category</th><th>Item</th><th style="min-width:230px">Price (&#8369;, before tax)</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$prices): ?><tr><td colspan="5" class="text-center text-muted py-4">The price list is empty. Staff cannot add restaurant, minibar, laundry or spa charges until items and prices are added here.</td></tr><?php endif; ?>
        <?php foreach ($prices as $pr): ?>
            <tr class="<?= $pr['is_active'] ? '' : 'row-locked' ?>">
                <td><?= e(CHARGE_CATEGORIES[$pr['category']] ?? $pr['category']) ?></td><td><strong><?= e($pr['item_name']) ?></strong></td>
                <td><form method="post" class="d-flex gap-2 align-items-center"><?= csrf_field() ?><input type="hidden" name="action" value="update_price"><input type="hidden" name="price_id" value="<?= (int)$pr['price_id'] ?>">
                    <input type="number" step="0.01" min="0" name="unit_price" class="form-control form-control-sm" style="max-width:120px" value="<?= e(number_format((float)$pr['unit_price'], 2, '.', '')) ?>" required>
                    <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="is_taxable" id="tx<?= (int)$pr['price_id'] ?>" <?= $pr['is_taxable'] ? 'checked' : '' ?>><label class="form-check-label small" for="tx<?= (int)$pr['price_id'] ?>">Taxable</label></div>
                    <button class="btn btn-outline-primary btn-sm" type="submit">Save</button></form></td>
                <td><?= $pr['is_active'] ? '<span class="badge badge-soft badge-sage">Active</span>' : '<span class="badge badge-soft badge-khaki">Removed</span>' ?></td>
                <td class="text-end"><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_price"><input type="hidden" name="price_id" value="<?= (int)$pr['price_id'] ?>">
                    <button class="btn btn-outline-secondary btn-sm" type="submit" data-confirm="<?= $pr['is_active'] ? 'Remove this item from the price list? Charges already billed are kept.' : 'Activate this item again?' ?>"><?= $pr['is_active'] ? 'Remove' : 'Activate' ?></button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="app-card mb-3">
    <div class="card-header"><h3 class="card-title-serif">Payroll and budget settings</h3></div>
    <div class="card-body"><form method="post" class="row g-3">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_settings"><input type="hidden" name="group" value="other">
        <?php foreach ($groups['other'] as $k) { $field($k); } ?>
        <div class="col-12 small text-muted">Monthly salaries are divided by the number of pay periods per month. It stays at 0 (not set) until you enter the real value, and monthly payroll cannot be generated before that.</div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Save settings</button></div>
    </form></div>
</div>

<div class="modal fade" id="priceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_price">
        <div class="modal-header"><h5 class="modal-title">Add price-list item</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-6"><label class="form-label">Category</label><select name="category" class="form-select" required>
                <?php foreach (CHARGE_CATEGORIES as $k => $v): if ($k === 'room') { continue; } ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label">Price (&#8369;, before tax)</label><input type="number" name="unit_price" step="0.01" min="0" class="form-control" required></div>
            <div class="col-12"><label class="form-label">Item name</label><input type="text" name="item_name" maxlength="120" class="form-control" required></div>
            <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_taxable" id="newTax" checked><label class="form-check-label" for="newTax">Subject to tax and service charge</label></div></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Add item</button></div>
    </form></div>
</div>

<div class="app-card">
    <div class="card-header"><h3 class="card-title-serif">Reporting rules in use</h3></div>
    <div class="card-body small">
        <p class="mb-2"><strong>Revenue:</strong> <?= e(get_setting('revenue_recognition_rule')) ?></p>
        <p class="mb-0"><strong>Expenses:</strong> <?= e(get_setting('expense_recognition_rule')) ?></p>
    </div>
</div>

<div class="modal fade" id="rateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_rate">
        <div class="modal-header"><h5 class="modal-title">Add new rate</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-6"><label class="form-label">Type</label><select name="rate_type" class="form-select" required><option value="tax">Tax</option><option value="service_charge">Service charge</option></select></div>
            <div class="col-6"><label class="form-label">Name</label><input type="text" name="rate_name" maxlength="60" class="form-control" placeholder="e.g. VAT" required></div>
            <div class="col-6"><label class="form-label">Rate (%)</label><input type="number" name="rate_percent" step="0.01" min="0" max="100" class="form-control" required></div>
            <div class="col-6"><label class="form-label">Effective from</label><input type="date" name="effective_from" class="form-control" value="<?= e($today) ?>" required></div>
        </div><div class="form-text mt-2">The newest active rate that has started is the one used. Existing invoices keep their own rates.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Add rate</button></div>
    </form></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
