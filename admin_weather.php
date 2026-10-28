<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/includes/admin.php';
require_admin();
ensure_admin_schema();
$errors = [];
$message = '';
$weather = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        if (($_POST['action'] ?? '') === 'fetch') {
            $weather = fetch_weather_advisory();
            $weatherData = json_encode(
                ['current' => $weather['current'], 'hourly' => $weather['hourly'], 'forecast' => $weather['forecast']],
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
            );
            $insert = db()->prepare('INSERT INTO weather_advisory (created_by_user_id, location, weather_summary, temperature, severity, recommendation, source_url, weather_data) VALUES (:user_id, :location, :summary, :temperature, :severity, :recommendation, :source_url, CAST(:weather_data AS JSONB)) RETURNING advisory_id');
            $insert->execute(['user_id' => $_SESSION['user']['id'], 'location' => $weather['location'], 'summary' => $weather['summary'], 'temperature' => $weather['temperature'], 'severity' => $weather['severity'], 'recommendation' => $weather['recommendation'], 'source_url' => $weather['source_url'], 'weather_data' => $weatherData]);
            staff_audit('WEATHER_ADVISORY_FETCHED', 'weather_advisory', (int) $insert->fetchColumn(), [
                'location' => $weather['location'],
                'summary' => $weather['summary'],
                'severity' => $weather['severity'],
                'hourly_records' => count($weather['hourly']),
                'forecast_days' => count($weather['forecast']),
            ]);
            $_SESSION['weather_snapshot'] = [
                'location' => $weather['location'],
                'summary' => $weather['summary'],
                'temperature' => $weather['temperature'],
                'severity' => $weather['severity'],
                'recommendation' => $weather['recommendation'],
                'source_url' => $weather['source_url'],
                'current' => $weather['current'],
                'hourly' => $weather['hourly'],
                'forecast' => $weather['forecast'],
            ];
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
$snapshot = $_SESSION['weather_snapshot'] ?? $weather;
if (!is_array($snapshot['current'] ?? null) || !is_array($snapshot['forecast'] ?? null)) {
    $snapshot = null;
}
$advisories = db()->query('SELECT * FROM weather_advisory ORDER BY created_at DESC LIMIT 20')->fetchAll();
$pageTitle = 'Weather advisory';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page staff-page"><aside class="dashboard-sidebar"><a class="dashboard-brand" href="staff_dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Admin Portal</small></span></a><?php require __DIR__ . '/includes/staff_nav.php'; ?></aside><section class="dashboard-content"><div class="staff-main"><div class="staff-hero compact"><div><p class="eyebrow">WEATHER MONITORING</p><h1>Weather advisory review</h1><p>Fetch conditions for Lipa City and decide whether an announcement is needed.</p></div></div><?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?><section class="staff-panel p-4 mb-3"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="fetch"><button class="btn btn-primary" type="submit"><i class="bi bi-cloud-download"></i> Fetch current weather</button><span class="ms-2 text-muted">Uses Open-Meteo and requires no API key.</span></form></section><?php if ($snapshot): ?><section class="staff-panel p-4 mb-3"><div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3"><div><p class="eyebrow mb-1">LATEST FETCH</p><h2 class="h5 mb-1"><?= e($snapshot['location']) ?> &middot; <?= e($snapshot['summary']) ?></h2><p class="text-muted mb-0"><?= e($snapshot['current']['temperature']) ?>&deg;C &middot; wind <?= e($snapshot['current']['wind_speed']) ?> km/h <?= e(weather_wind_direction($snapshot['current']['wind_direction'])) ?> &middot; <?= e($snapshot['current']['time'] !== '' ? (new DateTime($snapshot['current']['time'], new DateTimeZone(WEATHER_TIMEZONE)))->format('M j, Y g:i A') : 'time unavailable') ?></p></div><span class="badge <?= $snapshot['severity'] === 'Warning' ? 'text-bg-danger' : ($snapshot['severity'] === 'Watch' ? 'text-bg-warning' : 'text-bg-info') ?>"><?= e($snapshot['severity']) ?></span></div><div class="row g-3 mb-3"><div class="col-6 col-lg-3"><div class="border rounded p-3 h-100"><small class="text-muted d-block">Temperature</small><strong class="fs-5"><?= e($snapshot['current']['temperature']) ?>&deg;C</strong></div></div><div class="col-6 col-lg-3"><div class="border rounded p-3 h-100"><small class="text-muted d-block">Rain</small><strong class="fs-5"><?= e(number_format($snapshot['current']['rain'], 1)) ?> mm</strong></div></div><div class="col-6 col-lg-3"><div class="border rounded p-3 h-100"><small class="text-muted d-block">Cloud cover</small><strong class="fs-5"><?= e($snapshot['current']['cloud_cover']) ?>%</strong></div></div><div class="col-6 col-lg-3"><div class="border rounded p-3 h-100"><small class="text-muted d-block">Wind</small><strong class="fs-5"><?= e($snapshot['current']['wind_speed']) ?> km/h</strong></div></div></div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Day</th><th>Conditions</th><th>Min</th><th>Max</th><th>Rain</th><th>Sunrise</th><th>Sunset</th></tr></thead><tbody><?php foreach ($snapshot['forecast'] as $day): ?><tr><td><?= e((new DateTime($day['date']))->format('D, M j')) ?></td><td><?= e($day['summary']) ?></td><td><?= e($day['temperature_min']) ?>&deg;C</td><td><?= e($day['temperature_max']) ?>&deg;C</td><td><?= e(number_format($day['rain_sum'], 1)) ?> mm</td><td><?= e($day['sunrise'] !== '' ? substr($day['sunrise'], 11) : 'n/a') ?></td><td><?= e($day['sunset'] !== '' ? substr($day['sunset'], 11) : 'n/a') ?></td></tr><?php endforeach; ?></tbody></table></div><p class="text-muted small mt-3 mb-0"><?= e($snapshot['recommendation']) ?> Source: <a href="<?= e($snapshot['source_url']) ?>" target="_blank" rel="noopener">Open-Meteo</a>.</p></section><?php endif; ?><section class="staff-panel p-3"><h2 class="h5">Advisory history</h2><?php foreach ($advisories as $advisory): ?><div class="border-bottom py-3 d-flex justify-content-between gap-3"><div><strong><?= e($advisory['location']) ?> · <?= e($advisory['weather_summary']) ?></strong><p class="mb-1 text-muted"><?= e($advisory['temperature']) ?>°C · <?= e($advisory['recommendation']) ?></p><small><?= e($advisory['status']) ?> · <?= e((new DateTime($advisory['created_at']))->format('M j, Y g:i A')) ?></small></div><?php if ($advisory['status'] === 'Pending'): ?><form method="post" class="d-flex gap-2 align-items-start"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="advisory_id" value="<?= (int) $advisory['advisory_id'] ?>"><button class="btn btn-sm btn-success" name="status" value="Approved">Approve</button><button class="btn btn-sm btn-outline-secondary" name="status" value="Dismissed">Dismiss</button></form><?php endif; ?></div><?php endforeach; ?><?php if ($advisories === []): ?><p class="text-muted">No weather data has been fetched yet.</p><?php endif; ?></section></div></section></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
