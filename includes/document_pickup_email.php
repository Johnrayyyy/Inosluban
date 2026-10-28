<?php
declare(strict_types=1);

function document_pickup_ready_email_html(array $request, string $verificationUrl): string
{
    $templatePath = __DIR__ . '/../templates/emails/document_pickup_ready.html';
    $template = file_get_contents($templatePath);
    if ($template === false) {
        throw new RuntimeException('The document pickup email template could not be loaded.');
    }

    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $requestNumber = 'REQ-' . date('Y') . '-' . str_pad((string) $request['request_id'], 4, '0', STR_PAD_LEFT);
    $values = [
        '{{resident_name}}' => $escape((string) $request['name']),
        '{{document_type}}' => $escape((string) $request['document_type']),
        '{{request_number}}' => $escape($requestNumber),
        '{{verification_url}}' => $escape($verificationUrl),
        '{{portal_url}}' => $escape(rtrim(APP_BASE_URL, '/') . '/index.php'),
        '{{pickup_qr}}' => '<img src="cid:document-pickup-qr" alt="Document pickup verification QR code" width="220" height="220" style="display:block;width:220px;height:220px;margin:0 auto">',
    ];

    return strtr($template, $values);
}
