<?php
/**
 * payments.php - Payment Recording (modules 6-10), Partial Payments (12), Outstanding Balances (13)
 * Cash, card, GCash, Maya and bank transfer payments are recorded manually (no live gateway).
 * New payments start as "pending"; only verified payments reduce an invoice balance.
 * Full card numbers and CVV codes are never stored - only the card type and last 4 digits.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('payments.record');

$pdo = db();
$me  = current_user();
$cardTypes = ['Visa', 'Mastercard', 'JCB', 'American Express', 'Other'];

/* ============================ POST: record payment ============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
    try {
        if ((string)($_POST['action'] ?? '') !== 'record_payment') {
            throw new RuntimeException('Unknown action.');
        }
        $method = (string)($_POST['payment_method'] ?? '');
        $date   = (string)($_POST['payment_date'] ?? '');
        $amount = parse_money($_POST['amount'] ?? '');
        $ref    = clean_text($_POST['reference_number'] ?? '', 60);
        $cashier = clean_text($_POST['cashier_name'] ?? '', 100);
        $cardType = clean_text($_POST['card_type'] ?? '', 30);
        $last4  = trim((string)($_POST['card_last4'] ?? ''));
        $bank   = clean_text($_POST['bank_name'] ?? '', 80);

        if (!isset(PAYMENT_METHODS[$method])) { throw new RuntimeException('Choose a payment method.'); }
        if ($amount === null) { throw new RuntimeException('Enter a valid payment amount greater than zero.'); }
        if (!valid_date($date) || $date > date('Y-m-d')) { throw new RuntimeException('The payment date must be a valid date that is not in the future.'); }
        if ($ref !== '' && preg_match('/^[\d\s-]{13,23}$/', $ref) && strlen(preg_replace('/\D/', '', $ref)) >= 13) {
            throw new RuntimeException('The reference looks like a full card number. Never enter card numbers; use the transaction or approval reference.');
        }
        $cashierVal = $cardVal = $last4Val = $bankVal = null;
        if ($method === 'cash') {
            if ($cashier === '') { throw new RuntimeException('Enter the cashier who received the cash.'); }
            $cashierVal = $cashier;
        } else {
            if ($ref === '') { throw new RuntimeException('Enter the reference number for this ' . PAYMENT_METHODS[$method] . ' payment.'); }
            if ($method === 'card') {
                if (!in_array($cardType, $cardTypes, true)) { throw new RuntimeException('Choose the card type.'); }
                if ($last4 !== '' && !preg_match('/^\d{4}$/', $last4)) { throw new RuntimeException('Enter only the last 4 digits of the card, or leave it blank.'); }
                $cardVal = $cardType; $last4Val = $last4 !== '' ? $last4 : null;
            }
            if ($method === 'bank_transfer') {
                if ($bank === '') { throw new RuntimeException('Enter the bank name for the transfer.'); }
                $bankVal = $bank;
            }
        }

        $pdo->beginTransaction();
        $inv = db_one('SELECT invoice_id, invoice_number, issue_date, status FROM invoices WHERE invoice_id = ? FOR UPDATE', [$invoiceId]);
        if (!$inv || in_array($inv['status'], ['draft', 'cancelled'], true)) {
            throw new RuntimeException('Choose an issued invoice that is not cancelled.');
        }
        if ($date < $inv['issue_date']) { throw new RuntimeException('The payment date cannot be earlier than the invoice issue date (' . format_date($inv['issue_date']) . ').'); }
        $bal = invoice_balance($invoiceId);
        $available = round((float)$bal['outstanding_balance'] - pending_payment_sum($invoiceId), 2);
        if ($available <= 0) {
            throw new RuntimeException('Nothing is left to pay on this invoice. Pending payments already cover the balance.');
        }
        if ($amount > $available + 0.004) {
            throw new RuntimeException('The amount exceeds the payable balance of ' . peso($available) . ' (outstanding balance minus pending payments).');
        }
        if ($method !== 'cash' && db_value("SELECT COUNT(*) FROM payments WHERE payment_method = ? AND reference_number = ? AND status <> 'rejected'", [$method, $ref]) > 0) {
            throw new RuntimeException('This reference number was already recorded for another ' . PAYMENT_METHODS[$method] . ' payment.');
        }
        $periodId = require_open_period($date);
        $number   = next_doc_number('PAY', (int)date('Y', strtotime($date)));
        $pdo->prepare("INSERT INTO payments (payment_number, invoice_id, period_id, amount, payment_date, payment_method, reference_number,
                cashier_name, card_type, card_last4, bank_name, status, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)")
            ->execute([$number, $invoiceId, $periodId, $amount, $date, $method, $ref !== '' ? $ref : null, $cashierVal, $cardVal, $last4Val, $bankVal, (int)$me['id']]);
        $paymentId = (int)$pdo->lastInsertId();
        audit_log('payment_recorded', 'payments', $paymentId, null,
            ['payment_number' => $number, 'invoice' => $inv['invoice_number'], 'amount' => $amount, 'method' => $method]);
        $pdo->commit();
        set_flash('success', "Payment $number of " . peso($amount) . ' was recorded and is waiting for verification.');
        redirect('payments.php');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The payment could not be saved. Please try again.');
        redirect('payments.php' . ($invoiceId > 0 ? '?invoice=' . $invoiceId : ''));
    }
}

/* ================================ Filters ================================ */
$tab   = (($_GET['tab'] ?? '') === 'outstanding') ? 'outstanding' : 'payments';
$q     = clean_text($_GET['q'] ?? '', 60);
$from  = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$to    = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
$page  = current_page_number();
$perPage = 15;
$prefillInvoice = (int)($_GET['invoice'] ?? 0);

