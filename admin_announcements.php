<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/phil_sms.php';
require_admin();
ensure_admin_schema();
$errors = [];
$message = '';
$smsWarning = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        if (($_POST['action'] ?? '') === 'review_weather_forecast') {
            $announcementId = filter_var($_POST['announcement_id'] ?? '', FILTER_VALIDATE_INT);
            $reviewAction = (string) ($_POST['review_action'] ?? '');
            if (!$announcementId || !in_array($reviewAction, ['approve', 'dismiss'], true)) {
                throw new RuntimeException('Invalid weather announcement review action.');
            }
            $approved = $reviewAction === 'approve';
            $review = db()->prepare(
                "UPDATE announcement
                 SET status = :status,
                     publication_date = CASE WHEN :publication_status = 'Published' THEN CURRENT_TIMESTAMP ELSE NULL END,
                     weather_review_status = :review_status
                 WHERE announcement_id = :announcement_id
                   AND weather_forecast_date IS NOT NULL
                   AND weather_review_status = 'Pending'
                   AND status = 'Draft'"
            );
            $status = $approved ? 'Published' : 'Archived';
            $review->execute([
                'status' => $status,
                'publication_status' => $status,
                'review_status' => $approved ? 'Approved' : 'Dismissed',
                'announcement_id' => $announcementId,
            ]);
            if ($review->rowCount() !== 1) {
                throw new RuntimeException('This forecast draft was already reviewed or is no longer available.');
            }
            if ($approved) {
                if (!phil_sms_is_configured()) {
                    $smsWarning = 'Announcement published, but SMS was not sent. Configure PHILSMS_API_KEY and PHILSMS_SENDER_ID.';
                } else {
                    $forecast = db()->prepare(
                        'SELECT weather_forecast_date, weather_forecast_details
                         FROM announcement WHERE announcement_id = :announcement_id'
                    );
                    $forecast->execute(['announcement_id' => $announcementId]);
                    $forecastDetails = $forecast->fetch();
                    $weather = is_array($forecastDetails['weather_forecast_details'] ?? null)
                        ? $forecastDetails['weather_forecast_details']
                        : json_decode((string) ($forecastDetails['weather_forecast_details'] ?? ''), true);
                    $forecastDate = (string) ($forecastDetails['weather_forecast_date'] ?? '');
                    $summary = is_array($weather) ? (string) ($weather['summary'] ?? '') : '';
                    $smsText = weather_announcement_sms_message($forecastDate, $summary);
                    $recipients = db()->query(
                        "SELECT mobile_number FROM resident
                         WHERE residency_status = 'Active'
                           AND NULLIF(BTRIM(mobile_number), '') IS NOT NULL"
                    )->fetchAll(PDO::FETCH_COLUMN);
                    $attemptedNumbers = [];
                    $sentCount = 0;
                    $failedCount = 0;
                    $invalidCount = 0;
                    foreach ($recipients as $recipient) {
                        $normalized = phil_sms_normalize_number((string) $recipient);
                        if ($normalized === null) {
                            $invalidCount++;
                            continue;
                        }
                        if (isset($attemptedNumbers[$normalized])) {
                            continue;
                        }
                        $attemptedNumbers[$normalized] = true;
                        if (send_phil_sms($normalized, $smsText)) {
                            $sentCount++;
                        } else {
                            $failedCount++;
                        }
                    }
                    if ($failedCount > 0 || $invalidCount > 0) {
                        $smsWarning = sprintf(
                            'Announcement published. SMS sent to %d number(s); %d failed and %d had invalid mobile numbers.',
                            $sentCount,
                            $failedCount,
                            $invalidCount
                        );
                    } else {
                        $message = sprintf(
                            'Weather announcement approved and published; SMS sent to %d resident number(s).',
                            $sentCount
                        );
                    }
                }
            }
            staff_audit(
                $approved ? 'WEATHER_ANNOUNCEMENT_APPROVED' : 'WEATHER_ANNOUNCEMENT_DISMISSED',
                'announcement',
                $announcementId,
                ['review_status' => $approved ? 'Approved' : 'Dismissed']
            );
            if (!$approved) {
                $message = 'Weather announcement dismissed.';
            } elseif ($message === '' && $smsWarning !== '') {
                $message = 'Weather announcement approved and published to residents.';
            }
        } else {
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['message'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'Draft');
        if ($title === '' || $body === '' || !in_array($status, ['Draft', 'Published', 'Archived'], true)) {
            throw new RuntimeException('Title, message, and a valid status are required.');
        }
        $image = admin_upload_image('image');
        $insert = db()->prepare('INSERT INTO announcement (created_by_user_id, title, message, image_path, publication_date, expires_at, status) VALUES (:user_id, :title, :message, :image_path, CASE WHEN :publication_status = \'Published\' THEN CURRENT_TIMESTAMP ELSE NULL END, NULLIF(:expires_at, \'\')::timestamptz, :status) RETURNING announcement_id');
        $insert->execute(['user_id' => $_SESSION['user']['id'], 'title' => $title, 'message' => $body, 'image_path' => $image, 'expires_at' => trim((string) ($_POST['expires_at'] ?? '')), 'publication_status' => $status, 'status' => $status]);
        staff_audit('ANNOUNCEMENT_CREATED', 'announcement', (int) $insert->fetchColumn(), ['status' => $status]);
        $message = 'Announcement saved.';
        }
    } catch (Throwable $exception) {
        error_log('Announcement creation error: ' . $exception->getMessage());
        $errors[] = $exception->getMessage();
    }
}
$pendingWeather = db()->query(
    "SELECT announcement_id, title, message, weather_forecast_date, weather_forecast_details, created_at
     FROM announcement
     WHERE weather_forecast_date IS NOT NULL AND weather_review_status = 'Pending' AND status = 'Draft'
     ORDER BY weather_forecast_date"
)->fetchAll();
if ($smsWarning !== '') {
    $errors[] = $smsWarning;
}
$announcements = db()->query("SELECT announcement_id, title, status, publication_date, weather_review_status, weather_forecast_date FROM announcement ORDER BY COALESCE(publication_date, created_at) DESC LIMIT 30")->fetchAll();
$pageTitle = 'Manage announcements';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page staff-page"><aside class="dashboard-sidebar"><a class="dashboard-brand" href="staff_dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Admin Portal</small></span></a><?php require __DIR__ . '/includes/staff_nav.php'; ?></aside><section class="dashboard-content"><div class="staff-main"><div class="staff-hero compact"><div><p class="eyebrow">COMMUNITY COMMUNICATIONS</p><h1>Manage announcements</h1><p>Publish notices for residents. Staff accounts cannot access this module.</p></div></div><?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?><div class="staff-request-layout"><section class="staff-panel p-4"><h2 class="h5">Create announcement</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label class="form-label" for="title">Title</label><input class="form-control mb-3" id="title" name="title" required><label class="form-label" for="message">Announcement</label><textarea class="form-control mb-3" id="message" name="message" rows="8" required></textarea><div class="row g-3"><div class="col-md-6"><label class="form-label" for="status">Status</label><select class="form-control" id="status" name="status"><option>Draft</option><option>Published</option><option>Archived</option></select></div><div class="col-md-6"><label class="form-label" for="expires_at">Expires at</label><input class="form-control" id="expires_at" name="expires_at" type="datetime-local"></div></div><label class="form-label mt-3" for="image">Optional picture</label><input class="form-control" id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp"><button class="btn btn-primary mt-4" type="submit">Save announcement</button></form></section><div class="d-grid gap-3"><section class="staff-panel p-4"><h2 class="h5">Weather forecasts awaiting approval</h2><p class="text-muted">Rain or thunderstorm forecasts are saved as drafts and are not visible to residents until approved.</p><?php foreach ($pendingWeather as $draft): ?><article class="border-bottom py-3"><strong><?= e($draft['title']) ?></strong><p class="mt-2 mb-2"><?= e($draft['message']) ?></p><small class="d-block text-muted mb-2">Forecast date: <?= e((new DateTime((string) $draft['weather_forecast_date']))->format('M j, Y')) ?></small><div class="d-flex gap-2"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="review_weather_forecast"><input type="hidden" name="announcement_id" value="<?= (int) $draft['announcement_id'] ?>"><button class="btn btn-sm btn-success" name="review_action" value="approve" type="submit">Approve and publish</button></form><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="review_weather_forecast"><input type="hidden" name="announcement_id" value="<?= (int) $draft['announcement_id'] ?>"><button class="btn btn-sm btn-outline-secondary" name="review_action" value="dismiss" type="submit">Dismiss</button></form></div></article><?php endforeach; ?><?php if ($pendingWeather === []): ?><p class="text-muted mb-0">No weather forecast announcements need review.</p><?php endif; ?></section><section class="staff-panel p-4"><h2 class="h5">Recent announcements</h2><?php foreach ($announcements as $announcement): ?><div class="border-bottom py-2"><strong><?= e($announcement['title']) ?></strong><small class="d-block text-muted"><?php if ($announcement['weather_review_status'] === 'Pending'): ?>Pending weather approval<?php elseif ($announcement['weather_review_status'] === 'Approved'): ?>Weather forecast · approved<?php elseif ($announcement['weather_review_status'] === 'Dismissed'): ?>Weather forecast · dismissed<?php else: ?><?= e($announcement['status']) ?><?php endif; ?> · <?= e($announcement['publication_date'] ? (new DateTime($announcement['publication_date']))->format('M j, Y') : 'Not published') ?></small></div><?php endforeach; ?></section></div></div></div></section></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
