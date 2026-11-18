<?php
declare(strict_types=1);

require_once __DIR__ . "/includes/bootstrap.php";
require_once __DIR__ . "/config/mailer.php";
require_once __DIR__ . "/includes/national_id.php";
require_resident();
ensure_national_id_schema();

$residentId = (int) $_SESSION["user"]["resident_id"];
$errors = [];
$success = (string) ($_SESSION["profile_success"] ?? "");
unset($_SESSION["profile_success"]);

$fields = [
    "first_name",
    "middle_name",
    "last_name",
    "birthdate",
    "place_of_birth",
    "gender",
    "civil_status",
    "nationality",
    "religion",
    "complete_address",
    "purok",
    "residency_start_date",
    "mobile_number",
    "email",
    "voters_status",
    "employment_status",
    "occupation",
    "educational_attainment",
    "blood_type",
    "disability_special_needs",
    "emergency_contact_person",
    "emergency_contact_number",
];
$selects = [
    "gender" => ["Male", "Female", "Other"],
    "civil_status" => ["Single", "Married", "Widowed", "Separated", "Divorced"],
    "voters_status" => ["Registered", "Not Registered"],
    "employment_status" => [
        "Employed",
        "Self-employed",
        "Unemployed",
        "Student",
        "Retired",
    ],
];
$columns =
    implode(
        ", ",
        array_map(
            static fn(string $field): string => "r.{$field} AS {$field}",
            $fields,
        ),
    ) . ", r.resident_id, r.valid_id_file_path, r.profile_image_path";

function profile_upload(
    string $field,
    string $prefix,
    array $allowedMimeTypes,
): ?string {
    if (empty($_FILES[$field]["name"])) {
        return null;
    }
    if (
        $_FILES[$field]["error"] !== UPLOAD_ERR_OK ||
        $_FILES[$field]["size"] > 5 * 1024 * 1024
    ) {
        throw new RuntimeException("Uploaded files must be smaller than 5 MB.");
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]["tmp_name"]);
    if (!isset($allowedMimeTypes[$mime])) {
        throw new RuntimeException("The uploaded file type is not allowed.");
    }
    $directory = __DIR__ . "/storage/uploads";
    if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
        throw new RuntimeException("Unable to prepare secure file storage.");
    }
    $filename =
        $prefix .
        "-" .
        bin2hex(random_bytes(16)) .
        "." .
        $allowedMimeTypes[$mime];
    if (
        !move_uploaded_file(
            $_FILES[$field]["tmp_name"],
            $directory . "/" . $filename,
        )
    ) {
        throw new RuntimeException("The uploaded file could not be saved.");
    }
    return "storage/uploads/" . $filename;
}

