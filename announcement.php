<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_resident();

$announcementId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
$announcement = null;
$announcements = [];

try {
    $announcementsStatement = db()->query(
        "SELECT announcement_id, title, message, publication_date, expires_at
         FROM announcement
         WHERE status = 'Published'
           AND (publication_date IS NULL OR publication_date <= CURRENT_TIMESTAMP)
           AND (expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP)
         ORDER BY COALESCE(publication_date, CURRENT_TIMESTAMP) DESC"
    );
    $announcements = $announcementsStatement->fetchAll();

    if ($announcementId) {
        foreach ($announcements as $item) {
            if ((int) $item['announcement_id'] === $announcementId) {
                $announcement = $item;
                break;
            }
        }
    }

    if ($announcement === null && $announcements !== []) {
        $announcement = $announcements[0];
    }
} catch (PDOException $exception) {
    error_log('Announcement database error: ' . $exception->getMessage());
    http_response_code(500);
    exit('The announcement service is temporarily unavailable.');
}

$pageTitle = 'Announcements';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page request-module announcement-module" data-theme="light">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Community Portal</small></span></a>
        <?php require __DIR__ . '/includes/resident_nav.php'; ?>
        <div class="dashboard-sidebar-footer"><img src="assets/images/logo.png" alt=""><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small></span></div>
    </aside>
    <section class="dashboard-content request-content">
        <header class="dashboard-topbar request-topbar"><div class="dashboard-search"><i class="bi bi-search"></i><input type="search" placeholder="Search services, announcements, or information..." aria-label="Search portal"></div><div class="dashboard-user"><button class="theme-toggle" type="button" data-theme-toggle aria-label="Switch to dark mode"><i class="bi bi-moon-stars"></i></button><span class="user-avatar"><i class="bi bi-person-fill"></i></span><span><strong><?= e($_SESSION['user']['full_name']) ?></strong><small>Resident Account</small></span></div></header>
        <div class="request-main announcement-main">
            <div class="request-breadcrumb"><a href="dashboard.php">Back to dashboard</a> / Announcements</div>
            <div class="request-title-row"><div><h1><i class="bi bi-megaphone"></i> Announcements</h1><p>Stay informed with official updates from Barangay Inosluban.</p></div></div>
            <div class="announcement-layout">
                <section class="announcement-detail-card">
                    <?php if ($announcement !== null): ?>
                        <div class="announcement-detail-meta"><span class="announcement-badge">Official Announcement</span><span><i class="bi bi-calendar3"></i> <?= e((new DateTime($announcement['publication_date'] ?? 'now'))->format('M j, Y')) ?></span></div>
                        <h2><?= e($announcement['title']) ?></h2>
                        <p class="announcement-author"><i class="bi bi-geo-alt-fill"></i> Barangay Inosluban <span>•</span><i class="bi bi-clock"></i> <?= e((new DateTime($announcement['publication_date'] ?? 'now'))->format('g:i A')) ?></p>
                        <div class="announcement-hero-image" role="img" aria-label="Barangay announcement"></div>
                        <div class="announcement-message"><?= nl2br(e($announcement['message'])) ?></div>
                        <div class="announcement-readonly-note"><i class="bi bi-lock-fill"></i><span><strong>Resident view only</strong><small>Announcements are published by authorized barangay staff. Residents can view them but cannot edit or delete their contents.</small></span></div>
                    <?php else: ?>
                        <div class="announcement-empty"><i class="bi bi-megaphone"></i><h2>No published announcements</h2><p>Official barangay announcements will appear here once published.</p></div>
                    <?php endif; ?>
                </section>
                <aside class="announcement-side">
                    <section class="announcement-related-card"><div class="card-heading"><div><h2>Related Announcements</h2><p>Latest official updates</p></div></div><div class="announcement-related-list"><?php foreach (array_slice($announcements, 0, 5) as $item): ?><a class="<?= $announcement !== null && (int) $item['announcement_id'] === (int) $announcement['announcement_id'] ? 'selected' : '' ?>" href="announcement.php?id=<?= (int) $item['announcement_id'] ?>"><span class="announcement-dot"></span><span><strong><?= e($item['title']) ?></strong><small><?= e((new DateTime($item['publication_date'] ?? 'now'))->format('M j, Y · g:i A')) ?></small></span></a><?php endforeach; ?><?php if ($announcements === []): ?><p class="announcement-side-empty">No related announcements.</p><?php endif; ?></div></section>
                    <section class="announcement-safety-card"><i class="bi bi-megaphone-fill"></i><h3>Stay informed</h3><p>Check this page regularly for important barangay notices, advisories, and community updates.</p></section>
                </aside>
            </div>
        </div>
        <footer class="dashboard-footer"><span>Connect. Collaborate. Grow Together.</span><small>© 2026 Barangay Inosluban. All rights reserved.</small></footer>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
