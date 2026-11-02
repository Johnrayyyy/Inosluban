<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/analytics.php';
require_once __DIR__ . '/includes/gemini.php';
require_once __DIR__ . '/includes/phil_sms.php';

require_admin();
ensure_admin_schema();
$refreshWeatherOnLogin = !empty($_SESSION['weather_refresh_on_login']);
unset($_SESSION['weather_refresh_on_login']);

$aiPrediction = '';
$aiError = '';
$actionMessage = '';
$actionError = '';
$actionWarning = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        if ($action === 'predict') {
            $aiPrediction = ask_gemini(admin_analytics_ai_prompt(admin_analytics_data()));
        } elseif ($action === 'create_disaster_alert') {
            $alertType = trim((string) ($_POST['alert_type'] ?? ''));
            $severity = (string) ($_POST['severity'] ?? '');
            $affectedArea = trim((string) ($_POST['affected_area'] ?? ''));
            $alertMessage = trim((string) ($_POST['alert_message'] ?? ''));
            $expiresAt = trim((string) ($_POST['expires_at'] ?? ''));
            $allowedSeverities = ['Information', 'Advisory', 'Watch', 'Warning', 'Emergency'];
            if (
                $alertType === ''
                || strlen($alertType) > 100
                || !in_array($severity, $allowedSeverities, true)
                || strlen($affectedArea) > 255
                || $alertMessage === ''
                || strlen($alertMessage) > 10000
            ) {
                throw new RuntimeException('Enter an alert type, valid severity, and a message. The affected area must be 255 characters or fewer.');
            }
            if ($expiresAt !== '') {
                $expiry = new DateTimeImmutable($expiresAt, new DateTimeZone(APP_TIMEZONE));
                if ($expiry <= new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE))) {
                    throw new RuntimeException('The alert expiry must be in the future.');
                }
                $expiresAt = $expiry->format(DATE_ATOM);
            }
            $insert = db()->prepare(
                "INSERT INTO disaster_preparedness
                    (source, alert_type, severity, affected_area, alert_message, expires_at)
                 VALUES
                    ('Admin dashboard', :alert_type, :severity, NULLIF(:affected_area, ''),
                     :alert_message, NULLIF(:expires_at, '')::timestamptz)
                 RETURNING disaster_id"
            );
            $insert->execute([
                'alert_type' => $alertType,
                'severity' => $severity,
                'affected_area' => $affectedArea,
                'alert_message' => $alertMessage,
                'expires_at' => $expiresAt,
            ]);
            $alertId = (int) $insert->fetchColumn();
            staff_audit('DISASTER_ALERT_CREATED', 'disaster_preparedness', $alertId, [
                'alert_type' => $alertType,
                'severity' => $severity,
            ]);
            $actionMessage = 'Disaster alert published to the admin dashboard.';
        } elseif ($action === 'send_disaster_alert') {
            $alertId = filter_var($_POST['disaster_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$alertId) {
                throw new RuntimeException('Select a valid disaster alert to send.');
            }
            if (!phil_sms_is_configured()) {
                throw new RuntimeException('SMS delivery is not configured. Set PHILSMS_API_KEY and PHILSMS_SENDER_ID before sending alerts.');
            }
            $alertQuery = db()->prepare(
                'SELECT alert_type, severity, affected_area, alert_message
                 FROM disaster_preparedness
                 WHERE disaster_id = :disaster_id
                   AND (expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP)'
            );
            $alertQuery->execute(['disaster_id' => $alertId]);
            $alert = $alertQuery->fetch();
            if (!$alert) {
                throw new RuntimeException('This alert has expired or no longer exists.');
            }
            $smsText = disaster_alert_sms_message(
                (string) $alert['severity'],
                (string) $alert['alert_type'],
                (string) ($alert['affected_area'] ?? ''),
                (string) $alert['alert_message']
            );
            $residents = db()->query(
                "SELECT resident_id, mobile_number
                 FROM resident
                 WHERE residency_status = 'Active'
                   AND NULLIF(BTRIM(mobile_number), '') IS NOT NULL"
            )->fetchAll();
            if ($residents === []) {
                throw new RuntimeException('No active residents with a mobile number are available for this alert.');
            }
            $insertDelivery = db()->prepare(
                "INSERT INTO notification_log
                    (resident_id, disaster_id, recipient, channel, message, delivery_status, sent_at)
                 VALUES
                    (:resident_id, :disaster_id, :recipient, 'SMS', :message, :delivery_status,
                     CASE WHEN :sent_status = 'Sent' THEN CURRENT_TIMESTAMP ELSE NULL END)"
            );
            $attemptedNumbers = [];
            $sentCount = 0;
            $failedCount = 0;
            foreach ($residents as $resident) {
                $rawNumber = (string) $resident['mobile_number'];
                $normalized = phil_sms_normalize_number($rawNumber);
                if ($normalized !== null && isset($attemptedNumbers[$normalized])) {
                    continue;
                }
                if ($normalized !== null) {
                    $attemptedNumbers[$normalized] = true;
                }
                $sent = $normalized !== null && send_phil_sms($normalized, $smsText);
                $deliveryStatus = $sent ? 'Sent' : 'Failed';
                $insertDelivery->execute([
                    'resident_id' => (int) $resident['resident_id'],
                    'disaster_id' => (int) $alertId,
                    'recipient' => $normalized ?? $rawNumber,
                    'message' => $smsText,
                    'delivery_status' => $deliveryStatus,
                    'sent_status' => $deliveryStatus,
                ]);
                $sent ? $sentCount++ : $failedCount++;
            }
            staff_audit('DISASTER_ALERT_SMS_SENT', 'disaster_preparedness', (int) $alertId, [
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
            ]);
            $actionMessage = sprintf('Alert SMS delivery finished: %d sent, %d failed.', $sentCount, $failedCount);
            if ($failedCount > 0) {
                $actionWarning = $actionMessage . ' Review the delivery log and resident mobile numbers.';
            }
        } elseif ($action === 'resolve_disaster_alert') {
            $alertId = filter_var($_POST['disaster_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$alertId) {
                throw new RuntimeException('Select a valid disaster alert to resolve.');
            }
            $resolve = db()->prepare(
                'UPDATE disaster_preparedness
                 SET expires_at = CURRENT_TIMESTAMP
                 WHERE disaster_id = :disaster_id
                   AND (expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP)'
            );
            $resolve->execute(['disaster_id' => $alertId]);
            if ($resolve->rowCount() !== 1) {
                throw new RuntimeException('This alert is already expired or no longer exists.');
            }
            staff_audit('DISASTER_ALERT_RESOLVED', 'disaster_preparedness', (int) $alertId);
            $actionMessage = 'Disaster alert marked as resolved.';
        } else {
            throw new RuntimeException('Invalid analytics action.');
        }
    } catch (Throwable $exception) {
        error_log('Admin analytics action error: ' . $exception->getMessage());
        if ($action === 'predict') {
            $aiError = $exception->getMessage();
        } else {
            $actionError = $exception->getMessage();
        }
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
$deliveryTotals = ['Sent' => 0, 'Delivered' => 0, 'Failed' => 0, 'Queued' => 0];
foreach ($analytics['notification_status'] as $delivery) {
    $deliveryTotals[$delivery['status']] = ($deliveryTotals[$delivery['status']] ?? 0) + $delivery['count'];
}
$successfulDeliveries = $deliveryTotals['Sent'] + $deliveryTotals['Delivered'];
$totalDeliveries = array_sum($deliveryTotals);
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

            <?php if ($actionMessage !== ''): ?><div class="alert <?= $actionWarning !== '' ? 'alert-warning' : 'alert-success' ?>"><?= e($actionWarning !== '' ? $actionWarning : $actionMessage) ?></div><?php endif; ?>
            <?php if ($actionError !== ''): ?><div class="alert alert-danger"><?= e($actionError) ?></div><?php endif; ?>

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
                    <div class="analytics-panel-heading"><span class="analytics-panel-icon analytics-staff-icon"><i class="bi bi-stopwatch"></i></span><div><h2>Staff work time</h2><p>Hours recorded this month · active staff only</p></div><span class="analytics-panel-period">MONTH TO DATE</span></div>
                    <?php if ($analytics['staff_worktime'] !== []): ?>
                        <div class="analytics-staff-list">
                            <?php foreach ($analytics['staff_worktime'] as $worker): ?>
                                <div class="analytics-staff-row"><span class="analytics-staff-avatar"><?= e(strtoupper(substr($worker['name'], 0, 1))) ?></span><span class="analytics-staff-name"><strong><?= e($worker['name']) ?></strong><small><?= e($worker['position']) ?> · <?= number_format($worker['shifts']) ?> shifts</small></span><span class="analytics-staff-hours"><strong><?= number_format($worker['hours'], 1) ?></strong><small>hours</small></span><div class="analytics-staff-track" role="img" aria-label="<?= e(number_format($worker['hours'], 1) . ' hours this month') ?>"><i style="width: <?= round($worker['hours'] / $maxStaffHours * 100, 1) ?>%"></i></div></div>
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

                <section class="analytics-panel analytics-comms-panel">
                    <div class="analytics-panel-heading"><span class="analytics-panel-icon analytics-comms-icon"><i class="bi bi-megaphone"></i></span><div><h2>Announcements &amp; delivery</h2><p>Published notices and recorded channel results</p></div><a class="analytics-heading-link" href="admin_announcements.php">Manage <i class="bi bi-arrow-up-right"></i></a></div>
                    <h3 class="analytics-subheading">Announcement status</h3>
                    <div class="analytics-appointment-tags analytics-announcement-tags"><?php foreach ($analytics['announcement_status'] as $status): ?><span><?= e($status['label']) ?><strong><?= number_format($status['count']) ?></strong></span><?php endforeach; ?><?php if ($analytics['announcement_status'] === []): ?><small>No announcements recorded.</small><?php endif; ?></div>
                    <div class="analytics-delivery-summary"><strong><?= number_format($successfulDeliveries) ?><small> successful</small></strong><span>of <?= number_format($totalDeliveries) ?> recorded deliveries</span></div>
                    <?php if ($analytics['notification_status'] !== []): ?>
                        <div class="analytics-delivery-list">
                            <?php foreach ($analytics['notification_status'] as $delivery): ?>
                                <?php $deliveryStatusClass = strtolower($delivery['status']); ?>
                                <div><span class="analytics-delivery-channel"><?= e($delivery['channel']) ?></span><span class="analytics-delivery-status delivery-<?= e($deliveryStatusClass) ?>"><?= e($delivery['status']) ?></span><strong><?= number_format($delivery['count']) ?></strong></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?><p class="analytics-empty analytics-delivery-empty">No notification delivery logs recorded yet.</p><?php endif; ?>
                    <p class="analytics-delivery-note">Delivery counts include disaster alerts and published weather announcements. SMS messages are recorded as sent only when the provider accepts them.</p>
                </section>

                <section class="analytics-panel analytics-disaster-panel">
                    <div class="analytics-panel-heading"><span class="analytics-panel-icon analytics-alert-icon"><i class="bi bi-broadcast-pin"></i></span><div><h2>Disaster preparedness</h2><p>Create an active alert, notify residents by SMS, and resolve it when the risk has passed.</p></div><strong class="analytics-disaster-count"><?= number_format($overview['active_disaster_alerts']) ?> active</strong></div>
                    <div class="analytics-disaster-severities"><?php foreach ($analytics['disaster_severity'] as $severity): ?><span><?= e($severity['label']) ?><strong><?= number_format($severity['count']) ?></strong></span><?php endforeach; ?><?php if ($analytics['disaster_severity'] === []): ?><span>No active alerts yet</span><?php endif; ?></div>
                    <div class="analytics-disaster-workspace">
                        <form class="analytics-disaster-form" method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="create_disaster_alert">
                            <h3>Publish a preparedness alert</h3>
                            <label for="disaster-alert-type">Alert type</label>
                            <input class="form-control" id="disaster-alert-type" name="alert_type" maxlength="100" placeholder="Flood, fire, evacuation..." required>
                            <div class="analytics-disaster-fields">
                                <div><label for="disaster-severity">Severity</label><select class="form-control" id="disaster-severity" name="severity" required><option value="Information">Information</option><option value="Advisory">Advisory</option><option value="Watch">Watch</option><option value="Warning">Warning</option><option value="Emergency">Emergency</option></select></div>
                                <div><label for="disaster-area">Affected area</label><input class="form-control" id="disaster-area" name="affected_area" maxlength="255" placeholder="Optional"></div>
                            </div>
                            <label for="disaster-message">Resident guidance</label>
                            <textarea class="form-control" id="disaster-message" name="alert_message" rows="3" maxlength="10000" required placeholder="Share verified safety instructions and where residents should get updates."></textarea>
                            <label for="disaster-expires">Expires at <small>Leave blank if the alert has no set expiry.</small></label>
                            <input class="form-control" id="disaster-expires" name="expires_at" type="datetime-local">
                            <button class="btn btn-primary" type="submit"><i class="bi bi-megaphone"></i> Publish alert</button>
                        </form>
                        <div class="analytics-active-alerts">
                            <h3>Active alerts</h3>
                            <?php foreach ($analytics['active_disaster_alerts'] as $alert): ?>
                                <?php $severityClass = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $alert['severity'])); ?>
                                <article class="analytics-active-alert">
                                    <div class="analytics-active-alert-top"><span class="analytics-severity-badge severity-<?= e($severityClass) ?>"><?= e($alert['severity']) ?></span><time><?= e(format_app_datetime($alert['created_at'], 'M j, g:i A')) ?></time></div>
                                    <strong><?= e($alert['type']) ?><?= $alert['area'] !== '' ? ' · ' . e($alert['area']) : '' ?></strong>
                                    <p><?= e($alert['message']) ?></p>
                                    <div class="analytics-alert-delivery"><span><i class="bi bi-check-circle"></i> <?= number_format($alert['delivered']) ?> sent</span><span><i class="bi bi-exclamation-circle"></i> <?= number_format($alert['failed']) ?> failed</span></div>
                                    <div class="analytics-alert-actions">
                                        <form method="post" onsubmit="return confirm('Send this alert by SMS to all active residents with a valid mobile number?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="send_disaster_alert"><input type="hidden" name="disaster_id" value="<?= $alert['id'] ?>"><button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-send"></i> Send SMS alert</button></form>
                                        <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="resolve_disaster_alert"><input type="hidden" name="disaster_id" value="<?= $alert['id'] ?>"><button class="btn btn-sm btn-outline-secondary" type="submit">Resolve</button></form>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                            <?php if ($analytics['active_disaster_alerts'] === []): ?><p class="analytics-empty">No active alerts. Publish a verified alert when residents need preparedness guidance.</p><?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>

            <p class="analytics-privacy-note"><i class="bi bi-shield-lock"></i> Analytics use aggregate service records. Usernames, password hashes, resident names, addresses, contact details, and account credentials are not queried for this dashboard or sent for AI analysis.</p>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
