<?php
declare(strict_types=1);

use setasign\Fpdi\Tfpdf\Fpdi;

function document_template_path(string $documentType): string
{
    $templates = [
        'Barangay Clearance' => 'Clearance.pdf',
        'Certificate of Indigency' => 'Indigency.pdf',
        'Certificate of Residency' => 'Residency.pdf',
    ];
    if (!isset($templates[$documentType])) {
        throw new RuntimeException('No print template is available for this document type.');
    }

    $path = __DIR__ . '/../storage/document_templates/' . $templates[$documentType];
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('The original document template is unavailable.');
    }

    return $path;
}

function document_template_text(Fpdi $pdf, float $x, float $baseline, float $width, string $value, float $fontSize = 9): void
{
    if (!function_exists('iconv')) {
        throw new RuntimeException('PHP iconv support is required to format document text.');
    }
    $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
    if ($converted === false) {
        throw new RuntimeException('A document field contains text that cannot be printed.');
    }
    $value = trim($converted);
    if ($value === '') {
        return;
    }

    for ($size = $fontSize; $size >= 6; $size -= 0.5) {
        $pdf->SetFont('Arial', '', $size);
        if ($pdf->GetStringWidth($value) <= $width) {
            break;
        }
    }
    if ($pdf->GetStringWidth($value) > $width) {
        throw new RuntimeException('A document field is too long to fit the original template. Shorten it before printing.');
    }

    $pdf->Text($x, $baseline, $value);
}

function document_template_issue_date(Fpdi $pdf, float $baseline, string $date, float $monthX = 84): void
{
    $issueDate = new DateTimeImmutable($date);
    $textBaseline = $baseline - 0.5;
    document_template_text($pdf, 59, $textBaseline, 14, $issueDate->format('j'), 8);
    document_template_text($pdf, $monthX, $textBaseline, 35, $issueDate->format('F'), 8);
    document_template_text($pdf, 121, $textBaseline, 22, $issueDate->format('Y'), 8);
}

function staff_generate_document_template_pdf(array $request, array $fields): string
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $templatePath = document_template_path((string) $request['document_type']);
    $pdf = new Fpdi();
    $pageCount = $pdf->setSourceFile($templatePath);
    if ($pageCount < 1) {
        throw new RuntimeException('The document template contains no printable pages.');
    }

    $templatePage = $pdf->importPage(1);
    $pageSize = $pdf->getTemplateSize($templatePage);
    $pdf->AddPage($pageSize['orientation'], [$pageSize['width'], $pageSize['height']]);
    $pdf->useTemplate($templatePage);
    $pdf->SetTextColor(25, 25, 25);
    $baseline = static fn (float $fromTop): float => $fromTop;
    if ($request['document_type'] === 'Barangay Clearance') {
        $fields['birthday'] = (new DateTimeImmutable($fields['birthday']))->format('F j, Y');
        document_template_text($pdf, 77, $baseline(117), 73, $fields['name']);
        document_template_text($pdf, 153, $baseline(117), 24, (string) $fields['age']);
        document_template_text($pdf, 50, $baseline(125), 38, $fields['civil_status'], 8);
        document_template_text($pdf, 119, $baseline(125), 42, $fields['birthday'], 8);
        document_template_text($pdf, 28, $baseline(136), 155, $fields['purok_address'], 8);
        document_template_text($pdf, 72, $baseline(163), 94, $fields['purpose'], 8);
        document_template_issue_date($pdf, $baseline(182), $fields['issue_date'], 90);
    } elseif ($request['document_type'] === 'Certificate of Indigency') {
        document_template_text($pdf, 78, $baseline(100.5), 72, $fields['name']);
        document_template_text($pdf, 153, $baseline(100.5), 24, (string) $fields['age']);
        document_template_text($pdf, 47, $baseline(112.5), 138, $fields['purok_address'], 8);
        document_template_text($pdf, 127, $baseline(151), 55, $fields['name'], 8);
        document_template_text($pdf, 43, $baseline(161.5), 137, $fields['purpose'], 8);
        document_template_issue_date($pdf, $baseline(171), $fields['issue_date']);
    } else {
        $fields['birthday'] = (new DateTimeImmutable($fields['birthday']))->format('F j, Y');
        document_template_text($pdf, 78, $baseline(116.5), 74, $fields['name']);
        document_template_text($pdf, 151, $baseline(116.5), 24, (string) $fields['age']);
        document_template_text($pdf, 50, $baseline(124.5), 38, $fields['civil_status'], 8);
        document_template_text($pdf, 118, $baseline(124.5), 47, $fields['birthday'], 8);
        document_template_text($pdf, 28, $baseline(148.5), 155, $fields['purok_address'], 8);
        document_template_issue_date($pdf, $baseline(182), $fields['issue_date'], 90);
    }

    return $pdf->Output('S');
}
