<?php
/**
 * payroll.php - Automated payroll (Revision 2)
 *  1. An administrator adds employees with their OWN pay arrangement and rate (monthly salary, daily rate or hourly rate).
 *  2. An administrator creates pay periods (start date and end date).
 *  3. "Generate" creates one draft record per active employee for the period. The database allows only ONE record per
 *     employee and period, so duplicates are impossible.
 *       monthly : gross = monthly salary / pay periods per month (setting)
 *       daily   : gross = daily rate x days worked        (days entered by authorized staff)
 *       hourly  : gross = hourly rate x hours worked      (hours entered by authorized staff)
 *     net = gross - deductions (deductions are typed in; no government rates are built in).
 *  4. Draft -> Verified (another person must verify) -> Paid (a person other than the verifier marks it paid).
 *  Paid records count as an expense (gross pay, on the pay-period end date). Pay rates are copied into each record,
 *  so later rate changes never alter old payroll. Attendance, overtime and leave are NOT in the system.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission('payroll.view');
require_revision2();

$pdo = db();
$me  = current_user();
$canManage = has_permission('payroll.manage');
$canConfig = has_permission('payroll.configure');
$canVerify = has_permission('payroll.verify');
$canPay    = has_permission('payroll.pay');
const PAY_TYPES = ['monthly' => 'Monthly salary', 'daily' => 'Daily rate', 'hourly' => 'Hourly rate'];

/** @return array{gross: float, net: float} */
function payroll_calc(string $type, float $rate, float $units, float $deductions, int $perMonth): array
{
    if ($type === 'monthly') {
        if ($perMonth < 1) { throw new RuntimeException('Set "Payroll: pay periods per month" under Administration > Tax and Charges before calculating monthly salaries.'); }
        $gross = round($rate / $perMonth, 2);
    } else {
        $gross = round($rate * $units, 2);
    }
    if ($deductions > $gross + 0.004) { throw new RuntimeException('Deductions (' . peso($deductions) . ') cannot be more than the gross pay (' . peso($gross) . ').'); }
    return ['gross' => $gross, 'net' => round($gross - $deductions, 2)];
}

