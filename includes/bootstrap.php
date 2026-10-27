<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (DB_HOST === '' || DB_NAME === '' || DB_USER === '' || DB_PASSWORD === '') {
        throw new RuntimeException('Database configuration is incomplete. Set DATABASE_URL or all DB_* values in the Render secret file or service environment.');
    }

    $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";sslmode=require";

    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(string $token): void
{
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Your form session expired. Please go back and try again.');
    }
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']['id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function require_resident(): void
{
    require_login();

    if (($_SESSION['user']['role'] ?? null) !== 'resident') {
        http_response_code(403);
        exit('This service is available to resident accounts only.');
    }
}

function require_staff(): void
{
    require_login();

    if (!in_array($_SESSION['user']['role'] ?? null, ['staff', 'admin'], true)) {
        http_response_code(403);
        exit('This area is available to staff accounts only.');
    }
}
