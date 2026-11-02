<?php
declare(strict_types=1);

const PHILSMS_SEND_ENDPOINT = 'https://dashboard.philsms.com/api/v3/sms/send';

function phil_sms_is_configured(): bool
{
    return PHILSMS_API_KEY !== '' && PHILSMS_SENDER_ID !== '';
}

function phil_sms_normalize_number(string $number): ?string
{
    $number = preg_replace('/[\s().-]+/', '', trim($number)) ?? '';
    if (str_starts_with($number, '+')) {
        $number = substr($number, 1);
    }
    if (preg_match('/^09\d{9}$/', $number) === 1) {
        $number = '63' . substr($number, 1);
    } elseif (preg_match('/^9\d{9}$/', $number) === 1) {
        $number = '63' . $number;
    }

    return preg_match('/^639\d{9}$/', $number) === 1 ? $number : null;
}

function phil_sms_clean_text(string $text, int $maxLength): string
{
    $text = preg_replace('/[^A-Za-z0-9 .,!?:;()\/-]/', '', $text) ?? '';
    $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';

    return substr($text, 0, $maxLength);
}

function send_phil_sms(string $number, string $message): bool
{
    if (!phil_sms_is_configured()) {
        error_log('PhilSMS is not configured; set PHILSMS_API_KEY and PHILSMS_SENDER_ID.');
        return false;
    }
    if (!function_exists('curl_init')) {
        error_log('PhilSMS could not send a message because the PHP cURL extension is unavailable.');
        return false;
    }

    $recipient = phil_sms_normalize_number($number);
    $message = phil_sms_clean_text($message, 160);
    if ($recipient === null || $message === '') {
        error_log('PhilSMS skipped a message because the recipient number or message was invalid.');
        return false;
    }

    $payload = json_encode([
        'recipient' => $recipient,
        'sender_id' => PHILSMS_SENDER_ID,
        'type' => 'plain',
        'message' => $message,
    ], JSON_THROW_ON_ERROR);

    $curl = curl_init(PHILSMS_SEND_ENDPOINT);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . PHILSMS_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($curl);
    if ($response === false) {
        $error = curl_error($curl);
        curl_close($curl);
        error_log('PhilSMS request failed: ' . $error);
        return false;
    }

    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    $result = json_decode($response, true);
    $providerRejected = is_array($result) && (
        !empty($result['error'])
        || (array_key_exists('success', $result) && $result['success'] === false)
        || in_array(strtolower((string) ($result['status'] ?? '')), ['error', 'failed', 'failure'], true)
    );
    if ($status < 200 || $status >= 300 || $providerRejected) {
        error_log('PhilSMS rejected a message with HTTP status ' . $status . '.');
        return false;
    }

    return true;
}

function document_pickup_sms_message(string $documentType, int $requestId): string
{
    $documentType = phil_sms_clean_text($documentType, 34);
    $reference = 'REQ-' . date('Y') . '-' . str_pad((string) $requestId, 4, '0', STR_PAD_LEFT);

    return 'Inosluban: Your ' . $documentType . ' is ready for pickup. Bring a valid ID. Ref ' . $reference . '.';
}

function weather_announcement_sms_message(string $forecastDate, string $summary): string
{
    $date = strtotime($forecastDate);
    $dateLabel = $date === false ? '' : date('M j', $date);
    $summary = phil_sms_clean_text($summary, 35);

    return 'Inosluban weather advisory, ' . $dateLabel . ': ' . $summary . '. Stay alert; follow official updates.';
}

function disaster_alert_sms_message(string $severity, string $alertType, string $affectedArea, string $message): string
{
    $severity = phil_sms_clean_text($severity, 18);
    $alertType = phil_sms_clean_text($alertType, 32);
    $affectedArea = phil_sms_clean_text($affectedArea, 32);
    $areaText = $affectedArea === '' ? '' : ' in ' . $affectedArea;
    $prefix = 'Inosluban ' . $severity . ' alert: ' . $alertType . $areaText . '.';
    $suffix = ' Follow official updates.';
    $message = phil_sms_clean_text($message, max(0, 160 - strlen($prefix) - strlen($suffix) - 1));

    return $prefix . ($message === '' ? '' : ' ' . $message) . $suffix;
}
