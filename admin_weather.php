<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/includes/admin.php';
require_admin();
ensure_admin_schema();

$message = '';
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        $id = filter_var($_POST['advisory_id'] ?? '', FILTER_VALIDATE_INT);
        $status = (string) ($_POST['status'] ?? '');
        if (!$id || !in_array($status, ['Approved', 'Dismissed'], true)) {
            throw new RuntimeException('Invalid advisory action.');
        }
        $update = db()->prepare('UPDATE weather_advisory SET status = :status WHERE advisory_id = :id');
        $update->execute(['status' => $status, 'id' => $id]);
        staff_audit('WEATHER_ADVISORY_REVIEWED', 'weather_advisory', $id, ['status' => $status]);
        $message = 'Weather advisory review saved.';
    } catch (Throwable $exception) {
        error_log('Weather advisory review error: ' . $exception->getMessage());
        $errors[] = $exception->getMessage();
    }
}

$snapshot = latest_weather_snapshot();
$weather = $snapshot['weather'] ?? null;
$current = is_array($weather) ? ($weather['current'] ?? []) : [];
$hourly = is_array($weather) ? array_slice($weather['hourly'] ?? [], 0, 24) : [];
$forecast = is_array($weather) ? ($weather['forecast'] ?? []) : [];
$advisories = db()->query('SELECT * FROM weather_advisory ORDER BY created_at DESC LIMIT 20')->fetchAll();

$temperaturePoints = [];
$chartHours = [];
if (count($hourly) > 1) {
    $temperatures = array_map(static fn (array $hour): float => (float) ($hour['temperature'] ?? 0), $hourly);
    $minTemperature = min($temperatures);
    $temperatureRange = max(1.0, max($temperatures) - $minTemperature);
    foreach ($hourly as $index => $hour) {
        $x = 28 + ($index * 744 / (count($hourly) - 1));
        $y = 126 - (((float) ($hour['temperature'] ?? 0) - $minTemperature) / $temperatureRange * 82);
        $temperaturePoints[] = round($x, 1) . ',' . round($y, 1);
        if ($index % 5 === 0 || $index === count($hourly) - 1) {
            $chartHours[] = [
                'x' => round($x, 1),
                'time' => (new DateTimeImmutable((string) $hour['time'], new DateTimeZone(WEATHER_TIMEZONE)))->format('g A'),
            ];
        }
    }
}
$temperatureLine = implode(' ', $temperaturePoints);
$temperatureArea = count($temperaturePoints) > 1
    ? '28,142 ' . $temperatureLine . ' 772,142'
    : '';

