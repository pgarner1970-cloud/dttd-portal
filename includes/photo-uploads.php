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
    // Preferred renderer: Imagick + pre-rendered transparent template assets.
    // This is much closer to the approved mock-up than drawing the whole frame with GD lines.
    if (extension_loaded('imagick') && class_exists('Imagick')) {
        try {
            if (photo_render_framed_image_imagick($sourcePath, $destPath, $event, $orientation, $creditName)) {
                return true;
            }
        } catch (Throwable $e) {
            // Fall through to the existing upload fallback behaviour.
        }
    }

    // If Imagick is ever unavailable, fail gracefully so the caller keeps the original upload.
    return false;
}

function photo_overlay_clean_text($value, $fallback = '') {
    $text = trim((string)$value);
    if ($text === '') {
        $text = $fallback;
    }
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r", "\n", "\t"], ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    // Keep these overlays simple and avoid mojibake-prone symbols in generated artwork.
    $text = str_replace(['–', '—', '•', '…'], ['-', '-', '-', '...'], $text);
    return trim($text);
}

function photo_overlay_font($bold = false) {
    $candidates = $bold ? [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
    ] : [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    ];
    foreach ($candidates as $font) {
        if (is_file($font)) {
            return $font;
        }
    }
    return '';
}

function photo_imagick_cover_to_canvas($path, $canvasW, $canvasH) {
    $img = new Imagick($path);
    if (method_exists($img, 'autoOrient')) {
        $img->autoOrient();
    } elseif (method_exists($img, 'autoOrientImage')) {
        $img->autoOrientImage();
    }
    $img->setImageColorspace(Imagick::COLORSPACE_SRGB);
    $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
    $img->setImageBackgroundColor(new ImagickPixel('black'));
    $img->cropThumbnailImage($canvasW, $canvasH);
    $img->setImagePage(0, 0, 0, 0);
    return $img;
}

function photo_imagick_text_width($img, $text, $font, $size, $bold = false) {
    $draw = new ImagickDraw();
    if ($font !== '') { $draw->setFont($font); }
    $draw->setFontSize($size);
    $draw->setFontWeight($bold ? 700 : 400);
    $metrics = $img->queryFontMetrics($draw, (string)$text);
    return isset($metrics['textWidth']) ? (float)$metrics['textWidth'] : 0.0;
}

function photo_imagick_draw_text($img, $text, $x, $baselineY, $size, $font, $fill, $maxW = null, $bold = false, $stroke = null, $strokeWidth = 0, $minSize = 12) {
    $text = photo_overlay_clean_text($text);
    if ($text === '') { return; }
    $size = (int)$size;
    if ($maxW !== null) {
        while ($size > $minSize && photo_imagick_text_width($img, $text, $font, $size, $bold) > $maxW) {
            $size -= 2;
        }
        while ($size <= $minSize && photo_imagick_text_width($img, $text, $font, $size, $bold) > $maxW && strlen($text) > 6) {
            $text = rtrim(substr($text, 0, -4)) . '...';
        }
    }
    $draw = new ImagickDraw();
    if ($font !== '') { $draw->setFont($font); }
    $draw->setFontSize($size);
    $draw->setFontWeight($bold ? 700 : 400);
    $draw->setFillColor(new ImagickPixel($fill));
    if ($stroke && $strokeWidth > 0) {
        $draw->setStrokeColor(new ImagickPixel($stroke));
        $draw->setStrokeWidth($strokeWidth);
        $draw->setStrokeAntialias(true);
    }
    $draw->setTextAntialias(true);
    $img->annotateImage($draw, (int)$x, (int)$baselineY, 0, $text);
}

function photo_imagick_draw_centered_text($img, $text, $centerX, $baselineY, $size, $font, $fill, $maxW, $bold = false, $stroke = null, $strokeWidth = 0) {
    $text = photo_overlay_clean_text($text);
    if ($text === '') { return; }
    $actualSize = (int)$size;
    while ($actualSize > 12 && photo_imagick_text_width($img, $text, $font, $actualSize, $bold) > $maxW) {
        $actualSize -= 2;
    }
    $w = photo_imagick_text_width($img, $text, $font, $actualSize, $bold);
    photo_imagick_draw_text($img, $text, (int)round($centerX - ($w / 2)), $baselineY, $actualSize, $font, $fill, $maxW, $bold, $stroke, $strokeWidth);
}

