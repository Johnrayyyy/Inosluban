<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/analytics.php';
require_once __DIR__ . '/includes/gemini.php';

require_admin();
ensure_admin_schema();
$refreshWeatherOnLogin = !empty($_SESSION['weather_refresh_on_login']);
unset($_SESSION['weather_refresh_on_login']);

$aiPrediction = '';
$aiError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        if (($_POST['action'] ?? '') !== 'predict') {
            throw new RuntimeException('Invalid analytics action.');
        }
        $aiPrediction = ask_gemini(admin_analytics_ai_prompt(admin_analytics_data()));
    } catch (Throwable $exception) {
        error_log('Admin analytics AI error: ' . $exception->getMessage());
        $aiError = $exception->getMessage();
    }
}

$analytics = admin_analytics_data();
$overview = $analytics['overview'];
$monthCounts = array_column($analytics['monthly_documents'], 'count');
$maxMonthCount = max(1, ...$monthCounts);
$maxWeekdayCount = max(1, ...array_column($analytics['weekday_documents'], 'count'));
$maxDocumentTypeCount = max(1, ...array_column($analytics['document_types'], 'count'));
$maxWeatherTemperature = max(1, ...array_map(
    static fn (array $row): float => (float) ($row['average_temperature'] ?? 0),
    $analytics['weather_history']
));
$maxStaffHours = max(1, ...array_column($analytics['staff_worktime'], 'hours'));
$snapshot = latest_weather_snapshot();
$latestWeather = $snapshot['weather'] ?? null;
$pageTitle = 'Admin analytics dashboard';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page staff-page analytics-dashboard"<?= $refreshWeatherOnLogin ? ' data-weather-refresh-on-login data-csrf-token="' . e(csrf_token()) . '"' : '' ?>>
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="admin_analytics.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Admin Portal</small></span></a>
        <?php require __DIR__ . '/includes/staff_nav.php'; ?>
    </aside>
    <section class="dashboard-content">
        <div class="staff-main analytics-main">
            <header class="analytics-heading">
                <div><p class="eyebrow"><i class="bi bi-bar-chart-line"></i> ADMIN OVERVIEW</p><h1>Data analytics</h1><p>Service activity, workload, weather signals, and operational insights.</p></div>
                <span class="analytics-updated"><i class="bi bi-clock-history"></i> Updated <?= e((new DateTimeImmutable($analytics['generated_at']))->format('M j, Y · g:i A')) ?></span>
            </header>

            <section class="analytics-kpi-grid" aria-label="Barangay operations summary">
                <article class="analytics-kpi analytics-kpi-residents"><span class="analytics-kpi-icon"><i class="bi bi-people"></i></span><div><small>Residents</small><strong><?= number_format($overview['residents']) ?></strong><span>registered resident records</span></div></article>
                <article class="analytics-kpi analytics-kpi-households"><span class="analytics-kpi-icon"><i class="bi bi-houses"></i></span><div><small>Households</small><strong><?= number_format($overview['households']) ?></strong><span>registered household records</span></div></article>
                <article class="analytics-kpi analytics-kpi-documents"><span class="analytics-kpi-icon"><i class="bi bi-file-earmark-text"></i></span><div><small>Document requests</small><strong><?= number_format($overview['document_requests']) ?></strong><span><?= number_format($overview['pending_documents']) ?> currently pending</span></div></article>
                <article class="analytics-kpi analytics-kpi-appointments"><span class="analytics-kpi-icon"><i class="bi bi-calendar-check"></i></span><div><small>Upcoming appointments</small><strong><?= number_format($overview['upcoming_appointments']) ?></strong><span>pending or approved</span></div></article>
                <article class="analytics-kpi analytics-kpi-blotter"><span class="analytics-kpi-icon"><i class="bi bi-shield-exclamation"></i></span><div><small>Open blotter cases</small><strong><?= number_format($overview['open_blotter']) ?></strong><span>awaiting resolution</span></div></article>
                <article class="analytics-kpi analytics-kpi-staff"><span class="analytics-kpi-icon"><i class="bi bi-person-workspace"></i></span><div><small>Active staff</small><strong><?= number_format($overview['active_staff']) ?></strong><span><?= number_format($overview['published_announcements']) ?> published announcements</span></div></article>
                <article class="analytics-kpi analytics-kpi-revenue"><span class="analytics-kpi-icon"><i class="bi bi-cash-coin"></i></span><div><small>Revenue</small><strong>Not tracked</strong><span><?= e($analytics['revenue']['message']) ?></span></div></article>
                <article class="analytics-kpi analytics-kpi-alerts"><span class="analytics-kpi-icon"><i class="bi bi-broadcast-pin"></i></span><div><small>Active disaster alerts</small><strong><?= number_format($overview['active_disaster_alerts']) ?></strong><span><?= number_format($overview['failed_notifications']) ?> failed notifications</span></div></article>
            </section>

            <section class="analytics-panel analytics-ai-panel">
                <div class="analytics-panel-heading">
                    <span class="analytics-panel-icon analytics-ai-icon"><i class="bi bi-stars"></i></span>
                    <div><h2>AI predictive analysis</h2><p>Uses aggregated weather and service-demand history only. No account credentials or resident identities are sent.</p></div>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="predict"><button class="btn btn-primary analytics-ai-button" type="submit"><i class="bi bi-magic"></i> Generate outlook</button></form>
                </div>
                <?php if ($aiError !== ''): ?><div class="alert alert-warning mb-0"><?= e($aiError) ?></div><?php endif; ?>
                <?php if ($aiPrediction !== ''): ?><div class="analytics-ai-result"><?= nl2br(e($aiPrediction)) ?></div><?php endif; ?>
                <?php if ($aiError === '' && $aiPrediction === ''): ?><p class="analytics-ai-placeholder"><i class="bi bi-lightbulb"></i> Generate an AI outlook for potential document-request peak periods and weather trends. Predictions are advisory and depend on available historical data.</p><?php endif; ?>
            </section>

            <div class="analytics-chart-grid">
                <section class="analytics-panel analytics-month-chart">
                    <div class="analytics-panel-heading"><span class="analytics-panel-icon analytics-doc-icon"><i class="bi bi-graph-up-arrow"></i></span><div><h2>Document request trend</h2><p>Requests received by month · last 12 months</p></div></div>
                    <div class="analytics-month-bars" role="img" aria-label="Monthly document request counts">
                        <?php foreach ($analytics['monthly_documents'] as $month): ?>
                            <?php $barHeight = $month['count'] === 0 ? 2 : max(8, (int) round($month['count'] / $maxMonthCount * 100)); ?>
                            <div class="analytics-month-bar"><span class="analytics-bar-value"><?= $month['count'] > 0 ? number_format($month['count']) : '' ?></span><div class="analytics-bar-track"><span style="height: <?= $barHeight ?>%"></span></div><small><?= e($month['label']) ?></small></div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="analytics-panel">
                    <div class="analytics-panel-heading"><span class="analytics-panel-icon analytics-type-icon"><i class="bi bi-file-earmark-bar-graph"></i></span><div><h2>Most requested documents</h2><p>All recorded requests</p></div></div>
                    <?php if ($analytics['document_types'] !== []): ?>
                        <div class="analytics-horizontal-bars">
                            <?php foreach ($analytics['document_types'] as $type): ?>
                                <div class="analytics-horizontal-row"><div><span><?= e($type['label']) ?></span><strong><?= number_format($type['count']) ?></strong></div><div class="analytics-horizontal-track"><span style="width: <?= round($type['count'] / $maxDocumentTypeCount * 100, 1) ?>%"></span></div></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?><p class="analytics-empty">No document requests recorded yet.</p><?php endif; ?>
                </section>

                <section class="analytics-panel">
                    <div class="analytics-panel-heading"><span class="analytics-panel-icon analytics-week-icon"><i class="bi bi-calendar-week"></i></span><div><h2>Peak request weekdays</h2><p>Request submissions · last 2 years</p></div></div>
                    <?php if ($analytics['weekday_documents'] !== []): ?>
                        <div class="analytics-weekdays">
                            <?php foreach ($analytics['weekday_documents'] as $day): ?>
                                <div class="analytics-weekday-row"><span><?= e(substr($day['day'], 0, 3)) ?></span><div><i style="width: <?= round($day['count'] / $maxWeekdayCount * 100, 1) ?>%"></i></div><strong><?= number_format($day['count']) ?></strong></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?><p class="analytics-empty">Not enough request history to identify a busy weekday.</p><?php endif; ?>
                </section>

                <section class="analytics-panel">
                    <div class="analytics-panel-heading"><span class="analytics-panel-icon analytics-status-icon"><i class="bi bi-pie-chart"></i></span><div><h2>Request status</h2><p>Current document request workload</p></div></div>
                    <?php if ($analytics['document_statuses'] !== []): ?>
                        <div class="analytics-status-list">
                            <?php foreach ($analytics['document_statuses'] as $status): ?>
                                <?php $statusPercent = $overview['document_requests'] > 0 ? round($status['count'] / $overview['document_requests'] * 100) : 0; ?>
                                <div><span class="analytics-status-dot status-dot-<?= e(strtolower($status['label'])) ?>"></span><span><?= e($status['label']) ?></span><strong><?= number_format($status['count']) ?></strong><div><i style="width: <?= $statusPercent ?>%"></i></div></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?><p class="analytics-empty">No status data available.</p><?php endif; ?>
                    <h3 class="analytics-subheading">Appointments by status</h3>
                    <div class="analytics-appointment-tags"><?php foreach ($analytics['appointment_status'] as $status): ?><span><?= e($status['label']) ?><strong><?= number_format($status['count']) ?></strong></span><?php endforeach; ?><?php if ($analytics['appointment_status'] === []): ?><small>No appointment data yet.</small><?php endif; ?></div>
                </section>

                <section class="analytics-panel analytics-weather-panel">
                    <div class="analytics-panel-heading"><span class="analytics-panel-icon analytics-weather-icon"><i class="bi bi-cloud-sun"></i></span><div><h2>Weather signals</h2><p>Saved weather-advisory history and latest snapshot</p></div></div>
                    <?php if ($analytics['weather_history'] !== []): ?>
                        <div class="analytics-weather-list">
                            <?php foreach (array_slice($analytics['weather_history'], -7) as $weatherDay): ?>
                                <?php $temperature = $weatherDay['average_temperature'] ?? 0; ?>
                                <div><span><?= e((new DateTimeImmutable($weatherDay['date']))->format('M j')) ?></span><div><i style="width: <?= round($temperature / $maxWeatherTemperature * 100, 1) ?>%"></i></div><strong><?= $weatherDay['average_temperature'] === null ? '—' : e(number_format($temperature, 1)) . '°' ?></strong><small><?= e($weatherDay['common_condition']) ?></small></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?><p class="analytics-empty">No historical weather advisories have been saved yet. Forecast data is shown below when available.</p><?php endif; ?>
                    <?php if (is_array($latestWeather) && isset($latestWeather['current'])): ?>
                        <div class="analytics-latest-weather"><i class="bi <?= e(weather_icon_for((int) ($latestWeather['current']['weather_code'] ?? 3))) ?>"></i><span>Latest snapshot · <?= e((string) ($latestWeather['summary'] ?? 'Conditions unavailable')) ?></span><strong><?= e(number_format((float) ($latestWeather['current']['temperature'] ?? 0), 1)) ?>°C</strong></div>
                    <?php endif; ?>
                    <a class="analytics-weather-link" href="admin_weather.php">Open weather dashboard <i class="bi bi-arrow-up-right"></i></a>
                </section>

                <section class="analytics-panel analytics-staff-panel">
                    <div class="analytics-panel-heading"><span class="analytics-panel-icon analytics-staff-icon"><i class="bi bi-stopwatch"></i></span><div><h2>Staff work time</h2><p>Hours recorded this month · active staff only</p></div></div>
                    <?php if ($analytics['staff_worktime'] !== []): ?>
                        <div class="analytics-staff-list">
                            <?php foreach ($analytics['staff_worktime'] as $worker): ?>
                                <div class="analytics-staff-row"><span class="analytics-staff-avatar"><?= e(strtoupper(substr($worker['name'], 0, 1))) ?></span><span class="analytics-staff-name"><strong><?= e($worker['name']) ?></strong><small><?= e($worker['position']) ?> · <?= number_format($worker['shifts']) ?> shifts</small></span><span class="analytics-staff-hours"><strong><?= number_format($worker['hours'], 1) ?></strong><small>hours</small></span><div class="analytics-staff-track"><i style="width: <?= round($worker['hours'] / $maxStaffHours * 100, 1) ?>%"></i></div></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?><p class="analytics-empty">No staff time logs recorded this month.</p><?php endif; ?>
                    <a class="analytics-weather-link" href="admin_audit.php">Review time logs <i class="bi bi-arrow-up-right"></i></a>
                </section>

                <section class="analytics-panel">
                    <div class="analytics-panel-heading"><span class="analytics-panel-icon analytics-resident-icon"><i class="bi bi-person-lines-fill"></i></span><div><h2>Resident records</h2><p>Aggregate residency status</p></div></div>
                    <?php $residentTotal = max(1, array_sum(array_column($analytics['resident_statuses'], 'count'))); ?>
                    <?php if ($analytics['resident_statuses'] !== []): ?>
                        <div class="analytics-status-list">
                            <?php foreach ($analytics['resident_statuses'] as $status): ?>
                                <?php $statusPercent = round($status['count'] / $residentTotal * 100); ?>
                                <div><span class="analytics-status-dot status-dot-<?= e(strtolower($status['label'])) ?>"></span><span><?= e($status['label']) ?></span><strong><?= number_format($status['count']) ?></strong><div><i style="width: <?= $statusPercent ?>%"></i></div></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?><p class="analytics-empty">No resident records are available.</p><?php endif; ?>
                </section>

                <section class="analytics-panel">
                    <div class="analytics-panel-heading"><span class="analytics-panel-icon analytics-blotter-icon"><i class="bi bi-shield-check"></i></span><div><h2>Blotter activity</h2><p>Incident status and most common categories</p></div></div>
                    <div class="analytics-mini-summary">
                        <?php foreach ($analytics['blotter_status'] as $status): ?><span><?= e($status['label']) ?><strong><?= number_format($status['count']) ?></strong></span><?php endforeach; ?>
                        <?php if ($analytics['blotter_status'] === []): ?><small>No blotter data recorded.</small><?php endif; ?>
                    </div>
                    <?php if ($analytics['blotter_categories'] !== []): ?>
                        <div class="analytics-category-list"><?php foreach (array_slice($analytics['blotter_categories'], 0, 4) as $category): ?><div><span><?= e($category['label']) ?></span><strong><?= number_format($category['count']) ?></strong></div><?php endforeach; ?></div>
                    <?php endif; ?>
                </section>

                <section class="analytics-panel">
                    <div class="analytics-panel-heading"><span class="analytics-panel-icon analytics-comms-icon"><i class="bi bi-megaphone"></i></span><div><h2>Announcements &amp; delivery</h2><p>Publication and notification delivery totals</p></div></div>
                    <h3 class="analytics-subheading">Announcement status</h3>
                    <div class="analytics-appointment-tags"><?php foreach ($analytics['announcement_status'] as $status): ?><span><?= e($status['label']) ?><strong><?= number_format($status['count']) ?></strong></span><?php endforeach; ?><?php if ($analytics['announcement_status'] === []): ?><small>No announcements recorded.</small><?php endif; ?></div>
                    <h3 class="analytics-subheading">Notification delivery by channel</h3>
                    <div class="analytics-appointment-tags"><?php foreach ($analytics['notification_status'] as $delivery): ?><span><?= e($delivery['channel']) ?> · <?= e($delivery['status']) ?><strong><?= number_format($delivery['count']) ?></strong></span><?php endforeach; ?><?php if ($analytics['notification_status'] === []): ?><small>No notification delivery logs recorded.</small><?php endif; ?></div>
                </section>

                <section class="analytics-panel">
                    <div class="analytics-panel-heading"><span class="analytics-panel-icon analytics-alert-icon"><i class="bi bi-broadcast-pin"></i></span><div><h2>Disaster preparedness</h2><p>Active alerts by severity</p></div></div>
                    <div class="analytics-appointment-tags"><?php foreach ($analytics['disaster_severity'] as $severity): ?><span><?= e($severity['label']) ?><strong><?= number_format($severity['count']) ?></strong></span><?php endforeach; ?><?php if ($analytics['disaster_severity'] === []): ?><small>No active disaster alerts.</small><?php endif; ?></div>
                    <p class="analytics-disaster-total"><strong><?= number_format($overview['active_disaster_alerts']) ?></strong> active alerts in total</p>
                </section>
            </div>

            <p class="analytics-privacy-note"><i class="bi bi-shield-lock"></i> Analytics use aggregate service records. Usernames, password hashes, resident names, addresses, contact details, and account credentials are not queried for this dashboard or sent for AI analysis.</p>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
