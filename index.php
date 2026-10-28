<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/mailer.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$oldEmail = '';
$recoveryEmail = '';
$recoveryMessage = '';
$resetToken = trim((string) ($_GET['reset_token'] ?? ''));
$resetTokenValid = false;
$showRecoveryModal = $resetToken !== '';

function ensure_password_reset_schema(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS password_reset_token (
            reset_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            user_id INT NOT NULL REFERENCES user_account(user_id) ON DELETE CASCADE,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at TIMESTAMPTZ NOT NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
}

if ($resetToken !== '') {
    header('Referrer-Policy: no-referrer');
    ensure_password_reset_schema();
    $tokenLookup = db()->prepare(
        'SELECT reset_id FROM password_reset_token
         WHERE token_hash = :token_hash AND expires_at > CURRENT_TIMESTAMP'
    );
    $tokenLookup->execute(['token_hash' => hash('sha256', $resetToken)]);
    $resetTokenValid = (bool) $tokenLookup->fetchColumn();
    if (!$resetTokenValid) {
        $errors[] = 'This password reset link is invalid or has expired. Request a new link below.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? 'login');

    if ($action === 'request_reset') {
        $showRecoveryModal = true;
        $recoveryEmail = strtolower(trim((string) ($_POST['recovery_email'] ?? '')));
        if (!filter_var($recoveryEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        } else {
            ensure_password_reset_schema();
            $accountLookup = db()->prepare(
                "SELECT ua.user_id
                 FROM user_account ua
                 JOIN resident r ON r.resident_id = ua.resident_id
                 WHERE LOWER(r.email) = LOWER(:email)
                   AND ua.account_status = 'active'
                   AND ua.role = 'resident'
                 LIMIT 1"
            );
            $accountLookup->execute(['email' => $recoveryEmail]);
            $userId = $accountLookup->fetchColumn();

            if ($userId !== false) {
                $recentReset = db()->prepare(
                    "SELECT 1 FROM password_reset_token
                     WHERE user_id = :user_id
                       AND created_at > CURRENT_TIMESTAMP - INTERVAL '1 minute'
                     LIMIT 1"
                );
                $recentReset->execute(['user_id' => $userId]);

                if (!$recentReset->fetchColumn()) {
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);
                    $removeOldTokens = db()->prepare('DELETE FROM password_reset_token WHERE user_id = :user_id');
                    $removeOldTokens->execute(['user_id' => $userId]);
                    $saveToken = db()->prepare(
                        "INSERT INTO password_reset_token (user_id, token_hash, expires_at)
                         VALUES (:user_id, :token_hash, CURRENT_TIMESTAMP + INTERVAL '1 hour')"
                    );
                    $saveToken->execute(['user_id' => $userId, 'token_hash' => $tokenHash]);

                    $resetUrl = rtrim(APP_BASE_URL, '/') . '/index.php?reset_token=' . rawurlencode($token);
                    $safeResetUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
                    $sent = sendEmail(
                        $recoveryEmail,
                        'Reset your Barangay Inosluban account password',
                        '<p>We received a request to reset the password for your Barangay Inosluban account.</p>'
                        . '<p><a href="' . $safeResetUrl . '">Reset your password</a></p>'
                        . '<p>If the link does not open, copy this address into your browser: ' . $safeResetUrl . '</p>'
                        . '<p>This link expires in one hour and can only be used once. If you did not request this, you can ignore this email.</p>'
                    );
                    if (!$sent) {
                        $removeFailedToken = db()->prepare('DELETE FROM password_reset_token WHERE token_hash = :token_hash');
                        $removeFailedToken->execute(['token_hash' => $tokenHash]);
                    }
                }
            }

            $recoveryMessage = 'If an active resident account uses that email and mail delivery is available, a password reset link will be sent. Check your inbox and spam folder.';
        }
    } elseif ($action === 'reset_password') {
        $showRecoveryModal = true;
        $resetToken = trim((string) ($_POST['reset_token'] ?? ''));
        $password = (string) ($_POST['new_password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');
        if ($password === '' || strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must be at least 8 characters and include one uppercase letter and one number.';
        }
        if ($password !== $confirmation) {
            $errors[] = 'The password confirmation does not match.';
        }

        if ($errors === []) {
            ensure_password_reset_schema();
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $tokenLookup = $pdo->prepare(
                    'SELECT reset_id, user_id FROM password_reset_token
                     WHERE token_hash = :token_hash AND expires_at > CURRENT_TIMESTAMP
                     FOR UPDATE'
                );
                $tokenLookup->execute(['token_hash' => hash('sha256', $resetToken)]);
                $reset = $tokenLookup->fetch();
                if (!$reset) {
                    $pdo->rollBack();
                    $errors[] = 'This password reset link is invalid or has expired. Request a new link below.';
                } else {
                    $updatePassword = $pdo->prepare('UPDATE user_account SET password_hash = :password_hash WHERE user_id = :user_id');
                    $updatePassword->execute([
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'user_id' => $reset['user_id'],
                    ]);
                    $removeTokens = $pdo->prepare('DELETE FROM password_reset_token WHERE user_id = :user_id');
                    $removeTokens->execute(['user_id' => $reset['user_id']]);
                    $pdo->commit();
                    $_SESSION['success'] = 'Your password has been reset. You can now log in.';
                    header('Location: index.php');
                    exit;
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
        if ($errors !== []) {
            ensure_password_reset_schema();
            $tokenLookup = db()->prepare(
                'SELECT reset_id FROM password_reset_token
                 WHERE token_hash = :token_hash AND expires_at > CURRENT_TIMESTAMP'
            );
            $tokenLookup->execute(['token_hash' => hash('sha256', $resetToken)]);
            $resetTokenValid = (bool) $tokenLookup->fetchColumn();
        }
    } else {
        $oldEmail = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($oldEmail === '') {
            $errors[] = 'Please enter your username or email address.';
        }
        if ($password === '') {
            $errors[] = 'Please enter your password.';
        }
    }

    if ($action === 'login' && $errors === []) {
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
                    <input type="hidden" name="action" value="login">
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
                        <button class="auth-link-button" type="button" data-bs-toggle="modal" data-bs-target="#passwordRecoveryModal">Forgot password?</button>
                    </div>
                    <button class="btn btn-primary w-100 login-submit" type="submit">Log In <i class="bi bi-arrow-right ms-2"></i></button>
                </form>

                <p class="text-center mt-3 mb-0 small login-signup">Don't have an account? <a href="register.php">Sign up</a></p>
            </section>
        </div>
    </div>
</main>

<div class="modal fade auth-modal" id="passwordRecoveryModal" tabindex="-1" aria-labelledby="passwordRecoveryTitle" aria-hidden="true" <?= $showRecoveryModal ? 'data-open-modal="true"' : '' ?>>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="passwordRecoveryTitle"><?= $resetTokenValid ? 'Choose a new password' : 'Reset your password' ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if ($errors !== []): ?>
                    <div class="alert alert-danger" role="alert">
                        <ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>
                <?php if ($resetTokenValid): ?>
                    <p>Enter a new password for your account. Use at least 8 characters with one uppercase letter and one number.</p>
                    <form method="post" action="index.php" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="reset_token" value="<?= e($resetToken) ?>">
                        <div class="mb-3"><label for="new_password" class="form-label">New password</label><input class="form-control" type="password" id="new_password" name="new_password" autocomplete="new-password" required></div>
                        <div class="mb-3"><label for="password_confirmation" class="form-label">Confirm new password</label><input class="form-control" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required></div>
                        <button class="btn btn-primary w-100" type="submit">Save new password</button>
                    </form>
                <?php else: ?>
                    <p>Enter the email address linked to your resident account. If it matches an active account, we will email you a one-time reset link that expires after one hour.</p>
                    <?php if ($recoveryMessage !== ''): ?><div class="alert alert-success" role="status"><?= e($recoveryMessage) ?></div><?php endif; ?>
                    <form method="post" action="index.php" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="request_reset">
                        <div class="mb-3">
                            <label for="recovery_email" class="form-label">Account email</label>
                            <input class="form-control" type="email" id="recovery_email" name="recovery_email" value="<?= e($recoveryEmail) ?>" autocomplete="email" required>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Email reset link</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>