$pageTitle = 'Weather dashboard';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page staff-page weather-dashboard">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="staff_dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Admin Portal</small></span></a>
        <?php require __DIR__ . '/includes/staff_nav.php'; ?>
    </aside>
    <section class="dashboard-content">
        <div class="staff-main weather-main">
            <header class="weather-page-heading">
                <div>
                    <p class="eyebrow"><i class="bi bi-cloud-sun"></i> LOCAL WEATHER MONITORING</p>
                    <h1>Weather dashboard</h1>
                    <p>Current conditions and forecast for <?= e(WEATHER_LOCATION) ?>.</p>
                </div>
                <div class="weather-auto-status"><span class="weather-live-dot"></span><span>Automatic refresh</span><strong>Every hour</strong></div>
            </header>

            <?php if ($message !== ''): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
            <?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>
            <?php if (!empty($snapshot['last_error'])): ?>
                <div class="alert alert-warning weather-sync-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <span>Last scheduled refresh failed: <?= e((string) $snapshot['last_error']) ?><?= !empty($snapshot['fetched_at']) ? ' Showing the latest successful data.' : '' ?></span>
                </div>
            <?php endif; ?>

            <?php if (is_array($weather) && is_array($current)): ?>
                <?php
                $sourceName = (string) ($weather['source_name'] ?? 'Weather provider');
                $currentCode = (int) ($current['weather_code'] ?? 3);
                $fetchedAt = !empty($snapshot['fetched_at'])
                    ? (new DateTimeImmutable((string) $snapshot['fetched_at']))->setTimezone(new DateTimeZone(WEATHER_TIMEZONE))
                    : null;
                ?>
                <section class="weather-current-card">
                    <div class="weather-current-backdrop-icon"><i class="bi <?= e(weather_icon_for($currentCode)) ?>"></i></div>
                    <div class="weather-current-overview">
                        <div class="weather-current-label"><i class="bi bi-geo-alt-fill"></i> <?= e((string) $weather['location']) ?></div>
                        <div class="weather-current-main">
                            <span class="weather-current-icon"><i class="bi <?= e(weather_icon_for($currentCode)) ?>"></i></span>
                            <div><strong><?= e(number_format((float) ($current['temperature'] ?? 0), 1)) ?><sup>°C</sup></strong><span><?= e((string) ($weather['summary'] ?? 'Conditions unavailable')) ?></span></div>
                        </div>
                        <div class="weather-current-meta">
                            <span><i class="bi bi-clock"></i> <?= e($fetchedAt ? $fetchedAt->format('D, M j · g:i A') : 'Fetch time unavailable') ?></span>
                            <span><i class="bi bi-cloud-check"></i> Source: <?= e($sourceName) ?></span>
                        </div>
                    </div>
                    <div class="weather-current-stats">
                        <article><span><i class="bi bi-droplet-half"></i> Humidity</span><strong><?= isset($current['relative_humidity']) && $current['relative_humidity'] !== null ? e((string) $current['relative_humidity']) . '%' : '—' ?></strong></article>
                        <article><span><i class="bi bi-cloud-rain"></i> Rain</span><strong><?= e(number_format((float) ($current['rain'] ?? 0), 1)) ?> <small>mm</small></strong></article>
                        <article><span><i class="bi bi-clouds"></i> Cloud cover</span><strong><?= isset($current['cloud_cover']) && $current['cloud_cover'] !== null ? e((string) $current['cloud_cover']) . '%' : '—' ?></strong></article>
                        <article><span><i class="bi bi-wind"></i> Wind</span><strong><?= e(number_format((float) ($current['wind_speed'] ?? 0), 1)) ?> <small>km/h</small></strong><small><?= e(weather_wind_direction((int) ($current['wind_direction'] ?? 0))) ?> direction</small></article>
                    </div>
                </section>

                <div class="weather-section-heading"><div><span class="weather-section-icon"><i class="bi bi-graph-up"></i></span><div><h2>Next 24 hours</h2><p>Hourly temperature trend and conditions</p></div></div><span><?= count($hourly) ?> hourly readings</span></div>
                <?php if (count($hourly) > 1): ?>
                    <section class="weather-chart-card">
                        <div class="weather-chart-title"><div><strong>Temperature trend</strong><span>°C · local time</span></div><span class="weather-chart-current"><?= e(number_format((float) ($current['temperature'] ?? 0), 1)) ?>°C now</span></div>
                        <div class="weather-chart-wrap">
                            <svg class="weather-temperature-chart" viewBox="0 0 800 164" role="img" aria-label="Hourly temperature forecast line chart">
                                <defs><linearGradient id="weatherAreaFill" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#f8a637" stop-opacity=".28"/><stop offset="100%" stop-color="#f8a637" stop-opacity="0"/></linearGradient></defs>
                                <line x1="28" y1="44" x2="772" y2="44" class="weather-chart-gridline"/>
                                <line x1="28" y1="85" x2="772" y2="85" class="weather-chart-gridline"/>
                                <line x1="28" y1="126" x2="772" y2="126" class="weather-chart-gridline"/>
                                <?php if ($temperatureArea !== ''): ?><polygon points="<?= e($temperatureArea) ?>" class="weather-chart-area"/><?php endif; ?>
                                <polyline points="<?= e($temperatureLine) ?>" class="weather-chart-line"/>
                                <?php foreach ($chartHours as $chartHour): ?><text x="<?= e((string) $chartHour['x']) ?>" y="158" class="weather-chart-label" text-anchor="middle"><?= e($chartHour['time']) ?></text><?php endforeach; ?>
                            </svg>
                        </div>
                        <div class="weather-hourly-strip">
                            <?php foreach (array_slice($hourly, 0, 8) as $hour): ?>
                                <article><span><?= e((new DateTimeImmutable((string) $hour['time'], new DateTimeZone(WEATHER_TIMEZONE)))->format('g A')) ?></span><i class="bi <?= e(weather_icon_for((int) ($hour['weather_code'] ?? 3))) ?>"></i><strong><?= e(number_format((float) ($hour['temperature'] ?? 0), 1)) ?>°</strong><small><i class="bi bi-droplet"></i> <?= e(number_format((float) ($hour['precipitation'] ?? 0), 1)) ?> mm</small></article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php else: ?>
                    <section class="weather-chart-card"><p class="weather-empty-state">Hourly forecast data will appear after the first scheduled refresh.</p></section>
                <?php endif; ?>

                <div class="weather-section-heading"><div><span class="weather-section-icon weather-section-icon-blue"><i class="bi bi-calendar-week"></i></span><div><h2>7-day forecast</h2><p>Plan ahead with daily conditions</p></div></div><span><?= count($forecast) ?> days</span></div>
                <section class="weather-forecast-grid">
                    <?php foreach ($forecast as $index => $day): ?>
                        <article class="weather-forecast-card <?= $index === 0 ? 'is-today' : '' ?>">
                            <div class="weather-forecast-day"><?= e($index === 0 ? 'Today' : (new DateTimeImmutable((string) $day['date']))->format('D')) ?><small><?= e((new DateTimeImmutable((string) $day['date']))->format('M j')) ?></small></div>
                            <i class="weather-forecast-icon bi <?= e(weather_icon_for((int) ($day['weather_code'] ?? 3))) ?>"></i>
                            <strong class="weather-forecast-summary"><?= e((string) $day['summary']) ?></strong>
                            <div class="weather-forecast-temperatures"><strong><?= e(number_format((float) ($day['temperature_max'] ?? 0), 0)) ?>°</strong><span><?= e(number_format((float) ($day['temperature_min'] ?? 0), 0)) ?>°</span></div>
                            <div class="weather-forecast-rain"><i class="bi bi-droplet"></i> <?= e(number_format((float) ($day['rain_sum'] ?? 0), 1)) ?> mm <span>rain</span></div>
                            <?php if (!empty($day['wind_speed_max'])): ?><div class="weather-forecast-wind"><i class="bi bi-wind"></i> <?= e(number_format((float) $day['wind_speed_max'], 0)) ?> km/h</div><?php endif; ?>
                            <?php if (!empty($day['sunrise']) || !empty($day['sunset'])): ?><div class="weather-forecast-sun"><span><i class="bi bi-sunrise"></i> <?= e($day['sunrise'] !== '' ? (new DateTimeImmutable((string) $day['sunrise'], new DateTimeZone(WEATHER_TIMEZONE)))->format('g:i A') : '—') ?></span><span><i class="bi bi-sunset"></i> <?= e($day['sunset'] !== '' ? (new DateTimeImmutable((string) $day['sunset'], new DateTimeZone(WEATHER_TIMEZONE)))->format('g:i A') : '—') ?></span></div><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </section>

                <div class="weather-recommendation <?= ($weather['severity'] ?? '') === 'Warning' ? 'is-warning' : (($weather['severity'] ?? '') === 'Watch' ? 'is-watch' : '') ?>">
                    <span><i class="bi <?= ($weather['severity'] ?? '') === 'Warning' ? 'bi-exclamation-triangle' : 'bi-lightbulb' ?>"></i></span>
                    <div><strong><?= e((string) ($weather['severity'] ?? 'Information')) ?> · Weather guidance</strong><p><?= e((string) ($weather['recommendation'] ?? 'No immediate weather announcement is suggested.')) ?></p></div>
                </div>
            <?php else: ?>
                <section class="weather-no-data">
                    <span><i class="bi bi-cloud-arrow-down"></i></span>
                    <h2>Weather data is being prepared</h2>
                    <p>The scheduled weather job will populate this dashboard automatically. The first reading appears after the hourly job runs.</p>
                    <?php if (empty($snapshot['last_error'])): ?><small>Automatic updates run every hour.</small><?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="weather-history-panel">
                <div class="weather-history-heading"><div><h2>Advisory review history</h2><p>Review and record weather advisories when needed.</p></div><i class="bi bi-journal-check"></i></div>
                <?php foreach ($advisories as $advisory): ?>
                    <article class="weather-history-row">
                        <span class="weather-history-icon"><i class="bi bi-cloud-sun"></i></span>
                        <div class="weather-history-details"><strong><?= e($advisory['location']) ?> · <?= e($advisory['weather_summary']) ?></strong><span><?= e((string) $advisory['temperature']) ?>°C · <?= e($advisory['recommendation']) ?></span><small><?= e((new DateTime((string) $advisory['created_at']))->format('M j, Y g:i A')) ?></small></div>
                        <span class="weather-history-status status-<?= e(strtolower((string) $advisory['status'])) ?>"><?= e($advisory['status']) ?></span>
                        <?php if ($advisory['status'] === 'Pending'): ?>
                            <form method="post" class="weather-history-actions">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="advisory_id" value="<?= (int) $advisory['advisory_id'] ?>">
                                <button class="btn btn-sm btn-success" name="status" value="Approved">Approve</button>
                                <button class="btn btn-sm btn-outline-secondary" name="status" value="Dismissed">Dismiss</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if ($advisories === []): ?><p class="weather-empty-state">No advisories have been submitted for review.</p><?php endif; ?>
            </section>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
