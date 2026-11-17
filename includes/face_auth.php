<?php
declare(strict_types=1);

function ensure_face_auth_schema(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS face_auth_credential (
            user_id INT PRIMARY KEY REFERENCES user_account(user_id) ON DELETE CASCADE,
            encrypted_template TEXT NOT NULL,
            nonce TEXT NOT NULL,
            auth_tag TEXT NOT NULL,
            consented_at TIMESTAMPTZ NOT NULL,
            enrolled_by INT REFERENCES user_account(user_id) ON DELETE SET NULL,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS face_auth_attempt (
            attempt_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            user_id INT NOT NULL REFERENCES user_account(user_id) ON DELETE CASCADE,
            succeeded BOOLEAN NOT NULL,
            attempted_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS face_auth_setup (
            setup_id SMALLINT PRIMARY KEY CHECK (setup_id = 1),
            completed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
    );
    db()->exec(
        "CREATE INDEX IF NOT EXISTS idx_face_auth_attempt_user_date
         ON face_auth_attempt (user_id, attempted_at DESC)",
    );
}

function face_auth_is_enrolled(int $userId): bool
{
    $query = db()->prepare(
        "SELECT EXISTS (
            SELECT 1 FROM face_auth_credential WHERE user_id = :user_id
        )",
    );
    $query->execute(["user_id" => $userId]);

    return in_array($query->fetchColumn(), [true, 1, "1", "t", "true"], true);
}

function face_auth_initial_setup_available(): bool
{
    $query = db()->query(
        "SELECT NOT EXISTS (
            SELECT 1 FROM face_auth_setup WHERE setup_id = 1
        ) AND NOT EXISTS (
            SELECT 1
            FROM face_auth_credential credential
            JOIN user_account account ON account.user_id = credential.user_id
            WHERE account.role = 'admin' AND account.account_status = 'active'
        )",
    );

    return in_array($query->fetchColumn(), [true, 1, "1", "t", "true"], true);
}

function is_face_verified(): bool
{
    $userId = filter_var(
        $_SESSION["user"]["id"] ?? null,
        FILTER_VALIDATE_INT,
    );
    $verifiedAt = filter_var(
        $_SESSION["face_verified_at"] ?? null,
        FILTER_VALIDATE_INT,
    );

    return $userId !== false &&
        $userId !== null &&
        (int) ($_SESSION["face_verified_user_id"] ?? 0) === $userId &&
        $verifiedAt !== false &&
        $verifiedAt !== null &&
        $verifiedAt <= time() &&
        $verifiedAt >= time() - 12 * 60 * 60;
}

function require_face_verified(): void
{
    if (!is_logged_in()) {
        require_login();
    }

    if (is_face_verified()) {
        return;
    }

    unset(
        $_SESSION["face_verified_at"],
        $_SESSION["face_verified_user_id"],
        $_SESSION["staff_time_log_id"],
    );

    header("Location: face_verify.php");
    exit();
}

function face_auth_encryption_key(): string
{
    $key = base64_decode(FACE_ENCRYPTION_KEY, true);
    if (!is_string($key) || strlen($key) !== 32) {
        throw new RuntimeException(
            "FACE_ENCRYPTION_KEY must be a base64-encoded 32-byte key.",
        );
    }

    return $key;
}

function face_auth_is_encryption_configured(): bool
{
    $key = base64_decode(FACE_ENCRYPTION_KEY, true);

    return is_string($key) && strlen($key) === 32;
}

function face_auth_encrypt_template(string $template): array
{
    $nonce = random_bytes(12);
    $tag = "";
    $ciphertext = openssl_encrypt(
        $template,
        "aes-256-gcm",
        face_auth_encryption_key(),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        "face-template-v1",
        16,
    );
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException("The face template could not be encrypted.");
    }

    return [
        "encrypted_template" => base64_encode($ciphertext),
        "nonce" => base64_encode($nonce),
        "auth_tag" => base64_encode($tag),
    ];
}

function face_auth_decrypt_template(
    string $ciphertext,
    string $nonce,
    string $tag,
): string {
    $decodedCiphertext = base64_decode($ciphertext, true);
    $decodedNonce = base64_decode($nonce, true);
    $decodedTag = base64_decode($tag, true);
    if (
        !is_string($decodedCiphertext) ||
        !is_string($decodedNonce) ||
        strlen($decodedNonce) !== 12 ||
        !is_string($decodedTag) ||
        strlen($decodedTag) !== 16
    ) {
        throw new RuntimeException("The stored face template is invalid.");
    }

    $template = openssl_decrypt(
        $decodedCiphertext,
        "aes-256-gcm",
        face_auth_encryption_key(),
        OPENSSL_RAW_DATA,
        $decodedNonce,
        $decodedTag,
        "face-template-v1",
    );
    if (!is_string($template)) {
        throw new RuntimeException(
            "The face template could not be decrypted. Check FACE_ENCRYPTION_KEY.",
        );
    }

    return $template;
}

