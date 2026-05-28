<?php
require_once __DIR__ . '/../includes/photo-uploads.php';
require_once __DIR__ . '/_auth.php';

$message = '';
$errors = [];
$updated = 0;
$checked = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectPieces = [
        'p.*',
        'e.event_name',
        'e.venue_name',
        'e.event_date',
    ];
    if (!photo_column_exists('event_photo_uploads', 'original_path')) $selectPieces[] = "'' AS original_path";
    if (!photo_column_exists('event_photo_uploads', 'framed_path')) $selectPieces[] = "'' AS framed_path";
    if (!photo_column_exists('event_photo_uploads', 'thumb_path')) $selectPieces[] = "'' AS thumb_path";
    if (!photo_column_exists('event_photo_uploads', 'image_orientation')) $selectPieces[] = "'' AS image_orientation";

    $stmt = db()->query('SELECT ' . implode(', ', $selectPieces) . ' FROM event_photo_uploads p INNER JOIN events e ON e.id = p.event_id ORDER BY p.id DESC');
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $checked++;
        $paths = photo_row_display_paths($row);
        $originalRel = trim((string)($paths['original'] ?? ''));
        $framedRel = trim((string)($paths['display'] ?? ''));
        $thumbRel = trim((string)($paths['thumb'] ?? ''));

        $root = dirname(__DIR__);
        $originalAbs = $root . '/' . ltrim($originalRel, '/');
        $framedAbs = $root . '/' . ltrim($framedRel, '/');
        $thumbAbs = $root . '/' . ltrim($thumbRel, '/');

        if (!is_file($originalAbs)) {
            $errors[] = 'Photo #' . (int)$row['id'] . ': original file missing (' . $originalRel . ')';
            continue;
        }

        $info = @getimagesize($originalAbs);
        $orientation = 'portrait';
        if ($info && !empty($info[0]) && !empty($info[1])) {
            $orientation = ((int)$info[0] > ((int)$info[1] * 1.10)) ? 'landscape' : 'portrait';
        }
        if (!empty($row['image_orientation']) && in_array($row['image_orientation'], ['portrait', 'landscape'], true)) {
            $orientation = $row['image_orientation'];
        }

        if (!is_dir(dirname($framedAbs))) @mkdir(dirname($framedAbs), 0775, true);
        if (!is_dir(dirname($thumbAbs))) @mkdir(dirname($thumbAbs), 0775, true);

        $event = [
            'event_name' => $row['event_name'] ?? '',
            'venue_name' => $row['venue_name'] ?? '',
            'event_date' => $row['event_date'] ?? '',
        ];

        if (!photo_render_framed_image($originalAbs, $framedAbs, $event, $orientation)) {
            $errors[] = 'Photo #' . (int)$row['id'] . ': could not regenerate framed image.';
            continue;
        }
        if (!photo_render_thumb($framedAbs, $thumbAbs, 540)) {
            $errors[] = 'Photo #' . (int)$row['id'] . ': framed image regenerated, but thumbnail failed.';
            continue;
        }
        $updated++;
    }

    $message = 'Regenerated ' . $updated . ' of ' . $checked . ' photo frame(s).';
}

admin_header('Regenerate Photo Frames');
?>
<div class="container">
  <div class="card" style="max-width:900px;margin:0 auto;">
    <h1 style="margin-top:0;">Regenerate Photo Frames</h1>
    <p style="opacity:.82;line-height:1.6;">Use this after changing the event photo frame design. It rebuilds the framed display image and thumbnail from the saved original upload.</p>

    <?php if ($message): ?>
      <div class="notice success" style="margin:18px 0;"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="notice" style="margin:18px 0;">
        <strong>Some photos could not be regenerated:</strong>
        <ul>
          <?php foreach (array_slice($errors, 0, 20) as $error): ?>
            <li><?= h($error) ?></li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($errors) > 20): ?><p><?= count($errors) - 20 ?> more error(s) hidden.</p><?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="post" onsubmit="return confirm('Regenerate all saved event photo frames now?');">
      <button class="btn btn-primary" type="submit">Regenerate all photo frames</button>
      <a class="btn" href="event-photos.php" style="margin-left:8px;">Back to Photo Moderation</a>
    </form>
  </div>
</div>
<?php admin_footer(); ?>
