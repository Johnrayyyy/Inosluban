<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_staff();

$statement = db()->prepare(
    "SELECT encode(profile_image_data, 'base64') AS profile_image_base64,
            profile_image_mime
     FROM user_account
     WHERE user_id = :user_id"
);
$statement->execute(['user_id' => (int) $_SESSION['user']['id']]);
$photo = $statement->fetch();
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
if (
    !$photo
    || !in_array($photo['profile_image_mime'], $allowedMimes, true)
    || $photo['profile_image_base64'] === null
) {
    http_response_code(404);
    exit;
}

$imageData = base64_decode((string) $photo['profile_image_base64'], true);
if (!is_string($imageData) || $imageData === '') {
    error_log('Staff profile image data could not be read for user #' . (int) $_SESSION['user']['id']);
    http_response_code(500);
    exit;
}

header('Content-Type: ' . $photo['profile_image_mime']);
header('Content-Length: ' . strlen($imageData));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $imageData;
