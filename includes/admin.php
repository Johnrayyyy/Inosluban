<?php

function require_admin(): void
{
    require_login();
    if (($_SESSION['user']['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('This area is available to administrators only.');
    }
}

function ensure_admin_schema(): void
{
    db()->exec('ALTER TABLE announcement ADD COLUMN IF NOT EXISTS image_path TEXT');
    db()->exec(
        "CREATE TABLE IF NOT EXISTS weather_advisory (
            advisory_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            created_by_user_id INT REFERENCES user_account(user_id) ON DELETE SET NULL,
            location VARCHAR(255) NOT NULL,
            weather_summary TEXT NOT NULL,
            temperature NUMERIC(6,2),
            severity VARCHAR(30) NOT NULL DEFAULT 'Information',
            recommendation TEXT NOT NULL,
            source_url TEXT,
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMPTZ,
            status VARCHAR(30) NOT NULL DEFAULT 'Pending'
                CHECK (status IN ('Pending', 'Approved', 'Dismissed'))
        )"
    );
}

function admin_upload_image(string $field): ?string
{
    if (empty($_FILES[$field]['name'])) {
        return null;
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK || $_FILES[$field]['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Images must be smaller than 5 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Only JPG, PNG, or WEBP images are accepted.');
    }
    $directory = __DIR__ . '/../storage/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
        throw new RuntimeException('Unable to prepare image storage.');
    }
    $filename = 'announcement-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('The image could not be saved.');
    }
    return 'storage/uploads/' . $filename;
}

function weather_code_label(int $code): string
{
    return match (true) {
        $code === 0 => 'Clear sky',
        $code === 1 => 'Mainly clear',
        $code === 2 => 'Partly cloudy',
        $code === 3 => 'Overcast',
        in_array($code, [45, 48], true) => 'Fog',
        in_array($code, [51, 53, 55], true) => 'Drizzle',
        in_array($code, [56, 57], true) => 'Freezing drizzle',
        in_array($code, [61, 63, 65], true) => 'Rain',
        in_array($code, [66, 67], true) => 'Freezing rain',
        in_array($code, [71, 73, 75, 77], true) => 'Snow',
        in_array($code, [80, 81, 82], true) => 'Rain showers',
        in_array($code, [85, 86], true) => 'Snow showers',
        $code === 95 => 'Thunderstorm',
        in_array($code, [96, 99], true) => 'Thunderstorm with hail',
        default => 'Conditions unavailable',
    };
}

function weather_severity_for(int $code): string
{
    if (in_array($code, [95, 96, 99], true)) {
        return 'Warning';
    }
    if ($code >= 51) {
        return 'Watch';
    }
    return 'Information';
}

function weather_severity_rank(string $severity): int
{
    return ['Information' => 1, 'Watch' => 2, 'Warning' => 3][$severity] ?? 1;
}

function weather_wind_direction(int $degrees): string
{
    $directions = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
    return $directions[(int) round((($degrees % 360) + 360) % 360 / 22.5) % 16];
}

function fetch_weather_advisory(): array
{
    $query = http_build_query([
        'latitude' => WEATHER_LATITUDE,
        'longitude' => WEATHER_LONGITUDE,
        'daily' => 'weather_code,temperature_2m_min,temperature_2m_max,sunrise,sunset,daylight_duration,sunshine_duration,rain_sum,precipitation_sum',
        'hourly' => 'temperature_2m,rain,precipitation,weather_code,cloud_cover,cloud_cover_low,cloud_cover_mid,cloud_cover_high,wind_speed_10m,wind_direction_10m',
        'models' => 'jma_seamless',
        'current' => 'temperature_2m,rain,precipitation,showers,weather_code,cloud_cover,wind_speed_10m,wind_direction_10m',
        'timezone' => WEATHER_TIMEZONE,
        'forecast_days' => 7,
    ], '', '&', PHP_QUERY_RFC3986);
    $url = 'https://api.open-meteo.com/v1/forecast?' . $query;
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => APP_NAME . ' weather advisory',
    ]);
    $raw = curl_exec($curl);
    if ($raw === false) {
        throw new RuntimeException('Weather service could not be reached.');
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $data = json_decode($raw, true);
    if ($status < 200 || $status >= 300 || !is_array($data)) {
        throw new RuntimeException((string) ($data['reason'] ?? 'Weather service returned an error.'));
    }

    $current = $data['current'] ?? null;
    $daily = $data['daily'] ?? null;
    if (!is_array($current) || !is_array($daily) || empty($daily['time'])) {
        throw new RuntimeException('Weather service returned incomplete data.');
    }

    $currentCode = (int) ($current['weather_code'] ?? 0);
    $summary = weather_code_label($currentCode);
    $temperature = (float) ($current['temperature_2m'] ?? 0);
    $severity = weather_severity_for($currentCode);

    $forecast = [];
    foreach ($daily['time'] as $index => $date) {
        $dayCode = (int) ($daily['weather_code'][$index] ?? 0);
        $daySeverity = weather_severity_for($dayCode);
        if (weather_severity_rank($daySeverity) > weather_severity_rank($severity)) {
            $severity = $daySeverity;
        }
        $daylightSeconds = $daily['daylight_duration'][$index] ?? null;
        $sunshineSeconds = $daily['sunshine_duration'][$index] ?? null;
        $forecast[] = [
            'date' => (string) $date,
            'weather_code' => $dayCode,
            'summary' => weather_code_label($dayCode),
            'temperature_min' => (float) ($daily['temperature_2m_min'][$index] ?? 0),
            'temperature_max' => (float) ($daily['temperature_2m_max'][$index] ?? 0),
            'sunrise' => (string) ($daily['sunrise'][$index] ?? ''),
            'sunset' => (string) ($daily['sunset'][$index] ?? ''),
            'daylight_hours' => $daylightSeconds === null ? null : round((float) $daylightSeconds / 3600, 1),
            'sunshine_hours' => $sunshineSeconds === null ? null : round((float) $sunshineSeconds / 3600, 1),
            'rain_sum' => (float) ($daily['rain_sum'][$index] ?? 0),
            'precipitation_sum' => (float) ($daily['precipitation_sum'][$index] ?? 0),
        ];
    }

    $recommendation = match ($severity) {
        'Warning' => 'Thunderstorms are forecast. Prepare a weather advisory and monitor PAGASA updates.',
        'Watch' => 'Rain is forecast. Review conditions before publishing a weather-related advisory.',
        default => 'No immediate weather announcement is suggested.',
    };

    return [
        'location' => WEATHER_LOCATION,
        'summary' => $summary,
        'temperature' => $temperature,
        'severity' => $severity,
        'recommendation' => $recommendation,
        'source_url' => $url,
        'current' => [
            'time' => (string) ($current['time'] ?? ''),
            'temperature' => $temperature,
            'rain' => (float) ($current['rain'] ?? 0),
            'precipitation' => (float) ($current['precipitation'] ?? 0),
            'showers' => (float) ($current['showers'] ?? 0),
            'weather_code' => $currentCode,
            'cloud_cover' => (int) ($current['cloud_cover'] ?? 0),
            'wind_speed' => (float) ($current['wind_speed_10m'] ?? 0),
            'wind_direction' => (int) ($current['wind_direction_10m'] ?? 0),
        ],
        'forecast' => $forecast,
    ];
}
