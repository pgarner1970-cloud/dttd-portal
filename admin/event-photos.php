<?php
require_once __DIR__ . '/../includes/photo-uploads.php';
require_once __DIR__ . '/_auth.php';

$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'pending')));
if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $statusFilter = 'pending';
}
$eventFilter = (int)($_GET['event_id'] ?? 0);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['photo_id']) && !empty($_POST['action'])) {
    $photoId = (int)$_POST['photo_id'];
    $action = (string)$_POST['action'];
    $newStatus = null;
    if ($action === 'approve') $newStatus = 'approved';
    if ($action === 'reject') $newStatus = 'rejected';
    if ($action === 'pending') $newStatus = 'pending';

    if ($action === 'delete_permanent') {
        $stmt = db()->prepare('SELECT * FROM event_photo_uploads WHERE id = ? LIMIT 1');
        $stmt->execute([$photoId]);
        $photo = $stmt->fetch();
        if ($photo) {
            photo_delete_upload_files($photo);
            $delete = db()->prepare('DELETE FROM event_photo_uploads WHERE id = ?');
            $delete->execute([$photoId]);
            $message = 'Photo permanently deleted from the server.';
        } else {
            $error = 'That photo could not be found.';
        }
    } elseif ($newStatus) {
        $stmt = db()->prepare('UPDATE event_photo_uploads SET status = ? WHERE id = ?');
        $stmt->execute([$newStatus, $photoId]);
        $message = 'Photo status updated.';
    }
}

$eventOptions = db()->query("SELECT id, event_name, venue_name, event_date FROM events ORDER BY event_date DESC, id DESC")->fetchAll();

$countSql = 'SELECT p.status, COUNT(*) AS total FROM event_photo_uploads p WHERE 1=1';
$countParams = [];
if ($eventFilter > 0) {
    $countSql .= ' AND p.event_id = ?';
    $countParams[] = $eventFilter;
}
$countSql .= ' GROUP BY p.status';
$countStmt = db()->prepare($countSql);
$countStmt->execute($countParams);
$statusCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($countStmt->fetchAll() as $row) {
    $statusCounts[(string)$row['status']] = (int)$row['total'];
}
$statusCounts['all'] = $statusCounts['pending'] + $statusCounts['approved'] + $statusCounts['rejected'];

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
<div class="touch-wrap photo-moderation-page">
  <section class="touch-panel photo-moderation-panel">
    <div class="touch-panel-header photo-moderation-header">
      <div>
        <h1 class="touch-panel-title">Photo Moderation</h1>
        <p class="touch-subtitle">Review uploaded event photos and approve, reject or permanently delete them before they appear publicly.</p>
      </div>
      <form method="get" class="photo-filter-form">
        <label>
          <span>Status</span>
          <select name="status">
            <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="event-filter-label">
          <span>Event</span>
          <select name="event_id">
            <option value="0">All events</option>
            <?php foreach ($eventOptions as $event): ?>
              <option value="<?= (int)$event['id'] ?>" <?= $eventFilter === (int)$event['id'] ? 'selected' : '' ?>><?= h(photo_event_label($event)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="touch-btn blue compact" type="submit">Filter</button>
      </form>
    </div>

    <div class="touch-panel-pad">
      <?php if ($message): ?><div class="notice success" style="margin-bottom:18px;"><?= h($message) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="notice error" style="margin-bottom:18px;"><?= h($error) ?></div><?php endif; ?>

      <?php if ($eventFilter > 0):
        $selectedEvent = null;
        foreach ($eventOptions as $event) {
          if ((int)$event['id'] === $eventFilter) { $selectedEvent = $event; break; }
        }
        if ($selectedEvent): ?>
          <div class="photo-selected-event">
            <span>Event</span>
            <strong><?= h(photo_event_label($selectedEvent)) ?></strong>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <nav class="photo-status-tabs" aria-label="Photo status filters">
        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label):
          $query = ['status' => $value];
          if ($eventFilter > 0) $query['event_id'] = $eventFilter;
        ?>
          <a class="photo-status-tab <?= $statusFilter === $value ? 'active ' . h($value) : '' ?>" href="?<?= h(http_build_query($query)) ?>">
            <?= h($label) ?> <span><?= (int)$statusCounts[$value] ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <?php if (!$photos): ?>
        <div class="notice">No photos match this filter.</div>
      <?php else: ?>
        <div class="photo-card-grid">
          <?php foreach ($photos as $photo):
            $photo = photo_ensure_display_versions($photo);
            $paths = photo_row_display_paths($photo);
            $thumbUrl = photo_public_url($paths['thumb']);
            $displayUrl = photo_public_url($paths['display']);
            $timeText = '';
            if (!empty($photo['created_at'])) {
              try { $timeText = (new DateTime($photo['created_at']))->format('H:i - j M'); } catch (Throwable $e) { $timeText = ''; }
            }
            $status = strtolower((string)($photo['status'] ?? 'pending'));
          ?>
            <article class="photo-moderation-card status-<?= h($status) ?>">
              <a class="photo-card-image" href="<?= h($displayUrl) ?>" target="_blank" rel="noopener">
                <img src="<?= h($thumbUrl ?: $displayUrl) ?>" alt="<?= h((string)($photo['event_name'] ?? 'Event photo')) ?>">
              </a>
              <div class="photo-card-body">
                <div class="photo-card-row">
                  <span class="photo-status-pill <?= h($status) ?>"><?= h(strtoupper($status)) ?></span>
                  <?php if ($timeText): ?><span class="photo-card-time"><?= h($timeText) ?></span><?php endif; ?>
                </div>
                <h2><?= h(trim((string)($photo['guest_name'] ?? '')) ?: 'Guest upload') ?></h2>
                <?php if (!empty($photo['original_filename'])): ?><p class="photo-file-name"><?= h((string)$photo['original_filename']) ?></p><?php endif; ?>

                <label class="photo-note-label">
                  <span>Moderation note</span>
                  <input type="text" value="" placeholder="Optional internal note" disabled>
                </label>

                <div class="photo-actions">
                  <?php if ($status !== 'approved'): ?>
                    <form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="approve"><button class="touch-btn green compact" type="submit">Approve</button></form>
                  <?php endif; ?>
                  <?php if ($status !== 'rejected'): ?>
                    <form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="reject"><button class="touch-btn red compact" type="submit">Reject</button></form>
                  <?php endif; ?>
                  <?php if ($status !== 'pending'): ?>
                    <form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="pending"><button class="touch-btn compact" type="submit">Back to Pending</button></form>
                  <?php endif; ?>
                  <a class="touch-btn blue compact" href="<?= h($displayUrl) ?>" target="_blank" rel="noopener">View</a>
                  <?php if ($status === 'rejected'): ?>
                    <form method="post" onsubmit="return confirm('Delete this rejected photo permanently? This removes the database record and all stored image files. This cannot be undone.');">
                      <input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>">
                      <input type="hidden" name="action" value="delete_permanent">
                      <button class="touch-btn danger compact" type="submit">Delete Permanently</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>
<?php admin_footer(); ?>
