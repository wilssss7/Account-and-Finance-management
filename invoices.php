<?php
/**
 * invoices.php - Invoice Generation (module 4) and Invoice Numbering (module 5)
 * Numbers come from document_sequences (row lock) and are also protected by a UNIQUE key.
 * Issued invoices are never deleted; they can only be cancelled with a documented reason.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('invoices.manage');

$pdo = db();
$me  = current_user();

/* ============================ POST actions ============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'generate') {
            $folioId = (int)($_POST['folio_id'] ?? 0);
            $issue   = (string)($_POST['issue_date'] ?? date('Y-m-d'));
            if (!valid_date($issue) || $issue > date('Y-m-d')) {
                throw new RuntimeException('The issue date must be a valid date that is not in the future.');
            }
            $dueDays = max(0, (int)get_setting('invoice_due_days', '7'));
            $due = (string)($_POST['due_date'] ?? '');
            if (!valid_date($due) || $due < $issue) {
                $due = date('Y-m-d', strtotime($issue . " +$dueDays days"));
            }

            $pdo->beginTransaction();
            $folio = db_one('SELECT f.*, g.guest_type FROM guest_folios f JOIN guests g ON g.guest_id = f.guest_id
                             WHERE f.folio_id = ? FOR UPDATE', [$folioId]);
            if (!$folio) { throw new RuntimeException('Folio not found.'); }
            if (!in_array($folio['status'], ['open', 'verified'], true)
                || db_value("SELECT COUNT(*) FROM invoices WHERE folio_id = ? AND status <> 'cancelled'", [$folioId]) > 0) {
                throw new RuntimeException('This folio already has an invoice.');
            }
            $charges = db_all('SELECT * FROM folio_charges WHERE folio_id = ? ORDER BY charge_date, charge_id FOR UPDATE', [$folioId]);
            if (!$charges) { throw new RuntimeException('The folio has no charges to invoice.'); }
            $taxable = 0.0; $subtotal = 0.0;
            foreach ($charges as $c) {
                if ($c['verification_status'] !== 'verified') {
                    throw new RuntimeException('Every charge must be verified before invoicing. Verify or remove: ' . $c['description']);
                }
                $subtotal += (float)$c['amount'];
                if ($c['is_taxable']) { $taxable += (float)$c['amount']; }
            }
            $periodId = require_open_period($issue);
            $svcRate  = active_rate('service_charge', $issue);
            $taxRate  = active_rate('tax', $issue);
            $t        = calc_invoice_totals($taxable, $subtotal, $svcRate, $taxRate);
            $number   = next_doc_number('INV', (int)date('Y', strtotime($issue)));

            $pdo->prepare("INSERT INTO invoices (invoice_number, folio_id, guest_id, period_id, customer_type, issue_date, due_date,
                    subtotal, service_rate_applied, service_charge_amount, tax_rate_applied, tax_amount, discount_amount, adjustment_amount,
                    total_amount, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, 'issued', ?)")
                ->execute([$number, $folioId, (int)$folio['guest_id'], $periodId, $folio['guest_type'] === 'corporate' ? 'corporate' : 'guest',
                    $issue, $due, round($subtotal, 2), $svcRate, $t['service'], $taxRate, $t['tax'], $t['total'], (int)$me['id']]);
            $invoiceId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare('INSERT INTO invoice_items (invoice_id, folio_charge_id, description, quantity, unit_price, is_taxable) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($charges as $c) {
                $ins->execute([$invoiceId, (int)$c['charge_id'], $c['description'], $c['quantity'], $c['unit_price'], (int)$c['is_taxable']]);
            }
            $pdo->prepare("UPDATE guest_folios SET status = 'invoiced' WHERE folio_id = ?")->execute([$folioId]);
            audit_log('invoice_issued', 'invoices', $invoiceId, null,
                ['invoice_number' => $number, 'folio' => $folio['folio_number'], 'subtotal' => round($subtotal, 2), 'total_amount' => $t['total']]);
            $pdo->commit();
            set_flash('success', "Invoice $number was generated.");
            redirect('invoices.php?view=' . $invoiceId);
        }

        if ($action === 'cancel') {
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            $reason    = clean_text($_POST['reason'] ?? '', 255);
            if (mb_strlen($reason) < 5) { throw new RuntimeException('Enter a cancellation reason (at least 5 characters).'); }
            $pdo->beginTransaction();
            $inv = db_one('SELECT * FROM invoices WHERE invoice_id = ? FOR UPDATE', [$invoiceId]);
            if (!$inv) { throw new RuntimeException('Invoice not found.'); }
            if ($inv['status'] === 'cancelled') { throw new RuntimeException('This invoice is already cancelled.'); }
            require_open_period_id((int)$inv['period_id']);
            $bal = invoice_balance($invoiceId);
            if ((float)$bal['verified_paid'] > 0 || (float)$bal['deposit_applied'] > 0) {
                throw new RuntimeException('An invoice with payments cannot be cancelled. Use a refund or a billing adjustment instead.');
            }
            if (pending_payment_sum($invoiceId) > 0) {
                throw new RuntimeException('Reject the pending payment(s) on this invoice before cancelling it.');
            }
            $pdo->prepare("UPDATE invoices SET status = 'cancelled', cancellation_reason = ? WHERE invoice_id = ?")->execute([$reason, $invoiceId]);
            if ($inv['folio_id']) {
                $pdo->prepare("UPDATE guest_folios SET status = 'verified' WHERE folio_id = ? AND status = 'invoiced'")->execute([(int)$inv['folio_id']]);
            }
            audit_log('invoice_cancelled', 'invoices', $invoiceId, ['status' => $inv['status']], ['status' => 'cancelled'], $reason);
            $pdo->commit();
            set_flash('success', 'Invoice ' . $inv['invoice_number'] . ' was cancelled. Its number is kept in the history.');
            redirect('invoices.php');
        }
        throw new RuntimeException('Unknown action.');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The action could not be completed. Please try again.');
        redirect('invoices.php');
    }
}

/* ========================== Printable invoice ========================== */
$viewId = (int)($_GET['view'] ?? 0);
if ($viewId > 0) {
    $r2 = revision2_ready();   // the new stay columns exist only after the revision 2 migration
    $inv = db_one(
        'SELECT i.*, g.guest_code, g.full_name, g.company_name, g.email, g.phone, g.address, b.verified_paid, b.deposit_applied,
                b.outstanding_balance, b.payment_status, f.folio_number, r.reservation_code, r.check_in, r.check_out, rm.room_number' . ($r2 ? ', f.checkin_at, f.expected_checkout_at, f.actual_checkout_at, f.hourly_rate_applied' : '') . '
         FROM invoices i JOIN guests g ON g.guest_id = i.guest_id JOIN v_invoice_balances b ON b.invoice_id = i.invoice_id
         LEFT JOIN guest_folios f ON f.folio_id = i.folio_id LEFT JOIN reservations r ON r.reservation_id = f.reservation_id
         LEFT JOIN rooms rm ON rm.room_id = ' . ($r2 ? 'COALESCE(f.room_id, r.room_id)' : 'r.room_id') . ' WHERE i.invoice_id = ?', [$viewId]);
    if (!$inv) {
        set_flash('danger', 'Invoice not found.');
        redirect('invoices.php');
    }
    $items = db_all('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY item_id', [$viewId]);
    $pays  = db_all('SELECT p.*, u.full_name AS recorder FROM payments p JOIN users u ON u.user_id = p.recorded_by
                     WHERE p.invoice_id = ? ORDER BY p.payment_date, p.payment_id', [$viewId]);

    $pageTitle = 'Invoice ' . $inv['invoice_number'];
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="d-flex flex-wrap gap-2 mb-3 no-print">
        <a href="<?= e(url('invoices.php')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>All invoices</a>
        <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print / save as PDF</button>
        <?php if (has_permission('payments.record') && $inv['payment_status'] !== 'paid' && $inv['payment_status'] !== 'cancelled'): ?>
            <a href="<?= e(url('payments.php?invoice=' . (int)$viewId)) ?>" class="btn btn-secondary btn-sm"><i class="bi bi-credit-card me-1"></i>Record payment</a>
        <?php endif; ?>
    </div>

    <div class="invoice-doc">
        <div class="d-flex justify-content-between flex-wrap gap-3 mb-4">
            <?= render_document_brand() ?>
            <div class="text-md-end">
                <h2>INVOICE</h2>
                <div class="fw-semibold"><?= e($inv['invoice_number']) ?></div>
                <div><?= status_badge($inv['payment_status']) ?></div>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="meta-label">Billed to</div>
                <div class="fw-semibold"><?= e($inv['full_name']) ?></div>
                <?php if ($inv['company_name']): ?><div><?= e($inv['company_name']) ?></div><?php endif; ?>
                <div class="small"><?= e($inv['guest_code']) ?></div>
                <?php if ($inv['address']): ?><div class="small"><?= e($inv['address']) ?></div><?php endif; ?>
                <?php if ($inv['email']): ?><div class="small"><?= e($inv['email']) ?></div><?php endif; ?>
            </div>
            <div class="col-md-6">
                <div class="row g-2 small">
                    <div class="col-6"><div class="meta-label">Issue date</div><?= e(format_date($inv['issue_date'])) ?></div>
                    <div class="col-6"><div class="meta-label">Due date</div><?= e(format_date($inv['due_date'])) ?></div>
                    <div class="col-6"><div class="meta-label">Folio</div><?= e($inv['folio_number'] ?? '-') ?></div>
                    <div class="col-6"><div class="meta-label">Room</div><?= e($inv['room_number'] ?? '-') ?></div>
                    <div class="col-12"><div class="meta-label">Stay</div><?= $inv['check_in'] ? e(format_date($inv['check_in']) . ' to ' . format_date($inv['check_out'])) : '-' ?></div>
                    <?php if (!empty($inv['checkin_at'])): ?>
                        <div class="col-12"><div class="meta-label">Time in and out</div>Check-in <?= e(format_date($inv['checkin_at'], 'M d, Y g:i A')) ?> &ndash;
                            checkout <?= e(format_date($inv['actual_checkout_at'] ?: $inv['expected_checkout_at'], 'M d, Y g:i A')) ?><?= $inv['actual_checkout_at'] ? '' : ' (expected)' ?>
                            &middot; hourly rate <?= e(peso($inv['hourly_rate_applied'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="table-responsive mb-3">
            <table class="table">
                <thead><tr><th>Description</th><th class="amount">Qty</th><th class="amount">Unit price</th><th class="amount">Amount</th></tr></thead>
                <tbody>
                <?php if (!$items): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">This invoice has no line items recorded.</td></tr>
                <?php endif; ?>
                <?php foreach ($items as $it): ?>
                    <tr><td><?= e($it['description']) ?></td>
                        <td class="amount"><?= e(rtrim(rtrim(number_format((float)$it['quantity'], 2), '0'), '.')) ?></td>
                        <td class="amount"><?= e(peso($it['unit_price'])) ?></td>
                        <td class="amount"><?= e(peso($it['line_total'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <table class="table invoice-totals">
            <tr><td>Subtotal</td><td class="amount"><?= e(peso($inv['subtotal'])) ?></td></tr>
            <tr><td>Service charge (<?= e(rtrim(rtrim(number_format((float)$inv['service_rate_applied'], 2), '0'), '.')) ?>%)</td><td class="amount"><?= e(peso($inv['service_charge_amount'])) ?></td></tr>
            <tr><td>Tax (<?= e(rtrim(rtrim(number_format((float)$inv['tax_rate_applied'], 2), '0'), '.')) ?>%)</td><td class="amount"><?= e(peso($inv['tax_amount'])) ?></td></tr>
            <?php if ((float)$inv['discount_amount'] > 0): ?><tr><td>Discount</td><td class="amount">-<?= e(peso($inv['discount_amount'])) ?></td></tr><?php endif; ?>
            <?php if ((float)$inv['adjustment_amount'] != 0): ?><tr><td>Adjustments</td><td class="amount"><?= e(peso($inv['adjustment_amount'])) ?></td></tr><?php endif; ?>
            <tr class="grand"><td>Total</td><td class="amount"><?= e(peso($inv['total_amount'])) ?></td></tr>
            <tr><td>Payments received (net of refunds)</td><td class="amount">-<?= e(peso($inv['verified_paid'])) ?></td></tr>
            <?php if ((float)$inv['deposit_applied'] > 0): ?><tr><td>Deposit applied</td><td class="amount">-<?= e(peso($inv['deposit_applied'])) ?></td></tr><?php endif; ?>
        </table>
        <div class="invoice-balance-box d-flex justify-content-between align-items-center mt-2">
            <span class="fw-semibold">Outstanding balance</span>
            <span class="h4 mb-0"><?= e(peso($inv['payment_status'] === 'cancelled' ? 0 : $inv['outstanding_balance'])) ?></span>
        </div>

        <?php if ($pays): ?>
            <h3 class="card-title-serif mt-4 mb-2">Payment history</h3>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Payment</th><th>Date</th><th>Method</th><th>Reference</th><th class="amount">Amount</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($pays as $p): ?>
                        <tr><td><?= e($p['payment_number']) ?></td><td><?= e(format_date($p['payment_date'])) ?></td>
                            <td><?= e(PAYMENT_METHODS[$p['payment_method']] ?? $p['payment_method']) ?></td>
                            <td><?= e($p['reference_number'] ?? '-') ?></td><td class="amount"><?= e(peso($p['amount'])) ?></td>
                            <td><?= status_badge($p['status']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ($inv['status'] === 'cancelled'): ?>
            <div class="alert alert-danger mt-3 mb-0">This invoice was cancelled. Reason: <?= e($inv['cancellation_reason']) ?></div>
        <?php endif; ?>
        <p class="small text-muted text-center mt-4 mb-0">Thank you for staying with us.</p>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

/* ============================== List view ============================== */
$q      = clean_text($_GET['q'] ?? '', 60);
$stat   = (string)($_GET['status'] ?? '');
$from   = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$to     = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
$page   = current_page_number();
$perPage = 15;

$where = ['1 = 1']; $params = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(i.invoice_number LIKE ? OR g.full_name LIKE ? OR g.guest_code LIKE ?)';
    array_push($params, $like, $like, $like);
}
if (in_array($stat, ['unpaid', 'partially_paid', 'paid', 'overdue', 'cancelled'], true)) { $where[] = 'b.payment_status = ?'; $params[] = $stat; }
if ($from !== '') { $where[] = 'i.issue_date >= ?'; $params[] = $from; }
if ($to !== '')   { $where[] = 'i.issue_date <= ?'; $params[] = $to; }
$fromSql = 'FROM invoices i JOIN guests g ON g.guest_id = i.guest_id JOIN v_invoice_balances b ON b.invoice_id = i.invoice_id WHERE ' . implode(' AND ', $where);

$total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
$sums = db_one("SELECT COALESCE(SUM(CASE WHEN b.payment_status <> 'cancelled' THEN i.total_amount END), 0) AS billed,
                       COALESCE(SUM(CASE WHEN b.payment_status NOT IN ('cancelled', 'paid') THEN b.outstanding_balance END), 0) AS outstanding $fromSql", $params);
$rows = db_all("SELECT i.invoice_id, i.invoice_number, i.issue_date, i.due_date, i.total_amount, i.status, g.full_name, g.guest_code,
                       b.verified_paid, b.deposit_applied, b.outstanding_balance, b.payment_status
                $fromSql ORDER BY i.invoice_id DESC", $params, $perPage, ($page - 1) * $perPage);

$eligible = db_all(
    "SELECT f.folio_id, f.folio_number, g.full_name, SUM(c.amount) AS total
     FROM guest_folios f JOIN guests g ON g.guest_id = f.guest_id JOIN folio_charges c ON c.folio_id = f.folio_id
     WHERE f.status IN ('open', 'verified')
       AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.folio_id = f.folio_id AND i.status <> 'cancelled')
     GROUP BY f.folio_id, f.folio_number, g.full_name
     HAVING SUM(c.verification_status <> 'verified') = 0
     ORDER BY f.folio_id");

if (($_GET['export'] ?? '') === 'csv') {
    require_permission('exports.generate');
    $data = db_all("SELECT i.invoice_number, (SELECT fo.folio_number FROM guest_folios fo WHERE fo.folio_id = i.folio_id) AS folio_number, g.guest_code, g.full_name, i.customer_type,
                           i.issue_date, i.due_date, i.subtotal, i.service_charge_amount, i.tax_amount, i.discount_amount, i.adjustment_amount, i.total_amount,
                           b.verified_paid, b.deposit_applied, b.outstanding_balance, b.payment_status
                    $fromSql ORDER BY i.invoice_id DESC", $params);
    $rows = []; $live = [];
    foreach ($data as $d) {
        $cancelled = $d['payment_status'] === 'cancelled';
        $row = [$d['invoice_number'], $d['folio_number'], $d['guest_code'], $d['full_name'], csv_label($d['customer_type']), $d['issue_date'], $d['due_date'], $d['subtotal'], $d['service_charge_amount'],
                $d['tax_amount'], $d['discount_amount'], $d['adjustment_amount'], $d['total_amount'], $d['verified_paid'], $d['deposit_applied'], $cancelled ? null : $d['outstanding_balance'], csv_label($d['payment_status'])];
        $rows[] = $row;
        if (!$cancelled) { $live[] = $row; }
    }
    csv_download('invoices_report', 'Invoices Report', ['Search' => $q, 'Status' => csv_label($stat), 'Issued from' => $from, 'Issued to' => $to],
        [['Invoice No.', 'text'], ['Folio No.', 'text'], ['Guest ID', 'text'], ['Guest name', 'text'], ['Customer type', 'text'], ['Issue date', 'date'], ['Due date', 'date'],
         ['Subtotal before tax (PHP)', 'money', true], ['Service charge (PHP)', 'money', true], ['Tax (PHP)', 'money', true], ['Discounts (PHP)', 'money', true], ['Adjustments (PHP)', 'money', true],
         ['Total incl. tax (PHP)', 'money', true], ['Payments received, net of refunds (PHP)', 'money', true], ['Deposits applied (PHP)', 'money', true], ['Outstanding balance (PHP)', 'money', true],
         ['Status', 'text']], $rows, $live, 'TOTAL (cancelled invoices excluded)');
}

$pageTitle = 'Invoices';
include __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
        <span class="summary-chip"><strong><?= $total ?></strong> invoice(s)</span>
        <span class="summary-chip">Billed <strong><?= e(peso($sums['billed'])) ?></strong></span>
        <span class="summary-chip">Outstanding <strong><?= e(peso($sums['outstanding'])) ?></strong></span>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#generateModal"><i class="bi bi-file-earmark-plus me-1"></i>Generate invoice</button>
</div>

<div class="mb-3 no-print"><?= render_export_menu('?' . qs(['export' => 'csv', 'page' => '']), has_permission('exports.generate')) ?></div>
<form method="get" class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label small mb-1">Search</label>
            <input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Invoice number or guest"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm"><option value="">All</option>
                <?php foreach (['unpaid' => 'Unpaid', 'partially_paid' => 'Partially paid', 'paid' => 'Paid', 'overdue' => 'Overdue', 'cancelled' => 'Cancelled'] as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= $stat === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Issued from</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">Issued to</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-2 d-grid"><button class="btn btn-primary btn-sm" type="submit">Filter</button></div>
    </div>
</form>

<div class="app-card">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Invoice</th><th>Guest</th><th>Issued</th><th>Due</th><th class="amount">Total</th><th class="amount">Paid</th><th class="amount">Balance</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No invoices match your filters.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): $cancelled = $r['payment_status'] === 'cancelled'; ?>
                <tr class="<?= $cancelled ? 'row-locked' : '' ?>">
                    <td><a href="<?= e(url('invoices.php?view=' . (int)$r['invoice_id'])) ?>"><strong><?= e($r['invoice_number']) ?></strong></a></td>
                    <td><?= e($r['full_name']) ?><div class="small text-muted"><?= e($r['guest_code']) ?></div></td>
                    <td><?= e(format_date($r['issue_date'])) ?></td>
                    <td><?= e(format_date($r['due_date'])) ?></td>
                    <td class="amount"><?= e(peso($r['total_amount'])) ?></td>
                    <td class="amount"><?= e(peso($r['verified_paid'] + $r['deposit_applied'])) ?></td>
                    <td class="amount"><strong><?= $cancelled ? '-' : e(peso($r['outstanding_balance'])) ?></strong></td>
                    <td><?= status_badge($r['payment_status']) ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('invoices.php?view=' . (int)$r['invoice_id'])) ?>">View</a>
                        <?php if (!$cancelled && (float)$r['verified_paid'] == 0 && (float)$r['deposit_applied'] == 0): ?>
                            <button class="btn btn-outline-secondary btn-sm cancel-btn" data-bs-toggle="modal" data-bs-target="#cancelModal"
                                data-id="<?= (int)$r['invoice_id'] ?>" data-num="<?= e($r['invoice_number']) ?>">Cancel</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= render_pager($total, $page, $perPage) ?>

<div class="modal fade" id="generateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="generate">
        <div class="modal-header"><h5 class="modal-title">Generate invoice</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <?php if (!$eligible): ?>
                <div class="alert alert-info mb-0">No folios are ready. A folio is ready when it has charges and all of them are verified. See <a href="<?= e(url('guest-billing.php')) ?>">guest billing</a>.</div>
            <?php else: ?>
                <div class="mb-3"><label class="form-label">Guest folio</label>
                    <select name="folio_id" class="form-select" required>
                        <?php foreach ($eligible as $f): ?><option value="<?= (int)$f['folio_id'] ?>"><?= e($f['folio_number'] . ' - ' . $f['full_name'] . ' - charges ' . peso($f['total'])) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="row g-3">
                    <div class="col-6"><label class="form-label">Issue date</label><input type="date" name="issue_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
                    <div class="col-6"><label class="form-label">Due date</label><input type="date" name="due_date" class="form-control" value="<?= e(date('Y-m-d', strtotime('+' . max(0, (int)get_setting('invoice_due_days', '7')) . ' days'))) ?>"></div>
                </div>
                <div class="form-text mt-2">Tax and service charge use the rates active on the issue date and are saved on the invoice.</div>
            <?php endif; ?>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            <?php if ($eligible): ?><button class="btn btn-primary" type="submit">Generate</button><?php endif; ?></div>
    </form></div>
</div>

<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="invoice_id" id="cxId">
        <div class="modal-header"><h5 class="modal-title">Cancel invoice <span id="cxNum"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <p class="small text-muted">The invoice is kept in the records as cancelled and its number is not reused.</p>
            <label class="form-label" for="cxReason">Reason</label>
            <textarea class="form-control" id="cxReason" name="reason" rows="3" maxlength="255" required></textarea>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep invoice</button><button class="btn btn-danger" type="submit">Cancel invoice</button></div>
    </form></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.cancel-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('cxId').value = b.dataset.id;
            document.getElementById('cxNum').textContent = b.dataset.num;
        });
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
