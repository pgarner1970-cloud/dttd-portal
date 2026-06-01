<?php
require_once __DIR__ . '/db.php';

function photo_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}


function photo_overlay_log($message) {
    error_log('[DTTD overlay] ' . (string)$message);
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

function photo_public_url($path, $cacheBust = false) {
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }

    $url = '/' . ltrim($path, '/');
    if ($cacheBust) {
        $localPath = dirname(__DIR__) . '/' . ltrim($path, '/');
        if (is_file($localPath)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'v=' . filemtime($localPath);
        }
    }
    return $url;
}

function photo_public_cache_busted_url($path) {
    return photo_public_url($path, true);
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
        photo_overlay_log('Render failed: ' . $e->getMessage());
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

function photo_event_is_wedding($event) {
    $type = strtolower(trim((string)($event['event_type'] ?? '')));
    if ($type === 'wedding' || strpos($type, 'wedding') !== false) {
        return true;
    }

    $name = strtolower(trim((string)($event['event_name'] ?? $event['name'] ?? '')));
    return strpos($name, 'wedding') !== false;
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


function photo_imagick_font_path($bold = false) {
    $candidates = $bold
        ? [
            __DIR__ . '/../assets/fonts/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
            '/usr/local/share/fonts/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        ]
        : [
            __DIR__ . '/../assets/fonts/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            '/usr/local/share/fonts/DejaVuSans.ttf',
            '/usr/share/fonts/TTF/DejaVuSans.ttf',
        ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    // 20i/shared hosts may expose ImageMagick fonts by family name rather than
    // Linux filesystem path. Returning a family name avoids hard failure from
    // a missing /usr/share/fonts path.
    return $bold ? 'DejaVu-Sans-Bold' : 'DejaVu-Sans';
}


function photo_imagick_set_font_safe(ImagickDraw $draw, $fontPath) {
    $fontPath = trim((string)$fontPath);
    if ($fontPath === '') {
        return;
    }
    try {
        $draw->setFont($fontPath);
    } catch (Throwable $e) {
        // Fall back to ImageMagick's default font if the requested one is not available.
        photo_overlay_log('Font unavailable, using ImageMagick default: ' . $fontPath);
    }
}

function photo_imagick_text_width(Imagick $canvas, ImagickDraw $draw, $text) {
    $metrics = $canvas->queryFontMetrics($draw, (string)$text, false);
    return (float)($metrics['textWidth'] ?? 0);
}

function photo_imagick_fit_font_size(Imagick $canvas, $text, $fontPath, $startSize, $maxWidth, $minSize = 12) {
    $size = (int)$startSize;
    while ($size > (int)$minSize) {
        $draw = new ImagickDraw();
        photo_imagick_set_font_safe($draw, $fontPath);
        $draw->setFontSize($size);
        $width = photo_imagick_text_width($canvas, $draw, $text);
        if ($width <= $maxWidth) {
            break;
        }
        $size -= 2;
    }
    return max((int)$minSize, $size);
}

function photo_imagick_truncate_text(Imagick $canvas, ImagickDraw $draw, $text, $maxWidth) {
    $text = trim((string)$text);
    if ($text === '') {
        return '';
    }
    if (photo_imagick_text_width($canvas, $draw, $text) <= $maxWidth) {
        return $text;
    }
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) {
        return $text;
    }
    $ellipsis = '…';
    while (count($chars) > 1) {
        array_pop($chars);
        $trial = rtrim(implode('', $chars)) . $ellipsis;
        if (photo_imagick_text_width($canvas, $draw, $trial) <= $maxWidth) {
            return $trial;
        }
    }
    return $ellipsis;
}


function photo_imagick_apply_draw_color(ImagickDraw $draw, $target, $spec) {
    $spec = trim((string)$spec);
    $opacity = 1.0;
    if (preg_match('/^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*([0-9.]+)\s*\)$/i', $spec, $m)) {
        $spec = sprintf('rgb(%d,%d,%d)', (int)$m[1], (int)$m[2], (int)$m[3]);
        $opacity = max(0.0, min(1.0, (float)$m[4]));
    }
    $pixel = new ImagickPixel($spec !== '' ? $spec : 'white');
    if ($target === 'stroke') {
        $draw->setStrokeColor($pixel);
        if (method_exists($draw, 'setStrokeOpacity')) {
            $draw->setStrokeOpacity($opacity);
        }
    } else {
        $draw->setFillColor($pixel);
        if (method_exists($draw, 'setFillOpacity')) {
            $draw->setFillOpacity($opacity);
        }
    }
}

function photo_imagick_draw_text(Imagick $canvas, $text, $x, $y, $fontPath, $fontSize, $fill, $stroke = null, $strokeWidth = 0, $maxWidth = 0, $align = Imagick::ALIGN_LEFT) {
    $text = trim((string)$text);
    if ($text === '') {
        return 0;
    }
    $draw = new ImagickDraw();
    photo_imagick_set_font_safe($draw, $fontPath);
    $draw->setFontSize($fontSize);
    $draw->setTextAntialias(true);
    photo_imagick_apply_draw_color($draw, 'fill', $fill);
    $draw->setTextAlignment($align);
    if ($stroke !== null && $strokeWidth > 0) {
        photo_imagick_apply_draw_color($draw, 'stroke', $stroke);
        $draw->setStrokeWidth($strokeWidth);
    } else {
        if (method_exists($draw, 'setStrokeOpacity')) {
            $draw->setStrokeOpacity(0);
        }
    }
    if ($maxWidth > 0) {
        $text = photo_imagick_truncate_text($canvas, $draw, $text, $maxWidth);
    }
    $canvas->annotateImage($draw, (float)$x, (float)$y, 0, $text);
    return photo_imagick_text_width($canvas, $draw, $text);
}


function photo_imagick_measure_pill_width(Imagick $canvas, $text, $fontPath, $fontSize, $paddingX = 24, $maxWidth = 0) {
    $probe = new ImagickDraw();
    photo_imagick_set_font_safe($probe, $fontPath);
    $probe->setFontSize($fontSize);
    $label = trim((string)$text);
    if ($label === '') {
        return 0;
    }
    if ($maxWidth > 0) {
        $label = photo_imagick_truncate_text($canvas, $probe, $label, max(10, $maxWidth - ($paddingX * 2)));
    }
    $textWidth = photo_imagick_text_width($canvas, $probe, $label);
    $width = (int)ceil($textWidth + ($paddingX * 2));
    if ($maxWidth > 0) {
        $width = min($width, (int)$maxWidth);
    }
    return $width;
}

function photo_imagick_draw_pill(Imagick $canvas, $text, $x, $y, $fontPath, $fontSize, $textColor, $strokeColor, $fillColor, $paddingX = 24, $height = 42, $maxWidth = 0) {
    $probe = new ImagickDraw();
    photo_imagick_set_font_safe($probe, $fontPath);
    $probe->setFontSize($fontSize);
    $label = trim((string)$text);
    if ($label === '') {
        return 0;
    }
    if ($maxWidth > 0) {
        $label = photo_imagick_truncate_text($canvas, $probe, $label, max(10, $maxWidth - ($paddingX * 2)));
    }
    $textWidth = photo_imagick_text_width($canvas, $probe, $label);
    $width = (int)ceil($textWidth + ($paddingX * 2));
    if ($maxWidth > 0) {
        $width = min($width, (int)$maxWidth);
    }
    $radius = (int)floor($height / 2);
    $shape = new ImagickDraw();
    photo_imagick_apply_draw_color($shape, 'stroke', $strokeColor);
    $shape->setStrokeWidth(2);
    photo_imagick_apply_draw_color($shape, 'fill', $fillColor);
    $shape->roundRectangle($x, $y, $x + $width, $y + $height, $radius, $radius);
    $canvas->drawImage($shape);
    photo_imagick_draw_text($canvas, $label, $x + $paddingX, $y + ($height * 0.69), $fontPath, $fontSize, $textColor, null, 0, 0, Imagick::ALIGN_LEFT);
    return $width;
}

function photo_render_framed_image($sourcePath, $destPath, $event, $orientation = 'portrait', $creditName = '') {
    if (!extension_loaded('imagick')) {
        return false;
    }

    $landscape = ($orientation === 'landscape');
    $canvasW = $landscape ? 1600 : 1080;
    $canvasH = $landscape ? 1200 : 1350;
    $isWedding = photo_event_is_wedding($event);
    $overlayFile = $landscape ? 'dttd-overlay-landscape.png' : 'dttd-overlay-portrait.png';
    $logoFile = 'dttd-logo-inner.png';

    if ($isWedding) {
        $weddingOverlayFile = $landscape ? 'dttd-overlay-wedding-landscape.png' : 'dttd-overlay-wedding-portrait.png';
        if (is_file(dirname(__DIR__) . '/assets/' . $weddingOverlayFile)) {
            $overlayFile = $weddingOverlayFile;
        }
        if (is_file(dirname(__DIR__) . '/assets/dttd-logo-wedding.png')) {
            $logoFile = 'dttd-logo-wedding.png';
        }
    }

    $overlayPath = dirname(__DIR__) . '/assets/' . $overlayFile;
    $logoPath = dirname(__DIR__) . '/assets/' . $logoFile;
    if (!is_file($logoPath)) {
        $logoPath = dirname(__DIR__) . '/assets/dttd-neon-logo.png';
    }
    if (!is_file($overlayPath) || !is_file($logoPath)) {
        photo_overlay_log('Missing overlay asset(s): overlay=' . (is_file($overlayPath) ? 'yes' : 'no') . ', logo=' . (is_file($logoPath) ? 'yes' : 'no'));
        return false;
    }

    try {
        $photo = new Imagick($sourcePath);
        if (method_exists($photo, 'autoOrient')) {
            $photo->autoOrient();
        }
        $photo->setImageColorspace(Imagick::COLORSPACE_SRGB);
        $photo->cropThumbnailImage($canvasW, $canvasH);
        $photo->setImagePage(0, 0, 0, 0);

        $canvas = new Imagick();
        $canvas->newImage($canvasW, $canvasH, new ImagickPixel('black'));
        $canvas->setImageColorspace(Imagick::COLORSPACE_SRGB);
        $canvas->compositeImage($photo, Imagick::COMPOSITE_OVER, 0, 0);

        $overlay = new Imagick($overlayPath);
        $canvas->compositeImage($overlay, Imagick::COMPOSITE_OVER, 0, 0);

        $logo = new Imagick($logoPath);
        $logo->setImageBackgroundColor(new ImagickPixel('transparent'));
        try {
            $logo->trimImage(0);
            $logo->setImagePage(0, 0, 0, 0);
            $logoW = $logo->getImageWidth();
            $logoH = $logo->getImageHeight();
            $logoSquare = max($logoW, $logoH);
            if ($logoSquare > 0) {
                $logo->extentImage($logoSquare, $logoSquare, (int)round(($logoSquare - $logoW) / -2), (int)round(($logoSquare - $logoH) / -2));
                $logo->setImagePage(0, 0, 0, 0);
            }
        } catch (Throwable $e) {
            // Ignore trim failures and use the source as-is.
        }
        $logoSize = $isWedding ? ($landscape ? 150 : 168) : ($landscape ? 116 : 116);
        $logo->resizeImage($logoSize, $logoSize, Imagick::FILTER_LANCZOS, 1, true);
        $logoX = $isWedding ? ($landscape ? 42 : 44) : ($landscape ? 30 : 30);
        $logoY = $isWedding ? ($landscape ? 992 : 1038) : ($landscape ? 30 : 30);
        $canvas->compositeImage($logo, Imagick::COMPOSITE_OVER, $logoX, $logoY);

        $fontBold = photo_imagick_font_path(true);
        $fontRegular = photo_imagick_font_path(false);

        $eventName = trim((string)($event['event_name'] ?? $event['name'] ?? 'Dance Thru The Decades'));
        if ($eventName === '') { $eventName = 'Dance Thru The Decades'; }
        $venueLine = trim((string)($event['venue_name'] ?? $event['venue'] ?? ''));
        $dateLine = photo_event_date_long($event['event_date'] ?? '');
        $siteUrlText = 'dancethruthedecades.co.uk';
        $headlineText = $isWedding ? 'CELEBRATING THE WEDDING OF' : '';
        $thankYouText = $isWedding ? 'Thank you for celebrating with us!' : 'Dance Thru The Decades';
        $taglineText = $isWedding ? 'Share your photos & request your favourite songs' : '60s  •  70s  •  80s  •  90s  •  00s';
        $weddingInk = '#4d4447';
        $weddingRose = '#b27670';
        $weddingGold = '#b88f4f';

        if ($landscape) {
            if ($isWedding) {
                photo_imagick_draw_text($canvas, $headlineText, 800, 52, $fontBold, 24, $weddingInk, null, 0, 960, Imagick::ALIGN_CENTER);
                $titleSize = photo_imagick_fit_font_size($canvas, $eventName, $fontRegular, 58, 980, 34);
                photo_imagick_draw_text($canvas, $eventName, 800, 118, $fontRegular, $titleSize, $weddingRose, null, 0, 980, Imagick::ALIGN_CENTER);
                photo_imagick_draw_text($canvas, ($dateLine !== '' ? $dateLine : 'TBA'), 800, 164, $fontBold, 24, $weddingInk, null, 0, 520, Imagick::ALIGN_CENTER);
                if ($venueLine !== '') {
                    photo_imagick_draw_text($canvas, $venueLine, 800, 194, $fontBold, 18, $weddingInk, null, 0, 660, Imagick::ALIGN_CENTER);
                }
                photo_imagick_draw_text($canvas, $thankYouText, 800, 1040, $fontRegular, 32, $weddingRose, null, 0, 820, Imagick::ALIGN_CENTER);
                photo_imagick_draw_text($canvas, strtoupper($taglineText), 800, 1084, $fontBold, 16, $weddingInk, null, 0, 820, Imagick::ALIGN_CENTER);
            }
            $rightInset = 86;
            $pillGap = 14;
            $eventMaxW = 220;
            $eventW = photo_imagick_measure_pill_width($canvas, 'EVENT PHOTO', $fontBold, 15, 20, $eventMaxW);
            $eventX = $canvasW - $rightInset - $eventW;
            $creditName = trim((string)$creditName);
            $creditX = null;
            $creditMaxW = 390;
            if ($creditName !== '') {
                $creditText = 'Photo by ' . $creditName;
                $creditW = photo_imagick_measure_pill_width($canvas, $creditText, $fontBold, 14, 18, $creditMaxW);
                $creditX = $eventX - $pillGap - $creditW;
            }

            $siteRightX = $canvasW - $rightInset;
            $siteMaxW = 420;
            if (!$isWedding) { photo_imagick_draw_text($canvas, $siteUrlText, $siteRightX, 60, $fontBold, 22, '#f7aaff', 'rgba(110,0,170,0.92)', 1, $siteMaxW, Imagick::ALIGN_RIGHT); }

            $titleX = 178;
            $titleY = 92;
            $titleMaxW = 500;
            $titleSize = photo_imagick_fit_font_size($canvas, $eventName, $fontBold, 36, $titleMaxW, 24);
            if (!$isWedding) { photo_imagick_draw_text($canvas, $eventName, $titleX, $titleY, $fontBold, $titleSize, '#ffcf40', 'rgba(80,0,40,0.78)', 1, $titleMaxW); }

            $venueText = ($venueLine !== '' ? $venueLine : 'Dance Thru The Decades');
            $dateText = ($dateLine !== '' ? $dateLine : 'TBA');
            if (!$isWedding) { photo_imagick_draw_text($canvas, $venueText, 178, 124, $fontBold, 20, '#ffd977', 'rgba(80,0,40,0.66)', 1, 500); }
            if (!$isWedding) { photo_imagick_draw_text($canvas, $dateText, 178, 150, $fontBold, 20, '#ffd977', 'rgba(80,0,40,0.66)', 1, 500); }

            $pillTopY = 76;
            if (!$isWedding) {
                if ($creditX !== null) {
                    photo_imagick_draw_pill($canvas, 'Photo by ' . $creditName, $creditX, $pillTopY, $fontBold, 14, 'white', '#f7aaff', 'rgba(12,0,20,0.78)', 18, 30, $creditMaxW);
                }
                photo_imagick_draw_pill($canvas, 'EVENT PHOTO', $eventX, $pillTopY, $fontBold, 15, 'white', '#f7aaff', 'rgba(12,0,20,0.78)', 20, 30, $eventMaxW);
            }

            if (!$isWedding) { photo_imagick_draw_text($canvas, 'Dance Thru The Decades', 750, 1115, $fontBold, 32, '#ffcf40', 'rgba(80,0,40,0.78)', 1, 0, Imagick::ALIGN_CENTER); }
            if (!$isWedding) { photo_imagick_draw_text($canvas, '60s  •  70s  •  80s  •  90s  •  00s', 750, 1148, $fontBold, 17, '#ffcf40', null, 0, 0, Imagick::ALIGN_CENTER); }
        } else {
            if ($isWedding) {
                photo_imagick_draw_text($canvas, $headlineText, 540, 62, $fontBold, 22, $weddingInk, null, 0, 760, Imagick::ALIGN_CENTER);
                $titleSize = photo_imagick_fit_font_size($canvas, $eventName, $fontRegular, 56, 840, 32);
                photo_imagick_draw_text($canvas, $eventName, 540, 135, $fontRegular, $titleSize, $weddingRose, null, 0, 840, Imagick::ALIGN_CENTER);
                photo_imagick_draw_text($canvas, ($dateLine !== '' ? $dateLine : 'TBA'), 540, 184, $fontBold, 24, $weddingInk, null, 0, 500, Imagick::ALIGN_CENTER);
                if ($venueLine !== '') {
                    photo_imagick_draw_text($canvas, $venueLine, 540, 216, $fontBold, 18, $weddingInk, null, 0, 620, Imagick::ALIGN_CENTER);
                }
                photo_imagick_draw_text($canvas, $thankYouText, 620, 1165, $fontRegular, 31, $weddingRose, null, 0, 720, Imagick::ALIGN_CENTER);
                photo_imagick_draw_text($canvas, strtoupper($taglineText), 620, 1210, $fontBold, 14, $weddingInk, null, 0, 720, Imagick::ALIGN_CENTER);
            }
            $rightInset = 72;
            $pillGap = 12;
            $eventMaxW = 210;
            $eventW = photo_imagick_measure_pill_width($canvas, 'EVENT PHOTO', $fontBold, 15, 20, $eventMaxW);
            $eventX = $canvasW - $rightInset - $eventW;
            $creditName = trim((string)$creditName);
            $creditX = null;
            $creditMaxW = 360;
            if ($creditName !== '') {
                $creditText = 'Photo by ' . $creditName;
                $creditW = photo_imagick_measure_pill_width($canvas, $creditText, $fontBold, 14, 18, $creditMaxW);
                $creditX = $eventX - $pillGap - $creditW;
            }

            $siteRightX = $canvasW - $rightInset;
            $siteMaxW = 420;
            if (!$isWedding) { photo_imagick_draw_text($canvas, $siteUrlText, $siteRightX, 56, $fontBold, 22, '#f7aaff', 'rgba(110,0,170,0.92)', 1, $siteMaxW, Imagick::ALIGN_RIGHT); }

            $titleX = 178;
            $titleY = 86;
            $titleMaxW = 560;
            $titleSize = photo_imagick_fit_font_size($canvas, $eventName, $fontBold, 32, $titleMaxW, 22);
            if (!$isWedding) { photo_imagick_draw_text($canvas, $eventName, $titleX, $titleY, $fontBold, $titleSize, '#ffcf40', 'rgba(80,0,40,0.78)', 1, $titleMaxW); }
            if (!$isWedding) { photo_imagick_draw_text($canvas, ($venueLine !== '' ? $venueLine : 'Dance Thru The Decades'), 178, 118, $fontBold, 20, '#ffd977', 'rgba(80,0,40,0.66)', 1, 560); }
            if (!$isWedding) { photo_imagick_draw_text($canvas, ($dateLine !== '' ? $dateLine : 'TBA'), 178, 144, $fontBold, 20, '#ffd977', 'rgba(80,0,40,0.66)', 1, 560); }

            $pillTopY = 88;
            if (!$isWedding) {
                if ($creditX !== null) {
                    photo_imagick_draw_pill($canvas, 'Photo by ' . $creditName, $creditX, $pillTopY, $fontBold, 14, 'white', '#f7aaff', 'rgba(12,0,20,0.78)', 18, 30, $creditMaxW);
                }
                photo_imagick_draw_pill($canvas, 'EVENT PHOTO', $eventX, $pillTopY, $fontBold, 15, 'white', '#f7aaff', 'rgba(12,0,20,0.78)', 20, 30, $eventMaxW);
            }

            if (!$isWedding) { photo_imagick_draw_text($canvas, 'Dance Thru The Decades', 540, 1274, $fontBold, 30, '#ffcf40', 'rgba(80,0,40,0.78)', 1, 0, Imagick::ALIGN_CENTER); }
            if (!$isWedding) { photo_imagick_draw_text($canvas, '60s  •  70s  •  80s  •  90s  •  00s', 540, 1300, $fontBold, 15, '#ffcf40', null, 0, 0, Imagick::ALIGN_CENTER); }
        }

        $canvas->setImageFormat('jpeg');
        $canvas->setImageCompression(Imagick::COMPRESSION_JPEG);
        $canvas->setImageCompressionQuality(92);
        $canvas->stripImage();
        $written = $canvas->writeImage($destPath);

        foreach ([$photo, $overlay, $logo, $canvas] as $obj) {
            if ($obj instanceof Imagick) {
                $obj->clear();
                $obj->destroy();
            }
        }
        return (bool)$written;
    } catch (Throwable $e) {
        photo_overlay_log('Render failed: ' . $e->getMessage());
        return false;
    }
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


function photo_detect_upload_orientation($path) {
    $orientation = 'portrait';

    if (extension_loaded('imagick')) {
        try {
            $im = new Imagick($path);
            if (method_exists($im, 'autoOrient')) {
                $im->autoOrient();
            } elseif (method_exists($im, 'autoOrientImage')) {
                $im->autoOrientImage();
            }
            $w = (int)$im->getImageWidth();
            $h = (int)$im->getImageHeight();
            $im->clear();
            $im->destroy();
            if ($w > 0 && $h > 0) {
                return ($w > ($h * 1.15)) ? 'landscape' : 'portrait';
            }
        } catch (Throwable $e) {
            photo_overlay_log('Orientation detect via Imagick failed: ' . $e->getMessage());
        }
    }

    $info = @getimagesize($path);
    if ($info && !empty($info[0]) && !empty($info[1])) {
        $w = (int)$info[0];
        $h = (int)$info[1];

        // Some phone portraits are stored sideways with an EXIF orientation flag.
        // If EXIF says rotate 90/270, swap dimensions before deciding template.
        if (function_exists('exif_read_data')) {
            try {
                $exif = @exif_read_data($path);
                $exifOrientation = (int)($exif['Orientation'] ?? 0);
                if (in_array($exifOrientation, [5, 6, 7, 8], true)) {
                    $tmp = $w;
                    $w = $h;
                    $h = $tmp;
                }
            } catch (Throwable $e) {
                // Ignore EXIF read errors; use getimagesize dimensions.
            }
        }

        $orientation = ($w > ($h * 1.15)) ? 'landscape' : 'portrait';
    }

    return $orientation;
}


function photo_process_uploaded_file($tmpPath, $originalName, $event, $guestName = '') {
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

    // Determine visual orientation after allowing for phone EXIF rotation.
    // getimagesize() alone can report a portrait phone photo as landscape.
    $orientation = photo_detect_upload_orientation($tmpPath);

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

    if (!photo_render_framed_image($originalAbs, $framedAbs, $event, $orientation, $guestName)) {
        photo_overlay_log('Falling back to original image copy for ' . basename($originalAbs));
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