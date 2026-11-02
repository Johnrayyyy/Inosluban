<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_resident();

$officialGroups = [
    [
        'title' => 'Barangay Officials',
        'icon' => 'bi-person-badge',
        'tagline' => 'Local governance officials of Barangay Inosluban.',
        'leader_role' => 'Punong Barangay',
        'leader_name' => 'Hon. Miguel L. Olgado',
        'member_role' => 'Barangay Kagawad',
        'members' => [
            'Hon. Merlita P. Garcia',
            'Hon. Elena B. Aguilera',
            'Hon. Felix A. Magcawas',
            'Hon. Jericho Dumlao',
            'Hon. Omar M. Cabrera',
            'Hon. Nestor Balita',
        ],
    ],
    [
        'title' => 'Sangguniang Kabataan',
        'icon' => 'bi-person-hearts',
        'tagline' => 'Youth leaders for a progressive Barangay.',
        'leader_role' => 'SK Chairperson',
        'leader_name' => 'Hon. Ashlie Nicole Gonzales',
        'member_role' => 'SK Kagawad',
        'members' => [
            'Faye Abueg',
            'Gemma Marquez',
            'Jean Michelle Maoirat',
            'Lawrence Macahia',
            'Gian Carlo Barairo',
            'Mylene Joycee Calise',
        ],
    ],
];
$developers = [
    'Mitra, John Ray I.',
    'Lana, Patrick Jerald O.',
    'Maralit, Ben Jairo A.',
];

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
            <section class="resident-officials-section" aria-labelledby="resident-officials-title">
                <div class="resident-officials-heading">
                    <p class="eyebrow">YOUR COMMUNITY REPRESENTATIVES</p>
                    <h2 id="resident-officials-title">Barangay Officials</h2>
                    <p>Meet the officials serving Barangay Inosluban.</p>
                </div>
                <div class="resident-officials-grid">
                    <?php foreach ($officialGroups as $group): ?>
                        <article class="resident-official-group">
                            <header><span><i class="bi <?= e($group['icon']) ?>"></i></span><h3><?= e($group['title']) ?></h3></header>
                            <p class="resident-official-tagline"><?= e($group['tagline']) ?></p>
                            <div class="resident-official-featured">
                                <span><?= e($group['leader_role']) ?></span>
                                <strong><?= e($group['leader_name']) ?></strong>
                            </div>
                            <div class="resident-official-member-heading"><i class="bi bi-people-fill"></i><span><?= e($group['member_role']) ?> (<?= count($group['members']) ?> Members)</span></div>
                            <div class="resident-official-members">
                                <?php foreach ($group['members'] as $member): ?>
                                    <article class="resident-official-member">
                                        <strong><?= e($member) ?></strong>
                                        <span><?= e($group['member_role']) ?></span>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <article class="resident-developer-group">
                        <header><span><i class="bi bi-code-slash"></i></span><h3 id="resident-developers-title">Developers</h3></header>
                        <p>System developers behind the Barangay Inosluban Portal.</p>
                        <div class="resident-developer-cards">
                            <?php foreach ($developers as $developer): ?>
                                <article class="resident-developer-card">
                                    <strong><?= e($developer) ?></strong>
                                    <span>Developer</span>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </article>
                </div>
            </section>
            <p class="resident-contact-note"><i class="bi bi-info-circle"></i> Please avoid sending passwords or sensitive account information through social media or email.</p>
            <a class="resident-contact-back" href="dashboard.php"><i class="bi bi-arrow-left"></i> Back to resident dashboard</a>
        </div>
        <footer class="dashboard-footer"><span>Connect. Collaborate. Grow Together.</span><a class="dashboard-footer-contact" href="contact.php">Contact Office</a><span class="dashboard-footer-social"><a href="https://www.facebook.com/p/Brgy-Inosluban-61553651555583/" target="_blank" rel="noopener noreferrer" aria-label="Barangay Inosluban on Facebook"><i class="bi bi-facebook"></i></a><a href="mailto:inosluban2014@gmail.com" aria-label="Email Barangay Inosluban"><i class="bi bi-envelope-fill"></i></a></span><small>© <?= date('Y') ?> Barangay Inosluban. All rights reserved.</small></footer>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
