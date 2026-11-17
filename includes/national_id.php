<?php
declare(strict_types=1);

function ensure_national_id_schema(): void
{
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS national_id_verification (
            verification_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            resident_id INT NOT NULL UNIQUE REFERENCES resident(resident_id)
                ON DELETE CASCADE,
            service_verification_id VARCHAR(128),
            id_format VARCHAR(20) NOT NULL
                CHECK (id_format IN ('PhilID', 'ePhilID')),
            status VARCHAR(30) NOT NULL
                CHECK (status IN (
                    'PROCESSING', 'PRE_VERIFIED', 'PENDING_STAFF_REVIEW',
                    'RETRY_REQUIRED', 'STAFF_APPROVED', 'STAFF_REJECTED',
                    'OFFICIAL_CHECK_RECORDED'
                )),
            masked_pcn VARCHAR(32),
            qr_decoded BOOLEAN NOT NULL DEFAULT FALSE,
            front_pcn_match BOOLEAN,
            front_last_name_match BOOLEAN,
            selfie_quality_pass BOOLEAN,
            face_match_pass BOOLEAN,
            face_metric VARCHAR(40),
            face_score NUMERIC(12,8),
            review_reasons JSONB NOT NULL DEFAULT '[]'::JSONB,
            consented_at TIMESTAMPTZ NOT NULL,
            consent_version VARCHAR(40) NOT NULL,
            submitted_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at TIMESTAMPTZ,
            reviewed_by_user_id INT REFERENCES user_account(user_id)
                ON DELETE SET NULL,
            reviewed_at TIMESTAMPTZ,
            staff_decision VARCHAR(20),
            staff_reason TEXT,
            official_check_source VARCHAR(100),
            official_checked_at TIMESTAMPTZ,
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CHECK (staff_decision IS NULL OR staff_decision IN ('Approved', 'Rejected')),
            CHECK (face_score IS NULL OR face_score >= 0)
        )",
    );
    $pdo->exec(
        "CREATE INDEX IF NOT EXISTS idx_national_id_verification_status
         ON national_id_verification (status, submitted_at DESC)",
    );
    $pdo->exec(
        "CREATE INDEX IF NOT EXISTS idx_national_id_verification_resident
         ON national_id_verification (resident_id)",
    );
}

function national_id_upload_error_message(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL =>
            "National ID images must be complete and within the configured size limit.",
        UPLOAD_ERR_NO_FILE => "Upload all three National ID images.",
        default => "The National ID image upload could not be processed.",
    };
}

function national_id_prepare_upload(
    string $field,
    string $directory,
): string {
    $upload = $_FILES[$field] ?? null;
    if (!is_array($upload) || !isset($upload["error"], $upload["tmp_name"])) {
        throw new RuntimeException("Upload all three National ID images.");
    }
    if ((int) $upload["error"] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            national_id_upload_error_message((int) $upload["error"]),
        );
    }
    $temporaryPath = (string) $upload["tmp_name"];
    if (
        !is_uploaded_file($temporaryPath) ||
        (int) ($upload["size"] ?? 0) <= 0 ||
        (int) $upload["size"] > NATIONAL_ID_MAX_IMAGE_BYTES
    ) {
        throw new RuntimeException("National ID images exceed the configured size limit.");
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    if (!in_array($mime, ["image/jpeg", "image/png"], true)) {
        throw new RuntimeException("National ID images must be JPG or PNG files.");
    }
    $dimensions = @getimagesize($temporaryPath);
    if (
        !is_array($dimensions) ||
        ($dimensions[0] ?? 0) < 160 ||
        ($dimensions[1] ?? 0) < 120 ||
        ($dimensions[0] * $dimensions[1]) > NATIONAL_ID_MAX_PIXELS
    ) {
        throw new RuntimeException("A National ID image has unsupported dimensions.");
    }

    $target = $directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(24));
    if (!copy($temporaryPath, $target) || !chmod($target, 0600)) {
        throw new RuntimeException("National ID images could not be prepared securely.");
    }
    return $target;
}

