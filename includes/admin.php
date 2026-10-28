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
    db()->exec('ALTER TABLE weather_advisory ADD COLUMN IF NOT EXISTS weather_data JSONB');
    db()->exec(
        "CREATE TABLE IF NOT EXISTS weather_snapshot (
            snapshot_id SMALLINT PRIMARY KEY CHECK (snapshot_id = 1),
            weather_data JSONB,
            fetched_at TIMESTAMPTZ,
            attempted_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_error TEXT
        )"
    );
}

function store_weather_snapshot(array $weather): void
{
    $weatherData = json_encode($weather, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    $statement = db()->prepare(
        "INSERT INTO weather_snapshot (snapshot_id, weather_data, fetched_at, attempted_at, last_error)
         VALUES (1, CAST(:weather_data AS JSONB), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL)
         ON CONFLICT (snapshot_id) DO UPDATE SET
            weather_data = EXCLUDED.weather_data,
            fetched_at = EXCLUDED.fetched_at,
            attempted_at = EXCLUDED.attempted_at,
            last_error = NULL"
    );
    $statement->execute(['weather_data' => $weatherData]);
}

function record_weather_sync_failure(string $error): void
{
    $statement = db()->prepare(
        "INSERT INTO weather_snapshot (snapshot_id, attempted_at, last_error)
         VALUES (1, CURRENT_TIMESTAMP, :last_error)
         ON CONFLICT (snapshot_id) DO UPDATE SET
            attempted_at = EXCLUDED.attempted_at,
            last_error = EXCLUDED.last_error"
    );
    $statement->execute(['last_error' => substr($error, 0, 1000)]);
}

function latest_weather_snapshot(): ?array
{
    $snapshot = db()->query(
        'SELECT weather_data, fetched_at, attempted_at, last_error
         FROM weather_snapshot WHERE snapshot_id = 1'
    )->fetch();
    if (!$snapshot) {
        return null;
    }

    $weather = is_string($snapshot['weather_data'])
        ? json_decode($snapshot['weather_data'], true)
        : $snapshot['weather_data'];
    if (!is_array($weather)) {
        return [
            'fetched_at' => null,
            'attempted_at' => $snapshot['attempted_at'],
            'last_error' => $snapshot['last_error'],
            'weather' => null,
        ];
    }

    return [
        'fetched_at' => $snapshot['fetched_at'],
        'attempted_at' => $snapshot['attempted_at'],
        'last_error' => $snapshot['last_error'],
        'weather' => $weather,
    ];
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

function weather_icon_for(int $code): string
{
    return match (true) {
        $code === 0, $code === 1 => 'bi-sun',
        $code === 2 => 'bi-cloud-sun',
        $code === 3 => 'bi-cloud',
        in_array($code, [45, 48], true) => 'bi-cloud-fog',
        in_array($code, [51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82], true) => 'bi-cloud-rain',
        in_array($code, [71, 73, 75, 77, 85, 86], true) => 'bi-cloud-snow',
        in_array($code, [95, 96, 99], true) => 'bi-cloud-lightning-rain',
        default => 'bi-cloud',
    };
}

function weather_request_json(string $url, string $userAgent): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Weather fetching requires the PHP cURL extension. Enable or install PHP cURL, then restart the web service.');
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => $userAgent,
    ]);
    $raw = curl_exec($curl);
    if ($raw === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('Weather service could not be reached: ' . $error);
    }

    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $data = json_decode($raw, true);
    if ($status < 200 || $status >= 300 || !is_array($data)) {
        $reason = is_array($data) ? (string) ($data['reason'] ?? $data['message'] ?? '') : '';
        throw new RuntimeException($reason !== '' ? $reason : 'Weather service returned HTTP ' . $status . '.');
    }

    return $data;
}