try {
    $statement = db()->prepare(
        "SELECT {$columns} FROM resident r JOIN user_account ua ON ua.resident_id = r.resident_id WHERE r.resident_id = :resident_id",
    );
    $statement->execute(["resident_id" => $residentId]);
    $profile = $statement->fetch();
    if (!$profile) {
        http_response_code(404);
        exit("Resident profile not found.");
    }
    $verificationStatement = db()->prepare(
        "SELECT status, id_format, submitted_at, processed_at,
                reviewed_at, staff_reason
         FROM national_id_verification
         WHERE resident_id = :resident_id",
    );
    $verificationStatement->execute(["resident_id" => $residentId]);
    $nationalIdVerification = $verificationStatement->fetch();

    $values = [];
    foreach ($fields as $field) {
        $values[$field] = (string) ($profile[$field] ?? "");
    }
    $newPassword = "";
    $confirmPassword = "";
    $pending = $_SESSION["profile_pending"] ?? null;
    $passwordPending = $_SESSION["profile_password_pending"] ?? null;

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        verify_csrf_token($_POST["csrf_token"] ?? "");
        $action = (string) ($_POST["action"] ?? "save");

        if ($action === "password_send_code") {
            $newPassword = (string) ($_POST["new_password"] ?? "");
            $confirmPassword = (string) ($_POST["confirm_password"] ?? "");
            if (
                strlen($newPassword) < 8 ||
                !preg_match("/[A-Z]/", $newPassword) ||
                !preg_match("/[0-9]/", $newPassword)
            ) {
                $errors[] =
                    "New password must be at least 8 characters and include one uppercase letter and one number.";
            } elseif ($newPassword !== $confirmPassword) {
                $errors[] = "The new password confirmation does not match.";
            } elseif (
                filter_var(
                    (string) $profile["email"],
                    FILTER_VALIDATE_EMAIL,
                ) === false
            ) {
                $errors[] =
                    "Your current email address is not valid. Please contact the barangay office.";
            } else {
                $code = (string) random_int(100000, 999999);
                $passwordPending = [
                    "new_password" => $newPassword,
                    "code_hash" => password_hash($code, PASSWORD_DEFAULT),
                    "expires_at" => time() + 600,
                ];
                $sent = sendEmail(
                    (string) $profile["email"],
                    "Password change verification code",
                    '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#211a1c">'
                        . '<div style="padding:24px;background:#8e0710;color:white;text-align:center">'
                        . "<strong>BARANGAY INOSLUBAN</strong></div>"
                        . '<div style="padding:28px"><h2>Confirm your password change</h2>'
                        . "<p>Use this verification code to confirm your password change:</p>"
                        . '<p style="font-size:32px;letter-spacing:8px;font-weight:bold;color:#8e0710">'
                        . e($code)
                        . "</p>"
                        . "<p>This code expires in 10 minutes. If you did not request this, secure your account immediately.</p>"
                        . "</div></div>",
                );
                if ($sent) {
                    $_SESSION["profile_password_pending"] = $passwordPending;
                    $success =
                        "A password verification code was sent to your current email address.";
                } else {
                    $errors[] =
                        "The verification email could not be sent. Your password was not changed.";
                }
            }
        } elseif ($action === "password_verify") {
            $code = trim((string) ($_POST["password_verification_code"] ?? ""));
            if (
                !$passwordPending ||
                (int) ($passwordPending["expires_at"] ?? 0) < time() ||
                !password_verify(
                    $code,
                    (string) ($passwordPending["code_hash"] ?? ""),
                )
            ) {
                $errors[] =
                    "The password verification code is invalid or expired.";
            } else {
                $accountUpdate = db()->prepare(
                    "UPDATE user_account SET password_hash = :password_hash WHERE resident_id = :resident_id",
                );
                $accountUpdate->execute([
                    "password_hash" => password_hash(
                        (string) $passwordPending["new_password"],
                        PASSWORD_DEFAULT,
                    ),
                    "resident_id" => $residentId,
                ]);
                unset($_SESSION["profile_password_pending"]);
                $_SESSION["profile_success"] =
                    "Your password was changed successfully.";
                header("Location: profile.php");
                exit();
            }
        } elseif ($action === "verify") {
            $code = trim((string) ($_POST["verification_code"] ?? ""));
            if (
                !$pending ||
                !isset($pending["expires_at"], $pending["code_hash"]) ||
                (int) $pending["expires_at"] < time() ||
                !password_verify($code, (string) $pending["code_hash"])
            ) {
                $errors[] = "The verification code is invalid or expired.";
            } else {
                $values = $pending["values"];
                $newPassword = (string) ($pending["new_password"] ?? "");
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $updates = [];
                    $parameters = ["resident_id" => $residentId];
                    foreach ($fields as $field) {
                        $updates[] = "{$field} = :{$field}";
                        $parameters[$field] = $values[$field];
                    }
                    $updates[] =
                        "valid_id_file_path = COALESCE(:valid_id_file_path, valid_id_file_path)";
                    $updates[] =
                        "profile_image_path = COALESCE(:profile_image_path, profile_image_path)";
                    $parameters["valid_id_file_path"] =
                        $pending["valid_id_file_path"] ?? null;
                    $parameters["profile_image_path"] =
                        $pending["profile_image_path"] ?? null;
                    $profileUpdate = $pdo->prepare(
                        "UPDATE resident SET " .
                            implode(", ", $updates) .
                            ", updated_at = CURRENT_TIMESTAMP WHERE resident_id = :resident_id",
                    );
                    $profileUpdate->execute($parameters);
                    if ($newPassword !== "") {
                        $accountUpdate = $pdo->prepare(
                            "UPDATE user_account SET password_hash = :password_hash WHERE resident_id = :resident_id",
                        );
                        $accountUpdate->execute([
                            "password_hash" => password_hash(
                                $newPassword,
                                PASSWORD_DEFAULT,
                            ),
                            "resident_id" => $residentId,
                        ]);
                    }
                    $pdo->commit();
                    $_SESSION["user"]["email"] = $values["email"];
                    $_SESSION["user"]["full_name"] = trim(
                        implode(
                            " ",
                            array_filter([
                                $values["first_name"],
                                $values["middle_name"],
                                $values["last_name"],
                            ]),
                        ),
                    );
                    unset($_SESSION["profile_pending"]);
                    $_SESSION["profile_success"] =
                        "Your profile and security changes were saved successfully.";
                    header("Location: profile.php");
                    exit();
                } catch (Throwable $exception) {
                    $pdo->rollBack();
                    throw $exception;
                }
            }
        } elseif ($action === "send_code") {
            foreach ($fields as $field) {
                $values[$field] = trim((string) ($_POST[$field] ?? ""));
            }
            $emailChanged =
                strtolower($values["email"]) !==
                strtolower((string) $profile["email"]);

            if (!filter_var($values["email"], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Please provide a valid email address.";
            }
            if ($emailChanged && $errors === []) {
                $duplicate = db()->prepare(
                    "SELECT 1 FROM resident WHERE email = :email AND resident_id <> :resident_id LIMIT 1",
                );
                $duplicate->execute([
                    "email" => $values["email"],
                    "resident_id" => $residentId,
                ]);
                if ($duplicate->fetchColumn()) {
                    $errors[] = "That email address is already registered.";
                }
            }
            $profileImagePath = null;
            $validIdPath = null;
            if ($errors === []) {
                try {
                    $profileImagePath = profile_upload(
                        "profile_image",
                        "profile",
                        ["image/jpeg" => "jpg", "image/png" => "png"],
                    );
                    $validIdPath = null;
                } catch (RuntimeException $exception) {
                    $errors[] = $exception->getMessage();
                }
            }
            if (!$emailChanged) {
                $updates = array_map(
                    static fn(string $field): string => "{$field} = :{$field}",
                    $fields,
                );
                $updates[] =
                    "valid_id_file_path = COALESCE(:valid_id_file_path, valid_id_file_path)";
                $updates[] =
                    "profile_image_path = COALESCE(:profile_image_path, profile_image_path)";
                $save = db()->prepare(
                    "UPDATE resident SET " .
                        implode(", ", $updates) .
                        ", updated_at = CURRENT_TIMESTAMP WHERE resident_id = :resident_id",
                );
                $params = ["resident_id" => $residentId];
                foreach ($fields as $field) {
                    $params[$field] = $values[$field];
                }
                $params["valid_id_file_path"] = $validIdPath;
                $params["profile_image_path"] = $profileImagePath;
                if ($errors === []) {
                    $save->execute($params);
                    $_SESSION["user"]["full_name"] = trim(
                        implode(
                            " ",
                            array_filter([
                                $values["first_name"],
                                $values["middle_name"],
                                $values["last_name"],
                            ]),
                        ),
                    );
                    $_SESSION["user"]["email"] = $values["email"];
                    $_SESSION["profile_success"] =
                        "Your profile was saved successfully.";
                    header("Location: profile.php");
                    exit();
                }
            }
            if ($errors === []) {
                $code = (string) random_int(100000, 999999);
                $_SESSION["profile_pending"] = [
                    "values" => $values,
                    "new_password" => "",
                    "profile_image_path" => $profileImagePath,
                    "valid_id_file_path" => $validIdPath,
                    "code_hash" => password_hash($code, PASSWORD_DEFAULT),
                    "expires_at" => time() + 600,
                ];
                $sent = sendEmail(
                    (string) $profile["email"],
                    "Profile security verification code",
                    '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#211a1c">'
                        . '<div style="padding:24px;background:#8e0710;color:white;text-align:center">'
                        . "<strong>BARANGAY INOSLUBAN</strong></div>"
                        . '<div style="padding:28px"><h2>Confirm your profile change</h2>'
                        . "<p>Use this verification code to confirm your email or password change:</p>"
                        . '<p style="font-size:32px;letter-spacing:8px;font-weight:bold;color:#8e0710">'
                        . e($code)
                        . "</p>"
                        . "<p>This code expires in 10 minutes. If you did not request this, secure your account immediately.</p>"
                        . "</div></div>",
                );
                if ($sent) {
                    $success =
                        "A verification code was sent to your current email address.";
                } else {
                    unset($_SESSION["profile_pending"]);
                    $errors[] =
                        "The verification email could not be sent. No security changes were saved.";
                }
            }
        } else {
            foreach ($fields as $field) {
                $values[$field] = trim((string) ($_POST[$field] ?? ""));
            }
        }
    }
} catch (PDOException $exception) {
    error_log("Profile update database error: " . $exception->getMessage());
    http_response_code(500);
    exit("The profile service is temporarily unavailable.");
}

