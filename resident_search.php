<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_resident();

$query = trim((string) ($_GET['q'] ?? ''));
$query = function_exists('mb_substr') ? mb_substr($query, 0, 100) : substr($query, 0, 100);
$lowercase = static fn(string $value): string => function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
$terms = preg_split('/\s+/u', $lowercase($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$terms = array_slice($terms, 0, 8);
$pageResults = [
    [
        'title' => 'Request Document',
        'description' => 'Apply for barangay clearance, certificates, and other official documents.',
        'url' => 'document_request.php',
        'keywords' => 'document documents certificate clearance indigency residency barangay id request',
        'icon' => 'bi-file-earmark-text',
        'category' => 'Resident service',
    ],
    [
        'title' => 'Blotter and Incident Reports',
        'description' => 'Submit an incident report or check your blotter request.',
        'url' => 'blotter.php',
        'keywords' => 'blotter incident report complaint concern assistance safety',
        'icon' => 'bi-shield-check',
        'category' => 'Resident service',
    ],
    [
        'title' => 'Appointment',
        'description' => 'Schedule an appointment with barangay officials or services.',
        'url' => 'appointment.php',
        'keywords' => 'appointment schedule booking visit meeting',
        'icon' => 'bi-calendar3',
        'category' => 'Resident service',
    ],
    [
        'title' => 'Announcements',
        'description' => 'Read current notices, advisories, and community updates.',
        'url' => 'announcement.php',
        'keywords' => 'announcement announcements notice news advisory update event',
        'icon' => 'bi-megaphone',
        'category' => 'Information',
    ],
    [
        'title' => 'Contact Office',
        'description' => 'View barangay office contact details and officials.',
        'url' => 'contact.php',
        'keywords' => 'contact office facebook email phone officials captain kagawad',
        'icon' => 'bi-telephone',
        'category' => 'Information',
    ],
    [
        'title' => 'My Profile',
        'description' => 'View and update your resident account information.',
        'url' => 'profile.php',
        'keywords' => 'profile account personal details password picture photo',
        'icon' => 'bi-person',
        'category' => 'Account',
    ],
];

$results = [];
if ($terms !== []) {
    foreach ($pageResults as $pageResult) {
        $searchable = $lowercase($pageResult['title'] . ' ' . $pageResult['description'] . ' ' . $pageResult['keywords']);
        foreach ($terms as $term) {
            $position = function_exists('mb_strpos') ? mb_strpos($searchable, $term) : strpos($searchable, $term);
            if ($position !== false) {
                $results[] = $pageResult;
                break;
            }
        }
    }

    $conditions = [];
    $parameters = [];
    foreach ($terms as $index => $term) {
        $conditions[] = "(title ILIKE :title_{$index} OR message ILIKE :message_{$index})";
        $parameters["title_{$index}"] = '%' . $term . '%';
        $parameters["message_{$index}"] = '%' . $term . '%';
    }

    try {
        $announcementStatement = db()->prepare(
            "SELECT announcement_id, title, message, publication_date
             FROM announcement
             WHERE status = 'Published'
               AND (publication_date IS NULL OR publication_date <= CURRENT_TIMESTAMP)
               AND (expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP)
               AND " . implode(' AND ', $conditions) . "
             ORDER BY COALESCE(publication_date, CURRENT_TIMESTAMP) DESC
             LIMIT 25"
        );
        $announcementStatement->execute($parameters);
        foreach ($announcementStatement->fetchAll() as $announcement) {
            $message = strip_tags((string) $announcement['message']);
            $description = function_exists('mb_strimwidth')
                ? mb_strimwidth($message, 0, 180, '...')
                : substr($message, 0, 177) . (strlen($message) > 177 ? '...' : '');
            $results[] = [
                'title' => $announcement['title'],
                'description' => $description,
                'url' => 'announcement.php?id=' . (int) $announcement['announcement_id'],
                'icon' => 'bi-megaphone',
                'category' => 'Announcement · ' . format_app_datetime($announcement['publication_date'], 'M j, Y'),
            ];
        }
    } catch (PDOException $exception) {
        error_log('Resident search database error: ' . $exception->getMessage());
        http_response_code(500);
        exit('Search is temporarily unavailable. Please try again later.');
    }
}

$pageTitle = 'Search';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page resident-search-page">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Community Portal</small></span></a>
        <?php require __DIR__ . '/includes/resident_nav.php'; ?>
        <div class="dashboard-sidebar-footer"><img src="assets/images/logo.png" alt=""><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small></span></div>
    </aside>
    <section class="dashboard-content">
        <header class="dashboard-topbar">
            <div class="dashboard-search"><i class="bi bi-search"></i><input type="search" value="<?= e($query) ?>" placeholder="Search services, announcements, or information..." aria-label="Search portal"></div>
            <div class="dashboard-user"><span class="user-avatar"><i class="bi bi-person-fill"></i></span><span><strong><?= e($_SESSION['user']['full_name']) ?></strong><small>Resident</small></span></div>
        </header>
        <div class="dashboard-main resident-search-main">
            <div class="dashboard-section-heading"><h1>Search the portal</h1></div>
            <?php if ($query === ''): ?>
                <p class="resident-search-message">Search for a barangay service, announcement, or account page.</p>
            <?php elseif ($results === []): ?>
                <p class="resident-search-message">No results found for “<?= e($query) ?>”. Try a service name, such as documents, appointment, or blotter.</p>
            <?php else: ?>
                <p class="resident-search-message">Results for “<?= e($query) ?>”</p>
                <div class="resident-search-results">
                    <?php foreach ($results as $result): ?>
                        <a class="resident-search-result" href="<?= e($result['url']) ?>">
                            <span class="resident-search-result-icon"><i class="bi <?= e($result['icon']) ?>"></i></span>
                            <span><small><?= e($result['category']) ?></small><strong><?= e($result['title']) ?></strong><span><?= e($result['description']) ?></span></span>
                            <i class="bi bi-arrow-up-right resident-search-result-arrow"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <footer class="dashboard-footer"><span>Connect. Collaborate. Grow Together.</span><small>© <?= date('Y') ?> Barangay Inosluban. All rights reserved.</small></footer>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
