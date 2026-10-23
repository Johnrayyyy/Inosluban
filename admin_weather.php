<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/includes/admin.php';
require_admin();
ensure_admin_schema();
$errors = [];
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        if (($_POST['action'] ?? '') === 'fetch') {
            $weather = fetch_weather_advisory();
            $insert = db()->prepare('INSERT INTO weather_advisory (created_by_user_id, location, weather_summary, temperature, severity, recommendation, source_url) VALUES (:user_id, :location, :summary, :temperature, :severity, :recommendation, :source_url) RETURNING advisory_id');
            $insert->execute(['user_id' => $_SESSION['user']['id'], 'location' => $weather['location'], 'summary' => $weather['summary'], 'temperature' => $weather['temperature'], 'severity' => $weather['severity'], 'recommendation' => $weather['recommendation'], 'source_url' => $weather['source_url']]);
            staff_audit('WEATHER_ADVISORY_FETCHED', 'weather_advisory', (int) $insert->fetchColumn(), $weather);
            $message = 'Weather advisory fetched for review. It was not published automatically.';
        } else {
            $id = filter_var($_POST['advisory_id'] ?? '', FILTER_VALIDATE_INT);
            $status = (string) ($_POST['status'] ?? '');
            if (!$id || !in_array($status, ['Approved', 'Dismissed'], true)) {
                throw new RuntimeException('Invalid advisory action.');
            }
            $update = db()->prepare('UPDATE weather_advisory SET status = :status WHERE advisory_id = :id');
            $update->execute(['status' => $status, 'id' => $id]);
            staff_audit('WEATHER_ADVISORY_REVIEWED', 'weather_advisory', $id, ['status' => $status]);
            $message = 'Weather advisory review saved.';
        }
    } catch (Throwable $exception) {
        error_log('Weather advisory error: ' . $exception->getMessage());
        $errors[] = $exception->getMessage();
    }
}
$advisories = db()->query('SELECT * FROM weather_advisory ORDER BY created_at DESC LIMIT 20')->fetchAll();
$pageTitle = 'Weather advisory';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page staff-page"><aside class="dashboard-sidebar"><a class="dashboard-brand" href="staff_dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Admin Portal</small></span></a><?php require __DIR__ . '/includes/staff_nav.php'; ?></aside><section class="dashboard-content"><div class="staff-main"><div class="staff-hero compact"><div><p class="eyebrow">WEATHER MONITORING</p><h1>Weather advisory review</h1><p>Fetch conditions for Lipa City and decide whether an announcement is needed.</p></div></div><?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?><section class="staff-panel p-4 mb-3"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="fetch"><button class="btn btn-primary" type="submit"><i class="bi bi-cloud-download"></i> Fetch current weather</button><span class="ms-2 text-muted">Requires WEATHER_API_KEY in .env.</span></form></section><section class="staff-panel p-3"><h2 class="h5">Advisory history</h2><?php foreach ($advisories as $advisory): ?><div class="border-bottom py-3 d-flex justify-content-between gap-3"><div><strong><?= e($advisory['location']) ?> · <?= e($advisory['weather_summary']) ?></strong><p class="mb-1 text-muted"><?= e($advisory['temperature']) ?>°C · <?= e($advisory['recommendation']) ?></p><small><?= e($advisory['status']) ?> · <?= e((new DateTime($advisory['created_at']))->format('M j, Y g:i A')) ?></small></div><?php if ($advisory['status'] === 'Pending'): ?><form method="post" class="d-flex gap-2 align-items-start"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="advisory_id" value="<?= (int) $advisory['advisory_id'] ?>"><button class="btn btn-sm btn-success" name="status" value="Approved">Approve</button><button class="btn btn-sm btn-outline-secondary" name="status" value="Dismissed">Dismiss</button></form><?php endif; ?></div><?php endforeach; ?><?php if ($advisories === []): ?><p class="text-muted">No weather data has been fetched yet.</p><?php endif; ?></section></div></section></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
