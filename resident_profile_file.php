<?php
declare(strict_types=1);

require_once __DIR__ . "/includes/bootstrap.php";
require_resident();

$kind = (string) ($_GET["kind"] ?? "");
if (!in_array($kind, ["profile", "valid_id"], true)) {
    http_response_code(404);
    exit();
}

$residentId = (int) $_SESSION["user"]["resident_id"];
$column = $kind === "profile" ? "profile_image_path" : "valid_id_file_path";
$statement = db()->prepare(
    "SELECT {$column} AS file_path FROM resident WHERE resident_id = :resident_id",
);
$statement->execute(["resident_id" => $residentId]);
$record = $statement->fetch();

$relativePath = is_array($record) ? (string) ($record["file_path"] ?? "") : "";
$baseDirectory = realpath(__DIR__ . "/storage/uploads");
$absolutePath = $relativePath !== ""
    ? realpath(__DIR__ . "/" . ltrim(str_replace("\\", "/", $relativePath), "/"))
    : false;

if (
    $relativePath === "" ||
    $baseDirectory === false ||
    $absolutePath === false ||
    strncmp($absolutePath, rtrim($baseDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, strlen(rtrim($baseDirectory, DIRECTORY_SEPARATOR)) + 1) !== 0 ||
    !is_file($absolutePath)
) {
    http_response_code(404);
    exit();
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath);
$allowed = $kind === "profile"
    ? ["image/jpeg", "image/png"]
    : ["image/jpeg", "image/png", "application/pdf"];
if (!in_array($mime, $allowed, true)) {
    http_response_code(404);
    exit();
}

$contents = @file_get_contents($absolutePath);
if (!is_string($contents) || $contents === "") {
    http_response_code(404);
    exit();
}

header("Content-Type: " . $mime);
header("Content-Length: " . strlen($contents));
header("Cache-Control: private, no-store, max-age=0");
header("Pragma: no-cache");
header("X-Content-Type-Options: nosniff");
echo $contents;
