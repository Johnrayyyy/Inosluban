<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
$pageTitle = 'Resident dashboard';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="dashboard.php">
            <img src="assets/images/logo.png" alt="Barangay Inosluban logo">
            <span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Community Portal</small></span>
        </a>
        <nav class="dashboard-nav" aria-label="Resident portal navigation">
            <a class="active" href="dashboard.php"><i class="bi bi-house-fill"></i>Home</a>
            <a href="#services"><i class="bi bi-file-earmark-text"></i>Request Document</a>
            <a href="#blotter"><i class="bi bi-shield-check"></i>Blotter</a>
            <a href="#appointment"><i class="bi bi-calendar3"></i>Appointment</a>
            <a href="#announcements"><i class="bi bi-megaphone"></i>Announcements</a>
            <span class="dashboard-nav-label">MORE</span>
            <a href="#profile"><i class="bi bi-person"></i>My Profile</a>
            <a href="#settings"><i class="bi bi-gear"></i>Settings</a>
            <a href="logout.php"><i class="bi bi-box-arrow-right"></i>Logout</a>
        </nav>
        <div class="dashboard-sidebar-footer">
            <img src="assets/images/logo.png" alt="">
            <span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small></span>
        </div>
    </aside>
    <section class="dashboard-content">
        <header class="dashboard-topbar">
            <div class="dashboard-search"><i class="bi bi-search"></i><input type="search" placeholder="Search services, announcements, or information..." aria-label="Search portal"></div>
            <div class="dashboard-user"><i class="bi bi-bell-fill"></i><span class="notification-dot">3</span><span class="user-avatar"><i class="bi bi-person-fill"></i></span><span><strong><?= e($_SESSION['user']['full_name']) ?></strong><small>Resident</small></span><i class="bi bi-chevron-down"></i></div>
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
                <a class="dashboard-service-card" href="#request"><i class="bi bi-file-earmark-text"></i><h3>Request Document</h3><p>Apply for barangay clearance, certificates, and other documents.</p><span>Go to Request <i class="bi bi-arrow-right"></i></span></a>
                <a class="dashboard-service-card" href="#blotter"><i class="bi bi-shield-check"></i><h3>Blotter</h3><p>Report incidents, concerns, or request assistance.</p><span>Go to Blotter <i class="bi bi-arrow-right"></i></span></a>
                <a class="dashboard-service-card" href="#appointment"><i class="bi bi-calendar3"></i><h3>Appointment</h3><p>Book an appointment with barangay officials or services.</p><span>Go to Appointment <i class="bi bi-arrow-right"></i></span></a>
                <a class="dashboard-service-card" href="#announcements"><i class="bi bi-megaphone"></i><h3>Announcements</h3><p>View the latest updates, notices, and events.</p><span>Go to Announcements <i class="bi bi-arrow-right"></i></span></a>
            </div>
            <div class="dashboard-lower-grid">
                <section class="dashboard-panel announcements-panel" id="announcements"><div class="dashboard-panel-heading"><h2><i class="bi bi-megaphone"></i> Latest Announcements</h2><a href="#announcements">View All <i class="bi bi-arrow-right"></i></a></div><div class="announcement-item"><i class="bi bi-cloud-rain"></i><span><strong>Class Suspension Due to Heavy Rainfall</strong><small>Oct 12, 2025</small></span><i class="bi bi-chevron-right"></i></div><div class="announcement-item"><i class="bi bi-heart-pulse"></i><span><strong>Free Medical Check-up for Senior Citizens</strong><small>Oct 10, 2025</small></span><i class="bi bi-chevron-right"></i></div><div class="announcement-item"><i class="bi bi-people"></i><span><strong>Barangay Assembly Meeting</strong><small>Oct 8, 2025</small></span><i class="bi bi-chevron-right"></i></div></section>
                <section class="dashboard-panel quick-panel"><div class="dashboard-panel-heading"><h2><i class="bi bi-grid"></i> Quick Access</h2></div><div class="quick-grid"><a href="#request"><i class="bi bi-file-earmark-text"></i>Request Document</a><a href="#blotter"><i class="bi bi-shield-check"></i>Blotter</a><a href="#appointment"><i class="bi bi-calendar3"></i>Appointment</a><a href="#announcements"><i class="bi bi-megaphone"></i>Announcements</a></div></section>
                <section class="dashboard-panel portal-panel"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><p>“Bayanihan, disiplina, para sa isang mas maunlad na Inosluban.”</p><h3>Quick Stats</h3><span><i class="bi bi-people-fill"></i>Total Residents <b>8,666</b></span><span><i class="bi bi-house-fill"></i>Total Households <b>2,114</b></span></section>
            </div>
        </div>
        <footer class="dashboard-footer"><span><i class="bi bi-gear"></i></span><b></b><span>Connect. Collaborate. Grow Together.</span><span class="dashboard-footer-social"><i class="bi bi-facebook"></i><i class="bi bi-youtube"></i><i class="bi bi-instagram"></i></span><small>© 2026 Barangay Inosluban. All rights reserved.</small></footer>
    </div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
