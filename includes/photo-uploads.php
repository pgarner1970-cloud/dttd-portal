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
    try {
        $stmt = db()->query("
            SELECT *
            FROM events
            WHERE is_active = 1
              AND event_date IS NOT NULL
              AND event_date <= CURDATE()
              AND (portal_available_from IS NULL OR portal_available_from <= NOW())
              AND (portal_available_until IS NULL OR portal_available_until >= NOW())
            ORDER BY event_date DESC, start_time DESC, id DESC
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
        if ($event && photo_can_select_event($event) && !empty($event['event_date']) && strtotime((string)$event['event_date']) <= time()) {
            return $event;
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
              AND event_date <= CURDATE()
              AND event_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
            ORDER BY event_date ASC, start_time ASC, id ASC
        ");
        foreach ($stmt->fetchAll() as $event) {
            $id = (int)($event['id'] ?? 0);
            if ($id && !isset($seen[$id]) && photo_can_select_event($event)) {
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

    // Two fixed social-share templates. Portrait photos get a 4:5 card; landscape photos get a wider card.
    $canvasW = $landscape ? 1600 : 1080;
    $canvasH = $landscape ? 1200 : 1350;

    $im = imagecreatetruecolor($canvasW, $canvasH);
    imagealphablending($im, true);
    imagesavealpha($im, true);
    imageantialias($im, true);

    $black = photo_allocate($im, [5, 0, 12]);
    imagefilledrectangle($im, 0, 0, $canvasW, $canvasH, $black);
    photo_draw_gradient($im, 0, 0, $canvasW, $canvasH, [21, 0, 35], [57, 2, 78]);

    // Disco radial rays in the background.
    $ray = photo_allocate($im, [128, 28, 174], 108);
    $cx = (int)($canvasW / 2);
    $cy = (int)($canvasH * 0.52);
    for ($i = 0; $i < 32; $i += 2) {
        $a1 = deg2rad($i * 360 / 32 - 92);
        $a2 = deg2rad(($i + 1) * 360 / 32 - 92);
        $pts = [$cx, $cy,
            (int)round($cx + cos($a1) * $canvasW), (int)round($cy + sin($a1) * $canvasW),
            (int)round($cx + cos($a2) * $canvasW), (int)round($cy + sin($a2) * $canvasW)
        ];
        imagefilledpolygon($im, $pts, 3, $ray);
    }

    $pink = photo_allocate($im, [247, 64, 255]);
    $pinkSoft = photo_allocate($im, [247, 64, 255], 70);
    $pinkGlow1 = photo_allocate($im, [247, 64, 255], 98);
    $pinkGlow2 = photo_allocate($im, [247, 64, 255], 113);
    $panelBg = photo_allocate($im, [18, 0, 28], 12);
    $photoShade = photo_allocate($im, [0, 0, 0], 76);
    $white = photo_allocate($im, [255, 255, 255]);
    $gold = photo_allocate($im, [255, 220, 76]);
    $footerPink = photo_allocate($im, [251, 90, 255]);

    $margin = $landscape ? 80 : 70;
    $panelX = $margin;
    $panelY = $landscape ? 55 : 60;
    $panelW = $canvasW - ($margin * 2);
    $panelH = $canvasH - ($panelY * 2);
    $radius = $landscape ? 44 : 58;

    // Neon glow and main card.
    for ($i = 18; $i >= 4; $i -= 4) {
        photo_stroke_rounded_rect($im, $panelX - $i, $panelY - $i, $panelX + $panelW + $i, $panelY + $panelH + $i, $radius + $i, $pinkGlow2, 3);
    }
    photo_fill_rounded_rect($im, $panelX, $panelY, $panelX + $panelW, $panelY + $panelH, $radius, $panelBg);
    photo_stroke_rounded_rect($im, $panelX, $panelY, $panelX + $panelW, $panelY + $panelH, $radius, $pink, 5);
    photo_stroke_rounded_rect($im, $panelX + 7, $panelY + 7, $panelX + $panelW - 7, $panelY + $panelH - 7, $radius - 8, $pinkSoft, 1);

    $headerH = $landscape ? 130 : 145;
    $footerH = $landscape ? 270 : 300;
    $innerPad = $landscape ? 55 : 45;
    $photoX = $panelX + $innerPad;
    $photoY = $panelY + $headerH;
    $photoW = $panelW - ($innerPad * 2);
    $photoH = $panelH - $headerH - $footerH;

    // Polished angled divider lines like the mock-up.
    imagesetthickness($im, $landscape ? 5 : 4);
    imageline($im, $photoX, $photoY - 6, $photoX + $photoW, $photoY + ($landscape ? 20 : 28), $pink);
    imageline($im, $photoX, $photoY + $photoH - ($landscape ? 18 : 30), $photoX + $photoW, $photoY + $photoH + 8, $pink);
    imagesetthickness($im, 1);

    // Photo panel with dark base, then cover-cropped image.
    imagefilledrectangle($im, $photoX, $photoY, $photoX + $photoW, $photoY + $photoH, photo_allocate($im, [7, 7, 14]));
    photo_copy_cover_to_rect($src, $im, $srcW, $srcH, $photoX, $photoY, $photoW, $photoH);

    // Subtle top/bottom shading over the photo for depth and readability.
    imagefilledrectangle($im, $photoX, $photoY, $photoX + $photoW, $photoY + (int)($photoH * 0.10), $photoShade);
    imagefilledrectangle($im, $photoX, $photoY + (int)($photoH * 0.86), $photoX + $photoW, $photoY + $photoH, photo_allocate($im, [0, 0, 0], 92));

    // Header logo and label.
    $logoPath = dirname(__DIR__) . '/assets/dttd-logo-inner.png';
    if (!is_file($logoPath)) {
        $logoPath = dirname(__DIR__) . '/assets/dttd-neon-logo.png';
    }
    if (is_file($logoPath)) {
        $logo = photo_load_image_resource($logoPath, $logoMime);
        if ($logo) {
            $logoW = imagesx($logo);
            $logoH = imagesy($logo);
            $targetH = $landscape ? 105 : 118;
            $targetW = (int)round($logoW * ($targetH / max(1, $logoH)));
            imagecopyresampled($im, $logo, $panelX + ($landscape ? 45 : 32), $panelY + ($landscape ? 18 : 18), 0, 0, $targetW, $targetH, $logoW, $logoH);
            imagedestroy($logo);
        }
    }

    $fontBold = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    $fontRegular = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    $fontMono = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf';

    $label = 'EVENT PHOTO';
    if (is_file($fontBold)) {
        $box = imagettfbbox($landscape ? 25 : 23, 0, $fontBold, str_replace(' ', '', $label));
        photo_draw_letterspaced_text($im, $label, $landscape ? 25 : 23, $panelX + $panelW - ($landscape ? 370 : 330), $panelY + ($landscape ? 72 : 82), $gold, $fontBold, 8);
    }

    $eventName = trim((string)($event['event_name'] ?? 'Dance Thru The Decades'));
    $venueLine = trim((string)($event['venue_name'] ?? ''));
    $dateLine = '';
    if (!empty($event['event_date'])) {
        try {
            $dateLine = (new DateTime($event['event_date']))->format('D j M Y');
        } catch (Throwable $e) {
            $dateLine = (string)$event['event_date'];
        }
    }

    // Footer panel and typography.
    $footerTop = $photoY + $photoH + ($landscape ? 34 : 40);
    imagefilledrectangle($im, $panelX + 35, $footerTop - 10, $panelX + $panelW - 35, $panelY + $panelH - 34, photo_allocate($im, [25, 0, 36], 32));

    if (is_file($fontBold) && function_exists('imagettftext')) {
        // Soft glow behind the title.
        photo_draw_centered_text($im, $eventName, $landscape ? 47 : 50, $footerTop + ($landscape ? 78 : 92), photo_allocate($im, [255, 95, 255], 78), $fontBold, $panelW - 240, $cx + 3);
        photo_draw_centered_text($im, $eventName, $landscape ? 46 : 49, $footerTop + ($landscape ? 76 : 90), $white, $fontBold, $panelW - 240, $cx);
        photo_draw_centered_text($im, $venueLine ?: 'Dance Thru The Decades Events', $landscape ? 30 : 30, $footerTop + ($landscape ? 128 : 145), $gold, $fontRegular, $panelW - 260, $cx);
        photo_draw_centered_text($im, $dateLine, $landscape ? 28 : 29, $footerTop + ($landscape ? 178 : 198), $white, $fontBold, $panelW - 260, $cx);
        photo_draw_letterspaced_text($im, 'DANCE THRU THE DECADES EVENTS', $landscape ? 15 : 15, $cx - ($landscape ? 205 : 202), $panelY + $panelH - 38, $footerPink, $fontBold, 5);
    }

    // Decorative stars.
    $starY = $footerTop + ($landscape ? 156 : 173);
    foreach ([-390, -310, 310, 390] as $offset) {
        if (!$landscape && abs($offset) > 330) { continue; }
        photo_draw_star($im, $cx + $offset, $starY + (($offset % 2) ? 0 : 18), $landscape ? 17 : 16, $footerPink);
    }
    photo_draw_star($im, $cx - ($landscape ? 120 : 135), $starY + ($landscape ? 33 : 34), $landscape ? 16 : 16, $footerPink);
    photo_draw_star($im, $cx + ($landscape ? 120 : 135), $starY + ($landscape ? 33 : 34), $landscape ? 16 : 16, $footerPink);

    // Mask corners outside panel back to background-ish shade to soften hard square edges left by filled shapes.
    photo_stroke_rounded_rect($im, $panelX + 1, $panelY + 1, $panelX + $panelW - 1, $panelY + $panelH - 1, $radius - 1, photo_allocate($im, [255, 156, 255], 25), 1);

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