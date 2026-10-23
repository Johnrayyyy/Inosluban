<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

if (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}
require_once __DIR__ . '/config.php';

function sendEmail(
    string $recipient,
    string $subject,
    string $body
): bool {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        error_log('Email error: recipient address is invalid.');
        return false;
    }
    if (
        !class_exists(PHPMailer::class)
        || SMTP_USERNAME === ''
        || SMTP_PASSWORD === ''
        || MAIL_FROM === ''
    ) {
        error_log('Email error: PHPMailer or SMTP configuration is missing.');
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = preg_replace('/\s+/', '', SMTP_PASSWORD);
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->Timeout = 15;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($recipient);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return true;

    } catch (\Throwable $e) {
        error_log('Email error: ' . $e->getMessage());
        return false;
    }
}