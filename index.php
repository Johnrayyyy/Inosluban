<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/config/config.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$oldEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $oldEmail = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($oldEmail === '') {
        $errors[] = 'Please enter your username or email address.';
    }
    if ($password === '') {
        $errors[] = 'Please enter your password.';
    }

    if ($errors === []) {
        $statement = db()->prepare(
            "SELECT ua.user_id, ua.role, ua.resident_id, ua.password_hash, ua.account_status, s.staff_id,
                    COALESCE(r.first_name, s.first_name) AS first_name,
                    r.middle_name,
                    COALESCE(r.last_name, s.last_name) AS last_name,
                    COALESCE(r.email, '') AS email
             FROM user_account ua
             LEFT JOIN resident r ON r.resident_id = ua.resident_id
             LEFT JOIN staff s ON s.user_id = ua.user_id
             WHERE ua.username = :identifier OR r.email = :email
             LIMIT 1"
        );
        $statement->execute(['identifier' => $oldEmail, 'email' => strtolower($oldEmail)]);
        $user = $statement->fetch();

        if ($user && $user['account_status'] === 'active' && password_verify($password, $user['password_hash'])) {
            $timeLog = $user['role'] === 'staff'
                ? staff_start_time_log((int) $user['user_id'])
                : null;
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int) $user['user_id'],
                'role' => $user['role'],
                'resident_id' => (int) $user['resident_id'],
                'staff_id' => (int) ($user['staff_id'] ?? 0),
                'full_name' => trim(implode(' ', array_filter([$user['first_name'], $user['middle_name'], $user['last_name']]))),
                'email' => $user['email'],
            ];
            if ($timeLog !== null) {
                $_SESSION['staff_time_log_id'] = (int) $timeLog['time_log_id'];
            }
            header('Location: ' . (in_array($user['role'], ['staff', 'admin'], true) ? 'staff_dashboard.php' : 'dashboard.php'));
            exit;
        }

        $errors[] = $user && $user['account_status'] !== 'active'
            ? 'This account is inactive. Contact the administrator.'
            : 'The email or password is incorrect.';
    }
}

$pageTitle = 'Log in';
require __DIR__ . '/includes/header.php';
?>
<main class="auth-page login-page">
    <div class="container py-4 py-lg-5">
        <div class="auth-shell">
            <section class="brand-panel login-brand">
                <img src="assets/images/logo.png" alt="Barangay Inosluban logo" class="brand-logo">
                <p class="eyebrow">BARANGAY INOSLUBAN</p>
                <p class="brand-location">LIPA CITY, BATANGAS</p>
                <p class="brand-motto">A United Community<br>for a Progressive Tomorrow</p>
            </section>

            <section class="form-panel">
                <div class="form-heading">
                    <div>
                        <h2>Welcome Back</h2>
                        <p>Log in to your barangay account</p>
                    </div>
                </div>

                <?php require __DIR__ . '/includes/alerts.php'; ?>

                <form method="post" action="index.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <div class="mb-3">
                        <label for="username" class="visually-hidden">Email or username</label>
                        <div class="input-with-icon">
                            <i class="bi bi-envelope"></i>
                            <input type="text" class="form-control <?= $errors && $oldEmail === '' ? 'is-invalid' : '' ?>" id="username" name="username" placeholder="Email or Username" value="<?= e($oldEmail) ?>" autocomplete="username" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="visually-hidden">Password</label>
                        <div class="password-field input-with-icon">
                            <i class="bi bi-lock"></i>
                            <input type="password" class="form-control" id="password" name="password" placeholder="Password" autocomplete="current-password" required>
                            <button type="button" class="password-toggle" data-password-toggle="#password" aria-label="Show password"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="login-options mb-3">
                        <label class="form-check">
                            <input type="checkbox" class="form-check-input" name="remember" value="1">
                            <span class="form-check-label">Remember me</span>
                        </label>
                        <a href="mailto:barangay.inosluban@example.com">Forgot password?</a>
                    </div>
                    <button class="btn btn-primary w-100 login-submit" type="submit">Log In <i class="bi bi-arrow-right ms-2"></i></button>
                </form>

                <div class="login-divider"><span>OR</span></div>
                <div class="social-buttons">
                    <button type="button" class="btn btn-social"><strong>G</strong> Continue with Google</button>
                    <button type="button" class="btn btn-social"><i class="bi bi-envelope"></i> Continue with Email</button>
                </div>
                <p class="text-center mt-3 mb-0 small login-signup">Don't have an account? <a href="register.php">Sign up</a></p>
            </section>
        </div>
    </div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>