<?php
/**
 * notifications.php - small endpoint for the notification bell.
 *   GET  ?go=ID            marks that notification as read and opens the page it is about
 *   POST action=read       marks one notification as read   (id)
 *   POST action=read_all   marks every visible notification as read
 * Only notifications the user's role may see can be changed. All POSTs need a CSRF token.
 */
require_once __DIR__ . '/includes/notifications.php';

require_login();
$me    = current_user();
$perms = $_SESSION['permissions'] ?? [];
$isLocal = static fn(string $l): bool => (bool)preg_match('/^[a-z0-9\-]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $l);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ajax = isset($_POST['ajax']);
    $ok = true;
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'read') { notif_mark_read((int)($_POST['id'] ?? 0), (int)$me['id'], $perms); }
        elseif ($action === 'read_all') { notif_mark_all_read((int)$me['id'], $perms); }
        else { $ok = false; }
    } catch (Throwable $ex) {
        error_log('Notification update failed: ' . $ex->getMessage());
        $ok = false;
    }
    if ($ajax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'unread' => $ok ? notifications_unread_count((int)$me['id'], $perms) : null]);
        exit;
    }
    $back = (string)($_POST['return'] ?? '');
    redirect($isLocal($back) ? $back : 'dashboard.php');
}

$go = (int)($_GET['go'] ?? 0);
if ($go > 0) {
    try {
        $n = notif_mark_read($go, (int)$me['id'], $perms);
        if ($n !== null && $isLocal((string)$n['link'])) { redirect((string)$n['link']); }
    } catch (Throwable $ex) {
        error_log('Notification open failed: ' . $ex->getMessage());
    }
}
redirect('dashboard.php');
