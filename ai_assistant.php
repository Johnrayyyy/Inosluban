<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/gemini.php';
require_login();

$isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
$errors = [];
$response = '';
$mode = $isAdmin ? 'analytics' : 'question';
$input = '';
$systemContext = '';

try {
    db()->exec(
        "CREATE TABLE IF NOT EXISTS barangay_knowledge (
            knowledge_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            category VARCHAR(100) NOT NULL,
            question VARCHAR(255) NOT NULL,
            answer TEXT NOT NULL,
            source_url TEXT,
            source_name VARCHAR(255),
            is_published BOOLEAN NOT NULL DEFAULT TRUE,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (category, question)
        )"
    );
    $knowledgeSeed = [
        ['Identity', 'Where is Barangay Inosloban located?', 'Barangay Inosloban is in the City of Lipa, Province of Batangas, CALABARZON (Region IV-A), on the island of Luzon.'],
        ['Identity', 'What is the barangay geographic location?', 'Inosloban is situated at approximately 13.9888 latitude and 121.1710 longitude. Its estimated elevation is 335.2 meters (1,099.7 feet) above mean sea level.'],
        ['Demographics', 'What was the population of Inosloban in the 2020 Census?', 'The population of Inosloban was 14,904 in the 2020 Census, representing approximately 4.00% of the total population of Lipa.'],
        ['Demographics', 'How many households were recorded in Inosloban?', 'The 2015 Census recorded a household population of 12,688 in 3,186 households, with an average household size of 3.98 members.'],
        ['Demographics', 'What is the largest age group in Inosloban?', 'Based on the 2015 Census, the 20 to 24 age group had the highest population, with 1,419 individuals.'],
        ['History', 'How has the population of Inosloban changed?', 'The population grew from 4,463 in 1990 to 14,904 in 2020. PhilAtlas reports an annualized growth rate of 3.40% from 2015 to 2020.'],
        ['Location', 'Which barangays are adjacent to Inosloban?', 'Inosloban shares borders with Plaridel (Lipa), San Andres (Malvar), Bugtong na Pulo (Lipa), Santiago (Malvar), Lumbang (Lipa), Pusil (Lipa), Marauoy (Lipa), and Dagatan (Lipa).'],
        ['Identity', 'What is the alternate spelling of Barangay Inosluban?', 'Barangay Inosluban is also spelled Inosloban in some references and is a barangay of Lipa City, Batangas.', 'https://brgy.to/batangas/lipa/inosloban', 'brgy.to - Inosloban, Lipa, Batangas'],
        ['Demographics', 'What language is commonly associated with Inosluban?', 'brgy.to identifies Tagalog, including the Batangas dialect, as the language associated with Inosloban.', 'https://brgy.to/batangas/lipa/inosloban', 'brgy.to - Inosloban, Lipa, Batangas'],
        ['History', 'How did Barangay Inosluban get its name?', 'According to the history published by the City of Lipa, elders associated the name with a robbery at a woman named Ines\' home. The phrase “Si Ines, nilooban!” was later connected to the name Inosluban.', 'https://lipa.gov.ph/barangay-profiles/', 'City of Lipa - Barangay Profiles'],
        ['Local Leadership', 'Who is listed in the Inosluban barangay directory?', 'The City of Lipa barangay directory lists Miguel L. Olgado as Chairman, Lovely V. Dungo as Secretary, and Blesilda H. Ludovice as Treasurer for Inosluban. Directory information may change, so residents should confirm current officers with the barangay office.', 'https://lipa.gov.ph/barangay-profiles/', 'City of Lipa - Barangay Profiles'],
        ['Economy and Landmarks', 'What industry is specifically mentioned for Inosluban?', 'The City of Lipa barangay profile states that Inosluban caters the first semiconductor industry in Lipa, PKI (Pilipinas Kyohritsu, Inc.).', 'https://lipa.gov.ph/barangay-profiles/', 'City of Lipa - Barangay Profiles'],
        ['Location', 'What road and coordinates are shown in the Wikimedia Inosluban photo?', 'The Wikimedia Commons file describes a location in Inosluban along the Manila-Batangas Road, also called Jose P. Laurel Highway, and records approximate coordinates of 13.981667 latitude and 121.169444 longitude for the image.', 'https://commons.wikimedia.org/wiki/File:728Inosluban,_Lipa_City,_Batangas_10.jpg', 'Wikimedia Commons - Inosluban, Lipa City, Batangas 10'],
        ['Office Hours', 'What are the Barangay Hall of Inosluban office hours?', 'The supplied public office-hours information lists Monday to Friday from 8:00 AM to 5:00 PM, Saturday from 8:00 AM to 12:00 PM for basic desk services, and Sunday closed. Residents should verify schedules before visiting because hours may change.', 'https://www.facebook.com/61553988621875/posts/barangay-office-hoursmonday-friday-800am-500pmsaturday-800am-1200-noonsunday-clo/122124795758132954/', 'Barangay Inosluban public office-hours notice'],
        ['Emergency Services', 'Are emergency responders and Barangay Tanods available outside office hours?', 'The supplied public office-hours information states that local emergency responders and Barangay Tanods remain on duty 24/7 for urgent community matters, while desk operations follow daytime office hours.', 'https://www.facebook.com/61553988621875/posts/barangay-office-hoursmonday-friday-800am-500pmsaturday-800am-1200-noonsunday-clo/122124795758132954/', 'Barangay Inosluban public office-hours notice'],
        ['Contact Information', 'How can residents contact Barangay Hall of Inosluban?', 'The supplied public contact information lists Barangay Hall landline 404-3035, Barangay Outpost landline 233-2499, and Barangay Outpost mobile 0966-854-7444. Residents should verify contact details through the official channels before relying on them.', 'https://www.facebook.com/61553651555583/posts/updated-contact-numbersbarangay-hall-404-3035barangay-outpost-landline-233-2499c/122190825068121718/', 'Brgy. Inosluban | Lipa City public contact notice'],
        ['Contact Information', 'What is the official Facebook page of Barangay Inosluban?', 'The supplied public contact information identifies “Brgy. Inosluban | Lipa City” as the official Facebook page: https://www.facebook.com/p/Brgy-Inosluban-61553651555583/.', 'https://www.facebook.com/p/Brgy-Inosluban-61553651555583/', 'Brgy. Inosluban | Lipa City official Facebook page'],
    ];
    $knowledgeInsert = db()->prepare(
        "INSERT INTO barangay_knowledge (category, question, answer, source_url, source_name)
         VALUES (:category, :question, :answer, :source_url, :source_name)
         ON CONFLICT (category, question) DO NOTHING"
    );
    foreach ($knowledgeSeed as $record) {
        $category = $record[0];
        $question = $record[1];
        $answer = $record[2];
        $sourceUrl = $record[3] ?? 'https://www.philatlas.com/luzon/r04a/batangas/lipa/inosloban.html';
        $sourceName = $record[4] ?? 'PhilAtlas - Inosloban, Lipa, Batangas';
        $knowledgeInsert->execute([
            'category' => $category,
            'question' => $question,
            'answer' => $answer,
            'source_url' => $sourceUrl,
            'source_name' => $sourceName,
        ]);
    }
    $knowledgeRecords = db()->query(
        "SELECT category, question, answer, source_name
         FROM barangay_knowledge
         WHERE is_published = TRUE
         ORDER BY category, knowledge_id"
    )->fetchAll();
    $knowledgeContext = '';
    foreach ($knowledgeRecords as $record) {
        $knowledgeContext .= '[' . $record['category'] . '] ' . $record['question'] . ': ' . $record['answer'] . ' (Source: ' . ($record['source_name'] ?: 'Barangay knowledge base') . ') ';
    }
    $announcementTitles = db()->query(
        "SELECT title FROM announcement
         WHERE status = 'Published'
           AND (publication_date IS NULL OR publication_date <= CURRENT_TIMESTAMP)
           AND (expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP)
         ORDER BY COALESCE(publication_date, CURRENT_TIMESTAMP) DESC
         LIMIT 5"
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($isAdmin) {
        $counts = db()->query(
            "SELECT
                (SELECT COUNT(*) FROM resident WHERE residency_status = 'Active') AS residents,
                (SELECT COUNT(*) FROM appointment) AS appointments,
                (SELECT COUNT(*) FROM appointment WHERE status = 'Pending') AS pending_appointments,
                (SELECT COUNT(*) FROM document_request) AS document_requests,
                (SELECT COUNT(*) FROM document_request WHERE status = 'Pending') AS pending_documents"
        )->fetch();
        $systemContext = 'Barangay knowledge base: ' . $knowledgeContext . 'Current system snapshot: active residents=' . (int) $counts['residents']
            . ', total appointments=' . (int) $counts['appointments']
            . ', pending appointments=' . (int) $counts['pending_appointments']
            . ', total document requests=' . (int) $counts['document_requests']
            . ', pending document requests=' . (int) $counts['pending_documents']
            . '. Published announcements: ' . implode('; ', array_map('strval', $announcementTitles)) . '. ';
    } else {
        $systemContext = 'Barangay knowledge base: ' . $knowledgeContext . 'Barangay services available in this portal include document requests, blotter reports, appointments, resident profiles, and published announcements. Current published announcements: ' . implode('; ', array_map('strval', $announcementTitles)) . '. ';
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        $mode = (string) ($_POST['mode'] ?? 'question');
        $input = trim((string) ($_POST['input'] ?? ''));
        if ($input === '' || strlen($input) > 4000) {
            $errors[] = 'Enter information between 1 and 4,000 characters.';
        }
        if (!$isAdmin && $mode !== 'question') {
            http_response_code(403);
            exit('This assistant mode is available to administrators only.');
        }
        if ($errors === [] && !is_barangay_question($input)) {
            $errors[] = 'The AI Assistant only answers questions about Barangay Inosluban, its services, residents, and this portal.';
        }
        if ($errors === []) {
            $context = $systemContext . ($isAdmin
                ? "You are a decision-support assistant for Barangay Inosluban. Answer only about the barangay, its administration, residents, programs, services, and portal data. Do not answer unrelated topics. Do not invent official facts. Clearly label estimates, assumptions, and recommendations. User request: "
                : "You are a helpful Barangay Inosluban resident-service assistant. Answer only about Barangay Inosluban, its residents, announcements, appointments, document requests, blotter reports, and portal services. Do not answer unrelated topics. Do not expose private resident information or claim an appointment/document is approved. If details are unknown, say so and direct the resident to the barangay office. User question: ");
            $response = ask_gemini($context . $input);
        }
    }
} catch (RuntimeException $exception) {
    error_log('Gemini assistant error: ' . $exception->getMessage());
    $errors[] = $exception->getMessage();
} catch (JsonException $exception) {
    error_log('Gemini request encoding error: ' . $exception->getMessage());
    $errors[] = 'The assistant request could not be prepared.';
}

