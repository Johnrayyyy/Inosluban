<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
if (
    ($_SESSION['user']['role'] ?? '') === 'staff'
    && isset($_SESSION['user']['id'], $_SESSION['staff_time_log_id'])
) {
    staff_clock_out(
        (int) $_SESSION['staff_time_log_id'],
        (int) $_SESSION['user']['id']
    );
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: index.php');
exit;
