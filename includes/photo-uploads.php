<?php
require_once __DIR__ . '/db.php';

function photo_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function photo_column_exists($table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        $cache[$key] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function photo_upload_base_dir() {
    return dirname(__DIR__) . '/uploads/event-photos';
}

function photo_relative_to_public($path) {
    return ltrim(str_replace('\\', '/', (string)$path), '/');
}

function photo_public_url($path) {
    $path = trim(str_replace('\\', '/', (string)$path));
    if ($path === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }

    $siteRoot = realpath(dirname(__DIR__));
    $pathReal = realpath($path);
    if ($siteRoot && $pathReal && strpos($pathReal, $siteRoot) === 0) {
        $path = ltrim(substr($pathReal, strlen($siteRoot)), '/');
    }

    $path = preg_replace('~^\./+~', '', $path);
    while (strpos($path, '../') === 0) {
        $path = substr($path, 3);
    }
    $path = preg_replace('~^/?dttd-portal/~', '', $path);
    $path = preg_replace('~^/?public_html/~', '', $path);

    return '/' . ltrim($path, '/');
}

function photo_storage_directories() {
    $base = photo_upload_base_dir();
    $dirs = [
        'base' => $base,
        'original' => $base . '/original',
        'framed' => $base . '/framed',
        'thumbs' => $base . '/thumbs',
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    return $dirs;
}

function photo_selectable_events() {
    $events = [];
    $seen = [];

    try {
        $current = active_event();
        if ($current) {
            $events[] = $current;
            $seen[(int)$current['id']] = true;
        }
    } catch (Throwable $e) {
    }

    try {
        $stmt = db()->query("\n            SELECT *\n            FROM events\n            WHERE event_date IS NOT NULL\n              AND event_date <= CURDATE()\n            ORDER BY event_date DESC, start_time DESC, id DESC\n        ");
        foreach ($stmt->fetchAll() as $event) {
            $id = (int)($event['id'] ?? 0);
            if ($id && !isset($seen[$id])) {
                $events[] = $event;
                $seen[$id] = true;
            }
        }
    } catch (Throwable $e) {
    }

    return $events;
}

function photo_get_event($eventId) {
    if (!$eventId) {
        return null;
    }
    try {
        $stmt = db()->prepare("SELECT * FROM events WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$eventId]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function photo_event_label($event) {
    $name = trim((string)($event['event_name'] ?? 'Event'));
    $venue = trim((string)($event['venue_name'] ?? ''));
    $date = '';
    if (!empty($event['event_date'])) {
        try {
            $date = (new DateTime($event['event_date']))->format('D j M Y');
        } catch (Throwable $e) {
            $date = (string)$event['event_date'];
        }
    }

    $parts = array_filter([$name, $venue, $date]);
    return implode(' — ', $parts);
}

function photo_can_select_event($event) {
    if (!$event) {
        return false;
    }
    if (!empty($event['event_date'])) {
        try {
            $eventDate = new DateTime($event['event_date']);
            $today = new DateTime(date('Y-m-d'));
            return $eventDate <= $today || event_is_available($event);
        } catch (Throwable $e) {
            return true;
        }
    }
    return true;
}

function photo_load_image_resource($path, &$mime = null) {
    $info = @getimagesize($path);
    if (!$info || empty($info['mime'])) {
        return null;
    }

    $mime = $info['mime'];
    switch ($mime) {
        case 'image/jpeg':
            return @imagecreatefromjpeg($path);
        case 'image/png':
            return @imagecreatefrompng($path);
        case 'image/webp':
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
        case 'image/gif':
            return @imagecreatefromgif($path);
        default:
            return null;
    }
}

function photo_save_image_resource($im, $path, $quality = 90) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'png':
            imagesavealpha($im, true);
            return imagepng($im, $path, 6);
        case 'webp':
            return function_exists('imagewebp') ? imagewebp($im, $path, $quality) : imagejpeg($im, preg_replace('~\.webp$~i', '.jpg', $path), $quality);
        default:
            return imagejpeg($im, $path, $quality);
    }
}

function photo_resize_cover($src, $dest, $srcX, $srcY, $srcW, $srcH, $destW, $destH) {
    imagecopyresampled($dest, $src, 0, 0, $srcX, $srcY, $destW, $destH, $srcW, $srcH);
}

function photo_draw_gradient($im, $x, $y, $w, $h, $from, $to) {
    for ($i = 0; $i < $h; $i++) {
        $t = $h > 1 ? $i / ($h - 1) : 0;
        $r = (int)round($from[0] + ($to[0] - $from[0]) * $t);
        $g = (int)round($from[1] + ($to[1] - $from[1]) * $t);
        $b = (int)round($from[2] + ($to[2] - $from[2]) * $t);
        $color = imagecolorallocate($im, $r, $g, $b);
        imageline($im, $x, $y + $i, $x + $w, $y + $i, $color);
    }
}

function photo_render_framed_image($sourcePath, $destPath, $event, $orientation = 'portrait') {
    if (!extension_loaded('gd')) {
        return false;
    }

    $src = photo_load_image_resource($sourcePath, $mime);
    if (!$src) {
        return false;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    $portrait = $orientation !== 'landscape';
    $canvasW = $portrait ? 1080 : 1600;
    $canvasH = $portrait ? 1350 : 1200;

    $im = imagecreatetruecolor($canvasW, $canvasH);
    imageantialias($im, true);

    photo_draw_gradient($im, 0, 0, $canvasW, $canvasH, [18, 0, 35], [48, 0, 70]);
    $glow = imagecolorallocatealpha($im, 232, 61, 255, 100);
    imagefilledellipse($im, (int)($canvasW * 0.5), (int)($canvasH * 0.16), (int)($canvasW * 0.7), (int)($canvasH * 0.22), $glow);

    $panelX = 36;
    $panelY = 36;
    $panelW = $canvasW - 72;
    $panelH = $canvasH - 72;
    $panelBg = imagecolorallocatealpha($im, 20, 4, 34, 22);
    $panelBorder = imagecolorallocate($im, 198, 59, 219);
    imagefilledrectangle($im, $panelX, $panelY, $panelX + $panelW, $panelY + $panelH, $panelBg);
    imagerectangle($im, $panelX, $panelY, $panelX + $panelW, $panelY + $panelH, $panelBorder);

    $headerH = 120;
    $footerH = 160;
    $innerPad = 36;
    $photoX = $panelX + $innerPad;
    $photoY = $panelY + $headerH + 8;
    $photoW = $panelW - ($innerPad * 2);
    $photoH = $panelH - $headerH - $footerH - 16;

    $photoBg = imagecolorallocate($im, 10, 10, 22);
    imagefilledrectangle($im, $photoX, $photoY, $photoX + $photoW, $photoY + $photoH, $photoBg);

    $scale = max($photoW / $srcW, $photoH / $srcH);
    $cropW = (int)round($photoW / $scale);
    $cropH = (int)round($photoH / $scale);
    $cropX = (int)max(0, floor(($srcW - $cropW) / 2));
    $cropY = (int)max(0, floor(($srcH - $cropH) / 2));
    photo_resize_cover($src, $im, $cropX, $cropY, $cropW, $cropH, $photoW, $photoH);

    $frameColor = imagecolorallocate($im, 244, 78, 255);
    imagerectangle($im, $photoX - 3, $photoY - 3, $photoX + $photoW + 3, $photoY + $photoH + 3, $frameColor);

    $white = imagecolorallocate($im, 255, 255, 255);
    $gold = imagecolorallocate($im, 255, 222, 88);
    $muted = imagecolorallocate($im, 220, 205, 240);

    $fontBold = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    $fontRegular = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

    $logoPath = dirname(__DIR__) . '/assets/dttd-logo-inner.png';
    if (is_file($logoPath)) {
        $logo = photo_load_image_resource($logoPath, $logoMime);
        if ($logo) {
            $logoW = imagesx($logo);
            $logoH = imagesy($logo);
            $targetH = 72;
            $targetW = (int)round($logoW * ($targetH / max(1, $logoH)));
            imagecopyresampled($im, $logo, $panelX + 28, $panelY + 24, 0, 0, $targetW, $targetH, $logoW, $logoH);
            imagedestroy($logo);
        }
    }

    if (is_file($fontBold) && function_exists('imagettftext')) {
        imagettftext($im, 18, 0, $panelX + $panelW - 190, $panelY + 52, $gold, $fontBold, 'EVENT PHOTO');
        imagettftext($im, 38, 0, $panelX + 34, $panelY + $panelH - 108, $white, $fontBold, trim((string)($event['event_name'] ?? 'Dance Thru The Decades')));
        $venueLine = trim((string)($event['venue_name'] ?? ''));
        $dateLine = '';
        if (!empty($event['event_date'])) {
            try {
                $dateLine = (new DateTime($event['event_date']))->format('D j M Y');
            } catch (Throwable $e) {
                $dateLine = (string)$event['event_date'];
            }
        }
        $secondLine = implode('  •  ', array_filter([$venueLine, $dateLine]));
        imagettftext($im, 21, 0, $panelX + 36, $panelY + $panelH - 60, $muted, $fontRegular, $secondLine ?: 'Dance Thru The Decades Events');
        imagettftext($im, 16, 0, $panelX + 36, $panelY + $panelH - 28, $gold, $fontBold, 'Dance Thru The Decades Events');
    } else {
        imagestring($im, 5, $panelX + 26, $panelY + 30, 'EVENT PHOTO', $gold);
        imagestring($im, 5, $panelX + 26, $panelY + $panelH - 88, trim((string)($event['event_name'] ?? 'Dance Thru The Decades')), $white);
    }

    $saved = photo_save_image_resource($im, $destPath, 90);
    imagedestroy($src);
    imagedestroy($im);
    return $saved;
}

function photo_render_thumb($sourcePath, $destPath, $size = 480) {
    $src = photo_load_image_resource($sourcePath, $mime);
    if (!$src) {
        return false;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $side = min($srcW, $srcH);
    $cropX = (int)max(0, floor(($srcW - $side) / 2));
    $cropY = (int)max(0, floor(($srcH - $side) / 2));

    $thumb = imagecreatetruecolor($size, $size);
    imagecopyresampled($thumb, $src, 0, 0, $cropX, $cropY, $size, $size, $side, $side);
    $saved = imagejpeg($thumb, $destPath, 86);
    imagedestroy($src);
    imagedestroy($thumb);
    return $saved;
}

function photo_process_uploaded_file($tmpPath, $originalName, $event) {
    $dirs = photo_storage_directories();

    $info = @getimagesize($tmpPath);
    if (!$info) {
        throw new RuntimeException('This file could not be read as an image.');
    }

    $mime = $info['mime'] ?? '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'jpg',
        'image/gif' => 'jpg',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Please upload a JPG, PNG, WEBP or GIF image.');
    }

    $srcW = (int)($info[0] ?? 0);
    $srcH = (int)($info[1] ?? 0);
    $orientation = ($srcW > ($srcH * 1.15)) ? 'landscape' : 'portrait';

    $base = 'photo-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $originalExt = $allowed[$mime] === 'jpg' ? strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: 'jpg') : $allowed[$mime];
    if (!preg_match('~^(jpg|jpeg|png|webp|gif)$~i', $originalExt)) {
        $originalExt = 'jpg';
    }

    $originalRel = 'uploads/event-photos/original/' . $base . '.' . $originalExt;
    $framedRel = 'uploads/event-photos/framed/' . $base . '.jpg';
    $thumbRel = 'uploads/event-photos/thumbs/' . $base . '.jpg';

    $originalAbs = dirname(__DIR__) . '/' . $originalRel;
    $framedAbs = dirname(__DIR__) . '/' . $framedRel;
    $thumbAbs = dirname(__DIR__) . '/' . $thumbRel;

    if (!@move_uploaded_file($tmpPath, $originalAbs) && !@rename($tmpPath, $originalAbs)) {
        throw new RuntimeException('The uploaded file could not be saved.');
    }

    if (!photo_render_framed_image($originalAbs, $framedAbs, $event, $orientation)) {
        copy($originalAbs, $framedAbs);
    }

    if (!photo_render_thumb($framedAbs, $thumbAbs, 540)) {
        copy($framedAbs, $thumbAbs);
    }

    return [
        'original_path' => $originalRel,
        'framed_path' => $framedRel,
        'thumb_path' => $thumbRel,
        'orientation' => $orientation,
    ];
}

function photo_insert_upload($event, $guestName, $originalName, array $paths) {
    $columns = ['event_id', 'guest_name', 'original_filename', 'file_path', 'status'];
    $values = [
        (int)$event['id'],
        trim((string)$guestName),
        trim((string)$originalName),
        $paths['framed_path'],
        'pending'
    ];

    $optional = [
        'original_path' => $paths['original_path'] ?? '',
        'framed_path' => $paths['framed_path'] ?? '',
        'thumb_path' => $paths['thumb_path'] ?? '',
        'image_orientation' => $paths['orientation'] ?? '',
    ];

    foreach ($optional as $column => $value) {
        if (photo_column_exists('event_photo_uploads', $column)) {
            $columns[] = $column;
            $values[] = $value;
        }
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO event_photo_uploads (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
    $stmt = db()->prepare($sql);
    $stmt->execute($values);
    return (int)db()->lastInsertId();
}

function photo_row_display_paths($row) {
    $file = trim((string)($row['file_path'] ?? ''));
    $framed = trim((string)($row['framed_path'] ?? ''));
    $thumb = trim((string)($row['thumb_path'] ?? ''));
    $original = trim((string)($row['original_path'] ?? ''));

    return [
        'display' => $framed ?: $file,
        'thumb' => $thumb ?: ($framed ?: $file),
        'original' => $original ?: ($framed ?: $file),
    ];
}

function photo_absolute_upload_path($path) {
    $path = trim(str_replace('\\', '/', (string)$path));
    if ($path === '') {
        return '';
    }

    $path = preg_replace('~^https?://[^/]+/~i', '', $path);
    $path = preg_replace('~^\./+~', '', $path);
    while (strpos($path, '../') === 0) {
        $path = substr($path, 3);
    }
    $path = preg_replace('~^/?dttd-portal/~', '', $path);
    $path = preg_replace('~^/?public_html/~', '', $path);
    $path = ltrim($path, '/');

    $root = realpath(dirname(__DIR__));
    $candidate = dirname(__DIR__) . '/' . $path;
    $real = realpath($candidate);

    if ($root && $real && strpos($real, $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR) === 0) {
        return $real;
    }

    // Allow deletion of a not-yet-realpath-resolvable file only when it is clearly inside uploads.
    if ($root && strpos($path, 'uploads/') === 0) {
        return $candidate;
    }

    return '';
}

function photo_delete_upload_files(array $row) {
    $paths = photo_row_display_paths($row);
    $extra = [
        $row['file_path'] ?? '',
        $row['original_path'] ?? '',
        $row['framed_path'] ?? '',
        $row['thumb_path'] ?? '',
        $paths['display'] ?? '',
        $paths['thumb'] ?? '',
        $paths['original'] ?? '',
    ];

    $deleted = 0;
    $seen = [];
    foreach ($extra as $path) {
        $abs = photo_absolute_upload_path($path);
        if ($abs === '' || isset($seen[$abs])) {
            continue;
        }
        $seen[$abs] = true;
        if (is_file($abs) && @unlink($abs)) {
            $deleted++;
        }
    }
    return $deleted;
}

function photo_delete_upload_permanently($photoId) {
    $stmt = db()->prepare('SELECT * FROM event_photo_uploads WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$photoId]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }

    photo_delete_upload_files($row);
    $delete = db()->prepare('DELETE FROM event_photo_uploads WHERE id = ? LIMIT 1');
    $delete->execute([(int)$photoId]);
    return true;
}

?>