$pageTitle = 'AI Assistant';
require __DIR__ . '/includes/header.php';
?>
<main class="dashboard-page ai-assistant-page">
    <aside class="dashboard-sidebar">
        <a class="dashboard-brand" href="dashboard.php"><img src="assets/images/logo.png" alt="Barangay Inosluban logo"><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small><small>Community Portal</small></span></a>
        <?php require __DIR__ . '/includes/resident_nav.php'; ?>
        <div class="dashboard-sidebar-footer"><img src="assets/images/logo.png" alt=""><span><strong>BARANGAY INOSLUBAN</strong><small>LIPA CITY, BATANGAS</small></span></div>
    </aside>
    <section class="dashboard-content">
        <header class="dashboard-topbar"><div class="dashboard-search"><i class="bi bi-search"></i><input type="search" placeholder="Search services, announcements, or information..." aria-label="Search portal"></div><div class="dashboard-user"><span class="user-avatar"><i class="bi bi-person-fill"></i></span><span><strong><?= e($_SESSION['user']['full_name']) ?></strong><small><?= $isAdmin ? 'Administrator' : 'Resident' ?></small></span></div></header>
        <div class="ai-assistant-main">
            <div class="ai-assistant-heading"><p class="eyebrow">SMART BARANGAY SERVICES</p><h1><i class="bi bi-stars"></i> AI Assistant</h1><p><?= $isAdmin ? 'Use connected barangay information to support planning and prepare announcement drafts.' : 'Ask a question about barangay services and get helpful guidance.' ?></p></div>
            <?php if ($errors !== []): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <div class="ai-assistant-layout">
                <section class="ai-assistant-card"><div class="ai-card-heading"><span class="ai-icon"><i class="bi bi-stars"></i></span><div><h2><?= $isAdmin ? 'Decision support and drafting' : 'Ask about barangay services' ?></h2><p><?= $isAdmin ? 'Use the floating chat button to ask for analytics or announcement drafts.' : 'Use the floating chat button to ask questions about Barangay Inosluban.' ?></p></div></div><p class="ai-page-chat-hint"><i class="bi bi-chat-dots"></i> The Barangay Assistant is available from the chat button at the bottom-right of every portal page.</p></section>
            </div>
            <?php if ($response !== ''): ?><section class="ai-response-card"><div class="ai-card-heading"><span class="ai-icon"><i class="bi bi-chat-left-text"></i></span><div><h2>Gemini response</h2><p>Review this guidance before taking action.</p></div></div><div class="ai-response"><?= nl2br(e($response)) ?></div></section><?php endif; ?>
        </div>
        <footer class="dashboard-footer"><span>Connect. Collaborate. Grow Together.</span><small>© 2026 Barangay Inosluban. All rights reserved.</small></footer>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
