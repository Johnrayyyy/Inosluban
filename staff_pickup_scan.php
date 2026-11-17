<?php
declare(strict_types=1);

require_once __DIR__ . "/includes/bootstrap.php";
require_once __DIR__ . "/includes/staff.php";
require_once __DIR__ . "/includes/document_pickup.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

$correlationId = bin2hex(random_bytes(8));

function pickup_scan_json(
    bool $ok,
    int $status,
    string $code,
    string $message,
    array $extra = [],
): never {
    http_response_code($status);
    echo json_encode(
        [
            "ok" => $ok,
            "code" => $code,
            "message" => $message,
        ] + $extra,
        JSON_THROW_ON_ERROR,
    );
    exit();
}

function pickup_scan_token(string $value): ?string
{
    $value = trim($value);
    if (preg_match('/^[a-f0-9]{64}$/i', $value)) {
        return strtolower($value);
    }

    $parts = parse_url($value);
    if (
        !is_array($parts) ||
        !isset($parts["path"]) ||
        !preg_match('/\/staff_verify_document\.php$/i', $parts["path"])
    ) {
        return null;
    }
    $query = [];
    parse_str((string) ($parts["query"] ?? ""), $query);
    $token = is_string($query["token"] ?? null) ? trim($query["token"]) : "";

    return preg_match('/^[a-f0-9]{64}$/i', $token)
        ? strtolower($token)
        : null;
}

if (!is_logged_in()) {
    pickup_scan_json(false, 401, "AUTH_REQUIRED", "Please sign in again.");
}
if (
    !in_array($_SESSION["user"]["role"] ?? null, ["staff", "admin"], true)
) {
    pickup_scan_json(
        false,
        403,
        "STAFF_ACCESS_REQUIRED",
        "Staff access is required.",
    );
}
if (!is_face_verified()) {
    pickup_scan_json(
        false,
        403,
        "FACE_VERIFICATION_REQUIRED",
        "Complete staff face verification before scanning.",
    );
}

$phase = "setup";
try {
    ensure_staff_tables();
    if (
        ($_SESSION["user"]["role"] ?? "") === "staff" &&
        current_staff_role() === "BHW"
    ) {
        pickup_scan_json(
            false,
            403,
            "STAFF_ACCESS_REQUIRED",
            "General staff or administrator access is required.",
        );
    }
    ensure_document_pickup_schema();

    $sessionToken = $_SESSION["csrf_token"] ?? "";
    $submittedToken = (string) ($_POST["csrf_token"] ?? "");
    if (
        !is_string($sessionToken) ||
        $sessionToken === "" ||
        !hash_equals($sessionToken, $submittedToken)
    ) {
        pickup_scan_json(
            false,
            419,
            "CSRF_INVALID",
            "Your session expired. Refresh the scanner and try again.",
        );
    }

    $token = pickup_scan_token((string) ($_POST["token"] ?? ""));
    if ($token === null) {
        pickup_scan_json(
            false,
            422,
            "TOKEN_INVALID",
            "This pickup code is invalid or no longer active.",
        );
    }

    $phase = "release";
    $requestId = release_document_pickup($token);
    echo json_encode(
        [
            "ok" => true,
            "code" => "RELEASED",
            "message" => "Pickup verified.",
            "request_id" => $requestId,
            "request_number" =>
                "REQ-" .
                date("Y") .
                "-" .
                str_pad((string) $requestId, 4, "0", STR_PAD_LEFT),
        ],
        JSON_THROW_ON_ERROR,
    );
} catch (Throwable $exception) {
    error_log(
        "Staff pickup QR scan failed; correlation_id=" .
            $correlationId .
            "; type=" .
            get_class($exception),
    );
    if ($phase === "release" && $exception instanceof RuntimeException) {
        pickup_scan_json(
            false,
            422,
            "TOKEN_INVALID",
            "This pickup code is invalid or no longer active.",
        );
    }
    pickup_scan_json(
        false,
        503,
        "SERVICE_UNAVAILABLE",
        "Pickup verification is temporarily unavailable. Please try again.",
    );
}
