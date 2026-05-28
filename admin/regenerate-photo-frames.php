<?php
require_once __DIR__ . '/../includes/photo-uploads.php';
require_once __DIR__ . '/_auth.php';

$message = '';
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectPieces = [
        'p.id', 'p.file_path', 'p.original_filename', 'p.status',
        'e.id AS event_id', 'e.event_name', 'e.venue_name', 'e.event_date',
    ];
    $selectPieces[] = photo_column_exists('event_photo_uploads', 'original_path') ? 'p.original_path' : "p.file_path AS original_path";
    $selectPieces[] = photo_column_exists('event_photo_uploads', 'framed_path') ? 'p.framed_path' : "p.file_path AS framed_path";
    $selectPieces[] = photo_column_exists('event_photo_uploads', 'thumb_path') ? 'p.thumb_path' : "'' AS thumb_path";
    $selectPieces[] = photo_column_exists('event_photo_uploads', 'image_orientation') ? 'p.image_orientation' : "'' AS image_orientation";

    $stmt = db()->query('SELECT ' . implode(', ', $selectPieces) . ' FROM event_photo_uploads p INNER JOIN events e ON e.id = p.event_id ORDER BY p.id DESC');
    foreach ($stmt->fetchAll() as $row) {
        $originalRel = trim((string)($row['original_path'] ?? ''));
        $framedRel = trim((string)($row['framed_path'] ?? '')) ?: trim((string)($row['file_path'] ?? ''));
        $thumbRel = trim((string)($row['thumb_path'] ?? ''));

        if ($originalRel === '' || $framedRel === '') {
            $results[] = 'Photo #' . (int)$row['id'] . ': skipped, missing paths.';
            continue;
        }

        $originalAbs = dirname(__DIR__) . '/' . ltrim($originalRel, '/');
        $framedAbs = dirname(__DIR__) . '/' . ltrim($framedRel, '/');
        $thumbAbs = $thumbRel !== '' ? dirname(__DIR__) . '/' . ltrim($thumbRel, '/') : '';

        if (!is_file($originalAbs)) {
            $results[] = 'Photo #' . (int)$row['id'] . ': skipped, original file not found.';
            continue;
        }

        $info = @getimagesize($originalAbs);
        $orientation = (string)($row['image_orientation'] ?? '');
        if ($orientation === '' && $info) {
            $orientation = ((int)$info[0] > ((int)$info[1] * 1.15)) ? 'landscape' : 'portrait';
        }

        $event = [
            'id' => $row['event_id'] ?? null,
            'event_name' => $row['event_name'] ?? '',
            'venue_name' => $row['venue_name'] ?? '',
            'event_date' => $row['event_date'] ?? '',
        ];

        @mkdir(dirname($framedAbs), 0775, true);
        if (!photo_render_framed_image($originalAbs, $framedAbs, $event, $orientation ?: 'portrait')) {
            $results[] = 'Photo #' . (int)$row['id'] . ': failed to regenerate framed image.';
            continue;
        }

        if ($thumbAbs !== '') {
            @mkdir(dirname($thumbAbs), 0775, true);
            photo_render_thumb($framedAbs, $thumbAbs, 540);
        }
        $results[] = 'Photo #' . (int)$row['id'] . ': regenerated.';
    }

    $message = 'Regeneration finished.';
}

admin_header('Regenerate Photo Frames');
?>
<div class="container">
  <div class="card">
    <h1>Regenerate Photo Frames</h1>
    <p>This rebuilds the framed and thumbnail files from the stored original uploads using the latest photo template.</p>
    <?php if ($message): ?><p class="notice"><?= h($message) ?></p><?php endif; ?>
    <form method="post" onsubmit="return confirm('Regenerate all existing event photo frames now?');">
      <button type="submit" class="btn btn-primary">Regenerate all photo frames</button>
      <a class="btn" href="<?= h(admin_url('event-photos.php')) ?>">Back to Photo Moderation</a>
    </form>
    <?php if ($results): ?>
      <pre style="margin-top:18px; white-space:pre-wrap; background:#06101d; border:1px solid rgba(90,160,255,.25); border-radius:14px; padding:14px;"><?= h(implode("\n", $results)) ?></pre>
    <?php endif; ?>
  </div>
</div>
<?php admin_footer(); ?>
