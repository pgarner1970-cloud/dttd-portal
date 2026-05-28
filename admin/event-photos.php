<?php
require_once __DIR__ . '/../includes/photo-uploads.php';
require_once __DIR__ . '/_auth.php';

$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'pending')));
if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $statusFilter = 'pending';
}
$eventFilter = (int)($_GET['event_id'] ?? 0);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['photo_id']) && !empty($_POST['action'])) {
    $photoId = (int)$_POST['photo_id'];
    $action = (string)$_POST['action'];
    $newStatus = null;
    if ($action === 'approve') $newStatus = 'approved';
    if ($action === 'reject') $newStatus = 'rejected';
    if ($action === 'pending') $newStatus = 'pending';

    if ($newStatus) {
        $stmt = db()->prepare('UPDATE event_photo_uploads SET status = ? WHERE id = ?');
        $stmt->execute([$newStatus, $photoId]);
        $message = 'Photo status updated.';
    }
}

$eventOptions = db()->query("SELECT id, event_name, venue_name, event_date FROM events ORDER BY event_date DESC, id DESC")->fetchAll();

$selectPieces = [
    'p.*',
    'e.event_name',
    'e.venue_name',
    'e.event_date',
];
if (!photo_column_exists('event_photo_uploads', 'original_path')) $selectPieces[] = "'' AS original_path";
if (!photo_column_exists('event_photo_uploads', 'framed_path')) $selectPieces[] = "'' AS framed_path";
if (!photo_column_exists('event_photo_uploads', 'thumb_path')) $selectPieces[] = "'' AS thumb_path";

$sql = 'SELECT ' . implode(', ', $selectPieces) . ' FROM event_photo_uploads p INNER JOIN events e ON e.id = p.event_id WHERE 1=1';
$params = [];
if ($statusFilter !== 'all') {
    $sql .= ' AND p.status = ?';
    $params[] = $statusFilter;
}
if ($eventFilter > 0) {
    $sql .= ' AND p.event_id = ?';
    $params[] = $eventFilter;
}
$sql .= ' ORDER BY FIELD(p.status, "pending","approved","rejected"), p.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$photos = $stmt->fetchAll();

admin_header('Photo Moderation');
?>
<div class="container">
  <div class="card">
    <div style="display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:end; margin-bottom:20px;">
      <div>
        <h1 style="margin:0 0 8px;">Photo Moderation</h1>
        <p style="margin:0; opacity:.82;">Review uploaded event photos and approve or reject them before they appear publicly.</p>
      </div>
      <form method="get" style="display:flex; gap:12px; flex-wrap:wrap; align-items:end;">
        <label>
          <span style="display:block; margin-bottom:6px;">Status</span>
          <select name="status">
            <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <span style="display:block; margin-bottom:6px;">Event</span>
          <select name="event_id">
            <option value="0">All events</option>
            <?php foreach ($eventOptions as $event): ?>
              <option value="<?= (int)$event['id'] ?>" <?= $eventFilter === (int)$event['id'] ? 'selected' : '' ?>><?= h(photo_event_label($event)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn" type="submit">Filter</button>
      </form>
    </div>

    <?php if ($message): ?>
      <div class="notice success" style="margin-bottom:18px;"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if (!$photos): ?>
      <div class="notice">No photos match this filter.</div>
    <?php else: ?>
      <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:18px;">
        <?php foreach ($photos as $photo):
          $paths = photo_row_display_paths($photo);
          $thumbUrl = photo_public_url($paths['thumb']);
          $displayUrl = photo_public_url($paths['display']);
          $dateText = !empty($photo['event_date']) ? date('D j M Y', strtotime($photo['event_date'])) : '';
        ?>
          <article class="card" style="padding:14px;">
            <a href="<?= h($displayUrl) ?>" target="_blank" rel="noopener"><img src="<?= h($thumbUrl) ?>" alt="" style="width:100%; aspect-ratio:1/1; object-fit:cover; border-radius:14px; margin-bottom:12px;"></a>
            <h3 style="margin:0 0 8px;"><?= h($photo['event_name'] ?? 'Event photo') ?></h3>
            <p style="margin:0 0 4px; opacity:.85;"><?= h(($photo['venue_name'] ?? '') . ($dateText ? ' · ' . $dateText : '')) ?></p>
            <p style="margin:0 0 4px; opacity:.85;">Status: <strong><?= h(ucfirst((string)$photo['status'])) ?></strong></p>
            <?php if (!empty($photo['guest_name'])): ?><p style="margin:0 0 10px; opacity:.85;">Shared by <?= h($photo['guest_name']) ?></p><?php endif; ?>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
              <?php if (($photo['status'] ?? '') !== 'approved'): ?>
                <form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="approve"><button class="btn btn-primary" type="submit">Approve</button></form>
              <?php endif; ?>
              <?php if (($photo['status'] ?? '') !== 'rejected'): ?>
                <form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="reject"><button class="btn" type="submit">Reject</button></form>
              <?php endif; ?>
              <?php if (($photo['status'] ?? '') !== 'pending'): ?>
                <form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="pending"><button class="btn" type="submit">Back to Pending</button></form>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php admin_footer(); ?>
