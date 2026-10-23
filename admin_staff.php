<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/includes/admin.php';
require_admin();
$errors = [];
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        if (($_POST['action'] ?? '') === 'update_staff') {
            $staffId = filter_var($_POST['staff_id'] ?? '', FILTER_VALIDATE_INT);
            $position = trim((string) ($_POST['position'] ?? ''));
            $status = (string) ($_POST['account_status'] ?? '');
            if (!$staffId || !in_array($status, ['active', 'inactive', 'pending', 'locked'], true)) {
                throw new RuntimeException('Invalid staff account update.');
            }
            $update = db()->prepare('UPDATE staff SET position = NULLIF(:position, \'\') WHERE staff_id = :staff_id');
            $update->execute(['position' => $position, 'staff_id' => $staffId]);
            $account = db()->prepare('UPDATE user_account SET account_status = :account_status WHERE user_id = (SELECT user_id FROM staff WHERE staff_id = :staff_id)');
            $account->execute(['account_status' => $status, 'staff_id' => $staffId]);
            staff_audit('STAFF_ACCOUNT_UPDATED', 'staff', $staffId, ['position' => $position, 'account_status' => $status]);
            $message = 'Staff account updated.';
        } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $position = trim((string) ($_POST['position'] ?? ''));
        if ($username === '' || $firstName === '' || $lastName === '' || strlen($password) < 8) {
            throw new RuntimeException('Username, first name, last name, and a password of at least 8 characters are required.');
        }
        db()->beginTransaction();
        $account = db()->prepare('INSERT INTO user_account (username, password_hash, role, account_status) VALUES (:username, :password_hash, \'staff\', \'active\') RETURNING user_id');
        $account->execute(['username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int) $account->fetchColumn();
        $staff = db()->prepare('INSERT INTO staff (user_id, first_name, last_name, position) VALUES (:user_id, :first_name, :last_name, NULLIF(:position, \'\')) RETURNING staff_id');
        $staff->execute(['user_id' => $userId, 'first_name' => $firstName, 'last_name' => $lastName, 'position' => $position]);
        $staffId = (int) $staff->fetchColumn();
        db()->commit();
        staff_audit('STAFF_ACCOUNT_CREATED', 'staff', $staffId, ['username' => $username]);
        $message = 'Staff account created successfully.';
        }
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('Staff account creation error: ' . $exception->getMessage());
        $errors[] = str_contains($exception->getMessage(), 'duplicate key') ? 'That username is already in use.' : $exception->getMessage();
    }
}
$staffMembers = db()->query('SELECT s.staff_id, s.first_name, s.last_name, s.position, ua.username, ua.account_status FROM staff s JOIN user_account ua ON ua.user_id = s.user_id ORDER BY s.last_name, s.first_name')->fetchAll();
$pageTitle = 'Manage staff';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page staff-page"><aside class="dashboard-sidebar"><a class="dashboard-brand" href="staff_dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Staff Portal</small></span></a><?php require __DIR__ . '/includes/staff_nav.php'; ?></aside><section class="dashboard-content"><div class="staff-main"><div class="staff-hero compact"><div><p class="eyebrow">ADMINISTRATION</p><h1>Manage staff accounts</h1><p>Create accounts, reposition staff, or deactivate access.</p></div></div><?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?><?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?><div class="staff-request-layout"><section class="staff-panel p-4"><h2 class="h5">Create staff account</h2><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="row g-3"><div class="col-md-6"><label class="form-label" for="first_name">First name</label><input class="form-control" id="first_name" name="first_name" required></div><div class="col-md-6"><label class="form-label" for="last_name">Last name</label><input class="form-control" id="last_name" name="last_name" required></div><div class="col-md-6"><label class="form-label" for="position">Position</label><input class="form-control" id="position" name="position"></div><div class="col-md-6"><label class="form-label" for="username">Username</label><input class="form-control" id="username" name="username" autocomplete="username" required></div><div class="col-md-6"><label class="form-label" for="password">Temporary password</label><input class="form-control" id="password" name="password" type="password" minlength="8" autocomplete="new-password" required></div></div><button class="btn btn-primary mt-4" type="submit">Create staff account</button></form></section><section class="staff-panel p-4"><h2 class="h5">Current staff</h2><?php foreach ($staffMembers as $member): ?><form method="post" class="border-bottom py-3"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_staff"><input type="hidden" name="staff_id" value="<?= (int) $member['staff_id'] ?>"><strong><?= e($member['first_name'] . ' ' . $member['last_name']) ?></strong><small class="d-block text-muted"><?= e($member['username']) ?></small><div class="d-flex gap-2 mt-2"><input class="form-control form-control-sm" name="position" value="<?= e($member['position'] ?? '') ?>" placeholder="Position"><select class="form-control form-control-sm" name="account_status"><?php foreach (['active', 'inactive', 'pending', 'locked'] as $status): ?><option <?= $member['account_status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-primary" type="submit">Save</button></div></form><?php endforeach; ?><?php if ($staffMembers === []): ?><p class="text-muted">No staff accounts have been created.</p><?php endif; ?></section></div></div></section></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
