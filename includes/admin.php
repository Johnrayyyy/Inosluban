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

function fetch_weather_advisory(): array
{
    if (WEATHER_API_KEY === '') {
        throw new RuntimeException('Weather API is not configured. Add WEATHER_API_KEY to the local .env file.');
    }
    $url = 'https://api.openweathermap.org/data/2.5/weather?q=' . rawurlencode(WEATHER_CITY) . '&appid=' . rawurlencode(WEATHER_API_KEY) . '&units=metric';
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $raw = curl_exec($curl);
    if ($raw === false) {
        throw new RuntimeException('Weather service could not be reached.');
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $data = json_decode($raw, true);
    if ($status < 200 || $status >= 300 || !is_array($data)) {
        throw new RuntimeException((string) ($data['message'] ?? 'Weather service returned an error.'));
    }
    $summary = ucfirst((string) ($data['weather'][0]['description'] ?? 'Current conditions unavailable'));
    $temperature = (float) ($data['main']['temp'] ?? 0);
    $severity = str_contains(strtolower($summary), 'storm') || str_contains(strtolower($summary), 'rain') ? 'Watch' : 'Information';
    return [
        'location' => (string) ($data['name'] ?? WEATHER_CITY),
        'summary' => $summary,
        'temperature' => $temperature,
        'severity' => $severity,
        'recommendation' => $severity === 'Watch' ? 'Review conditions before publishing a weather-related advisory.' : 'No immediate weather announcement is suggested.',
        'source_url' => $url,
    ];
}
