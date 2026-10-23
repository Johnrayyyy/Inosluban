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
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['message'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'Draft');
        if ($title === '' || $body === '' || !in_array($status, ['Draft', 'Published', 'Archived'], true)) {
            throw new RuntimeException('Title, message, and a valid status are required.');
        }
        $image = admin_upload_image('image');
        $insert = db()->prepare('INSERT INTO announcement (created_by_user_id, title, message, image_path, publication_date, expires_at, status) VALUES (:user_id, :title, :message, :image_path, CASE WHEN :status = \'Published\' THEN CURRENT_TIMESTAMP ELSE NULL END, NULLIF(:expires_at, \'\')::timestamptz, :status) RETURNING announcement_id');
        $insert->execute(['user_id' => $_SESSION['user']['id'], 'title' => $title, 'message' => $body, 'image_path' => $image, 'expires_at' => trim((string) ($_POST['expires_at'] ?? '')), 'status' => $status]);
        staff_audit('ANNOUNCEMENT_CREATED', 'announcement', (int) $insert->fetchColumn(), ['status' => $status]);
        $message = 'Announcement saved.';
    } catch (Throwable $exception) {
        error_log('Announcement creation error: ' . $exception->getMessage());
        $errors[] = $exception->getMessage();
    }
}
$announcements = db()->query("SELECT announcement_id, title, status, publication_date FROM announcement ORDER BY COALESCE(publication_date, CURRENT_TIMESTAMP) DESC LIMIT 30")->fetchAll();
$pageTitle = 'Manage announcements';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page staff-page"><aside class="dashboard-sidebar"><a class="dashboard-brand" href="staff_dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Admin Portal</small></span></a><?php require __DIR__ . '/includes/staff_nav.php'; ?></aside><section class="dashboard-content"><div class="staff-main"><div class="staff-hero compact"><div><p class="eyebrow">COMMUNITY COMMUNICATIONS</p><h1>Create announcement</h1><p>Publish notices for residents. Staff accounts cannot access this module.</p></div></div><?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?><div class="staff-request-layout"><section class="staff-panel p-4"><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label class="form-label" for="title">Title</label><input class="form-control mb-3" id="title" name="title" required><label class="form-label" for="message">Announcement</label><textarea class="form-control mb-3" id="message" name="message" rows="8" required></textarea><div class="row g-3"><div class="col-md-6"><label class="form-label" for="status">Status</label><select class="form-control" id="status" name="status"><option>Draft</option><option>Published</option><option>Archived</option></select></div><div class="col-md-6"><label class="form-label" for="expires_at">Expires at</label><input class="form-control" id="expires_at" name="expires_at" type="datetime-local"></div></div><label class="form-label mt-3" for="image">Optional picture</label><input class="form-control" id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp"><button class="btn btn-primary mt-4" type="submit">Save announcement</button></form></section><section class="staff-panel p-4"><h2 class="h5">Recent announcements</h2><?php foreach ($announcements as $announcement): ?><div class="border-bottom py-2"><strong><?= e($announcement['title']) ?></strong><small class="d-block text-muted"><?= e($announcement['status']) ?> · <?= e($announcement['publication_date'] ? (new DateTime($announcement['publication_date']))->format('M j, Y') : 'Not published') ?></small></div><?php endforeach; ?></section></div></div></section></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
