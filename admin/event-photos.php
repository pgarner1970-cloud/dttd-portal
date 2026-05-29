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

function dttd_photo_safe_abs_path($relativePath) {
    $relativePath = photo_relative_to_public($relativePath);
    if ($relativePath === '') return null;
    $root = realpath(dirname(__DIR__));
    $candidate = realpath(dirname(__DIR__) . '/' . $relativePath);
    if (!$root || !$candidate || strpos($candidate, $root . DIRECTORY_SEPARATOR) !== 0) return null;
    return is_file($candidate) ? $candidate : null;
}

function dttd_photo_delete_files_for_row($photo) {
    $paths = photo_row_display_paths($photo);
    $delete = array_unique(array_filter([
        $photo['file_path'] ?? '',
        $photo['original_path'] ?? '',
        $photo['framed_path'] ?? '',
        $photo['thumb_path'] ?? '',
        $paths['display'] ?? '',
        $paths['thumb'] ?? '',
        $paths['original'] ?? '',
    ]));
    foreach ($delete as $rel) {
        $abs = dttd_photo_safe_abs_path($rel);
        if ($abs) @unlink($abs);
    }
}

$selectPieces = ['p.*'];
if (!photo_column_exists('event_photo_uploads', 'original_path')) $selectPieces[] = "'' AS original_path";
if (!photo_column_exists('event_photo_uploads', 'framed_path')) $selectPieces[] = "'' AS framed_path";
if (!photo_column_exists('event_photo_uploads', 'thumb_path')) $selectPieces[] = "'' AS thumb_path";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['photo_id']) && !empty($_POST['action'])) {
    $photoId = (int)$_POST['photo_id'];
    $action = (string)$_POST['action'];

    if ($action === 'delete') {
        $stmt = db()->prepare('SELECT ' . implode(', ', $selectPieces) . ' FROM event_photo_uploads p WHERE p.id = ? LIMIT 1');
        $stmt->execute([$photoId]);
        $photo = $stmt->fetch();
        if ($photo) {
            dttd_photo_delete_files_for_row($photo);
            $deleteStmt = db()->prepare('DELETE FROM event_photo_uploads WHERE id = ?');
            $deleteStmt->execute([$photoId]);
            $message = 'Photo permanently deleted.';
        } else {
            $error = 'Photo could not be found.';
        }
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

$countSql = 'SELECT status, COUNT(*) AS total FROM event_photo_uploads GROUP BY status';
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
foreach (db()->query($countSql)->fetchAll() as $row) {
    $key = strtolower((string)$row['status']);
    if (isset($counts[$key])) $counts[$key] = (int)$row['total'];
    $counts['all'] += (int)$row['total'];
}

$sql = 'SELECT ' . implode(', ', array_merge($selectPieces, ['e.event_name', 'e.venue_name', 'e.event_date'])) . ' FROM event_photo_uploads p INNER JOIN events e ON e.id = p.event_id WHERE 1=1';
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

function dttd_photo_status_url($status, $eventFilter) {
    $query = ['status' => $status];
    if ($eventFilter > 0) $query['event_id'] = $eventFilter;
    return 'event-photos.php?' . http_build_query($query);
}

admin_header('Photo Moderation');
?>
<style>
.photo-mod-wrap{max-width:1220px;margin:28px auto 56px;padding:0 20px;color:#fff}.photo-mod-panel{background:linear-gradient(135deg,rgba(15,34,58,.96),rgba(3,18,28,.96));border:1px solid rgba(83,145,222,.35);border-radius:24px;padding:26px;box-shadow:0 24px 70px rgba(0,0,0,.28)}.photo-mod-head{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;align-items:flex-end}.photo-mod-head h1{font-size:34px;margin:0 0 8px}.photo-mod-head p{margin:0;color:#d8e7ff}.photo-filter-form{display:grid;grid-template-columns:minmax(180px,260px) minmax(260px,520px) auto;gap:14px;align-items:end}.photo-filter-form label{display:grid;gap:7px;font-weight:800}.photo-filter-form select,.photo-note-input{min-height:46px;border:1px solid rgba(126,156,196,.45);border-radius:14px;background:#111a2a;color:#fff;padding:0 14px;font-weight:700}.photo-filter-form select{-webkit-appearance:none;-moz-appearance:none;appearance:none;padding-right:42px;background-color:#111a2a;background-image:linear-gradient(45deg,transparent 50%,#ffe85a 50%),linear-gradient(135deg,#ffe85a 50%,transparent 50%),linear-gradient(135deg,rgba(255,255,255,.06),rgba(255,255,255,0));background-position:calc(100% - 24px) 50%,calc(100% - 16px) 50%,0 0;background-size:8px 8px,8px 8px,100% 100%;background-repeat:no-repeat}.photo-filter-form select:hover,.photo-filter-form select:focus{border-color:rgba(255,232,90,.85);box-shadow:0 0 0 3px rgba(255,232,90,.12),0 0 18px rgba(44,119,232,.22);outline:none}.photo-filter-form option{background:#111a2a;color:#fff;font-weight:700}.photo-btn{border:1px solid rgba(126,156,196,.35);border-radius:14px;background:#202936;color:#fff;font-weight:900;padding:13px 17px;text-decoration:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;min-height:46px}.photo-btn-green{background:#073b25;border-color:#07c66b}.photo-btn-red{background:#350d16;border-color:#ff4b63}.photo-btn-blue{background:#072a58;border-color:#2c77e8}.photo-btn-danger{background:#48101a;border-color:#ff5c70}.photo-tabs{display:flex;gap:12px;flex-wrap:wrap;margin:22px 0 0}.photo-tab{display:inline-flex;gap:10px;align-items:center;border:1px solid #2b79e8;border-radius:999px;padding:11px 18px;color:#fff;text-decoration:none;font-weight:900;background:#08264b}.photo-tab.active{border-color:#f7cf26;background:#3b3003}.photo-count{background:#1c63c7;border-radius:999px;min-width:25px;height:25px;display:inline-flex;align-items:center;justify-content:center}.photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,280px));gap:24px;margin-top:26px}.photo-card{overflow:hidden;border:1px solid rgba(64,125,209,.46);border-radius:18px;background:#07101f;box-shadow:0 16px 38px rgba(0,0,0,.27)}.photo-thumb-link{display:block;background:#010612;border-bottom:1px solid rgba(255,255,255,.08)}.photo-thumb{display:block;width:100%;height:190px;object-fit:cover}.photo-card-body{padding:15px}.photo-card-meta{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:12px}.photo-status{border:1px solid currentColor;border-radius:999px;padding:6px 11px;font-size:12px;font-weight:1000;text-transform:uppercase}.photo-status.pending{color:#ffdd2d}.photo-status.approved{color:#13d875}.photo-status.rejected{color:#ff6473}.photo-time{color:#a7bddb;font-weight:800;font-size:13px}.photo-title{font-size:20px;font-weight:1000;margin:0 0 8px}.photo-file,.photo-event{font-size:13px;color:#dbe8ff;margin:0 0 9px;word-break:break-word}.photo-note-label{display:grid;gap:7px;font-size:13px;font-weight:900;margin:10px 0}.photo-note-input{width:100%;box-sizing:border-box}.photo-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.photo-actions form{display:inline}.photo-actions .photo-btn{padding:10px 12px;min-height:42px;font-size:13px}.photo-notice{margin-top:20px;border-radius:14px;padding:13px 15px;background:#10233b;border:1px solid rgba(126,156,196,.35)}.photo-notice.success{border-color:#12b86a}.photo-notice.error{border-color:#ff5c70}@media(max-width:820px){.photo-filter-form{grid-template-columns:1fr}.photo-grid{grid-template-columns:minmax(0,1fr)}.photo-card{max-width:360px}}
</style>
<div class="photo-mod-wrap">
  <section class="photo-mod-panel">
    <div class="photo-mod-head">
      <div>
        <h1>Photo Moderation</h1>
        <p>Review uploaded event photos and approve or reject them before they appear publicly.</p>
      </div>
      <form class="photo-filter-form" method="get">
        <label><span>Status</span><select name="status">
          <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select></label>
        <label><span>Event</span><select name="event_id">
          <option value="0">All events</option>
          <?php foreach ($eventOptions as $event): ?>
            <option value="<?= (int)$event['id'] ?>" <?= $eventFilter === (int)$event['id'] ? 'selected' : '' ?>><?= h(photo_event_label($event)) ?></option>
          <?php endforeach; ?>
        </select></label>
        <button class="photo-btn" type="submit">Filter</button>
      </form>
    </div>

    <nav class="photo-tabs" aria-label="Photo status filters">
      <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label): ?>
        <a class="photo-tab <?= $statusFilter === $value ? 'active' : '' ?>" href="<?= h(dttd_photo_status_url($value, $eventFilter)) ?>"><span><?= h($label) ?></span><span class="photo-count"><?= (int)$counts[$value] ?></span></a>
      <?php endforeach; ?>
    </nav>

    <?php if ($message): ?><div class="photo-notice success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="photo-notice error"><?= h($error) ?></div><?php endif; ?>
  </section>

  <?php if (!$photos): ?>
    <div class="photo-notice">No photos match this filter.</div>
  <?php else: ?>
    <div class="photo-grid">
      <?php foreach ($photos as $photo):
        $status = strtolower((string)($photo['status'] ?? 'pending'));
        $displayUrl = 'event-photo-image.php?id=' . (int)$photo['id'] . '&type=display';
        $thumbUrl = 'event-photo-image.php?id=' . (int)$photo['id'] . '&type=thumb';
        $timeText = !empty($photo['created_at']) ? date('H:i - j M', strtotime($photo['created_at'])) : '';
        $eventLabel = trim((string)($photo['event_name'] ?? ''));
        if ($eventLabel === '') $eventLabel = 'Event photo';
      ?>
        <article class="photo-card">
          <a class="photo-thumb-link" href="<?= h($displayUrl) ?>" target="_blank" rel="noopener" title="Open framed photo">
            <img class="photo-thumb" src="<?= h($thumbUrl) ?>" alt="<?= h($eventLabel) ?>">
          </a>
          <div class="photo-card-body">
            <div class="photo-card-meta"><span class="photo-status <?= h($status) ?>"><?= h($status) ?></span><span class="photo-time"><?= h($timeText) ?></span></div>
            <h2 class="photo-title"><?= h(trim((string)($photo['guest_name'] ?? '')) ?: 'Guest upload') ?></h2>
            <p class="photo-file"><?= h((string)($photo['original_filename'] ?? '')) ?></p>
            <p class="photo-event"><?= h($eventLabel) ?></p>
            <label class="photo-note-label">Moderation note<input class="photo-note-input" type="text" name="note" placeholder="Optional internal note" disabled></label>
            <div class="photo-actions">
              <?php if ($status !== 'approved'): ?><form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="approve"><button class="photo-btn photo-btn-green" type="submit">Approve</button></form><?php endif; ?>
              <?php if ($status !== 'rejected'): ?><form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="reject"><button class="photo-btn photo-btn-red" type="submit">Reject</button></form><?php endif; ?>
              <?php if ($status !== 'pending'): ?><form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="pending"><button class="photo-btn" type="submit">Back to Pending</button></form><?php endif; ?>
              <a class="photo-btn photo-btn-blue" href="<?= h($displayUrl) ?>" target="_blank" rel="noopener">View</a>
              <?php if ($status === 'rejected'): ?><form method="post" onsubmit="return confirm('Delete this rejected photo permanently? This removes the database record and all image files.');"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="delete"><button class="photo-btn photo-btn-danger" type="submit">Delete</button></form><?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php admin_footer(); ?>
