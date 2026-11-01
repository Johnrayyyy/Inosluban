<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/includes/document_templates.php';
require_staff();
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');

$requestId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
$editMode = ($_GET['edit'] ?? '') === '1';
$request = null;
$fields = [];
$errors = [];
$usesBirthdayAndCivilStatus = false;
$usesPurpose = false;

if ($requestId !== false && $requestId !== null && $requestId > 0) {
    $statement = db()->prepare(
        'SELECT dr.request_id, dr.document_type, dr.name, dr.birthday, dr.age,
                dr.purok_address, dr.civil_status, dr.purpose, dr.purpose_other,
                dr.status, dr.request_date
         FROM document_request dr
         WHERE dr.request_id = :request_id'
    );
    $statement->execute(['request_id' => $requestId]);
    $request = $statement->fetch();
    if (!$request) {
        http_response_code(404);
        exit('Document request not found.');
    }
    if (in_array($request['status'], ['Cancelled', 'Rejected'], true)) {
        http_response_code(409);
        exit('A cancelled or rejected request cannot be used to prepare a document.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$editMode) {
        http_response_code(400);
        exit('Document fields can only be edited from the staff editor.');
    }
    $usesBirthdayAndCivilStatus = $request['document_type'] !== 'Certificate of Indigency';
    $usesPurpose = $request['document_type'] !== 'Certificate of Residency';

    $fields = [
        'name' => (string) $request['name'],
        'birthday' => (new DateTimeImmutable((string) $request['birthday']))->format('Y-m-d'),
        'age' => (string) $request['age'],
        'purok_address' => (string) $request['purok_address'],
        'civil_status' => (string) $request['civil_status'],
        'purpose' => (string) ($request['purpose'] === 'Other' ? $request['purpose_other'] : $request['purpose']),
        'issue_date' => (new DateTimeImmutable('today', new DateTimeZone(APP_TIMEZONE)))->format('Y-m-d'),
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$editMode) {
        try {
            $pdfContents = staff_generate_document_template_pdf($request, $fields);
            staff_audit('DOCUMENT_TEMPLATE_PRINTED', 'document_request', $requestId, [
                'document_type' => $request['document_type'],
            ]);
            $slug = strtolower(str_replace(' ', '-', $request['document_type']));
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $slug . '-request-' . $requestId . '.pdf"');
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            echo $pdfContents;
            exit;
        } catch (Throwable $exception) {
            error_log('Staff document template generation error: ' . $exception->getMessage());
            http_response_code(500);
            exit('The document could not be prepared. Please try again or contact an administrator.');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        $editableFields = ['name', 'age', 'purok_address', 'issue_date'];
        if ($usesBirthdayAndCivilStatus) {
            array_push($editableFields, 'birthday', 'civil_status');
        }
        if ($usesPurpose) {
            $editableFields[] = 'purpose';
        }
        foreach ($editableFields as $field) {
            $value = $_POST[$field] ?? '';
            $fields[$field] = is_string($value) ? trim($value) : '';
        }
        $birthday = DateTime::createFromFormat('!Y-m-d', $fields['birthday']);
        $issueDate = DateTime::createFromFormat('!Y-m-d', $fields['issue_date']);
        $age = filter_var($fields['age'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 150]]);

        if ($fields['name'] === '' || strlen($fields['name']) > 255) {
            $errors[] = 'Enter a name of 1 to 255 characters.';
        }
        if ($usesBirthdayAndCivilStatus && (!$birthday || $birthday->format('Y-m-d') !== $fields['birthday'] || $birthday > new DateTime('today'))) {
            $errors[] = 'Enter a valid birthday that is not in the future.';
        }
        if ($age === false) {
            $errors[] = 'Age must be a whole number from 0 to 150.';
        }
        if ($fields['purok_address'] === '' || strlen($fields['purok_address']) > 500) {
            $errors[] = 'Enter an address of 1 to 500 characters.';
        }
        if ($usesBirthdayAndCivilStatus && !in_array($fields['civil_status'], ['Single', 'Married', 'Widowed', 'Separated', 'Divorced'], true)) {
            $errors[] = 'Choose a valid civil status.';
        }
        if ($usesPurpose && ($fields['purpose'] === '' || strlen($fields['purpose']) > 255)) {
            $errors[] = 'Enter a purpose of 1 to 255 characters.';
        }
        if (!$issueDate || $issueDate->format('Y-m-d') !== $fields['issue_date']) {
            $errors[] = 'Enter a valid issue date.';
        }

        if ($errors === []) {
            try {
                $fields['age'] = (string) $age;
                $pdfContents = staff_generate_document_template_pdf($request, $fields);
                staff_audit('DOCUMENT_TEMPLATE_PRINTED', 'document_request', $requestId, [
                    'document_type' => $request['document_type'],
                ]);
                $slug = strtolower(str_replace(' ', '-', $request['document_type']));
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="' . $slug . '-request-' . $requestId . '.pdf"');
                header('Cache-Control: private, no-store, max-age=0');
                header('X-Content-Type-Options: nosniff');
                echo $pdfContents;
                exit;
            } catch (Throwable $exception) {
                error_log('Staff document template generation error: ' . $exception->getMessage());
                $errors[] = 'The document could not be prepared. Please try again or contact an administrator.';
            }
        }
    }
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(400);
        exit('A valid document request is required.');
    }
    header('Location: staff_requests.php?type=documents');
    exit;
}

