<?php

function resident_notifications(int $residentId): array
{
    $announcementCountStatement = db()->query(
        "SELECT COUNT(*)
         FROM announcement
         WHERE status = 'Published'
           AND (publication_date IS NULL OR publication_date <= CURRENT_TIMESTAMP)
           AND (expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP)"
    );

    $requestCountStatement = db()->prepare(
        'SELECT COUNT(*) FROM document_request WHERE resident_id = :resident_id'
    );
    $requestCountStatement->execute(['resident_id' => $residentId]);

    $announcementsStatement = db()->query(
        "SELECT announcement_id, title, publication_date
         FROM announcement
         WHERE status = 'Published'
           AND (publication_date IS NULL OR publication_date <= CURRENT_TIMESTAMP)
           AND (expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP)
         ORDER BY COALESCE(publication_date, CURRENT_TIMESTAMP) DESC
         LIMIT 5"
    );

    $requestsStatement = db()->prepare(
        'SELECT request_id, document_type, status, request_date
         FROM document_request
         WHERE resident_id = :resident_id
         ORDER BY request_date DESC
         LIMIT 5'
    );
    $requestsStatement->execute(['resident_id' => $residentId]);

    $items = [];
    foreach ($announcementsStatement->fetchAll() as $announcement) {
        $items[] = [
            'icon' => 'bi-megaphone',
            'title' => 'New announcement',
            'message' => (string) $announcement['title'],
            'date' => $announcement['publication_date'],
            'url' => 'announcement.php?id=' . (int) $announcement['announcement_id'],
        ];
    }

    foreach ($requestsStatement->fetchAll() as $request) {
        $items[] = [
            'icon' => 'bi-file-earmark-text',
            'title' => 'Document request update',
            'message' => (string) $request['document_type'] . ' - ' . (string) $request['status'],
            'date' => $request['request_date'],
            'url' => 'document_request_status.php?id=' . (int) $request['request_id'],
        ];
    }

    usort($items, static function (array $first, array $second): int {
        return strtotime((string) ($second['date'] ?? 'now')) <=> strtotime((string) ($first['date'] ?? 'now'));
    });

    return [
        'count' => (int) $announcementCountStatement->fetchColumn() + (int) $requestCountStatement->fetchColumn(),
        'items' => array_slice($items, 0, 8),
    ];
}
