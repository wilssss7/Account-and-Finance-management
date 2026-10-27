<?php
/**
 * period-closing.php - Financial Period Closing (module 32)
 * A manager closes a period after reviewing warnings (pending payments, unresolved discrepancies, pending approvals).
 * A closed period cannot be changed: every module refuses to create or modify records dated in it.
 * Reopening needs a documented authorization, which is stored on the period and in the audit trail.
 */
require_once __DIR__ . '/includes/finance.php';

require_login();
require_permission(['period.close', 'period.reopen']);

$pdo = db();
$me  = current_user();
$canClose  = has_permission('period.close');
$canReopen = has_permission('period.reopen');

/** Items that should be resolved before a period is closed. */
function period_warnings(array $p): array
{
    $id = (int)$p['period_id']; $s = $p['start_date']; $e = $p['end_date'];
    return [
        ['Pending payments awaiting verification', (int)db_value("SELECT COUNT(*) FROM payments WHERE period_id = ? AND status = 'pending'", [$id]), 'payment-verification.php?status=pending'],
        ['Refunds not yet completed', (int)db_value("SELECT COUNT(*) FROM refunds WHERE status IN ('pending', 'approved') AND DATE(created_at) BETWEEN ? AND ?", [$s, $e]), 'refunds.php'],
        ['Discounts waiting for approval', (int)db_value("SELECT COUNT(*) FROM discounts d JOIN invoices i ON i.invoice_id = d.invoice_id WHERE i.period_id = ? AND d.status = 'pending'", [$id]), 'discounts.php?status=pending'],
        ['Billing adjustments waiting for approval', (int)db_value("SELECT COUNT(*) FROM billing_adjustments a JOIN invoices i ON i.invoice_id = a.invoice_id WHERE i.period_id = ? AND a.status = 'pending'", [$id]), 'billing-adjustments.php?status=pending'],
        ['Unresolved cash discrepancies', (int)db_value("SELECT COUNT(*) FROM cash_reconciliations WHERE recon_date BETWEEN ? AND ? AND status = 'discrepancy'", [$s, $e]), 'cash-reconciliation.php?status=discrepancy'],
        ['Unmatched or discrepant provider transactions', (int)db_value("SELECT COUNT(*) FROM payment_reconciliations WHERE statement_date BETWEEN ? AND ? AND status IN ('unmatched', 'discrepant')", [$s, $e]), 'payment-reconciliation.php'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $periodId = (int)($_POST['period_id'] ?? 0);
    try {
        $pdo->beginTransaction();
        $p = db_one('SELECT * FROM financial_periods WHERE period_id = ? FOR UPDATE', [$periodId]);
        if (!$p) { throw new RuntimeException('Period not found.'); }

        if ($action === 'close') {
            if (!$canClose) { throw new RuntimeException('Your role cannot close periods.'); }
            if ($p['status'] !== 'open') { throw new RuntimeException($p['period_name'] . ' is already closed.'); }
            if ($p['end_date'] >= date('Y-m-d')) { throw new RuntimeException($p['period_name'] . ' has not ended yet. It can be closed after ' . format_date($p['end_date']) . '.'); }
            $warnings = period_warnings($p);
            $count = array_sum(array_column($warnings, 1));
            $notes = clean_text($_POST['closing_notes'] ?? '', 255);
            if ($count > 0) {
                if (!isset($_POST['acknowledge'])) { throw new RuntimeException('Tick the box to confirm that you reviewed the warnings.'); }
                if (mb_strlen($notes) < 10) { throw new RuntimeException('Explain why the period is closed with unresolved items (at least 10 characters).'); }
            }
            $pdo->prepare("UPDATE financial_periods SET status = 'closed', closed_by = ?, closed_at = NOW(), closing_notes = ? WHERE period_id = ?")
                ->execute([(int)$me['id'], $notes !== '' ? $notes : ($count == 0 ? 'Closed with no unresolved items.' : null), $periodId]);
            audit_log('period_closed', 'financial_periods', $periodId, ['status' => 'open'],
                ['status' => 'closed', 'unresolved_items' => $count, 'warnings' => array_combine(array_column($warnings, 0), array_column($warnings, 1))], $notes !== '' ? $notes : 'No unresolved items');
            $pdo->commit();
            set_flash('success', $p['period_name'] . ' is now closed.' . ($count > 0 ? " It was closed with $count unresolved item(s)." : ''));

        } elseif ($action === 'reopen') {
            if (!$canReopen) { throw new RuntimeException('Your role cannot reopen periods.'); }
            if ($p['status'] !== 'closed') { throw new RuntimeException($p['period_name'] . ' is already open.'); }
            $auth = clean_text($_POST['authorization'] ?? '', 255);
            if (mb_strlen($auth) < 10) { throw new RuntimeException('Document the authorization: who approved the reopening and why (at least 10 characters).'); }
            $pdo->prepare("UPDATE financial_periods SET status = 'open', reopened_by = ?, reopened_at = NOW(), reopen_authorization = ? WHERE period_id = ?")
                ->execute([(int)$me['id'], $auth, $periodId]);
            audit_log('period_reopened', 'financial_periods', $periodId, ['status' => 'closed'], ['status' => 'open'], $auth);
            $pdo->commit();
            set_flash('warning', $p['period_name'] . ' was reopened. Close it again when the corrections are finished.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!($ex instanceof RuntimeException)) { error_log($ex->getMessage()); }
        set_flash('danger', $ex instanceof RuntimeException ? $ex->getMessage() : 'The action could not be completed. Please try again.');
        redirect('period-closing.php?' . ($action === 'reopen' ? 'reopen=' : 'close=') . $periodId);
    }
    redirect('period-closing.php');
}

/* ============================ Close / reopen review ============================ */
$closeId  = (int)($_GET['close'] ?? 0);
$reopenId = (int)($_GET['reopen'] ?? 0);
if ($closeId > 0 || $reopenId > 0) {
    $p = db_one('SELECT * FROM financial_periods WHERE period_id = ?', [$closeId ?: $reopenId]);
    if (!$p) { set_flash('danger', 'Period not found.'); redirect('period-closing.php'); }
    $isClose = $closeId > 0;
    $warnings = period_warnings($p);
    $count = array_sum(array_column($warnings, 1));
    $incPayroll = has_permission('payroll.view');
    $rev = (float)db_value('SELECT COALESCE(SUM(amount), 0) FROM v_revenue_events WHERE event_date BETWEEN ? AND ?', [$p['start_date'], $p['end_date']]);
    $exp = (float)db_value('SELECT COALESCE(SUM(amount), 0) FROM v_expense_events WHERE event_date BETWEEN ? AND ?' . ($incPayroll ? '' : " AND source <> 'payroll'"), [$p['start_date'], $p['end_date']]);
    $pageTitle = ($isClose ? 'Close ' : 'Reopen ') . $p['period_name'];
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="mb-3"><a href="<?= e(url('period-closing.php')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>All periods</a></div>
    <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="app-card stat-card"><div class="stat-label">Period</div><div class="stat-value"><?= e($p['period_name']) ?></div><div class="stat-note"><?= e(format_date($p['start_date'])) ?> - <?= e(format_date($p['end_date'])) ?> &middot; <?= status_badge($p['status']) ?></div></div></div>
        <div class="col-md-4"><div class="app-card stat-card"><div class="stat-label">Revenue</div><div class="stat-value"><?= e(peso($rev)) ?></div><div class="stat-note">Verified payments less refunds</div></div></div>
        <div class="col-md-4"><div class="app-card stat-card"><div class="stat-label">Expenses</div><div class="stat-value"><?= e(peso($exp)) ?></div><div class="stat-note">Net result <?= e(peso($rev - $exp)) ?></div></div></div>
    </div>

    <?php if ($isClose): ?>
        <div class="app-card mb-3"><div class="card-header"><h3 class="card-title-serif">Review before closing</h3></div>
            <div class="table-responsive"><table class="table"><tbody>
                <?php foreach ($warnings as [$label, $n, $link]): ?>
                    <tr><td><?= e($label) ?></td><td class="text-end"><?= $n > 0 ? '<span class="badge badge-soft badge-terracotta">' . $n . '</span>' : '<span class="badge badge-soft badge-sage">0</span>' ?></td>
                        <td class="text-end"><?php if ($n > 0): ?><a href="<?= e(url($link)) ?>" class="btn btn-outline-primary btn-sm">Review</a><?php endif; ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div></div>

        <?php if ($p['status'] !== 'open'): ?>
            <div class="alert alert-info">This period is already closed.</div>
        <?php elseif ($p['end_date'] >= date('Y-m-d')): ?>
            <div class="alert alert-warning">This period has not ended yet. It can be closed after <?= e(format_date($p['end_date'])) ?>.</div>
        <?php elseif ($canClose): ?>
            <form method="post" class="app-card"><div class="card-body">
                <?= csrf_field() ?><input type="hidden" name="action" value="close"><input type="hidden" name="period_id" value="<?= (int)$p['period_id'] ?>">
                <?php if ($count > 0): ?>
                    <div class="alert alert-warning"><strong><?= (int)$count ?> unresolved item(s)</strong> were found. You can still close the period, but you must explain why.</div>
                    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="acknowledge" id="ack" required><label class="form-check-label" for="ack">I reviewed the warnings above and want to close this period anyway.</label></div>
                <?php else: ?><div class="alert alert-success">No unresolved items. The period is ready to close.</div><?php endif; ?>
                <div class="mb-3"><label class="form-label">Closing notes<?= $count > 0 ? '' : ' (optional)' ?></label>
                    <input type="text" name="closing_notes" maxlength="255" class="form-control" <?= $count > 0 ? 'required minlength="10"' : '' ?>></div>
                <p class="small text-muted">After closing, payments, expenses, refunds, discounts and other records dated in this period can no longer be created or changed.</p>
                <button class="btn btn-primary" type="submit" data-confirm="Close <?= e($p['period_name']) ?>? Records dated in it will be locked.">Close period</button>
            </div></form>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($p['status'] !== 'closed'): ?><div class="alert alert-info">This period is already open.</div>
        <?php elseif ($canReopen): ?>
            <form method="post" class="app-card"><div class="card-body">
                <?= csrf_field() ?><input type="hidden" name="action" value="reopen"><input type="hidden" name="period_id" value="<?= (int)$p['period_id'] ?>">
                <div class="alert alert-warning">Reopening unlocks every record dated in <?= e($p['period_name']) ?>. Use it only for approved corrections.</div>
                <div class="mb-3"><label class="form-label">Documented authorization</label>
                    <input type="text" name="authorization" maxlength="255" class="form-control" required minlength="10" placeholder="Who approved it, the reference, and why (for example: GM approved memo 12 to correct a supplier payment)"></div>
                <button class="btn btn-danger" type="submit" data-confirm="Reopen <?= e($p['period_name']) ?>?">Reopen period</button>
            </div></form>
        <?php endif; ?>
    <?php endif; ?>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

/* ================================ Period list ================================ */
$periods = db_all('SELECT p.*, uc.full_name AS closer, ur.full_name AS reopener FROM financial_periods p
                   LEFT JOIN users uc ON uc.user_id = p.closed_by LEFT JOIN users ur ON ur.user_id = p.reopened_by
                   ORDER BY p.start_date DESC LIMIT 36');
$incPayroll = has_permission('payroll.view');
$pageTitle = 'Period Closing';
include __DIR__ . '/includes/header.php';
?>
<div class="alert alert-info py-2 small">Closing a period locks every record dated in it. Periods are created automatically, one per month, the first time a record is dated in that month.</div>
<div class="app-card"><div class="table-responsive">
    <table class="table table-hover">
        <thead><tr><th>Period</th><th>Dates</th><th class="amount">Revenue</th><th class="amount">Expenses</th><th class="amount">Net</th><th>Status</th><th>Open items</th><th></th></tr></thead>
        <tbody>
        <?php if (!$periods): ?><tr><td colspan="8" class="text-center text-muted py-4">No periods yet.</td></tr><?php endif; ?>
        <?php foreach ($periods as $p):
            $rev = (float)db_value('SELECT COALESCE(SUM(amount), 0) FROM v_revenue_events WHERE event_date BETWEEN ? AND ?', [$p['start_date'], $p['end_date']]);
            $exp = (float)db_value('SELECT COALESCE(SUM(amount), 0) FROM v_expense_events WHERE event_date BETWEEN ? AND ?' . ($incPayroll ? '' : " AND source <> 'payroll'"), [$p['start_date'], $p['end_date']]);
            $open = $p['status'] === 'open' ? array_sum(array_column(period_warnings($p), 1)) : null; ?>
            <tr>
                <td><strong><?= e($p['period_name']) ?></strong></td>
                <td class="small"><?= e(format_date($p['start_date'], 'M d')) ?> - <?= e(format_date($p['end_date'], 'M d, Y')) ?></td>
                <td class="amount"><?= e(peso($rev)) ?></td><td class="amount"><?= e(peso($exp)) ?></td>
                <td class="amount <?= $rev - $exp >= 0 ? 'value-positive' : 'value-negative' ?>"><?= e(peso($rev - $exp)) ?></td>
                <td><?= status_badge($p['status']) ?>
                    <?php if ($p['status'] === 'closed' && $p['closer']): ?><div class="small text-muted"><?= e($p['closer']) ?>, <?= e(format_date($p['closed_at'], 'M d, Y')) ?></div><?php endif; ?>
                    <?php if ($p['reopen_authorization']): ?><div class="small text-muted" title="<?= e($p['reopen_authorization']) ?>">Reopened by <?= e($p['reopener'] ?? '-') ?></div><?php endif; ?></td>
                <td><?= $open === null ? '<span class="text-muted">-</span>' : ($open > 0 ? '<span class="badge badge-soft badge-terracotta">' . (int)$open . '</span>' : '<span class="badge badge-soft badge-sage">0</span>') ?></td>
                <td class="text-end text-nowrap">
                    <?php if ($p['status'] === 'open' && $canClose): ?><a class="btn btn-primary btn-sm" href="<?= e(url('period-closing.php?close=' . (int)$p['period_id'])) ?>">Review and close</a>
                    <?php elseif ($p['status'] === 'closed' && $canReopen): ?><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('period-closing.php?reopen=' . (int)$p['period_id'])) ?>">Reopen</a><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
