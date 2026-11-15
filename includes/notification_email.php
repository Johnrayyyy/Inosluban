<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/mailer.php";

function resident_notification_email_html(
    string $heading,
    string $content,
): string {
    return '<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>' .
        e($heading) .
        '</title>
</head>
<body style="margin:0;background:#f8f5f3;color:#211a1c;font-family:Arial,sans-serif">
    <main style="max-width:620px;margin:32px auto;background:#fff;border-radius:16px;overflow:hidden">
        <header style="padding:28px;background:#8e0710;color:#fff;text-align:center">
            <strong>BARANGAY INOSLUBAN</strong>
            <div style="margin-top:5px;color:#f4d789;font-size:12px;letter-spacing:2px">LIPA CITY, BATANGAS</div>
        </header>
        <section style="padding:30px">
            <h1 style="margin:0 0 16px;color:#8e0710;font-size:24px">' .
        e($heading) .
        '</h1>
            ' .
        $content .
        '
        </section>
        <footer style="padding:18px;text-align:center;background:#211a1c;color:#fff;font-size:12px">
            Connect. Collaborate. Grow Together.
        </footer>
    </main>
</body>
</html>';
}

function send_resident_broadcast_email(
    string $subject,
    string $htmlBody,
    string $plainText,
    ?int $disasterId = null,
    ?int $announcementId = null,
): array {
    $subject = trim(preg_replace("/[\r\n]+/", " ", $subject) ?? "");
    if ($subject === "") {
        throw new InvalidArgumentException(
            "Resident notification email subjects cannot be empty.",
        );
    }

    $recipients = db()
        ->query(
            "SELECT DISTINCT ON (LOWER(BTRIM(email)))
                resident_id, BTRIM(email) AS email
             FROM resident
             WHERE residency_status = 'Active'
               AND NULLIF(BTRIM(email), '') IS NOT NULL
             ORDER BY LOWER(BTRIM(email)), resident_id",
        )
        ->fetchAll();

    $insertDelivery = db()->prepare(
        "INSERT INTO notification_log
            (resident_id, disaster_id, announcement_id, recipient, channel, message, delivery_status, sent_at)
         VALUES
            (:resident_id, :disaster_id, :announcement_id, :recipient, 'Email', :message, :delivery_status,
             CASE WHEN :sent_status = 'Sent' THEN CURRENT_TIMESTAMP ELSE NULL END)",
    );
    $sentCount = 0;
    $failedCount = 0;

    foreach ($recipients as $recipient) {
        $email = (string) $recipient["email"];
        $sent = sendEmail($email, $subject, $htmlBody, [], $plainText);
        $deliveryStatus = $sent ? "Sent" : "Failed";
        $insertDelivery->execute([
            "resident_id" => (int) $recipient["resident_id"],
            "disaster_id" => $disasterId,
            "announcement_id" => $announcementId,
            "recipient" => $email,
            "message" => $plainText,
            "delivery_status" => $deliveryStatus,
            "sent_status" => $deliveryStatus,
        ]);

        if ($sent) {
            $sentCount++;
        } else {
            $failedCount++;
        }
    }

    return [
        "recipient_count" => count($recipients),
        "sent_count" => $sentCount,
        "failed_count" => $failedCount,
    ];
}

function send_disaster_alert_email(int $disasterId, array $alert): array
{
    $severity = (string) $alert["severity"];
    $alertType = (string) $alert["alert_type"];
    $affectedArea = trim((string) ($alert["affected_area"] ?? ""));
    $message = (string) $alert["alert_message"];
    $subject = $severity . " alert: " . $alertType;
    $plainText = $subject . "\n";
    if ($affectedArea !== "") {
        $plainText .= "Affected area: " . $affectedArea . "\n";
    }
    $plainText .= "\n" . $message;

    $htmlContent = "<p><strong>Severity:</strong> " . e($severity) . "</p>";
    if ($affectedArea !== "") {
        $htmlContent .=
            "<p><strong>Affected area:</strong> " . e($affectedArea) . "</p>";
    }
    $htmlContent .= "<p>" . nl2br(e($message)) . "</p>";
    $htmlContent .=
        '<p style="font-size:13px;color:#655b5d">Please follow verified guidance from local authorities and official channels.</p>';

    return send_resident_broadcast_email(
        $subject,
        resident_notification_email_html($subject, $htmlContent),
        $plainText,
        disasterId: $disasterId,
    );
}
