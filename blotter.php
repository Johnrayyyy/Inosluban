<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/blotter_email.php';
require_once __DIR__ . '/config/mailer.php';
require_resident();

$residentId = (int) $_SESSION['user']['resident_id'];
$categories = ['Noise Disturbance', 'Theft', 'Road Obstruction', 'Public Safety', 'Other'];
$errors = [];
$success = '';

function blotter_upload(string $field): ?string
{
    if (empty($_FILES[$field]['name'])) {
        return null;
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK || $_FILES[$field]['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('The attachment must be smaller than 5 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Only JPG, PNG, or PDF attachments are accepted.');
    }
    $directory = __DIR__ . '/storage/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
        throw new RuntimeException('Unable to prepare secure file storage.');
    }
    $filename = 'blotter-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('The attachment could not be saved.');
    }
    return 'storage/uploads/' . $filename;
}

try {
    $residentStatement = db()->prepare(
        'SELECT first_name, middle_name, last_name, email
         FROM resident WHERE resident_id = :resident_id'
    );
    $residentStatement->execute(['resident_id' => $residentId]);
    $resident = $residentStatement->fetch();
    if (!$resident) {
        http_response_code(404);
        exit('Resident profile not found.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        $title = trim((string) ($_POST['report_title'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $reportDate = trim((string) ($_POST['report_date'] ?? ''));
        $location = trim((string) ($_POST['location'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $latitude = trim((string) ($_POST['latitude'] ?? ''));
        $longitude = trim((string) ($_POST['longitude'] ?? ''));

        if ($title === '') $errors[] = 'Report title is required.';
        if (!in_array($category, $categories, true)) $errors[] = 'Please choose a valid category.';
        if ($reportDate === '' || !DateTime::createFromFormat('!Y-m-d\TH:i', $reportDate)) $errors[] = 'Please enter a valid date and time.';
        if ($location === '') $errors[] = 'Please select or enter a location.';
        if ($description === '') $errors[] = 'Please provide a description.';
        if ($latitude !== '' && (!is_numeric($latitude) || (float) $latitude < -90 || (float) $latitude > 90)) $errors[] = 'The selected map latitude is invalid.';
        if ($longitude !== '' && (!is_numeric($longitude) || (float) $longitude < -180 || (float) $longitude > 180)) $errors[] = 'The selected map longitude is invalid.';

        $attachment = null;
        if ($errors === []) {
            try {
                $attachment = blotter_upload('attachment');
                $statement = db()->prepare(
                    'INSERT INTO blotter (resident_id, report_title, category, location, latitude, longitude, description, report_date, attachment_file_path)
                     VALUES (:resident_id, :report_title, :category, :location, :latitude, :longitude, :description, :report_date, :attachment_file_path)
                     RETURNING report_id'
                );
                $statement->execute([
                    'resident_id' => $residentId,
                    'report_title' => $title,
                    'category' => $category,
                    'location' => $location,
                    'latitude' => $latitude !== '' ? $latitude : null,
                    'longitude' => $longitude !== '' ? $longitude : null,
                    'description' => $description,
                    'report_date' => str_replace('T', ' ', $reportDate),
                    'attachment_file_path' => $attachment,
                ]);
                $reportId = (int) $statement->fetchColumn();
                $trackingUrl = rtrim(APP_BASE_URL, '/') . '/blotter_status.php?id=' . $reportId;
                $emailReport = [
                    'resident_name' => trim(implode(' ', array_filter([$resident['first_name'], $resident['middle_name'], $resident['last_name']]))),
                    'report_number' => date('Y') . '-' . str_pad((string) $reportId, 4, '0', STR_PAD_LEFT),
                    'report_title' => $title,
                    'category' => $category,
                    'location' => $location,
                    'report_date' => str_replace('T', ' ', $reportDate),
                ];
                if ($resident['email'] && sendEmail(
                    (string) $resident['email'],
                    'Blotter report confirmation',
                    blotter_submission_email_html($emailReport, $trackingUrl)
                )) {
                    $success = 'Your blotter report was submitted successfully. A tracking link was sent to your email.';
                } elseif ($resident['email']) {
                    error_log('Blotter confirmation email was not sent for report #' . $reportId);
                    $success = 'Your blotter report was submitted successfully, but the confirmation email could not be sent.';
                } else {
                    $success = 'Your blotter report was submitted successfully. Add an email address to your profile to receive notifications.';
                }
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
    }

    $reportsStatement = db()->prepare(
        'SELECT report_id, report_title, category, location, report_date, status
         FROM blotter WHERE resident_id = :resident_id ORDER BY report_date DESC LIMIT 20'
    );
    $reportsStatement->execute(['resident_id' => $residentId]);
    $reports = $reportsStatement->fetchAll();
} catch (PDOException $exception) {
    error_log('Blotter database error: ' . $exception->getMessage());
    http_response_code(500);
    exit('The blotter service is temporarily unavailable.');
}

$pageTitle = 'Blotter report';
require __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<main class="dashboard-page request-module blotter-module" data-theme="light">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Community Portal</small></span></a>
        <?php require __DIR__ . '/includes/resident_nav.php'; ?>
        <div class="dashboard-sidebar-footer"><img src="assets/images/logo.png" alt=""><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small></span></div>
    </aside>
    <section class="dashboard-content request-content">
        <header class="dashboard-topbar request-topbar"><div class="dashboard-search"><i class="bi bi-search"></i><input type="search" placeholder="Search services, announcements, or information..." aria-label="Search portal"></div><div class="dashboard-user"><button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch to dark mode"><i class="bi bi-moon-stars"></i></button><span class="user-avatar"><i class="bi bi-person-fill"></i></span><span><strong><?= e($_SESSION['user']['full_name']) ?></strong><small>Resident Account</small></span></div></header>
        <div class="request-main blotter-main">
            <div class="request-breadcrumb">Resident Services / Blotter</div>
            <div class="request-title-row"><div><h1>Blotter</h1><p>Report incidents, concerns, or request assistance from the barangay office.</p></div></div>
            <?php if ($errors !== []): ?><div class="alert alert-danger request-alert"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <?php if ($success !== ''): ?><div class="alert alert-success request-alert"><?= e($success) ?></div><?php endif; ?>
            <div class="blotter-columns">
                <section class="request-card blotter-form-card"><div class="card-heading"><div><h2>Blotter report</h2><p>Fields marked with * are required.</p></div></div><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="latitude" id="latitude"><input type="hidden" name="longitude" id="longitude">
                    <div class="blotter-form-grid"><div><label class="form-label" for="report_title">Report title <b>*</b></label><input class="form-control request-input" id="report_title" name="report_title" placeholder="Enter report title" required></div><div><label class="form-label" for="category">Category <b>*</b></label><select class="form-control request-input" id="category" name="category" required><option value="">Select category</option><?php foreach ($categories as $category): ?><option value="<?= e($category) ?>"><?= e($category) ?></option><?php endforeach; ?></select></div><div><label class="form-label" for="report_date">Date and time of incident <b>*</b></label><input class="form-control request-input" type="datetime-local" id="report_date" name="report_date" value="<?= e(date('Y-m-d\TH:i')) ?>" required></div><div><label class="form-label" for="location">Location <b>*</b></label><input class="form-control request-input" id="location" name="location" placeholder="Select on the map or enter location" required></div><div class="detail-wide"><label class="form-label" for="description">Description <b>*</b></label><textarea class="form-control request-input" id="description" name="description" rows="5" placeholder="Provide a detailed description of the incident." required></textarea></div><div class="detail-wide"><label class="form-label" for="attachment">Attach file <small>(optional)</small></label><input class="form-control request-input" type="file" id="attachment" name="attachment" accept="image/jpeg,image/png,application/pdf"><small class="profile-help">JPG, PNG, or PDF up to 5 MB.</small></div></div>
                    <div class="wizard-actions"><a class="btn btn-light request-back" href="dashboard.php">Cancel</a><button class="btn btn-primary request-next" type="submit">Submit report <i class="bi bi-send ms-1"></i></button></div>
                </form></section>
                <aside class="blotter-side"><section class="request-card map-card"><div class="card-heading"><div><h2><i class="bi bi-geo-alt-fill"></i> Select location on map</h2><p>Click the map to place the incident location.</p></div></div><div id="blotter-map" aria-label="Incident location map"></div><small class="map-hint"><i class="bi bi-arrows-move"></i> Drag the pin to set the location.</small></section><section class="request-card recent-reports"><div class="card-heading"><div><h2>Recent blotter reports</h2><p>Your submitted reports</p></div><span class="active-count"><?= count($reports) ?> reports</span></div>                <div class="blotter-report-list"><?php foreach ($reports as $report): ?><a class="blotter-report" href="blotter_status.php?id=<?= (int) $report['report_id'] ?>"><span class="active-request-icon"><i class="bi bi-file-earmark-text"></i></span><span><strong><?= e($report['report_title']) ?></strong><small><?= e((new DateTime($report['report_date']))->format('M d, Y')) ?> · <?= e($report['location'] ?: 'Location not set') ?></small></span><em class="blotter-status status-<?= strtolower(str_replace(' ', '-', $report['status'])) ?>"><?= e($report['status']) ?></em><i class="bi bi-chevron-right"></i></a><?php endforeach; ?><?php if ($reports === []): ?><p class="empty-requests">No reports submitted yet.</p><?php endif; ?></div></section></aside>
            </div>
        </div>
        <footer class="dashboard-footer"><span>Connect. Collaborate. Grow Together.</span><small>(c) 2026 Barangay Inosluban. All rights reserved.</small></footer>
    </section>
</main>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
