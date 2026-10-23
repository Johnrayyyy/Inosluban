<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/gemini.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Only POST requests are supported.');
    }
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $message = trim((string) ($_POST['message'] ?? ''));
    if ($message === '' || strlen($message) > 4000) {
        throw new RuntimeException('Please enter a message between 1 and 4,000 characters.');
    }
    if (!is_barangay_question($message)) {
        throw new RuntimeException('I can only answer questions about Barangay Inosluban, its residents, announcements, appointments, document requests, blotter reports, and portal services.');
    }
    $isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
    $prompt = barangay_ai_context($isAdmin)
        . ($isAdmin
            ? 'You are the Barangay Inosluban decision-support assistant. Answer only about Barangay Inosluban, its administration, residents, programs, services, and portal data. Refuse unrelated topics. Give useful, concise answers, label assumptions, and never invent official facts. '
            : 'You are the Barangay Inosluban resident assistant. Answer only about Barangay Inosluban, its residents, announcements, appointments, document requests, blotter reports, and portal services. Refuse unrelated topics. Use the supplied knowledge. Do not expose private resident data or claim approvals. If unknown, say so and direct the resident to the barangay office. ')
        . 'User message: ' . $message;
    echo json_encode(['ok' => true, 'message' => ask_gemini($prompt)], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('AI chat error: ' . $exception->getMessage());
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()]);
}