function fetch_open_meteo_advisory(): array
{
    $query = http_build_query([
        'latitude' => WEATHER_LATITUDE,
        'longitude' => WEATHER_LONGITUDE,
        'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,sunrise,sunset,daylight_duration,sunshine_duration,rain_sum,precipitation_sum,precipitation_hours,wind_speed_10m_max,wind_gusts_10m_max',
        'hourly' => 'temperature_2m,dew_point_2m,relative_humidity_2m,rain,precipitation,weather_code,cloud_cover,cloud_cover_low,cloud_cover_mid,cloud_cover_high,wind_speed_10m,wind_direction_10m,et0_fao_evapotranspiration,vapour_pressure_deficit',
        'models' => 'jma_seamless',
        'current' => 'temperature_2m,relative_humidity_2m,is_day,precipitation,rain,showers,weather_code,cloud_cover,wind_speed_10m,wind_direction_10m',
        'timezone' => WEATHER_TIMEZONE,
        'forecast_days' => 7,
    ], '', '&', PHP_QUERY_RFC3986);
    $url = 'https://api.open-meteo.com/v1/forecast?' . $query;
    $data = weather_request_json($url, APP_NAME . ' weather advisory');
    $current = $data['current'] ?? null;
    $daily = $data['daily'] ?? null;
    $hourlyData = $data['hourly'] ?? null;
    if (!is_array($current) || !is_array($daily) || empty($daily['time']) || !is_array($hourlyData) || empty($hourlyData['time'])) {
        throw new RuntimeException('Open-Meteo returned incomplete current, hourly, or daily data.');
    }

    $currentCode = (int) ($current['weather_code'] ?? 0);
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
            'precipitation_hours' => (float) ($daily['precipitation_hours'][$index] ?? 0),
            'wind_speed_max' => (float) ($daily['wind_speed_10m_max'][$index] ?? 0),
            'wind_gusts_max' => (float) ($daily['wind_gusts_10m_max'][$index] ?? 0),
        ];
    }

    $hourly = [];
    foreach ($hourlyData['time'] as $index => $time) {
        $hourlyCode = (int) ($hourlyData['weather_code'][$index] ?? 0);
        $hourly[] = [
            'time' => (string) $time,
            'temperature' => (float) ($hourlyData['temperature_2m'][$index] ?? 0),
            'dew_point' => (float) ($hourlyData['dew_point_2m'][$index] ?? 0),
            'relative_humidity' => (int) ($hourlyData['relative_humidity_2m'][$index] ?? 0),
            'rain' => (float) ($hourlyData['rain'][$index] ?? 0),
            'precipitation' => (float) ($hourlyData['precipitation'][$index] ?? 0),
            'weather_code' => $hourlyCode,
            'summary' => weather_code_label($hourlyCode),
            'cloud_cover' => (int) ($hourlyData['cloud_cover'][$index] ?? 0),
            'cloud_cover_low' => (int) ($hourlyData['cloud_cover_low'][$index] ?? 0),
            'cloud_cover_mid' => (int) ($hourlyData['cloud_cover_mid'][$index] ?? 0),
            'cloud_cover_high' => (int) ($hourlyData['cloud_cover_high'][$index] ?? 0),
            'wind_speed' => (float) ($hourlyData['wind_speed_10m'][$index] ?? 0),
            'wind_direction' => (int) ($hourlyData['wind_direction_10m'][$index] ?? 0),
            'evapotranspiration' => (float) ($hourlyData['et0_fao_evapotranspiration'][$index] ?? 0),
            'vapour_pressure_deficit' => (float) ($hourlyData['vapour_pressure_deficit'][$index] ?? 0),
        ];
    }

    $currentResult = [
        'time' => (string) ($current['time'] ?? ''),
        'temperature' => $temperature,
        'relative_humidity' => (int) ($current['relative_humidity_2m'] ?? 0),
        'is_day' => (int) ($current['is_day'] ?? 0),
        'rain' => (float) ($current['rain'] ?? 0),
        'precipitation' => (float) ($current['precipitation'] ?? 0),
        'showers' => (float) ($current['showers'] ?? 0),
        'weather_code' => $currentCode,
        'cloud_cover' => (int) ($current['cloud_cover'] ?? 0),
        'wind_speed' => (float) ($current['wind_speed_10m'] ?? 0),
        'wind_direction' => (int) ($current['wind_direction_10m'] ?? 0),
    ];

    return [
        'location' => WEATHER_LOCATION,
        'summary' => weather_code_label($currentCode),
        'temperature' => $temperature,
        'severity' => $severity,
        'recommendation' => weather_recommendation($severity),
        'source_name' => 'Open-Meteo',
        'source_url' => $url,
        'current' => $currentResult,
        'hourly' => $hourly,
        'forecast' => $forecast,
    ];
}

