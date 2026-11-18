<?php
declare(strict_types=1);

require_once __DIR__ . "/includes/bootstrap.php";
require_once __DIR__ . "/includes/staff.php";
require_once __DIR__ . "/includes/national_id.php";

require_general_staff_or_admin();
ensure_staff_tables();
ensure_national_id_schema();

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        verify_csrf_token($_POST["csrf_token"] ?? "");
        $verificationId = filter_var(
            $_POST["verification_id"] ?? "",
            FILTER_VALIDATE_INT,
        );
        $decision = (string) ($_POST["decision"] ?? "");
        $reason = trim((string) ($_POST["reason"] ?? ""));
        if (
            !$verificationId ||
            !in_array($decision, ["Approved", "Rejected"], true) ||
            strlen($reason) < 5 ||
            strlen($reason) > 1000
        ) {
            throw new RuntimeException(
                "Choose an approval decision and provide a review reason.",
            );
        }
        $statement = db()->prepare(
            "UPDATE national_id_verification
             SET status = :status,
                 staff_decision = :decision,
                 staff_reason = :reason,
                 reviewed_by_user_id = :reviewed_by,
                 reviewed_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE verification_id = :verification_id
               AND status = 'PENDING_STAFF_REVIEW'",
        );
        $statement->execute([
            "status" =>
                $decision === "Approved"
                    ? "STAFF_APPROVED"
                    : "STAFF_REJECTED",
            "decision" => $decision,
            "reason" => $reason,
            "reviewed_by" => (int) $_SESSION["user"]["id"],
            "verification_id" => $verificationId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException(
                "This verification was already reviewed or no longer exists.",
            );
        }
        staff_audit(
            "NATIONAL_ID_REVIEW_" . strtoupper($decision),
            "national_id_verification",
            $verificationId,
            ["reason" => $reason],
        );
        $message = "National ID review decision saved and recorded.";
    } catch (Throwable $exception) {
        error_log("National ID staff review error: " . $exception->getMessage());
        $error = $exception->getMessage();
    }
}

$statement = db()->query(
    "SELECT v.verification_id, v.resident_id, v.id_format, v.status, v.masked_pcn,
            v.qr_decoded, v.front_pcn_match, v.front_last_name_match,
            v.selfie_quality_pass, v.face_match_pass, v.face_metric, v.face_score,
            v.review_reasons, v.submitted_at,
            r.first_name, r.middle_name, r.last_name, r.birthdate
     FROM national_id_verification v
     JOIN resident r ON r.resident_id = v.resident_id
     WHERE v.status = 'PENDING_STAFF_REVIEW'
     ORDER BY v.submitted_at ASC, v.verification_id ASC",
);
$reviews = $statement->fetchAll();

$pageTitle = "National ID review";
require __DIR__ . "/includes/header.php";
?>
<main class="dashboard-page staff-page">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="staff_dashboard.php"><img src="assets/images/logo.png"
                alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA
                    CITY, BATANGAS</small><small>Staff Portal</small></span></a>
        <?php require __DIR__ . "/includes/staff_nav.php"; ?>
    </aside>
    <section class="dashboard-content">
        <?php require __DIR__ . "/includes/staff_topbar.php"; ?>
        <div class="staff-main">
            <div class="staff-hero compact">
                <div>
                    <p class="eyebrow">IDENTITY REVIEW</p>
                    <h1>National ID pre-verification</h1>
                    <p>Review uncertain local checks. This is not an official PSA verification.</p>
                </div>
            </div>
            <?php if ($message !== ""): ?><div class="alert alert-success"><?= e(
                $message,
            ) ?></div><?php endif; ?>
            <?php if ($error !== ""): ?><div class="alert alert-danger"><?= e(
                $error,
            ) ?></div><?php endif; ?>
            <?php if ($reviews === []): ?>
            <section class="staff-panel p-4">
                <p class="mb-0">No National ID cases are waiting for staff review.</p>
            </section>
            <?php else: ?>
            <div class="national-id-review-list">
                <?php foreach ($reviews as $review): ?>
                <?php
                    $reasons = json_decode(
                        (string) ($review["review_reasons"] ?? "[]"),
                        true,
                    );
                    $reasonText = is_array($reasons)
                        ? implode(", ", array_map("strval", $reasons))
                        : "Review required";
                    ?>
                <section class="staff-panel national-id-review-card">
                    <div class="d-flex justify-content-between gap-3 flex-wrap">
                        <div>
                            <h2 class="h5 mb-1"><?= e(
                                trim(
                                    implode(" ", array_filter([
                                        $review["first_name"],
                                        $review["middle_name"],
                                        $review["last_name"],
                                    ])),
                                ),
                            ) ?></h2>
                            <p class="small mb-0">
                                Resident #<?= (int) $review["resident_id"] ?> ·
                                <?= e($review["id_format"]) ?> ·
                                <?= e(format_app_datetime($review["submitted_at"], "M j, Y g:i A")) ?>
                            </p>
                        </div>
                        <span class="verification-badge">Masked PCN:
                            <?= e((string) ($review["masked_pcn"] ?? "Unavailable")) ?></span>
                    </div>
                    <dl class="row mt-3 mb-3">
                        <dt class="col-sm-5">QR decoded</dt><dd class="col-sm-7"><?= $review[
                            "qr_decoded"
                        ]
                            ? "Pass"
                            : "Review" ?></dd>
                        <dt class="col-sm-5">Front PCN match</dt><dd class="col-sm-7"><?= $review[
                            "front_pcn_match"
                        ] === null
                            ? "Unavailable"
                            : ($review["front_pcn_match"]
                                ? "Pass"
                                : "Mismatch") ?></dd>
                        <dt class="col-sm-5">Last name match</dt><dd class="col-sm-7"><?= $review[
                            "front_last_name_match"
                        ] === null
                            ? "Unavailable"
                            : ($review["front_last_name_match"]
                                ? "Pass"
                                : "Mismatch") ?></dd>
                        <dt class="col-sm-5">Selfie quality (legacy)</dt><dd class="col-sm-7"><?= $review[
                            "selfie_quality_pass"
                        ] === null
                            ? "Not collected"
                            : ($review["selfie_quality_pass"]
                                ? "Pass"
                                : "Review") ?></dd>
                        <dt class="col-sm-5">Face comparison (legacy)</dt><dd class="col-sm-7"><?= $review[
                            "face_match_pass"
                        ] === null
                            ? "Not collected"
                            : ($review["face_match_pass"]
                                ? "Pass"
                                : "Review") ?></dd>
                    </dl>
                    <p class="small"><strong>Review reasons:</strong> <?= e($reasonText) ?></p>
                    <form method="post" class="national-id-review-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="verification_id" value="<?= (int) $review[
                            "verification_id"
                        ] ?>">
                        <label class="form-label" for="reason-<?= (int) $review[
                            "verification_id"
                        ] ?>">Decision reason</label>
                        <textarea class="form-control" required minlength="5" maxlength="1000"
                            id="reason-<?= (int) $review[
                                "verification_id"
                            ] ?>" name="reason" rows="2"></textarea>
                        <div class="d-flex gap-2 mt-2">
                            <button class="btn btn-primary" name="decision" value="Approved"
                                type="submit">Approve</button>
                            <button class="btn btn-outline-danger" name="decision" value="Rejected"
                                type="submit">Reject</button>
                        </div>
                    </form>
                </section>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php require __DIR__ . "/includes/footer.php"; ?>
