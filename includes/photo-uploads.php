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

function photo_text_width($text, $size, $font) {
    $text = (string)$text;
    if ($text === '' || !is_file($font) || !function_exists('imagettfbbox')) {
        return strlen($text) * max(7, (int)$size);
    }
    $box = imagettfbbox((int)$size, 0, $font, $text);
    return abs($box[2] - $box[0]);
}

function photo_fit_font_size($text, $font, $maxW, $startSize, $minSize = 12) {
    $size = (int)$startSize;
    while ($size > (int)$minSize && photo_text_width($text, $size, $font) > $maxW) {
        $size -= 2;
    }
    return max((int)$minSize, $size);
}

function photo_ellipsis_text($text, $font, $size, $maxW) {
    $text = trim((string)$text);
    if ($text === '' || photo_text_width($text, $size, $font) <= $maxW) {
        return $text;
    }
    $ellipsis = '…';
    while (strlen($text) > 1 && photo_text_width($text . $ellipsis, $size, $font) > $maxW) {
        $text = substr($text, 0, strlen($text) - 1);
    }
    return rtrim($text) . $ellipsis;
}

function photo_draw_fit_text($im, $text, $size, $x, $y, $color, $font, $maxW, $glowColor = null, $minSize = 12) {
    $text = trim((string)$text);
    if ($text === '') { return; }
    $size = photo_fit_font_size($text, $font, $maxW, $size, $minSize);
    $text = photo_ellipsis_text($text, $font, $size, $maxW);
    photo_draw_text_glow($im, $text, $size, (int)$x, (int)$y, $color, $font, $glowColor, $glowColor ? 2 : 0);
}

function photo_draw_pill($im, $text, $x, $y, $padX, $h, $font, $fontSize, $textColor, $borderColor, $bgColor, $maxW = 0) {
    $text = trim((string)$text);
    if ($text === '') { return [0, 0]; }
    if ($maxW > 0) {
        $text = photo_ellipsis_text($text, $font, $fontSize, $maxW - ($padX * 2));
    }
    $textW = photo_text_width($text, $fontSize, $font);
    $w = $maxW > 0 ? min($maxW, $textW + ($padX * 2)) : $textW + ($padX * 2);
    photo_fill_rounded_rect($im, $x, $y, $x + $w, $y + $h, (int)($h / 2), $bgColor);
    photo_stroke_rounded_rect($im, $x, $y, $x + $w, $y + $h, (int)($h / 2), $borderColor, 2);
    photo_draw_text_glow($im, $text, $fontSize, $x + $padX, $y + (int)round($h * 0.68), $textColor, $font, null, 0);
    return [$w, $h];
}

function photo_event_date_long($dateValue) {
    $dateValue = trim((string)$dateValue);
    if ($dateValue === '') { return ''; }
    try {
        return (new DateTime($dateValue))->format('l j F Y');
    } catch (Throwable $e) {
        return $dateValue;
    }
}

function photo_copy_circle($dest, $src, $destX, $destY, $diameter) {
    $diameter = (int)$diameter;
    $tmp = imagecreatetruecolor($diameter, $diameter);
    imagealphablending($tmp, false);
    imagesavealpha($tmp, true);
    $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
    imagefilledrectangle($tmp, 0, 0, $diameter, $diameter, $transparent);
    imagealphablending($tmp, true);
    imagecopyresampled($tmp, $src, 0, 0, 0, 0, $diameter, $diameter, imagesx($src), imagesy($src));

    $r = $diameter / 2;
    $r2 = $r * $r;
    for ($y = 0; $y < $diameter; $y++) {
        for ($x = 0; $x < $diameter; $x++) {
            $dx = $x - $r;
            $dy = $y - $r;
            if (($dx * $dx + $dy * $dy) > $r2) {
                imagesetpixel($tmp, $x, $y, $transparent);
            }
        }
    }
    imagecopy($dest, $tmp, (int)$destX, (int)$destY, 0, 0, $diameter, $diameter);
    imagedestroy($tmp);
}

