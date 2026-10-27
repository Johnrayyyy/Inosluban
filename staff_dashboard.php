<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/staff.php';
require_staff();
$isStaff = ($_SESSION['user']['role'] ?? '') === 'staff';
$timeLog = $isStaff ? staff_clock_in((int) $_SESSION['user']['id']) : null;
$counts = [
    'documents' => (int) db()->query("SELECT COUNT(*) FROM document_request WHERE status = 'Pending'")->fetchColumn(),
    'blotter' => (int) db()->query("SELECT COUNT(*) FROM blotter WHERE status IN ('Pending', 'Under Review', 'In Progress')")->fetchColumn(),
    'appointments' => (int) db()->query("SELECT COUNT(*) FROM appointment WHERE status = 'Pending'")->fetchColumn(),
];
$pageTitle = 'Staff dashboard';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page staff-page">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="staff_dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Staff Portal</small></span></a>
        <?php require __DIR__ . '/includes/staff_nav.php'; ?>
    </aside>
    <section class="dashboard-content">
        <header class="dashboard-topbar"><div class="dashboard-search"><i class="bi bi-search"></i><input type="search" placeholder="Search requests..." aria-label="Search requests"></div><div class="dashboard-user"><i class="bi bi-bell-fill"></i><span class="user-avatar"><i class="bi bi-person-fill"></i></span><span><strong><?= e($_SESSION['user']['full_name']) ?></strong><small><?= e(ucfirst($_SESSION['user']['role'])) ?></small></span></div></header>
        <div class="staff-main">
            <div class="staff-hero"><div><p class="eyebrow">STAFF PORTAL</p><h1>Good day, <?= e(explode(' ', trim($_SESSION['user']['full_name']))[0]) ?>!</h1><p>Review and process resident requests securely.</p></div><?php if ($isStaff && $timeLog): ?><?php $clockIn = new DateTimeImmutable((string) $timeLog['clock_in']); ?><div class="staff-timer"><small>TIME SHEET · CLOCKED IN</small><strong data-staff-timer data-clock-in="<?= e($clockIn->format('U')) ?>" data-server-now="<?= e((string) time()) ?>">00:00:00</strong><span>Session started <?= e($clockIn->setTimezone(new DateTimeZone(APP_TIMEZONE))->format('M j, Y g:i A')) ?> Philippine Time</span></div><?php endif; ?></div>
            <div class="staff-stat-grid">
                <a class="staff-stat-card" href="staff_requests.php?type=documents"><i class="bi bi-file-earmark-text"></i><span>Document requests<strong><?= $counts['documents'] ?></strong></span></a>
                <a class="staff-stat-card" href="staff_requests.php?type=blotter"><i class="bi bi-shield-check"></i><span>Blotter cases<strong><?= $counts['blotter'] ?></strong></span></a>
                <a class="staff-stat-card" href="staff_requests.php?type=appointments"><i class="bi bi-calendar3"></i><span>Appointments<strong><?= $counts['appointments'] ?></strong></span></a>
            </div>
            <section class="staff-panel"><div class="staff-panel-heading"><div><p class="eyebrow">WORK QUEUE</p><h2>Approval modules</h2></div><span>Every action is recorded in the audit log.</span></div><div class="staff-module-grid"><a href="staff_requests.php?type=documents"><i class="bi bi-file-earmark-check"></i><strong>Document request approvals</strong><small>Verify residents and approve, process, or reject documents.</small></a><a href="staff_requests.php?type=blotter"><i class="bi bi-shield-check"></i><strong>Blotter approval</strong><small>Review incidents and record resolutions.</small></a><a href="staff_requests.php?type=appointments"><i class="bi bi-calendar-check"></i><strong>Appointment approval</strong><small>Confirm or reject requests for official schedules.</small></a></div></section>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
