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

function photo_current_upload_event() {
    // Prefer an event taking place today. Do not trust is_active alone, because
    // future public events may also be marked active for display on the website.
    try {
        $stmt = db()->query("
            SELECT *
            FROM events
            WHERE event_date = CURDATE()
            ORDER BY
                CASE WHEN is_active = 1 THEN 0 ELSE 1 END,
                COALESCE(start_time, '00:00:00') ASC,
                id DESC
            LIMIT 1
        ");
        $event = $stmt->fetch();
        if ($event) {
            return $event;
        }
    } catch (Throwable $e) {
    }

    try {
        $event = active_event();
        if ($event && !empty($event['event_date'])) {
            $eventDate = new DateTime($event['event_date']);
            $today = new DateTime(date('Y-m-d'));
            if ($eventDate <= $today) {
                return $event;
            }
        }
    } catch (Throwable $e) {
    }

    return null;
}

function photo_selectable_events() {
    $events = [];
    $seen = [];

    try {
        $stmt = db()->query("
            SELECT *
            FROM events
            WHERE event_date IS NOT NULL
              AND event_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
              AND event_date <= CURDATE()
            ORDER BY event_date ASC, COALESCE(start_time, '00:00:00') ASC, id ASC
        ");
        foreach ($stmt->fetchAll() as $event) {
            $id = (int)($event['id'] ?? 0);
            if ($id && !isset($seen[$id])) {
                $events[] = $event;
                $seen[$id] = true;
            }
        }
    } catch (Throwable $e) {
    }

    $current = photo_current_upload_event();
    if ($current) {
        $id = (int)($current['id'] ?? 0);
        if ($id && !isset($seen[$id])) {
            $events[] = $current;
        }
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
    if (!$event || empty($event['event_date'])) {
        return false;
    }

    try {
        $eventDate = new DateTime($event['event_date']);
        $today = new DateTime(date('Y-m-d'));
        return $eventDate <= $today;
    } catch (Throwable $e) {
        return false;
    }
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


function photo_allocate($im, $rgb, $alpha = 0) {
    return imagecolorallocatealpha($im, (int)$rgb[0], (int)$rgb[1], (int)$rgb[2], max(0, min(127, (int)$alpha)));
}

function photo_fill_rounded_rect($im, $x1, $y1, $x2, $y2, $radius, $color) {
    $x1 = (int)$x1; $y1 = (int)$y1; $x2 = (int)$x2; $y2 = (int)$y2; $radius = (int)$radius;
    imagefilledrectangle($im, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledrectangle($im, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    imagefilledellipse($im, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($im, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($im, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($im, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
}

function photo_stroke_rounded_rect($im, $x1, $y1, $x2, $y2, $radius, $color, $thickness = 3) {
    imagesetthickness($im, max(1, (int)$thickness));
    $x1 = (int)$x1; $y1 = (int)$y1; $x2 = (int)$x2; $y2 = (int)$y2; $radius = (int)$radius;
    imageline($im, $x1 + $radius, $y1, $x2 - $radius, $y1, $color);
    imageline($im, $x1 + $radius, $y2, $x2 - $radius, $y2, $color);
    imageline($im, $x1, $y1 + $radius, $x1, $y2 - $radius, $color);
    imageline($im, $x2, $y1 + $radius, $x2, $y2 - $radius, $color);
    imagearc($im, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, 180, 270, $color);
    imagearc($im, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, 270, 360, $color);
    imagearc($im, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, 0, 90, $color);
    imagearc($im, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, 90, 180, $color);
    imagesetthickness($im, 1);
}

function photo_draw_centered_text($im, $text, $size, $y, $color, $font, $maxW, $xCenter) {
    $text = trim((string)$text);
    if ($text === '' || !is_file($font) || !function_exists('imagettftext')) {
        return;
    }
    while ($size > 10) {
        $box = imagettfbbox($size, 0, $font, $text);
        $w = abs($box[2] - $box[0]);
        if ($w <= $maxW) {
            break;
        }
        $size -= 2;
    }
    $box = imagettfbbox($size, 0, $font, $text);
    $w = abs($box[2] - $box[0]);
    $x = (int)round($xCenter - ($w / 2));
    imagettftext($im, $size, 0, $x, (int)$y, $color, $font, $text);
}

function photo_draw_letterspaced_text($im, $text, $size, $x, $y, $color, $font, $spacing = 8) {
    $text = (string)$text;
    if (!is_file($font) || !function_exists('imagettftext')) {
        imagestring($im, 5, (int)$x, (int)$y - 14, $text, $color);
        return;
    }
    $cx = (int)$x;
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        imagettftext($im, $size, 0, $cx, (int)$y, $color, $font, $ch);
        $box = imagettfbbox($size, 0, $font, $ch);
        $cx += abs($box[2] - $box[0]) + (int)$spacing;
    }
}

function photo_draw_star($im, $cx, $cy, $r, $color) {
    $pts = [];
    for ($i = 0; $i < 8; $i++) {
        $ang = deg2rad(-90 + $i * 45);
        $rr = ($i % 2 === 0) ? $r : max(2, $r * 0.28);
        $pts[] = (int)round($cx + cos($ang) * $rr);
        $pts[] = (int)round($cy + sin($ang) * $rr);
    }
    imagefilledpolygon($im, $pts, 8, $color);
}


function photo_draw_text_glow($im, $text, $size, $x, $y, $color, $font, $glowColor = null, $glowRadius = 2) {
    $text = (string)$text;
    if ($text === '') { return; }
    if (!is_file($font) || !function_exists('imagettftext')) {
        imagestring($im, 5, (int)$x, (int)$y - 14, $text, $color);
        return;
    }
    if ($glowColor !== null && $glowRadius > 0) {
        for ($dx = -$glowRadius; $dx <= $glowRadius; $dx++) {
            for ($dy = -$glowRadius; $dy <= $glowRadius; $dy++) {
                if ($dx === 0 && $dy === 0) { continue; }
                imagettftext($im, $size, 0, (int)$x + $dx, (int)$y + $dy, $glowColor, $font, $text);
            }
        }
    }
    imagettftext($im, $size, 0, (int)$x, (int)$y, $color, $font, $text);
}

function photo_copy_contain_to_rect($src, $dest, $srcW, $srcH, $destX, $destY, $destW, $destH, $bgColor = null) {
    if ($bgColor !== null) {
        imagefilledrectangle($dest, (int)$destX, (int)$destY, (int)($destX + $destW), (int)($destY + $destH), $bgColor);
    }
    $scale = min($destW / max(1, $srcW), $destH / max(1, $srcH));
    $drawW = (int)round($srcW * $scale);
    $drawH = (int)round($srcH * $scale);
    $drawX = (int)round($destX + (($destW - $drawW) / 2));
    $drawY = (int)round($destY + (($destH - $drawH) / 2));
    imagecopyresampled($dest, $src, $drawX, $drawY, 0, 0, $drawW, $drawH, $srcW, $srcH);
}

function photo_copy_cover_to_rect($src, $dest, $srcW, $srcH, $destX, $destY, $destW, $destH) {
    $scale = max($destW / max(1, $srcW), $destH / max(1, $srcH));
    $cropW = (int)round($destW / $scale);
    $cropH = (int)round($destH / $scale);
    $cropX = (int)max(0, floor(($srcW - $cropW) / 2));
    $cropY = (int)max(0, floor(($srcH - $cropH) / 2));
    imagecopyresampled($dest, $src, (int)$destX, (int)$destY, $cropX, $cropY, (int)$destW, (int)$destH, $cropW, $cropH);
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
    $portraitSource = $srcH >= $srcW;
    $landscape = ($orientation === 'landscape' || (!$portraitSource && $srcW > $srcH * 1.10));

    // Branded artwork output. Portrait is close to a social 4:5 card; landscape keeps a wider event-card layout.
    $canvasW = $landscape ? 1600 : 1080;
    $canvasH = $landscape ? 1200 : 1350;

    $im = imagecreatetruecolor($canvasW, $canvasH);
    imagealphablending($im, true);
    imagesavealpha($im, true);
    imageantialias($im, true);

    $black = photo_allocate($im, [4, 0, 10]);
    imagefilledrectangle($im, 0, 0, $canvasW, $canvasH, $black);
    photo_draw_gradient($im, 0, 0, $canvasW, $canvasH, [16, 0, 29], [50, 0, 72]);

    // Subtle disco rays.
    $cx = (int)($canvasW / 2);
    $cy = (int)($canvasH * 0.52);
    $rayA = photo_allocate($im, [126, 38, 168], 112);
    $rayB = photo_allocate($im, [255, 75, 236], 121);
    for ($i = 0; $i < 36; $i += 2) {
        $a1 = deg2rad($i * 360 / 36 - 90);
        $a2 = deg2rad(($i + 1) * 360 / 36 - 90);
        $pts = [$cx, $cy,
            (int)round($cx + cos($a1) * $canvasW * 1.2), (int)round($cy + sin($a1) * $canvasW * 1.2),
            (int)round($cx + cos($a2) * $canvasW * 1.2), (int)round($cy + sin($a2) * $canvasW * 1.2)
        ];
        imagefilledpolygon($im, $pts, 3, ($i % 4 === 0) ? $rayA : $rayB);
    }

    $pink = photo_allocate($im, [249, 63, 255]);
    $pinkLight = photo_allocate($im, [255, 145, 255]);
    $pinkSoft = photo_allocate($im, [249, 63, 255], 76);
    $pinkGlow = photo_allocate($im, [249, 63, 255], 104);
    $panelBg = photo_allocate($im, [16, 0, 27], 4);
    $photoBg = photo_allocate($im, [6, 3, 14]);
    $white = photo_allocate($im, [255, 255, 255]);
    $whiteGlow = photo_allocate($im, [255, 154, 255], 92);
    $gold = photo_allocate($im, [255, 222, 75]);
    $footerPink = photo_allocate($im, [252, 89, 255]);

    $panelX = $landscape ? 82 : 82;
    $panelY = $landscape ? 62 : 66;
    $panelW = $canvasW - ($panelX * 2);
    $panelH = $canvasH - ($panelY * 2);
    $radius = $landscape ? 56 : 58;

    // Glowing outer frame.
    for ($i = 22; $i >= 5; $i -= 4) {
        photo_stroke_rounded_rect($im, $panelX - $i, $panelY - $i, $panelX + $panelW + $i, $panelY + $panelH + $i, $radius + $i, $pinkGlow, 3);
    }
    photo_fill_rounded_rect($im, $panelX, $panelY, $panelX + $panelW, $panelY + $panelH, $radius, $panelBg);
    photo_stroke_rounded_rect($im, $panelX, $panelY, $panelX + $panelW, $panelY + $panelH, $radius, $pink, 5);
    photo_stroke_rounded_rect($im, $panelX + 8, $panelY + 8, $panelX + $panelW - 8, $panelY + $panelH - 8, $radius - 10, $pinkLight, 1);

    // Layout measurements designed to keep logo/header/footer inside the final artwork.
    // The framed image is final branded artwork, so keep generous safe areas for the logo and event text.
    $innerPad = $landscape ? 58 : 54;
    $headerH = $landscape ? 128 : 150;
    $footerH = $landscape ? 310 : 330;
    $photoX = $panelX + $innerPad;
    $photoY = $panelY + $headerH;
    $photoW = $panelW - ($innerPad * 2);
    $photoH = $panelH - $headerH - $footerH;

    imagefilledrectangle($im, $photoX, $photoY, $photoX + $photoW, $photoY + $photoH, $photoBg);
    photo_copy_cover_to_rect($src, $im, $srcW, $srcH, $photoX, $photoY, $photoW, $photoH);

    // Dramatic angled neon separators, similar to the mock-up.
    imagesetthickness($im, $landscape ? 6 : 5);
    imageline($im, $photoX, $photoY - 7, $photoX + $photoW, $photoY + ($landscape ? 20 : 28), $pink);
    imageline($im, $photoX, $photoY + $photoH - ($landscape ? 18 : 28), $photoX + $photoW, $photoY + $photoH + 8, $pink);
    imagesetthickness($im, 1);

    // Top/bottom photo shading for a more premium composite.
    imagefilledrectangle($im, $photoX, $photoY, $photoX + $photoW, $photoY + (int)($photoH * 0.11), photo_allocate($im, [0, 0, 0], 86));
    imagefilledrectangle($im, $photoX, $photoY + (int)($photoH * 0.86), $photoX + $photoW, $photoY + $photoH, photo_allocate($im, [0, 0, 0], 91));

    // Logo.
    $logoPath = dirname(__DIR__) . '/assets/dttd-logo-inner.png';
    if (!is_file($logoPath)) {
        $logoPath = dirname(__DIR__) . '/assets/dttd-neon-logo.png';
    }
    if (is_file($logoPath)) {
        $logo = photo_load_image_resource($logoPath, $logoMime);
        if ($logo) {
            $logoW = imagesx($logo);
            $logoH = imagesy($logo);
            $targetH = $landscape ? 108 : 125;
            $targetW = (int)round($logoW * ($targetH / max(1, $logoH)));
            imagecopyresampled($im, $logo, $panelX + ($landscape ? 42 : 34), $panelY + ($landscape ? 18 : 18), 0, 0, $targetW, $targetH, $logoW, $logoH);
            imagedestroy($logo);
        }
    }

    $fontBold = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    $fontRegular = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

    // Header label.
    $eventPhotoX = $panelX + $panelW - ($landscape ? 390 : 342);
    $eventPhotoY = $panelY + ($landscape ? 78 : 92);
    photo_draw_letterspaced_text($im, 'EVENT PHOTO', $landscape ? 26 : 25, $eventPhotoX + 2, $eventPhotoY + 2, $whiteGlow, $fontBold, 9);
    photo_draw_letterspaced_text($im, 'EVENT PHOTO', $landscape ? 26 : 25, $eventPhotoX, $eventPhotoY, $gold, $fontBold, 9);

    $eventName = trim((string)($event['event_name'] ?? $event['name'] ?? 'Dance Thru The Decades'));
    $venueLine = trim((string)($event['venue_name'] ?? $event['venue'] ?? ''));
    $dateLine = '';
    if (!empty($event['event_date'])) {
        try {
            $dateLine = (new DateTime($event['event_date']))->format('D j M Y');
        } catch (Throwable $e) {
            $dateLine = (string)$event['event_date'];
        }
    }

    $footerTop = $photoY + $photoH + ($landscape ? 24 : 36);
    $footerBottom = $panelY + $panelH - 35;
    photo_draw_gradient($im, $panelX + 30, $footerTop - 6, $panelW - 60, $footerBottom - ($footerTop - 6), [35, 0, 49], [13, 0, 24]);
    imagefilledrectangle($im, $panelX + 30, $footerTop - 6, $panelX + $panelW - 30, $footerBottom, photo_allocate($im, [32, 0, 45], 52));

    // Event footer text inside the branded artwork. Use field fallbacks because older event rows use `name`/`venue`.
    if ($eventName === '') { $eventName = 'Dance Thru The Decades'; }
    if ($venueLine === '') { $venueLine = 'Dance Thru The Decades Events'; }
    $lineDateVenue = trim($dateLine !== '' ? ($venueLine . ' • ' . $dateLine) : $venueLine);

    $titleY = $footerTop + ($landscape ? 83 : 94);
    $venueY = $footerTop + ($landscape ? 139 : 155);
    $dateY = $footerTop + ($landscape ? 190 : 209);
    photo_draw_centered_text($im, $eventName, $landscape ? 54 : 58, $titleY + 3, $whiteGlow, $fontBold, $panelW - 235, $cx + 2);
    photo_draw_centered_text($im, $eventName, $landscape ? 53 : 57, $titleY, $white, $fontBold, $panelW - 235, $cx);
    photo_draw_centered_text($im, $venueLine, $landscape ? 33 : 34, $venueY, $gold, $fontRegular, $panelW - 270, $cx);
    if ($dateLine !== '') {
        photo_draw_centered_text($im, $dateLine, $landscape ? 31 : 32, $dateY, $white, $fontBold, $panelW - 270, $cx);
    }

    // Decorative stars around the footer, but avoid overlapping text on narrower portrait cards.
    $starY = $footerTop + ($landscape ? 170 : 188);
    foreach ([-420, -330, 330, 420] as $offset) {
        if (!$landscape && abs($offset) > 340) { continue; }
        photo_draw_star($im, $cx + $offset, $starY + (($offset % 2) ? 0 : 18), $landscape ? 17 : 16, $footerPink);
    }
    photo_draw_star($im, $cx - ($landscape ? 145 : 142), $starY + ($landscape ? 34 : 35), $landscape ? 16 : 16, $footerPink);
    photo_draw_star($im, $cx + ($landscape ? 145 : 142), $starY + ($landscape ? 34 : 35), $landscape ? 16 : 16, $footerPink);

    // Footer brand line.
    $brandText = 'DANCE THRU THE DECADES EVENTS';
    $brandX = $cx - ($landscape ? 225 : 205);
    photo_draw_letterspaced_text($im, $brandText, $landscape ? 16 : 15, $brandX, $panelY + $panelH - 42, $footerPink, $fontBold, 5);

    $saved = photo_save_image_resource($im, $destPath, 95);
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
    $thumb = imagecreatetruecolor($size, $size);
    imageantialias($thumb, true);
    $bg = photo_allocate($thumb, [19, 0, 30]);
    imagefilledrectangle($thumb, 0, 0, $size, $size, $bg);

    // Do not crop branded artwork: the logo, border and event footer are part of the image.
    $pad = max(10, (int)round($size * 0.035));
    photo_copy_contain_to_rect($src, $thumb, $srcW, $srcH, $pad, $pad, $size - ($pad * 2), $size - ($pad * 2), $bg);

    $saved = imagejpeg($thumb, $destPath, 88);
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