$pageTitle = 'Document templates';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page staff-page">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="staff_dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Staff Portal</small></span></a>
        <?php require __DIR__ . '/includes/staff_nav.php'; ?>
    </aside>
    <section class="dashboard-content">
        <div class="staff-main">
            <div class="staff-hero compact"><div><p class="eyebrow">STAFF-ONLY DOCUMENTS</p><h1>Ready-made templates</h1><p>Resident details are prefilled from the request. Staff may correct this printout without changing the resident's submitted record.</p></div></div>
            <?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>
            <?php if ($request && $editMode): ?>
                <section class="staff-panel document-template-panel">
                    <div class="staff-detail-heading"><h2><?= e($request['document_type']) ?></h2><a href="staff_requests.php?type=documents&amp;id=<?= (int) $request['request_id'] ?>" aria-label="Back to document request">&times;</a></div>
                    <p class="template-privacy-note"><i class="bi bi-lock-fill"></i> This PDF is available only in the staff portal. Changes below apply to this printout only.</p>
                    <form method="post" action="staff_document_templates.php?id=<?= (int) $request['request_id'] ?>&amp;edit=1" target="_blank" class="document-template-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <div class="document-template-fields">
                            <div class="template-field-wide"><label class="form-label" for="template-name">Resident name</label><input class="form-control" id="template-name" name="name" maxlength="255" value="<?= e($fields['name']) ?>" required></div>
                            <?php if ($usesBirthdayAndCivilStatus): ?><div><label class="form-label" for="template-birthday">Birthday</label><input class="form-control" type="date" id="template-birthday" name="birthday" value="<?= e($fields['birthday']) ?>" required></div><?php endif; ?>
                            <div><label class="form-label" for="template-age">Age</label><input class="form-control" type="number" id="template-age" name="age" min="0" max="150" value="<?= e($fields['age']) ?>" required></div>
                            <div class="template-field-wide"><label class="form-label" for="template-address">Purok / address</label><textarea class="form-control" id="template-address" name="purok_address" maxlength="500" rows="2" required><?= e($fields['purok_address']) ?></textarea></div>
                            <?php if ($usesBirthdayAndCivilStatus): ?><div><label class="form-label" for="template-civil-status">Civil status</label><select class="form-control" id="template-civil-status" name="civil_status" required><?php foreach (['Single', 'Married', 'Widowed', 'Separated', 'Divorced'] as $status): ?><option value="<?= e($status) ?>" <?= $fields['civil_status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                            <?php if ($usesPurpose): ?><div><label class="form-label" for="template-purpose">Purpose</label><input class="form-control" id="template-purpose" name="purpose" maxlength="255" value="<?= e($fields['purpose']) ?>" required></div><?php endif; ?>
                            <div><label class="form-label" for="template-issue-date">Issue date</label><input class="form-control" type="date" id="template-issue-date" name="issue_date" value="<?= e($fields['issue_date']) ?>" required></div>
                        </div>
                        <div class="document-template-actions"><a class="btn btn-light" href="staff_requests.php?type=documents&amp;id=<?= (int) $request['request_id'] ?>">Back to request</a><button class="btn btn-primary" type="submit"><i class="bi bi-printer"></i> Prepare printable PDF</button></div>
                    </form>
                </section>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
