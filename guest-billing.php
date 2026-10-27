<?php
/**
 * guest-billing.php - Guest Billing Management (module 2)
 * List/search/filter folios, open a new bill, view a folio and add or remove charges.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('billing.manage');
require_revision2();

$pdo = db();
$me  = current_user();

/* ============================ POST actions ============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create_folio') {
            $reservationId = (int)($_POST['reservation_id'] ?? 0);
            $guestId       = (int)($_POST['guest_id'] ?? 0);
            $roomId        = (int)($_POST['room_id'] ?? 0);
            $notes         = clean_text($_POST['notes'] ?? '', 255);
            $checkin  = parse_datetime_local($_POST['checkin_at'] ?? '');
            $expected = parse_datetime_local($_POST['expected_checkout_at'] ?? '');
            if ($checkin === null || $expected === null) { throw new RuntimeException('Enter the check-in and the expected checkout date and time.'); }
            if ($expected <= $checkin) { throw new RuntimeException('The expected checkout must be later than the check-in.'); }
            $pdo->beginTransaction();
            if ($reservationId > 0) {
                $r = db_one('SELECT reservation_id, guest_id, room_id, status FROM reservations WHERE reservation_id = ? FOR UPDATE', [$reservationId]);
                if (!$r || $r['status'] === 'cancelled') {
                    throw new RuntimeException('Choose a valid, non-cancelled reservation.');
                }
                if (db_value('SELECT COUNT(*) FROM guest_folios WHERE reservation_id = ?', [$reservationId]) > 0) {
                    throw new RuntimeException('This reservation already has a folio.');
                }
                $guestId = (int)$r['guest_id'];
                $roomId  = (int)$r['room_id'];
            } else {
                if ($guestId <= 0 || db_value('SELECT COUNT(*) FROM guests WHERE guest_id = ?', [$guestId]) == 0) {
                    throw new RuntimeException('Choose a reservation or a guest for the new bill.');
                }
                if ($roomId <= 0) { throw new RuntimeException('Choose the room for a walk-in guest.'); }
                $reservationId = 0;
            }
            $room = db_one('SELECT room_id, room_number, hourly_rate FROM rooms WHERE room_id = ?', [$roomId]);
            if (!$room) { throw new RuntimeException('Choose a valid room.'); }
            if ($room['hourly_rate'] === null || (float)$room['hourly_rate'] <= 0) {
                throw new RuntimeException('Room ' . $room['room_number'] . ' has no hourly rate yet. An administrator sets it under Administration > Tax and Charges.');
            }
            $number = next_doc_number('FOL');
            $pdo->prepare("INSERT INTO guest_folios (folio_number, reservation_id, guest_id, room_id, checkin_at, expected_checkout_at, hourly_rate_applied, status, notes)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?)")
                ->execute([$number, $reservationId ?: null, $guestId, $roomId, $checkin, $expected, $room['hourly_rate'], $notes !== '' ? $notes : null]);
            $folioId = (int)$pdo->lastInsertId();
            $calc = refresh_hourly_charge($folioId, $expected);
            audit_log('folio_created', 'guest_folios', $folioId, null, ['folio_number' => $number, 'guest_id' => $guestId, 'room' => $room['room_number'],
                'check_in' => $checkin, 'expected_checkout' => $expected, 'hourly_rate' => $room['hourly_rate'], 'billed_hours' => $calc['hours'], 'room_charge' => $calc['amount']]);
            $pdo->commit();
            set_flash('success', "Guest bill $number was created. The room charge was calculated automatically: " . $calc['hours'] . ' hour(s) = ' . peso($calc['amount']) . ' before tax.');
            redirect('guest-billing.php?folio=' . $folioId);
        }

        if ($action === 'set_stay') {                         // for bills created before hourly billing (no stay times yet)
            $folioId  = (int)($_POST['folio_id'] ?? 0);
            $checkin  = parse_datetime_local($_POST['checkin_at'] ?? '');
            $expected = parse_datetime_local($_POST['expected_checkout_at'] ?? '');
            if ($checkin === null || $expected === null || $expected <= $checkin) { throw new RuntimeException('Enter a check-in and a later expected checkout date and time.'); }
            $pdo->beginTransaction();
            $f = db_one('SELECT f.*, r.room_id AS res_room FROM guest_folios f LEFT JOIN reservations r ON r.reservation_id = f.reservation_id WHERE f.folio_id = ? FOR UPDATE', [$folioId]);
            if (!$f) { throw new RuntimeException('Folio not found.'); }
            if ($f['checkin_at'] !== null) { throw new RuntimeException('This bill already has stay times. Use "Change expected checkout" instead.'); }
            $roomId = (int)($f['res_room'] ?: ($_POST['room_id'] ?? 0));
            $room = db_one('SELECT room_id, room_number, hourly_rate FROM rooms WHERE room_id = ?', [$roomId]);
            if (!$room) { throw new RuntimeException('Choose the room.'); }
            if ($room['hourly_rate'] === null || (float)$room['hourly_rate'] <= 0) { throw new RuntimeException('Room ' . $room['room_number'] . ' has no hourly rate yet. An administrator sets it under Administration > Tax and Charges.'); }
            $pdo->prepare('UPDATE guest_folios SET room_id = ?, checkin_at = ?, expected_checkout_at = ?, hourly_rate_applied = ? WHERE folio_id = ?')
                ->execute([$roomId, $checkin, $expected, $room['hourly_rate'], $folioId]);
            $calc = refresh_hourly_charge($folioId, $expected);
            audit_log('folio_stay_set', 'guest_folios', $folioId, null, ['room' => $room['room_number'], 'check_in' => $checkin, 'expected_checkout' => $expected, 'billed_hours' => $calc['hours'], 'room_charge' => $calc['amount']]);
            $pdo->commit();
            set_flash('success', 'Stay saved: ' . $calc['hours'] . ' hour(s) = ' . peso($calc['amount']) . ' before tax. If this bill already had a manual room charge, remove that old charge so the room is not billed twice.');
            redirect('guest-billing.php?folio=' . $folioId);
        }

        if ($action === 'update_checkout') {                  // change of the expected checkout time (guest still in the room)
            $folioId  = (int)($_POST['folio_id'] ?? 0);
            $expected = parse_datetime_local($_POST['expected_checkout_at'] ?? '');
            if ($expected === null) { throw new RuntimeException('Enter a valid expected checkout date and time.'); }
            $pdo->beginTransaction();
            $f = db_one('SELECT * FROM guest_folios WHERE folio_id = ? FOR UPDATE', [$folioId]);
            if (!$f || $f['checkin_at'] === null) { throw new RuntimeException('This bill has no stay times yet.'); }
            if ($f['actual_checkout_at'] !== null) { throw new RuntimeException('The guest already checked out. The bill uses the actual checkout time.'); }
            if ($expected <= $f['checkin_at']) { throw new RuntimeException('The expected checkout must be later than the check-in.'); }
            $pdo->prepare('UPDATE guest_folios SET expected_checkout_at = ? WHERE folio_id = ?')->execute([$expected, $folioId]);
            $calc = refresh_hourly_charge($folioId, $expected);
            audit_log('expected_checkout_changed', 'guest_folios', $folioId, ['expected_checkout' => $f['expected_checkout_at']], ['expected_checkout' => $expected, 'billed_hours' => $calc['hours'], 'room_charge' => $calc['amount']], clean_text($_POST['reason'] ?? '', 255) ?: null);
            $pdo->commit();
            set_flash('success', 'Expected checkout updated. Room charge: ' . $calc['hours'] . ' hour(s) = ' . peso($calc['amount']) . ' before tax (verify it again).');
            redirect('guest-billing.php?folio=' . $folioId);
        }

        if ($action === 'check_out') {                        // actual checkout: early or late checkout is billed on the real time
            $folioId = (int)($_POST['folio_id'] ?? 0);
            $actual  = parse_datetime_local($_POST['actual_checkout_at'] ?? '');
            if ($actual === null) { throw new RuntimeException('Enter a valid checkout date and time.'); }
            if ($actual > date('Y-m-d H:i:s', time() + 300)) { throw new RuntimeException('The checkout time cannot be in the future.'); }
            $pdo->beginTransaction();
            $f = db_one('SELECT * FROM guest_folios WHERE folio_id = ? FOR UPDATE', [$folioId]);
            if (!$f || $f['checkin_at'] === null) { throw new RuntimeException('This bill has no stay times yet.'); }
            if ($f['actual_checkout_at'] !== null) { throw new RuntimeException('The guest is already checked out.'); }
            if ($actual <= $f['checkin_at']) { throw new RuntimeException('The checkout must be later than the check-in.'); }
            $pdo->prepare('UPDATE guest_folios SET actual_checkout_at = ? WHERE folio_id = ?')->execute([$actual, $folioId]);
            $calc = refresh_hourly_charge($folioId, $actual);
            $when = $actual < $f['expected_checkout_at'] ? 'early' : ($actual > $f['expected_checkout_at'] ? 'late' : 'on time');
            audit_log('guest_checked_out', 'guest_folios', $folioId, ['expected_checkout' => $f['expected_checkout_at']], ['actual_checkout' => $actual, 'checkout' => $when, 'billed_hours' => $calc['hours'], 'room_charge' => $calc['amount']]);
            $pdo->commit();
            set_flash('success', "Guest checked out ($when). Room charge: " . $calc['hours'] . ' hour(s) = ' . peso($calc['amount']) . ' before tax. Verify the charge, then generate the invoice.');
            redirect('guest-billing.php?folio=' . $folioId);
        }

        if ($action === 'add_charge') {
            $folioId  = (int)($_POST['folio_id'] ?? 0);
            $folio    = db_one('SELECT folio_id, status FROM guest_folios WHERE folio_id = ?', [$folioId]);
            if (!$folio) { throw new RuntimeException('Folio not found.'); }
            if (!in_array($folio['status'], ['open', 'verified'], true)) {
                throw new RuntimeException('Charges cannot be added to an invoiced or closed folio.');
            }
            $item = db_one('SELECT * FROM service_prices WHERE price_id = ? AND is_active = 1', [(int)($_POST['price_id'] ?? 0)]);
            $date = (string)($_POST['charge_date'] ?? '');
            $qty  = parse_money($_POST['quantity'] ?? '');
            if (!$item) { throw new RuntimeException('Choose a service from the price list. Prices are not typed in manually.'); }
            if (!valid_date($date)) { throw new RuntimeException('Enter a valid charge date.'); }
            if ($qty === null) { throw new RuntimeException('Enter a valid quantity greater than zero.'); }

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO folio_charges (folio_id, charge_date, category, description, quantity, unit_price, is_taxable, charge_source)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 'price_list')")->execute([$folioId, $date, $item['category'], $item['item_name'], $qty, $item['unit_price'], (int)$item['is_taxable']]);
            $chargeId = (int)$pdo->lastInsertId();
            if ($folio['status'] === 'verified') {
                $pdo->prepare("UPDATE guest_folios SET status = 'open' WHERE folio_id = ?")->execute([$folioId]);
            }
            audit_log('folio_charge_added', 'folio_charges', $chargeId, null,
                ['folio_id' => $folioId, 'item' => $item['item_name'], 'price_from_list' => $item['unit_price'], 'quantity' => $qty, 'amount' => round($qty * (float)$item['unit_price'], 2)]);
            $pdo->commit();
            set_flash('success', 'Charge added at the price-list price. It must be verified before the folio can be invoiced.');
            redirect('guest-billing.php?folio=' . $folioId);
        }

        if ($action === 'remove_charge') {
            $chargeId = (int)($_POST['charge_id'] ?? 0);
            $pdo->beginTransaction();
            $c = db_one('SELECT c.*, f.status AS folio_status FROM folio_charges c JOIN guest_folios f ON f.folio_id = c.folio_id
                         WHERE c.charge_id = ? FOR UPDATE', [$chargeId]);
            if (!$c) { throw new RuntimeException('Charge not found.'); }
            if (($c['charge_source'] ?? 'manual') === 'hourly') {
                throw new RuntimeException('The room charge is calculated automatically from the stay. Change the expected checkout or check out the guest instead.');
            }
            $used = db_value("SELECT COUNT(*) FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
                              WHERE ii.folio_charge_id = ? AND i.status <> 'cancelled'", [$chargeId]);
            if ($used > 0 || !in_array($c['folio_status'], ['open', 'verified'], true)) {
                throw new RuntimeException('This charge is already on an invoice and cannot be removed.');
            }
            $pdo->prepare('DELETE FROM folio_charges WHERE charge_id = ?')->execute([$chargeId]);
            audit_log('folio_charge_removed', 'folio_charges', $chargeId,
                ['description' => $c['description'], 'amount' => $c['amount'], 'status' => $c['verification_status']], null,
                clean_text($_POST['reason'] ?? 'Removed by billing staff', 255));
            $open = db_value("SELECT COUNT(*) FROM folio_charges WHERE folio_id = ? AND verification_status <> 'verified'", [(int)$c['folio_id']]);
            $total = db_value('SELECT COUNT(*) FROM folio_charges WHERE folio_id = ?', [(int)$c['folio_id']]);
            if ($open == 0 && $total > 0 && $c['folio_status'] === 'open') {
                $pdo->prepare("UPDATE guest_folios SET status = 'verified' WHERE folio_id = ?")->execute([(int)$c['folio_id']]);
            }
            $pdo->commit();
            set_flash('success', 'Charge removed.');
            redirect('guest-billing.php?folio=' . (int)$c['folio_id']);
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $msg = $ex instanceof RuntimeException ? $ex->getMessage() : 'The action could not be completed. Please try again.';
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $msg);
        $back = (int)($_POST['folio_id'] ?? 0);
        redirect($back > 0 ? 'guest-billing.php?folio=' . $back : 'guest-billing.php');
    }
}

/* ============================ Folio detail ============================ */
$folioId = (int)($_GET['folio'] ?? 0);
if ($folioId > 0) {
    $folio = db_one(
        'SELECT f.*, g.guest_code, g.full_name, g.guest_type, g.company_name, g.email, g.phone,
                r.reservation_code, r.check_in, r.check_out, rm.room_number, rm.room_type
         FROM guest_folios f JOIN guests g ON g.guest_id = f.guest_id
         LEFT JOIN reservations r ON r.reservation_id = f.reservation_id
         LEFT JOIN rooms rm ON rm.room_id = COALESCE(f.room_id, r.room_id)
         WHERE f.folio_id = ?', [$folioId]);
    if (!$folio) {
        set_flash('danger', 'Folio not found.');
        redirect('guest-billing.php');
    }
    $charges = db_all('SELECT c.*, u.full_name AS verifier FROM folio_charges c
                       LEFT JOIN users u ON u.user_id = c.verified_by
                       WHERE c.folio_id = ? ORDER BY c.charge_date, c.charge_id', [$folioId]);
    $invoice = db_one("SELECT b.*, i.status AS inv_status, i.subtotal, i.service_rate_applied, i.tax_rate_applied, i.tax_amount, i.service_charge_amount, i.discount_amount
                       FROM invoices i JOIN v_invoice_balances b ON b.invoice_id = i.invoice_id
                       WHERE i.folio_id = ? AND i.status <> 'cancelled' LIMIT 1", [$folioId]);
    $usedCharges = array_column(db_all("SELECT ii.folio_charge_id FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
                                        WHERE i.folio_id = ? AND i.status <> 'cancelled'", [$folioId]), 'folio_charge_id');
    $sum = ['all' => 0.0, 'verified' => 0.0, 'pending' => 0.0, 'flagged' => 0.0];
    foreach ($charges as $c) {
        $sum['all'] += (float)$c['amount'];
        $sum[$c['verification_status']] += (float)$c['amount'];
    }
    $canInvoice = !$invoice && $charges && $sum['pending'] == 0 && $sum['flagged'] == 0
        && in_array($folio['status'], ['open', 'verified'], true);
    $editable = in_array($folio['status'], ['open', 'verified'], true);

    $bill = folio_bill_summary($folioId);
    $rules = billing_rules();
    $hasStay = $folio['checkin_at'] !== null;
    $hourlyCharge = null;
    foreach ($charges as $c) { if (($c['charge_source'] ?? 'manual') === 'hourly') { $hourlyCharge = $c; } }
    $stayOpen = $hasStay && $folio['actual_checkout_at'] === null && $editable;
    $priceList = db_all('SELECT * FROM service_prices WHERE is_active = 1 ORDER BY category, item_name');
    $roomsAll = db_all('SELECT room_id, room_number, room_type, hourly_rate FROM rooms ORDER BY room_number');
    $nowLocal = date('Y-m-d\TH:i');

    $pageTitle = 'Folio ' . $folio['folio_number'];
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="mb-3"><a href="<?= e(url('guest-billing.php')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to guest billing</a></div>

    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="app-card h-100"><div class="card-body">
                <div class="d-flex justify-content-between flex-wrap gap-2">
                    <div>
                        <div class="stat-label">Guest</div>
                        <div class="h5 mb-0"><?= e($folio['full_name']) ?></div>
                        <div class="small text-muted"><?= e($folio['guest_code']) ?><?= $folio['company_name'] ? ' &middot; ' . e($folio['company_name']) : '' ?></div>
                    </div>
                    <div class="text-md-end">
                        <div class="stat-label">Folio</div>
                        <div class="h5 mb-0"><?= e($folio['folio_number']) ?></div>
                        <div><?= status_badge($folio['status']) ?></div>
                    </div>
                </div>
                <hr>
                <div class="row small g-2">
                    <div class="col-6 col-md-3"><span class="text-muted d-block">Room</span><?= $folio['room_number'] ? e($folio['room_number'] . ' (' . $folio['room_type'] . ')') : '-' ?></div>
                    <div class="col-6 col-md-3"><span class="text-muted d-block">Reservation</span><?= e($folio['reservation_code'] ?? '-') ?></div>
                    <?php if ($hasStay): ?>
                        <div class="col-6 col-md-3"><span class="text-muted d-block">Check-in</span><?= e(format_date($folio['checkin_at'], 'M d, Y g:i A')) ?></div>
                        <div class="col-6 col-md-3"><span class="text-muted d-block">Expected checkout</span><?= e(format_date($folio['expected_checkout_at'], 'M d, Y g:i A')) ?></div>
                        <div class="col-6 col-md-3"><span class="text-muted d-block">Actual checkout</span><?= $folio['actual_checkout_at'] ? e(format_date($folio['actual_checkout_at'], 'M d, Y g:i A')) : '<span class="text-muted">Not yet</span>' ?></div>
                        <div class="col-6 col-md-3"><span class="text-muted d-block">Hourly rate</span><?= e(peso($folio['hourly_rate_applied'])) ?> / hour</div>
                        <div class="col-6 col-md-3"><span class="text-muted d-block">Billed hours</span><?= $hourlyCharge ? e(rtrim(rtrim(number_format((float)$hourlyCharge['quantity'], 2), '0'), '.')) . ' h' : '-' ?></div>
                    <?php else: ?>
                        <div class="col-12 text-muted">Stay times were not recorded for this bill (it was created before hourly billing).</div>
                    <?php endif; ?>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-3 no-print">
                    <?php if (!$hasStay && $editable): ?><button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#stayModal"><i class="bi bi-clock-history me-1"></i>Set stay times</button><?php endif; ?>
                    <?php if ($stayOpen): ?>
                        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#expectedModal"><i class="bi bi-pencil me-1"></i>Change expected checkout</button>
                        <button class="btn btn-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#checkoutModal"><i class="bi bi-box-arrow-right me-1"></i>Check out guest</button>
                    <?php endif; ?>
                </div>
                <?php if ($hasStay && !$rules['approved']): ?><div class="alert alert-warning py-2 small mt-3 mb-0">The hourly billing rules (rounding, grace time, minimum hours) are <strong>proposed and not yet approved</strong>. Rules in use: <?= e(['ceil_hour' => 'round up to the next full hour', 'ceil_half_hour' => 'round up to the next half hour', 'exact_minutes' => 'charge by the exact minute'][$rules['mode']]) ?>, grace <?= (int)$rules['grace'] ?> min, minimum <?= e(rtrim(rtrim(number_format($rules['min_hours'], 2), '0'), '.') ?: '0') ?> h.</div><?php endif; ?>
                <?php if ($folio['notes']): ?><div class="small mt-3"><span class="text-muted">Notes:</span> <?= e($folio['notes']) ?></div><?php endif; ?>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="app-card h-100"><div class="card-body">
                <div class="stat-label mb-2">Charge summary</div>
                <div class="d-flex justify-content-between"><span>Verified</span><strong><?= e(peso($sum['verified'])) ?></strong></div>
                <div class="d-flex justify-content-between"><span>Pending</span><strong><?= e(peso($sum['pending'])) ?></strong></div>
                <div class="d-flex justify-content-between"><span>Flagged</span><strong class="value-negative"><?= e(peso($sum['flagged'])) ?></strong></div>
                <hr class="my-2">
                <div class="d-flex justify-content-between"><span>All charges</span><strong><?= e(peso($sum['all'])) ?></strong></div>
                <hr class="my-2">
                <?php $sm = $invoice
                    ? ['subtotal' => (float)$invoice['subtotal'], 'sr' => (float)$invoice['service_rate_applied'], 'service' => (float)$invoice['service_charge_amount'], 'tr' => (float)$invoice['tax_rate_applied'], 'tax' => (float)$invoice['tax_amount'], 'total' => (float)$invoice['total_amount']]
                    : ['subtotal' => $bill['subtotal'], 'sr' => $bill['service_rate'], 'service' => $bill['service'], 'tr' => $bill['tax_rate'], 'tax' => $bill['tax'], 'total' => $bill['total']]; ?>
                <div class="stat-label mb-1">Bill summary <span class="text-muted text-lowercase">(<?= $invoice ? 'as invoiced' : 'if invoiced today' ?>)</span></div>
                <div class="d-flex justify-content-between small"><span>Subtotal (before tax)</span><span><?= e(peso($sm['subtotal'])) ?></span></div>
                <div class="d-flex justify-content-between small"><span>Service charge (<?= e(rtrim(rtrim(number_format($sm['sr'], 2), '0'), '.')) ?>%)</span><span><?= e(peso($sm['service'])) ?></span></div>
                <div class="d-flex justify-content-between small"><span>Tax (<?= e(rtrim(rtrim(number_format($sm['tr'], 2), '0'), '.')) ?>%)</span><span><?= e(peso($sm['tax'])) ?></span></div>
                <div class="d-flex justify-content-between"><strong>Total (tax included)</strong><strong><?= e(peso($sm['total'])) ?></strong></div>
                <?php if ($invoice): ?>
                    <hr class="my-2">
                    <div class="small">Invoice <strong><?= e($invoice['invoice_number']) ?></strong> &middot; <?= status_badge($invoice['payment_status']) ?></div>
                    <div class="d-flex justify-content-between small mt-1"><span>Total</span><span><?= e(peso($invoice['total_amount'])) ?></span></div>
                    <div class="d-flex justify-content-between small"><span>Balance</span><strong><?= e(peso($invoice['outstanding_balance'])) ?></strong></div>
                    <?php if (has_permission('invoices.manage')): ?>
                        <a class="btn btn-outline-primary btn-sm mt-2 w-100" href="<?= e(url('invoices.php?view=' . (int)$invoice['invoice_id'])) ?>">Open invoice</a>
                    <?php endif; ?>
                <?php elseif ($canInvoice && has_permission('invoices.manage')): ?>
                    <form method="post" action="<?= e(url('invoices.php')) ?>" class="mt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="generate">
                        <input type="hidden" name="folio_id" value="<?= (int)$folioId ?>">
                        <input type="hidden" name="issue_date" value="<?= e(date('Y-m-d')) ?>">
                        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-file-earmark-plus me-1"></i>Generate invoice</button>
                    </form>
                <?php elseif (!$invoice && $charges): ?>
                    <div class="small text-muted mt-3">All charges must be verified before an invoice can be generated.
                        <?php if (has_permission('folio.verify')): ?><a href="<?= e(url('folio-verification.php?folio=' . (int)$folioId)) ?>">Verify charges</a><?php endif; ?></div>
                <?php endif; ?>
            </div></div>
        </div>
    </div>

    <div class="app-card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title-serif">Charges</h3>
            <?php if ($editable): ?>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#chargeModal"><i class="bi bi-plus-lg me-1"></i>Add charge</button>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Date</th><th>Category</th><th>Description</th><th class="amount">Qty</th><th class="amount">Unit price</th><th class="amount">Amount</th><th>Taxable</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php if (!$charges): ?><tr><td colspan="9" class="text-center text-muted py-4">No charges posted yet.</td></tr><?php endif; ?>
                <?php foreach ($charges as $c): $locked = in_array($c['charge_id'], $usedCharges); ?>
                    <tr class="<?= $locked ? 'row-locked' : '' ?>">
                        <td><?= e(format_date($c['charge_date'])) ?></td>
                        <td><?= e(CHARGE_CATEGORIES[$c['category']] ?? $c['category']) ?></td>
                        <td><?= e($c['description']) ?><?php if (($c['charge_source'] ?? 'manual') !== 'manual'): ?> <span class="badge badge-soft badge-pink"><?= ($c['charge_source'] ?? '') === 'hourly' ? 'Automatic' : 'Price list' ?></span><?php endif; ?>
                            <?php if ($c['verification_notes']): ?><div class="small text-muted"><?= e($c['verification_notes']) ?></div><?php endif; ?></td>
                        <td class="amount"><?= e(rtrim(rtrim(number_format((float)$c['quantity'], 2), '0'), '.')) ?></td>
                        <td class="amount"><?= e(peso($c['unit_price'])) ?></td>
                        <td class="amount"><?= e(peso($c['amount'])) ?></td>
                        <td><?= $c['is_taxable'] ? 'Yes' : 'No' ?></td>
                        <td><?= status_badge($c['verification_status']) ?></td>
                        <td class="text-end">
                            <?php if ($locked): ?><span class="text-muted small"><i class="bi bi-lock"></i> invoiced</span>
                            <?php elseif ($editable && ($c['charge_source'] ?? 'manual') !== 'hourly'): ?>
                                <form method="post" class="d-inline" data-no-double>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_charge">
                                    <input type="hidden" name="charge_id" value="<?= (int)$c['charge_id'] ?>">
                                    <input type="hidden" name="folio_id" value="<?= (int)$folioId ?>">
                                    <button class="btn btn-outline-secondary btn-sm" type="submit" data-confirm="Remove this charge from the folio?"><i class="bi bi-x-lg"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($editable): ?>
    <div class="modal fade" id="chargeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog"><form method="post" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_charge">
            <input type="hidden" name="folio_id" value="<?= (int)$folioId ?>">
            <div class="modal-header"><h5 class="modal-title">Add service charge</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <?php if (!$priceList): ?>
                    <div class="alert alert-info mb-0">The service price list is empty, and prices are not typed in manually. An administrator adds services and prices under <strong>Administration &rarr; Tax and Charges &rarr; Service price list</strong>. The room is charged automatically by the hour.</div>
                <?php else: ?>
                <div class="row g-3">
                    <div class="col-12"><label class="form-label">Service</label>
                        <select name="price_id" id="svcPrice" class="form-select" required><option value="" data-price="">- select from the price list -</option>
                            <?php foreach ($priceList as $pl): ?><option value="<?= (int)$pl['price_id'] ?>" data-price="<?= e(number_format((float)$pl['unit_price'], 2, '.', '')) ?>"><?= e(CHARGE_CATEGORIES[$pl['category']] . ' - ' . $pl['item_name'] . ' - ' . peso($pl['unit_price'])) ?></option><?php endforeach; ?></select>
                        <div class="form-text">The price comes from the price list and cannot be changed here.</div></div>
                    <div class="col-6"><label class="form-label">Quantity</label><input type="number" name="quantity" id="svcQty" step="0.01" min="0.01" value="1" class="form-control" required></div>
                    <div class="col-6"><label class="form-label">Date</label><input type="date" name="charge_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" required></div>
                    <div class="col-12"><div class="p-2 rounded small" style="background:rgba(195,176,145,.22)">Amount before tax: <strong id="svcAmount">-</strong></div></div>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><?php if ($priceList): ?><button class="btn btn-primary" type="submit">Add charge</button><?php endif; ?></div>
        </form></div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var sel = document.getElementById('svcPrice'), qty = document.getElementById('svcQty'), out = document.getElementById('svcAmount');
        if (!sel) { return; }
        function calc() {
            var p = parseFloat(sel.options[sel.selectedIndex].dataset.price), q = parseFloat(qty.value);
            out.textContent = (isFinite(p) && isFinite(q)) ? '\u20B1' + (p * q).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '-';
        }
        sel.addEventListener('change', calc); qty.addEventListener('input', calc);
    });
    </script>

    <?php if (!$hasStay): ?>
    <div class="modal fade" id="stayModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog"><form method="post" class="modal-content">
            <?= csrf_field() ?><input type="hidden" name="action" value="set_stay"><input type="hidden" name="folio_id" value="<?= (int)$folioId ?>">
            <div class="modal-header"><h5 class="modal-title">Set stay times</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><div class="row g-3">
                <?php if (!$folio['reservation_id']): ?>
                    <div class="col-12"><label class="form-label">Room</label><select name="room_id" class="form-select" required><option value="">- select room -</option>
                        <?php foreach ($roomsAll as $rm): ?><option value="<?= (int)$rm['room_id'] ?>"><?= e($rm['room_number'] . ' (' . $rm['room_type'] . ')' . ($rm['hourly_rate'] === null ? ' - no hourly rate yet' : ' - ' . peso($rm['hourly_rate']) . '/hour')) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
                <div class="col-md-6"><label class="form-label">Check-in</label><input type="datetime-local" name="checkin_at" class="form-control" value="<?= e($nowLocal) ?>" required></div>
                <div class="col-md-6"><label class="form-label">Expected checkout</label><input type="datetime-local" name="expected_checkout_at" class="form-control" required></div>
            </div><div class="form-text mt-2">The room is charged automatically: hours &times; the room's hourly rate. If this bill already has a manual room charge, remove it afterwards so the room is not billed twice.</div></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save stay</button></div>
        </form></div>
    </div>
    <?php endif; ?>

    <?php if ($stayOpen): ?>
    <div class="modal fade" id="expectedModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog"><form method="post" class="modal-content">
            <?= csrf_field() ?><input type="hidden" name="action" value="update_checkout"><input type="hidden" name="folio_id" value="<?= (int)$folioId ?>">
            <div class="modal-header"><h5 class="modal-title">Change expected checkout</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><div class="row g-3">
                <div class="col-12"><label class="form-label">New expected checkout</label><input type="datetime-local" name="expected_checkout_at" class="form-control" value="<?= e(date('Y-m-d\TH:i', strtotime((string)$folio['expected_checkout_at']))) ?>" required></div>
                <div class="col-12"><label class="form-label">Reason (optional)</label><input type="text" name="reason" maxlength="255" class="form-control"></div>
            </div><div class="form-text mt-2">The room charge is recalculated and must be verified again. The change is recorded in the audit trail.</div></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form></div>
    </div>
    <div class="modal fade" id="checkoutModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog"><form method="post" class="modal-content">
            <?= csrf_field() ?><input type="hidden" name="action" value="check_out"><input type="hidden" name="folio_id" value="<?= (int)$folioId ?>">
            <div class="modal-header"><h5 class="modal-title">Check out guest</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><label class="form-label">Actual checkout</label><input type="datetime-local" name="actual_checkout_at" class="form-control" value="<?= e($nowLocal) ?>" max="<?= e($nowLocal) ?>" required>
                <div class="form-text mt-2">Early or late checkout is billed on the actual time, using the billing rules. The room charge must be verified again.</div></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Check out</button></div>
        </form></div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

/* ============================== List view ============================== */
$q      = clean_text($_GET['q'] ?? '', 60);
$fstat  = (string)($_GET['fstatus'] ?? '');
$bstat  = (string)($_GET['bstatus'] ?? '');
$from   = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$to     = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
$page   = current_page_number();
$perPage = 15;

$where = ['1 = 1'];
$params = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(f.folio_number LIKE ? OR g.full_name LIKE ? OR g.guest_code LIKE ? OR rm.room_number LIKE ? OR inv.invoice_number LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}
if (in_array($fstat, ['open', 'verified', 'invoiced', 'closed'], true)) { $where[] = 'f.status = ?'; $params[] = $fstat; }
if ($bstat === 'unbilled') { $where[] = 'inv.invoice_id IS NULL'; }
elseif (in_array($bstat, ['unpaid', 'partially_paid', 'paid', 'overdue'], true)) { $where[] = 'b.payment_status = ?'; $params[] = $bstat; }
if ($from !== '') { $where[] = 'r.check_in >= ?'; $params[] = $from; }
if ($to !== '')   { $where[] = 'r.check_in <= ?'; $params[] = $to; }
$whereSql = implode(' AND ', $where);

$fromSql = "FROM guest_folios f
    JOIN guests g ON g.guest_id = f.guest_id
    LEFT JOIN reservations r ON r.reservation_id = f.reservation_id
    LEFT JOIN rooms rm ON rm.room_id = COALESCE(f.room_id, r.room_id)
    LEFT JOIN invoices inv ON inv.folio_id = f.folio_id AND inv.status <> 'cancelled'
    LEFT JOIN v_invoice_balances b ON b.invoice_id = inv.invoice_id
    WHERE $whereSql";

$total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
$rows = db_all(
    "SELECT f.folio_id, f.folio_number, f.status, g.guest_code, g.full_name, rm.room_number, r.check_in, r.check_out,
            (SELECT COALESCE(SUM(c.amount), 0) FROM folio_charges c WHERE c.folio_id = f.folio_id) AS charges_total,
            inv.invoice_id, inv.invoice_number, inv.tax_amount, inv.service_charge_amount, inv.discount_amount, inv.total_amount,
            b.verified_paid, b.deposit_applied, b.outstanding_balance, b.payment_status
     $fromSql ORDER BY f.folio_id DESC", $params, $perPage, ($page - 1) * $perPage);

$freeReservations = db_all(
    "SELECT r.reservation_id, r.reservation_code, g.full_name, rm.room_number, r.check_in, r.check_out
     FROM reservations r JOIN guests g ON g.guest_id = r.guest_id JOIN rooms rm ON rm.room_id = r.room_id
     WHERE r.status <> 'cancelled' AND NOT EXISTS (SELECT 1 FROM guest_folios f WHERE f.reservation_id = r.reservation_id)
     ORDER BY r.check_in");
$allGuests = db_all('SELECT guest_id, guest_code, full_name FROM guests ORDER BY full_name');
$roomsAll = db_all('SELECT room_id, room_number, room_type, hourly_rate FROM rooms ORDER BY room_number');
$nowLocal = date('Y-m-d\\TH:i');

if (($_GET['export'] ?? '') === 'csv') {
    require_permission('exports.generate');
    $data = db_all("SELECT f.folio_id, f.folio_number, g.guest_code, g.full_name, rm.room_number, f.checkin_at, f.expected_checkout_at, f.actual_checkout_at, r.check_in, r.check_out,
                           f.status AS folio_status, inv.invoice_number, inv.subtotal, inv.service_charge_amount, inv.tax_amount, inv.discount_amount, inv.adjustment_amount, inv.total_amount,
                           b.verified_paid, b.deposit_applied, b.outstanding_balance, b.payment_status
                    $fromSql ORDER BY f.folio_id DESC", $params);
    $rows = []; $invoiced = [];
    foreach ($data as $d) {
        if ($d['invoice_number'] !== null) {
            $row = [$d['folio_number'], $d['guest_code'], $d['full_name'], $d['room_number'], $d['checkin_at'] ?? $d['check_in'], $d['expected_checkout_at'] ?? $d['check_out'], $d['actual_checkout_at'],
                    csv_label($d['folio_status']), $d['invoice_number'], 'Invoice', $d['subtotal'], $d['service_charge_amount'], $d['tax_amount'], $d['discount_amount'], $d['adjustment_amount'],
                    $d['total_amount'], (float)$d['verified_paid'] + (float)$d['deposit_applied'], $d['outstanding_balance'], csv_label($d['payment_status'])];
            $invoiced[] = $row;
        } else {                                                  // not invoiced yet: show the estimate the bill screen shows
            $bs = folio_bill_summary((int)$d['folio_id']);
            $row = [$d['folio_number'], $d['guest_code'], $d['full_name'], $d['room_number'], $d['checkin_at'] ?? $d['check_in'], $d['expected_checkout_at'] ?? $d['check_out'], $d['actual_checkout_at'],
                    csv_label($d['folio_status']), null, 'Estimate (not invoiced yet)', $bs['subtotal'], $bs['service'], $bs['tax'], null, null, $bs['total'], null, null, 'Not invoiced'];
        }
        $rows[] = $row;
    }
    csv_download('guest_billing_report', 'Guest Billing Report',
        ['Search' => $q, 'Folio status' => csv_label($fstat), 'Billing status' => csv_label($bstat), 'Check-in from' => $from, 'Check-in to' => $to],
        [['Folio No.', 'text'], ['Guest ID', 'text'], ['Guest name', 'text'], ['Room', 'text'], ['Check-in', 'datetime'], ['Expected checkout', 'datetime'], ['Actual checkout', 'datetime'],
         ['Folio status', 'text'], ['Invoice No.', 'text'], ['Amount basis', 'text'], ['Subtotal before tax (PHP)', 'money', true], ['Service charge (PHP)', 'money', true], ['Tax (PHP)', 'money', true],
         ['Discounts (PHP)', 'money', true], ['Adjustments (PHP)', 'money', true], ['Total incl. tax (PHP)', 'money', true], ['Payments received (PHP)', 'money', true],
         ['Outstanding balance (PHP)', 'money', true], ['Billing status', 'text']], $rows, $invoiced, 'TOTAL (invoiced bills only)');
}

$pageTitle = 'Guest Billing';
include __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="text-muted small">Guest folios, their charges, invoices and balances.</div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#folioModal"><i class="bi bi-plus-lg me-1"></i>New guest bill</button>
</div>

<div class="mb-3 no-print"><?= render_export_menu('?' . qs(['export' => 'csv', 'page' => '']), has_permission('exports.generate')) ?></div>
<form method="get" class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small mb-1">Search</label>
            <input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Guest, folio, room, invoice"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Folio status</label>
            <select name="fstatus" class="form-select form-select-sm"><option value="">All</option>
                <?php foreach (['open', 'verified', 'invoiced', 'closed'] as $s): ?><option value="<?= e($s) ?>" <?= $fstat === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Billing status</label>
            <select name="bstatus" class="form-select form-select-sm"><option value="">All</option>
                <?php foreach (['unbilled' => 'Not invoiced', 'unpaid' => 'Unpaid', 'partially_paid' => 'Partially paid', 'paid' => 'Paid', 'overdue' => 'Overdue'] as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= $bstat === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Check-in from</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Check-in to</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <div class="col-md-1 d-grid"><button class="btn btn-primary btn-sm" type="submit">Filter</button></div>
    </div>
</form>

<div class="app-card">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Folio</th><th>Guest</th><th>Room</th><th>Stay</th><th class="amount">Charges</th><th class="amount">Discount</th><th class="amount">Tax + service</th><th class="amount">Paid</th><th class="amount">Balance</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="11" class="text-center text-muted py-4">No guest bills match your filters.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a href="<?= e(url('guest-billing.php?folio=' . (int)$r['folio_id'])) ?>"><strong><?= e($r['folio_number']) ?></strong></a>
                        <?php if ($r['invoice_number']): ?><div class="small text-muted"><?= e($r['invoice_number']) ?></div><?php endif; ?></td>
                    <td><?= e($r['full_name']) ?><div class="small text-muted"><?= e($r['guest_code']) ?></div></td>
                    <td><?= e($r['room_number'] ?? '-') ?></td>
                    <td class="small"><?= e(format_date($r['check_in'], 'M d')) ?> - <?= e(format_date($r['check_out'], 'M d, Y')) ?></td>
                    <td class="amount"><?= e(peso($r['charges_total'])) ?></td>
                    <td class="amount"><?= $r['invoice_id'] ? e(peso($r['discount_amount'])) : '-' ?></td>
                    <td class="amount"><?= $r['invoice_id'] ? e(peso($r['tax_amount'] + $r['service_charge_amount'])) : '-' ?></td>
                    <td class="amount"><?= $r['invoice_id'] ? e(peso($r['verified_paid'] + $r['deposit_applied'])) : '-' ?></td>
                    <td class="amount"><strong><?= $r['invoice_id'] ? e(peso($r['outstanding_balance'])) : '-' ?></strong></td>
                    <td><?= $r['invoice_id'] ? status_badge($r['payment_status']) : status_badge($r['status']) ?></td>
                    <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="<?= e(url('guest-billing.php?folio=' . (int)$r['folio_id'])) ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="d-flex justify-content-between align-items-center mt-2 small text-muted"><span><?= $total ?> bill(s)</span></div>
<?= render_pager($total, $page, $perPage) ?>

<div class="modal fade" id="folioModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_folio">
        <div class="modal-header"><h5 class="modal-title">New guest bill</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">From a reservation</label>
                <select name="reservation_id" class="form-select">
                    <option value="0">- none (choose a guest below) -</option>
                    <?php foreach ($freeReservations as $fr): ?>
                        <option value="<?= (int)$fr['reservation_id'] ?>"><?= e($fr['reservation_code'] . ' - ' . $fr['full_name'] . ' - Room ' . $fr['room_number'] . ' (' . format_date($fr['check_in'], 'M d') . ' to ' . format_date($fr['check_out'], 'M d') . ')') ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Only reservations without a bill are listed.</div></div>
            <div class="mb-3"><label class="form-label">Or for a guest (walk-in / other)</label>
                <select name="guest_id" class="form-select"><option value="0">- select guest -</option>
                    <?php foreach ($allGuests as $g): ?><option value="<?= (int)$g['guest_id'] ?>"><?= e($g['guest_code'] . ' - ' . $g['full_name']) ?></option><?php endforeach; ?></select></div>
            <div class="mb-3"><label class="form-label">Room (walk-in guests only)</label>
                <select name="room_id" id="nbRoom" class="form-select"><option value="0">- the room comes from the reservation -</option>
                    <?php foreach ($roomsAll as $rm): ?><option value="<?= (int)$rm['room_id'] ?>"><?= e($rm['room_number'] . ' (' . $rm['room_type'] . ')' . ($rm['hourly_rate'] === null ? ' - no hourly rate yet' : ' - ' . peso($rm['hourly_rate']) . '/hour')) ?></option><?php endforeach; ?></select></div>
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label class="form-label">Check-in</label><input type="datetime-local" name="checkin_at" class="form-control" value="<?= e($nowLocal) ?>" required></div>
                <div class="col-md-6"><label class="form-label">Expected checkout</label><input type="datetime-local" name="expected_checkout_at" class="form-control" required></div>
            </div>
            <div><label class="form-label">Notes (optional)</label><input type="text" name="notes" maxlength="255" class="form-control"></div>
            <div class="form-text mt-2">No price is typed in: the room charge is calculated automatically as hours &times; the room's hourly rate.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Create bill</button></div>
    </form></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
