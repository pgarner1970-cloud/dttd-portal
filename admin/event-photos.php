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

function admin_photo_load($photoId) {
    $selectPieces = ['p.*', 'e.event_name', 'e.venue_name', 'e.event_date'];
    if (!photo_column_exists('event_photo_uploads', 'original_path')) $selectPieces[] = "'' AS original_path";
    if (!photo_column_exists('event_photo_uploads', 'framed_path')) $selectPieces[] = "'' AS framed_path";
    if (!photo_column_exists('event_photo_uploads', 'thumb_path')) $selectPieces[] = "'' AS thumb_path";
    if (!photo_column_exists('event_photo_uploads', 'image_orientation')) $selectPieces[] = "'' AS image_orientation";
    $stmt = db()->prepare('SELECT ' . implode(', ', $selectPieces) . ' FROM event_photo_uploads p INNER JOIN events e ON e.id = p.event_id WHERE p.id = ? LIMIT 1');
    $stmt->execute([(int)$photoId]);
    return $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['photo_id']) && !empty($_POST['action'])) {
    $photoId = (int)$_POST['photo_id'];
    $action = (string)$_POST['action'];
    $photo = admin_photo_load($photoId);

    if (!$photo) {
        $error = 'Photo record not found.';
    } elseif ($action === 'delete') {
        photo_delete_upload_files($photo);
        $stmt = db()->prepare('DELETE FROM event_photo_uploads WHERE id = ?');
        $stmt->execute([$photoId]);
        $message = 'Photo permanently deleted.';
    } else {
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
}

$eventOptions = db()->query("SELECT id, event_name, venue_name, event_date FROM events ORDER BY event_date DESC, id DESC")->fetchAll();

$selectPieces = ['p.*', 'e.event_name', 'e.venue_name', 'e.event_date'];
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
foreach ($photos as $photo) {
    photo_ensure_generated_files($photo, $photo);
}

$countSql = 'SELECT status, COUNT(*) AS total FROM event_photo_uploads GROUP BY status';
$rawCounts = db()->query($countSql)->fetchAll();
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
foreach ($rawCounts as $row) {
    $key = strtolower((string)$row['status']);
    if (isset($counts[$key])) $counts[$key] = (int)$row['total'];
    $counts['all'] += (int)$row['total'];
}

admin_header('Photo Moderation');
?>
<style>
.photo-mod-panel{max-width:1180px;margin:0 auto 22px;padding:24px;border:1px solid rgba(96,165,250,.22);border-radius:22px;background:linear-gradient(135deg,rgba(12,28,48,.95),rgba(5,14,24,.96));box-shadow:0 18px 42px rgba(0,0,0,.22)}
.photo-mod-panel h1{margin:0 0 8px;font-size:32px;line-height:1.1}.photo-mod-panel p{margin:0;color:#cbd5e1}.photo-mod-filters{display:grid;grid-template-columns:minmax(180px,280px) minmax(260px,1fr) auto;gap:12px;align-items:end;margin-top:18px}.photo-mod-filters label{display:grid;gap:8px;color:#fff;font-weight:800}.photo-mod-filters select,.photo-note-input{width:100%;border:1px solid rgba(148,163,184,.35);border-radius:14px;background:#101827;color:#fff;padding:12px 14px;font-weight:800}.photo-tabs{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.photo-tab{display:inline-flex;align-items:center;gap:10px;text-decoration:none;border:1px solid #2563eb;border-radius:999px;padding:10px 16px;color:#fff;background:#08224a;font-weight:900}.photo-tab.active{border-color:#facc15;background:#332400}.photo-tab span{display:inline-grid;place-items:center;min-width:24px;height:24px;border-radius:999px;background:#1d4ed8;color:#fff}.photo-tab.active span{background:#a16207}.photo-grid{max-width:1180px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,280px));gap:18px;align-items:start}.photo-card{overflow:hidden;border:1px solid rgba(59,130,246,.35);border-radius:20px;background:#07111f;color:#fff;box-shadow:0 14px 34px rgba(0,0,0,.28)}.photo-card.is-rejected{border-color:rgba(248,113,113,.5)}.photo-thumb{display:block;height:176px;background:#030713;overflow:hidden;border-bottom:1px solid rgba(148,163,184,.12)}.photo-thumb img{display:block;width:100%;height:100%;object-fit:cover}.photo-thumb .missing{height:100%;display:grid;place-items:center;padding:20px;color:#fca5a5;text-align:center;font-weight:900}.photo-card-body{padding:16px}.photo-card-row{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px}.photo-status{display:inline-flex;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:1000;text-transform:uppercase;border:1px solid currentColor}.photo-status.pending{color:#fde047;background:rgba(250,204,21,.08)}.photo-status.approved{color:#86efac;background:rgba(34,197,94,.08)}.photo-status.rejected{color:#fca5a5;background:rgba(239,68,68,.08)}.photo-time{font-size:12px;color:#94a3b8;font-weight:900}.photo-card h2{font-size:20px;margin:0 0 8px;line-height:1.15}.photo-card .filename{font-size:13px;color:#cbd5e1;margin-bottom:12px;word-break:break-word}.photo-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.photo-actions form{display:inline}.photo-btn{border:1px solid rgba(148,163,184,.25);border-radius:13px;padding:10px 12px;color:#fff;background:#1f2937;font-weight:1000;cursor:pointer;text-decoration:none;display:inline-flex}.photo-btn.approve{background:#052e1a;border-color:#16a34a}.photo-btn.reject,.photo-btn.delete{background:#2b0811;border-color:#dc2626}.photo-btn.view{background:#08265c;border-color:#2563eb}.photo-empty{max-width:1180px;margin:0 auto;padding:24px;border:1px dashed rgba(148,163,184,.35);border-radius:18px;color:#cbd5e1}@media(max-width:760px){.photo-mod-filters{grid-template-columns:1fr}.photo-grid{grid-template-columns:1fr}}
</style>

<div class="photo-mod-panel">
  <h1>Photo Moderation</h1>
  <p>Review uploaded event photos and approve or reject them before they appear publicly.</p>

  <?php if ($message): ?><div class="notice success" style="margin-top:16px;"><?= h($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice error" style="margin-top:16px;"><?= h($error) ?></div><?php endif; ?>

  <form class="photo-mod-filters" method="get">
    <label>Status
      <select name="status">
        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label): ?>
          <option value="<?= h($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Event
      <select name="event_id">
        <option value="0">All events</option>
        <?php foreach ($eventOptions as $event): ?>
          <option value="<?= (int)$event['id'] ?>" <?= $eventFilter === (int)$event['id'] ? 'selected' : '' ?>><?= h(photo_event_label($event)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn" type="submit">Filter</button>
  </form>

  <nav class="photo-tabs" aria-label="Photo status filters">
    <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label):
      $url = '?status=' . rawurlencode($value) . ($eventFilter > 0 ? '&event_id=' . (int)$eventFilter : '');
    ?>
      <a class="photo-tab <?= $statusFilter === $value ? 'active' : '' ?>" href="<?= h($url) ?>"><?= h($label) ?> <span><?= (int)$counts[$value] ?></span></a>
    <?php endforeach; ?>
  </nav>
</div>

<?php if (!$photos): ?>
  <div class="photo-empty">No photos match this filter.</div>
<?php else: ?>
  <div class="photo-grid">
    <?php foreach ($photos as $photo):
      $paths = photo_row_display_paths($photo);
      $thumbUrl = photo_public_url($paths['thumb']);
      $displayUrl = photo_public_url($paths['display']);
      $status = strtolower((string)($photo['status'] ?? 'pending'));
      $created = !empty($photo['created_at']) ? strtotime((string)$photo['created_at']) : 0;
      $timeText = $created ? date('H:i - j M', $created) : '';
      $name = trim((string)($photo['guest_name'] ?? '')) ?: 'Guest upload';
      $filename = trim((string)($photo['original_filename'] ?? '')) ?: basename((string)$paths['original']);
    ?>
      <article class="photo-card is-<?= h($status) ?>">
        <a class="photo-thumb" href="<?= h($displayUrl ?: $thumbUrl) ?>" target="_blank" rel="noopener">
          <?php if ($thumbUrl): ?>
            <img src="<?= h($thumbUrl) ?>?v=<?= (int)($photo['id'] ?? 0) ?>" alt="<?= h((string)($photo['event_name'] ?? 'Event photo')) ?>" loading="lazy">
          <?php else: ?>
            <div class="missing">Image file missing</div>
          <?php endif; ?>
        </a>
        <div class="photo-card-body">
          <div class="photo-card-row"><span class="photo-status <?= h($status) ?>"><?= h($status) ?></span><span class="photo-time"><?= h($timeText) ?></span></div>
          <h2><?= h($name) ?></h2>
          <div class="filename"><?= h($filename) ?></div>
          <label style="display:grid;gap:8px;font-weight:900;color:#cbd5e1;font-size:13px;">Moderation note
            <input class="photo-note-input" type="text" name="moderation_note" placeholder="Optional internal note" disabled>
          </label>
          <div class="photo-actions">
            <?php if ($status !== 'approved'): ?><form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="approve"><button class="photo-btn approve" type="submit">Approve</button></form><?php endif; ?>
            <?php if ($status !== 'rejected'): ?><form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="reject"><button class="photo-btn reject" type="submit">Reject</button></form><?php endif; ?>
            <?php if ($status !== 'pending'): ?><form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="pending"><button class="photo-btn" type="submit">Back to Pending</button></form><?php endif; ?>
            <a class="photo-btn view" href="<?= h($displayUrl ?: $thumbUrl) ?>" target="_blank" rel="noopener">View</a>
            <?php if ($status === 'rejected'): ?><form method="post" onsubmit="return confirm('Delete this photo permanently? This removes the database record and all uploaded/generated image files. This cannot be undone.');"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="delete"><button class="photo-btn delete" type="submit">Delete Permanently</button></form><?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php admin_footer(); ?>
