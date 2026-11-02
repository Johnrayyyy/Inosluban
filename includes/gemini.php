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
        'weather', 'rain', 'rainy', 'forecast', 'storm', 'thunder',
    ];

    foreach ($topics as $topic) {
        if (str_contains($normalized, $topic)) {
            return true;
        }
    }

    return false;
}

function barangay_weather_context_from_snapshot(?array $snapshot): string
{
    $weather = $snapshot['weather'] ?? null;
    if (!is_array($weather)) {
        return 'Stored weather forecast: No forecast is currently available in the database. Do not guess future weather.';
    }

    $fetchedAt = $snapshot['fetched_at'] ?? null;
    $freshness = 'refresh time unavailable';
    if (is_string($fetchedAt) && $fetchedAt !== '') {
        $fetchedDate = new DateTimeImmutable($fetchedAt);
        $fetchedAt = $fetchedDate->setTimezone(new DateTimeZone(APP_TIMEZONE));
        $freshness = 'last updated ' . $fetchedAt->format('M j, Y g:i A T');
        if ($fetchedAt < new DateTimeImmutable('-24 hours', new DateTimeZone(APP_TIMEZONE))) {
            $freshness .= ' (stale; treat as potentially outdated)';
        }
    }

    $context = 'Stored weather forecast for ' . WEATHER_LOCATION . ', ' . $freshness
        . '. The forecast is an estimate, not a guarantee. ';
    $current = $weather['current'] ?? [];
    if (is_array($current)) {
        $context .= 'Current conditions: ' . (string) ($weather['summary'] ?? 'unavailable');
        if (isset($weather['temperature'])) {
            $context .= ', ' . number_format((float) $weather['temperature'], 1) . ' C';
        }
        $context .= '. ';
    }

    $forecastDays = $weather['forecast'] ?? [];
    if (!is_array($forecastDays) || $forecastDays === []) {
        return $context . 'No daily forecast entries are stored. Do not guess future weather.';
    }

    $timezone = new DateTimeZone(WEATHER_TIMEZONE);
    $today = new DateTimeImmutable('today', $timezone);
    $context .= 'Stored daily forecast: ';
    $entries = [];
    foreach (array_slice($forecastDays, 0, 7) as $day) {
        if (!is_array($day) || empty($day['date'])) {
            continue;
        }
        $date = new DateTimeImmutable((string) $day['date'], $timezone);
        $label = $date->format('M j, Y');
        if ($date->format('Y-m-d') === $today->format('Y-m-d')) {
            $label = 'today (' . $label . ')';
        } elseif ($date->format('Y-m-d') === $today->modify('+1 day')->format('Y-m-d')) {
            $label = 'tomorrow (' . $label . ')';
        } else {
            $label = $date->format('l, M j');
        }
        $summary = (string) ($day['summary'] ?? 'conditions unavailable');
        $rain = isset($day['rain_sum']) ? number_format((float) $day['rain_sum'], 1) . ' mm rain' : 'rain amount unavailable';
        $temperature = '';
        if (isset($day['temperature_min'], $day['temperature_max'])) {
            $temperature = ', ' . number_format((float) $day['temperature_min'], 0)
                . '-' . number_format((float) $day['temperature_max'], 0) . ' C';
        }
        $entries[] = $label . ': ' . $summary . ', ' . $rain . $temperature;
    }

    return $context . ($entries === [] ? 'No valid forecast entries are stored.' : implode('; ', $entries) . '.');
}

function barangay_weather_context(): string
{
    try {
        $snapshot = db()->query(
            'SELECT weather_data, fetched_at FROM weather_snapshot WHERE snapshot_id = 1'
        )->fetch();
    } catch (PDOException $exception) {
        error_log('AI weather context database error: ' . $exception->getMessage());
        return 'Stored weather forecast: The forecast database is currently unavailable. Do not guess future weather.';
    }

    if (!$snapshot) {
        return barangay_weather_context_from_snapshot(null);
    }

    $weather = is_array($snapshot['weather_data'] ?? null)
        ? $snapshot['weather_data']
        : json_decode((string) ($snapshot['weather_data'] ?? ''), true);

    return barangay_weather_context_from_snapshot([
        'weather' => is_array($weather) ? $weather : null,
        'fetched_at' => $snapshot['fetched_at'] ?? null,
    ]);
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
        ['Local Leadership', 'Who is listed in the Inosluban barangay directory?', 'The current Punong Barangay is Hon. Miguel L. Olgado. Barangay Kagawad are Hon. Merlita P. Garcia, Hon. Elena B. Aguilera, Hon. Felix A. Magcawas, Hon. Jericho Dumlao, Hon. Omar M. Cabrera, and Hon. Nestor Balita. This roster was supplied for the barangay portal; confirm with the barangay office if details need updating.', null, 'Roster supplied by the barangay portal administrator'],
        ['Sangguniang Kabataan Officials', 'Who are the Sangguniang Kabataan (SK) officials of Barangay Inosluban?', 'The SK Chairperson is Hon. Ashlie Nicole Gonzales. SK Kagawad (Councilors) are Faye Abueg, Gemma Marquez, Jean Michelle Maoirat, Lawrence Macahia, Gian Carlo Barairo, and Mylene Joycee Calise. This roster was supplied for the barangay portal; confirm with the barangay office if details need updating.', null, 'Roster supplied by the barangay portal administrator'],
        ['Developers', 'Who developed the Barangay Inosluban portal?', 'The portal developers are Mitra, John Ray I.; Lana, Patrick Jerald O.; and Maralit, Ben Jairo A.', null, 'Developer credits supplied for the barangay portal'],
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
         ON CONFLICT (category, question) DO UPDATE
         SET answer = EXCLUDED.answer,
             source_url = EXCLUDED.source_url,
             source_name = EXCLUDED.source_name,
             is_published = TRUE,
             updated_at = CURRENT_TIMESTAMP"
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
    $context .= barangay_weather_context() . ' ';
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
