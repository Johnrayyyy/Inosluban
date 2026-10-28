<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/document_pickup.php';
require_once __DIR__ . '/includes/document_request_email.php';
require_once __DIR__ . '/config/mailer.php';
require_resident();

$residentId = (int) $_SESSION['user']['resident_id'];
$documentTypes = ['Barangay Clearance', 'Certificate of Indigency', 'Certificate of Residency'];
$purposes = [
    'Travel Abroad', 'Legal Purpose', 'Loan Application', 'Financial/Medical',
    'Police/NBI Clearance', 'Postal ID', 'Local Employment', 'School Requirement',
    'Water/Electrical Installation', 'Personal Identification', 'Other',
];
$civilStatuses = ['Single', 'Married', 'Widowed', 'Separated', 'Divorced'];
$defaults = [
    'document_type' => '', 'purpose' => '', 'purpose_other' => '', 'name' => '',
    'birthday' => '', 'age' => '', 'purok_address' => '', 'civil_status' => '',
];
$draft = array_merge($defaults, $_SESSION['document_request_draft'] ?? []);
$errors = [];
$success = (string) ($_SESSION['document_request_success'] ?? '');
$requestError = (string) ($_SESSION['document_request_error'] ?? '');
unset($_SESSION['document_request_success']);
unset($_SESSION['document_request_error']);
$step = max(1, min(3, (int) ($_GET['step'] ?? $_POST['step'] ?? 1)));

function document_request_validate(array $values, int $step, array $documentTypes, array $purposes, array $civilStatuses): array
{
    $errors = [];
    if ($step === 1) {
        if (!in_array($values['document_type'], $documentTypes, true)) {
            $errors[] = 'Please choose a document type.';
        }
        if (!in_array($values['purpose'], $purposes, true)) {
            $errors[] = 'Please choose a purpose.';
        }
        if ($values['purpose'] === 'Other' && trim($values['purpose_other']) === '') {
            $errors[] = 'Please specify the other purpose.';
        }
    }
    if ($step === 2) {
        if (trim($values['name']) === '' || trim($values['purok_address']) === '') {
            $errors[] = 'Name and purok/address are required.';
        }
        $birthday = DateTime::createFromFormat('!Y-m-d', $values['birthday']);
        if (!$birthday || $birthday->format('Y-m-d') !== $values['birthday'] || $birthday > new DateTime('today')) {
            $errors[] = 'Please enter a valid birthday.';
        }
        if (filter_var($values['age'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 150]]) === false) {
            $errors[] = 'Age must be a whole number from 0 to 150.';
        }
        if (!in_array($values['civil_status'], $civilStatuses, true)) {
            $errors[] = 'Please choose a valid civil status.';
        }
    }
    return $errors;
}

