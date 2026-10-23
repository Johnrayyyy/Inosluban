<?php
$currentModule = basename($_SERVER['PHP_SELF']);
$activeModule = match ($currentModule) {
    'blotter_status.php' => 'blotter.php',
    'document_request_status.php' => 'document_request.php',
    default => $currentModule,
};
$navItems = [
    ['dashboard.php', 'bi-house-fill', 'Home'],
    ['document_request.php', 'bi-file-earmark-text', 'Request Document'],
    ['blotter.php', 'bi-shield-check', 'Blotter'],
    ['appointment.php', 'bi-calendar3', 'Appointment'],
    ['announcement.php', 'bi-megaphone', 'Announcements'],
];
?>
<nav class="dashboard-nav" aria-label="Resident portal navigation">
    <?php foreach ($navItems as [$href, $icon, $label]): ?>
        <a class="<?= $activeModule === $href ? 'active' : '' ?>" href="<?= e($href) ?>"><i class="bi <?= e($icon) ?>"></i><?= e($label) ?></a>
    <?php endforeach; ?>
    <span class="dashboard-nav-label">MORE</span>
    <a class="<?= $activeModule === 'profile.php' ? 'active' : '' ?>" href="profile.php"><i class="bi bi-person"></i>My Profile</a>
    <a href="#settings"><i class="bi bi-gear"></i>Settings</a>
    <a href="logout.php"><i class="bi bi-box-arrow-right"></i>Logout</a>
</nav>