function load_record(int $id): array
{
    $r = db_one('SELECT r.*, e.full_name, pp.end_date, pp.period_label FROM payroll_records r JOIN employees e ON e.employee_id = r.employee_id
                 JOIN payroll_periods pp ON pp.payroll_period_id = r.payroll_period_id WHERE r.payroll_id = ? FOR UPDATE', [$id]);
    if (!$r) { throw new RuntimeException('Payroll record not found.'); }
    return $r;
}

function verify_record(array $r, int $userId): void
{
    if ($r['status'] !== 'draft') { throw new RuntimeException($r['full_name'] . ' is not a draft.'); }
    if ((float)$r['gross_pay'] <= 0) { throw new RuntimeException($r['full_name'] . ': enter the days or hours worked before verifying (gross pay is zero).'); }
    if ($userId === (int)$r['created_by'] || $userId === (int)($r['updated_by'] ?? 0)) { throw new RuntimeException($r['full_name'] . ': you prepared this record, so another authorized person must verify it.'); }
    db()->prepare("UPDATE payroll_records SET status = 'verified', verified_by = ?, verified_at = NOW() WHERE payroll_id = ?")->execute([$userId, (int)$r['payroll_id']]);
    audit_log('payroll_verified', 'payroll_records', $r['payroll_id'], ['status' => 'draft'], ['status' => 'verified', 'employee' => $r['full_name'], 'gross' => $r['gross_pay'], 'net' => $r['net_pay']]);
}

function pay_record(array $r, int $userId, string $date, string $ref): void
{
    if ($r['status'] !== 'verified') { throw new RuntimeException($r['full_name'] . ' must be verified before it can be paid.'); }
    if ($userId === (int)$r['verified_by']) { throw new RuntimeException($r['full_name'] . ': you verified this record, so another authorized person must mark it paid.'); }
    if ($date < $r['end_date']) { throw new RuntimeException('The payment date cannot be before the pay period ends (' . format_date($r['end_date']) . ').'); }
    $finPeriod = require_open_period((string)$r['end_date']);      // the expense belongs to the financial period of the pay-period end date
    db()->prepare("UPDATE payroll_records SET status = 'paid', paid_by = ?, paid_at = NOW(), payment_date = ?, payment_reference = ?, fin_period_id = ? WHERE payroll_id = ?")
        ->execute([$userId, $date, $ref !== '' ? $ref : null, $finPeriod, (int)$r['payroll_id']]);
    audit_log('payroll_paid', 'payroll_records', $r['payroll_id'], ['status' => 'verified'], ['status' => 'paid', 'employee' => $r['full_name'], 'net_paid' => $r['net_pay'], 'payment_date' => $date, 'reference' => $ref]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $periodBack = (int)($_POST['period_id'] ?? 0);
    $tabBack = in_array($action, ['add_employee', 'edit_employee'], true) ? '?tab=employees' : ($periodBack > 0 ? '?period=' . $periodBack : '');
    try {
        $pdo->beginTransaction();

        if ($action === 'add_employee' || $action === 'edit_employee') {
            if (!$canConfig) { throw new RuntimeException('Only an administrator can manage employees and pay rates.'); }
            $id = (int)($_POST['employee_id'] ?? 0);
            $code = strtoupper(trim((string)($_POST['employee_code'] ?? '')));
            $name = clean_text($_POST['full_name'] ?? '', 120);
            $dept = clean_text($_POST['department'] ?? '', 80);
            $pos  = clean_text($_POST['position_title'] ?? '', 80);
            $type = (string)($_POST['pay_type'] ?? '');
            $rate = parse_money($_POST['pay_rate'] ?? '');
            if (!preg_match('/^[A-Z0-9\-]{2,20}$/', $code)) { throw new RuntimeException('The employee code must be 2-20 letters, numbers or dashes.'); }
            if (mb_strlen($name) < 2 || mb_strlen($dept) < 2) { throw new RuntimeException('Enter the employee name and department.'); }
            if (!isset(PAY_TYPES[$type])) { throw new RuntimeException('Choose how the employee is paid.'); }
            if ($rate === null) { throw new RuntimeException('Enter the pay rate (an amount greater than zero).'); }
            if (db_value('SELECT COUNT(*) FROM employees WHERE employee_code = ? AND employee_id <> ?', [$code, $id]) > 0) { throw new RuntimeException('That employee code is already used.'); }
            if ($action === 'add_employee') {
                $pdo->prepare('INSERT INTO employees (employee_code, full_name, department, position_title, pay_type, pay_rate) VALUES (?, ?, ?, ?, ?, ?)')->execute([$code, $name, $dept, $pos ?: null, $type, $rate]);
                audit_log('employee_added', 'employees', (int)$pdo->lastInsertId(), null, ['code' => $code, 'name' => $name, 'pay_type' => $type]);
                $msg = "Employee $code was added.";
            } else {
                $old = db_one('SELECT * FROM employees WHERE employee_id = ? FOR UPDATE', [$id]);
                if (!$old) { throw new RuntimeException('Employee not found.'); }
                $active = isset($_POST['is_active']) ? 1 : 0;
                $pdo->prepare('UPDATE employees SET employee_code = ?, full_name = ?, department = ?, position_title = ?, pay_type = ?, pay_rate = ?, is_active = ? WHERE employee_id = ?')
                    ->execute([$code, $name, $dept, $pos ?: null, $type, $rate, $active, $id]);
                audit_log('employee_updated', 'employees', $id, ['pay_type' => $old['pay_type'], 'pay_rate' => $old['pay_rate'], 'is_active' => $old['is_active']], ['pay_type' => $type, 'pay_rate' => $rate, 'is_active' => $active], 'Existing payroll records keep the rate they were generated with.');
                $msg = "Employee $code was updated. Existing payroll records keep their original rate.";
            }
            $pdo->commit();
            set_flash('success', $msg);

        } elseif ($action === 'add_period') {
            if (!$canConfig) { throw new RuntimeException('Only an administrator can set pay periods.'); }
            $start = (string)($_POST['start_date'] ?? ''); $end = (string)($_POST['end_date'] ?? '');
            if (!valid_date($start) || !valid_date($end) || $end < $start) { throw new RuntimeException('Enter a valid start date and an end date that is not before it.'); }
            if ((strtotime($end) - strtotime($start)) / 86400 > 62) { throw new RuntimeException('A pay period cannot be longer than 62 days.'); }
            if (db_value('SELECT COUNT(*) FROM payroll_periods WHERE start_date <= ? AND end_date >= ?', [$end, $start]) > 0) { throw new RuntimeException('That period overlaps an existing pay period.'); }
            $label = clean_text($_POST['period_label'] ?? '', 80) ?: format_date($start, 'M d') . ' - ' . format_date($end, 'M d, Y');
            $pdo->prepare('INSERT INTO payroll_periods (period_label, start_date, end_date, created_by) VALUES (?, ?, ?, ?)')->execute([$label, $start, $end, (int)$me['id']]);
            audit_log('payroll_period_created', 'payroll_periods', (int)$pdo->lastInsertId(), null, ['label' => $label, 'start' => $start, 'end' => $end]);
            $pdo->commit();
            set_flash('success', "Pay period \"$label\" was created.");

        } elseif ($action === 'generate') {
            if (!$canManage) { throw new RuntimeException('Your role cannot generate payroll.'); }
            $pp = db_one('SELECT * FROM payroll_periods WHERE payroll_period_id = ? FOR UPDATE', [$periodBack]);
            if (!$pp) { throw new RuntimeException('Pay period not found.'); }
            if ($pp['end_date'] >= date('Y-m-d')) { throw new RuntimeException('This pay period has not ended yet. Payroll can be generated after ' . format_date($pp['end_date']) . '.'); }
            $perMonth = (int)get_setting('payroll_periods_per_month', '0');
            $created = 0; $skipped = 0;
            foreach (db_all('SELECT * FROM employees WHERE is_active = 1 ORDER BY full_name') as $emp) {
                if (db_value('SELECT COUNT(*) FROM payroll_records WHERE payroll_period_id = ? AND employee_id = ?', [$periodBack, (int)$emp['employee_id']]) > 0) { $skipped++; continue; }
                $calc = payroll_calc($emp['pay_type'], (float)$emp['pay_rate'], 0.0, 0.0, $perMonth);   // monthly pay is calculated now; daily/hourly start at 0
                $pdo->prepare('INSERT INTO payroll_records (payroll_period_id, employee_id, pay_type, pay_rate, periods_per_month, units, gross_pay, deductions, net_pay, created_by)
                               VALUES (?, ?, ?, ?, ?, 0, ?, 0, ?, ?)')->execute([$periodBack, (int)$emp['employee_id'], $emp['pay_type'], $emp['pay_rate'], $emp['pay_type'] === 'monthly' ? $perMonth : null, $calc['gross'], $calc['net'], (int)$me['id']]);
                $created++;
            }
            if ($created === 0 && $skipped === 0) { throw new RuntimeException('There are no active employees. Add employees first.'); }
            audit_log('payroll_generated', 'payroll_periods', $periodBack, null, ['period' => $pp['period_label'], 'created' => $created, 'already_had_records' => $skipped]);
            $pdo->commit();
            set_flash('success', "$created payroll record(s) created" . ($skipped ? ", $skipped employee(s) already had a record for this period (no duplicates were made)" : '') . '. Enter days or hours for daily and hourly employees, then send for verification.');

        } elseif ($action === 'edit_record') {
            if (!$canManage) { throw new RuntimeException('Your role cannot edit payroll.'); }
            $r = load_record((int)($_POST['payroll_id'] ?? 0));
            if ($r['status'] !== 'draft') { throw new RuntimeException('Only draft records can be edited.'); }
            $units = $r['pay_type'] === 'monthly' ? 0.0 : parse_money($_POST['units'] ?? '', true);
            $ded   = parse_money($_POST['deductions'] ?? '', true);
            if ($units === null) { throw new RuntimeException('Enter the ' . ($r['pay_type'] === 'daily' ? 'days' : 'hours') . ' worked (0 or more).'); }
            if ($ded === null) { throw new RuntimeException('Enter the deductions (0 if none).'); }
            $calc = payroll_calc($r['pay_type'], (float)$r['pay_rate'], $units, $ded, (int)($r['periods_per_month'] ?? 0));
            $pdo->prepare('UPDATE payroll_records SET units = ?, gross_pay = ?, deductions = ?, net_pay = ?, notes = ?, updated_by = ? WHERE payroll_id = ?')
                ->execute([$units, $calc['gross'], $ded, $calc['net'], clean_text($_POST['notes'] ?? '', 255) ?: null, (int)$me['id'], (int)$r['payroll_id']]);
            audit_log('payroll_edited', 'payroll_records', $r['payroll_id'], ['units' => $r['units'], 'gross' => $r['gross_pay'], 'deductions' => $r['deductions']], ['units' => $units, 'gross' => $calc['gross'], 'deductions' => $ded, 'net' => $calc['net']]);
            $pdo->commit();
            set_flash('success', $r['full_name'] . ': gross ' . peso($calc['gross']) . ', net ' . peso($calc['net']) . '.');

        } elseif ($action === 'delete_draft') {
            if (!$canManage) { throw new RuntimeException('Your role cannot delete draft payroll.'); }
            $r = load_record((int)($_POST['payroll_id'] ?? 0));
            if ($r['status'] !== 'draft') { throw new RuntimeException('Only draft records can be removed.'); }
            $pdo->prepare('DELETE FROM payroll_records WHERE payroll_id = ?')->execute([(int)$r['payroll_id']]);
            audit_log('payroll_draft_removed', 'payroll_records', $r['payroll_id'], ['employee' => $r['full_name'], 'gross' => $r['gross_pay']], null, 'Draft removed before verification');
            $pdo->commit();
            set_flash('success', 'The draft for ' . $r['full_name'] . ' was removed. You can generate it again.');

        } elseif ($action === 'verify' || $action === 'verify_all') {
            if (!$canVerify) { throw new RuntimeException('Your role cannot verify payroll.'); }
            $rows = $action === 'verify' ? [load_record((int)($_POST['payroll_id'] ?? 0))]
                  : db_all("SELECT r.payroll_id FROM payroll_records r WHERE r.payroll_period_id = ? AND r.status = 'draft' FOR UPDATE", [$periodBack]);
            $ok = 0; $skip = [];
            foreach ($rows as $row) {
                $r = $action === 'verify' ? $row : load_record((int)$row['payroll_id']);
                try { verify_record($r, (int)$me['id']); $ok++; } catch (RuntimeException $ex) { if ($action === 'verify') { throw $ex; } $skip[] = $ex->getMessage(); }
            }
            $pdo->commit();
            set_flash($skip ? 'warning' : 'success', "$ok record(s) verified." . ($skip ? ' Skipped: ' . implode(' ', array_slice($skip, 0, 3)) : ''));

        } elseif ($action === 'unverify') {
            if (!$canVerify) { throw new RuntimeException('Your role cannot change verification.'); }
            $reason = clean_text($_POST['reason'] ?? '', 255);
            if (mb_strlen($reason) < 5) { throw new RuntimeException('Enter the reason for returning the record to draft (at least 5 characters).'); }
            $r = load_record((int)($_POST['payroll_id'] ?? 0));
            if ($r['status'] !== 'verified') { throw new RuntimeException('Only verified records can be returned to draft.'); }
            $pdo->prepare("UPDATE payroll_records SET status = 'draft', verified_by = NULL, verified_at = NULL WHERE payroll_id = ?")->execute([(int)$r['payroll_id']]);
            audit_log('payroll_returned_to_draft', 'payroll_records', $r['payroll_id'], ['status' => 'verified'], ['status' => 'draft'], $reason);
            $pdo->commit();
            set_flash('warning', $r['full_name'] . ' was returned to draft.');

        } elseif ($action === 'pay' || $action === 'pay_all') {
            if (!$canPay) { throw new RuntimeException('Your role cannot mark payroll as paid.'); }
            $date = (string)($_POST['payment_date'] ?? '');
            if (!valid_date($date) || $date > date('Y-m-d')) { throw new RuntimeException('The payment date must be a valid date that is not in the future.'); }
            $ref = clean_text($_POST['payment_reference'] ?? '', 60);
            $rows = $action === 'pay' ? [['payroll_id' => (int)($_POST['payroll_id'] ?? 0)]]
                  : db_all("SELECT payroll_id FROM payroll_records WHERE payroll_period_id = ? AND status = 'verified' FOR UPDATE", [$periodBack]);
            if (!$rows) { throw new RuntimeException('There are no verified records to pay.'); }
            $ok = 0; $skip = [];
            foreach ($rows as $row) {
                $r = load_record((int)$row['payroll_id']);
                try { pay_record($r, (int)$me['id'], $date, $ref); $ok++; } catch (RuntimeException $ex) { if ($action === 'pay') { throw $ex; } $skip[] = $ex->getMessage(); }
            }
            $pdo->commit();
            set_flash($skip ? 'warning' : 'success', "$ok record(s) marked as paid." . ($skip ? ' Skipped: ' . implode(' ', array_slice($skip, 0, 3)) : ''));
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The action could not be completed. Please try again.');
    }
    redirect('payroll.php' . $tabBack);
}

if (time() - (int)($_SESSION['payroll_view_logged'] ?? 0) > 1800) {      // access to salary data is logged
    audit_log('payroll_viewed', 'payroll_records', null, null, null, 'Payroll page opened');
    $_SESSION['payroll_view_logged'] = time();
}

$periodId = (int)($_GET['period'] ?? 0);
$tab = (($_GET['tab'] ?? '') === 'employees') ? 'employees' : 'periods';
$pp = $periodId > 0 ? db_one('SELECT * FROM payroll_periods WHERE payroll_period_id = ?', [$periodId]) : null;
$perMonth = (int)get_setting('payroll_periods_per_month', '0');

$pageTitle = 'Payroll';
include __DIR__ . '/includes/header.php';
$units = static fn(string $t): string => $t === 'daily' ? 'days' : ($t === 'hourly' ? 'hours' : '');
?>
<div class="alert alert-warning py-2 small"><i class="bi bi-shield-lock me-1"></i>Restricted information. Access is recorded in the audit trail. Attendance, overtime, leave and government deductions are not part of this system: days or hours and deductions are typed in by authorized staff.</div>

<?php if ($pp): /* ============================ records of one pay period ============================ */
    $recs = db_all('SELECT r.*, e.employee_code, e.full_name, e.department, uv.full_name AS verifier, up.full_name AS payer
                    FROM payroll_records r JOIN employees e ON e.employee_id = r.employee_id
                    LEFT JOIN users uv ON uv.user_id = r.verified_by LEFT JOIN users up ON up.user_id = r.paid_by
                    WHERE r.payroll_period_id = ? ORDER BY e.full_name', [$periodId]);
    $tot = ['gross' => 0.0, 'ded' => 0.0, 'net' => 0.0, 'draft' => 0, 'verified' => 0, 'paid' => 0];
    foreach ($recs as $r) { $tot['gross'] += (float)$r['gross_pay']; $tot['ded'] += (float)$r['deductions']; $tot['net'] += (float)$r['net_pay']; $tot[$r['status']]++; }
    $ended = $pp['end_date'] < date('Y-m-d'); ?>
    <div class="mb-3"><a href="<?= e(url('payroll.php')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>All pay periods</a></div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><h2 class="h4 mb-0"><?= e($pp['period_label']) ?></h2><div class="small text-muted"><?= e(format_date($pp['start_date'])) ?> to <?= e(format_date($pp['end_date'])) ?></div></div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($canManage): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="generate"><input type="hidden" name="period_id" value="<?= (int)$periodId ?>">
                <button class="btn btn-primary btn-sm" type="submit" <?= $ended ? '' : 'disabled title="The pay period has not ended yet"' ?>><i class="bi bi-calculator me-1"></i>Generate payroll</button></form><?php endif; ?>
            <?php if ($canVerify && $tot['draft'] > 0): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="verify_all"><input type="hidden" name="period_id" value="<?= (int)$periodId ?>">
                <button class="btn btn-secondary btn-sm" type="submit" data-confirm="Verify every draft record in this period?"><i class="bi bi-check2-all me-1"></i>Verify all drafts</button></form><?php endif; ?>
            <?php if ($canPay && $tot['verified'] > 0): ?><button class="btn btn-success btn-sm pay-btn" data-bs-toggle="modal" data-bs-target="#payModal" data-mode="pay_all" data-id="0"><i class="bi bi-cash-coin me-1"></i>Mark verified as paid</button><?php endif; ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="summary-chip">Draft <strong><?= (int)$tot['draft'] ?></strong></span><span class="summary-chip">Verified <strong><?= (int)$tot['verified'] ?></strong></span><span class="summary-chip">Paid <strong><?= (int)$tot['paid'] ?></strong></span>
        <span class="summary-chip">Gross <strong><?= e(peso($tot['gross'])) ?></strong></span><span class="summary-chip">Net <strong><?= e(peso($tot['net'])) ?></strong></span>
    </div>
    <div class="app-card"><div class="table-responsive"><table class="table table-hover align-middle">
        <thead><tr><th>Employee</th><th>Pay arrangement</th><th class="amount">Days / hours</th><th class="amount">Gross pay</th><th class="amount">Deductions</th><th class="amount">Net pay</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$recs): ?><tr><td colspan="8" class="text-center text-muted py-4">No payroll records yet. Click <strong>Generate payroll</strong> after the pay period ends.</td></tr><?php endif; ?>
        <?php foreach ($recs as $r): ?>
            <tr>
                <td><strong><?= e($r['full_name']) ?></strong><div class="small text-muted"><?= e($r['employee_code']) ?> &middot; <?= e($r['department']) ?></div></td>
                <td class="small"><?= e(PAY_TYPES[$r['pay_type']]) ?><div class="text-muted"><?= e(peso($r['pay_rate'])) ?><?= $r['pay_type'] === 'monthly' ? ' per month / ' . (int)$r['periods_per_month'] . ' period(s)' : ' per ' . ($r['pay_type'] === 'daily' ? 'day' : 'hour') ?></div></td>
                <td class="amount"><?= $r['pay_type'] === 'monthly' ? '-' : e(rtrim(rtrim(number_format((float)$r['units'], 2), '0'), '.') ?: '0') . ' ' . e($units($r['pay_type'])) ?></td>
                <td class="amount"><?= e(peso($r['gross_pay'])) ?></td><td class="amount"><?= e(peso($r['deductions'])) ?></td><td class="amount"><strong><?= e(peso($r['net_pay'])) ?></strong></td>
                <td><?= status_badge($r['status']) ?><?php if ($r['verifier']): ?><div class="small text-muted">Verified: <?= e($r['verifier']) ?></div><?php endif; ?>
                    <?php if ($r['payer']): ?><div class="small text-muted">Paid <?= e(format_date($r['payment_date'])) ?> by <?= e($r['payer']) ?></div><?php endif; ?></td>
                <td class="text-end text-nowrap">
                    <?php if ($r['status'] === 'draft'): ?>
                        <?php if ($canManage): ?><button class="btn btn-outline-primary btn-sm edit-btn" data-bs-toggle="modal" data-bs-target="#editModal" data-id="<?= (int)$r['payroll_id'] ?>" data-name="<?= e($r['full_name']) ?>"
                            data-type="<?= e($r['pay_type']) ?>" data-units="<?= e(number_format((float)$r['units'], 2, '.', '')) ?>" data-ded="<?= e(number_format((float)$r['deductions'], 2, '.', '')) ?>" data-notes="<?= e($r['notes'] ?? '') ?>">Edit</button><?php endif; ?>
                        <?php if ($canVerify): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="period_id" value="<?= (int)$periodId ?>"><input type="hidden" name="payroll_id" value="<?= (int)$r['payroll_id'] ?>"><button class="btn btn-secondary btn-sm" type="submit">Verify</button></form><?php endif; ?>
                        <?php if ($canManage): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete_draft"><input type="hidden" name="period_id" value="<?= (int)$periodId ?>"><input type="hidden" name="payroll_id" value="<?= (int)$r['payroll_id'] ?>"><button class="btn btn-outline-secondary btn-sm" type="submit" data-confirm="Remove this draft?"><i class="bi bi-x-lg"></i></button></form><?php endif; ?>
                    <?php elseif ($r['status'] === 'verified'): ?>
                        <?php if ($canPay): ?><button class="btn btn-success btn-sm pay-btn" data-bs-toggle="modal" data-bs-target="#payModal" data-mode="pay" data-id="<?= (int)$r['payroll_id'] ?>">Mark paid</button><?php endif; ?>
                        <?php if ($canVerify): ?><button class="btn btn-outline-secondary btn-sm back-btn" data-bs-toggle="modal" data-bs-target="#backModal" data-id="<?= (int)$r['payroll_id'] ?>">Return</button><?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($recs): ?><tfoot><tr class="fw-bold"><td colspan="3">Total</td><td class="amount"><?= e(peso($tot['gross'])) ?></td><td class="amount"><?= e(peso($tot['ded'])) ?></td><td class="amount"><?= e(peso($tot['net'])) ?></td><td colspan="2"></td></tr></tfoot><?php endif; ?>
    </table></div></div>
    <div class="small text-muted mt-2">Paid records count as an expense (gross pay) on <?= e(format_date($pp['end_date'])) ?>, the pay-period end date. The person who prepares a record cannot verify it, and the person who verifies it cannot mark it paid.</div>

    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="edit_record"><input type="hidden" name="period_id" value="<?= (int)$periodId ?>"><input type="hidden" name="payroll_id" id="edId">
        <div class="modal-header"><h5 class="modal-title">Edit <span id="edName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-6" id="edUnitsBox"><label class="form-label" id="edUnitsLabel">Days worked</label><input type="number" step="0.01" min="0" name="units" id="edUnits" class="form-control"></div>
            <div class="col-6"><label class="form-label">Deductions (&#8369;)</label><input type="number" step="0.01" min="0" name="deductions" id="edDed" class="form-control" required></div>
            <div class="col-12"><label class="form-label">Notes (optional)</label><input type="text" name="notes" id="edNotes" maxlength="255" class="form-control"></div>
        </div><div class="form-text mt-2" id="edHelp"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save and recalculate</button></div>
    </form></div></div>
    <div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" id="pyAction" value="pay"><input type="hidden" name="period_id" value="<?= (int)$periodId ?>"><input type="hidden" name="payroll_id" id="pyId">
        <div class="modal-header"><h5 class="modal-title">Mark as paid</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-6"><label class="form-label">Payment date</label><input type="date" name="payment_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" min="<?= e($pp['end_date']) ?>" required></div>
            <div class="col-6"><label class="form-label">Reference (optional)</label><input type="text" name="payment_reference" maxlength="60" class="form-control"></div>
        </div><div class="form-text mt-2">Paid payroll becomes an expense in the financial period of the pay-period end date.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" type="submit">Mark as paid</button></div>
    </form></div></div>
    <div class="modal fade" id="backModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="action" value="unverify"><input type="hidden" name="period_id" value="<?= (int)$periodId ?>"><input type="hidden" name="payroll_id" id="bkId">
        <div class="modal-header"><h5 class="modal-title">Return to draft</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><label class="form-label">Reason</label><input type="text" name="reason" maxlength="255" class="form-control" required></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Return to draft</button></div>
    </form></div></div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.edit-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                var t = b.dataset.type, monthly = t === 'monthly';
                document.getElementById('edId').value = b.dataset.id; document.getElementById('edName').textContent = b.dataset.name;
                document.getElementById('edUnits').value = b.dataset.units; document.getElementById('edDed').value = b.dataset.ded; document.getElementById('edNotes').value = b.dataset.notes;
                document.getElementById('edUnitsBox').classList.toggle('d-none', monthly); document.getElementById('edUnits').disabled = monthly;
                document.getElementById('edUnitsLabel').textContent = t === 'daily' ? 'Days worked' : 'Hours worked';
                document.getElementById('edHelp').textContent = monthly ? 'Monthly salary: the gross pay is calculated automatically from the salary and the pay periods per month.' : 'Gross pay = rate x ' + (t === 'daily' ? 'days' : 'hours') + ' worked.';
            });
        });
        document.querySelectorAll('.pay-btn').forEach(function (b) { b.addEventListener('click', function () { document.getElementById('pyAction').value = b.dataset.mode; document.getElementById('pyId').value = b.dataset.id; }); });
        document.querySelectorAll('.back-btn').forEach(function (b) { b.addEventListener('click', function () { document.getElementById('bkId').value = b.dataset.id; }); });
    });
    </script>

