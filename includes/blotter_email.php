<?php
declare(strict_types=1);

function blotter_submission_email_html(array $report, string $trackingUrl): string
{
    $templatePath = __DIR__ . '/../templates/emails/blotter_submission.html';
    $template = file_get_contents($templatePath);
    if ($template === false) {
        throw new RuntimeException('The blotter email template could not be loaded.');
    }

    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $reportedAt = (new DateTime(
        (string) $report['report_date'],
        new DateTimeZone(APP_TIMEZONE)
    ))->format('F j, Y \a\t g:i A') . ' (Philippine Time, GMT+8)';
    $values = [
        '{{resident_name}}' => $escape((string) $report['resident_name']),
        '{{report_number}}' => $escape((string) $report['report_number']),
        '{{report_title}}' => $escape((string) $report['report_title']),
        '{{category}}' => $escape((string) $report['category']),
        '{{location}}' => $escape((string) $report['location']),
        '{{reported_at}}' => $escape($reportedAt),
        '{{tracking_url}}' => $escape($trackingUrl),
    ];

    return strtr($template, $values);
}