function photo_draw_neon_line($im, $x1, $y1, $x2, $y2, $color, $glowColor, $thickness = 3) {
    imagesetthickness($im, $thickness + 8);
    imageline($im, (int)$x1, (int)$y1, (int)$x2, (int)$y2, $glowColor);
    imagesetthickness($im, $thickness);
    imageline($im, (int)$x1, (int)$y1, (int)$x2, (int)$y2, $color);
    imagesetthickness($im, 1);
}

function photo_render_framed_image($sourcePath, $destPath, $event, $orientation = 'portrait', $creditName = '') {
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

    // Final artwork sizes: landscape = event-card ratio, portrait = social 4:5 style.
    $canvasW = $landscape ? 1600 : 1080;
    $canvasH = $landscape ? 1200 : 1350;

    $im = imagecreatetruecolor($canvasW, $canvasH);
    imagealphablending($im, true);
    imagesavealpha($im, true);
    imageantialias($im, true);

    $fontBold = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    $fontRegular = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    if (!is_file($fontBold)) { $fontBold = __DIR__ . '/../assets/DejaVuSans-Bold.ttf'; }
    if (!is_file($fontRegular)) { $fontRegular = $fontBold; }

    $black = photo_allocate($im, [4, 0, 10]);
    $deep = photo_allocate($im, [10, 0, 22]);
    $pink = photo_allocate($im, [252, 64, 255]);
    $pinkLight = photo_allocate($im, [255, 155, 255]);
    $pinkGlow = photo_allocate($im, [252, 64, 255], 92);
    $panelBg = photo_allocate($im, [10, 0, 18], 12);
    $headerBg = photo_allocate($im, [8, 0, 16], 42);
    $footerBg = photo_allocate($im, [14, 0, 23], 18);
    $white = photo_allocate($im, [255, 255, 255]);
    $softWhite = photo_allocate($im, [245, 235, 255]);
    $whiteGlow = photo_allocate($im, [255, 178, 255], 96);
    $gold = photo_allocate($im, [255, 218, 72]);
    $goldDark = photo_allocate($im, [255, 160, 44]);
    $muted = photo_allocate($im, [232, 214, 245]);

    imagefilledrectangle($im, 0, 0, $canvasW, $canvasH, $black);
    photo_draw_gradient($im, 0, 0, $canvasW, $canvasH, [8, 0, 17], [32, 0, 54]);

    $panelX = $landscape ? 46 : 48;
    $panelY = $landscape ? 52 : 58;
    $panelW = $canvasW - ($panelX * 2);
    $panelH = $canvasH - ($panelY * 2);
    $panelR = $landscape ? 52 : 58;

    // Place the uploaded photo as the hero image under the graphic frame.
    $photoPad = $landscape ? 18 : 22;
    $photoX = $panelX + $photoPad;
    $photoY = $panelY + $photoPad;
    $photoW = $panelW - ($photoPad * 2);
    $photoH = $panelH - ($photoPad * 2);
    photo_copy_cover_to_rect($src, $im, $srcW, $srcH, $photoX, $photoY, $photoW, $photoH);

    // Global dark/purple treatment so uploaded photos look consistent with the site.
    imagefilledrectangle($im, $photoX, $photoY, $photoX + $photoW, $photoY + $photoH, photo_allocate($im, [18, 0, 42], $landscape ? 58 : 64));
    imagefilledrectangle($im, $photoX, $photoY, $photoX + $photoW, $photoY + (int)($photoH * .18), photo_allocate($im, [0, 0, 0], 72));
    imagefilledrectangle($im, $photoX, $photoY + (int)($photoH * .78), $photoX + $photoW, $photoY + $photoH, photo_allocate($im, [0, 0, 0], 78));

    // Faint disco rays / sparkle texture.
    $cx = (int)($canvasW / 2);
    $cy = (int)($canvasH * .48);
    $rayA = photo_allocate($im, [126, 38, 168], 114);
    for ($i = 0; $i < 22; $i += 2) {
        $a1 = deg2rad($i * 360 / 22 - 92);
        $a2 = deg2rad(($i + 1) * 360 / 22 - 92);
        $pts = [$cx, $cy,
            (int)round($cx + cos($a1) * $canvasW * 1.15), (int)round($cy + sin($a1) * $canvasW * 1.15),
            (int)round($cx + cos($a2) * $canvasW * 1.15), (int)round($cy + sin($a2) * $canvasW * 1.15)
        ];
        imagefilledpolygon($im, $pts, 3, $rayA);
    }
    foreach ([[90, 985, 18], [1400, 92, 14], [118, 885, 11], [1340, 930, 11], [1180, 150, 9], [420, 142, 8]] as $s) {
        if (!$landscape && $s[0] > 1000) { continue; }
        photo_draw_star($im, $s[0], $s[1], $s[2], $gold);
    }

    // Header and footer glass panels.
    $headerH = $landscape ? 132 : 182;
    imagefilledrectangle($im, $panelX + 14, $panelY + 14, $panelX + $panelW - 14, $panelY + $headerH, $headerBg);
    photo_draw_neon_line($im, $panelX + 160, $panelY + $headerH, $panelX + $panelW - 34, $panelY + $headerH, $pinkLight, $pinkGlow, 2);

    // Outer frame: draw after photo, then draw custom logo/QR pockets so they appear merged into the border.
    for ($i = 24; $i >= 6; $i -= 5) {
        photo_stroke_rounded_rect($im, $panelX - $i, $panelY - $i, $panelX + $panelW + $i, $panelY + $panelH + $i, $panelR + $i, $pinkGlow, 3);
    }
    photo_stroke_rounded_rect($im, $panelX, $panelY, $panelX + $panelW, $panelY + $panelH, $panelR, $pink, 5);
    photo_stroke_rounded_rect($im, $panelX + 8, $panelY + 8, $panelX + $panelW - 8, $panelY + $panelH - 8, $panelR - 9, $pinkLight, 1);

    // Logo merged into the border.
    $logoSize = $landscape ? 172 : 176;
    $logoX = $panelX + ($landscape ? 24 : 20);
    $logoY = $panelY - ($landscape ? 34 : 30);
    imagefilledellipse($im, $logoX + (int)($logoSize / 2), $logoY + (int)($logoSize / 2), $logoSize + 30, $logoSize + 30, photo_allocate($im, [8, 0, 16], 5));
    for ($i = 16; $i >= 4; $i -= 4) {
        imagesetthickness($im, 3);
        imagearc($im, $logoX + (int)($logoSize / 2), $logoY + (int)($logoSize / 2), $logoSize + $i, $logoSize + $i, 0, 360, $pinkGlow);
    }
    imagesetthickness($im, 4);
    imagearc($im, $logoX + (int)($logoSize / 2), $logoY + (int)($logoSize / 2), $logoSize, $logoSize, 0, 360, $pink);
    imagesetthickness($im, 1);

    $logoPath = dirname(__DIR__) . '/assets/dttd-logo-inner.png';
    if (!is_file($logoPath)) { $logoPath = dirname(__DIR__) . '/assets/dttd-neon-logo.png'; }
    if (is_file($logoPath)) {
        $logo = photo_load_image_resource($logoPath, $logoMime);
        if ($logo) {
            photo_copy_circle($im, $logo, $logoX + 10, $logoY + 10, $logoSize - 20);
            imagedestroy($logo);
        }
    }
    // Smooth-looking connectors from the circular logo pocket back into the top/left frame.
    photo_draw_neon_line($im, $logoX + $logoSize - 12, $panelY, $panelX + $panelW - $panelR, $panelY, $pink, $pinkGlow, 3);
    photo_draw_neon_line($im, $panelX, $logoY + $logoSize - 18, $panelX, $panelY + $panelH - $panelR, $pink, $pinkGlow, 3);

    $eventName = trim((string)($event['event_name'] ?? $event['name'] ?? 'Back To The 80s'));
    $venueLine = trim((string)($event['venue_name'] ?? $event['venue'] ?? ''));
    $dateLine = photo_event_date_long($event['event_date'] ?? '');
    if ($eventName === '') { $eventName = 'Dance Thru The Decades'; }
    if ($venueLine === '') { $venueLine = 'Dance Thru The Decades Events'; }

    // Top header text row.
    $titleX = $logoX + $logoSize + ($landscape ? 42 : 20);
    $titleY = $panelY + ($landscape ? 72 : 96);
    $headerRight = $panelX + $panelW - 44;
    $titleMaxW = $landscape ? 570 : ($headerRight - $titleX - 20);
    photo_draw_fit_text($im, $eventName, $landscape ? 57 : 48, $titleX + 2, $titleY + 2, $whiteGlow, $fontBold, $titleMaxW, null, 26);
    photo_draw_fit_text($im, $eventName, $landscape ? 56 : 47, $titleX, $titleY, $gold, $fontBold, $titleMaxW, photo_allocate($im, [120, 0, 100], 88), 26);

    if ($landscape) {
        $metaY = $panelY + 74;
        $metaX = $titleX + $titleMaxW + 38;
        photo_draw_text_glow($im, '|', 30, $metaX - 28, $metaY, $goldDark, $fontRegular, null, 0);
        photo_draw_text_glow($im, 'Venue: ' . photo_ellipsis_text($venueLine, $fontRegular, 22, 360), 22, $metaX, $metaY, $softWhite, $fontRegular, null, 0);
        $dateX = $metaX + 395;
        photo_draw_text_glow($im, '|', 30, $dateX - 24, $metaY, $goldDark, $fontRegular, null, 0);
        photo_draw_text_glow($im, 'Date: ' . photo_ellipsis_text($dateLine, $fontRegular, 21, 280), 21, $dateX, $metaY, $softWhite, $fontRegular, null, 0);
    } else {
        photo_draw_fit_text($im, $venueLine, 24, $titleX, $panelY + 132, $softWhite, $fontRegular, $headerRight - $titleX, null, 16);
        photo_draw_fit_text($im, $dateLine, 22, $titleX, $panelY + 165, $gold, $fontRegular, $headerRight - $titleX, null, 15);
    }

    // EVENT PHOTO pill.
    photo_draw_pill($im, 'EVENT PHOTO', $titleX, $panelY + ($landscape ? 96 : 188), 22, $landscape ? 42 : 40, $fontBold, $landscape ? 18 : 16, $softWhite, $pinkLight, photo_allocate($im, [20, 0, 30], 24), 230);

    // Optional photographer/uploader credit.
    $creditName = trim((string)$creditName);
    if ($creditName !== '') {
        $creditText = 'Photo by ' . $creditName;
        $creditW = min($landscape ? 310 : 290, photo_text_width($creditText, $landscape ? 19 : 17, $fontRegular) + 42);
        $creditX = $headerRight - $creditW;
        $creditY = $panelY + ($landscape ? 92 : 198);
        photo_draw_pill($im, $creditText, $creditX, $creditY, 18, $landscape ? 38 : 36, $fontRegular, $landscape ? 19 : 17, $softWhite, $pinkLight, photo_allocate($im, [20, 0, 30], 32), $creditW);
    }

    // Bottom brand strip.
    $footerW = $landscape ? 820 : 720;
    $footerH = $landscape ? 118 : 132;
    $footerX = (int)round(($canvasW - $footerW) / 2) - ($landscape ? 45 : 0);
    $footerY = $panelY + $panelH - $footerH - 34;
    for ($i = 12; $i >= 4; $i -= 4) {
        photo_stroke_rounded_rect($im, $footerX - $i, $footerY - $i, $footerX + $footerW + $i, $footerY + $footerH + $i, 34 + $i, $pinkGlow, 3);
    }
    photo_fill_rounded_rect($im, $footerX, $footerY, $footerX + $footerW, $footerY + $footerH, 34, $footerBg);
    photo_stroke_rounded_rect($im, $footerX, $footerY, $footerX + $footerW, $footerY + $footerH, 34, $pinkLight, 2);
    photo_draw_centered_text($im, 'Dance Thru The Decades', $landscape ? 47 : 44, $footerY + ($landscape ? 62 : 70), $gold, $fontBold, $footerW - 80, $footerX + (int)($footerW / 2));
    photo_draw_centered_text($im, '60s   •   70s   •   80s   •   90s   •   00s', $landscape ? 23 : 21, $footerY + ($landscape ? 98 : 108), $gold, $fontRegular, $footerW - 120, $footerX + (int)($footerW / 2));

    // QR pocket integrated into the bottom/right frame. Keep it smaller and high contrast.
    $qrPocketW = $landscape ? 170 : 150;
    $qrPocketH = $landscape ? 190 : 170;
    $qrPocketX = $panelX + $panelW - $qrPocketW - ($landscape ? 28 : 22);
    $qrPocketY = $panelY + $panelH - $qrPocketH - ($landscape ? 28 : 24);
    photo_fill_rounded_rect($im, $qrPocketX, $qrPocketY, $qrPocketX + $qrPocketW, $qrPocketY + $qrPocketH, 24, photo_allocate($im, [10, 0, 18], 12));
    for ($i = 10; $i >= 4; $i -= 3) {
        photo_stroke_rounded_rect($im, $qrPocketX - $i, $qrPocketY - $i, $qrPocketX + $qrPocketW + $i, $qrPocketY + $qrPocketH + $i, 24 + $i, $pinkGlow, 2);
    }
    photo_stroke_rounded_rect($im, $qrPocketX, $qrPocketY, $qrPocketX + $qrPocketW, $qrPocketY + $qrPocketH, 24, $pinkLight, 3);
    // Connect pocket to the border to make it look like an intentional border feature.
    photo_draw_neon_line($im, $qrPocketX + $qrPocketW, $qrPocketY + 34, $panelX + $panelW, $qrPocketY + 34, $pink, $pinkGlow, 3);
    photo_draw_neon_line($im, $qrPocketX + 34, $qrPocketY + $qrPocketH, $qrPocketX + 34, $panelY + $panelH, $pink, $pinkGlow, 3);

    $qrPath = dirname(__DIR__) . '/assets/dttd-website-qr.png';
    if (is_file($qrPath)) {
        $qr = photo_load_image_resource($qrPath, $qrMime);
        if ($qr) {
            $qrSize = $landscape ? 116 : 102;
            $qrX = $qrPocketX + (int)(($qrPocketW - $qrSize) / 2);
            $qrY = $qrPocketY + ($landscape ? 43 : 40);
            $whiteBg = photo_allocate($im, [255, 255, 255]);
            imagefilledrectangle($im, $qrX - 6, $qrY - 6, $qrX + $qrSize + 6, $qrY + $qrSize + 6, $whiteBg);
            imagecopyresampled($im, $qr, $qrX, $qrY, 0, 0, $qrSize, $qrSize, imagesx($qr), imagesy($qr));
            imagedestroy($qr);
        }
    }
    photo_draw_centered_text($im, 'Scan', $landscape ? 16 : 14, $qrPocketY + 29, $softWhite, $fontBold, $qrPocketW - 20, $qrPocketX + (int)($qrPocketW / 2));
    photo_draw_centered_text($im, 'Website', $landscape ? 16 : 14, $qrPocketY + $qrPocketH - 22, $softWhite, $fontRegular, $qrPocketW - 20, $qrPocketX + (int)($qrPocketW / 2));

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

    if (!photo_render_framed_image($originalAbs, $framedAbs, $event, $orientation, $guestName ?? '')) {
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