<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';

require_staff();

$pdo = db();
$pdo->exec('ALTER TABLE user_account ADD COLUMN IF NOT EXISTS profile_image_data BYTEA');
$pdo->exec(
    "ALTER TABLE user_account
     ADD COLUMN IF NOT EXISTS profile_image_mime VARCHAR(50)
     CHECK (profile_image_mime IS NULL OR profile_image_mime IN ('image/jpeg', 'image/png', 'image/webp'))"
);

$userId = (int) $_SESSION['user']['id'];
$errors = [];
$success = (string) ($_SESSION['staff_profile_success'] ?? '');
unset($_SESSION['staff_profile_success']);
$photoQuery = $pdo->prepare(
    'SELECT profile_image_mime FROM user_account WHERE user_id = :user_id'
);
$photoQuery->execute(['user_id' => $userId]);
$profileImageMime = $photoQuery->fetchColumn();
if ($profileImageMime === false) {
    http_response_code(404);
    exit('Account not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'upload_photo') {
            $upload = $_FILES['profile_photo'] ?? null;
            if (
                !is_array($upload)
                || !is_string($upload['tmp_name'] ?? null)
                || !is_int($upload['error'] ?? null)
                || !is_int($upload['size'] ?? null)
            ) {
                throw new RuntimeException('Choose a valid profile photo to upload.');
            }
            if ($upload['error'] === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Choose a profile photo to upload.');
            }
            if (
                $upload['error'] !== UPLOAD_ERR_OK
                || !is_uploaded_file($upload['tmp_name'])
                || $upload['size'] < 1
                || $upload['size'] > 2 * 1024 * 1024
            ) {
                throw new RuntimeException('The photo upload failed or is larger than 2 MB.');
            }

            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
            $imageInfo = getimagesize($upload['tmp_name']);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
            if (
                !is_string($mime)
                || !in_array($mime, $allowedMimes, true)
                || !is_array($imageInfo)
                || ($imageInfo['mime'] ?? '') !== $mime
                || (int) $imageInfo[0] < 1
                || (int) $imageInfo[1] < 1
                || (int) $imageInfo[0] * (int) $imageInfo[1] > 40000000
            ) {
                throw new RuntimeException('Choose a valid JPG, PNG, or WEBP image.');
            }
            $imageData = file_get_contents($upload['tmp_name']);
            if (!is_string($imageData)) {
                throw new RuntimeException('The uploaded image could not be read.');
            }

            $savePhoto = $pdo->prepare(
                'UPDATE user_account
                 SET profile_image_data = decode(:image_data, \'base64\'),
                     profile_image_mime = :image_mime
                 WHERE user_id = :user_id'
            );
            $encodedImage = base64_encode($imageData);
            try {
                $pdo->beginTransaction();
                $savePhoto->bindValue(':image_data', $encodedImage, PDO::PARAM_STR);
                $savePhoto->bindValue(':image_mime', $mime, PDO::PARAM_STR);
                $savePhoto->bindValue(':user_id', $userId, PDO::PARAM_INT);
                $savePhoto->execute();
                staff_audit('STAFF_PROFILE_PHOTO_UPDATED', 'user_account', $userId);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            $_SESSION['staff_profile_success'] = 'Your profile photo was updated.';
            header('Location: staff_profile.php');
            exit;
        }

        if ($action === 'change_password') {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
            $passwordQuery = $pdo->prepare(
                'SELECT password_hash FROM user_account WHERE user_id = :user_id'
            );
            $passwordQuery->execute(['user_id' => $userId]);
            $passwordHash = $passwordQuery->fetchColumn();
            if (!is_string($passwordHash) || !password_verify($currentPassword, $passwordHash)) {
                throw new RuntimeException('Your current password is incorrect.');
            }
            if (
                strlen($newPassword) < 8
                || !preg_match('/[A-Z]/', $newPassword)
                || !preg_match('/[0-9]/', $newPassword)
            ) {
                throw new RuntimeException('The new password must be at least 8 characters and include an uppercase letter and a number.');
            }
            if ($newPassword !== $confirmPassword) {
                throw new RuntimeException('The new password confirmation does not match.');
            }
            if (password_verify($newPassword, $passwordHash)) {
                throw new RuntimeException('Choose a new password different from your current password.');
            }

            $pdo->beginTransaction();
            try {
                $updatePassword = $pdo->prepare(
                    'UPDATE user_account SET password_hash = :password_hash WHERE user_id = :user_id'
                );
                $updatePassword->execute([
                    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                    'user_id' => $userId,
                ]);
                staff_audit('STAFF_PASSWORD_CHANGED', 'user_account', $userId);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            session_regenerate_id(true);
            $_SESSION['staff_profile_success'] = 'Your password was changed successfully.';
            header('Location: staff_profile.php');
            exit;
        }

        throw new RuntimeException('Choose a valid account action.');
    } catch (PDOException $exception) {
        error_log('Staff profile database error: ' . $exception->getMessage());
        $errors[] = 'Your account change could not be saved. Please try again.';
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Staff profile update error: ' . $exception->getMessage());
        $errors[] = 'Your account change could not be saved. Please try again.';
    }
}

$pageTitle = 'My account';
$isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
$dashboardUrl = $isAdmin ? 'admin_analytics.php' : 'staff_dashboard.php';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page staff-page staff-profile-page">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="<?= e($dashboardUrl) ?>"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small><?= $isAdmin ? 'Admin Portal' : 'Staff Portal' ?></small></span></a>
        <?php require __DIR__ . '/includes/staff_nav.php'; ?>
    </aside>
    <section class="dashboard-content">
        <div class="staff-main staff-profile-main">
            <header class="staff-profile-heading"><p class="eyebrow">ACCOUNT SETTINGS</p><h1>Personalize your account</h1><p>Update your profile photo and keep your sign-in password secure.</p></header>
            <?php if ($success !== ''): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
            <?php if ($errors !== []): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <div class="staff-profile-grid">
                <section class="staff-panel staff-profile-card">
                    <div class="staff-profile-section-heading"><span><i class="bi bi-person-circle"></i></span><div><h2>Profile photo</h2><p>Use a clear JPG, PNG, or WEBP image, up to 2 MB.</p></div></div>
                    <div class="staff-profile-preview">
                        <?php if (is_string($profileImageMime) && $profileImageMime !== ''): ?>
                            <img src="staff_profile_photo.php" alt="Your current profile photo">
                        <?php else: ?>
                            <span><i class="bi bi-person-fill"></i></span>
                        <?php endif; ?>
                        <div><strong><?= e((string) ($_SESSION['user']['full_name'] ?? 'Staff account')) ?></strong><small><?= $isAdmin ? 'Administrator' : 'Staff account' ?></small></div>
                    </div>
                    <form method="post" enctype="multipart/form-data" class="staff-profile-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="upload_photo">
                        <label class="form-label" for="profile_photo">Choose a new photo</label>
                        <input class="form-control" id="profile_photo" name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp" required>
                        <button class="btn btn-primary" type="submit"><i class="bi bi-camera"></i> Save profile photo</button>
                    </form>
                </section>
                <section class="staff-panel staff-profile-card">
                    <div class="staff-profile-section-heading"><span><i class="bi bi-shield-lock"></i></span><div><h2>Change password</h2><p>Confirm your current password before choosing a new one.</p></div></div>
                    <form method="post" class="staff-profile-form staff-password-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="change_password">
                        <div><label class="form-label" for="current_password">Current password</label><input class="form-control" id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
                        <div><label class="form-label" for="new_password">New password</label><input class="form-control" id="new_password" name="new_password" type="password" minlength="8" autocomplete="new-password" required><small>At least 8 characters, including an uppercase letter and a number.</small></div>
                        <div><label class="form-label" for="confirm_password">Confirm new password</label><input class="form-control" id="confirm_password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required></div>
                        <button class="btn btn-primary" type="submit"><i class="bi bi-key"></i> Update password</button>
                    </form>
                </section>
            </div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