if ($tab === 'payments') {
    $method = (string)($_GET['method'] ?? '');
    $stat   = (string)($_GET['status'] ?? '');
    $where = ['1 = 1']; $params = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(p.payment_number LIKE ? OR p.reference_number LIKE ? OR i.invoice_number LIKE ? OR g.full_name LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    if (isset(PAYMENT_METHODS[$method])) { $where[] = 'p.payment_method = ?'; $params[] = $method; }
    if (in_array($stat, ['pending', 'verified', 'rejected'], true)) { $where[] = 'p.status = ?'; $params[] = $stat; }
    if ($from !== '') { $where[] = 'p.payment_date >= ?'; $params[] = $from; }
    if ($to !== '')   { $where[] = 'p.payment_date <= ?'; $params[] = $to; }
    $fromSql = 'FROM payments p JOIN invoices i ON i.invoice_id = p.invoice_id JOIN guests g ON g.guest_id = i.guest_id
                JOIN users u ON u.user_id = p.recorded_by WHERE ' . implode(' AND ', $where);
    $total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
    $sums = db_one("SELECT COALESCE(SUM(CASE WHEN p.status = 'verified' THEN p.amount END), 0) AS verified,
                           COALESCE(SUM(CASE WHEN p.status = 'pending' THEN p.amount END), 0) AS pending $fromSql", $params);
    $rows = db_all("SELECT p.*, i.invoice_number, g.full_name, u.full_name AS recorder $fromSql
                    ORDER BY p.payment_date DESC, p.payment_id DESC", $params, $perPage, ($page - 1) * $perPage);
} else {
    $stat = (string)($_GET['status'] ?? '');
    $where = ["b.payment_status IN ('unpaid', 'partially_paid', 'overdue')", 'b.outstanding_balance > 0']; $params = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(b.invoice_number LIKE ? OR g.full_name LIKE ?)';
        array_push($params, $like, $like);
    }
    if (in_array($stat, ['unpaid', 'partially_paid', 'overdue'], true)) { $where[] = 'b.payment_status = ?'; $params[] = $stat; }
    if ($from !== '') { $where[] = 'b.due_date >= ?'; $params[] = $from; }
    if ($to !== '')   { $where[] = 'b.due_date <= ?'; $params[] = $to; }
    $fromSql = 'FROM v_invoice_balances b JOIN guests g ON g.guest_id = b.guest_id WHERE ' . implode(' AND ', $where);
    $total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
    $sums = db_one("SELECT COALESCE(SUM(b.outstanding_balance), 0) AS outstanding,
                           COALESCE(SUM(CASE WHEN b.payment_status = 'overdue' THEN b.outstanding_balance END), 0) AS overdue $fromSql", $params);
    $rows = db_all("SELECT b.*, g.full_name, g.guest_code $fromSql ORDER BY b.due_date, b.invoice_id", $params, $perPage, ($page - 1) * $perPage);
}

$payable = db_all(
    "SELECT t.* FROM (
         SELECT b.invoice_id, b.invoice_number, g.full_name, b.outstanding_balance, b.due_date,
                COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = b.invoice_id AND p.status = 'pending'), 0) AS pending_sum
         FROM v_invoice_balances b JOIN guests g ON g.guest_id = b.guest_id
         WHERE b.payment_status IN ('unpaid', 'partially_paid', 'overdue')
     ) t
     WHERE (t.outstanding_balance - t.pending_sum) > 0
     ORDER BY t.due_date, t.invoice_id");

if (($_GET['export'] ?? '') === 'csv') {
    require_permission('exports.generate');
    if ($tab === 'payments') {
        $data = db_all("SELECT p.payment_number, p.payment_date, i.invoice_number, (SELECT rc.receipt_number FROM receipts rc WHERE rc.payment_id = p.payment_id) AS receipt_number,
                               g.guest_code, g.full_name, p.payment_method, p.cashier_name, p.card_type, p.card_last4, p.bank_name, p.reference_number, p.amount, p.status, u.full_name AS recorder
                        $fromSql ORDER BY p.payment_date DESC, p.payment_id DESC", $params);
        $rows = []; $verified = [];
        foreach ($data as $d) {
            $details = $d['payment_method'] === 'cash' ? ($d['cashier_name'] ? 'Cashier: ' . $d['cashier_name'] : '')
                     : ($d['payment_method'] === 'card' ? trim(($d['card_type'] ?? '') . ($d['card_last4'] ? ' ending ' . $d['card_last4'] : ''))
                     : ($d['payment_method'] === 'bank_transfer' ? (string)$d['bank_name'] : ''));
            $row = [$d['payment_number'], $d['payment_date'], $d['invoice_number'], $d['receipt_number'], $d['guest_code'], $d['full_name'], PAYMENT_METHODS[$d['payment_method']] ?? csv_label($d['payment_method']),
                    $details, $d['reference_number'], $d['amount'], csv_label($d['status']), $d['recorder']];
            $rows[] = $row;
            if ($d['status'] === 'verified') { $verified[] = $row; }
        }
        csv_download('payments_report', 'Payments Report', ['Search' => $q, 'Method' => isset(PAYMENT_METHODS[$method]) ? PAYMENT_METHODS[$method] : '', 'Status' => csv_label($stat), 'Paid from' => $from, 'Paid to' => $to],
            [['Payment No.', 'text'], ['Payment date', 'date'], ['Invoice No.', 'text'], ['Receipt No.', 'text'], ['Guest ID', 'text'], ['Guest name', 'text'], ['Payment method', 'text'],
             ['Method details', 'text'], ['Reference No.', 'text'], ['Amount (PHP)', 'money', true], ['Status', 'text'], ['Recorded by', 'text']], $rows, $verified, 'TOTAL (verified payments only)');
    }
    $data = db_all("SELECT b.invoice_number, g.guest_code, g.full_name, b.issue_date, b.due_date, b.total_amount, b.verified_paid, b.deposit_applied, b.outstanding_balance, b.payment_status
                    $fromSql ORDER BY b.due_date, b.invoice_id", $params);
    $rows = [];
    foreach ($data as $d) {
        $rows[] = [$d['invoice_number'], $d['guest_code'], $d['full_name'], $d['issue_date'], $d['due_date'], $d['payment_status'] === 'overdue' ? days_past_due($d['due_date']) : 0,
                   $d['total_amount'], $d['verified_paid'], $d['deposit_applied'], $d['outstanding_balance'], csv_label($d['payment_status'])];
    }
    csv_download('outstanding_balances_report', 'Outstanding Balances Report', ['Search' => $q, 'Status' => csv_label($stat), 'Due from' => $from, 'Due to' => $to],
        [['Invoice No.', 'text'], ['Guest ID', 'text'], ['Guest name', 'text'], ['Issue date', 'date'], ['Due date', 'date'], ['Days overdue', 'number'], ['Total incl. tax (PHP)', 'money', true],
         ['Payments received (PHP)', 'money', true], ['Deposits applied (PHP)', 'money', true], ['Outstanding balance (PHP)', 'money', true], ['Status', 'text']], $rows);
}

$pageTitle = 'Payments';
include __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <ul class="nav nav-tabs-soft nav border-0">
        <li class="nav-item"><a class="nav-link <?= $tab === 'payments' ? 'active' : '' ?>" href="<?= e(url('payments.php')) ?>">Payments</a></li>
        <li class="nav-item"><a class="nav-link <?= $tab === 'outstanding' ? 'active' : '' ?>" href="<?= e(url('payments.php?tab=outstanding')) ?>">Outstanding balances</a></li>
    </ul>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#paymentModal"><i class="bi bi-plus-lg me-1"></i>Record payment</button>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <span class="summary-chip"><strong><?= $total ?></strong> record(s)</span>
    <?php if ($tab === 'payments'): ?>
        <span class="summary-chip">Verified <strong><?= e(peso($sums['verified'])) ?></strong></span>
        <span class="summary-chip">Pending <strong><?= e(peso($sums['pending'])) ?></strong></span>
    <?php else: ?>
        <span class="summary-chip">Outstanding <strong><?= e(peso($sums['outstanding'])) ?></strong></span>
        <span class="summary-chip">Overdue <strong class="value-negative"><?= e(peso($sums['overdue'])) ?></strong></span>
    <?php endif; ?>
</div>

<div class="mb-3 no-print"><?= render_export_menu('?' . qs(['export' => 'csv', 'page' => '']), has_permission('exports.generate')) ?></div>
<form method="get" class="filter-bar mb-3">
    <?php if ($tab === 'outstanding'): ?><input type="hidden" name="tab" value="outstanding"><?php endif; ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small mb-1">Search</label>
            <input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="<?= $tab === 'payments' ? 'Payment, reference, invoice, guest' : 'Invoice or guest' ?>"></div>
        <?php if ($tab === 'payments'): ?>
            <div class="col-6 col-md-2"><label class="form-label small mb-1">Method</label>
                <select name="method" class="form-select form-select-sm"><option value="">All</option>
                    <?php foreach (PAYMENT_METHODS as $k => $v): ?><option value="<?= e($k) ?>" <?= $method === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm"><option value="">All</option>
                    <?php foreach (['pending', 'verified', 'rejected'] as $s): ?><option value="<?= e($s) ?>" <?= $stat === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-1">Paid from</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-1">Paid to</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <?php else: ?>
            <div class="col-6 col-md-3"><label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm"><option value="">All open</option>
                    <?php foreach (['unpaid' => 'Unpaid', 'partially_paid' => 'Partially paid', 'overdue' => 'Overdue'] as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $stat === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-1">Due from</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-1">Due to</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <?php endif; ?>
        <div class="col-6 col-md-1 d-grid"><button class="btn btn-primary btn-sm" type="submit">Filter</button></div>
    </div>
</form>

<div class="app-card">
    <div class="table-responsive">
    <?php if ($tab === 'payments'): ?>
        <table class="table table-hover">
            <thead><tr><th>Payment</th><th>Invoice / guest</th><th>Method</th><th>Reference</th><th class="amount">Amount</th><th>Status</th><th>Recorded by</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No payments match your filters.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['payment_number']) ?></strong><div class="small text-muted"><?= e(format_date($r['payment_date'])) ?></div></td>
                    <td><a href="<?= e(url('invoices.php?view=' . (int)$r['invoice_id'])) ?>"><?= e($r['invoice_number']) ?></a><div class="small text-muted"><?= e($r['full_name']) ?></div></td>
                    <td><?= e(PAYMENT_METHODS[$r['payment_method']] ?? $r['payment_method']) ?>
                        <div class="small text-muted">
                            <?php if ($r['payment_method'] === 'cash'): ?>Cashier: <?= e($r['cashier_name']) ?>
                            <?php elseif ($r['payment_method'] === 'card'): ?><?= e($r['card_type']) ?><?= $r['card_last4'] ? ' ending ' . e($r['card_last4']) : '' ?>
                            <?php elseif ($r['payment_method'] === 'bank_transfer'): ?><?= e($r['bank_name']) ?><?php endif; ?>
                        </div></td>
                    <td><?= e($r['reference_number'] ?? '-') ?></td>
                    <td class="amount"><strong><?= e(peso($r['amount'])) ?></strong></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="small"><?= e($r['recorder']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <table class="table table-hover">
            <thead><tr><th>Invoice</th><th>Guest</th><th>Issued</th><th>Due</th><th class="amount">Total</th><th class="amount">Paid</th><th class="amount">Balance</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No outstanding balances. Every invoice is settled.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r):
                $daysLate = $r['payment_status'] === 'overdue' ? (int)floor((strtotime(date('Y-m-d')) - strtotime($r['due_date'])) / 86400) : 0; ?>
                <tr>
                    <td><a href="<?= e(url('invoices.php?view=' . (int)$r['invoice_id'])) ?>"><strong><?= e($r['invoice_number']) ?></strong></a></td>
                    <td><?= e($r['full_name']) ?><div class="small text-muted"><?= e($r['guest_code']) ?></div></td>
                    <td><?= e(format_date($r['issue_date'])) ?></td>
                    <td><?= e(format_date($r['due_date'])) ?><?php if ($daysLate > 0): ?><div class="small value-negative"><?= $daysLate ?> day(s) late</div><?php endif; ?></td>
                    <td class="amount"><?= e(peso($r['total_amount'])) ?></td>
                    <td class="amount"><?= e(peso($r['verified_paid'] + $r['deposit_applied'])) ?></td>
                    <td class="amount"><strong><?= e(peso($r['outstanding_balance'])) ?></strong></td>
                    <td><?= status_badge($r['payment_status']) ?></td>
                    <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="<?= e(url('payments.php?invoice=' . (int)$r['invoice_id'])) ?>">Record payment</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</div>
<?= render_pager($total, $page, $perPage) ?>

<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><form method="post" class="modal-content" id="paymentForm" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="record_payment">
        <div class="modal-header"><h5 class="modal-title">Record payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <?php if (!$payable): ?>
                <div class="alert alert-info mb-0">There are no invoices waiting for payment.</div>
            <?php else: ?>
            <div class="row g-3">
                <div class="col-12"><label class="form-label" for="pInvoice">Invoice</label>
                    <select name="invoice_id" id="pInvoice" class="form-select" required>
                        <option value="">- select invoice -</option>
                        <?php foreach ($payable as $p): $avail = round((float)$p['outstanding_balance'] - (float)$p['pending_sum'], 2); ?>
                            <option value="<?= (int)$p['invoice_id'] ?>" data-available="<?= e(number_format($avail, 2, '.', '')) ?>" <?= $prefillInvoice === (int)$p['invoice_id'] ? 'selected' : '' ?>>
                                <?= e($p['invoice_number'] . ' - ' . $p['full_name'] . ' - payable ' . peso($avail)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Partial payments are allowed. The payable balance excludes payments still waiting for verification.</div></div>
                <div class="col-md-4"><label class="form-label" for="pAmount">Amount (&#8369;)</label><input type="number" step="0.01" min="0.01" name="amount" id="pAmount" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label">Payment date</label><input type="date" name="payment_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
                <div class="col-md-4"><label class="form-label" for="pMethod">Method</label>
                    <select name="payment_method" id="pMethod" class="form-select" required>
                        <?php foreach (PAYMENT_METHODS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>

                <div class="col-md-6 method-field" data-methods="cash"><label class="form-label">Cashier</label>
                    <input type="text" name="cashier_name" maxlength="100" class="form-control" value="<?= e($me['name']) ?>"></div>
                <div class="col-md-6 method-field d-none" data-methods="card"><label class="form-label">Card type</label>
                    <select name="card_type" class="form-select"><?php foreach ($cardTypes as $ct): ?><option><?= e($ct) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6 method-field d-none" data-methods="card"><label class="form-label">Last 4 digits (optional)</label>
                    <input type="text" name="card_last4" maxlength="4" inputmode="numeric" pattern="\d{4}" class="form-control" placeholder="1234">
                    <div class="form-text">Never enter the full card number or CVV.</div></div>
                <div class="col-md-6 method-field d-none" data-methods="bank_transfer"><label class="form-label">Bank name</label>
                    <input type="text" name="bank_name" maxlength="80" class="form-control"></div>
                <div class="col-md-6 method-field d-none" data-methods="card gcash maya bank_transfer"><label class="form-label">Reference number</label>
                    <input type="text" name="reference_number" maxlength="60" class="form-control" placeholder="Transaction / approval / transfer reference"></div>
                <div class="col-md-6 method-field" data-methods="cash"><label class="form-label">Receipt / reference (optional)</label>
                    <input type="text" name="reference_number" maxlength="60" class="form-control" placeholder="Optional"></div>
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <?php if ($payable): ?><button class="btn btn-primary" type="submit">Save payment</button><?php endif; ?></div>
    </form></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var method = document.getElementById('pMethod'), inv = document.getElementById('pInvoice'), amt = document.getElementById('pAmount');
    if (!method) { return; }
    function syncMethod() {
        document.querySelectorAll('.method-field').forEach(function (f) {
            var on = f.dataset.methods.split(' ').indexOf(method.value) !== -1;
            f.classList.toggle('d-none', !on);
            f.querySelectorAll('input,select').forEach(function (i) { i.disabled = !on; });
        });
    }
    function syncAmount() {
        var o = inv.options[inv.selectedIndex];
        if (o && o.dataset.available) { amt.value = o.dataset.available; amt.max = o.dataset.available; }
    }
    method.addEventListener('change', syncMethod);
    inv.addEventListener('change', syncAmount);
    syncMethod();
    <?php if ($prefillInvoice > 0): ?>
    syncAmount();
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
    <?php endif; ?>
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
