<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/document_pickup.php';
require_resident();

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$requestId || $requestId < 1) {
    http_response_code(404);
    exit('Document request not found.');
}

$statement = db()->prepare(
    'SELECT request_id, document_type, name, birthday, age, purok_address, civil_status,
            purpose, purpose_other, status, request_date, date_completed, remarks
     FROM document_request
     WHERE request_id = :request_id AND resident_id = :resident_id'
);
$statement->execute(['request_id' => $requestId, 'resident_id' => (int) $_SESSION['user']['resident_id']]);
$request = $statement->fetch();
if (!$request) {
    http_response_code(404);
    exit('Document request not found.');
}

$statusMessage = (string) ($_SESSION['document_status_message'] ?? '');
$statusError = (string) ($_SESSION['document_status_error'] ?? '');
unset($_SESSION['document_status_message'], $_SESSION['document_status_error']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    if (cancel_pending_document_request($requestId, (int) $_SESSION['user']['resident_id'])) {
        $_SESSION['document_status_message'] = 'Your pending document request was cancelled.';
    } else {
        $_SESSION['document_status_error'] = 'Only pending requests can be cancelled.';
    }
    header('Location: document_request_status.php?id=' . $requestId);
    exit;
}

$requestsStatement = db()->prepare(
    'SELECT request_id, document_type, status, request_date FROM document_request
     WHERE resident_id = :resident_id ORDER BY request_date DESC LIMIT 10'
);
$requestsStatement->execute(['resident_id' => (int) $_SESSION['user']['resident_id']]);
$requests = $requestsStatement->fetchAll();

$statuses = [
    ['Pending', 'Request submitted', 'Your request was received successfully.', 'bi-check-lg'],
    ['Processing', 'Initial verification', 'Your information and requirements are being checked.', 'bi-search'],
    ['Approved', 'Staff review', 'Barangay staff are reviewing your request.', 'bi-person-check'],
    ['Completed', 'Ready for pickup', 'We will notify you when the document is ready.', 'bi-file-earmark-check'],
    ['Released', 'Document released', 'Bring your valid ID when collecting.', 'bi-box-arrow-up-right'],
];
$statusIndex = ['Pending' => 0, 'Processing' => 1, 'Approved' => 2, 'Completed' => 3, 'Released' => 4];
$currentIndex = $statusIndex[$request['status']] ?? 0;
$displayStatus = $request['status'] === 'Completed' ? 'Ready for pickup' : (string) $request['status'];
$isCancelled = $request['status'] === 'Cancelled';

$pageTitle = 'Track your document';
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
            <div class="request-breadcrumb">Resident Services / Request Documents</div><div class="request-title-row"><div><h1>Track your document</h1><p>Follow each step from submission to collection.</p></div></div>
            <?php if ($statusMessage !== ''): ?><div class="alert alert-success"><?= e($statusMessage) ?></div><?php endif; ?>
            <?php if ($statusError !== ''): ?><div class="alert alert-danger"><?= e($statusError) ?></div><?php endif; ?>
            <section class="tracking-summary request-card"><div><small>TRACKING NUMBER</small><h2>REQ-<?= date('Y') ?>-<?= str_pad((string) $request['request_id'], 4, '0', STR_PAD_LEFT) ?></h2><p><?= e($request['document_type']) ?> <b>&bull;</b> Submitted <?= e((new DateTime($request['request_date']))->format('M d, Y')) ?></p></div><span class="tracking-status"><?= e($displayStatus) ?></span><button class="btn btn-primary copy-number" type="button" data-copy-number="REQ-<?= date('Y') ?>-<?= str_pad((string) $request['request_id'], 4, '0', STR_PAD_LEFT) ?>">Copy number <i class="bi bi-copy"></i></button></section>
            <?php if ($request['status'] === 'Completed'): ?><div class="alert alert-info">Your document is ready for pickup. Check the email linked to your resident account for the QR verification code.</div><?php endif; ?>
            <?php if ($request['status'] === 'Pending'): ?><form class="mb-3" method="post" onsubmit="return confirm('Cancel this pending document request?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="btn btn-outline-danger" type="submit">Cancel pending request</button></form><?php endif; ?>
            <div class="tracking-columns">
                <section class="request-card timeline-card"><div class="card-heading"><div><h2>Request timeline</h2><p>We will update this timeline when your request changes.</p></div></div><div class="timeline-notice"><i class="bi bi-exclamation-lg"></i><div><strong><?= $isCancelled ? 'This request was cancelled' : ($request['status'] === 'Processing' ? 'Your request is being reviewed' : e($displayStatus)) ?></strong><small><?= $isCancelled ? 'No further processing will take place.' : ($request['status'] === 'Processing' ? 'Barangay staff are checking the details you submitted.' : 'Your request status is updated below.') ?></small></div></div><div class="request-timeline"><?php foreach ($statuses as $index => $status): $complete = !$isCancelled && ($index < $currentIndex || ($index === $currentIndex && $request['status'] !== 'Processing')); $active = !$isCancelled && $index === $currentIndex; ?><div class="timeline-event <?= $complete ? 'complete' : '' ?> <?= $active ? 'active' : '' ?>"><span class="timeline-icon"><i class="bi <?= $status[3] ?>"></i></span><div><strong><?= e($status[1]) ?></strong><small><?= e($status[2]) ?></small></div><em><?= $complete ? 'Complete' : ($active ? 'In progress' : 'Pending') ?></em></div><?php endforeach; ?></div><?php if ($request['remarks']): ?><div class="timeline-remarks"><strong>Remarks</strong><span><?= e($request['remarks']) ?></span></div><?php endif; ?></section>
                <aside class="request-card active-requests"><div class="card-heading"><div><h2>Active document requests</h2><p>Choose a request to see its progress.</p></div></div><div class="tracking-search"><i class="bi bi-search"></i><input type="search" placeholder="Search tracking number" aria-label="Search tracking number"></div><?php foreach ($requests as $item): ?><a class="active-request <?= (int) $item['request_id'] === (int) $request['request_id'] ? 'selected' : '' ?>" href="document_request_status.php?id=<?= (int) $item['request_id'] ?>"><span class="active-request-icon"><i class="bi bi-file-earmark-text"></i></span><span><strong><?= e($item['document_type']) ?></strong><small>REQ-<?= date('Y') ?>-<?= str_pad((string) $item['request_id'], 4, '0', STR_PAD_LEFT) ?></small><em><?= e($item['status']) ?></em><small>Updated <?= e((new DateTime($item['request_date']))->format('M d')) ?></small></span><i class="bi bi-chevron-right"></i></a><?php endforeach; ?><div class="request-help"><strong>Questions about your request?</strong><small>Contact the barangay office and provide your tracking number.</small><a href="mailto:barangay.inosluban@example.com">Contact office <i class="bi bi-arrow-right"></i></a></div></aside>
            </div>
        </div>
        <footer class="dashboard-footer"><span>Connect. Collaborate. Grow Together.</span><small>(c) 2026 Barangay Inosluban. All rights reserved.</small></footer>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
