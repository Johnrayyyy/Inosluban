<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/admin.php';

try {
    $lock = db()->query("SELECT CASE WHEN pg_try_advisory_lock(hashtext('inosluban-daily-weather-archive')) THEN 1 ELSE 0 END")->fetchColumn();
    if ((int) $lock !== 1) {
        fwrite(STDOUT, "Daily weather archive already running; skipping this invocation.\n");
        exit(0);
    }

    try {
        $day = new DateTimeImmutable('yesterday', new DateTimeZone(WEATHER_TIMEZONE));
        $readings = archive_daily_weather_history($day);
        if ($readings === 0) {
            fwrite(STDOUT, sprintf(
                "No weather observations were recorded for %s; no daily archive was created.\n",
                $day->format('Y-m-d')
            ));
        } else {
            fwrite(STDOUT, sprintf(
                "Archived %d weather observations for %s (%s).\n",
                $readings,
                $day->format('Y-m-d'),
                WEATHER_LOCATION
            ));
        }
    } finally {
        db()->query("SELECT pg_advisory_unlock(hashtext('inosluban-daily-weather-archive'))");
    }
} catch (Throwable $exception) {
    error_log('Daily weather archive failed: ' . $exception->getMessage());
    fwrite(STDERR, 'Daily weather archive failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