function weather_code_from_symbol(string $symbol): int
{
    $symbol = strtolower(preg_replace('/_(day|night|polartwilight)$/', '', $symbol) ?? $symbol);

    return match (true) {
        str_contains($symbol, 'thunder') => 95,
        str_starts_with($symbol, 'clearsky') => 0,
        str_starts_with($symbol, 'fair') => 1,
        str_starts_with($symbol, 'partlycloudy') => 2,
        str_starts_with($symbol, 'cloudy') => 3,
        str_starts_with($symbol, 'fog') => 45,
        str_starts_with($symbol, 'rainshowers') => 80,
        str_starts_with($symbol, 'lightrainshowers') => 80,
        str_starts_with($symbol, 'lightrain') => 51,
        str_starts_with($symbol, 'heavyrain') => 65,
        str_starts_with($symbol, 'rain') => 61,
        str_starts_with($symbol, 'sleet') => 66,
        str_starts_with($symbol, 'snowshowers') => 85,
        str_starts_with($symbol, 'snow') => 71,
        str_starts_with($symbol, 'thunder') => 95,
        default => 3,
    };
}

function fetch_met_no_advisory(): array
{
    $url = 'https://api.met.no/weatherapi/locationforecast/2.0/compact?' . http_build_query([
        'lat' => WEATHER_LATITUDE,
        'lon' => WEATHER_LONGITUDE,
    ], '', '&', PHP_QUERY_RFC3986);
    $data = weather_request_json($url, APP_NAME . ' weather advisory (' . APP_BASE_URL . ')');
    $timeseries = $data['properties']['timeseries'] ?? null;
    if (!is_array($timeseries) || $timeseries === []) {
        throw new RuntimeException('MET Norway returned no hourly forecast data.');
    }

    $timezone = new DateTimeZone(WEATHER_TIMEZONE);
    $now = new DateTimeImmutable('now', $timezone);
    $hourly = [];
    $dailyGroups = [];
    $currentIndex = 0;
    $smallestTimeDifference = PHP_INT_MAX;
    foreach ($timeseries as $index => $entry) {
        $timestamp = (new DateTimeImmutable((string) $entry['time']))->setTimezone($timezone);
        $details = $entry['data']['instant']['details'] ?? [];
        $oneHour = $entry['data']['next_1_hours'] ?? [];
        $symbol = (string) ($oneHour['summary']['symbol_code'] ?? $entry['data']['next_6_hours']['summary']['symbol_code'] ?? 'cloudy');
        $code = weather_code_from_symbol($symbol);
        $temperature = (float) ($details['air_temperature'] ?? 0);
        $precipitation = (float) (
            $oneHour['details']['precipitation_amount']
            ?? $entry['data']['next_6_hours']['details']['precipitation_amount']
            ?? 0
        );
        $windSpeed = (float) ($details['wind_speed'] ?? 0) * 3.6;
        $hourly[] = [
            'time' => $timestamp->format('Y-m-d\TH:i'),
            'temperature' => $temperature,
            'dew_point' => null,
            'relative_humidity' => isset($details['relative_humidity']) ? (int) round((float) $details['relative_humidity']) : null,
            'rain' => $precipitation,
            'precipitation' => $precipitation,
            'weather_code' => $code,
            'summary' => weather_code_label($code),
            'cloud_cover' => (int) round((float) ($details['cloud_area_fraction'] ?? 0)),
            'cloud_cover_low' => null,
            'cloud_cover_mid' => null,
            'cloud_cover_high' => null,
            'wind_speed' => round($windSpeed, 1),
            'wind_direction' => (int) round((float) ($details['wind_from_direction'] ?? 0)),
            'evapotranspiration' => null,
            'vapour_pressure_deficit' => null,
        ];

        $dateKey = $timestamp->format('Y-m-d');
        if (!isset($dailyGroups[$dateKey])) {
            $dailyGroups[$dateKey] = [
                'date' => $dateKey,
                'weather_code' => $code,
                'temperature_min' => $temperature,
                'temperature_max' => $temperature,
                'rain_sum' => 0.0,
                'precipitation_sum' => 0.0,
                'precipitation_hours' => 0,
                'wind_speed_max' => 0.0,
                'wind_gusts_max' => null,
            ];
        }
        $dailyGroups[$dateKey]['temperature_min'] = min($dailyGroups[$dateKey]['temperature_min'], $temperature);
        $dailyGroups[$dateKey]['temperature_max'] = max($dailyGroups[$dateKey]['temperature_max'], $temperature);
        $dailyGroups[$dateKey]['rain_sum'] += $precipitation;
        $dailyGroups[$dateKey]['precipitation_sum'] += $precipitation;
        $dailyGroups[$dateKey]['precipitation_hours'] += $precipitation > 0 ? 1 : 0;
        $dailyGroups[$dateKey]['wind_speed_max'] = max($dailyGroups[$dateKey]['wind_speed_max'], $windSpeed);
        if (isset($details['wind_speed_of_gust'])) {
            $gustSpeed = (float) $details['wind_speed_of_gust'] * 3.6;
            $dailyGroups[$dateKey]['wind_gusts_max'] = $dailyGroups[$dateKey]['wind_gusts_max'] === null
                ? $gustSpeed
                : max($dailyGroups[$dateKey]['wind_gusts_max'], $gustSpeed);
        }
        if (weather_severity_rank(weather_severity_for($code)) > weather_severity_rank(weather_severity_for($dailyGroups[$dateKey]['weather_code']))) {
            $dailyGroups[$dateKey]['weather_code'] = $code;
        }

        $difference = abs($timestamp->getTimestamp() - $now->getTimestamp());
        if ($difference < $smallestTimeDifference) {
            $currentIndex = count($hourly) - 1;
            $smallestTimeDifference = $difference;
        }
    }

    $currentHour = $hourly[$currentIndex];
    $currentCode = $currentHour['weather_code'];
    $current = $currentHour + [
        'showers' => $currentHour['precipitation'],
        'is_day' => (int) ((int) $now->format('G') >= 6 && (int) $now->format('G') < 18),
    ];
    $forecast = [];
    $severity = weather_severity_for($currentCode);
    foreach (array_slice($dailyGroups, 0, 7, true) as $day) {
        $day['summary'] = weather_code_label($day['weather_code']);
        $day['sunrise'] = '';
        $day['sunset'] = '';
        $day['daylight_hours'] = null;
        $day['sunshine_hours'] = null;
        if (weather_severity_rank(weather_severity_for($day['weather_code'])) > weather_severity_rank($severity)) {
            $severity = weather_severity_for($day['weather_code']);
        }
        $forecast[] = $day;
    }

    return [
        'location' => WEATHER_LOCATION,
        'summary' => weather_code_label($currentCode),
        'temperature' => $currentHour['temperature'],
        'severity' => $severity,
        'recommendation' => weather_recommendation($severity),
        'source_name' => 'MET Norway',
        'source_url' => $url,
        'current' => $current,
        'hourly' => $hourly,
        'forecast' => $forecast,
    ];
}

function weather_recommendation(string $severity): string
{
    return match ($severity) {
        'Warning' => 'Thunderstorms are forecast. Prepare a weather advisory and monitor PAGASA updates.',
        'Watch' => 'Rain is forecast. Review conditions before publishing a weather-related advisory.',
        default => 'No immediate weather announcement is suggested.',
    };
}

function fetch_weather_advisory(): array
{
    try {
        return fetch_open_meteo_advisory();
    } catch (Throwable $openMeteoException) {
        try {
            $weather = fetch_met_no_advisory();
            $weather['recommendation'] .= ' Open-Meteo was unavailable; these conditions are from MET Norway.';
            error_log('Open-Meteo fetch failed; using MET Norway fallback: ' . $openMeteoException->getMessage());
            return $weather;
        } catch (Throwable $fallbackException) {
            throw new RuntimeException(
                'Weather providers could not return data. Open-Meteo: ' . $openMeteoException->getMessage()
                . ' MET Norway: ' . $fallbackException->getMessage(),
                0,
                $fallbackException
            );
        }
    }
}
