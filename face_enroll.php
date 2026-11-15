<?php
declare(strict_types=1);

require_once __DIR__ . "/includes/bootstrap.php";
require_once __DIR__ . "/includes/staff.php";
require_once __DIR__ . "/includes/face_auth.php";

ensure_face_auth_schema();
if (!is_logged_in() || ($_SESSION["user"]["role"] ?? "") !== "admin") {
    http_response_code(403);
    exit("Administrator access is required.");
}

$currentAdminId = (int) $_SESSION["user"]["id"];
$initialBootstrap = face_auth_initial_setup_available();
$recoveryEnrollment =
    ($_GET["recovery"] ?? "") === "1" &&
    ($_SESSION["user"]["role"] ?? "") === "admin";
$faceEncryptionConfigured = face_auth_is_encryption_configured();
if (!$initialBootstrap && !$recoveryEnrollment && !is_face_verified()) {
    require_face_verified();
}

$requestedUserId = filter_var(
    $_GET["user_id"] ?? $currentAdminId,
    FILTER_VALIDATE_INT,
);
$targetUserId = $requestedUserId === false ? 0 : (int) $requestedUserId;
if (
    ($initialBootstrap || $recoveryEnrollment) &&
    $targetUserId !== $currentAdminId
) {
    http_response_code(403);
    exit("This recovery enrollment must be completed for the current administrator.");
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
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

    $targetUserId = filter_var(
        $request["user_id"] ?? null,
        FILTER_VALIDATE_INT,
    );
    if ($targetUserId === false || $targetUserId === null || $targetUserId < 1) {
        face_auth_json_response(
            ["ok" => false, "message" => "Select an active staff or admin account."],
            422,
        );
    }
    if ($initialBootstrap || $recoveryEnrollment) {
        $requiredToken = $recoveryEnrollment
            ? FACE_ADMIN_RECOVERY_TOKEN
            : FACE_ENROLLMENT_SETUP_TOKEN;
        $tokenField = $recoveryEnrollment ? "recovery_token" : "setup_token";
        if (
            $targetUserId !== $currentAdminId ||
            strlen($requiredToken) < 32 ||
            ($initialBootstrap && strlen(FACE_ADMIN_RECOVERY_TOKEN) < 32) ||
            !is_string($request[$tokenField] ?? null) ||
            !hash_equals($requiredToken, $request[$tokenField])
        ) {
            face_auth_json_response(
                [
                    "ok" => false,
                    "message" => "The administrator setup or recovery token is missing or invalid.",
                ],
                403,
            );
        }
    } elseif (!is_face_verified()) {
        face_auth_json_response(
            ["ok" => false, "message" => "Administrator face verification is required."],
            403,
        );
    }
    if (($request["biometric_consent"] ?? false) !== true) {
        face_auth_json_response(
            [
                "ok" => false,
                "message" => "The staff member must consent before face enrollment.",
            ],
            422,
        );
    }

    $accountQuery = db()->prepare(
        "SELECT user_id, role
         FROM user_account
         WHERE user_id = :user_id
           AND role IN ('staff', 'admin')
           AND account_status = 'active'",
    );
    $accountQuery->execute(["user_id" => $targetUserId]);
    $targetAccount = $accountQuery->fetch();
    if (!$targetAccount) {
        face_auth_json_response(
            ["ok" => false, "message" => "Select an active staff or admin account."],
            404,
        );
    }

    try {
        $frames = face_auth_decode_frames($request["frames"] ?? null);
        $result = face_auth_run_verifier([
            "mode" => "enroll",
            "frames" => $frames,
        ]);
        if (!($result["ok"] ?? false)) {
            face_auth_json_response(
                [
                    "ok" => false,
                    "message" => $result["message"] ?? "Face enrollment did not succeed.",
                ],
                422,
            );
        }

        $template = base64_decode((string) ($result["template"] ?? ""), true);
        if (!is_string($template) || $template === "") {
            throw new RuntimeException("The face verifier returned an invalid template.");
        }
        $encrypted = face_auth_encrypt_template($template);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($initialBootstrap && !$recoveryEnrollment) {
                $claimInitialSetup = $pdo->prepare(
                    "INSERT INTO face_auth_setup (setup_id, completed_at)
                     VALUES (1, CURRENT_TIMESTAMP)
                     ON CONFLICT (setup_id) DO NOTHING",
                );
                $claimInitialSetup->execute();
                if ($claimInitialSetup->rowCount() !== 1) {
                    $pdo->rollBack();
                    face_auth_json_response(
                        [
                            "ok" => false,
                            "message" => "Initial administrator setup has already been completed.",
                        ],
                        409,
                    );
                }
            }

            $saveCredential = $pdo->prepare(
                "INSERT INTO face_auth_credential (
                    user_id, encrypted_template, nonce, auth_tag, consented_at, enrolled_by, updated_at
                 ) VALUES (
                    :user_id, :encrypted_template, :nonce, :auth_tag, CURRENT_TIMESTAMP, :enrolled_by, CURRENT_TIMESTAMP
                 )
                 ON CONFLICT (user_id) DO UPDATE SET
                    encrypted_template = EXCLUDED.encrypted_template,
                    nonce = EXCLUDED.nonce,
                    auth_tag = EXCLUDED.auth_tag,
                    consented_at = EXCLUDED.consented_at,
                    enrolled_by = EXCLUDED.enrolled_by,
                    updated_at = CURRENT_TIMESTAMP",
            );
            $saveCredential->execute([
                "user_id" => $targetUserId,
                "encrypted_template" => $encrypted["encrypted_template"],
                "nonce" => $encrypted["nonce"],
                "auth_tag" => $encrypted["auth_tag"],
                "enrolled_by" => $currentAdminId,
            ]);
            staff_audit(
                "FACE_AUTH_ENROLLED",
                "user_account",
                $targetUserId,
                ["role" => $targetAccount["role"]],
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        unset($_SESSION["face_challenge"]);
        if ($initialBootstrap || $recoveryEnrollment) {
            face_auth_json_response([
                "ok" => true,
                "message" => $recoveryEnrollment
                    ? "Administrator face recovery complete."
                    : "Initial administrator enrollment complete.",
                "redirect" => "face_verify.php",
            ]);
        }

        $_SESSION["success"] = "Face enrollment saved.";
        face_auth_json_response([
            "ok" => true,
            "message" => "Face enrollment saved.",
            "redirect" => "face_enroll.php",
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
        error_log("Face enrollment error: " . $exception->getMessage());
        face_auth_json_response(
            [
                "ok" => false,
                "message" => "Face enrollment is unavailable. Contact the administrator.",
            ],
            503,
        );
    } catch (Throwable $exception) {
        error_log("Face enrollment error: " . $exception->getMessage());
        face_auth_json_response(
            [
                "ok" => false,
                "message" => "Face enrollment is unavailable. Contact the administrator.",
            ],
            503,
        );
    }
}

if (
    !$initialBootstrap &&
    !$recoveryEnrollment &&
    $targetUserId === $currentAdminId
) {
    $targetUserId = 0;
}

$targetAccount = null;
if ($targetUserId > 0) {
    $targetQuery = db()->prepare(
        "SELECT account.user_id, account.username, account.role,
                COALESCE(
                    NULLIF(BTRIM(CONCAT_WS(' ', staff.first_name, staff.last_name)), ''),
                    account.username
                ) AS display_name
         FROM user_account account
         LEFT JOIN staff ON staff.user_id = account.user_id
         WHERE account.user_id = :user_id
           AND account.role IN ('staff', 'admin')
           AND account.account_status = 'active'",
    );
    $targetQuery->execute(["user_id" => $targetUserId]);
    $targetAccount = $targetQuery->fetch();
    if (!$targetAccount) {
        http_response_code(404);
        exit("The selected staff or administrator account is not active.");
    }
}

$challenge = face_auth_challenge();
$pageTitle = $recoveryEnrollment
    ? "Recover administrator face access"
    : ($initialBootstrap
        ? "Set up administrator face access"
        : "Face enrollment");
$hideSiteFooter = true;
$additionalStylesheets = ["assets/css/face-auth.css"];
$additionalScripts = ["assets/js/face-auth.js"];
require __DIR__ . "/includes/header.php";
?>
<main class="face-auth-page">
    <section class="face-auth-card"
        <?php if ($targetAccount): ?>data-face-auth
        data-mode="enroll"
        data-endpoint="face_enroll.php<?= $recoveryEnrollment ? "?recovery=1" : ($initialBootstrap ? "?initial=1" : "") ?>"
        data-csrf-token="<?= e(csrf_token()) ?>"
        data-challenge-id="<?= e($challenge["id"]) ?>"
        data-turn-first="<?= e($challenge["turns"][0]) ?>"
        data-turn-second="<?= e($challenge["turns"][1]) ?>"
        data-user-id="<?= (int) $targetAccount["user_id"] ?>"<?php endif; ?>>
        <p class="eyebrow">
            <?= $recoveryEnrollment
                ? "ADMINISTRATOR RECOVERY"
                : ($initialBootstrap
                    ? "ONE-TIME SECURITY SETUP"
                    : "ADMINISTRATION") ?>
        </p>
        <h1>
            <?= $recoveryEnrollment
                ? "Recover administrator face access"
                : ($initialBootstrap
                    ? "Set up administrator face access"
                    : "Enroll a staff member") ?>
        </h1>

        <?php if (!empty($_SESSION["success"])): ?>
        <div class="alert alert-success"><?= e((string) $_SESSION["success"]) ?></div>
        <?php unset($_SESSION["success"]); ?>
        <?php endif; ?>

        <?php if ($initialBootstrap && !$recoveryEnrollment): ?>
        <p>
            This first setup is only available while no active administrator has a face template.
            The setup token must be configured privately on the server and is used once.
        </p>
        <?php if (strlen(FACE_ADMIN_RECOVERY_TOKEN) >= 32): ?>
        <p>
            <a href="face_enroll.php?recovery=1">
                Use the administrator recovery token instead
            </a>
        </p>
        <?php endif; ?>
        <?php if (strlen(FACE_ENROLLMENT_SETUP_TOKEN) < 32): ?>
        <div class="alert alert-danger" role="alert">
            Face setup is not configured. Set a private, random
            <code>FACE_ENROLLMENT_SETUP_TOKEN</code> (at least 32 characters) and reload this page.
        </div>
        <?php endif; ?>
        <?php if (strlen(FACE_ADMIN_RECOVERY_TOKEN) < 32): ?>
        <div class="alert alert-danger" role="alert">
            Configure a separate random <code>FACE_ADMIN_RECOVERY_TOKEN</code> before enrolling
            the initial administrator.
        </div>
        <?php endif; ?>
        <?php if (!$faceEncryptionConfigured): ?>
        <div class="alert alert-danger" role="alert">
            Face setup is not configured. Set a valid base64-encoded
            <code>FACE_ENCRYPTION_KEY</code> and reload this page.
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($recoveryEnrollment): ?>
        <p>
            Use this only if you cannot pass the face check. This recovery requires the private
            administrator recovery token and enrolls only your own active administrator account.
        </p>
        <?php if (strlen(FACE_ADMIN_RECOVERY_TOKEN) < 32): ?>
        <div class="alert alert-danger" role="alert">
            Administrator recovery is not configured. Set a separate random
            <code>FACE_ADMIN_RECOVERY_TOKEN</code> and reload this page.
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (!$targetAccount && !$initialBootstrap && !$recoveryEnrollment): ?>
        <p>Select an active staff or administrator account. Ask that person to be present and
            confirm consent before capturing a face template.</p>
        <?php
        $accounts = db()
            ->query(
                "SELECT account.user_id, account.username, account.role,
                        COALESCE(
                            NULLIF(BTRIM(CONCAT_WS(' ', staff.first_name, staff.last_name)), ''),
                            account.username
                        ) AS display_name,
                        CASE WHEN EXISTS (
                            SELECT 1 FROM face_auth_credential credential
                            WHERE credential.user_id = account.user_id
                        ) THEN 1 ELSE 0 END AS is_enrolled
                 FROM user_account account
                 LEFT JOIN staff ON staff.user_id = account.user_id
                 WHERE account.role IN ('staff', 'admin')
                   AND account.account_status = 'active'
                 ORDER BY account.role, display_name",
            )
            ->fetchAll();
        ?>
        <div class="list-group">
            <?php foreach ($accounts as $account): ?>
            <a class="list-group-item list-group-item-action d-flex justify-content-between gap-3"
                href="face_enroll.php?user_id=<?= (int) $account["user_id"] ?>">
                <span>
                    <strong><?= e($account["display_name"]) ?></strong>
                    <small class="d-block text-muted">
                        <?= e(ucfirst($account["role"])) ?> · @<?= e($account["username"]) ?>
                    </small>
                </span>
                <span class="badge text-bg-<?= (int) $account["is_enrolled"] === 1 ? "success" : "secondary" ?>">
                    <?= (int) $account["is_enrolled"] === 1 ? "Enrolled" : "Needs enrollment" ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
        <a class="btn btn-outline-secondary mt-3" href="admin_staff.php">Back to staff directory</a>
        <?php else: ?>
        <p>
            Enrolling:
            <strong><?= e($targetAccount["display_name"]) ?></strong>
            (<?= e(ucfirst($targetAccount["role"])) ?>). The person being enrolled must be present
            and agree to the biometric template being stored.
        </p>
        <?php if (!$recoveryEnrollment && $initialBootstrap && strlen(FACE_ENROLLMENT_SETUP_TOKEN) >= 32): ?>
        <label class="form-label" for="setup_token">One-time administrator setup token</label>
        <input class="form-control" id="setup_token" name="setup_token" type="password"
            autocomplete="off" required>
        <?php elseif ($recoveryEnrollment && strlen(FACE_ADMIN_RECOVERY_TOKEN) >= 32): ?>
        <label class="form-label" for="recovery_token">Administrator recovery token</label>
        <input class="form-control" id="recovery_token" name="recovery_token" type="password"
            autocomplete="off" required>
        <?php endif; ?>
        <?php if (
            !$faceEncryptionConfigured &&
            (!$initialBootstrap || $recoveryEnrollment)
        ): ?>
        <div class="alert alert-danger" role="alert">
            Face setup is not configured. Set a valid base64-encoded
            <code>FACE_ENCRYPTION_KEY</code> and reload this page.
        </div>
        <?php endif; ?>
        <label class="face-auth-consent">
            <input type="checkbox" name="biometric_consent" value="1">
            <span>
                I consent to the server processing my camera frames to create an encrypted face
                template for staff sign-in. The original images are not retained.
            </span>
        </label>
        <div class="face-auth-video-wrap">
            <video class="face-auth-video" data-face-video autoplay muted playsinline></video>
        </div>
        <p class="face-auth-message" data-face-message role="status" aria-live="polite">
            Camera access is needed to continue.
        </p>
        <div class="d-grid gap-2">
            <button class="btn btn-primary btn-lg" type="button" data-face-start
                <?= (!$recoveryEnrollment &&
                $initialBootstrap &&
                (strlen(FACE_ENROLLMENT_SETUP_TOKEN) < 32 ||
                    strlen(FACE_ADMIN_RECOVERY_TOKEN) < 32)) ||
                ($recoveryEnrollment && strlen(FACE_ADMIN_RECOVERY_TOKEN) < 32) ||
                !$faceEncryptionConfigured
                    ? "disabled"
                    : "" ?>>
                Capture face enrollment
            </button>
            <a class="btn btn-outline-secondary"
                href="<?= $initialBootstrap || $recoveryEnrollment ? "face_verify.php" : "face_enroll.php" ?>">
                Cancel
            </a>
        </div>
        <?php endif; ?>

        <p class="face-auth-note mt-4 mb-0">
            Face matching is an additional account check, not a guarantee against photo/video
            spoofing. Do not enroll anyone without their informed consent.
        </p>
    </section>
</main>
<?php require __DIR__ . "/includes/footer.php"; ?>
