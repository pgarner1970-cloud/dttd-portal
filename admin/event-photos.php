<?php
require_once __DIR__ . '/../includes/photo-uploads.php';
require_once __DIR__ . '/_auth.php';

$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'pending')));
if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'all'], true)) $statusFilter = 'pending';
$eventFilter = (int)($_GET['event_id'] ?? 0);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['photo_id']) && !empty($_POST['action'])) {
    $photoId = (int)$_POST['photo_id'];
    $action = (string)$_POST['action'];

    if ($action === 'delete') {
        $stmt = db()->prepare('SELECT * FROM event_photo_uploads WHERE id = ? LIMIT 1');
        $stmt->execute([$photoId]);
        $row = $stmt->fetch();
        if ($row) {
            photo_delete_upload_files($row);
            db()->prepare('DELETE FROM event_photo_uploads WHERE id = ?')->execute([$photoId]);
            $message = 'Photo and stored image files deleted permanently.';
        }
    } else {
        $newStatus = null;
        if ($action === 'approve') $newStatus = 'approved';
        if ($action === 'reject') $newStatus = 'rejected';
        if ($action === 'pending') $newStatus = 'pending';
        if ($newStatus) {
            db()->prepare('UPDATE event_photo_uploads SET status = ? WHERE id = ?')->execute([$newStatus, $photoId]);
            $message = 'Photo status updated.';
        }
    }
}

$eventOptions = db()->query("SELECT id, event_name, venue_name, event_date FROM events ORDER BY event_date DESC, id DESC")->fetchAll();
$selectPieces = ['p.*', 'e.event_name', 'e.venue_name', 'e.event_date'];
if (!photo_column_exists('event_photo_uploads', 'original_path')) $selectPieces[] = "'' AS original_path";
if (!photo_column_exists('event_photo_uploads', 'framed_path')) $selectPieces[] = "'' AS framed_path";
if (!photo_column_exists('event_photo_uploads', 'thumb_path')) $selectPieces[] = "'' AS thumb_path";

$countSql = 'SELECT p.status, COUNT(*) AS total FROM event_photo_uploads p WHERE 1=1';
$countParams = [];
if ($eventFilter > 0) { $countSql .= ' AND p.event_id = ?'; $countParams[] = $eventFilter; }
$countSql .= ' GROUP BY p.status';
$countStmt = db()->prepare($countSql);
$countStmt->execute($countParams);
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($countStmt->fetchAll() as $row) $counts[(string)$row['status']] = (int)$row['total'];
$counts['all'] = $counts['pending'] + $counts['approved'] + $counts['rejected'];

$sql = 'SELECT ' . implode(', ', $selectPieces) . ' FROM event_photo_uploads p INNER JOIN events e ON e.id = p.event_id WHERE 1=1';
$params = [];
if ($statusFilter !== 'all') { $sql .= ' AND p.status = ?'; $params[] = $statusFilter; }
if ($eventFilter > 0) { $sql .= ' AND p.event_id = ?'; $params[] = $eventFilter; }
$sql .= ' ORDER BY FIELD(p.status, "pending","approved","rejected"), p.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$photos = $stmt->fetchAll();