$pageTitle = "Profile settings";
$nationalIdStatus = $nationalIdVerification["status"] ?? null;
$nationalIdSubmissionBlocked = in_array(
    $nationalIdStatus,
    ["PROCESSING", "PRE_VERIFIED", "PENDING_STAFF_REVIEW", "STAFF_APPROVED"],
    true,
);
$idVerificationStatus = "Not submitted";
$idVerificationClass = "profile-status-missing";
$idVerificationHint = "Submit a valid government ID to begin validation.";
if ($nationalIdStatus === "PROCESSING") {
    $idVerificationStatus = "Processing";
    $idVerificationClass = "profile-status-pending";
    $idVerificationHint = "Your local pre-verification is being processed.";
} elseif ($nationalIdStatus === "PRE_VERIFIED") {
    $idVerificationStatus = "Pre-verified";
    $idVerificationClass = "profile-status-preverified";
    $idVerificationHint = "Local pre-check passed; this is not official PSA verification.";
} elseif ($nationalIdStatus === "PENDING_STAFF_REVIEW") {
    $idVerificationStatus = "Pending staff review";
    $idVerificationClass = "profile-status-pending";
    $idVerificationHint = "Your case is waiting for barangay staff review.";
} elseif ($nationalIdStatus === "RETRY_REQUIRED") {
    $idVerificationStatus = "Retry required";
    $idVerificationClass = "profile-status-rejected";
    $idVerificationHint = "Capture clearer photos and resubmit.";
} elseif ($nationalIdStatus === "STAFF_APPROVED") {
    $idVerificationStatus = "Approved by staff";
    $idVerificationClass = "profile-status-approved";
    $idVerificationHint = "Staff approved this local pre-verification. It is not official PSA verification.";
} elseif ($nationalIdStatus === "STAFF_REJECTED") {
    $idVerificationStatus = "Rejected";
    $idVerificationClass = "profile-status-rejected";
    $idVerificationHint = "Staff rejected the submission. Resubmit with clearer, complete photos.";
} elseif ($nationalIdStatus === "OFFICIAL_CHECK_RECORDED") {
    $idVerificationStatus = "Official check recorded";
    $idVerificationClass = "profile-status-approved";
    $idVerificationHint = "An authorized official check is recorded; this portal does not itself perform PSA verification.";
}
$profileGroups = [
    "personal" => [
        "title" => "Personal information",
        "fields" => [
            "first_name",
            "middle_name",
            "last_name",
            "birthdate",
            "place_of_birth",
            "gender",
            "civil_status",
            "nationality",
            "religion",
        ],
    ],
    "contact" => [
        "title" => "Contact information",
        "fields" => [
            "mobile_number",
            "email",
            "emergency_contact_person",
            "emergency_contact_number",
        ],
    ],
    "address" => [
        "title" => "Address and residency",
        "fields" => [
            "complete_address",
            "purok",
            "residency_start_date",
            "voters_status",
            "employment_status",
            "occupation",
            "educational_attainment",
            "blood_type",
            "disability_special_needs",
        ],
    ],
];
require __DIR__ . "/includes/header.php";
?>
<main class="dashboard-page profile-page">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="dashboard.php"><img src="assets/images/logo.png"
                alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA
                    CITY, BATANGAS</small><small>Community Portal</small></span></a>
        <?php require __DIR__ . "/includes/resident_nav.php"; ?>
        <div class="dashboard-sidebar-footer"><img src="assets/images/logo.png"
                alt=""><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY,
                    BATANGAS</small></span></div>
    </aside>
    <section class="dashboard-content profile-content">
        <header class="dashboard-topbar">
            <?php require __DIR__ . "/includes/portal_topbar_brand.php"; ?>
            <div class="dashboard-user">
                <?php require __DIR__ . "/includes/resident_account_menu.php"; ?>
            </div>
        </header>
        <div class="profile-main">
            <div class="profile-heading">
                <p class="eyebrow">ACCOUNT</p>
                <h1>Profile Settings</h1>
                <p>Update your information and manage your account security.</p>
            </div>
            <?php if (
                $errors !== []
            ): ?><div class="alert alert-danger">
                <ul class="mb-0"><?php foreach (
    $errors
    as $error
): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
            </div><?php endif; ?>
            <?php if (
                $success !== ""
            ): ?><div class="alert alert-success"><?= e(
    $success,
) ?></div><?php endif; ?>
            <?php if ($pending): ?>
            <section class="profile-card verification-card">
                <div class="profile-card-title"><i class="bi bi-shield-lock"></i>
                    <div>
                        <h2>Confirm sensitive changes</h2>
                        <p>Enter the six-digit code sent to your current email address.</p>
                    </div>
                </div>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= e(
                    csrf_token(),
                ) ?>"><label class="form-label" for="verification_code">Verification
                        code</label><input class="form-control profile-input verification-input"
                        id="verification_code" name="verification_code" inputmode="numeric"
                        pattern="[0-9]{6}" maxlength="6" required><button
                        class="btn btn-primary mt-3" name="action" value="verify">Verify and save
                        changes</button></form>
            </section>
            <?php endif; ?>
            <div class="profile-layout">
                <section class="profile-card profile-form-card">
                    <form method="post" enctype="multipart/form-data"><input type="hidden"
                            name="csrf_token" value="<?= e(
                    csrf_token(),
                ) ?>">
                        <nav class="profile-tabs" aria-label="Profile sections">
                            <button class="profile-tab active" type="button"
                                data-profile-tab="personal" aria-selected="true"><i
                                    class="bi bi-person"></i><span>Personal Info</span></button>
                            <button class="profile-tab" type="button" data-profile-tab="contact"
                                aria-selected="false"><i class="bi bi-telephone"></i><span>Contact
                                    Info</span></button>
                            <button class="profile-tab" type="button" data-profile-tab="address"
                                aria-selected="false"><i
                                    class="bi bi-geo-alt"></i><span>Address</span></button>
                            <button class="profile-tab" type="button" data-profile-tab="account"
                                aria-selected="false"><i
                                    class="bi bi-shield-check"></i><span>Account</span></button>
                        </nav>
                        <?php foreach ($profileGroups as $group): ?>
                        <section class="profile-section profile-panel" data-profile-panel="<?= e(
                            array_search($group, $profileGroups, true),
                        ) ?>" <?= array_search($group, $profileGroups, true) ===
