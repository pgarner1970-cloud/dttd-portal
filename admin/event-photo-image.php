<?php
require_once __DIR__ . '/../includes/photo-uploads.php';
require_once __DIR__ . '/_auth.php';

$id = (int)($_GET['id'] ?? 0);
$variant = strtolower((string)($_GET['variant'] ?? 'thumb'));
if (!in_array($variant, ['thumb', 'display', 'original'], true)) {
    $variant = 'thumb';
}

if ($id <= 0) {
    http_response_code(404);
    exit;
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
    exit;
}

$paths = photo_row_display_paths($row);
$rel = $paths[$variant] ?? $paths['display'] ?? '';
$abs = photo_absolute_path($rel);
if ($abs === '' || !is_file($abs) || filesize($abs) <= 0) {
    foreach (['thumb', 'display', 'original'] as $fallback) {
        $rel = $paths[$fallback] ?? '';
        $abs = photo_absolute_path($rel);
        if ($abs !== '' && is_file($abs) && filesize($abs) > 0) {
            break;
        }
    }
}

if ($abs === '' || !is_file($abs) || filesize($abs) <= 0) {
    http_response_code(404);
    exit;
}

$info = @getimagesize($abs);
$mime = $info['mime'] ?? 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, max-age=120, must-revalidate');
readfile($abs);
exit;
