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

    try {
        if ($action === 'delete') {
            if (photo_delete_upload_permanently($photoId)) {
                $message = 'Photo permanently deleted from the server.';
            } else {
                $error = 'Photo could not be found, so nothing was deleted.';
            }
        } elseif ($newStatus) {
            $stmt = db()->prepare('UPDATE event_photo_uploads SET status = ? WHERE id = ?');
            $stmt->execute([$newStatus, $photoId]);
            $message = 'Photo status updated.';
        }
    } catch (Throwable $e) {
        $error = 'The photo action could not be completed.';
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
<style>
.photo-admin-page{width:min(1500px,100%);margin:0 auto;padding:20px;}
.photo-admin-header{display:flex;justify-content:space-between;align-items:flex-end;gap:18px;flex-wrap:wrap;margin-bottom:16px;}
.photo-admin-title{margin:0;font-size:30px;font-weight:900;letter-spacing:-.04em;}
.photo-admin-subtitle{margin:6px 0 0;color:#cbd5e1;}
.photo-filter-form{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;}
.photo-filter-form label{display:block;color:#bfdbfe;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.05em;}
.photo-filter-form span{display:block;margin-bottom:6px;}
.photo-filter-form select{min-height:44px;border:1px solid rgba(147,197,253,.35);border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,.11),rgba(255,255,255,.065));color:#fff;padding:0 12px;font:inherit;font-weight:800;}
.photo-filter-form option{color:#111;background:#fff;}
.photo-event-select{min-width:420px;max-width:52vw;}
.photo-card-panel{border:1px solid rgba(148,163,184,.18);border-radius:18px;background:linear-gradient(180deg,rgba(13,24,38,.88),rgba(9,19,31,.84));box-shadow:0 18px 60px rgba(0,0,0,.45);overflow:hidden;}
.photo-card-pad{padding:18px;}
.photo-notice{padding:14px 16px;border:1px solid rgba(147,197,253,.28);border-radius:14px;background:rgba(59,130,246,.12);color:#dbeafe;font-weight:800;margin-bottom:16px;}
.photo-notice.success{border-color:rgba(34,197,94,.38);background:rgba(34,197,94,.12);color:#bbf7d0;}
.photo-notice.error{border-color:rgba(239,68,68,.42);background:rgba(239,68,68,.13);color:#fecaca;}
.photo-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;}
.photo-mod-card{border:1px solid rgba(148,163,184,.18);border-radius:18px;background:rgba(255,255,255,.055);padding:14px;box-shadow:0 12px 34px rgba(0,0,0,.28);}
.photo-preview{display:block;width:100%;aspect-ratio:1/1;border:1px solid rgba(148,163,184,.16);border-radius:14px;background:rgba(2,6,23,.55);overflow:hidden;margin-bottom:12px;}
.photo-preview img{width:100%;height:100%;display:block;object-fit:cover;}
.photo-preview-missing{height:100%;display:grid;place-items:center;text-align:center;color:#94a3b8;font-weight:900;padding:18px;}
.photo-mod-card h3{margin:0 0 8px;font-size:18px;line-height:1.15;}
.photo-meta{margin:0 0 6px;color:#cbd5e1;}
.photo-meta strong{color:#fff;}
.photo-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;}
.photo-actions form{margin:0;}
.photo-btn{min-height:40px;display:inline-flex;align-items:center;justify-content:center;border:1px solid rgba(148,163,184,.22);border-radius:12px;background:rgba(255,255,255,.08);color:#fff;padding:8px 12px;font:inherit;font-weight:900;cursor:pointer;text-decoration:none;}
.photo-btn.approve{border-color:rgba(34,197,94,.48);background:rgba(34,197,94,.16);}
.photo-btn.reject{border-color:rgba(245,158,11,.52);background:rgba(245,158,11,.15);}
.photo-btn.delete{border-color:rgba(239,68,68,.58);background:rgba(239,68,68,.18);}
@media (max-width:760px){.photo-event-select{min-width:0;max-width:none;width:100%;}.photo-filter-form,.photo-filter-form label{width:100%;}.photo-admin-title{font-size:24px;}}
</style>
<main class="photo-admin-page">
  <div class="photo-admin-header">
    <div>
      <h1 class="photo-admin-title">Photo Moderation</h1>
      <p class="photo-admin-subtitle">Review uploaded event photos and approve, reject or permanently delete them before they appear publicly.</p>
    </div>
    <form class="photo-filter-form" method="get">
      <label>
        <span>Status</span>
        <select name="status">
          <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <span>Event</span>
        <select class="photo-event-select" name="event_id">
          <option value="0">All events</option>
          <?php foreach ($eventOptions as $event): ?>
            <option value="<?= (int)$event['id'] ?>" <?= $eventFilter === (int)$event['id'] ? 'selected' : '' ?>><?= h(photo_event_label($event)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="photo-btn" type="submit">Filter</button>
    </form>
  </div>

  <section class="photo-card-panel">
    <div class="photo-card-pad">
      <?php if ($message): ?>
        <div class="photo-notice success"><?= h($message) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="photo-notice error"><?= h($error) ?></div>
      <?php endif; ?>

      <?php if (!$photos): ?>
        <div class="photo-notice">No photos match this filter.</div>
      <?php else: ?>
        <div class="photo-grid">
          <?php foreach ($photos as $photo):
            $paths = photo_row_display_paths($photo);
            $thumbUrl = photo_public_url($paths['thumb']);
            $displayUrl = photo_public_url($paths['display']);
            $dateText = !empty($photo['event_date']) ? date('D j M Y', strtotime($photo['event_date'])) : '';
          ?>
            <article class="photo-mod-card">
              <?php if ($thumbUrl): ?>
                <a class="photo-preview" href="<?= h($displayUrl ?: $thumbUrl) ?>" target="_blank" rel="noopener"><img src="<?= h($thumbUrl) ?>" alt=""></a>
              <?php else: ?>
                <div class="photo-preview"><div class="photo-preview-missing">No image path saved</div></div>
              <?php endif; ?>
              <h3><?= h($photo['event_name'] ?? 'Event photo') ?></h3>
              <p class="photo-meta"><?= h(trim(($photo['venue_name'] ?? '') . ($dateText ? ' · ' . $dateText : ''))) ?></p>
              <p class="photo-meta">Status: <strong><?= h(ucfirst((string)$photo['status'])) ?></strong></p>
              <?php if (!empty($photo['guest_name'])): ?><p class="photo-meta">Shared by <?= h($photo['guest_name']) ?></p><?php endif; ?>
              <div class="photo-actions">
                <?php if (($photo['status'] ?? '') !== 'approved'): ?>
                  <form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="approve"><button class="photo-btn approve" type="submit">Approve</button></form>
                <?php endif; ?>
                <?php if (($photo['status'] ?? '') !== 'rejected'): ?>
                  <form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="reject"><button class="photo-btn reject" type="submit">Reject</button></form>
                <?php endif; ?>
                <?php if (($photo['status'] ?? '') !== 'pending'): ?>
                  <form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="pending"><button class="photo-btn" type="submit">Back to Pending</button></form>
                <?php endif; ?>
                <?php if (($photo['status'] ?? '') === 'rejected'): ?>
                  <form method="post" onsubmit="return confirm('Delete this photo permanently? This removes the database row and all saved image copies from the server. This cannot be undone.');"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="delete"><button class="photo-btn delete" type="submit">Delete Permanently</button></form>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
