<?php
declare(strict_types=1);

const APP_NAME = 'Barangay Inosluban';


const DB_HOST       = 'localhost';
const DB_PORT       = '5432';
const DB_NAME       = 'Portal_Inosluban';
const DB_USER       = 'postgres';
const DB_PASSWORD   = 'admin';

function load_local_environment(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === '' || getenv($name) !== false) {
            continue;
        }
        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        putenv($name . '=' . $value);
    }
}

load_local_environment(dirname(__DIR__) . '/.env');

define('APP_BASE_URL', getenv('APP_BASE_URL') ?: 'http://localhost/PHP_project');
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 587));
define('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: '');
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: '');
define('MAIL_FROM', getenv('MAIL_FROM') ?: SMTP_USERNAME);
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: APP_NAME);
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-2.0-flash');
define('WEATHER_LOCATION', getenv('WEATHER_LOCATION') ?: 'Lipa City, Batangas');
define('WEATHER_LATITUDE', (float) (getenv('WEATHER_LATITUDE') ?: '13.9888'));
define('WEATHER_LONGITUDE', (float) (getenv('WEATHER_LONGITUDE') ?: '121.171'));
define('WEATHER_TIMEZONE', getenv('WEATHER_TIMEZONE') ?: 'Asia/Manila');


try {
    $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";";

    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}

catch (PDOException $e) {
    die("DAatabase Connection failed: " . $e->getMessage());
}