function face_auth_run_verifier(array $payload): array
{
    $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . "face_verifier.py";
    if (!is_file($script)) {
        throw new RuntimeException("The face verification service is missing.");
    }

    $process = proc_open(
        [FACE_VERIFIER_COMMAND, $script],
        [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ],
        $pipes,
        dirname(__DIR__),
    );
    if (!is_resource($process)) {
        throw new RuntimeException("The face verification service did not start.");
    }

    fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if (!is_string($output) || strlen($output) > 1024 * 1024) {
        throw new RuntimeException("The face verification service returned invalid output.");
    }
    if ($exitCode !== 0) {
        error_log(
            "Face verifier failed (exit {$exitCode}): " .
                substr((string) $errorOutput, 0, 1000),
        );
        throw new RuntimeException("The face verification service failed.");
    }
    $result = json_decode($output, true);
    if (!is_array($result) || !array_key_exists("ok", $result)) {
        throw new RuntimeException("The face verification service returned an invalid response.");
    }

    return $result;
}

function face_auth_record_attempt(int $userId, bool $succeeded): void
{
    if ($succeeded) {
        db()->prepare(
            "DELETE FROM face_auth_attempt
             WHERE user_id = :user_id AND succeeded = FALSE",
        )->execute(["user_id" => $userId]);
    }

    $query = db()->prepare(
        "INSERT INTO face_auth_attempt (user_id, succeeded)
         VALUES (:user_id, " .
            ($succeeded ? "TRUE" : "FALSE") .
            ")",
    );
    $query->execute(["user_id" => $userId]);

    db()->prepare(
        "DELETE FROM face_auth_attempt
         WHERE user_id = :user_id
           AND attempted_at < CURRENT_TIMESTAMP - INTERVAL '1 day'",
    )->execute(["user_id" => $userId]);
}

function face_auth_is_rate_limited(int $userId): bool
{
    $query = db()->prepare(
        "SELECT COUNT(*)
         FROM face_auth_attempt
         WHERE user_id = :user_id
           AND succeeded = FALSE
           AND attempted_at > CURRENT_TIMESTAMP - INTERVAL '15 minutes'",
    );
    $query->execute(["user_id" => $userId]);

    return (int) $query->fetchColumn() >= 5;
}

function face_auth_decode_frames(mixed $frames): array
{
    if (!is_array($frames) || count($frames) !== 5) {
        throw new RuntimeException("Capture all five camera steps and try again.");
    }

    $decodedFrames = [];
    foreach ($frames as $frame) {
        if (
            !is_string($frame) ||
            strlen($frame) > 200_000 ||
            !preg_match(
                '/^data:image\/jpeg;base64,([A-Za-z0-9+\/=]+)$/',
                $frame,
                $matches,
            )
        ) {
            throw new RuntimeException("A camera image was missing or invalid.");
        }

        $image = base64_decode($matches[1], true);
        if (!is_string($image) || strlen($image) > 150_000) {
            throw new RuntimeException("A camera image is too large.");
        }
        $decodedFrames[] = base64_encode($image);
    }

    return $decodedFrames;
}

function face_auth_challenge(): array
{
    if (
        !isset($_SESSION["face_challenge"]) ||
        !is_array($_SESSION["face_challenge"]) ||
        !isset(
            $_SESSION["face_challenge"]["id"],
            $_SESSION["face_challenge"]["turns"],
            $_SESSION["face_challenge"]["created_at"],
        ) ||
        (int) $_SESSION["face_challenge"]["created_at"] < time() - 300
    ) {
        $turns = random_int(0, 1) === 0
            ? ["left", "right"]
            : ["right", "left"];
        $_SESSION["face_challenge"] = [
            "id" => bin2hex(random_bytes(16)),
            "turns" => $turns,
            "created_at" => time(),
        ];
    }

    return $_SESSION["face_challenge"];
}

function face_auth_json_response(array $body, int $status = 200): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    if (ob_get_level() > 0) {
        ob_clean();
    }
    echo json_encode($body, JSON_THROW_ON_ERROR);
    exit();
}

function face_auth_read_json_request(): array
{
    $raw = file_get_contents("php://input");
    if (!is_string($raw) || strlen($raw) > 2_000_000) {
        face_auth_json_response(
            ["ok" => false, "message" => "The camera request is too large."],
            413,
        );
    }

    try {
        $request = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        face_auth_json_response(
            ["ok" => false, "message" => "The camera request is invalid."],
            400,
        );
    }
    if (!is_array($request)) {
        face_auth_json_response(
            ["ok" => false, "message" => "The camera request is invalid."],
            400,
        );
    }

    return $request;
}

function face_auth_validate_challenge(array $request): array
{
    $challenge = $_SESSION["face_challenge"] ?? null;
    if (
        !is_array($challenge) ||
        !isset($challenge["id"], $challenge["turns"], $challenge["created_at"]) ||
        !is_string($request["challenge_id"] ?? null) ||
        !hash_equals($challenge["id"], $request["challenge_id"]) ||
        !is_array($request["turns"] ?? null) ||
        $request["turns"] !== $challenge["turns"] ||
        (int) $challenge["created_at"] < time() - 300
    ) {
        face_auth_json_response(
            [
                "ok" => false,
                "message" => "This camera challenge expired. Refresh the page and try again.",
            ],
            419,
        );
    }

    return $challenge;
}