function national_id_service_result(array $result): array
{
    $statuses = [
        "PRE_VERIFIED",
        "PENDING_STAFF_REVIEW",
        "RETRY_REQUIRED",
    ];
    if (
        ($result["ok"] ?? null) !== true ||
        !is_string($result["status"] ?? null) ||
        !in_array($result["status"], $statuses, true) ||
        !is_string($result["verification_id"] ?? null) ||
        !is_array($result["checks"] ?? null)
    ) {
        throw new RuntimeException("The National ID service returned an invalid response.");
    }

    $checks = $result["checks"];
    foreach (
        [
            "qr_decoded",
            "front_pcn_match",
            "front_last_name_match",
            "selfie_one_face",
            "selfie_quality_pass",
            "face_match_pass",
        ]
        as $check
    ) {
        if (
            array_key_exists($check, $checks) &&
            $checks[$check] !== null &&
            !is_bool($checks[$check])
        ) {
            throw new RuntimeException("The National ID service returned invalid checks.");
        }
    }
    if (
        isset($result["review_reasons"]) &&
        (!is_array($result["review_reasons"]) ||
            count($result["review_reasons"]) > 20)
    ) {
        throw new RuntimeException("The National ID service returned invalid review reasons.");
    }
    return $result;
}

function national_id_verify_uploads(): array
{
    if (!function_exists("curl_init")) {
        throw new RuntimeException("National ID verification is unavailable.");
    }
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "inosluban-national-id";
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
        throw new RuntimeException("National ID verification storage is unavailable.");
    }
    if (!chmod($directory, 0700)) {
        throw new RuntimeException("National ID verification storage is not private.");
    }

    $paths = [];
    try {
        foreach (["front_image", "back_image", "selfie_image"] as $field) {
            $paths[$field] = national_id_prepare_upload($field, $directory);
        }
        $postFields = [];
        foreach ($paths as $field => $path) {
            $postFields[$field] = new CURLFile($path, "image/*", $field . ".jpg");
        }
        $headers = ["Accept: application/json"];
        if (NATIONAL_ID_SERVICE_TOKEN !== "") {
            $headers[] = "Authorization: Bearer " . NATIONAL_ID_SERVICE_TOKEN;
        }
        $curl = curl_init(NATIONAL_ID_SERVICE_URL . "/verify-id");
        if ($curl === false) {
            throw new RuntimeException("National ID verification is unavailable.");
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, NATIONAL_ID_SERVICE_TIMEOUT),
            CURLOPT_TIMEOUT => NATIONAL_ID_SERVICE_TIMEOUT,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        if (!is_string($body) || strlen($body) > 200_000) {
            throw new RuntimeException("National ID verification returned an invalid response.");
        }
        if ($body === "" || $httpCode < 200 || $httpCode >= 300) {
            error_log(
                "National ID service request failed (" .
                    $httpCode .
                    "): " .
                    substr($curlError, 0, 200),
            );
            throw new RuntimeException(
                "National ID verification is temporarily unavailable. Please retry.",
            );
        }
        try {
            $result = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "National ID verification returned invalid data.",
                0,
                $exception,
            );
        }
        return national_id_service_result($result);
    } finally {
        foreach ($paths as $path) {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }
}

function national_id_result_for_storage(array $result): array
{
    $checks = $result["checks"];
    return [
        "service_verification_id" => $result["verification_id"],
        "status" => $result["status"],
        "masked_pcn" => $result["masked_pcn"] ?? null,
        "qr_decoded" => $checks["qr_decoded"] ?? false,
        "front_pcn_match" => $checks["front_pcn_match"] ?? null,
        "front_last_name_match" => $checks["front_last_name_match"] ?? null,
        "selfie_quality_pass" => $checks["selfie_quality_pass"] ?? null,
        "face_match_pass" => $checks["face_match_pass"] ?? null,
        "face_metric" => $result["face_metric"] ?? null,
        "face_score" => $result["face_score"] ?? null,
        "review_reasons" => $result["review_reasons"] ?? [],
        "extracted" => $result["extracted"] ?? [],
    ];
}
