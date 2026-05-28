<?php
require_once __DIR__ . '/../includes/photo-uploads.php';
require_once __DIR__ . '/_auth.php';

$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'pending')));
if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $statusFilter = 'pending';
}
$eventFilter = (int)($_GET['event_id'] ?? 0);
$message = '';

function dttd_photo_admin_columns() {
    $cols = ['p.*', 'e.event_name', 'e.venue_name', 'e.event_date'];
    foreach (['original_path', 'framed_path', 'thumb_path', 'moderation_note', 'created_at', 'updated_at'] as $column) {
        if (!photo_column_exists('event_photo_uploads', $column)) {
            $cols[] = "'' AS {$column}";
        }
    }
    return $cols;
}

function dttd_photo_admin_data_uri($row) {
    $paths = photo_row_display_paths($row);
    foreach (['thumb', 'display', 'original'] as $variant) {
        $rel = $paths[$variant] ?? '';
        $abs = photo_absolute_path($rel);
        if ($abs !== '' && is_file($abs) && filesize($abs) > 0) {
            $info = @getimagesize($abs);
            $mime = $info['mime'] ?? 'image/jpeg';
            $data = @file_get_contents($abs);
            if ($data !== false) {
                return 'data:' . $mime . ';base64,' . base64_encode($data);
            }
        }
    }
    return '';
}

