<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/includes/admin.php';

require_admin();
ensure_staff_tables();

$staffRoles = ['General Staff', 'BHW'];
$errors = [];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        if (($_POST['action'] ?? '') === 'update_staff') {
            $staffId = filter_var($_POST['staff_id'] ?? '', FILTER_VALIDATE_INT);
            $position = trim((string) ($_POST['position'] ?? ''));
            $staffRole = (string) ($_POST['staff_role'] ?? '');
            $status = (string) ($_POST['account_status'] ?? '');
            if (!$staffId || !in_array($staffRole, $staffRoles, true) || !in_array($status, ['active', 'inactive', 'pending', 'locked'], true)) {
                throw new RuntimeException('Invalid staff account update.');
            }
            db()->beginTransaction();
            $update = db()->prepare('UPDATE staff SET position = NULLIF(:position, \'\'), staff_role = :staff_role WHERE staff_id = :staff_id');
            $update->execute(['position' => $position, 'staff_role' => $staffRole, 'staff_id' => $staffId]);
            $account = db()->prepare('UPDATE user_account SET account_status = :account_status WHERE user_id = (SELECT user_id FROM staff WHERE staff_id = :staff_id)');
            $account->execute(['account_status' => $status, 'staff_id' => $staffId]);
            db()->commit();
            staff_audit('STAFF_ACCOUNT_UPDATED', 'staff', $staffId, [
                'position' => $position,
                'staff_role' => $staffRole,
                'account_status' => $status,
            ]);
            $message = 'Staff account updated.';
        } else {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $position = trim((string) ($_POST['position'] ?? ''));
            $staffRole = (string) ($_POST['staff_role'] ?? '');
            if ($username === '' || $firstName === '' || $lastName === '' || strlen($password) < 8 || !in_array($staffRole, $staffRoles, true)) {
                throw new RuntimeException('Username, name, a valid staff role, and a password of at least 8 characters are required.');
            }
            db()->beginTransaction();
            $account = db()->prepare('INSERT INTO user_account (username, password_hash, role, account_status) VALUES (:username, :password_hash, \'staff\', \'active\') RETURNING user_id');
            $account->execute(['username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) $account->fetchColumn();
            $staff = db()->prepare('INSERT INTO staff (user_id, first_name, last_name, position, staff_role) VALUES (:user_id, :first_name, :last_name, NULLIF(:position, \'\'), :staff_role) RETURNING staff_id');
            $staff->execute([
                'user_id' => $userId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'position' => $position,
                'staff_role' => $staffRole,
            ]);
            $staffId = (int) $staff->fetchColumn();
            db()->commit();
            staff_audit('STAFF_ACCOUNT_CREATED', 'staff', $staffId, ['username' => $username, 'staff_role' => $staffRole]);
            $message = 'Staff account created successfully.';
        }
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('Staff account management error: ' . $exception->getMessage());
        $errors[] = str_contains($exception->getMessage(), 'duplicate key')
            ? 'That username is already in use.'
            : $exception->getMessage();
    }
}

