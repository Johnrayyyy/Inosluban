<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/phil_sms.php';

require_admin();
ensure_admin_schema();
ensure_staff_tables();

$message = '';
$warning = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create_disaster_alert') {
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
            $message = 'Disaster alert published.';
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
            $message = sprintf('Alert SMS delivery finished: %d sent, %d failed.', $sentCount, $failedCount);
            if ($failedCount > 0) {
                $warning = 'Review the delivery log and resident mobile numbers.';
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
            $message = 'Disaster alert marked as resolved.';
        } else {
            throw new RuntimeException('Invalid disaster preparedness action.');
        }
    } catch (Throwable $exception) {
        error_log('Disaster preparedness action error: ' . $exception->getMessage());
        $error = $exception->getMessage();
    }
}

$alerts = db()->query(
    "SELECT d.disaster_id, d.alert_type, d.severity, d.affected_area, d.alert_message,
            d.created_at, d.expires_at,
            COUNT(n.notification_id) FILTER (
                WHERE n.delivery_status IN ('Sent', 'Delivered')
            ) AS delivered_count,
            COUNT(n.notification_id) FILTER (
                WHERE n.delivery_status = 'Failed'
            ) AS failed_count
     FROM disaster_preparedness d
     LEFT JOIN notification_log n ON n.disaster_id = d.disaster_id
     GROUP BY d.disaster_id
     ORDER BY (d.expires_at IS NULL OR d.expires_at >= CURRENT_TIMESTAMP) DESC,
              d.created_at DESC
     LIMIT 30"
)->fetchAll();
$activeCount = (int) db()->query(
    'SELECT COUNT(*) FROM disaster_preparedness
     WHERE expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP'
)->fetchColumn();
$smsReady = phil_sms_is_configured();
$pageTitle = 'Disaster preparedness';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page staff-page analytics-dashboard">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="admin_analytics.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Admin Portal</small></span></a>
        <?php require __DIR__ . '/includes/staff_nav.php'; ?>
    </aside>
    <section class="dashboard-content">
        <div class="staff-main analytics-main">
            <header class="analytics-heading">
                <div><p class="eyebrow"><i class="bi bi-broadcast-pin"></i> COMMUNITY SAFETY</p><h1>Disaster preparedness</h1><p>Publish verified safety guidance, notify residents, and close alerts when risks have passed.</p></div>
                <span class="analytics-disaster-count"><?= number_format($activeCount) ?> active alerts</span>
            </header>
            <?php if ($message !== ''): ?><div class="alert <?= $warning !== '' ? 'alert-warning' : 'alert-success' ?>"><?= e($message) ?><?= $warning !== '' ? ' ' . e($warning) : '' ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
            <div class="analytics-disaster-workspace">
                <form class="analytics-disaster-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_disaster_alert">
                    <h2>Publish a preparedness alert</h2>
                    <p>Use confirmed information and clear, actionable instructions for residents.</p>
                    <label for="disaster-alert-type">Alert type</label>
                    <input class="form-control" id="disaster-alert-type" name="alert_type" maxlength="100" placeholder="Flood, fire, evacuation..." required>
                    <div class="analytics-disaster-fields">
                        <div><label for="disaster-severity">Severity</label><select class="form-control" id="disaster-severity" name="severity" required><option value="Information">Information</option><option value="Advisory">Advisory</option><option value="Watch">Watch</option><option value="Warning">Warning</option><option value="Emergency">Emergency</option></select></div>
                        <div><label for="disaster-area">Affected area</label><input class="form-control" id="disaster-area" name="affected_area" maxlength="255" placeholder="Optional"></div>
                    </div>
                    <label for="disaster-message">Resident guidance</label>
                    <textarea class="form-control" id="disaster-message" name="alert_message" rows="5" maxlength="10000" required placeholder="Share verified safety instructions and where residents should get updates."></textarea>
                    <label for="disaster-expires">Expires at <small>Leave blank if the alert has no set expiry.</small></label>
                    <input class="form-control" id="disaster-expires" name="expires_at" type="datetime-local">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-megaphone"></i> Publish alert</button>
                </form>
                <section class="analytics-active-alerts">
                    <div class="analytics-panel-heading"><span class="analytics-panel-icon analytics-alert-icon"><i class="bi bi-broadcast-pin"></i></span><div><h2>Alert history</h2><p>Active alerts and recently resolved events</p></div></div>
                    <?php foreach ($alerts as $alert): ?>
                        <?php
                        $isActive = $alert['expires_at'] === null || new DateTimeImmutable((string) $alert['expires_at']) >= new DateTimeImmutable('now', new DateTimeZone('UTC'));
                        $severity = (string) ($alert['severity'] ?? 'Unspecified');
                        $severityClass = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $severity));
                        ?>
                        <article class="analytics-active-alert">
                            <div class="analytics-active-alert-top"><span class="analytics-severity-badge severity-<?= e($severityClass) ?>"><?= e($severity) ?></span><time><?= e(format_app_datetime((string) $alert['created_at'], 'M j, Y · g:i A')) ?></time></div>
                            <strong><?= e((string) $alert['alert_type']) ?><?= !empty($alert['affected_area']) ? ' · ' . e((string) $alert['affected_area']) : '' ?></strong>
                            <p><?= nl2br(e((string) $alert['alert_message'])) ?></p>
                            <div class="analytics-alert-delivery"><span><i class="bi bi-check-circle"></i> <?= number_format((int) $alert['delivered_count']) ?> sent</span><span><i class="bi bi-exclamation-circle"></i> <?= number_format((int) $alert['failed_count']) ?> failed</span><span class="staff-status <?= $isActive ? 'staff-status-progress' : 'staff-status-complete' ?>"><?= $isActive ? 'Active' : 'Resolved / expired' ?></span></div>
                            <?php if ($isActive): ?>
                                <div class="analytics-alert-actions">
                                    <form method="post" onsubmit="return confirm('Send this alert by SMS to all active residents with a valid mobile number?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="send_disaster_alert"><input type="hidden" name="disaster_id" value="<?= (int) $alert['disaster_id'] ?>"><button class="btn btn-sm btn-outline-primary" type="submit" <?= !$smsReady ? 'disabled title="SMS delivery is not configured"' : '' ?>><i class="bi bi-send"></i> Send SMS alert</button></form>
                                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="resolve_disaster_alert"><input type="hidden" name="disaster_id" value="<?= (int) $alert['disaster_id'] ?>"><button class="btn btn-sm btn-outline-secondary" type="submit">Resolve</button></form>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                    <?php if ($alerts === []): ?><p class="analytics-empty">No alerts have been published. Create one when residents need verified preparedness guidance.</p><?php endif; ?>
                </section>
            </div>
            <?php if (!$smsReady): ?><p class="analytics-privacy-note"><i class="bi bi-info-circle"></i> SMS delivery is unavailable until PHILSMS_API_KEY and PHILSMS_SENDER_ID are configured in deployment secrets.</p><?php endif; ?>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
