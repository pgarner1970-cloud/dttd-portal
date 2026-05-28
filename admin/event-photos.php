<?php
require_once __DIR__ . '/../includes/photo-uploads.php';
require_once __DIR__ . '/_auth.php';

function photo_admin_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function photo_admin_abs_path($path) {
    $path = ltrim(str_replace('\\', '/', (string)$path), '/');
    if ($path === '' || strpos($path, '..') !== false) {
        return '';
    }
    return dirname(__DIR__) . '/' . $path;
}

function photo_admin_file_exists($path) {
    $abs = photo_admin_abs_path($path);
    return $abs !== '' && is_file($abs) && filesize($abs) > 0;
}

function photo_admin_data_uri($path) {
    $abs = photo_admin_abs_path($path);
    if ($abs === '' || !is_file($abs) || filesize($abs) <= 0) {
        return '';
    }
    $mime = 'image/jpeg';
    if (function_exists('mime_content_type')) {
        $detected = @mime_content_type($abs);
        if ($detected && strpos($detected, 'image/') === 0) {
            $mime = $detected;
        }
    }
    $data = @file_get_contents($abs);
    if ($data === false) {
        return '';
    }
    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

function photo_admin_display_paths($photo) {
    $paths = photo_row_display_paths($photo);
    $candidates = [
        'thumb' => [$paths['thumb'] ?? '', $paths['display'] ?? '', $photo['framed_path'] ?? '', $photo['file_path'] ?? '', $photo['original_path'] ?? ''],
        'display' => [$paths['display'] ?? '', $photo['framed_path'] ?? '', $photo['file_path'] ?? '', $photo['original_path'] ?? '', $paths['thumb'] ?? ''],
    ];

    $out = ['thumb' => '', 'display' => ''];
    foreach ($candidates as $key => $list) {
        foreach ($list as $candidate) {
            $candidate = ltrim(str_replace('\\', '/', (string)$candidate), '/');
            if ($candidate !== '' && photo_admin_file_exists($candidate)) {
                $out[$key] = $candidate;
                break;
            }
        }
    }
    return $out;
}

function photo_admin_delete_files_for_row($photo) {
    $paths = [];
    foreach (['file_path', 'original_path', 'framed_path', 'thumb_path'] as $column) {
        if (!empty($photo[$column])) {
            $paths[] = $photo[$column];
        }
    }
    $derived = photo_row_display_paths($photo);
    foreach (['display', 'thumb'] as $key) {
        if (!empty($derived[$key])) {
            $paths[] = $derived[$key];
        }
    }
    foreach (array_unique($paths) as $path) {
        $abs = photo_admin_abs_path($path);
        if ($abs && is_file($abs)) {
            @unlink($abs);
        }
    }
}

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
    $note = trim((string)($_POST['moderation_note'] ?? ''));

    $stmt = db()->prepare('SELECT * FROM event_photo_uploads WHERE id = ? LIMIT 1');
    $stmt->execute([$photoId]);
    $photoForAction = $stmt->fetch();

    if ($photoForAction) {
        if ($action === 'delete' && ($photoForAction['status'] ?? '') === 'rejected') {
            photo_admin_delete_files_for_row($photoForAction);
            $stmt = db()->prepare('DELETE FROM event_photo_uploads WHERE id = ?');
            $stmt->execute([$photoId]);
            $message = 'Rejected photo permanently deleted.';
        } else {
            $newStatus = null;
            if ($action === 'approve') $newStatus = 'approved';
            if ($action === 'reject') $newStatus = 'rejected';
            if ($action === 'pending') $newStatus = 'pending';

            if ($newStatus) {
                if (photo_column_exists('event_photo_uploads', 'moderation_note')) {
                    $stmt = db()->prepare('UPDATE event_photo_uploads SET status = ?, moderation_note = ? WHERE id = ?');
                    $stmt->execute([$newStatus, $note, $photoId]);
                } else {
                    $stmt = db()->prepare('UPDATE event_photo_uploads SET status = ? WHERE id = ?');
                    $stmt->execute([$newStatus, $photoId]);
                }
                $message = 'Photo status updated.';
            }
        }
    } else {
        $error = 'Photo not found.';
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
if (!photo_column_exists('event_photo_uploads', 'moderation_note')) $selectPieces[] = "'' AS moderation_note";

$where = ' FROM event_photo_uploads p INNER JOIN events e ON e.id = p.event_id WHERE 1=1';
$params = [];
if ($statusFilter !== 'all') {
    $where .= ' AND p.status = ?';
    $params[] = $statusFilter;
}
if ($eventFilter > 0) {
    $where .= ' AND p.event_id = ?';
    $params[] = $eventFilter;
}

$sql = 'SELECT ' . implode(', ', $selectPieces) . $where . ' ORDER BY FIELD(p.status, "pending","approved","rejected"), p.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$photos = $stmt->fetchAll();

$countWhere = ' FROM event_photo_uploads p WHERE 1=1';
$countParams = [];
if ($eventFilter > 0) {
    $countWhere .= ' AND p.event_id = ?';
    $countParams[] = $eventFilter;
}
$countStmt = db()->prepare('SELECT status, COUNT(*) AS total' . $countWhere . ' GROUP BY status');
$countStmt->execute($countParams);
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
foreach ($countStmt->fetchAll() as $row) {
    $status = strtolower((string)$row['status']);
    if (isset($counts[$status])) {
        $counts[$status] = (int)$row['total'];
        $counts['all'] += (int)$row['total'];
    }
}

admin_header('Photo Moderation');
?>
<div class="container photo-moderation-wrap">
  <section class="card photo-moderation-hero">
    <div style="padding:24px 24px 8px;">
      <h1 style="margin:0 0 8px;">Photo Moderation</h1>
      <p style="margin:0; opacity:.86;">Review uploaded event photos and approve or reject them before they appear publicly.</p>
    </div>

    <form class="photo-moderation-controls" method="get">
      <label>
        <span>Status</span>
        <select name="status">
          <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label): ?>
            <option value="<?= photo_admin_h($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= photo_admin_h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <span>Event</span>
        <select name="event_id">
          <option value="0">All events</option>
          <?php foreach ($eventOptions as $event): ?>
            <option value="<?= (int)$event['id'] ?>" <?= $eventFilter === (int)$event['id'] ? 'selected' : '' ?>><?= photo_admin_h(photo_event_label($event)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="touch-btn touch-btn-primary" type="submit">Filter</button>
    </form>

    <div class="photo-filter-pills" style="padding:0 24px 24px;">
      <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label):
        $url = '?status=' . urlencode($value) . ($eventFilter > 0 ? '&event_id=' . (int)$eventFilter : '');
      ?>
        <a class="photo-filter-pill <?= $statusFilter === $value ? 'active' : '' ?>" href="<?= photo_admin_h($url) ?>"><?= photo_admin_h($label) ?> <span><?= (int)$counts[$value] ?></span></a>
      <?php endforeach; ?>
    </div>
  </section>

  <?php if ($message): ?>
    <div class="notice success"><?= photo_admin_h($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="notice error"><?= photo_admin_h($error) ?></div>
  <?php endif; ?>

  <?php if (!$photos): ?>
    <section class="card photo-empty-state">
      <h2>No photos match this filter.</h2>
      <p>Try a different status or event filter.</p>
    </section>
  <?php else: ?>
    <section class="photo-moderation-grid">
      <?php foreach ($photos as $photo):
        $paths = photo_admin_display_paths($photo);
        $thumbSrc = photo_admin_data_uri($paths['thumb']);
        $displayHref = $paths['display'] ? photo_public_url($paths['display']) : ($paths['thumb'] ? photo_public_url($paths['thumb']) : '#');
        $status = strtolower((string)($photo['status'] ?? 'pending'));
        $created = '';
        if (!empty($photo['created_at'])) {
            try { $created = (new DateTime($photo['created_at']))->format('H:i - j M'); } catch (Throwable $e) { $created = (string)$photo['created_at']; }
        }
        $title = trim((string)($photo['guest_name'] ?? '')) ?: 'Guest upload';
        $filename = trim((string)($photo['original_filename'] ?? '')) ?: basename((string)($paths['display'] ?: $paths['thumb']));
        $noteValue = (string)($photo['moderation_note'] ?? '');
      ?>
        <article class="photo-review-card status-<?= photo_admin_h($status) ?>">
          <a class="photo-review-image-link" href="<?= photo_admin_h($displayHref) ?>" target="_blank" rel="noopener">
            <?php if ($thumbSrc): ?>
              <img class="photo-review-image" src="<?= photo_admin_h($thumbSrc) ?>" alt="<?= photo_admin_h((string)($photo['event_name'] ?? 'Event photo')) ?>">
            <?php else: ?>
              <div class="photo-review-image" style="display:grid;place-items:center;color:#94a3b8;padding:18px;text-align:center;">Image file missing</div>
            <?php endif; ?>
          </a>
          <div class="photo-review-body">
            <div class="photo-review-topline">
              <span class="photo-status-badge status-<?= photo_admin_h($status) ?>"><?= photo_admin_h($status) ?></span>
              <small><?= photo_admin_h($created) ?></small>
            </div>
            <h2><?= photo_admin_h($title) ?></h2>
            <p class="photo-filename"><?= photo_admin_h($filename) ?></p>
            <?php if (!empty($photo['event_name'])): ?>
              <p class="photo-note"><?= photo_admin_h($photo['event_name']) ?></p>
            <?php endif; ?>
            <div class="photo-review-actions">
              <form method="post" class="photo-note-field">
                <input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>">
                <label>Moderation note
                  <input type="text" name="moderation_note" value="<?= photo_admin_h($noteValue) ?>" placeholder="Optional internal note">
                </label>
                <div class="photo-action-row">
                  <?php if ($status !== 'approved'): ?>
                    <button class="touch-btn touch-btn-success" name="action" value="approve" type="submit">Approve</button>
                  <?php endif; ?>
                  <?php if ($status !== 'rejected'): ?>
                    <button class="touch-btn touch-btn-danger" name="action" value="reject" type="submit">Reject</button>
                  <?php endif; ?>
                  <?php if ($status !== 'pending'): ?>
                    <button class="touch-btn" name="action" value="pending" type="submit">Back to Pending</button>
                  <?php endif; ?>
                  <a class="touch-btn" href="<?= photo_admin_h($displayHref) ?>" target="_blank" rel="noopener">View</a>
                  <?php if ($status === 'rejected'): ?>
                    <button class="touch-btn touch-btn-danger" name="action" value="delete" type="submit" onclick="return confirm('Delete this rejected photo permanently, including original, framed and thumbnail files? This cannot be undone.');">Delete Permanently</button>
                  <?php endif; ?>
                </div>
              </form>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</div>
<?php admin_footer(); ?>
