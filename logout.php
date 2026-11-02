<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
$isStaff = ($_SESSION['user']['role'] ?? '') === 'staff';
$staffTimeLogId = filter_var($_SESSION['staff_time_log_id'] ?? '', FILTER_VALIDATE_INT);
$userId = filter_var($_SESSION['user']['id'] ?? '', FILTER_VALIDATE_INT);
$elapsedSeconds = $isStaff && $staffTimeLogId && $userId
    ? staff_time_log_elapsed_seconds((int) $staffTimeLogId, (int) $userId)
    : null;
$isEarlyStaffLogout = $elapsedSeconds !== null && $elapsedSeconds < 8 * 60 * 60;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token((string) ($_POST['csrf_token'] ?? ''));
    if (($_POST['action'] ?? '') !== 'logout') {
        http_response_code(400);
        exit('Invalid logout request.');
    }
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $isEarlyStaffLogout) {
    $hours = intdiv($elapsedSeconds, 3600);
    $minutes = intdiv($elapsedSeconds % 3600, 60);
    $pageTitle = 'Confirm early logout';
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($pageTitle) ?> | <?= APP_NAME ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <main class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
            <section class="card shadow-sm w-100" style="max-width: 34rem">
                <div class="card-body p-4 p-md-5">
                    <h1 class="h4 mb-3">Leaving before completing 8 hours?</h1>
                    <div class="alert alert-warning" role="alert">
                        Your recorded work time is <?= $hours ?> hour<?= $hours === 1 ? '' : 's' ?> and <?= $minutes ?> minute<?= $minutes === 1 ? '' : 's' ?>. If you continue, your time will be clocked out and your staff session will end.
                    </div>
                    <form method="post" class="d-flex flex-wrap gap-2">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="logout">
                        <button class="btn btn-danger" type="submit">End session and log out</button>
                        <a class="btn btn-outline-secondary" href="staff_dashboard.php">Continue working</a>
                    </form>
                </div>
            </section>
        </main>
    </body>
    </html>
    <?php
    exit;
}

if ($isStaff && $staffTimeLogId && $userId) {
    staff_clock_out(
        (int) $staffTimeLogId,
        (int) $userId
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
