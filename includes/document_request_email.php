<?php
declare(strict_types=1);

function document_request_email_html(array $request, string $trackingUrl): string
{
    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $purpose = $request['purpose'] === 'Other'
        ? 'Other: ' . (string) $request['purpose_other']
        : (string) $request['purpose'];
    $requestedAt = (new DateTime((string) $request['request_date']))->format('F j, Y \a\t g:i A');

    return '<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Document request confirmation</title></head>
<body style="margin:0;background:#f8f5f3;color:#211a1c;font-family:Arial,sans-serif">
<div style="max-width:620px;margin:32px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 8px 28px rgba(52,27,20,.12)">
<div style="padding:28px;background:#8e0710;color:#fff;text-align:center">
<div style="font-size:20px;font-weight:700">BARANGAY INOSLUBAN</div>
<div style="margin-top:5px;color:#f4d789;font-size:12px;letter-spacing:2px">LIPA CITY, BATANGAS</div>
</div>
<div style="padding:30px">
<h1 style="margin:0 0 12px;color:#8e0710;font-size:24px">Request submitted</h1>
<p>Hi ' . $escape((string) $request['name']) . ',</p>
<p>Your document request has been successfully received. We will review it and update its progress through the resident portal.</p>
<div style="margin:24px 0;padding:18px;background:#f8f5f3;border-left:4px solid #e9ad25">
<p style="margin:0 0 8px"><strong>Document:</strong> ' . $escape((string) $request['document_type']) . '</p>
<p style="margin:0 0 8px"><strong>Purpose:</strong> ' . $escape($purpose) . '</p>
<p style="margin:0"><strong>Submitted:</strong> ' . $escape($requestedAt) . '</p>
</div>
<p style="text-align:center;margin:28px 0"><a href="' . $escape($trackingUrl) . '" style="display:inline-block;padding:13px 22px;background:#8e0710;color:#fff;text-decoration:none;border-radius:7px;font-weight:700">Track my request</a></p>
<p style="font-size:13px;color:#655b5d">If the button does not work, copy and paste this link into your browser:<br>' . $escape($trackingUrl) . '</p>
</div>
<div style="padding:18px;text-align:center;background:#211a1c;color:#fff;font-size:12px">Connect. Collaborate. Grow Together.</div>
</div></body></html>';
}