$staffMembers = db()->query(
    'SELECT s.staff_id, s.first_name, s.last_name, s.position, s.staff_role,
            ua.username, ua.account_status
     FROM staff s
     JOIN user_account ua ON ua.user_id = s.user_id
     ORDER BY s.last_name, s.first_name'
)->fetchAll();
$activeStaffCount = count(array_filter($staffMembers, static fn (array $member): bool => $member['account_status'] === 'active'));
$pendingStaffCount = count(array_filter($staffMembers, static fn (array $member): bool => $member['account_status'] === 'pending'));
$pageTitle = 'Manage staff';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page staff-page admin-staff-page">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="admin_analytics.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Admin Portal</small></span></a>
        <?php require __DIR__ . '/includes/staff_nav.php'; ?>
    </aside>
    <section class="dashboard-content">
        <div class="staff-main admin-staff-main">
            <header class="admin-staff-heading">
                <div><p class="eyebrow">ADMINISTRATION</p><h1>Staff directory</h1><p>Create staff accounts, maintain their assignments, and control portal access.</p></div>
                <span><i class="bi bi-shield-check"></i> Access controlled</span>
            </header>
            <div class="admin-staff-summary">
                <article><span><i class="bi bi-people"></i></span><div><small>Total staff</small><strong><?= number_format(count($staffMembers)) ?></strong></div></article>
                <article><span><i class="bi bi-person-check"></i></span><div><small>Active accounts</small><strong><?= number_format($activeStaffCount) ?></strong></div></article>
                <article><span><i class="bi bi-person-exclamation"></i></span><div><small>Pending accounts</small><strong><?= number_format($pendingStaffCount) ?></strong></div></article>
            </div>
            <?php if ($message !== ''): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
            <?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>

            <div class="admin-staff-layout">
                <section class="staff-panel admin-staff-create">
                    <div class="admin-staff-panel-heading"><span><i class="bi bi-person-plus"></i></span><div><h2>Create staff account</h2><p>Set up a new member of the barangay team.</p></div></div>
                    <form method="post" class="admin-staff-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <div class="admin-staff-form-grid">
                            <div><label class="form-label" for="first_name">First name</label><input class="form-control" id="first_name" name="first_name" autocomplete="given-name" required></div>
                            <div><label class="form-label" for="last_name">Last name</label><input class="form-control" id="last_name" name="last_name" autocomplete="family-name" required></div>
                            <div><label class="form-label" for="staff_role">Staff role</label><select class="form-control" id="staff_role" name="staff_role" required><?php foreach ($staffRoles as $role): ?><option value="<?= e($role) ?>"><?= e($role) ?></option><?php endforeach; ?></select></div>
                            <div><label class="form-label" for="position">Position</label><input class="form-control" id="position" name="position" placeholder="Optional"></div>
                            <div><label class="form-label" for="username">Username</label><input class="form-control" id="username" name="username" autocomplete="username" required></div>
                            <div><label class="form-label" for="password">Temporary password</label><input class="form-control" id="password" name="password" type="password" minlength="8" autocomplete="new-password" required><small>At least 8 characters</small></div>
                        </div>
                        <button class="btn btn-primary" type="submit"><i class="bi bi-person-plus"></i> Create account</button>
                    </form>
                </section>

                <section class="staff-panel admin-staff-directory">
                    <div class="admin-staff-panel-heading"><span><i class="bi bi-person-vcard"></i></span><div><h2>Staff accounts</h2><p>Update role, assignment, or account availability.</p></div><strong><?= number_format(count($staffMembers)) ?></strong></div>
                    <div class="admin-staff-list">
                        <?php foreach ($staffMembers as $member): ?>
                            <?php $accountStatus = (string) $member['account_status']; ?>
                            <form method="post" class="admin-staff-record">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="update_staff">
                                <input type="hidden" name="staff_id" value="<?= (int) $member['staff_id'] ?>">
                                <div class="admin-staff-record-heading">
                                    <span class="admin-staff-avatar"><?= e(strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1))) ?></span>
                                    <div><strong><?= e($member['first_name'] . ' ' . $member['last_name']) ?></strong><small>@<?= e($member['username']) ?></small></div>
                                    <span class="admin-staff-status status-<?= e($accountStatus) ?>"><?= e(ucfirst($accountStatus)) ?></span>
                                </div>
                                <div class="admin-staff-record-fields">
                                    <div><label class="form-label">Position</label><input class="form-control form-control-sm" name="position" value="<?= e($member['position'] ?? '') ?>" placeholder="Position"></div>
                                    <div><label class="form-label">Staff role</label><select class="form-control form-control-sm" name="staff_role" aria-label="Staff role"><?php foreach ($staffRoles as $role): ?><option value="<?= e($role) ?>" <?= $member['staff_role'] === $role ? 'selected' : '' ?>><?= e($role) ?></option><?php endforeach; ?></select></div>
                                    <div><label class="form-label">Account status</label><select class="form-control form-control-sm" name="account_status"><?php foreach (['active', 'inactive', 'pending', 'locked'] as $status): ?><option value="<?= e($status) ?>" <?= $accountStatus === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?></select></div>
                                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-check2"></i> Save</button>
                                </div>
                            </form>
                        <?php endforeach; ?>
                        <?php if ($staffMembers === []): ?><p class="analytics-empty">No staff accounts yet. Create the first account using the form.</p><?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
