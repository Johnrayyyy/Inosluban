<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/admin.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!is_logged_in() || ($_SESSION['user']['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Administrator access is required.']);
    exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
    exit;
}

try {
    $result = refresh_weather_snapshot_if_due();
    echo json_encode(['ok' => true] + $result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Admin-login weather refresh failed: ' . $exception->getMessage());
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'message' => 'Weather refresh failed. The latest saved weather remains available.',
    ], JSON_THROW_ON_ERROR);
}
