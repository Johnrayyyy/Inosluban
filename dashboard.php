<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/resident_notifications.php';
require_login();
if (in_array($_SESSION['user']['role'] ?? '', ['staff', 'admin'], true)) {
    header('Location: staff_dashboard.php');
    exit;
}

try {
    $announcementsStatement = db()->query(
        "SELECT announcement_id, title, publication_date
         FROM announcement
         WHERE status = 'Published'
           AND (publication_date IS NULL OR publication_date <= CURRENT_TIMESTAMP)
           AND (expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP)
         ORDER BY COALESCE(publication_date, CURRENT_TIMESTAMP) DESC
         LIMIT 3"
    );
    $latestAnnouncements = $announcementsStatement->fetchAll();
    $residentNotifications = resident_notifications((int) $_SESSION['user']['resident_id']);
} catch (PDOException $exception) {
    error_log('Dashboard announcements database error: ' . $exception->getMessage());
    http_response_code(500);
    exit('The dashboard is temporarily unavailable.');
}

$pageTitle = 'Resident dashboard';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="dashboard.php">
            <img src="assets/images/logo.png" alt="Barangay Inosluban logo">
            <span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Community Portal</small></span>
        </a>
        <?php require __DIR__ . '/includes/resident_nav.php'; ?>
        <div class="dashboard-sidebar-footer">
            <img src="assets/images/logo.png" alt="">
            <span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small></span>
        </div>
    </aside>
    <section class="dashboard-content">
        <header class="dashboard-topbar">
            <div class="dashboard-search"><i class="bi bi-search"></i><input type="search" placeholder="Search services, announcements, or information..." aria-label="Search portal"></div>
            <div class="dashboard-user">
                <details class="notification-menu">
                    <summary class="notification-trigger" aria-label="View notifications">
                        <i class="bi bi-bell-fill"></i>
                        <span class="notification-dot"><?= (int) $residentNotifications['count'] ?></span>
                    </summary>
                    <div class="notification-panel">
                        <div class="notification-panel-heading"><strong>Notifications</strong><small><?= (int) $residentNotifications['count'] ?> total</small></div>
                        <?php if ($residentNotifications['items'] === []): ?>
                            <p class="notification-empty">You have no new announcements or document updates.</p>
                        <?php else: ?>
                            <?php foreach ($residentNotifications['items'] as $notification): ?>
                                <a class="notification-item" href="<?= e($notification['url']) ?>">
                                    <span class="notification-icon"><i class="bi <?= e($notification['icon']) ?>"></i></span>
                                    <span><strong><?= e($notification['title']) ?></strong><small><?= e($notification['message']) ?></small><em><?= e((new DateTime($notification['date'] ?? 'now'))->format('M j, Y')) ?></em></span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </details>
                <span class="user-avatar"><i class="bi bi-person-fill"></i></span><span><strong><?= e($_SESSION['user']['full_name']) ?></strong><small>Resident</small></span><i class="bi bi-chevron-down"></i>
            </div>
        </header>
        <div class="dashboard-main">
            <div class="dashboard-welcome">
                <p class="eyebrow">COMMUNITY PORTAL</p>
                <h1>Good Morning, <?= e(explode(' ', trim($_SESSION['user']['full_name']))[0]) ?>!</h1>
                <p>Welcome to Barangay Inosluban Community Portal.</p>
                <small>A faster, easier, and more convenient way to access barangay services.</small>
                <div class="dashboard-tagline">Mas Maayos na Serbisyo,<br>para sa ating Barangay.</div>
            </div>
            <div class="dashboard-section-heading"><h2>Barangay Services</h2><a href="#services">View all <i class="bi bi-arrow-right"></i></a></div>
            <div class="dashboard-service-grid" id="services">
                <a class="dashboard-service-card" href="document_request.php"><i class="bi bi-file-earmark-text"></i><h3>Request Document</h3><p>Apply for barangay clearance, certificates, and other documents.</p><span>Go to Request <i class="bi bi-arrow-right"></i></span></a>
                <a class="dashboard-service-card" href="blotter.php"><i class="bi bi-shield-check"></i><h3>Blotter</h3><p>Report incidents, concerns, or request assistance.</p><span>Go to Blotter <i class="bi bi-arrow-right"></i></span></a>
                <a class="dashboard-service-card" href="appointment.php"><i class="bi bi-calendar3"></i><h3>Appointment</h3><p>Book an appointment with barangay officials or services.</p><span>Go to Appointment <i class="bi bi-arrow-right"></i></span></a>
                <a class="dashboard-service-card" href="announcement.php"><i class="bi bi-megaphone"></i><h3>Announcements</h3><p>View the latest updates, notices, and events.</p><span>Go to Announcements <i class="bi bi-arrow-right"></i></span></a>
            </div>
            <div class="dashboard-lower-grid">
                <section class="dashboard-panel announcements-panel" id="announcements"><div class="dashboard-panel-heading"><h2><i class="bi bi-megaphone"></i> Latest Announcements</h2><a href="announcement.php">View All <i class="bi bi-arrow-right"></i></a></div><?php foreach ($latestAnnouncements as $announcement): ?><a class="announcement-item" href="announcement.php?id=<?= (int) $announcement['announcement_id'] ?>"><i class="bi bi-megaphone"></i><span><strong><?= e($announcement['title']) ?></strong><small><?= e((new DateTime($announcement['publication_date'] ?? 'now'))->format('M j, Y')) ?></small></span><i class="bi bi-chevron-right"></i></a><?php endforeach; ?><?php if ($latestAnnouncements === []): ?><p class="announcement-dashboard-empty">No published announcements at this time.</p><?php endif; ?></section>
                <section class="dashboard-panel quick-panel"><div class="dashboard-panel-heading"><h2><i class="bi bi-grid"></i> Quick Access</h2></div><div class="quick-grid"><a href="document_request.php"><i class="bi bi-file-earmark-text"></i>Request Document</a><a href="blotter.php"><i class="bi bi-shield-check"></i>Blotter</a><a href="appointment.php"><i class="bi bi-calendar3"></i>Appointment</a><a href="announcement.php"><i class="bi bi-megaphone"></i>Announcements</a></div></section>
                <section class="dashboard-panel portal-panel"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><p>“Bayanihan, disiplina, para sa isang mas maunlad na Inosluban.”</p><h3>Quick Stats</h3><span><i class="bi bi-people-fill"></i>Total Residents <b>8,666</b></span><span><i class="bi bi-house-fill"></i>Total Households <b>2,114</b></span></section>
            </div>
        </div>
        <footer class="dashboard-footer"><span><i class="bi bi-gear"></i></span><b></b><span>Connect. Collaborate. Grow Together.</span><span class="dashboard-footer-social"><i class="bi bi-facebook"></i><i class="bi bi-youtube"></i><i class="bi bi-instagram"></i></span><small>© 2026 Barangay Inosluban. All rights reserved.</small></footer>
    </div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
