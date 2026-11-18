<?php
declare(strict_types=1);

require_once __DIR__ . "/includes/bootstrap.php";
require_once __DIR__ . "/includes/national_id.php";
require_once __DIR__ . "/includes/staff.php";

require_resident();
ensure_national_id_schema();

header("Content-Type: application/json");

$submissionStage = "request_validation";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    header("Allow: POST");
    echo json_encode(["ok" => false, "message" => "POST is required."]);
    exit();
}

try {
    verify_csrf_token($_POST["csrf_token"] ?? "");

    $submissionStage = "resident_lookup";
    $residentId = (int) $_SESSION["user"]["resident_id"];
    $existing = db()->prepare(
        "SELECT status FROM national_id_verification WHERE resident_id = :resident_id",
    );
    $existing->execute(["resident_id" => $residentId]);
    $existingStatus = $existing->fetchColumn();
    if ($existingStatus === "STAFF_APPROVED") {
        http_response_code(409);
        echo json_encode([
            "ok" => false,
            "message" => "This National ID submission is already approved by staff.",
        ]);
        exit();
    }
    if (in_array($existingStatus, ["PROCESSING", "PENDING_STAFF_REVIEW", "PRE_VERIFIED"], true)) {
        http_response_code(409);
        echo json_encode([
            "ok" => false,
            "message" => "Your existing National ID submission is already in progress.",
        ]);
        exit();
    }

    $format = (string) ($_POST["id_format"] ?? "");
    if (!in_array($format, ["PhilID", "ePhilID"], true)) {
        throw new RuntimeException("Choose the correct National ID format.");
    }
    if (($_POST["national_id_consent"] ?? "") !== "accepted") {
        throw new RuntimeException("National ID consent is required.");
    }

    $submissionStage = "verification_service";
    $result = national_id_verify_uploads();
    $stored = national_id_result_for_storage($result);

    $submissionStage = "database_write";
    $pdo = db();
    $pdo->beginTransaction();
    $upsert = db()->prepare(
        "INSERT INTO national_id_verification (
            resident_id, service_verification_id, id_format, status,
            masked_pcn, qr_decoded, front_pcn_match, front_last_name_match,
            selfie_quality_pass, face_match_pass, face_metric, face_score,
            review_reasons, consented_at, consent_version, submitted_at,
            processed_at, updated_at
         ) VALUES (
            :resident_id, :service_verification_id, :id_format, :status,
            :masked_pcn, :qr_decoded, :front_pcn_match, :front_last_name_match,
            :selfie_quality_pass, :face_match_pass, :face_metric, :face_score,
            CAST(:review_reasons AS JSONB), CURRENT_TIMESTAMP, :consent_version,
            CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
         )
         ON CONFLICT (resident_id) DO UPDATE SET
            service_verification_id = EXCLUDED.service_verification_id,
            id_format = EXCLUDED.id_format,
            status = EXCLUDED.status,
            masked_pcn = EXCLUDED.masked_pcn,
            qr_decoded = EXCLUDED.qr_decoded,
            front_pcn_match = EXCLUDED.front_pcn_match,
            front_last_name_match = EXCLUDED.front_last_name_match,
            selfie_quality_pass = EXCLUDED.selfie_quality_pass,
            face_match_pass = EXCLUDED.face_match_pass,
            face_metric = EXCLUDED.face_metric,
            face_score = EXCLUDED.face_score,
            review_reasons = EXCLUDED.review_reasons,
            consented_at = EXCLUDED.consented_at,
            consent_version = EXCLUDED.consent_version,
            submitted_at = EXCLUDED.submitted_at,
            processed_at = EXCLUDED.processed_at,
            reviewed_by_user_id = NULL,
            reviewed_at = NULL,
            staff_decision = NULL,
            staff_reason = NULL,
            updated_at = EXCLUDED.updated_at
         RETURNING verification_id",
    );
    $upsert->execute([
        "resident_id" => $residentId,
        "service_verification_id" => $stored["service_verification_id"],
        "id_format" => $format,
        "status" => $stored["status"],
        "masked_pcn" => $stored["masked_pcn"],
        "qr_decoded" => national_id_database_boolean($stored["qr_decoded"]),
        "front_pcn_match" => national_id_database_boolean($stored["front_pcn_match"]),
        "front_last_name_match" => national_id_database_boolean($stored["front_last_name_match"]),
        "selfie_quality_pass" => $stored["selfie_quality_pass"],
        "face_match_pass" => $stored["face_match_pass"],
        "face_metric" => $stored["face_metric"],
        "face_score" => $stored["face_score"],
        "review_reasons" => json_encode($stored["review_reasons"], JSON_THROW_ON_ERROR),
        "consent_version" => "national-id-v2",
    ]);
    $verificationId = (int) $upsert->fetchColumn();

    $submissionStage = "audit_write";
    staff_audit(
        "NATIONAL_ID_RESIDENT_SUBMITTED",
        "national_id_verification",
        $verificationId,
        ["id_format" => $format, "status" => $stored["status"]],
    );
    $pdo->commit();

    $message = match ($stored["status"]) {
        "PRE_VERIFIED" => "Local pre-verification complete. This is not official PSA verification.",
        "PENDING_STAFF_REVIEW" => "Submitted for staff review. This is a local pre-check, not official verification.",
        "RETRY_REQUIRED" => "Capture clearer photos and resubmit.",
        default => "National ID submission received.",
    };
    echo json_encode(["ok" => true, "status" => $stored["status"], "message" => $message]);
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    $sqlState = $exception instanceof PDOException
        ? (string) ($exception->errorInfo[0] ?? $exception->getCode())
        : "";
    error_log(
        "Resident National ID submission failed at " .
            $submissionStage .
            ": " .
            get_class($exception) .
            ($sqlState !== "" ? " SQLSTATE=" . $sqlState : ""),
    );
    if ($exception instanceof PDOException) {
        http_response_code(500);
        echo json_encode([
            "ok" => false,
            "message" =>
                "The photos reached verification, but the result could not be saved. Please try again shortly.",
        ]);
        exit();
    }

    $message = match ($exception->getMessage()) {
        "Choose the correct National ID format." =>
            "Choose whether your ID is a PhilID or ePhilID.",
        "National ID consent is required." =>
            "Accept the terms and conditions before submitting.",
        "Upload both front and back National ID images." =>
            "Add the front and back-with-QR photos of your National ID.",
        "National ID images must be JPG or PNG files." =>
            "Use JPG or PNG photos for both images.",
        "National ID images exceed the configured size limit." =>
            "Each photo must be 5 MB or less.",
        "A National ID image has unsupported dimensions." =>
            "Each photo must be at least 160 × 120 pixels and not exceed 12 megapixels.",
        "The verification service could not read one or more images. Retake clear photos and try again.",
        "The photos are too large for the server. Choose smaller JPG or PNG images and try again.",
        "National ID verification is temporarily unavailable. Please retry." =>
            "The verification service is unavailable right now. Please try again shortly.",
        default =>
            "The National ID pre-check could not be submitted. Please check the photos and try again.",
    };
    http_response_code(422);
    echo json_encode([
        "ok" => false,
        "message" => $message,
    ]);
}
