<?php
declare(strict_types=1);

require_once __DIR__ . "/includes/bootstrap.php";
require_once __DIR__ . "/includes/staff.php";
require_once __DIR__ . "/includes/face_auth.php";

ensure_face_auth_schema();
if (!is_logged_in()) {
    header("Location: index.php");
    exit();
}

$userId = (int) $_SESSION["user"]["id"];
$role = (string) ($_SESSION["user"]["role"] ?? "");
if (!in_array($role, ["staff", "admin"], true)) {
    http_response_code(403);
    exit("Face verification is required only for staff and administrator accounts.");
}

if (is_face_verified()) {
    header(
        "Location: " .
            ($role === "admin" ? "admin_analytics.php" : "staff_dashboard.php"),
    );
    exit();
}

if (!face_auth_is_enrolled($userId)) {
    if ($role === "admin" && face_auth_initial_setup_available()) {
        header("Location: face_enroll.php?initial=1");
        exit();
    }
    $enrollmentRequired = true;
} else {
    $enrollmentRequired = false;
}
$faceEncryptionConfigured = face_auth_is_encryption_configured();

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    if ($role !== "staff" && $role !== "admin") {
        face_auth_json_response(
            ["ok" => false, "message" => "Staff access is required."],
            403,
        );
    }

    $request = face_auth_read_json_request();
    $sessionToken = $_SESSION["csrf_token"] ?? "";
    if (
        !is_string($sessionToken) ||
        !is_string($request["csrf_token"] ?? null) ||
        !hash_equals($sessionToken, $request["csrf_token"])
    ) {
        face_auth_json_response(
            ["ok" => false, "message" => "Your session expired. Refresh and retry."],
            419,
        );
    }
    face_auth_validate_challenge($request);

    if ($enrollmentRequired) {
        face_auth_json_response(
            [
                "ok" => false,
                "message" => "An administrator must enroll your face before you can sign in.",
            ],
            403,
        );
    }
    if (face_auth_is_rate_limited($userId)) {
        face_auth_json_response(
            [
                "ok" => false,
                "message" => "Too many unsuccessful attempts. Wait 15 minutes and try again.",
            ],
            429,
        );
    }

    try {
        $frames = face_auth_decode_frames($request["frames"] ?? null);
        $credentialQuery = db()->prepare(
            "SELECT encrypted_template, nonce, auth_tag
             FROM face_auth_credential WHERE user_id = :user_id",
        );
        $credentialQuery->execute(["user_id" => $userId]);
        $credential = $credentialQuery->fetch();
        if (!$credential) {
            face_auth_json_response(
                [
                    "ok" => false,
                    "message" => "An administrator must enroll your face before you can sign in.",
                ],
                403,
            );
        }

        $template = face_auth_decrypt_template(
            $credential["encrypted_template"],
            $credential["nonce"],
            $credential["auth_tag"],
        );
        $result = face_auth_run_verifier([
            "mode" => "verify",
            "frames" => $frames,
            "template" => base64_encode($template),
        ]);
        if (($result["retryable"] ?? false) === true) {
            face_auth_record_attempt($userId, false);
            face_auth_json_response(
                ["ok" => false, "message" => $result["message"] ?? "Try the camera steps again."],
                422,
            );
        }
        if (!($result["ok"] ?? false)) {
            face_auth_record_attempt($userId, false);
            face_auth_json_response(
                [
                    "ok" => false,
                    "message" => $result["message"] ?? "Face not recognized. Try again.",
                ],
                401,
            );
        }

        face_auth_record_attempt($userId, true);
        if ($role === "staff") {
            $timeLog = staff_start_time_log($userId);
        }
        session_regenerate_id(true);
        unset($_SESSION["csrf_token"], $_SESSION["face_challenge"]);
        csrf_token();
        $_SESSION["face_verified_at"] = time();
        $_SESSION["face_verified_user_id"] = $userId;
        if ($role === "staff") {
            $_SESSION["staff_time_log_id"] = (int) $timeLog["time_log_id"];
        } else {
            $_SESSION["weather_refresh_on_login"] = true;
        }

        face_auth_json_response([
            "ok" => true,
            "message" => "Face verified.",
            "redirect" => $role === "admin" ? "admin_analytics.php" : "staff_dashboard.php",
        ]);
    } catch (RuntimeException $exception) {
        if (
            str_starts_with(
                $exception->getMessage(),
                "Capture all five camera steps",
            ) ||
            str_starts_with($exception->getMessage(), "A camera image")
        ) {
            face_auth_json_response(
                ["ok" => false, "message" => $exception->getMessage()],
                422,
            );
        }
        error_log("Face verification error: " . $exception->getMessage());
        face_auth_json_response(
            [
                "ok" => false,
                "message" => "Face verification is unavailable. Contact the administrator.",
            ],
            503,
        );
    } catch (Throwable $exception) {
        error_log("Face verification error: " . $exception->getMessage());
        face_auth_json_response(
            [
                "ok" => false,
                "message" => "Face verification is unavailable. Contact the administrator.",
            ],
            503,
        );
    }
}

$challenge = face_auth_challenge();
$pageTitle = "Staff face verification";
$hideSiteFooter = true;
$additionalStylesheets = ["assets/css/face-auth.css"];
$additionalScripts = ["assets/js/face-auth.js"];
require __DIR__ . "/includes/header.php";
?>
<main class="face-auth-page">
    <section class="face-auth-card" data-face-auth
        data-mode="verify"
        data-endpoint="face_verify.php"
        data-csrf-token="<?= e(csrf_token()) ?>"
        data-challenge-id="<?= e($challenge["id"]) ?>"
        data-turn-first="<?= e($challenge["turns"][0]) ?>"
        data-turn-second="<?= e($challenge["turns"][1]) ?>">
        <p class="eyebrow"><?= $role === "admin" ? "ADMINISTRATOR ACCESS" : "STAFF SIGN-IN" ?></p>
        <h1>Verify your face</h1>
        <?php if ($enrollmentRequired): ?>
        <div class="alert alert-warning" role="alert">
            Your account does not have a face template yet. Ask an administrator to enroll you.
        </div>
        <?php elseif (!$faceEncryptionConfigured): ?>
        <div class="alert alert-danger" role="alert">
            Face authentication is not configured. Contact the administrator.
        </div>
        <?php else: ?>
        <p>
            Use the camera to confirm your identity. Follow the prompts and keep only your face in
            view. Your camera images are sent to the server for matching and are not saved.
        </p>
        <div class="face-auth-video-wrap">
            <video class="face-auth-video" data-face-video autoplay muted playsinline></video>
        </div>
        <p class="face-auth-message" data-face-message role="status" aria-live="polite">
            Camera access is needed to continue.
        </p>
        <div class="d-grid gap-2">
            <button class="btn btn-primary btn-lg" type="button" data-face-start>
                Start face verification
            </button>
            <a class="btn btn-outline-secondary" href="logout.php">Log out</a>
        </div>
        <?php endif; ?>
        <?php if (
            $role === "admin" &&
            strlen(FACE_ADMIN_RECOVERY_TOKEN) >= 32
        ): ?>
        <a class="btn btn-outline-danger mt-3" href="face_enroll.php?recovery=1">
            Recover administrator face access
        </a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary mt-3" href="logout.php">Log out</a>
        <p class="face-auth-note mt-4 mb-0">
            Face matching is an additional account check, not a guarantee against photo/video
            spoofing. Do not share your password or leave this device unlocked.
        </p>
    </section>
</main>
<?php require __DIR__ . "/includes/footer.php"; ?>