"personal"
    ? ""
    : "hidden" ?>>
                            <div class="profile-section-heading"><i class="bi <?= $group[
    "title"
] === "Personal information"
    ? "bi-person"
    : ($group["title"] === "Contact information"
        ? "bi-telephone"
        : "bi-geo-alt") ?>"></i>
                                <div>
                                    <h2><?= e(
    $group["title"],
) ?></h2>
                                    <p>Keep this information current.</p>
                                </div>
                            </div>
                            <div class="profile-form-grid">
                                <?php foreach ($group["fields"] as $field):
                                $label = ucwords(
                                    str_replace("_", " ", $field),
                                ); ?>
                                <div class="<?= in_array(
                                    $field,
                                    [
                                        "complete_address",
                                        "disability_special_needs",
                                    ],
                                    true,
                                )
                                    ? "profile-wide"
                                    : "" ?>"><label class="form-label" for="<?= e(
    $field,
) ?>"><?= e($label) ?></label><?php if (
    isset($selects[$field])
): ?><select class="form-control profile-input" id="<?= e(
    $field,
) ?>" name="<?= e($field) ?>">
                                        <option value="">Select <?= e(
    $label,
) ?></option><?php foreach (
    $selects[$field]
    as $option
): ?><option value="<?= e($option) ?>" <?= $values[$field] === $option
    ? "selected"
    : "" ?>><?= e(
    $option,
) ?></option><?php endforeach; ?>
                                    </select><?php else: ?><input class="form-control profile-input"
                                        id="<?= e(
    $field,
) ?>" name="<?= e($field) ?>" type="<?= in_array(
    $field,
    ["birthdate", "residency_start_date"],
    true,
)
    ? "date"
    : ($field === "email"
        ? "email"
        : "text") ?>" value="<?= e($values[$field]) ?>"><?php endif; ?></div>
                                <?php
                            endforeach; ?>
                            </div>
                        </section>
                        <?php endforeach; ?>
                        <section class="profile-section profile-panel" data-profile-panel="account"
                            hidden>
                            <div class="profile-subsection">
                                <div class="profile-subsection-heading">
                                    <i class="bi bi-person-circle"></i>
                                    <div>
                                        <h3>Profile photo</h3>
                                        <p>Capture or upload a clear photo for your resident account.</p>
                                    </div>
                                </div>
                                <div class="profile-form-grid">
                                    <div>
                                        <label class="form-label" for="profile_image">Choose profile photo</label>
                                        <input class="form-control profile-input" id="profile_image"
                                            name="profile_image" type="file" accept="image/jpeg,image/png" capture="user">
                                        <div class="profile-camera-actions">
                                            <button class="btn btn-outline-primary btn-sm" type="button"
                                                data-profile-camera data-profile-camera-target="profile_image"
                                                data-profile-camera-facing="user" data-profile-camera-shape="face">
                                                <i class="bi bi-camera me-1"></i>Use camera
                                            </button>
                                            <img class="profile-camera-thumbnail" data-profile-camera-thumbnail="profile_image"
                                                alt="Captured profile photo preview" hidden>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="profile-subsection">
                                <div class="profile-subsection-heading">
                                    <i class="bi bi-shield-lock-fill"></i>
                                    <div>
                                        <h3>Password reset</h3>
                                        <p>Change your password through email verification.</p>
                                    </div>
                                </div>
                                <button class="btn btn-outline-primary" type="button" data-password-modal-open>
                                    <i class="bi bi-key me-2"></i>Change password
                                </button>
                            </div>
                            <div class="profile-subsection national-id-profile-section">
                                <div class="profile-subsection-heading">
                                    <i class="bi bi-person-vcard-fill"></i>
                                    <div>
                                        <h3>National ID local pre-verification</h3>
                                        <p>This is a local pre-check, not official PSA, PhilSys, or NIDAS verification.
                                    Only the front and back of your ID are used; no selfie or face comparison is performed.
                                    Uncertain QR or text checks may still require staff review.</p>
                                    </div>
                                </div>
                                <?php
                                $trackerSubmitted = $nationalIdStatus !== null;
                                $trackerProcessed = in_array($nationalIdStatus, [
                                    "PRE_VERIFIED",
                                    "PENDING_STAFF_REVIEW",
                                    "RETRY_REQUIRED",
                                    "STAFF_APPROVED",
                                    "STAFF_REJECTED",
                                    "OFFICIAL_CHECK_RECORDED",
                                ], true);
                                $trackerAwaitingStaff = $nationalIdStatus === "PENDING_STAFF_REVIEW";
                                $trackerNeedsAction = in_array($nationalIdStatus, [
                                    "RETRY_REQUIRED",
                                    "STAFF_REJECTED",
                                ], true);
                                $trackerFinished = in_array($nationalIdStatus, [
                                    "PRE_VERIFIED",
                                    "STAFF_APPROVED",
                                    "STAFF_REJECTED",
                                    "OFFICIAL_CHECK_RECORDED",
                                ], true);
                                $trackerStep1Class = $trackerSubmitted ? "is-complete" : "";
                                $trackerStep2Class = $trackerProcessed
                                    ? "is-complete"
                                    : ($nationalIdStatus === "PROCESSING" ? "is-active" : "");
                                $trackerStep3Class = $trackerFinished
                                    ? "is-complete"
                                    : (in_array($nationalIdStatus, [
                                        "PENDING_STAFF_REVIEW",
                                        "RETRY_REQUIRED",
                                        "STAFF_REJECTED",
                                    ], true) ? "is-active" : "");
                                ?>
                                <?php if ($trackerSubmitted): ?>
                                <div class="national-id-tracker" aria-label="National ID verification progress">
                                    <div class="national-id-tracker-step <?= e($trackerStep1Class) ?>">
                                        <span><i class="bi <?= $trackerSubmitted ? "bi-check-lg" : "bi-circle" ?>"></i></span>
                                        <div><strong>Submitted</strong><small><?= e(
                                            $nationalIdVerification["submitted_at"]
                                                ? format_app_datetime(
                                                    (string) $nationalIdVerification["submitted_at"],
                                                    "M j, Y · g:i A",
                                                )
                                                : "Waiting",
                                        ) ?></small></div>
                                    </div>
                                    <div class="national-id-tracker-step <?= e($trackerStep2Class) ?>">
                                        <span><i class="bi <?= $trackerProcessed ? "bi-check-lg" : ($nationalIdStatus === "PROCESSING" ? "bi-hourglass-split" : "bi-circle") ?>"></i></span>
                                        <div><strong>Local pre-check</strong><small><?=
                                            $trackerProcessed
                                                ? "Processing complete"
                                                : ($nationalIdStatus === "PROCESSING" ? "Processing now" : "Not started")
                                        ?></small></div>
                                    </div>
                                    <div class="national-id-tracker-step <?= e($trackerStep3Class) ?>">
                                        <span><i class="bi <?= $trackerFinished ? "bi-check-lg" : (in_array($nationalIdStatus, ["PENDING_STAFF_REVIEW", "RETRY_REQUIRED", "STAFF_REJECTED"], true) ? "bi-hourglass-split" : "bi-circle") ?>"></i></span>
                                        <div><strong><?= $trackerAwaitingStaff ? "Staff review" : ($trackerNeedsAction ? "Action required" : "Final result") ?></strong><small><?=
                                            $trackerFinished
                                                ? e($idVerificationStatus)
                                                : ($trackerAwaitingStaff
                                                    ? "Awaiting barangay staff"
                                                    : ($trackerNeedsAction ? "Resubmit clearer photos" : "Waiting for submission"))
                                        ?></small></div>
                                    </div>
                                </div>
                                <?php if ($nationalIdVerification["reviewed_at"]): ?>
                                <p class="national-id-review-time">
                                    Final review: <?= e(format_app_datetime(
                                        (string) $nationalIdVerification["reviewed_at"],
                                        "M j, Y · g:i A",
                                    )) ?>
                                </p>
                                <?php endif; ?>
                                <?php if ($nationalIdStatus === "STAFF_REJECTED" && $nationalIdVerification["staff_reason"]): ?>
                                <p class="national-id-review-reason"><strong>Staff reason:</strong> <?= e(
                                    (string) $nationalIdVerification["staff_reason"],
                                ) ?></p>
                                <?php endif; ?>
                                <?php else: ?>
                                <div class="national-id-not-submitted">
                                    <i class="bi bi-person-vcard"></i>
                                    <span><strong>No National ID submitted</strong><small>Complete the captures below to start your ID process.</small></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($nationalIdSubmissionBlocked): ?>
                                    <div class="alert <?= in_array($nationalIdStatus, ["PRE_VERIFIED", "STAFF_APPROVED"], true)
                                        ? "alert-success"
                                        : "alert-info" ?> py-2" role="status">
                                        <strong><?= e($idVerificationStatus) ?>.</strong>
                                        <?= e($idVerificationHint) ?>
                                    </div>
                                <?php else: ?>
                                <div id="national-id-profile-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label" for="national_id_format_profile">ID format</label>
                                            <select class="form-select" id="national_id_format_profile" name="id_format" required>
                                                <option value="">Select format</option>
                                                <option value="PhilID">PhilID (physical PVC)</option>
                                                <option value="ePhilID">ePhilID (printed)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="profile-form-grid mt-3">
                                        <?php foreach ([
                                            "front" => ["Front of ID", "environment", "card"],
                                            "back" => ["Back with QR", "environment", "card"],
                                        ] as $captureName => $capture): ?>
                                        <div>
                                            <label class="form-label" for="national_<?= e($captureName) ?>_image"><?= e($capture[0]) ?></label>
                                            <input class="form-control profile-input" type="file"
                                                id="national_<?= e($captureName) ?>_image"
                                                name="<?= e($captureName) ?>_image"
                                                accept="image/jpeg,image/png"
                                                capture="<?= e($capture[1]) ?>">
                                            <div class="profile-camera-actions">
                                                <button class="btn btn-outline-primary btn-sm" type="button"
                                                    data-profile-camera data-profile-camera-target="national_<?= e($captureName) ?>_image"
                                                    data-profile-camera-facing="<?= e($capture[1]) ?>"
                                                    data-profile-camera-shape="<?= e($capture[2]) ?>">
                                                    <i class="bi bi-camera me-1"></i>Capture
                                                </button>
                                                <img class="profile-camera-thumbnail"
                                                    data-profile-camera-thumbnail="national_<?= e($captureName) ?>_image"
                                                    alt="Captured National ID preview" hidden>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="form-check mt-3">
                                        <input class="form-check-input" type="checkbox" id="national_id_consent_profile"
                                            name="national_id_consent" value="accepted" required>
                                        <label class="form-check-label" for="national_id_consent_profile">
                                            I accept <a href="#" data-bs-toggle="modal"
                                                data-bs-target="#nationalIdTermsModal">terms and conditions</a>.
                                        </label>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 mt-3">
                                        <button class="btn btn-primary" type="button" id="national-id-submit">
                                            Submit National ID pre-check
                                        </button>
                                        <span class="small" data-national-id-status role="status"></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </section>
                        <div class="profile-actions"><a class="btn btn-light"
                                href="dashboard.php">Cancel</a><button class="btn btn-primary"
                                name="action" value="<?= $pending
                        ? "save"
                        : "send_code" ?>">Save changes</button></div>
                    </form>
                </section>
                <aside class="profile-card profile-summary"><button class="profile-avatar"
                        type="button" data-profile-photo-trigger aria-label="Change profile photo"><?php if (
                    $profile["profile_image_path"]
                ): ?><img data-profile-photo-preview src="resident_profile_file.php?kind=profile" alt="Profile photo"><?php else: ?><i class="bi bi-person-fill"
                            data-profile-photo-placeholder></i><?php endif; ?><span
                            class="profile-avatar-overlay"><i
                                class="bi bi-camera-fill"></i><small>Change
                                photo</small></span></button>
                    <h2><?= e(
    trim(
        implode(
            " ",
            array_filter([
                $profile["first_name"],
                $profile["middle_name"],
                $profile["last_name"],
            ]),
        ),
    ),
) ?></h2>
                    <p>Resident</p>
                    <div class="profile-completion"><strong>Profile information</strong><span>Keep
                            your information updated for faster services.</span>
                        <div class="profile-check"><i
                                class="bi bi-check-circle-fill"></i><span>Personal
                                information</span></div>
                        <div class="profile-check"><i
                                class="bi bi-check-circle-fill"></i><span>Contact information</span>
                        </div>
                        <div class="profile-check"><i
                                class="bi bi-check-circle-fill"></i><span>Verification
                                documents</span></div>
                        <div class="profile-id-status"><span><i class="bi bi-patch-check-fill"></i>
                                ID verification status</span><strong class="<?= e(
    $idVerificationClass,
) ?>"><?= e($idVerificationStatus) ?></strong><small><?= e($idVerificationHint) ?></small></div>
                    </div>
                </aside>
            </div>
        </div>
        <div class="modal fade auth-modal" id="nationalIdTermsModal" tabindex="-1"
            aria-labelledby="nationalIdTermsModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="nationalIdTermsModalTitle">National ID pre-verification terms and conditions</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>I consent to the private processing of the front and back ID images and QR-extracted details
                            for <?= e(NATIONAL_ID_PRIVACY_PURPOSE) ?> by authorized personnel.</p>
                        <p>Automated checks may be used. Uncertain cases may require staff review. This is a local
                            pre-check and is not official PSA, PhilSys, NIDAS, or cryptographic identity verification.</p>
                        <p>No selfie or face comparison is collected. This process does not confirm that the person
                            submitting the ID is its holder.</p>
                        <p><strong>Retention:</strong> <?= e(NATIONAL_ID_PRIVACY_RETENTION_TEXT) ?>.</p>
                        <p><strong>Contact/DPO:</strong> <?= e(NATIONAL_ID_PRIVACY_CONTACT) ?>.</p>
                        <p>Raw front and back images are submitted temporarily for verification and are not
                            intended to be retained as resident profile uploads.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="profile-modal <?= $passwordPending
            ? "is-open"
            : "" ?>" data-password-modal aria-hidden="<?= $passwordPending
    ? "false"
    : "true" ?>">
            <div class="profile-modal-dialog"><button class="profile-modal-close" type="button"
                    data-password-modal-close aria-label="Close">&times;</button>
                <div class="profile-card-title"><i class="bi bi-shield-lock"></i>
                    <div>
                        <h2>Change password</h2>
                        <p>We will send a verification code to your current email address.</p>
                    </div>
                </div>
                <?php if (
                    $passwordPending
                ): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(
    csrf_token(),
) ?>"><label class="form-label" for="password_verification_code">Verification code</label><input
                        class="form-control profile-input verification-input"
                        id="password_verification_code" name="password_verification_code"
                        inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required><button
                        class="btn btn-primary mt-3" name="action" value="password_verify">Verify
                        and change password</button></form>
                <?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(
    csrf_token(),
) ?>"><label class="form-label" for="modal_new_password">New password</label><input
                        class="form-control profile-input" id="modal_new_password"
                        name="new_password" type="password" required
                        autocomplete="new-password"><label class="form-label mt-3"
                        for="modal_confirm_password">Confirm new password</label><input
                        class="form-control profile-input" id="modal_confirm_password"
                        name="confirm_password" type="password" required
                        autocomplete="new-password"><small class="profile-help">At least 8
                        characters, one uppercase letter, and one number.</small><button
                        class="btn btn-primary mt-3" name="action" value="password_send_code">Send
                        verification code</button></form><?php endif; ?>
            </div>
        </div>
        <?php require __DIR__ . "/includes/resident_dashboard_footer.php"; ?>
    </section>
</main>
<?php $additionalScripts = [
    "assets/js/profile-camera.js?v=" . hash_file(
        "sha256",
        __DIR__ . "/assets/js/profile-camera.js",
    ),
]; ?>
<?php require __DIR__ . "/includes/footer.php"; ?>