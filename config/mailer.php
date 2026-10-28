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
    string $body,
    array $embeddedImages = []
): bool {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        error_log('Email error: recipient address is invalid.');
        return false;
    }
    if (!class_exists(PHPMailer::class)) {
        error_log('Email error: PHPMailer is not installed. Run Composer install during deployment.');
        return false;
    }
    if (SMTP_USERNAME === '' || SMTP_PASSWORD === '' || MAIL_FROM === '') {
        error_log('Email error: SMTP_USERNAME, SMTP_PASSWORD, and MAIL_FROM must be configured.');
        return false;
    }

    $isBrevo = strtolower(SMTP_HOST) === 'smtp-relay.brevo.com';
    if (!filter_var(MAIL_FROM, FILTER_VALIDATE_EMAIL)) {
        error_log('Email error: MAIL_FROM must be a valid sender email address.');
        return false;
    }
    if ($isBrevo && strcasecmp(MAIL_FROM, SMTP_USERNAME) === 0) {
        error_log('Email error: set MAIL_FROM to a verified sender address in Brevo.');
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
        $mail->Port = $isBrevo && SMTP_PORT === 587 ? 2525 : SMTP_PORT;
        $mail->Timeout = 15;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($recipient);
        foreach ($embeddedImages as $image) {
            if (
                !isset($image['content'], $image['cid'], $image['name'], $image['mime'])
                || !is_string($image['content'])
                || !is_string($image['cid'])
                || !is_string($image['name'])
                || !is_string($image['mime'])
            ) {
                throw new \InvalidArgumentException('Embedded email images must include string content, cid, name, and mime values.');
            }
            $mail->addStringEmbeddedImage(
                $image['content'],
                $image['cid'],
                $image['name'],
                PHPMailer::ENCODING_BASE64,
                $image['mime']
            );
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return true;

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log('Email error: ' . $e->getMessage());
        return false;
    }
}