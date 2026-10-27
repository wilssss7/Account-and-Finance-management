<?php
/**
 * deposits.php - Guest Deposit Management (module 14)
 * A deposit is held as a liability (not revenue). It can be applied to an invoice of the same guest
 * or refunded through refunds.php. Remaining = received - applied - refunded.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('deposits.manage');

$pdo = db();
$me  = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        $pdo->beginTransaction();

        if ($action === 'record') {
            $guestId = (int)($_POST['guest_id'] ?? 0);
            $resId   = (int)($_POST['reservation_id'] ?? 0);
            $date    = (string)($_POST['deposit_date'] ?? '');
            $method  = (string)($_POST['payment_method'] ?? '');
            $ref     = clean_text($_POST['reference_number'] ?? '', 60);
            $amount  = parse_money($_POST['amount'] ?? '');
            if (db_value('SELECT COUNT(*) FROM guests WHERE guest_id = ?', [$guestId]) == 0) { throw new RuntimeException('Choose the guest who paid the deposit.'); }
            if ($resId > 0) {
                $r = db_one('SELECT guest_id, status FROM reservations WHERE reservation_id = ?', [$resId]);
                if (!$r || (int)$r['guest_id'] !== $guestId || $r['status'] === 'cancelled') { throw new RuntimeException('Choose a valid reservation that belongs to this guest.'); }
            }
            if (!valid_date($date) || $date > date('Y-m-d')) { throw new RuntimeException('The deposit date must be a valid date that is not in the future.'); }
            if (!isset(PAYMENT_METHODS[$method])) { throw new RuntimeException('Choose a payment method.'); }
            if ($amount === null) { throw new RuntimeException('Enter a valid deposit amount.'); }
            if ($method !== 'cash' && $ref === '') { throw new RuntimeException('Enter the reference number for this ' . PAYMENT_METHODS[$method] . ' deposit.'); }
            if ($ref !== '' && strlen(preg_replace('/\D/', '', $ref)) >= 13 && preg_match('/^[\d\s-]+$/', $ref)) {
                throw new RuntimeException('The reference looks like a full card number. Never enter card numbers.');
            }
            if ($method !== 'cash' && db_value("SELECT COUNT(*) FROM guest_deposits WHERE payment_method = ? AND reference_number = ?", [$method, $ref]) > 0) {
                throw new RuntimeException('This reference number was already used for another deposit.');
            }
            require_open_period($date); // blocks deposits dated in a closed period
            $number = next_doc_number('DEP', (int)date('Y', strtotime($date)));
            $pdo->prepare("INSERT INTO guest_deposits (deposit_number, guest_id, reservation_id, deposit_date, payment_method, reference_number, amount_received, status, recorded_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 'held', ?)")
                ->execute([$number, $guestId, $resId ?: null, $date, $method, $ref !== '' ? $ref : null, $amount, (int)$me['id']]);
            audit_log('deposit_recorded', 'guest_deposits', (int)$pdo->lastInsertId(), null, ['deposit_number' => $number, 'amount' => $amount, 'method' => $method]);
            $pdo->commit();
            set_flash('success', "Deposit $number of " . peso($amount) . ' was recorded.');

        } elseif ($action === 'apply') {
            $depId = (int)($_POST['deposit_id'] ?? 0);
            $invId = (int)($_POST['invoice_id'] ?? 0);
            $amount = parse_money($_POST['amount'] ?? '');
            if ($amount === null) { throw new RuntimeException('Enter a valid amount to apply.'); }
            $dep = db_one('SELECT * FROM guest_deposits WHERE deposit_id = ? FOR UPDATE', [$depId]);
            $inv = db_one('SELECT invoice_id, invoice_number, guest_id, period_id, status FROM invoices WHERE invoice_id = ? FOR UPDATE', [$invId]);
            if (!$dep || !$inv) { throw new RuntimeException('Choose a deposit and an invoice.'); }
            if ((int)$dep['guest_id'] !== (int)$inv['guest_id']) { throw new RuntimeException('A deposit can only be applied to an invoice of the same guest.'); }
            if (in_array($inv['status'], ['draft', 'cancelled'], true)) { throw new RuntimeException('Choose an issued invoice that is not cancelled.'); }
            require_open_period_id((int)$inv['period_id']);
            $depAvail = deposit_available($depId);
            if ($amount > $depAvail + 0.004) { throw new RuntimeException('Only ' . peso($depAvail) . ' of this deposit is available (refund requests are set aside).'); }
            $bal = invoice_balance($invId);
            $invAvail = round((float)$bal['outstanding_balance'] - pending_payment_sum($invId), 2);
            if ($amount > $invAvail + 0.004) { throw new RuntimeException('The invoice can only take ' . peso(max(0, $invAvail)) . ' (unpaid balance minus pending payments).'); }

            $newApplied = round((float)$dep['amount_applied'] + $amount, 2);
            $status = deposit_status((float)$dep['amount_received'], $newApplied, (float)$dep['amount_refunded']);
            $pdo->prepare('INSERT INTO deposit_applications (deposit_id, invoice_id, amount, applied_by) VALUES (?, ?, ?, ?)')->execute([$depId, $invId, $amount, (int)$me['id']]);
            $pdo->prepare('UPDATE guest_deposits SET amount_applied = ?, status = ? WHERE deposit_id = ?')->execute([$newApplied, $status, $depId]);
            refresh_invoice_status($invId);
            audit_log('deposit_applied', 'guest_deposits', $depId, ['amount_applied' => $dep['amount_applied']],
                ['amount_applied' => $newApplied, 'invoice' => $inv['invoice_number'], 'applied_now' => $amount]);
            $pdo->commit();
            set_flash('success', peso($amount) . ' of deposit ' . $dep['deposit_number'] . ' was applied to ' . $inv['invoice_number'] . '.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The action could not be completed. Please try again.');
    }
    $ret = ltrim((string)($_POST['return'] ?? ''), '?');
    redirect('deposits.php' . ($ret !== '' ? '?' . $ret : ''));
}

/* ============================ Deposit detail ============================ */
$viewId = (int)($_GET['view'] ?? 0);
if ($viewId > 0) {
    $d = db_one('SELECT d.*, g.full_name, g.guest_code, r.reservation_code, u.full_name AS recorder
                 FROM guest_deposits d JOIN guests g ON g.guest_id = d.guest_id
                 LEFT JOIN reservations r ON r.reservation_id = d.reservation_id JOIN users u ON u.user_id = d.recorded_by
                 WHERE d.deposit_id = ?', [$viewId]);
    if (!$d) { set_flash('danger', 'Deposit not found.'); redirect('deposits.php'); }
    $apps = db_all('SELECT a.*, i.invoice_number, u.full_name AS applier FROM deposit_applications a
                    JOIN invoices i ON i.invoice_id = a.invoice_id JOIN users u ON u.user_id = a.applied_by
                    WHERE a.deposit_id = ? ORDER BY a.applied_at', [$viewId]);
    $refs = db_all('SELECT * FROM refunds WHERE deposit_id = ? ORDER BY created_at', [$viewId]);
    $pageTitle = 'Deposit ' . $d['deposit_number'];
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="mb-3"><a href="<?= e(url('deposits.php')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>All deposits</a></div>
    <div class="row g-3 mb-3">
        <div class="col-lg-7"><div class="app-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between"><div><div class="stat-label">Guest</div><div class="h5 mb-0"><?= e($d['full_name']) ?></div>
                <div class="small text-muted"><?= e($d['guest_code']) ?></div></div><div><?= status_badge($d['status']) ?></div></div>
            <hr><div class="row small g-2">
                <div class="col-6 col-md-3"><span class="text-muted d-block">Received</span><?= e(format_date($d['deposit_date'])) ?></div>
                <div class="col-6 col-md-3"><span class="text-muted d-block">Method</span><?= e(PAYMENT_METHODS[$d['payment_method']] ?? '') ?></div>
                <div class="col-6 col-md-3"><span class="text-muted d-block">Reference</span><?= e($d['reference_number'] ?? '-') ?></div>
                <div class="col-6 col-md-3"><span class="text-muted d-block">Reservation</span><?= e($d['reservation_code'] ?? '-') ?></div>
                <div class="col-12"><span class="text-muted">Recorded by</span> <?= e($d['recorder']) ?></div></div>
        </div></div></div>
        <div class="col-lg-5"><div class="app-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between"><span>Received</span><strong><?= e(peso($d['amount_received'])) ?></strong></div>
            <div class="d-flex justify-content-between"><span>Applied to invoices</span><strong><?= e(peso($d['amount_applied'])) ?></strong></div>
            <div class="d-flex justify-content-between"><span>Refunded</span><strong><?= e(peso($d['amount_refunded'])) ?></strong></div>
            <hr class="my-2"><div class="d-flex justify-content-between"><span>Remaining</span><strong class="h5 mb-0"><?= e(peso($d['remaining_amount'])) ?></strong></div>
        </div></div></div>
    </div>
    <div class="row g-3">
        <div class="col-lg-6"><div class="app-card"><div class="card-header"><h3 class="card-title-serif">Applied to invoices</h3></div><div class="table-responsive">
            <table class="table"><thead><tr><th>Invoice</th><th>Date</th><th>By</th><th class="amount">Amount</th></tr></thead><tbody>
            <?php if (!$apps): ?><tr><td colspan="4" class="text-center text-muted py-3">Not applied yet.</td></tr><?php endif; ?>
            <?php foreach ($apps as $a): ?><tr><td><?= e($a['invoice_number']) ?></td><td><?= e(format_date($a['applied_at'])) ?></td><td><?= e($a['applier']) ?></td><td class="amount"><?= e(peso($a['amount'])) ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div></div>
        <div class="col-lg-6"><div class="app-card"><div class="card-header"><h3 class="card-title-serif">Refund requests</h3></div><div class="table-responsive">
            <table class="table"><thead><tr><th>Refund</th><th>Date</th><th>Status</th><th class="amount">Amount</th></tr></thead><tbody>
            <?php if (!$refs): ?><tr><td colspan="4" class="text-center text-muted py-3">No refunds.</td></tr><?php endif; ?>
            <?php foreach ($refs as $f): ?><tr><td><?= e($f['refund_number']) ?></td><td><?= e(format_date($f['created_at'])) ?></td><td><?= status_badge($f['status']) ?></td><td class="amount"><?= e(peso($f['amount'])) ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div></div>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

/* ------------------------------ List view ------------------------------ */
$stat = (string)($_GET['status'] ?? '');
$q    = clean_text($_GET['q'] ?? '', 60);
$from = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$to   = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
$page = current_page_number();
$perPage = 15;
$where = ['1 = 1']; $params = [];
if (in_array($stat, ['held', 'partially_applied', 'fully_applied', 'refunded'], true)) { $where[] = 'd.status = ?'; $params[] = $stat; }
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(d.deposit_number LIKE ? OR g.full_name LIKE ? OR d.reference_number LIKE ? OR r.reservation_code LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
if ($from !== '') { $where[] = 'd.deposit_date >= ?'; $params[] = $from; }
if ($to !== '')   { $where[] = 'd.deposit_date <= ?'; $params[] = $to; }
$fromSql = 'FROM guest_deposits d JOIN guests g ON g.guest_id = d.guest_id LEFT JOIN reservations r ON r.reservation_id = d.reservation_id WHERE ' . implode(' AND ', $where);
$total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
$sums = db_one("SELECT COALESCE(SUM(d.amount_received), 0) AS received, COALESCE(SUM(d.remaining_amount), 0) AS remaining $fromSql", $params);
$rows = db_all("SELECT d.*, g.full_name, g.guest_code, r.reservation_code $fromSql ORDER BY d.deposit_id DESC", $params, $perPage, ($page - 1) * $perPage);

$guests = db_all('SELECT guest_id, guest_code, full_name FROM guests ORDER BY full_name');
$reservations = db_all("SELECT reservation_id, reservation_code, guest_id, check_in FROM reservations WHERE status <> 'cancelled' ORDER BY check_in DESC");
$payable = db_all("SELECT t.* FROM (
        SELECT b.invoice_id, b.invoice_number, b.guest_id, b.outstanding_balance,
               b.outstanding_balance - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = b.invoice_id AND p.status = 'pending'), 0) AS available
        FROM v_invoice_balances b WHERE b.payment_status IN ('unpaid', 'partially_paid', 'overdue')) t
    WHERE t.available > 0 ORDER BY t.invoice_number");

$pageTitle = 'Guest Deposits';
include __DIR__ . '/includes/header.php';
$returnQs = qs();
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
        <span class="summary-chip"><strong><?= $total ?></strong> deposit(s)</span>
        <span class="summary-chip">Received <strong><?= e(peso($sums['received'])) ?></strong></span>
        <span class="summary-chip">Still held <strong><?= e(peso($sums['remaining'])) ?></strong></span>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#depositModal"><i class="bi bi-plus-lg me-1"></i>Record deposit</button>
</div>

<form method="get" class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small mb-1">Search</label><input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Deposit, guest, reference"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm"><option value="">All</option>
                <?php foreach (['held', 'partially_applied', 'fully_applied', 'refunded'] as $s): ?><option value="<?= e($s) ?>" <?= $stat === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2 d-grid"><button class="btn btn-primary btn-sm" type="submit">Filter</button></div>
    </div>
</form>

<div class="app-card"><div class="table-responsive">
    <table class="table table-hover">
        <thead><tr><th>Deposit</th><th>Guest</th><th>Method</th><th class="amount">Received</th><th class="amount">Applied</th><th class="amount">Refunded</th><th class="amount">Remaining</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No deposits match your filters.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $avail = deposit_available((int)$r['deposit_id']); ?>
            <tr>
                <td><a href="<?= e(url('deposits.php?view=' . (int)$r['deposit_id'])) ?>"><strong><?= e($r['deposit_number']) ?></strong></a>
                    <div class="small text-muted"><?= e(format_date($r['deposit_date'])) ?><?= $r['reservation_code'] ? ' &middot; ' . e($r['reservation_code']) : '' ?></div></td>
                <td><?= e($r['full_name']) ?><div class="small text-muted"><?= e($r['guest_code']) ?></div></td>
                <td><?= e(PAYMENT_METHODS[$r['payment_method']] ?? '') ?><div class="small text-muted"><?= e($r['reference_number'] ?? '') ?></div></td>
                <td class="amount"><?= e(peso($r['amount_received'])) ?></td>
                <td class="amount"><?= e(peso($r['amount_applied'])) ?></td>
                <td class="amount"><?= e(peso($r['amount_refunded'])) ?></td>
                <td class="amount"><strong><?= e(peso($r['remaining_amount'])) ?></strong></td>
                <td><?= status_badge($r['status']) ?></td>
                <td class="text-end text-nowrap">
                    <?php if ($avail > 0): ?>
                        <button class="btn btn-outline-primary btn-sm apply-btn" data-bs-toggle="modal" data-bs-target="#applyModal"
                            data-id="<?= (int)$r['deposit_id'] ?>" data-guest="<?= (int)$r['guest_id'] ?>" data-avail="<?= e(number_format($avail, 2, '.', '')) ?>"
                            data-desc="<?= e($r['deposit_number'] . ' - ' . $r['full_name'] . ' - available ' . peso($avail)) ?>">Apply</button>
                        <?php if (has_permission('refunds.request')): ?>
                            <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('refunds.php?deposit=' . (int)$r['deposit_id'])) ?>">Refund</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<?= render_pager($total, $page, $perPage) ?>

<div class="modal fade" id="depositModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="record"><input type="hidden" name="return" value="<?= e($returnQs) ?>">
        <div class="modal-header"><h5 class="modal-title">Record guest deposit</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-12"><label class="form-label">Guest</label>
                <select name="guest_id" id="depGuest" class="form-select" required><option value="">- select guest -</option>
                    <?php foreach ($guests as $g): ?><option value="<?= (int)$g['guest_id'] ?>"><?= e($g['guest_code'] . ' - ' . $g['full_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label">Reservation (optional)</label>
                <select name="reservation_id" id="depRes" class="form-select"><option value="0">- none -</option>
                    <?php foreach ($reservations as $rv): ?><option value="<?= (int)$rv['reservation_id'] ?>" data-guest="<?= (int)$rv['guest_id'] ?>"><?= e($rv['reservation_code'] . ' (check-in ' . format_date($rv['check_in'], 'M d, Y') . ')') ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label">Amount (&#8369;)</label><input type="number" name="amount" step="0.01" min="0.01" class="form-control" required></div>
            <div class="col-6"><label class="form-label">Date received</label><input type="date" name="deposit_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
            <div class="col-6"><label class="form-label">Method</label>
                <select name="payment_method" class="form-select" required><?php foreach (PAYMENT_METHODS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label">Reference</label><input type="text" name="reference_number" maxlength="60" class="form-control" placeholder="Required unless cash"></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save deposit</button></div>
    </form></div>
</div>

<div class="modal fade" id="applyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="apply"><input type="hidden" name="return" value="<?= e($returnQs) ?>"><input type="hidden" name="deposit_id" id="apId">
        <div class="modal-header"><h5 class="modal-title">Apply deposit to an invoice</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <p class="fw-semibold" id="apDesc"></p>
            <div class="mb-3"><label class="form-label">Invoice (same guest)</label>
                <select name="invoice_id" id="apInv" class="form-select" required><option value="">- select invoice -</option>
                    <?php foreach ($payable as $p): ?><option value="<?= (int)$p['invoice_id'] ?>" data-guest="<?= (int)$p['guest_id'] ?>" data-avail="<?= e(number_format((float)$p['available'], 2, '.', '')) ?>"><?= e($p['invoice_number'] . ' - payable ' . peso($p['available'])) ?></option><?php endforeach; ?></select>
                <div class="form-text">Only invoices of the deposit's guest are shown.</div></div>
            <label class="form-label">Amount to apply (&#8369;)</label><input type="number" name="amount" id="apAmt" step="0.01" min="0.01" class="form-control" required>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Apply deposit</button></div>
    </form></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Reservation list follows the chosen guest
    var g = document.getElementById('depGuest'), rs = document.getElementById('depRes');
    g.addEventListener('change', function () {
        rs.value = '0';
        Array.prototype.forEach.call(rs.options, function (o) { if (o.dataset.guest) { o.hidden = o.dataset.guest !== g.value; } });
    });
    Array.prototype.forEach.call(rs.options, function (o) { if (o.dataset.guest) { o.hidden = true; } });

    // Apply modal: only that guest's invoices, amount defaults to the smaller of the two limits
    var inv = document.getElementById('apInv'), amt = document.getElementById('apAmt'), cur = {guest: '0', avail: 0};
    document.querySelectorAll('.apply-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            cur = {guest: b.dataset.guest, avail: parseFloat(b.dataset.avail)};
            document.getElementById('apId').value = b.dataset.id;
            document.getElementById('apDesc').textContent = b.dataset.desc;
            inv.value = ''; amt.value = '';
            Array.prototype.forEach.call(inv.options, function (o) { if (o.dataset.guest) { o.hidden = o.dataset.guest !== cur.guest; } });
        });
    });
    inv.addEventListener('change', function () {
        var o = inv.options[inv.selectedIndex];
        if (o && o.dataset.avail) { amt.value = Math.min(cur.avail, parseFloat(o.dataset.avail)).toFixed(2); }
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
