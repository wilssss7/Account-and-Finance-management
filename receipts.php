<?php
/**
 * receipts.php - Receipt Generation (module 18)
 * A printable official receipt can be generated once for every VERIFIED payment.
 * It shows the receipt number, invoice number, guest, amount paid, method, date and the balance remaining
 * after that payment. Receipts are never deleted and can be reprinted at any time.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('receipts.generate');

$pdo = db();
$me  = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        if ((string)($_POST['action'] ?? '') !== 'generate') { throw new RuntimeException('Unknown action.'); }
        $pdo->beginTransaction();
        $p = db_one('SELECT p.*, i.total_amount, i.status AS invoice_status FROM payments p JOIN invoices i ON i.invoice_id = p.invoice_id
                     WHERE p.payment_id = ? FOR UPDATE', [(int)($_POST['payment_id'] ?? 0)]);
        if (!$p) { throw new RuntimeException('Payment not found.'); }
        if ($p['status'] !== 'verified') { throw new RuntimeException('Receipts can only be issued for verified payments.'); }
        if (db_value('SELECT COUNT(*) FROM receipts WHERE payment_id = ?', [(int)$p['payment_id']]) > 0) { throw new RuntimeException('A receipt was already issued for this payment.'); }

        // Balance after this payment = invoice total - payments up to and including this one - deposits applied
        $paidToDate = (float)db_value("SELECT COALESCE(SUM(amount), 0) FROM payments
            WHERE invoice_id = ? AND status = 'verified' AND (payment_date < ? OR (payment_date = ? AND payment_id <= ?))",
            [(int)$p['invoice_id'], $p['payment_date'], $p['payment_date'], (int)$p['payment_id']]);
        $deposits = (float)db_value('SELECT COALESCE(SUM(amount), 0) FROM deposit_applications WHERE invoice_id = ?', [(int)$p['invoice_id']]);
        $balanceAfter = max(0.0, round((float)$p['total_amount'] - $paidToDate - $deposits, 2));

        $number = next_doc_number('OR');
        $pdo->prepare('INSERT INTO receipts (receipt_number, payment_id, invoice_id, balance_after, issued_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$number, (int)$p['payment_id'], (int)$p['invoice_id'], $balanceAfter, (int)$me['id']]);
        $receiptId = (int)$pdo->lastInsertId();
        audit_log('receipt_issued', 'receipts', $receiptId, null, ['receipt_number' => $number, 'payment' => $p['payment_number'], 'amount' => $p['amount'], 'balance_after' => $balanceAfter]);
        $pdo->commit();
        set_flash('success', "Receipt $number was issued.");
        redirect('receipts.php?view=' . $receiptId);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The receipt could not be issued. Please try again.');
        redirect('receipts.php');
    }
}

/* ========================== Printable receipt ========================== */
$viewId = (int)($_GET['view'] ?? 0);
if ($viewId > 0) {
    $rc = db_one('SELECT rc.*, p.payment_number, p.amount, p.payment_date, p.payment_method, p.reference_number, p.cashier_name, p.card_type, p.card_last4, p.bank_name,
                         i.invoice_number, i.total_amount, i.subtotal, i.service_rate_applied, i.service_charge_amount, i.tax_rate_applied, i.tax_amount, i.discount_amount, i.adjustment_amount,
                         g.full_name, g.guest_code, g.company_name, u.full_name AS issuer
                  FROM receipts rc JOIN payments p ON p.payment_id = rc.payment_id JOIN invoices i ON i.invoice_id = rc.invoice_id
                  JOIN guests g ON g.guest_id = i.guest_id JOIN users u ON u.user_id = rc.issued_by WHERE rc.receipt_id = ?', [$viewId]);
    if (!$rc) { set_flash('danger', 'Receipt not found.'); redirect('receipts.php'); }
    $pageTitle = 'Receipt ' . $rc['receipt_number'];
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="d-flex flex-wrap gap-2 mb-3 no-print">
        <a href="<?= e(url('receipts.php?tab=issued')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>All receipts</a>
        <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print / save as PDF</button>
    </div>
    <div class="invoice-doc" style="max-width:720px">
        <div class="d-flex justify-content-between flex-wrap gap-3 mb-4">
            <?= render_document_brand() ?>
            <div class="text-md-end"><h2 style="font-size:1.6rem">OFFICIAL RECEIPT</h2><div class="fw-semibold"><?= e($rc['receipt_number']) ?></div>
                <div class="small text-muted">Issued <?= e(format_date($rc['issued_at'], 'M d, Y g:i A')) ?></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-md-6"><div class="meta-label">Received from</div><div class="fw-semibold"><?= e($rc['full_name']) ?></div>
                <?php if ($rc['company_name']): ?><div><?= e($rc['company_name']) ?></div><?php endif; ?><div class="small"><?= e($rc['guest_code']) ?></div></div>
            <div class="col-md-6"><div class="row g-2 small">
                <div class="col-6"><div class="meta-label">Invoice</div><?= e($rc['invoice_number']) ?></div>
                <div class="col-6"><div class="meta-label">Payment</div><?= e($rc['payment_number']) ?></div>
                <div class="col-6"><div class="meta-label">Payment date</div><?= e(format_date($rc['payment_date'])) ?></div>
                <div class="col-6"><div class="meta-label">Method</div><?= e(PAYMENT_METHODS[$rc['payment_method']] ?? '') ?></div>
                <div class="col-12"><div class="meta-label">Details</div>
                    <?php if ($rc['payment_method'] === 'cash'): ?>Received by <?= e($rc['cashier_name']) ?>
                    <?php elseif ($rc['payment_method'] === 'card'): ?><?= e($rc['card_type']) ?><?= $rc['card_last4'] ? ' ending ' . e($rc['card_last4']) : '' ?>
                    <?php elseif ($rc['payment_method'] === 'bank_transfer'): ?><?= e($rc['bank_name']) ?><?php else: ?>-<?php endif; ?>
                    <?= $rc['reference_number'] ? ' &middot; Ref: ' . e($rc['reference_number']) : '' ?></div>
            </div></div>
        </div>
        <table class="table invoice-totals" style="max-width:100%">
            <tr><td>Invoice subtotal (before tax)</td><td class="amount"><?= e(peso($rc['subtotal'])) ?></td></tr>
            <tr><td>Service charge (<?= e(rtrim(rtrim(number_format((float)$rc['service_rate_applied'], 2), '0'), '.')) ?>%)</td><td class="amount"><?= e(peso($rc['service_charge_amount'])) ?></td></tr>
            <tr><td>Tax (<?= e(rtrim(rtrim(number_format((float)$rc['tax_rate_applied'], 2), '0'), '.')) ?>%)</td><td class="amount"><?= e(peso($rc['tax_amount'])) ?></td></tr>
            <?php if ((float)$rc['discount_amount'] > 0): ?><tr><td>Discount</td><td class="amount">-<?= e(peso($rc['discount_amount'])) ?></td></tr><?php endif; ?>
            <?php if ((float)$rc['adjustment_amount'] != 0): ?><tr><td>Adjustments</td><td class="amount"><?= e(peso($rc['adjustment_amount'])) ?></td></tr><?php endif; ?>
            <tr><td><strong>Invoice total (tax included)</strong></td><td class="amount"><strong><?= e(peso($rc['total_amount'])) ?></strong></td></tr>
            <tr class="grand"><td>Amount paid</td><td class="amount"><?= e(peso($rc['amount'])) ?></td></tr>
        </table>
        <div class="invoice-balance-box d-flex justify-content-between align-items-center mt-2">
            <span class="fw-semibold">Remaining balance after this payment</span><span class="h4 mb-0"><?= e(peso($rc['balance_after'])) ?></span>
        </div>
        <div class="d-flex justify-content-between small mt-4"><span>Issued by: <?= e($rc['issuer']) ?></span><span>Thank you for your payment.</span></div>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

/* ------------------------------ Lists ------------------------------ */
$tab  = (($_GET['tab'] ?? '') === 'issued') ? 'issued' : 'ready';
$q    = clean_text($_GET['q'] ?? '', 60);
$from = valid_date((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '';
$to   = valid_date((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '';
$page = current_page_number();
$perPage = 15;
$where = ['1 = 1']; $params = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(p.payment_number LIKE ? OR i.invoice_number LIKE ? OR g.full_name LIKE ? OR p.reference_number LIKE ?' . ($tab === 'issued' ? ' OR rc.receipt_number LIKE ?' : '') . ')';
    array_push($params, $like, $like, $like, $like);
    if ($tab === 'issued') { $params[] = $like; }
}
$dateCol = $tab === 'issued' ? 'DATE(rc.issued_at)' : 'p.payment_date';
if ($from !== '') { $where[] = "$dateCol >= ?"; $params[] = $from; }
if ($to !== '')   { $where[] = "$dateCol <= ?"; $params[] = $to; }

if ($tab === 'ready') {
    $fromSql = "FROM payments p JOIN invoices i ON i.invoice_id = p.invoice_id JOIN guests g ON g.guest_id = i.guest_id
                LEFT JOIN receipts rc ON rc.payment_id = p.payment_id WHERE p.status = 'verified' AND rc.receipt_id IS NULL AND " . implode(' AND ', $where);
    $total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
    $rows = db_all("SELECT p.*, i.invoice_number, g.full_name $fromSql ORDER BY p.payment_date DESC, p.payment_id DESC", $params, $perPage, ($page - 1) * $perPage);
} else {
    $fromSql = 'FROM receipts rc JOIN payments p ON p.payment_id = rc.payment_id JOIN invoices i ON i.invoice_id = rc.invoice_id
                JOIN guests g ON g.guest_id = i.guest_id JOIN users u ON u.user_id = rc.issued_by WHERE ' . implode(' AND ', $where);
    $total = (int)db_value("SELECT COUNT(*) $fromSql", $params);
    $rows = db_all("SELECT rc.*, p.payment_number, p.amount, p.payment_method, i.invoice_number, g.full_name, u.full_name AS issuer $fromSql
                    ORDER BY rc.receipt_id DESC", $params, $perPage, ($page - 1) * $perPage);
}
$readyCount = (int)db_value("SELECT COUNT(*) FROM payments p LEFT JOIN receipts rc ON rc.payment_id = p.payment_id WHERE p.status = 'verified' AND rc.receipt_id IS NULL");

if (($_GET['export'] ?? '') === 'csv') {
    require_permission('exports.generate');
    if ($tab === 'issued') {
        $data = db_all("SELECT rc.receipt_number, rc.issued_at, p.payment_number, p.payment_date, i.invoice_number, g.guest_code, g.full_name, p.payment_method, p.reference_number, p.amount,
                               i.total_amount, rc.balance_after, u.full_name AS issuer
                        $fromSql ORDER BY rc.receipt_id DESC", $params);
        $rows = [];
        foreach ($data as $d) {
            $rows[] = [$d['receipt_number'], $d['issued_at'], $d['payment_number'], $d['payment_date'], $d['invoice_number'], $d['guest_code'], $d['full_name'],
                       PAYMENT_METHODS[$d['payment_method']] ?? csv_label($d['payment_method']), $d['reference_number'], $d['amount'], $d['total_amount'], $d['balance_after'], $d['issuer']];
        }
        csv_download('receipts_report', 'Receipts Report', ['Search' => $q, 'Issued from' => $from, 'Issued to' => $to],
            [['Receipt No.', 'text'], ['Issued', 'datetime'], ['Payment No.', 'text'], ['Payment date', 'date'], ['Invoice No.', 'text'], ['Guest ID', 'text'], ['Guest name', 'text'],
             ['Payment method', 'text'], ['Reference No.', 'text'], ['Amount paid (PHP)', 'money', true], ['Invoice total incl. tax (PHP)', 'money'], ['Balance after payment (PHP)', 'money'], ['Issued by', 'text']], $rows, null, 'TOTAL AMOUNT PAID');
    }
    $data = db_all("SELECT p.payment_number, p.payment_date, i.invoice_number, g.guest_code, g.full_name, p.payment_method, p.reference_number, p.amount
                    $fromSql ORDER BY p.payment_date DESC, p.payment_id DESC", $params);
    $rows = [];
    foreach ($data as $d) {
        $rows[] = [$d['payment_number'], $d['payment_date'], $d['invoice_number'], $d['guest_code'], $d['full_name'], PAYMENT_METHODS[$d['payment_method']] ?? csv_label($d['payment_method']), $d['reference_number'], $d['amount']];
    }
    csv_download('payments_without_receipt_report', 'Verified Payments Without a Receipt', ['Search' => $q, 'Paid from' => $from, 'Paid to' => $to],
        [['Payment No.', 'text'], ['Payment date', 'date'], ['Invoice No.', 'text'], ['Guest ID', 'text'], ['Guest name', 'text'], ['Payment method', 'text'], ['Reference No.', 'text'],
         ['Amount (PHP)', 'money', true]], $rows);
}

$pageTitle = 'Receipts';
include __DIR__ . '/includes/header.php';
?>
<ul class="nav nav-tabs-soft nav border-0 mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 'ready' ? 'active' : '' ?>" href="<?= e(url('receipts.php')) ?>">Ready to issue <span class="badge badge-soft badge-khaki ms-1"><?= $readyCount ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'issued' ? 'active' : '' ?>" href="<?= e(url('receipts.php?tab=issued')) ?>">Issued receipts</a></li>
</ul>

<div class="mb-3 no-print"><?= render_export_menu('?' . qs(['export' => 'csv', 'page' => '']), has_permission('exports.generate')) ?></div>
<form method="get" class="filter-bar mb-3">
    <?php if ($tab === 'issued'): ?><input type="hidden" name="tab" value="issued"><?php endif; ?>
    <div class="row g-2 align-items-end">
        <div class="col-md-5"><label class="form-label small mb-1">Search</label><input type="search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Receipt, payment, invoice, guest or reference"></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1"><?= $tab === 'issued' ? 'Issued from' : 'Paid from' ?></label><input type="date" name="from" value="<?= e($from) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1"><?= $tab === 'issued' ? 'Issued to' : 'Paid to' ?></label><input type="date" name="to" value="<?= e($to) ?>" class="form-control form-control-sm"></div>
        <div class="col-md-1 d-grid"><button class="btn btn-primary btn-sm" type="submit">Go</button></div>
    </div>
</form>

<div class="app-card"><div class="table-responsive">
<?php if ($tab === 'ready'): ?>
    <table class="table table-hover">
        <thead><tr><th>Payment</th><th>Invoice / guest</th><th>Method</th><th>Reference</th><th class="amount">Amount</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">Every verified payment already has a receipt.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= e($r['payment_number']) ?></strong><div class="small text-muted"><?= e(format_date($r['payment_date'])) ?></div></td>
                <td><?= e($r['invoice_number']) ?><div class="small text-muted"><?= e($r['full_name']) ?></div></td>
                <td><?= e(PAYMENT_METHODS[$r['payment_method']] ?? '') ?></td>
                <td><?= e($r['reference_number'] ?? '-') ?></td>
                <td class="amount"><strong><?= e(peso($r['amount'])) ?></strong></td>
                <td class="text-end"><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="generate"><input type="hidden" name="payment_id" value="<?= (int)$r['payment_id'] ?>">
                    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-receipt me-1"></i>Issue receipt</button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <table class="table table-hover">
        <thead><tr><th>Receipt</th><th>Invoice / guest</th><th>Payment</th><th class="amount">Paid</th><th class="amount">Balance after</th><th>Issued by</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No receipts issued yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a href="<?= e(url('receipts.php?view=' . (int)$r['receipt_id'])) ?>"><strong><?= e($r['receipt_number']) ?></strong></a><div class="small text-muted"><?= e(format_date($r['issued_at'], 'M d, Y g:i A')) ?></div></td>
                <td><?= e($r['invoice_number']) ?><div class="small text-muted"><?= e($r['full_name']) ?></div></td>
                <td><?= e($r['payment_number']) ?><div class="small text-muted"><?= e(PAYMENT_METHODS[$r['payment_method']] ?? '') ?></div></td>
                <td class="amount"><?= e(peso($r['amount'])) ?></td>
                <td class="amount"><strong><?= e(peso($r['balance_after'])) ?></strong></td>
                <td class="small"><?= e($r['issuer']) ?></td>
                <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="<?= e(url('receipts.php?view=' . (int)$r['receipt_id'])) ?>">View / print</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div></div>
<div class="small text-muted mt-2"><?= $total ?> record(s)</div>
<?= render_pager($total, $page, $perPage) ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
