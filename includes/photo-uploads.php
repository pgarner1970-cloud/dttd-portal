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
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
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

function photo_alloc($im, $rgb, $alpha = 0) {
    return imagecolorallocatealpha($im, (int)$rgb[0], (int)$rgb[1], (int)$rgb[2], (int)$alpha);
}

function photo_draw_rounded_rect($im, $x1, $y1, $x2, $y2, $radius, $color, $filled = true) {
    $x1 = (int)$x1; $y1 = (int)$y1; $x2 = (int)$x2; $y2 = (int)$y2; $radius = (int)$radius;
    if ($filled) {
        imagefilledrectangle($im, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagefilledarc($im, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, 180, 270, $color, IMG_ARC_PIE);
        imagefilledarc($im, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, 270, 360, $color, IMG_ARC_PIE);
        imagefilledarc($im, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, 0, 90, $color, IMG_ARC_PIE);
        imagefilledarc($im, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, 90, 180, $color, IMG_ARC_PIE);
    } else {
        imageline($im, $x1 + $radius, $y1, $x2 - $radius, $y1, $color);
        imageline($im, $x1 + $radius, $y2, $x2 - $radius, $y2, $color);
        imageline($im, $x1, $y1 + $radius, $x1, $y2 - $radius, $color);
        imageline($im, $x2, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagearc($im, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, 180, 270, $color);
        imagearc($im, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, 270, 360, $color);
        imagearc($im, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, 0, 90, $color);
        imagearc($im, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, 90, 180, $color);
    }
}

function photo_draw_glow_rounded_rect($im, $x1, $y1, $x2, $y2, $radius, $rgb, $borderRgb = null) {
    $borderRgb = $borderRgb ?: $rgb;
    for ($i = 14; $i >= 2; $i -= 2) {
        $alpha = min(120, 120 - ($i * 5));
        $c = photo_alloc($im, $rgb, $alpha);
        for ($n = 0; $n < 2; $n++) {
            photo_draw_rounded_rect($im, $x1 - $i - $n, $y1 - $i - $n, $x2 + $i + $n, $y2 + $i + $n, $radius + $i, $c, false);
        }
    }
    $c1 = photo_alloc($im, $borderRgb, 0);
    $c2 = photo_alloc($im, [255, 185, 255], 15);
    for ($n = 0; $n < 4; $n++) {
        photo_draw_rounded_rect($im, $x1 - $n, $y1 - $n, $x2 + $n, $y2 + $n, $radius + $n, $n === 0 ? $c2 : $c1, false);
    }
}

function photo_ttf_bbox_width($size, $font, $text) {
    $box = imagettfbbox($size, 0, $font, $text);
    return abs($box[2] - $box[0]);
}

function photo_fit_font_size($text, $font, $maxWidth, $start, $min) {
    if (!is_file($font) || !function_exists('imagettfbbox')) return $start;
    for ($size = $start; $size >= $min; $size--) {
        if (photo_ttf_bbox_width($size, $font, $text) <= $maxWidth) return $size;
    }
    return $min;
}

function photo_draw_centered_ttf($im, $text, $size, $y, $color, $font, $x1, $x2, $angle = 0) {
    if (!is_file($font) || !function_exists('imagettftext')) return false;
    $w = photo_ttf_bbox_width($size, $font, $text);
    $x = (int)round($x1 + (($x2 - $x1 - $w) / 2));
    imagettftext($im, $size, $angle, $x, (int)$y, $color, $font, $text);
    return true;
}

function photo_draw_glow_text($im, $text, $size, $y, $color, $glowRgb, $font, $x1, $x2, $angle = 0) {
    if (!is_file($font) || !function_exists('imagettftext')) return false;
    $w = photo_ttf_bbox_width($size, $font, $text);
    $x = (int)round($x1 + (($x2 - $x1 - $w) / 2));
    for ($r = 7; $r >= 1; $r--) {
        $gc = photo_alloc($im, $glowRgb, 108);
        imagettftext($im, $size, $angle, $x - $r, (int)$y, $gc, $font, $text);
        imagettftext($im, $size, $angle, $x + $r, (int)$y, $gc, $font, $text);
        imagettftext($im, $size, $angle, $x, (int)$y - $r, $gc, $font, $text);
        imagettftext($im, $size, $angle, $x, (int)$y + $r, $gc, $font, $text);
    }
    imagettftext($im, $size, $angle, $x, (int)$y, $color, $font, $text);
    return true;
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

    // Portrait cards are best for socials; landscape photos get a polished widescreen card.
    $canvasW = $portrait ? 1080 : 1600;
    $canvasH = $portrait ? 1350 : 1200;
    $im = imagecreatetruecolor($canvasW, $canvasH);
    imageantialias($im, true);
    imagesavealpha($im, true);

    $deepPurple = [18, 0, 31];
    $purple = [50, 0, 78];
    $hotPink = [235, 54, 255];
    $softPink = [255, 158, 255];
    $goldRgb = [255, 220, 86];
    $whiteRgb = [255, 255, 255];

    // Background: dark purple with a subtle stage-light/ray feel.
    photo_draw_gradient($im, 0, 0, $canvasW, $canvasH, $deepPurple, $purple);
    $ray = photo_alloc($im, [120, 39, 160], 118);
    $cx = (int)($canvasW / 2);
    $cy = (int)($canvasH * 0.08);
    for ($a = -70; $a <= 250; $a += 18) {
        $x1 = $cx + (int)(cos(deg2rad($a)) * $canvasW * 1.2);
        $y1 = $cy + (int)(sin(deg2rad($a)) * $canvasH * 1.1);
        $x2 = $cx + (int)(cos(deg2rad($a + 8)) * $canvasW * 1.2);
        $y2 = $cy + (int)(sin(deg2rad($a + 8)) * $canvasH * 1.1);
        imagefilledpolygon($im, [$cx, $cy, $x1, $y1, $x2, $y2], 3, $ray);
    }
    imagefilledellipse($im, $cx, (int)($canvasH * 0.14), (int)($canvasW * 0.85), (int)($canvasH * 0.22), photo_alloc($im, [200, 60, 255], 112));

    $margin = $portrait ? 88 : 90;
    $cardX1 = $margin;
    $cardY1 = $portrait ? 54 : 50;
    $cardX2 = $canvasW - $margin;
    $cardY2 = $canvasH - ($portrait ? 58 : 54);
    $cardRadius = $portrait ? 58 : 48;

    photo_draw_rounded_rect($im, $cardX1, $cardY1, $cardX2, $cardY2, $cardRadius, photo_alloc($im, [24, 0, 35], 8), true);
    photo_draw_glow_rounded_rect($im, $cardX1, $cardY1, $cardX2, $cardY2, $cardRadius, $hotPink, $softPink);

    $cardW = $cardX2 - $cardX1;
    $cardH = $cardY2 - $cardY1;
    $headerH = $portrait ? 118 : 120;
    $footerH = $portrait ? 260 : 250;
    $photoPad = $portrait ? 36 : 46;
    $photoX = $cardX1 + $photoPad;
    $photoY = $cardY1 + $headerH;
    $photoW = $cardW - ($photoPad * 2);
    $photoH = $cardH - $headerH - $footerH;

    // Draw a dark placeholder, then cover-crop the source photo into the window.
    imagefilledrectangle($im, $photoX, $photoY, $photoX + $photoW, $photoY + $photoH, photo_alloc($im, [5, 5, 14], 0));
    $scale = max($photoW / max(1, $srcW), $photoH / max(1, $srcH));
    $cropW = (int)round($photoW / $scale);
    $cropH = (int)round($photoH / $scale);
    $cropX = (int)max(0, floor(($srcW - $cropW) / 2));
    $cropY = (int)max(0, floor(($srcH - $cropH) / 2));
    photo_resize_cover($src, $im, $cropX, $cropY, $cropW, $cropH, $photoW, $photoH);

    // Soft vignette and neon dividers.
    imagefilledrectangle($im, $photoX, $photoY, $photoX + $photoW, $photoY + 70, photo_alloc($im, [12, 0, 22], 104));
    imagefilledrectangle($im, $photoX, $photoY + $photoH - 80, $photoX + $photoW, $photoY + $photoH, photo_alloc($im, [12, 0, 22], 102));
    $line = photo_alloc($im, $hotPink, 0);
    for ($i = 0; $i < 5; $i++) {
        imageline($im, $photoX, $photoY - 2 + $i, $photoX + $photoW, $photoY - 28 + $i, $i === 2 ? $line : photo_alloc($im, $hotPink, 78));
        imageline($im, $photoX, $photoY + $photoH + 2 + $i, $photoX + $photoW, $photoY + $photoH - 18 + $i, $i === 2 ? $line : photo_alloc($im, $hotPink, 82));
    }

    // Header logo and title.
    $logoPath = dirname(__DIR__) . '/assets/dttd-logo-inner.png';
    if (is_file($logoPath)) {
        $logo = photo_load_image_resource($logoPath, $logoMime);
        if ($logo) {
            $logoW = imagesx($logo);
            $logoH = imagesy($logo);
            $targetH = $portrait ? 128 : 100;
            $targetW = (int)round($logoW * ($targetH / max(1, $logoH)));
            $lx = $cardX1 + ($portrait ? 28 : 34);
            $ly = $cardY1 + ($portrait ? 24 : 18);
            imagecopyresampled($im, $logo, $lx, $ly, 0, 0, $targetW, $targetH, $logoW, $logoH);
            imagedestroy($logo);
        }
    }

    $fontBold = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    $fontRegular = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    $fontMono = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf';
    $white = photo_alloc($im, $whiteRgb, 0);
    $gold = photo_alloc($im, $goldRgb, 0);
    $pink = photo_alloc($im, $hotPink, 0);

    if (is_file($fontBold) && function_exists('imagettftext')) {
        $eventPhoto = 'E V E N T   P H O T O';
        $titleSize = $portrait ? 24 : 22;
        $titleW = photo_ttf_bbox_width($titleSize, $fontBold, $eventPhoto);
        imagettftext($im, $titleSize, 0, $cardX2 - $titleW - ($portrait ? 62 : 74), $cardY1 + ($portrait ? 76 : 74), $gold, $fontBold, $eventPhoto);
    }

    $eventName = trim((string)($event['event_name'] ?? 'Dance Thru The Decades')) ?: 'Dance Thru The Decades';
    $venueLine = trim((string)($event['venue_name'] ?? ''));
    $dateLine = '';
    if (!empty($event['event_date'])) {
        try {
            $dateLine = (new DateTime($event['event_date']))->format('D j M Y');
        } catch (Throwable $e) {
            $dateLine = (string)$event['event_date'];
        }
    }

    $footerTop = $photoY + $photoH + ($portrait ? 56 : 52);
    if (is_file($fontBold) && function_exists('imagettftext')) {
        $nameSize = photo_fit_font_size($eventName, $fontBold, $cardW - 170, $portrait ? 44 : 42, 24);
        photo_draw_glow_text($im, $eventName, $nameSize, $footerTop + ($portrait ? 16 : 4), $white, [255, 80, 255], $fontBold, $cardX1 + 60, $cardX2 - 60);

        if ($venueLine !== '') {
            $venueSize = photo_fit_font_size($venueLine, $fontBold, $cardW - 190, $portrait ? 28 : 26, 18);
            photo_draw_centered_ttf($im, $venueLine, $venueSize, $footerTop + ($portrait ? 66 : 52), $gold, $fontBold, $cardX1 + 74, $cardX2 - 74);
        }

        if ($dateLine !== '') {
            $dateSize = $portrait ? 25 : 23;
            $starGap = $portrait ? 34 : 32;
            $dateW = photo_ttf_bbox_width($dateSize, $fontBold, $dateLine);
            $dateX1 = $cardX1 + (($cardW - $dateW) / 2);
            imagettftext($im, $dateSize, 0, (int)$dateX1, $footerTop + ($portrait ? 108 : 92), $white, $fontBold, $dateLine);
            imagettftext($im, $dateSize + 8, 0, (int)($dateX1 - $starGap), $footerTop + ($portrait ? 108 : 92), $pink, $fontBold, '★');
            imagettftext($im, $dateSize + 8, 0, (int)($dateX1 + $dateW + 12), $footerTop + ($portrait ? 108 : 92), $pink, $fontBold, '★');
        }

        $strap = 'D A N C E   T H R U   T H E   D E C A D E S   E V E N T S';
        $strapSize = photo_fit_font_size($strap, $fontBold, $cardW - 170, $portrait ? 15 : 14, 10);
        photo_draw_centered_ttf($im, $strap, $strapSize, $cardY2 - ($portrait ? 52 : 46), $pink, $fontBold, $cardX1 + 60, $cardX2 - 60);
    } else {
        imagestring($im, 5, $cardX1 + 40, $footerTop, $eventName, $white);
    }

    // Decorative sparkle stars in the footer.
    if (is_file($fontBold) && function_exists('imagettftext')) {
        $sparkles = [
            [$cardX1 + (int)($cardW * 0.17), $footerTop + 88, 26],
            [$cardX1 + (int)($cardW * 0.24), $footerTop + 64, 24],
            [$cardX1 + (int)($cardW * 0.29), $footerTop + 112, 22],
            [$cardX2 - (int)($cardW * 0.20), $footerTop + 74, 25],
            [$cardX2 - (int)($cardW * 0.13), $footerTop + 106, 23],
        ];
        foreach ($sparkles as $sp) {
            imagettftext($im, $sp[2], 0, $sp[0], $sp[1], photo_alloc($im, [238, 78, 255], 15), $fontBold, '✦');
        }
    }

    $saved = photo_save_image_resource($im, $destPath, 92);
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
?>