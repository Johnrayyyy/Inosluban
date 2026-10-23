<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_resident();

$residentId = (int) $_SESSION['user']['resident_id'];
$reportId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$reportId || $reportId < 1) {
    http_response_code(404);
    exit('Blotter report not found.');
}

try {
    $statement = db()->prepare(
        'SELECT report_id, report_title, category, location, latitude, longitude,
                description, report_date, date_resolved, status, resolution_notes
         FROM blotter
         WHERE report_id = :report_id AND resident_id = :resident_id'
    );
    $statement->execute(['report_id' => $reportId, 'resident_id' => $residentId]);
    $report = $statement->fetch();
    if (!$report) {
        http_response_code(404);
        exit('Blotter report not found.');
    }

    $reportsStatement = db()->prepare(
        'SELECT report_id, report_title, category, report_date, status
         FROM blotter WHERE resident_id = :resident_id ORDER BY report_date DESC LIMIT 20'
    );
    $reportsStatement->execute(['resident_id' => $residentId]);
    $reports = $reportsStatement->fetchAll();
} catch (PDOException $exception) {
    error_log('Blotter tracking database error: ' . $exception->getMessage());
    http_response_code(500);
    exit('The blotter tracking service is temporarily unavailable.');
}

$statuses = [
    ['Pending', 'Report submitted', 'Your report was received by the barangay office.', 'bi-check-lg'],
    ['Under Review', 'Initial review', 'Barangay staff are reviewing the report details.', 'bi-search'],
    ['In Progress', 'Action in progress', 'The barangay office is addressing the reported concern.', 'bi-person-gear'],
    ['Resolved', 'Report resolved', 'The reported concern has been addressed.', 'bi-check-circle'],
    ['Closed', 'Report closed', 'This report has been completed and closed.', 'bi-lock'],
];
$statusIndex = ['Pending' => 0, 'Under Review' => 1, 'In Progress' => 2, 'Resolved' => 3, 'Closed' => 4];
$currentIndex = $statusIndex[$report['status']] ?? 0;
$isRejected = $report['status'] === 'Rejected';
$pageTitle = 'Track blotter report';
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
            <div class="request-breadcrumb">Resident Services / Blotter / Track report</div>
            <div class="request-title-row"><div><h1>Track your blotter report</h1><p>Follow your report from submission to resolution.</p></div></div>
            <section class="tracking-summary request-card"><div><small>REPORT NUMBER</small><h2>BLT-<?= date('Y') ?>-<?= str_pad((string) $report['report_id'], 4, '0', STR_PAD_LEFT) ?></h2><p><?= e($report['report_title']) ?> <b>&bull;</b> Submitted <?= e((new DateTime($report['report_date']))->format('M d, Y')) ?></p></div><span class="tracking-status"><?= e($report['status']) ?></span></section>
            <div class="tracking-columns">
                <section class="request-card timeline-card"><div class="card-heading"><div><h2>Report timeline</h2><p>We will update this timeline when the report status changes.</p></div></div><div class="timeline-notice"><i class="bi <?= $isRejected ? 'bi-x-lg' : 'bi-exclamation-lg' ?>"></i><div><strong><?= $isRejected ? 'Your report was not accepted' : e($report['status']) ?></strong><small><?= $isRejected ? 'Please contact the barangay office for more information.' : 'Your report status is shown below.' ?></small></div></div><div class="request-timeline"><?php foreach ($statuses as $index => $status): $complete = !$isRejected && $index < $currentIndex; $active = !$isRejected && $index === $currentIndex; ?><div class="timeline-event <?= $complete ? 'complete' : '' ?> <?= $active ? 'active' : '' ?>"><span class="timeline-icon"><i class="bi <?= $status[3] ?>"></i></span><div><strong><?= e($status[1]) ?></strong><small><?= e($status[2]) ?></small></div><em><?= $complete ? 'Complete' : ($active ? 'In progress' : 'Pending') ?></em></div><?php endforeach; ?></div><?php if ($report['resolution_notes']): ?><div class="timeline-remarks"><strong>Resolution notes</strong><span><?= e($report['resolution_notes']) ?></span></div><?php endif; ?><div class="timeline-remarks"><strong>Report details</strong><span><?= e($report['category']) ?> · <?= e($report['location'] ?: 'Location not specified') ?></span><span><?= e($report['description']) ?></span></div></section>
                <aside class="request-card active-requests"><div class="card-heading"><div><h2>Recent blotter reports</h2><p>Choose a report to view its progress.</p></div></div><?php foreach ($reports as $item): ?><a class="active-request <?= (int) $item['report_id'] === (int) $report['report_id'] ? 'selected' : '' ?>" href="blotter_status.php?id=<?= (int) $item['report_id'] ?>"><span class="active-request-icon"><i class="bi bi-shield-check"></i></span><span><strong><?= e($item['report_title']) ?></strong><small>BLT-<?= date('Y') ?>-<?= str_pad((string) $item['report_id'], 4, '0', STR_PAD_LEFT) ?></small><em><?= e($item['status']) ?></em><small><?= e((new DateTime($item['report_date']))->format('M d, Y')) ?></small></span><i class="bi bi-chevron-right"></i></a><?php endforeach; ?></aside>
            </div>
        </div>
        <footer class="dashboard-footer"><span>Connect. Collaborate. Grow Together.</span><small>(c) 2026 Barangay Inosluban. All rights reserved.</small></footer>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