try {
    $residentStatement = db()->prepare(
        'SELECT first_name, middle_name, last_name, birthdate, complete_address, purok, civil_status, email
         FROM resident WHERE resident_id = :resident_id'
    );
    $residentStatement->execute(['resident_id' => $residentId]);
    $resident = $residentStatement->fetch();
    if (!$resident) {
        http_response_code(404);
        exit('Resident profile not found.');
    }

    if (!isset($_SESSION['document_request_draft'])) {
        $draft['name'] = trim(implode(' ', array_filter([$resident['first_name'], $resident['middle_name'], $resident['last_name']])));
        $draft['birthday'] = (string) $resident['birthdate'];
        $draft['purok_address'] = trim(implode(', ', array_filter([$resident['purok'], $resident['complete_address']])));
        $draft['civil_status'] = (string) ($resident['civil_status'] ?? '');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        $action = (string) ($_POST['action'] ?? 'next');
        if ($action === 'cancel_request') {
            $requestId = filter_var($_POST['request_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$requestId || $requestId < 1) {
                $_SESSION['document_request_error'] = 'The document request could not be found.';
            } else {
                if (cancel_pending_document_request($requestId, $residentId)) {
                    $_SESSION['document_request_success'] = 'Your pending document request was cancelled.';
                } else {
                    $_SESSION['document_request_error'] = 'Only pending requests can be cancelled.';
                }
            }
            header('Location: document_request.php');
            exit;
        }
        foreach (array_keys($draft) as $field) {
            if (array_key_exists($field, $_POST)) {
                $draft[$field] = trim((string) $_POST[$field]);
            }
        }
        if ($draft['purpose'] !== 'Other') {
            $draft['purpose_other'] = '';
        }
        $errors = document_request_validate($draft, $step, $documentTypes, $purposes, $civilStatuses);

        if ($errors === []) {
            $_SESSION['document_request_draft'] = $draft;
            if ($action === 'back') {
                $step = max(1, $step - 1);
            } elseif ($action === 'next') {
                $step = min(3, $step + 1);
            } elseif ($action === 'submit' && $step === 3) {
                $statement = db()->prepare(
                    'INSERT INTO document_request (
                        resident_id, document_type, name, birthday, age, purok_address,
                        civil_status, purpose, purpose_other
                    ) VALUES (
                        :resident_id, :document_type, :name, :birthday, :age, :purok_address,
                        :civil_status, :purpose, NULLIF(:purpose_other, \'\')
                    ) RETURNING request_id, document_type, name, purpose, purpose_other, request_date'
                );
                $statement->execute([
                    'resident_id' => $residentId, 'document_type' => $draft['document_type'],
                    'name' => $draft['name'], 'birthday' => $draft['birthday'],
                    'age' => (int) $draft['age'], 'purok_address' => $draft['purok_address'],
                    'civil_status' => $draft['civil_status'], 'purpose' => $draft['purpose'],
                    'purpose_other' => $draft['purpose_other'],
                ]);
                $request = $statement->fetch();
                $trackingUrl = APP_BASE_URL . '/document_request_status.php?id=' . (int) $request['request_id'];
                $emailSent = false;
                $emailAddress = trim((string) ($resident['email'] ?? ''));
                if ($emailAddress !== '' && filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
                    $emailSent = sendEmail(
                        $emailAddress,
                        'Document request confirmation #' . (int) $request['request_id'],
                        document_request_email_html($request, $trackingUrl)
                    );
                }
                if (!$emailSent) {
                    error_log('Document request confirmation email was not sent for request #' . (int) $request['request_id']);
                }
                unset($_SESSION['document_request_draft']);
                $_SESSION['document_request_success'] = $emailSent
                    ? 'Request submitted. A confirmation email with a tracking link was sent.'
                    : 'Request submitted. Email delivery is unavailable, but you can track it below.';
                header('Location: document_request.php');
                exit;
            }
        }
    }

    $historyStatement = db()->prepare(
        'SELECT request_id, document_type, purpose, purpose_other, status, request_date
         FROM document_request WHERE resident_id = :resident_id ORDER BY request_date DESC LIMIT 10'
    );
    $historyStatement->execute(['resident_id' => $residentId]);
    $requests = $historyStatement->fetchAll();
} catch (PDOException $exception) {
    error_log('Document request database error: ' . $exception->getMessage());
    http_response_code(500);
    exit('The document request service is temporarily unavailable.');
}

$pageTitle = 'Request documents';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page request-module" data-theme="light">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Community Portal</small></span></a>
        <?php require __DIR__ . '/includes/resident_nav.php'; ?>
        <div class="dashboard-sidebar-footer"><img src="assets/images/logo.png" alt=""><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small></span></div>
    </aside>
    <section class="dashboard-content request-content">
        <header class="dashboard-topbar request-topbar"><div class="dashboard-search"><i class="bi bi-search"></i><input type="search" placeholder="Search services, announcements, or information..." aria-label="Search portal"></div><div class="dashboard-user"><button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch to dark mode"><i class="bi bi-moon-stars"></i></button><span class="user-avatar"><i class="bi bi-person-fill"></i></span><span><strong><?= e($_SESSION['user']['full_name']) ?></strong><small>Resident Account</small></span></div></header>
        <div class="request-main">
            <div class="request-breadcrumb">Resident Services / Request Documents</div>
            <div class="request-title-row"><div><h1>Request a Document</h1><p>Apply for barangay documents online and track each request in one place.</p></div></div>
            <div class="request-banner"><span class="request-banner-icon"><i class="bi bi-check-lg"></i></span><div><strong>Skip the queue. Request online.</strong><small>Choose a document, complete the details, and follow its status from your dashboard.</small></div></div>
            <?php if ($errors !== []): ?><div class="alert alert-danger request-alert"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <?php if ($success !== ''): ?><div class="alert alert-success request-alert"><?= e($success) ?></div><?php endif; ?>
            <?php if ($requestError !== ''): ?><div class="alert alert-danger request-alert"><?= e($requestError) ?></div><?php endif; ?>
            <div class="request-columns">
                <section class="request-card wizard-card">
                    <div class="card-heading"><div><h2>New document request</h2><p>Fields marked with * are required.</p></div></div>
                    <div class="wizard-progress">
                        <?php foreach ([1 => 'Document', 2 => 'Details', 3 => 'Review'] as $number => $label): ?><a class="wizard-step <?= $step === $number ? 'active' : ($step > $number ? 'done' : '') ?>" href="document_request.php?step=<?= $number ?>"><span><?= $step > $number ? '&#10003;' : $number ?></span><?= $label ?></a><?php endforeach; ?>
                    </div>
                    <form method="post" action="document_request.php?step=<?= $step ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="step" value="<?= $step ?>">
                        <?php if ($step === 1): ?>
                            <h3 class="wizard-section-title">Select document type <b>*</b></h3>
                            <div class="choice-list">
                                <?php foreach ($documentTypes as $type): ?><label class="choice-card <?= $draft['document_type'] === $type ? 'selected' : '' ?>"><input type="radio" name="document_type" value="<?= e($type) ?>" <?= $draft['document_type'] === $type ? 'checked' : '' ?> required><span class="choice-radio"></span><span><strong><?= e($type) ?></strong><small><?= $type === 'Barangay Clearance' ? 'For employment, business, or general purposes' : ($type === 'Certificate of Residency' ? 'Proof that you live in this barangay' : 'For eligible assistance and applications') ?></small></span><em>Selected</em></label><?php endforeach; ?>
                            </div>
                            <label class="form-label wizard-label" for="purpose">Purpose of request <b>*</b></label>
                            <select class="form-control request-input" id="purpose" name="purpose" required><option value="">Choose a purpose</option><?php foreach ($purposes as $purpose): ?><option value="<?= e($purpose) ?>" <?= $draft['purpose'] === $purpose ? 'selected' : '' ?>><?= e($purpose) ?></option><?php endforeach; ?></select>
                            <label class="form-label wizard-label" for="purpose_other">Other purpose (if applicable)</label><input class="form-control request-input" id="purpose_other" name="purpose_other" value="<?= e($draft['purpose_other']) ?>" placeholder="Specify your purpose">
                        <?php elseif ($step === 2): ?>
                            <h3 class="wizard-section-title">Personal details</h3>
                            <div class="details-grid">
                                <div class="detail-wide"><label class="form-label" for="name">Name <b>*</b></label><input class="form-control request-input" id="name" name="name" value="<?= e($draft['name']) ?>" required></div>
                                <div><label class="form-label" for="birthday">Birthday <b>*</b></label><input class="form-control request-input" type="date" id="birthday" name="birthday" value="<?= e($draft['birthday']) ?>" required></div>
                                <div><label class="form-label" for="age">Age <b>*</b></label><input class="form-control request-input" type="number" id="age" name="age" min="0" max="150" value="<?= e($draft['age']) ?>" required></div>
                                <div class="detail-wide"><label class="form-label" for="purok_address">Purok / Address <b>*</b></label><input class="form-control request-input" id="purok_address" name="purok_address" value="<?= e($draft['purok_address']) ?>" required></div>
                                <div><label class="form-label" for="civil_status">Civil status <b>*</b></label><select class="form-control request-input" id="civil_status" name="civil_status" required><option value="">Choose status</option><?php foreach ($civilStatuses as $status): ?><option value="<?= e($status) ?>" <?= $draft['civil_status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></div>
                            </div>
                        <?php else: ?>
                            <h3 class="wizard-section-title">Review your request</h3><p class="review-help">Please double-check the information below before submitting.</p>
                            <div class="review-grid"><div><small>Document type</small><strong><?= e($draft['document_type']) ?></strong></div><div><small>Purpose</small><strong><?= e($draft['purpose'] === 'Other' ? $draft['purpose_other'] : $draft['purpose']) ?></strong></div><div><small>Name</small><strong><?= e($draft['name']) ?></strong></div><div><small>Birthday / Age</small><strong><?= e($draft['birthday']) ?> / <?= e($draft['age']) ?></strong></div><div><small>Purok / Address</small><strong><?= e($draft['purok_address']) ?></strong></div><div><small>Civil status</small><strong><?= e($draft['civil_status']) ?></strong></div></div>
                        <?php endif; ?>
                        <div class="wizard-actions"><button class="btn btn-light request-back" type="submit" name="action" value="back" <?= $step === 1 ? 'disabled' : '' ?>>Back</button><button class="btn btn-primary request-next" type="submit" name="action" value="<?= $step === 3 ? 'submit' : 'next' ?>"><?= $step === 3 ? 'Submit request' : 'Continue' ?> <i class="bi bi-arrow-right"></i></button></div>
                    </form>
                </section>
                <aside class="request-card active-requests"><div class="card-heading"><div><h2>My active requests</h2><p>Select a request to view its timeline.</p></div><span class="active-count"><?= count($requests) ?> active</span></div>
                    <?php foreach ($requests as $request): ?><a class="active-request <?= $request === $requests[0] ? 'selected' : '' ?>" href="document_request_status.php?id=<?= (int) $request['request_id'] ?>"><span class="active-request-icon"><i class="bi bi-file-earmark-text"></i></span><span><strong><?= e($request['document_type']) ?></strong><small>REQ-<?= date('Y') ?>-<?= str_pad((string) $request['request_id'], 4, '0', STR_PAD_LEFT) ?></small><em><?= e($request['status']) ?></em><small>Last update <?= e((new DateTime($request['request_date']))->format('M d, Y')) ?></small></span><i class="bi bi-chevron-right"></i></a><?php if ($request['status'] === 'Pending'): ?><form class="request-cancel-form" method="post" action="document_request.php" onsubmit="return confirm('Cancel this pending document request?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="cancel_request"><input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>"><button class="btn btn-sm request-cancel-button" type="submit">Cancel request</button></form><?php endif; ?><?php endforeach; ?>
                    <?php if ($requests === []): ?><p class="empty-requests">No requests yet.</p><?php endif; ?><div class="request-help"><strong>Need another document?</strong><small>Start a new request anytime. Keep your tracking number for follow-up.</small></div>
                </aside>
            </div>
        </div>
        <footer class="dashboard-footer"><span>Connect. Collaborate. Grow Together.</span><small>(c) 2026 Barangay Inosluban. All rights reserved.</small></footer>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
