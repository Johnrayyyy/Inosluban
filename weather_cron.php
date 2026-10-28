<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/admin.php';

try {
    ensure_admin_schema();
    $lock = db()->query("SELECT pg_try_advisory_lock(hashtext('inosluban-hourly-weather'))")->fetchColumn();
    if (!$lock) {
        fwrite(STDOUT, "Weather refresh already running; skipping this invocation.\n");
        exit(0);
    }

    try {
        $weather = fetch_weather_advisory();
        store_weather_snapshot($weather);
        fwrite(STDOUT, sprintf(
            "Weather snapshot refreshed from %s at %s (%d hourly records, %d forecast days).\n",
            $weather['source_name'],
            $weather['current']['time'],
            count($weather['hourly']),
            count($weather['forecast'])
        ));
    } finally {
        db()->query("SELECT pg_advisory_unlock(hashtext('inosluban-hourly-weather'))");
    }
} catch (Throwable $exception) {
    error_log('Hourly weather refresh failed: ' . $exception->getMessage());
    try {
        record_weather_sync_failure($exception->getMessage());
    } catch (Throwable $storageException) {
        error_log('Unable to store weather refresh failure: ' . $storageException->getMessage());
    }
    fwrite(STDERR, 'Hourly weather refresh failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
