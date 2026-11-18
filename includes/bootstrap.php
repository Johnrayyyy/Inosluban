<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/config.php";

// API endpoints must not leak PHP warnings into JSON responses.
ini_set("display_errors", "0");
ini_set("log_errors", "1");
ini_set("session.use_strict_mode", "1");
ini_set("session.use_only_cookies", "1");
ini_set("session.use_trans_sid", "0");
session_set_cookie_params([
    "httponly" => true,
    "secure" => !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off",
    "samesite" => "Lax",
]);
session_start();

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (
        DB_HOST === "" ||
        DB_NAME === "" ||
        DB_USER === "" ||
        DB_PASSWORD === ""
    ) {
        throw new RuntimeException(
            "Database configuration is incomplete. Set DATABASE_URL or all DB_* values in the Render secret file or service environment.",
        );
    }

    $sslMode = in_array(
        strtolower(DB_HOST),
        ["localhost", "127.0.0.1", "::1"],
        true,
    )
        ? "prefer"
        : "require";
    $dsn =
        "pgsql:host=" .
        DB_HOST .
        ";port=" .
        DB_PORT .
        ";dbname=" .
        DB_NAME .
        ";sslmode=" .
        $sslMode;
    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);

    ensure_core_database_schema($pdo);

    return $pdo;
}

function ensure_core_database_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS household_record (
            household_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            address TEXT NOT NULL,
            purok VARCHAR(100) NOT NULL,
            house_number VARCHAR(50),
            household_head_resident_id INT,
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_household_record_purok_house_number
            ON household_record (LOWER(BTRIM(purok)), LOWER(BTRIM(house_number)))
            WHERE NULLIF(BTRIM(house_number), '') IS NOT NULL;",
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS resident (
            resident_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            household_id INT REFERENCES household_record(household_id) ON DELETE SET NULL,
            username VARCHAR(100) NOT NULL UNIQUE,
            first_name VARCHAR(100) NOT NULL,
            middle_name VARCHAR(100),
            last_name VARCHAR(100) NOT NULL,
            birthdate DATE NOT NULL,
            place_of_birth VARCHAR(255),
            gender VARCHAR(30),
            civil_status VARCHAR(50),
            nationality VARCHAR(100) NOT NULL DEFAULT 'Filipino',
            religion VARCHAR(100),
            complete_address TEXT NOT NULL,
            purok VARCHAR(100),
            residency_start_date DATE,
            mobile_number VARCHAR(20),
            email VARCHAR(255),
            voters_status VARCHAR(50),
            employment_status VARCHAR(50),
            occupation VARCHAR(150),
            educational_attainment VARCHAR(100),
            blood_type VARCHAR(10),
            disability_special_needs TEXT,
            emergency_contact_person VARCHAR(255),
            emergency_contact_number VARCHAR(20),
            valid_id_file_path TEXT,
            profile_image_path TEXT,
            residency_status VARCHAR(30) NOT NULL DEFAULT 'Active'
                CHECK (residency_status IN ('Active', 'Inactive', 'Moved', 'Deceased')),
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        );",
    );
    $pdo->exec(
        "DO $$
         BEGIN
             IF NOT EXISTS (
                 SELECT 1
                 FROM pg_constraint
                 WHERE conname = 'fk_household_head'
                   AND conrelid = 'household_record'::regclass
             ) THEN
                 ALTER TABLE household_record
                 ADD CONSTRAINT fk_household_head
                 FOREIGN KEY (household_head_resident_id)
                 REFERENCES resident(resident_id)
                 ON DELETE SET NULL;
             END IF;
         END $$;",
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_account (
            user_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            resident_id INT UNIQUE REFERENCES resident(resident_id) ON DELETE SET NULL,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            profile_image_data BYTEA,
            profile_image_mime VARCHAR(50)
                CHECK (profile_image_mime IS NULL OR profile_image_mime IN ('image/jpeg', 'image/png', 'image/webp')),
            role VARCHAR(30) NOT NULL DEFAULT 'resident'
                CHECK (role IN ('resident', 'staff', 'admin')),
            account_status VARCHAR(30) NOT NULL DEFAULT 'pending'
                CHECK (account_status IN ('pending', 'active', 'disabled', 'locked')),
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_login_at TIMESTAMPTZ
        );",
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS staff (
            staff_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            user_id INT UNIQUE REFERENCES user_account(user_id) ON DELETE SET NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            position VARCHAR(100),
            staff_role VARCHAR(30) NOT NULL DEFAULT 'General Staff'
                CHECK (staff_role IN ('General Staff', 'BHW')),
            employment_status VARCHAR(30) NOT NULL DEFAULT 'Active'
                CHECK (employment_status IN ('Active', 'Inactive', 'Resigned')),
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        );",
    );
    $checked = true;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}

function format_app_datetime(?string $value, string $format): string
{
    $dateTime = new DateTimeImmutable($value ?? "now");

    return $dateTime
        ->setTimezone(new DateTimeZone(APP_TIMEZONE))
        ->format($format);
}

function csrf_token(): string
{
    if (
        !isset($_SESSION["csrf_token"]) ||
        !is_string($_SESSION["csrf_token"]) ||
        strlen($_SESSION["csrf_token"]) !== 64 ||
        !ctype_xdigit($_SESSION["csrf_token"])
    ) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf_token"];
}

function verify_csrf_token(mixed $token): void
{
    $sessionToken = $_SESSION["csrf_token"] ?? null;
    if (
        !is_string($token) ||
        !is_string($sessionToken) ||
        strlen($sessionToken) !== 64 ||
        !ctype_xdigit($sessionToken) ||
        !hash_equals($sessionToken, $token)
    ) {
        http_response_code(419);
        exit("Your form session expired. Please go back and try again.");
    }
}

function is_logged_in(): bool
{
    return isset($_SESSION["user"]["id"]);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header("Location: index.php");
        exit();
    }
}

function require_resident(): void
{
    require_login();

    if (($_SESSION["user"]["role"] ?? null) !== "resident") {
        http_response_code(403);
        exit("This service is available to resident accounts only.");
    }
}

function require_staff(): void
{
    require_login();

    if (
        !in_array($_SESSION["user"]["role"] ?? null, ["staff", "admin"], true)
    ) {
        http_response_code(403);
        exit("This area is available to staff accounts only.");
    }

    require_face_verified();
}

require_once __DIR__ . "/face_auth.php";