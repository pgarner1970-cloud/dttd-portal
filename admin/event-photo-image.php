<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../includes/photo-uploads.php';

$id = (int)($_GET['id'] ?? 0);
$type = strtolower(trim((string)($_GET['type'] ?? 'display')));
if (!in_array($type, ['thumb', 'display', 'framed', 'original'], true)) {
    $type = 'display';
}

if ($id <= 0) {
    http_response_code(404);
    exit('Photo not found');
}

$selectPieces = ['p.*'];
if (!photo_column_exists('event_photo_uploads', 'original_path')) $selectPieces[] = "'' AS original_path";
if (!photo_column_exists('event_photo_uploads', 'framed_path')) $selectPieces[] = "'' AS framed_path";
if (!photo_column_exists('event_photo_uploads', 'thumb_path')) $selectPieces[] = "'' AS thumb_path";

$stmt = db()->prepare('SELECT ' . implode(', ', $selectPieces) . ' FROM event_photo_uploads p WHERE p.id = ? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    exit('Photo not found');
}

$paths = photo_row_display_paths($row);
$rel = '';
if ($type === 'thumb') {
    $rel = $paths['thumb'];
} elseif ($type === 'original') {
    $rel = $paths['original'];
} else {
    $rel = $paths['display'];
}

$rel = photo_relative_to_public($rel);
$root = realpath(dirname(__DIR__));
$file = realpath(dirname(__DIR__) . '/' . $rel);

if (!$root || !$file || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
    http_response_code(404);
    exit('Image file not found');
}

$info = @getimagesize($file);
if (!$info || empty($info['mime']) || strpos($info['mime'], 'image/') !== 0) {
    http_response_code(404);
    exit('Invalid image');
}

header('Content-Type: ' . $info['mime']);
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=60');
readfile($file);
exit;