function dttd_photo_admin_delete_files($row) {
    $paths = [];
    foreach (['thumb_path', 'framed_path', 'original_path', 'file_path'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') {
            $paths[$value] = true;
        }
    }
    foreach (array_keys($paths) as $rel) {
        $abs = photo_absolute_path($rel);
        if ($abs !== '' && is_file($abs)) {
            @unlink($abs);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['photo_id']) && !empty($_POST['action'])) {
    $photoId = (int)$_POST['photo_id'];
    $action = (string)$_POST['action'];

    $selectPieces = dttd_photo_admin_columns();
    $stmt = db()->prepare('SELECT ' . implode(', ', $selectPieces) . ' FROM event_photo_uploads p INNER JOIN events e ON e.id = p.event_id WHERE p.id = ? LIMIT 1');
    $stmt->execute([$photoId]);
    $photoRow = $stmt->fetch();

    if ($photoRow) {
        if ($action === 'delete' && (($photoRow['status'] ?? '') === 'rejected')) {
            dttd_photo_admin_delete_files($photoRow);
            $stmt = db()->prepare('DELETE FROM event_photo_uploads WHERE id = ?');
            $stmt->execute([$photoId]);
            $message = 'Rejected photo permanently deleted.';
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
}

$eventOptions = db()->query("SELECT id, event_name, venue_name, event_date FROM events ORDER BY event_date DESC, id DESC")->fetchAll();

$baseSql = 'FROM event_photo_uploads p INNER JOIN events e ON e.id = p.event_id WHERE 1=1';
$baseParams = [];
if ($eventFilter > 0) {
    $baseSql .= ' AND p.event_id = ?';
    $baseParams[] = $eventFilter;
}

$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
try {
    $stmt = db()->prepare('SELECT p.status, COUNT(*) AS total ' . $baseSql . ' GROUP BY p.status');
    $stmt->execute($baseParams);
    foreach ($stmt->fetchAll() as $row) {
        $key = strtolower((string)$row['status']);
        if (isset($counts[$key])) {
            $counts[$key] = (int)$row['total'];
            $counts['all'] += (int)$row['total'];
        }
    }
} catch (Throwable $e) {}

$sql = 'SELECT ' . implode(', ', dttd_photo_admin_columns()) . ' ' . $baseSql;
$params = $baseParams;
if ($statusFilter !== 'all') {
    $sql .= ' AND p.status = ?';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY FIELD(p.status, "pending","approved","rejected"), p.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$photos = $stmt->fetchAll();

admin_header('Photo Moderation');
?>
<style>
.photo-moderation-wrap{max-width:1240px;margin:0 auto;padding:24px 18px 42px;}
.photo-filter-card{background:rgba(9,20,35,.92);border:1px solid rgba(86,153,255,.24);border-radius:24px;padding:26px;box-shadow:0 18px 45px rgba(0,0,0,.24);margin-bottom:22px;}
.photo-filter-card h1{margin:0 0 8px;font-size:34px;line-height:1.05;}
.photo-filter-card p{margin:0;color:#cbd8ec;}
.photo-filter-grid{display:grid;grid-template-columns:minmax(220px,280px) minmax(260px,1fr) auto;gap:12px;align-items:end;margin-top:22px;}
.photo-filter-grid label span{display:block;margin-bottom:8px;font-weight:800;color:#fff;}
.photo-filter-grid select{width:100%;min-height:46px;border-radius:14px;border:1px solid rgba(162,185,219,.32);background:#111827;color:#fff;padding:0 14px;font-weight:700;}
.photo-tabs{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;}
.photo-tab{display:inline-flex;align-items:center;gap:10px;text-decoration:none;border:1px solid #1f6ff2;background:#06295a;color:#fff;border-radius:999px;padding:10px 16px;font-weight:900;}
.photo-tab.is-active{border-color:#ffd426;background:#4b3b02;}
.photo-tab-count{display:inline-grid;place-items:center;min-width:26px;height:26px;border-radius:999px;background:#1d65df;color:#fff;padding:0 7px;}
.photo-tab.is-active .photo-tab-count{background:#b47702;}
.photo-card-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,280px));gap:22px;align-items:start;}
.photo-card{overflow:hidden;border-radius:20px;border:1px solid rgba(72,132,223,.48);background:#07111f;box-shadow:0 15px 40px rgba(0,0,0,.28);}
.photo-card-image{display:block;width:100%;height:190px;background:#030817;overflow:hidden;border-bottom:1px solid rgba(255,255,255,.07);}
.photo-card-image img{width:100%;height:100%;display:block;object-fit:cover;}
.photo-card-missing{height:100%;display:grid;place-items:center;color:#8ea3c0;font-size:13px;text-align:center;padding:18px;}
.photo-card-body{padding:16px;}
.photo-card-status-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;}
.photo-pill{display:inline-flex;align-items:center;border-radius:999px;padding:7px 11px;font-size:12px;font-weight:900;text-transform:uppercase;border:1px solid currentColor;}
.photo-pill.pending{color:#ffe35a;background:rgba(255,211,0,.08)}
.photo-pill.approved{color:#44f58e;background:rgba(68,245,142,.08)}
.photo-pill.rejected{color:#ff7777;background:rgba(255,87,87,.08)}
.photo-card-time{color:#aab9d1;font-size:12px;font-weight:800;white-space:nowrap;}
.photo-card-title{font-size:20px;margin:0 0 6px;line-height:1.15;}
.photo-card-file{font-size:13px;color:#cbd8ec;margin:0 0 12px;word-break:break-word;}
.photo-note-label{font-size:13px;font-weight:900;color:#dfe9fb;margin:0 0 6px;}
.photo-note-input{width:100%;min-height:44px;border-radius:14px;border:1px solid rgba(162,185,219,.32);background:#101827;color:#fff;padding:0 12px;font-weight:700;margin-bottom:12px;box-sizing:border-box;}
.photo-actions{display:flex;gap:8px;flex-wrap:wrap;}
.photo-actions form{margin:0;}
.photo-btn{border:1px solid #2679ff;background:#063071;color:#fff;border-radius:13px;padding:11px 13px;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;min-height:42px;}
.photo-btn.green{border-color:#16c269;background:#043d22;}
.photo-btn.red{border-color:#ff4d64;background:#3d0811;}
.photo-btn.grey{border-color:#64748b;background:#1f2937;}
.photo-btn.gold{border-color:#ffd426;background:#4b3b02;}
.photo-notice{padding:14px 16px;border:1px solid rgba(86,153,255,.3);border-radius:16px;background:rgba(8,25,48,.85);margin-bottom:18px;color:#dce8fb;}
.photo-notice.success{border-color:rgba(60,220,130,.42);background:rgba(8,72,38,.5);}
@media(max-width:760px){.photo-filter-grid{grid-template-columns:1fr}.photo-card-grid{grid-template-columns:minmax(0,1fr)}.photo-card-image{height:210px}}
</style>
<div class="photo-moderation-wrap">
  <section class="photo-filter-card">
    <h1>Photo Moderation</h1>
    <p>Review uploaded event photos and approve or reject them before they appear publicly.</p>

    <form method="get" class="photo-filter-grid">
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
        <select name="event_id">
          <option value="0">All events</option>
          <?php foreach ($eventOptions as $event): ?>
            <option value="<?= (int)$event['id'] ?>" <?= $eventFilter === (int)$event['id'] ? 'selected' : '' ?>><?= h(photo_event_label($event)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="photo-btn" type="submit">Filter</button>
    </form>

    <nav class="photo-tabs" aria-label="Photo status filters">
      <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label):
        $url = 'event-photos.php?status=' . urlencode($value) . ($eventFilter > 0 ? '&event_id=' . (int)$eventFilter : '');
      ?>
        <a class="photo-tab <?= $statusFilter === $value ? 'is-active' : '' ?>" href="<?= h($url) ?>">
          <span><?= h($label) ?></span><span class="photo-tab-count"><?= (int)$counts[$value] ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
  </section>

  <?php if ($message): ?>
    <div class="photo-notice success"><?= h($message) ?></div>
  <?php endif; ?>

  <?php if (!$photos): ?>
    <div class="photo-notice">No photos match this filter.</div>
  <?php else: ?>
    <section class="photo-card-grid">
      <?php foreach ($photos as $photo):
        $status = strtolower((string)($photo['status'] ?? 'pending'));
        $imageSrc = dttd_photo_admin_data_uri($photo);
        $displayUrl = 'event-photo-image.php?id=' . (int)$photo['id'] . '&variant=display&v=' . urlencode((string)($photo['updated_at'] ?? $photo['created_at'] ?? time()));
        $timeText = '';
        if (!empty($photo['created_at'])) {
            try { $timeText = (new DateTime($photo['created_at']))->format('H:i - j M'); } catch (Throwable $e) {}
        }
        $eventName = trim((string)($photo['event_name'] ?? 'Event photo'));
        $guestName = trim((string)($photo['guest_name'] ?? '')) ?: 'Guest upload';
        $filename = trim((string)($photo['original_filename'] ?? basename((string)($photo['file_path'] ?? ''))));
      ?>
        <article class="photo-card">
          <a class="photo-card-image" href="<?= h($displayUrl) ?>" target="_blank" rel="noopener" title="<?= h($eventName) ?>">
            <?php if ($imageSrc !== ''): ?>
              <img src="<?= h($imageSrc) ?>" alt="<?= h($eventName) ?>">
            <?php else: ?>
              <span class="photo-card-missing">Image file could not be read</span>
            <?php endif; ?>
          </a>
          <div class="photo-card-body">
            <div class="photo-card-status-row">
              <span class="photo-pill <?= h($status) ?>"><?= h($status) ?></span>
              <?php if ($timeText): ?><span class="photo-card-time"><?= h($timeText) ?></span><?php endif; ?>
            </div>
            <h2 class="photo-card-title"><?= h($guestName) ?></h2>
            <?php if ($filename !== ''): ?><p class="photo-card-file"><?= h($filename) ?></p><?php endif; ?>
            <p class="photo-note-label">Moderation note</p>
            <input class="photo-note-input" type="text" value="<?= h((string)($photo['moderation_note'] ?? '')) ?>" placeholder="Optional internal note" disabled>
            <div class="photo-actions">
              <?php if ($status !== 'approved'): ?>
                <form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="approve"><button class="photo-btn green" type="submit">Approve</button></form>
              <?php endif; ?>
              <?php if ($status !== 'rejected'): ?>
                <form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="reject"><button class="photo-btn red" type="submit">Reject</button></form>
              <?php endif; ?>
              <?php if ($status !== 'pending'): ?>
                <form method="post"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="pending"><button class="photo-btn grey" type="submit">Back to Pending</button></form>
              <?php endif; ?>
              <?php if ($status === 'rejected'): ?>
                <form method="post" onsubmit="return confirm('Delete this rejected photo permanently, including original, framed and thumbnail files? This cannot be undone.');"><input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>"><input type="hidden" name="action" value="delete"><button class="photo-btn red" type="submit">Delete Permanently</button></form>
              <?php endif; ?>
              <a class="photo-btn" href="<?= h($displayUrl) ?>" target="_blank" rel="noopener">View</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</div>
<?php admin_footer(); ?>
