<?php
declare(strict_types=1);

const APP_NAME = "Barangay Inosluban";
const INOSLUBAN_MAP_BOUNDS = [
    "south" => 13.9681559,
    "north" => 13.9881559,
    "west" => 121.1577885,
    "east" => 121.1777885,
];

function load_environment_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    foreach (
        file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        as $line
    ) {
        $line = trim($line);
        if (
            $line === "" ||
            str_starts_with($line, "#") ||
            !str_contains($line, "=")
        ) {
            continue;
        }
        [$name, $value] = explode("=", $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === "" || getenv($name) !== false) {
            continue;
        }
        if (
            strlen($value) >= 2 &&
            (($value[0] === '"' && substr($value, -1) === '"') ||
                ($value[0] === "'" && substr($value, -1) === "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        putenv($name . "=" . $value);
    }
}

load_environment_file("/etc/secrets/.env");
load_environment_file(dirname(__DIR__) . "/.env");

define("APP_TIMEZONE", getenv("APP_TIMEZONE") ?: "Asia/Manila");
date_default_timezone_set(APP_TIMEZONE);

$databaseUrlParts = [];
$databaseUrl = getenv("DATABASE_URL");
if ($databaseUrl !== false && $databaseUrl !== "") {
    try {
        $databaseUrlParts = parse_url($databaseUrl);
    } catch (ValueError $error) {
        throw new RuntimeException(
            "DATABASE_URL is not a valid PostgreSQL connection URL.",
            0,
            $error,
        );
    }

    if (
        !is_array($databaseUrlParts) ||
        !in_array(
            strtolower($databaseUrlParts["scheme"] ?? ""),
            ["postgres", "postgresql"],
            true,
        )
    ) {
        throw new RuntimeException(
            "DATABASE_URL must be a valid PostgreSQL connection URL.",
        );
    }
}

define("DB_HOST", getenv("DB_HOST") ?: $databaseUrlParts["host"] ?? "");
define(
    "DB_PORT",
    getenv("DB_PORT") ?: (string) ($databaseUrlParts["port"] ?? 5432),
);
define(
    "DB_NAME",
    getenv("DB_NAME") ?:
    rawurldecode(ltrim($databaseUrlParts["path"] ?? "", "/")),
);
define(
    "DB_USER",
    getenv("DB_USER") ?: rawurldecode($databaseUrlParts["user"] ?? ""),
);
define(
    "DB_PASSWORD",
    getenv("DB_PASSWORD") ?: rawurldecode($databaseUrlParts["pass"] ?? ""),
);

$appBaseUrl = rtrim(
    getenv("APP_BASE_URL") ?: "https://inosluban.onrender.com",
    "/",
);
if (str_ends_with($appBaseUrl, "/index.php")) {
    $appBaseUrl = substr($appBaseUrl, 0, -strlen("/index.php"));
}
define("APP_BASE_URL", $appBaseUrl);

define("SMTP_HOST", getenv("SMTP_HOST") ?: "smtp.gmail.com");
define("SMTP_PORT", (int) (getenv("SMTP_PORT") ?: 587));
define("SMTP_USERNAME", getenv("SMTP_USERNAME") ?: "");
define("SMTP_PASSWORD", getenv("SMTP_PASSWORD") ?: "");
define("MAIL_FROM", getenv("MAIL_FROM") ?: SMTP_USERNAME);
define("MAIL_FROM_NAME", getenv("MAIL_FROM_NAME") ?: APP_NAME);
define("GEMINI_API_KEY", getenv("GEMINI_API_KEY") ?: "");
define("GEMINI_MODEL", getenv("GEMINI_MODEL") ?: "gemini-2.0-flash");

define("WEATHER_LOCATION", getenv("WEATHER_LOCATION") ?: "Lipa City, Batangas");
define("WEATHER_LATITUDE", (float) (getenv("WEATHER_LATITUDE") ?: "13.9888"));
define("WEATHER_LONGITUDE", (float) (getenv("WEATHER_LONGITUDE") ?: "121.171"));
define("WEATHER_TIMEZONE", getenv("WEATHER_TIMEZONE") ?: APP_TIMEZONE);

define(
    "FACE_VERIFIER_COMMAND",
    getenv("FACE_VERIFIER_COMMAND") ?:
        (PHP_OS_FAMILY === "Windows" ? "python" : "python3"),
);
define("FACE_ENCRYPTION_KEY", getenv("FACE_ENCRYPTION_KEY") ?: "");
define(
    "FACE_ENROLLMENT_SETUP_TOKEN",
    getenv("FACE_ENROLLMENT_SETUP_TOKEN") ?: "",
);
define(
    "FACE_ADMIN_RECOVERY_TOKEN",
    getenv("FACE_ADMIN_RECOVERY_TOKEN") ?: "",
);

define(
    "NATIONAL_ID_SERVICE_URL",
    rtrim(
        getenv("NATIONAL_ID_SERVICE_URL") ?: "http://127.0.0.1:8000",
        "/",
    ),
);
define("NATIONAL_ID_SERVICE_TOKEN", getenv("NATIONAL_ID_SERVICE_TOKEN") ?: "");
define(
    "NATIONAL_ID_SERVICE_TIMEOUT",
    max(3, min(60, (int) (getenv("NATIONAL_ID_SERVICE_TIMEOUT") ?: 30))),
);
define(
    "NATIONAL_ID_MAX_IMAGE_BYTES",
    max(
        500_000,
        min(10_000_000, (int) (getenv("NATIONAL_ID_MAX_IMAGE_BYTES") ?: 5_000_000)),
    ),
);
define(
    "NATIONAL_ID_MAX_PIXELS",
    max(
        1_000_000,
        min(25_000_000, (int) (getenv("NATIONAL_ID_MAX_PIXELS") ?: 12_000_000)),
    ),
);
define(
    "NATIONAL_ID_RETENTION_DAYS",
    max(0, (int) (getenv("NATIONAL_ID_RETENTION_DAYS") ?: 0)),
);
define(
    "NATIONAL_ID_RETENTION_KEY",
    getenv("NATIONAL_ID_RETENTION_KEY") ?: "",
);
define(
    "NATIONAL_ID_PRIVACY_CONTACT",
    getenv("NATIONAL_ID_PRIVACY_CONTACT") ?: "[BARANGAY CONTACT/DPO TO BE CONFIRMED]",
);
define(
    "NATIONAL_ID_PRIVACY_PURPOSE",
    getenv("NATIONAL_ID_PRIVACY_PURPOSE") ?:
        "Barangay resident identity review",
);
define(
    "NATIONAL_ID_PRIVACY_RETENTION_TEXT",
    getenv("NATIONAL_ID_PRIVACY_RETENTION_TEXT") ?:
        "[RETENTION PERIOD REQUIRES BARANGAY/DPO APPROVAL]",
);