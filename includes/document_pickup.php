<?php
declare(strict_types=1);

function ensure_document_pickup_schema(): void
{
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS document_pickup_token (
            token_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            request_id INT NOT NULL UNIQUE REFERENCES document_request(request_id) ON DELETE CASCADE,
            token_hash CHAR(64) NOT NULL UNIQUE,
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );

    $constraint = $pdo->query(
        "SELECT pg_get_constraintdef(oid) FROM pg_constraint
         WHERE conrelid = 'document_request'::regclass
           AND conname = 'document_request_status_check'"
    )->fetchColumn();
    if ($constraint === false || !str_contains((string) $constraint, 'Released')) {
        $pdo->exec(
            "ALTER TABLE document_request
             DROP CONSTRAINT IF EXISTS document_request_status_check"
        );
        $pdo->exec(
            "ALTER TABLE document_request
             ADD CONSTRAINT document_request_status_check
             CHECK (status IN ('Pending', 'Processing', 'Approved', 'Rejected', 'Completed', 'Cancelled', 'Released'))"
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
         RETURNING request_id"
    );
    $statement->execute(['request_id' => $requestId, 'resident_id' => $residentId]);

    return $statement->fetchColumn() !== false;
}

function document_pickup_qr_png(string $url): string
{
    if (!class_exists(\chillerlan\QRCode\QRCode::class)) {
        throw new RuntimeException('The QR code generator is unavailable. Run Composer install.');
    }

    $qr = new \chillerlan\QRCode\QRCode();
    $qr->addByteSegment($url);
    $matrix = $qr->getQRMatrix()->getMatrix(true);
    $moduleScale = 6;
    $rawImage = '';
    foreach ($matrix as $row) {
        $pixelRow = '';
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
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };

    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
        . $chunk('IDAT', gzcompress($rawImage, 9))
        . $chunk('IEND', '');
}

function create_document_pickup_token(int $requestId): array
{
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $verificationUrl = rtrim(APP_BASE_URL, '/') . '/staff_verify_document.php?token=' . rawurlencode($token);
    $png = document_pickup_qr_png($verificationUrl);
    $statement = db()->prepare(
        'INSERT INTO document_pickup_token (request_id, token_hash)
         VALUES (:request_id, :token_hash)
         ON CONFLICT (request_id) DO UPDATE
         SET token_hash = EXCLUDED.token_hash, created_at = CURRENT_TIMESTAMP'
    );
    $statement->execute(['request_id' => $requestId, 'token_hash' => $tokenHash]);

    return [
        'url' => $verificationUrl,
        'png' => $png,
    ];
}