<?php else: /* ============================ pay periods and employees ============================ */ ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <ul class="nav nav-tabs-soft nav border-0">
            <li class="nav-item"><a class="nav-link <?= $tab === 'periods' ? 'active' : '' ?>" href="<?= e(url('payroll.php')) ?>">Pay periods</a></li>
            <li class="nav-item"><a class="nav-link <?= $tab === 'employees' ? 'active' : '' ?>" href="<?= e(url('payroll.php?tab=employees')) ?>">Employees and pay rates</a></li>
        </ul>
        <?php if ($canConfig && $tab === 'periods'): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#periodModal"><i class="bi bi-plus-lg me-1"></i>New pay period</button><?php endif; ?>
        <?php if ($canConfig && $tab === 'employees'): ?><button class="btn btn-primary emp-btn" data-bs-toggle="modal" data-bs-target="#empModal" data-mode="add"><i class="bi bi-person-plus me-1"></i>Add employee</button><?php endif; ?>
    </div>

    <?php if ($tab === 'periods'):
        $periods = db_all("SELECT pp.*, COUNT(r.payroll_id) AS n, COALESCE(SUM(r.status = 'draft'), 0) AS drafts, COALESCE(SUM(r.status = 'verified'), 0) AS verified, COALESCE(SUM(r.status = 'paid'), 0) AS paid,
                           COALESCE(SUM(r.gross_pay), 0) AS gross, COALESCE(SUM(r.net_pay), 0) AS net
                           FROM payroll_periods pp LEFT JOIN payroll_records r ON r.payroll_period_id = pp.payroll_period_id GROUP BY pp.payroll_period_id ORDER BY pp.start_date DESC"); ?>
        <?php if ($perMonth < 1): ?><div class="alert alert-info py-2 small">"Pay periods per month" is not set (0), so <strong>monthly</strong> salaries cannot be calculated yet. An administrator sets it under Administration &rarr; Tax and Charges.</div><?php endif; ?>
        <div class="app-card"><div class="table-responsive"><table class="table table-hover align-middle">
            <thead><tr><th>Pay period</th><th>Dates</th><th class="text-center">Records</th><th>Draft / verified / paid</th><th class="amount">Gross</th><th class="amount">Net</th><th></th></tr></thead>
            <tbody>
            <?php if (!$periods): ?><tr><td colspan="7" class="text-center text-muted py-4">No pay periods yet. An administrator creates the first one.</td></tr><?php endif; ?>
            <?php foreach ($periods as $p): ?>
                <tr><td><strong><?= e($p['period_label']) ?></strong></td><td class="small"><?= e(format_date($p['start_date'])) ?> - <?= e(format_date($p['end_date'])) ?></td><td class="text-center"><?= (int)$p['n'] ?></td>
                    <td><span class="badge badge-soft badge-khaki"><?= (int)$p['drafts'] ?></span> <span class="badge badge-soft badge-pink"><?= (int)$p['verified'] ?></span> <span class="badge badge-soft badge-sage"><?= (int)$p['paid'] ?></span></td>
                    <td class="amount"><?= e(peso($p['gross'])) ?></td><td class="amount"><?= e(peso($p['net'])) ?></td>
                    <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="<?= e(url('payroll.php?period=' . (int)$p['payroll_period_id'])) ?>">Open</a></td></tr>
            <?php endforeach; ?>
            </tbody></table></div></div>
        <div class="modal fade" id="periodModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form method="post" class="modal-content">
            <?= csrf_field() ?><input type="hidden" name="action" value="add_period">
            <div class="modal-header"><h5 class="modal-title">New pay period</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><div class="row g-3">
                <div class="col-6"><label class="form-label">Start date</label><input type="date" name="start_date" class="form-control" required></div>
                <div class="col-6"><label class="form-label">End date</label><input type="date" name="end_date" class="form-control" required></div>
                <div class="col-12"><label class="form-label">Label (optional)</label><input type="text" name="period_label" maxlength="80" class="form-control" placeholder="Filled in automatically"></div>
            </div><div class="form-text mt-2">Periods cannot overlap and cannot be longer than 62 days.</div></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Create period</button></div>
        </form></div></div>
    <?php else:
        $emps = db_all('SELECT * FROM employees ORDER BY is_active DESC, full_name'); ?>
        <div class="app-card"><div class="table-responsive"><table class="table table-hover align-middle">
            <thead><tr><th>Employee</th><th>Department</th><th>Pay arrangement</th><th class="amount">Rate</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$emps): ?><tr><td colspan="6" class="text-center text-muted py-4">No employees yet. An administrator adds each employee with the real pay arrangement and rate. The system never guesses a salary.</td></tr><?php endif; ?>
            <?php foreach ($emps as $m): ?>
                <tr class="<?= $m['is_active'] ? '' : 'row-locked' ?>"><td><strong><?= e($m['full_name']) ?></strong><div class="small text-muted"><?= e($m['employee_code']) ?><?= $m['position_title'] ? ' &middot; ' . e($m['position_title']) : '' ?></div></td>
                    <td><?= e($m['department']) ?></td><td><?= e(PAY_TYPES[$m['pay_type']]) ?></td><td class="amount"><?= e(peso($m['pay_rate'])) ?></td>
                    <td><?= $m['is_active'] ? '<span class="badge badge-soft badge-sage">Active</span>' : '<span class="badge badge-soft badge-khaki">Inactive</span>' ?></td>
                    <td class="text-end"><?php if ($canConfig): ?><button class="btn btn-outline-primary btn-sm emp-btn" data-bs-toggle="modal" data-bs-target="#empModal" data-mode="edit" data-id="<?= (int)$m['employee_id'] ?>"
                        data-code="<?= e($m['employee_code']) ?>" data-name="<?= e($m['full_name']) ?>" data-dept="<?= e($m['department']) ?>" data-pos="<?= e($m['position_title'] ?? '') ?>"
                        data-type="<?= e($m['pay_type']) ?>" data-rate="<?= e(number_format((float)$m['pay_rate'], 2, '.', '')) ?>" data-active="<?= (int)$m['is_active'] ?>">Edit</button><?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div></div>
        <?php if ($canConfig): ?>
        <div class="modal fade" id="empModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form method="post" class="modal-content" autocomplete="off">
            <?= csrf_field() ?><input type="hidden" name="action" id="emAction" value="add_employee"><input type="hidden" name="employee_id" id="emId" value="0">
            <div class="modal-header"><h5 class="modal-title" id="emTitle">Add employee</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><div class="row g-3">
                <div class="col-5"><label class="form-label">Code</label><input type="text" name="employee_code" id="emCode" maxlength="20" class="form-control" required></div>
                <div class="col-7"><label class="form-label">Full name</label><input type="text" name="full_name" id="emName" maxlength="120" class="form-control" required></div>
                <div class="col-6"><label class="form-label">Department</label><input type="text" name="department" id="emDept" maxlength="80" class="form-control" required></div>
                <div class="col-6"><label class="form-label">Position (optional)</label><input type="text" name="position_title" id="emPos" maxlength="80" class="form-control"></div>
                <div class="col-6"><label class="form-label">Paid</label><select name="pay_type" id="emType" class="form-select" required><?php foreach (PAY_TYPES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label">Rate (&#8369;)</label><input type="number" step="0.01" min="0.01" name="pay_rate" id="emRate" class="form-control" required></div>
                <div class="col-12" id="emActiveBox"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="emActive" checked><label class="form-check-label" for="emActive">Active (included when payroll is generated)</label></div></div>
            </div><div class="form-text mt-2">Rate = monthly salary, daily rate or hourly rate, depending on "Paid". Changing a rate does not change payroll that was already generated.</div></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save employee</button></div>
        </form></div></div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.emp-btn').forEach(function (b) {
                b.addEventListener('click', function () {
                    var edit = b.dataset.mode === 'edit';
                    document.getElementById('emAction').value = edit ? 'edit_employee' : 'add_employee';
                    document.getElementById('emTitle').textContent = edit ? 'Edit employee' : 'Add employee';
                    document.getElementById('emId').value = edit ? b.dataset.id : '0';
                    document.getElementById('emCode').value = edit ? b.dataset.code : ''; document.getElementById('emName').value = edit ? b.dataset.name : '';
                    document.getElementById('emDept').value = edit ? b.dataset.dept : ''; document.getElementById('emPos').value = edit ? b.dataset.pos : '';
                    document.getElementById('emType').value = edit ? b.dataset.type : 'monthly'; document.getElementById('emRate').value = edit ? b.dataset.rate : '';
                    document.getElementById('emActive').checked = edit ? b.dataset.active === '1' : true;
                    document.getElementById('emActiveBox').classList.toggle('d-none', !edit);
                });
            });
        });
        </script>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
