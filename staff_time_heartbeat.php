<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SESSION['user']['role'] ?? '') !== 'staff') {
    http_response_code(403);
    echo json_encode(['active' => false, 'error' => 'Staff login is required.']);
    exit;
}

try {
    verify_csrf_token((string) ($_POST['csrf_token'] ?? ''));
    $timeLogId = filter_var($_SESSION['staff_time_log_id'] ?? '', FILTER_VALIDATE_INT);
    if (!$timeLogId || !staff_touch_time_log((int) $timeLogId, (int) $_SESSION['user']['id'])) {
        unset($_SESSION['staff_time_log_id']);
        echo json_encode(['active' => false]);
        exit;
    }

    echo json_encode(['active' => true, 'server_now' => time()]);
} catch (Throwable $exception) {
    error_log('Staff time heartbeat error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['active' => false, 'error' => 'Time tracking could not be updated.']);
}