function photo_imagick_draw_pill($img, $text, $x, $y, $fontSize, $font, $padX = 22) {
    $text = photo_overlay_clean_text($text);
    if ($text === '') { return; }
    $w = (int)ceil(photo_imagick_text_width($img, $text, $font, $fontSize, false)) + ($padX * 2);
    $h = max(36, $fontSize + 18);
    $draw = new ImagickDraw();
    $draw->setFillColor(new ImagickPixel('rgba(10,0,18,0.72)'));
    $draw->setStrokeColor(new ImagickPixel('rgba(255,145,255,0.88)'));
    $draw->setStrokeWidth(2);
    $draw->roundRectangle($x, $y, $x + $w, $y + $h, (int)($h / 2), (int)($h / 2));
    $img->drawImage($draw);
    photo_imagick_draw_text($img, $text, $x + $padX, $y + $fontSize + 7, $fontSize, $font, '#ffffff', null, false);
}

function photo_render_framed_image_imagick($sourcePath, $destPath, $event, $orientation = 'portrait', $creditName = '') {
    $landscape = ($orientation === 'landscape');
    $canvasW = $landscape ? 1600 : 1080;
    $canvasH = $landscape ? 1200 : 1350;

    $assetName = $landscape ? 'dttd-overlay-landscape.png' : 'dttd-overlay-portrait.png';
    $overlayPath = dirname(__DIR__) . '/assets/' . $assetName;
    if (!is_file($overlayPath)) {
        return false;
    }

    $img = photo_imagick_cover_to_canvas($sourcePath, $canvasW, $canvasH);
    $overlay = new Imagick($overlayPath);
    $overlay->setImagePage(0, 0, 0, 0);
    if ($overlay->getImageWidth() !== $canvasW || $overlay->getImageHeight() !== $canvasH) {
        $overlay->resizeImage($canvasW, $canvasH, Imagick::FILTER_LANCZOS, 1);
    }
    $img->compositeImage($overlay, Imagick::COMPOSITE_OVER, 0, 0);
    $overlay->clear();
    $overlay->destroy();

    $fontBold = photo_overlay_font(true);
    $fontRegular = photo_overlay_font(false);

    $eventName = photo_overlay_clean_text($event['event_name'] ?? $event['name'] ?? '', 'Dance Thru The Decades');
    $venueLine = photo_overlay_clean_text($event['venue_name'] ?? $event['venue'] ?? '', 'Dance Thru The Decades Events');
    $dateLine = photo_overlay_clean_text(photo_event_date_long($event['event_date'] ?? ''), '');

    if ($landscape) {
        photo_imagick_draw_text($img, $eventName, 270, 118, 64, $fontBold, '#ffda48', 555, true, 'rgba(110,0,90,0.75)', 2, 28);
        photo_imagick_draw_text($img, 'Venue: ' . $venueLine, 838, 105, 25, $fontRegular, '#f5ebff', 370, false, null, 0, 16);
        if ($dateLine !== '') {
            photo_imagick_draw_text($img, 'Date: ' . $dateLine, 1240, 105, 24, $fontRegular, '#f5ebff', 300, false, null, 0, 16);
        }
        $creditName = photo_overlay_clean_text($creditName);
        if ($creditName !== '') {
            $creditText = 'Photo by ' . $creditName;
            $creditW = min(320, (int)photo_imagick_text_width($img, $creditText, $fontRegular, 21) + 44);
            photo_imagick_draw_pill($img, $creditText, $canvasW - 44 - $creditW - 50, 166, 21, $fontRegular, 22);
        }
    } else {
        photo_imagick_draw_text($img, $eventName, 238, 112, 46, $fontBold, '#ffda48', 765, true, 'rgba(110,0,90,0.75)', 2, 24);
        photo_imagick_draw_text($img, $venueLine, 238, 152, 24, $fontRegular, '#f5ebff', 755, false, null, 0, 15);
        if ($dateLine !== '') {
            photo_imagick_draw_text($img, $dateLine, 238, 186, 22, $fontRegular, '#ffda48', 755, false, null, 0, 15);
        }
        $creditName = photo_overlay_clean_text($creditName);
        if ($creditName !== '') {
            photo_imagick_draw_pill($img, 'Photo by ' . $creditName, 650, 206, 18, $fontRegular, 18);
        }
    }

    $img->setImageFormat('jpeg');
    $img->setImageCompression(Imagick::COMPRESSION_JPEG);
    $img->setImageCompressionQuality(94);
    if (!is_dir(dirname($destPath))) {
        @mkdir(dirname($destPath), 0775, true);
    }
    $ok = $img->writeImage($destPath);
    $img->clear();
    $img->destroy();
    return (bool)$ok;
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

    if (!photo_render_framed_image($originalAbs, $framedAbs, $event, $orientation, $guestName)) {
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