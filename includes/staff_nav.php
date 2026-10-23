<?php
$staffModule = basename($_SERVER['PHP_SELF']);
$staffCounts = [
    'document_request.php' => (int) db()->query("SELECT COUNT(*) FROM document_request WHERE status = 'Pending'")->fetchColumn(),
    'blotter.php' => (int) db()->query("SELECT COUNT(*) FROM blotter WHERE status IN ('Pending', 'Under Review', 'In Progress')")->fetchColumn(),
    'appointment.php' => (int) db()->query("SELECT COUNT(*) FROM appointment WHERE status = 'Pending'")->fetchColumn(),
];
?>
<nav class="dashboard-nav staff-nav" aria-label="Staff portal navigation">
    <a class="<?= $staffModule === 'staff_dashboard.php' ? 'active' : '' ?>" href="staff_dashboard.php"><i class="bi bi-house-fill"></i>Dashboard</a>
    <a class="<?= ($_GET['type'] ?? '') === 'documents' ? 'active' : '' ?>" href="staff_requests.php?type=documents"><i class="bi bi-file-earmark-text"></i>Document Requests<?php if ($staffCounts['document_request.php'] > 0): ?><b class="staff-nav-badge"><?= $staffCounts['document_request.php'] ?></b><?php endif; ?></a>
    <a class="<?= ($_GET['type'] ?? '') === 'blotter' ? 'active' : '' ?>" href="staff_requests.php?type=blotter"><i class="bi bi-shield-check"></i>Blotter<?php if ($staffCounts['blotter.php'] > 0): ?><b class="staff-nav-badge"><?= $staffCounts['blotter.php'] ?></b><?php endif; ?></a>
    <a class="<?= ($_GET['type'] ?? '') === 'appointments' ? 'active' : '' ?>" href="staff_requests.php?type=appointments"><i class="bi bi-calendar3"></i>Appointments<?php if ($staffCounts['appointment.php'] > 0): ?><b class="staff-nav-badge"><?= $staffCounts['appointment.php'] ?></b><?php endif; ?></a>
    <span class="dashboard-nav-label">MORE</span>
    <?php if (($_SESSION['user']['role'] ?? '') === 'admin'): ?><a class="<?= $staffModule === 'admin_staff.php' ? 'active' : '' ?>" href="admin_staff.php"><i class="bi bi-person-plus"></i>Manage Staff</a><?php endif; ?>
    <?php if (($_SESSION['user']['role'] ?? '') === 'admin'): ?><a class="<?= $staffModule === 'admin_audit.php' ? 'active' : '' ?>" href="admin_audit.php"><i class="bi bi-shield-lock"></i>Audit &amp; Time Logs</a><a class="<?= $staffModule === 'admin_announcements.php' ? 'active' : '' ?>" href="admin_announcements.php"><i class="bi bi-megaphone"></i>Announcements</a><a class="<?= $staffModule === 'admin_weather.php' ? 'active' : '' ?>" href="admin_weather.php"><i class="bi bi-cloud-sun"></i>Weather Advisory</a><a class="<?= $staffModule === 'admin_analytics.php' ? 'active' : '' ?>" href="admin_analytics.php"><i class="bi bi-bar-chart"></i>Data Analytics</a><?php endif; ?>
    <a href="logout.php"><i class="bi bi-box-arrow-right"></i>Logout</a>
</nav>
