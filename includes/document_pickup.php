<?php
declare(strict_types=1);

require_once __DIR__ . "/document_payments.php";

function ensure_document_pickup_schema(): void
{
    ensure_document_payment_schema();
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS document_pickup_token (
            token_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            request_id INT NOT NULL UNIQUE REFERENCES document_request(request_id) ON DELETE CASCADE,
            token_hash CHAR(64) NOT NULL UNIQUE,
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
    );

    $constraint = $pdo
        ->query(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint
         WHERE conrelid = 'document_request'::regclass
           AND conname = 'document_request_status_check'",
        )
        ->fetchColumn();
    if (
        $constraint === false ||
        !str_contains((string) $constraint, "Released")
    ) {
        $pdo->exec(
            "ALTER TABLE document_request
             DROP CONSTRAINT IF EXISTS document_request_status_check",
        );
        $pdo->exec(
            "ALTER TABLE document_request
             ADD CONSTRAINT document_request_status_check
             CHECK (status IN ('Pending', 'Processing', 'Approved', 'Rejected', 'Completed', 'Cancelled', 'Released'))",
        );
    }
}

function cancel_pending_document_request(int $requestId, int $residentId): bool
{
    $statement = db()->prepare(
        "UPDATE document_request
         SET status = 'Cancelled'
         WHERE request_id = :request_id
           AND resident_id = :resident_id
           AND status = 'Pending'
         RETURNING request_id",
    );
    $statement->execute([
        "request_id" => $requestId,
        "resident_id" => $residentId,
    ]);

    return $statement->fetchColumn() !== false;
}

function document_pickup_qr_png(string $url): string
{
    if (!class_exists(\chillerlan\QRCode\QRCode::class)) {
        throw new RuntimeException(
            "The QR code generator is unavailable. Run Composer install.",
        );
    }

    $qr = new \chillerlan\QRCode\QRCode();
    $qr->addByteSegment($url);
    $matrix = $qr->getQRMatrix()->getMatrix(true);
    $moduleScale = 6;
    $rawImage = "";
    foreach ($matrix as $row) {
        $pixelRow = "";
        foreach ($row as $isDark) {
            $pixelRow .= str_repeat($isDark ? "\x00" : "\xff", $moduleScale);
        }
        for ($scaleRow = 0; $scaleRow < $moduleScale; $scaleRow++) {
            $rawImage .= "\x00" . $pixelRow;
        }
    }

    $width = count($matrix[0]) * $moduleScale;
    $height = count($matrix) * $moduleScale;
    $chunk = static function (string $type, string $data): string {
        return pack("N", strlen($data)) .
            $type .
            $data .
            pack("N", crc32($type . $data));
    };

    return "\x89PNG\r\n\x1a\n" .
        $chunk("IHDR", pack("NNCCCCC", $width, $height, 8, 0, 0, 0, 0)) .
        $chunk("IDAT", gzcompress($rawImage, 9)) .
        $chunk("IEND", "");
}

function create_document_pickup_token(int $requestId): array
{
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash("sha256", $token);
    $verificationUrl =
        rtrim(APP_BASE_URL, "/") .
        "/staff_verify_document.php?token=" .
        rawurlencode($token);
    $png = document_pickup_qr_png($verificationUrl);
    $statement = db()->prepare(
        'INSERT INTO document_pickup_token (request_id, token_hash)
         VALUES (:request_id, :token_hash)
         ON CONFLICT (request_id) DO UPDATE
         SET token_hash = EXCLUDED.token_hash, created_at = CURRENT_TIMESTAMP',
    );
    $statement->execute([
        "request_id" => $requestId,
        "token_hash" => $tokenHash,
    ]);

    return [
        "url" => $verificationUrl,
        "png" => $png,
    ];
}

function release_document_pickup(string $token): int
{
    $token = strtolower(trim(rawurldecode($token)));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new RuntimeException("Pickup code is invalid.");
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lookup = $pdo->prepare(
            "SELECT dr.request_id
             FROM document_pickup_token pt
             JOIN document_request dr ON dr.request_id = pt.request_id
             WHERE pt.token_hash = :token_hash AND dr.status = 'Completed'
             FOR UPDATE OF pt, dr",
        );
        $lookup->execute(["token_hash" => hash("sha256", $token)]);
        $requestId = $lookup->fetchColumn();
        if ($requestId === false) {
            throw new RuntimeException(
                "This pickup code is invalid, already used, or no longer active.",
            );
        }

        $release = $pdo->prepare(
            "UPDATE document_request
             SET status = 'Released',
                 payment_status = 'Paid',
                 payment_amount = :payment_amount,
                 paid_at = COALESCE(paid_at, CURRENT_TIMESTAMP)
             WHERE request_id = :request_id AND status = 'Completed'",
        );
        $release->execute([
            "request_id" => $requestId,
            "payment_amount" => DOCUMENT_REQUEST_FEE_PESOS,
        ]);
        if ($release->rowCount() !== 1) {
            throw new RuntimeException(
                "The document could not be released from its current status.",
            );
        }

        $removeToken = $pdo->prepare(
            "DELETE FROM document_pickup_token WHERE request_id = :request_id",
        );
        $removeToken->execute(["request_id" => $requestId]);
        staff_audit(
            "DOCUMENT_RELEASED_BY_PICKUP_QR",
            "document_request",
            (int) $requestId,
        );
        $pdo->commit();

        return (int) $requestId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}