<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_once __DIR__ . '/includes/document_templates.php';
require_staff();
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');

$requestId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
$request = null;
$fields = [];
$errors = [];

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

    $fields = [
        'name' => (string) $request['name'],
        'birthday' => (new DateTimeImmutable((string) $request['birthday']))->format('Y-m-d'),
        'age' => (string) $request['age'],
        'purok_address' => (string) $request['purok_address'],
        'civil_status' => (string) $request['civil_status'],
        'purpose' => (string) ($request['purpose'] === 'Other' ? $request['purpose_other'] : $request['purpose']),
        'issue_date' => (new DateTimeImmutable('today', new DateTimeZone(APP_TIMEZONE)))->format('Y-m-d'),
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        foreach (array_keys($fields) as $field) {
            $value = $_POST[$field] ?? '';
            $fields[$field] = is_string($value) ? trim($value) : '';
        }
        $birthday = DateTime::createFromFormat('!Y-m-d', $fields['birthday']);
        $issueDate = DateTime::createFromFormat('!Y-m-d', $fields['issue_date']);
        $age = filter_var($fields['age'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 150]]);

        if ($fields['name'] === '' || strlen($fields['name']) > 255) {
            $errors[] = 'Enter a name of 1 to 255 characters.';
        }
        if (!$birthday || $birthday->format('Y-m-d') !== $fields['birthday'] || $birthday > new DateTime('today')) {
            $errors[] = 'Enter a valid birthday that is not in the future.';
        }
        if ($age === false) {
            $errors[] = 'Age must be a whole number from 0 to 150.';
        }
        if ($fields['purok_address'] === '' || strlen($fields['purok_address']) > 500) {
            $errors[] = 'Enter an address of 1 to 500 characters.';
        }
        if (!in_array($fields['civil_status'], ['Single', 'Married', 'Widowed', 'Separated', 'Divorced'], true)) {
            $errors[] = 'Choose a valid civil status.';
        }
        if ($fields['purpose'] === '' || strlen($fields['purpose']) > 255) {
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
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(400);
    exit('A valid document request is required.');
}

$requests = [];
if ($request === null) {
    $requests = db()->query(
        "SELECT request_id, document_type, name, status, request_date
         FROM document_request
         WHERE status NOT IN ('Cancelled', 'Rejected')
         ORDER BY request_date DESC
         LIMIT 100"
    )->fetchAll();
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
            <?php if ($request): ?>
                <section class="staff-panel document-template-panel">
                    <div class="staff-detail-heading"><h2><?= e($request['document_type']) ?></h2><a href="staff_document_templates.php" aria-label="Back to document requests">&times;</a></div>
                    <p class="template-privacy-note"><i class="bi bi-lock-fill"></i> This PDF is available only in the staff portal. Changes below apply to this printout only.</p>
                    <form method="post" class="document-template-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <div class="document-template-fields">
                            <div class="template-field-wide"><label class="form-label" for="template-name">Resident name</label><input class="form-control" id="template-name" name="name" maxlength="255" value="<?= e($fields['name']) ?>" required></div>
                            <div><label class="form-label" for="template-birthday">Birthday</label><input class="form-control" type="date" id="template-birthday" name="birthday" value="<?= e($fields['birthday']) ?>" required></div>
                            <div><label class="form-label" for="template-age">Age</label><input class="form-control" type="number" id="template-age" name="age" min="0" max="150" value="<?= e($fields['age']) ?>" required></div>
                            <div class="template-field-wide"><label class="form-label" for="template-address">Purok / address</label><textarea class="form-control" id="template-address" name="purok_address" maxlength="500" rows="2" required><?= e($fields['purok_address']) ?></textarea></div>
                            <div><label class="form-label" for="template-civil-status">Civil status</label><select class="form-control" id="template-civil-status" name="civil_status" required><?php foreach (['Single', 'Married', 'Widowed', 'Separated', 'Divorced'] as $status): ?><option value="<?= e($status) ?>" <?= $fields['civil_status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></div>
                            <div><label class="form-label" for="template-purpose">Purpose</label><input class="form-control" id="template-purpose" name="purpose" maxlength="255" value="<?= e($fields['purpose']) ?>" required></div>
                            <div><label class="form-label" for="template-issue-date">Issue date</label><input class="form-control" type="date" id="template-issue-date" name="issue_date" value="<?= e($fields['issue_date']) ?>" required></div>
                        </div>
                        <div class="document-template-actions"><a class="btn btn-light" href="staff_requests.php?type=documents&amp;id=<?= (int) $request['request_id'] ?>">Back to request</a><button class="btn btn-primary" type="submit"><i class="bi bi-printer"></i> Prepare printable PDF</button></div>
                    </form>
                </section>
            <?php else: ?>
                <section class="staff-panel">
                    <h2 class="h5">Choose a document request</h2>
                    <p>Original PDF templates are stored privately and can only be opened by staff for printing.</p>
                    <div class="table-responsive"><table class="staff-table"><thead><tr><th>Reference</th><th>Resident</th><th>Document</th><th>Status</th><th>Requested</th><th></th></tr></thead><tbody>
                    <?php foreach ($requests as $item): ?><tr><td>#<?= (int) $item['request_id'] ?></td><td><?= e($item['name']) ?></td><td><?= e($item['document_type']) ?></td><td><?= e($item['status']) ?></td><td><?= e((new DateTime($item['request_date']))->format('M j, Y g:i A')) ?></td><td><a class="btn btn-sm btn-outline-primary" href="staff_document_templates.php?id=<?= (int) $item['request_id'] ?>">Edit fields</a></td></tr><?php endforeach; ?>
                    <?php if ($requests === []): ?><tr><td colspan="6">No eligible document requests found.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
