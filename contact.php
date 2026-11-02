<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_resident();

$pageTitle = 'Contact the barangay office';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page resident-contact-page">
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
            <div class="dashboard-search"><i class="bi bi-telephone"></i><span>Barangay communication channels</span></div>
            <div class="dashboard-user"><span class="user-avatar"><i class="bi bi-person-fill"></i></span><span><strong><?= e($_SESSION['user']['full_name']) ?></strong><small>Resident</small></span></div>
        </header>
        <div class="dashboard-main resident-contact-main">
            <div class="resident-contact-heading">
                <p class="eyebrow">COMMUNITY SUPPORT</p>
                <h1>Contact the Barangay Office</h1>
                <p>Choose an official channel to reach Barangay Inosluban.</p>
            </div>
            <section class="resident-contact-grid" aria-label="Official communication channels">
                <a class="resident-contact-card" href="https://www.facebook.com/p/Brgy-Inosluban-61553651555583/" target="_blank" rel="noopener noreferrer">
                    <span class="resident-contact-icon"><i class="bi bi-facebook"></i></span>
                    <span class="resident-contact-detail"><small>OFFICIAL FACEBOOK PAGE</small><strong>Brgy. Inosluban | Lipa City</strong><em>Visit our Facebook page for updates and messages.</em></span>
                    <i class="bi bi-arrow-up-right resident-contact-arrow"></i>
                </a>
                <a class="resident-contact-card" href="mailto:inosluban2014@gmail.com">
                    <span class="resident-contact-icon resident-contact-email"><i class="bi bi-envelope-fill"></i></span>
                    <span class="resident-contact-detail"><small>EMAIL</small><strong>inosluban2014@gmail.com</strong><em>Send your inquiry directly to the barangay office.</em></span>
                    <i class="bi bi-arrow-up-right resident-contact-arrow"></i>
                </a>
            </section>
            <p class="resident-contact-note"><i class="bi bi-info-circle"></i> Please avoid sending passwords or sensitive account information through social media or email.</p>
            <a class="resident-contact-back" href="dashboard.php"><i class="bi bi-arrow-left"></i> Back to resident dashboard</a>
        </div>
        <footer class="dashboard-footer"><span>Connect. Collaborate. Grow Together.</span><a class="dashboard-footer-contact" href="contact.php">Contact Office</a><span class="dashboard-footer-social"><a href="https://www.facebook.com/p/Brgy-Inosluban-61553651555583/" target="_blank" rel="noopener noreferrer" aria-label="Barangay Inosluban on Facebook"><i class="bi bi-facebook"></i></a><a href="mailto:inosluban2014@gmail.com" aria-label="Email Barangay Inosluban"><i class="bi bi-envelope-fill"></i></a></span><small>© <?= date('Y') ?> Barangay Inosluban. All rights reserved.</small></footer>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
