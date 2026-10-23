<?php
declare(strict_types=1);

function ask_gemini(string $prompt): string
{
    if (GEMINI_API_KEY === '') {
        throw new RuntimeException('Gemini is not configured. Add GEMINI_API_KEY to the local .env file.');
    }
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(GEMINI_MODEL) . ':generateContent?key=' . rawurlencode(GEMINI_API_KEY);
    $payload = json_encode([
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.35, 'maxOutputTokens' => 900],
    ], JSON_THROW_ON_ERROR);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $rawResponse = curl_exec($curl);
    if ($rawResponse === false) {
        throw new RuntimeException('Gemini could not be reached.');
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $decoded = json_decode($rawResponse, true);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException((string) ($decoded['error']['message'] ?? 'The Gemini request failed.'));
    }
    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (!is_string($text) || trim($text) === '') {
        throw new RuntimeException('Gemini returned no usable response.');
    }
    return trim($text);
}

function is_barangay_question(string $message): bool
{
    $normalized = strtolower($message);
    $topics = [
        'barangay', 'inosluban', 'lipa', 'batangas', 'resident', 'residency',
        'document', 'certificate', 'clearance', 'appointment', 'official',
        'staff', 'blotter', 'incident', 'complaint', 'announcement', 'notice',
        'purok', 'household', 'profile', 'valid id', 'verification', 'permit',
        'request', 'status', 'schedule', 'office', 'community', 'population',
        'address', 'emergency', 'disaster', 'ordinance', 'service', 'portal',
        'admin', 'resident', 'approve', 'approval', 'processing', 'hours',
        'contact', 'phone', 'landline', 'facebook', 'tanod', 'emergency',
    ];

    foreach ($topics as $topic) {
        if (str_contains($normalized, $topic)) {
            return true;
        }
    }

    return false;
}

function barangay_ai_context(bool $admin = false): string
{
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
    $verifiedKnowledge = [
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
    foreach ($verifiedKnowledge as [$category, $question, $answer, $sourceUrl, $sourceName]) {
        $knowledgeInsert->execute([
            'category' => $category,
            'question' => $question,
            'answer' => $answer,
            'source_url' => $sourceUrl,
            'source_name' => $sourceName,
        ]);
    }
    $knowledge = db()->query(
        "SELECT category, question, answer, source_name
         FROM barangay_knowledge WHERE is_published = TRUE ORDER BY category, knowledge_id"
    )->fetchAll();
    $context = 'Barangay knowledge: ';
    foreach ($knowledge as $record) {
        $context .= '[' . $record['category'] . '] ' . $record['question'] . ': ' . $record['answer'] . '. ';
    }
    $announcements = db()->query(
        "SELECT title FROM announcement
         WHERE status = 'Published'
           AND (publication_date IS NULL OR publication_date <= CURRENT_TIMESTAMP)
           AND (expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP)
         ORDER BY COALESCE(publication_date, CURRENT_TIMESTAMP) DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_COLUMN);
    $context .= 'Current published announcements: ' . implode('; ', array_map('strval', $announcements)) . '. ';
    if ($admin) {
        $counts = db()->query(
            "SELECT
                (SELECT COUNT(*) FROM resident WHERE residency_status = 'Active') AS residents,
                (SELECT COUNT(*) FROM appointment WHERE status = 'Pending') AS pending_appointments,
                (SELECT COUNT(*) FROM document_request WHERE status = 'Pending') AS pending_documents"
        )->fetch();
        $context .= 'Admin aggregate snapshot: active residents=' . (int) $counts['residents']
            . ', pending appointments=' . (int) $counts['pending_appointments']
            . ', pending document requests=' . (int) $counts['pending_documents'] . '. ';
    }
    return $context;
}