admin_header('Photo Moderation');
?>
<style>
.photo-mod-shell{max-width:1180px;margin:0 auto;padding:0 18px 36px}.photo-mod-panel{background:linear-gradient(135deg,rgba(9,23,41,.96),rgba(3,13,23,.96));border:1px solid rgba(93,139,190,.28);border-radius:22px;padding:22px}.photo-mod-head{display:flex;justify-content:space-between;gap:18px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px}.photo-mod-head h1{margin:0 0 8px}.photo-mod-filters{display:grid;grid-template-columns:minmax(220px,1fr) minmax(280px,2fr) auto;gap:12px;align-items:end}.photo-mod-filters span{display:block;margin-bottom:7px;font-weight:800;color:#fff}.photo-mod-filters select{width:100%;background:#101827;color:#fff;border:1px solid rgba(136,171,214,.35);border-radius:12px;padding:10px 12px}.photo-mod-tabs{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 18px}.photo-mod-tab{display:inline-flex;align-items:center;gap:10px;border:1px solid #2f7be8;border-radius:999px;padding:10px 16px;color:#fff;text-decoration:none;font-weight:900;background:#092044}.photo-mod-tab.active{border-color:#f3c62d;background:#2f2608}.photo-mod-tab b{display:inline-grid;place-items:center;min-width:24px;height:24px;border-radius:999px;background:#1f58a7}.photo-mod-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,280px));gap:18px;align-items:start}.photo-card{overflow:hidden;background:#080f1e;border:1px solid rgba(70,132,218,.42);border-radius:22px}.photo-card.rejected{border-color:rgba(235,83,96,.55)}.photo-thumb{display:block;width:100%;height:190px;background:#030717;overflow:hidden}.photo-thumb img{width:100%;height:100%;object-fit:cover;display:block}.photo-card-body{padding:14px}.photo-status-row{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:10px}.photo-pill{border:1px solid #ffd93d;color:#ffd93d;border-radius:999px;padding:6px 10px;font-size:.78rem;font-weight:900}.photo-pill.approved{border-color:#22c55e;color:#86efac}.photo-pill.rejected{border-color:#f87171;color:#fecaca}.photo-date{color:#9fb0ca;font-weight:800;font-size:.85rem}.photo-card h3{margin:0 0 8px;font-size:1.25rem;color:#fff}.photo-file{margin:0 0 12px;color:#b7c3d8;font-size:.9rem;word-break:break-word}.photo-note{margin:0 0 6px;color:#fff;font-size:.85rem;font-weight:900}.photo-card textarea,.photo-card input[type=text]{width:100%;box-sizing:border-box;background:#111827;color:#fff;border:1px solid rgba(136,171,214,.32);border-radius:12px;padding:10px;margin-bottom:12px}.photo-actions{display:flex;gap:8px;flex-wrap:wrap}.photo-actions form{margin:0}.photo-btn{border:1px solid rgba(255,255,255,.18);border-radius:13px;background:#1f2937;color:#fff;font-weight:900;padding:11px 13px;cursor:pointer;text-decoration:none;display:inline-block}.photo-btn.approve{background:#063b25;border-color:#16a34a}.photo-btn.reject,.photo-btn.delete{background:#3b0910;border-color:#dc2626}.photo-btn.view{background:#08275b;border-color:#2f7be8}.photo-empty{border:1px dashed rgba(136,171,214,.35);border-radius:16px;padding:20px;color:#cbd5e1}@media(max-width:760px){.photo-mod-filters{grid-template-columns:1fr}.photo-mod-grid{grid-template-columns:1fr}.photo-thumb{height:220px}}
</style>
<div class="photo-mod-shell">
  <div class="photo-mod-panel">
    <div class="photo-mod-head">
      <div><h1>Photo Moderation</h1><p style="margin:0;opacity:.82;">Review uploaded event photos and approve or reject them before they appear publicly.</p></div>
      <form class="photo-mod-filters" method="get">
        <label><span>Status</span><select name="status"><?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','all'=>'All'] as $value=>$label): ?><option value="<?= h($value) ?>" <?= $statusFilter===$value?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
        <label><span>Event</span><select name="event_id"><option value="0">All events</option><?php foreach ($eventOptions as $event): ?><option value="<?= (int)$event['id'] ?>" <?= $eventFilter===(int)$event['id']?'selected':'' ?>><?= h(photo_event_label($event)) ?></option><?php endforeach; ?></select></label>
        <button class="photo-btn view" type="submit">Filter</button>
      </form>
    </div>
    <?php if ($eventFilter > 0): foreach ($eventOptions as $event): if ((int)$event['id'] === $eventFilter): ?><div style="margin-bottom:16px"><strong>Event</strong><div style="margin-top:8px;border:1px solid rgba(136,171,214,.28);border-radius:14px;padding:14px 16px;font-weight:900;color:#fff;"><?= h(photo_event_label($event)) ?></div></div><?php endif; endforeach; endif; ?>
    <nav class="photo-mod-tabs"><?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','all'=>'All'] as $key=>$label): $href='?status='.$key.($eventFilter?'&event_id='.$eventFilter:''); ?><a class="photo-mod-tab <?= $statusFilter===$key?'active':'' ?>" href="<?= h($href) ?>"><?= h($label) ?> <b><?= (int)$counts[$key] ?></b></a><?php endforeach; ?></nav>
    <?php if ($message): ?><div class="notice success" style="margin-bottom:18px;"><?= h($message) ?></div><?php endif; ?>
    <?php if (!$photos): ?><div class="photo-empty">No photos match this filter.</div><?php else: ?>
      <div class="photo-mod-grid">
        <?php foreach ($photos as $photo):
          $paths = photo_ensure_derivatives($photo);
          $thumbUrl = photo_public_url($paths['thumb']);
          $displayUrl = photo_public_url($paths['display']);
          $dateText = !empty($photo['created_at']) ? date('H:i - j M', strtotime($photo['created_at'])) : (!empty($photo['event_date']) ? date('j M', strtotime($photo['event_date'])) : '');
          $status = strtolower((string)($photo['status'] ?? 'pending'));
        ?>
          <article class="photo-card <?= h($status) ?>">
            <a class="photo-thumb" href="<?= h($displayUrl) ?>" target="_blank" rel="noopener"><img src="<?= h($thumbUrl) ?>" alt="<?= h((string)($photo['event_name'] ?? 'Event photo')) ?>" onerror="this.onerror=null;this.src='<?= h($displayUrl) ?>';"></a>
            <div class="photo-card-body">
              <div class="photo-status-row"><span class="photo-pill <?= h($status) ?>"><?= h(strtoupper($status)) ?></span><span class="photo-date"><?= h($dateText) ?></span></div>
              <h3><?= h(trim((string)($photo['guest_name'] ?? '')) ?: 'Guest upload') ?></h3>
              <p class="photo-file"><?= h((string)($photo['original_filename'] ?? basename((string)$paths['original']))) ?></p>
              <p class="photo-note">Moderation note</p>
              <input type="text" value="" placeholder="Optional internal note" disabled>
              <div class="photo-actions">
                <?php if ($status !== 'approved'): ?><form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="approve"><button class="photo-btn approve" type="submit">Approve</button></form><?php endif; ?>
                <?php if ($status !== 'rejected'): ?><form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="reject"><button class="photo-btn reject" type="submit">Reject</button></form><?php endif; ?>
                <?php if ($status !== 'pending'): ?><form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="pending"><button class="photo-btn" type="submit">Back to Pending</button></form><?php endif; ?>
                <a class="photo-btn view" href="<?= h($displayUrl) ?>" target="_blank" rel="noopener">View</a>
                <?php if ($status === 'rejected'): ?><form method="post" onsubmit="return confirm('Delete this photo permanently? This removes the database record and all stored image files.');"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="delete"><button class="photo-btn delete" type="submit">Delete</button></form><?php endif; ?>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php admin_footer(); ?>
