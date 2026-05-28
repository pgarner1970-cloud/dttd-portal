<?php
require_once __DIR__ . '/_auth.php';

function dttd_photos_table_ready() {
    return function_exists('dttd_table_exists')
        && dttd_table_exists('event_photo_uploads')
        && dttd_table_column_exists('event_photo_uploads', 'id')
        && dttd_table_column_exists('event_photo_uploads', 'event_id')
        && dttd_table_column_exists('event_photo_uploads', 'file_path');
}

function dttd_photo_public_url($path) {
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path)) return $path;
    return 'https://dancethruthedecades.co.uk/' . ltrim($path, '/');
}

function dttd_photo_statuses() {
    return ['pending', 'approved', 'rejected', 'all'];
}

function dttd_photo_status_label($status) {
    return [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'all' => 'All',
    ][$status] ?? 'Pending';
}

function dttd_photo_event_options() {
    try {
        return db()->query("\n            SELECT id, event_name, venue_name, event_date, start_time\n            FROM events\n            ORDER BY event_date DESC, start_time DESC, id DESC\n            LIMIT 80\n        ")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_photo_selected_event($events) {
    $requested = isset($_GET['event_id']) ? (int)$_GET['event_id'] : (isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0);
    if ($requested > 0) {
        foreach ($events as $event) {
            if ((int)$event['id'] === $requested) return $event;
        }
    }

    $current = function_exists('dttd_get_calculated_current_event') ? dttd_get_calculated_current_event() : null;
    if ($current) return $current;

    return $events[0] ?? null;
}

function dttd_photo_status_counts($event_id) {
    $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
    if (!dttd_photos_table_ready()) return $counts;

    try {
        $stmt = db()->prepare("SELECT COALESCE(status, 'pending') AS photo_status, COUNT(*) AS total FROM event_photo_uploads WHERE event_id = ? GROUP BY COALESCE(status, 'pending')");
        $stmt->execute([(int)$event_id]);
        foreach ($stmt->fetchAll() as $row) {
            $status = (string)$row['photo_status'];
            if (!isset($counts[$status])) $counts[$status] = 0;
            $counts[$status] = (int)$row['total'];
            $counts['all'] += (int)$row['total'];
        }
    } catch (Throwable $e) {
        // Leave zeros.
    }

    return $counts;
}

function dttd_photo_fetch($event_id, $status) {
    if (!dttd_photos_table_ready()) return [];

    $where = 'WHERE p.event_id = ?';
    $params = [(int)$event_id];
    if ($status !== 'all') {
        $where .= ' AND COALESCE(p.status, \'pending\') = ?';
        $params[] = $status;
    }

    $uploadedSort = dttd_table_column_exists('event_photo_uploads', 'uploaded_at') ? 'p.uploaded_at' : (dttd_table_column_exists('event_photo_uploads', 'created_at') ? 'p.created_at' : 'p.id');

    try {
        $stmt = db()->prepare("\n            SELECT p.*, e.event_name, e.venue_name\n            FROM event_photo_uploads p\n            LEFT JOIN events e ON e.id = p.event_id\n            $where\n            ORDER BY $uploadedSort DESC, p.id DESC\n            LIMIT 200\n        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

$events = dttd_photo_event_options();
$event = dttd_photo_selected_event($events);
$status = strtolower((string)($_GET['status'] ?? $_POST['status'] ?? 'pending'));
if (!in_array($status, dttd_photo_statuses(), true)) $status = 'pending';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $photoId = (int)($_POST['photo_id'] ?? 0);
    $eventId = (int)($_POST['event_id'] ?? 0);
    $note = trim((string)($_POST['moderation_note'] ?? ''));

    if (!dttd_photos_table_ready()) {
        $error = 'Photo moderation table is not available yet.';
    } elseif ($photoId <= 0) {
        $error = 'Photo not found.';
    } elseif (!in_array($action, ['approve', 'reject', 'pending'], true)) {
        $error = 'Unknown photo action.';
    } else {
        try {
            $newStatus = $action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : 'pending');
            $sets = ['status = ?'];
            $params = [$newStatus];

            if (dttd_table_column_exists('event_photo_uploads', 'moderation_note')) {
                $sets[] = 'moderation_note = ?';
                $params[] = $note;
            }

            if (dttd_table_column_exists('event_photo_uploads', 'approved_at')) {
                if ($newStatus === 'approved') {
                    $sets[] = 'approved_at = NOW()';
                } else {
                    $sets[] = 'approved_at = NULL';
                }
            }

            $params[] = $photoId;
            $params[] = $eventId;

            $stmt = db()->prepare('UPDATE event_photo_uploads SET ' . implode(', ', $sets) . ' WHERE id = ? AND event_id = ?');
            $stmt->execute($params);

            $success = $newStatus === 'approved'
                ? 'Photo approved. It can now appear on the public event gallery/carousel.'
                : ($newStatus === 'rejected' ? 'Photo rejected and hidden from the public gallery.' : 'Photo returned to pending review.');
        } catch (Throwable $e) {
            $error = 'Could not update the photo status.';
        }
    }

    // Refresh selected event after post.
    if ($eventId > 0) {
        foreach ($events as $option) {
            if ((int)$option['id'] === $eventId) {
                $event = $option;
                break;
            }
        }
    }
}

$eventId = $event ? (int)$event['id'] : 0;
$counts = $eventId ? dttd_photo_status_counts($eventId) : ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
$photos = $eventId ? dttd_photo_fetch($eventId, $status) : [];

admin_header('Photo Moderation - DJ Portal');
?>
<main class="touch-wrap photo-moderation-wrap">
  <section class="touch-panel photo-moderation-hero">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Photo Moderation</h1>
        <p class="touch-subtitle">Approve guest uploads before they appear on the public event gallery and carousel.</p>
      </div>
      <div class="photo-moderation-summary">
        <strong><?= (int)($counts['pending'] ?? 0) ?></strong>
        <span>waiting</span>
      </div>
    </div>

    <?php if ($success): ?><div class="notice success"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

    <?php if (!dttd_photos_table_ready()): ?>
      <div class="notice error">The <code>event_photo_uploads</code> table is missing or incomplete. Run the photo upload SQL before using this page.</div>
    <?php endif; ?>

    <form class="photo-moderation-controls" method="get">
      <label>
        <span>Event</span>
        <select name="event_id" onchange="this.form.submit()">
          <?php foreach ($events as $option): ?>
            <option value="<?= (int)$option['id'] ?>" <?= $eventId === (int)$option['id'] ? 'selected' : '' ?>>
              <?= h(($option['event_date'] ? date('d M Y', strtotime($option['event_date'])) . ' — ' : '') . $option['event_name'] . (!empty($option['venue_name']) ? ' @ ' . $option['venue_name'] : '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        <span>Status</span>
        <select name="status" onchange="this.form.submit()">
          <?php foreach (dttd_photo_statuses() as $option): ?>
            <option value="<?= h($option) ?>" <?= $status === $option ? 'selected' : '' ?>>
              <?= h(dttd_photo_status_label($option)) ?> (<?= (int)($counts[$option] ?? 0) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <button class="touch-btn blue" type="submit">Refresh</button>
    </form>
  </section>

  <?php if (!$event): ?>
    <section class="touch-panel"><div class="touch-panel-pad"><p>No events found.</p></div></section>
  <?php else: ?>
    <section class="photo-filter-pills" aria-label="Photo status filters">
      <?php foreach (dttd_photo_statuses() as $option): ?>
        <a class="photo-filter-pill <?= $status === $option ? 'active' : '' ?>" href="event-photos.php?event_id=<?= (int)$eventId ?>&status=<?= h($option) ?>">
          <?= h(dttd_photo_status_label($option)) ?> <span><?= (int)($counts[$option] ?? 0) ?></span>
        </a>
      <?php endforeach; ?>
    </section>

    <?php if (!$photos): ?>
      <section class="touch-panel"><div class="touch-panel-pad photo-empty-state">
        <h2>No <?= h(dttd_photo_status_label($status)) ?> photos</h2>
        <p>Guest uploads will appear here once they are submitted for this event.</p>
      </div></section>
    <?php else: ?>
      <section class="photo-moderation-grid">
        <?php foreach ($photos as $photo): ?>
          <?php
            $photoStatus = (string)($photo['status'] ?? 'pending');
            $photoUrl = dttd_photo_public_url($photo['file_path'] ?? '');
            $uploadedAt = $photo['uploaded_at'] ?? ($photo['created_at'] ?? '');
          ?>
          <article class="photo-review-card status-<?= h($photoStatus) ?>">
            <a class="photo-review-image-link" href="<?= h($photoUrl) ?>" target="_blank" rel="noopener">
              <img class="photo-review-image" src="<?= h($photoUrl) ?>" alt="Uploaded event photo">
            </a>
            <div class="photo-review-body">
              <div class="photo-review-topline">
                <span class="photo-status-badge status-<?= h($photoStatus) ?>"><?= h(dttd_photo_status_label($photoStatus)) ?></span>
                <?php if ($uploadedAt): ?><small><?= h(date('H:i · d M', strtotime($uploadedAt))) ?></small><?php endif; ?>
              </div>

              <h2><?= h($photo['guest_name'] ?: 'Guest upload') ?></h2>
              <?php if (!empty($photo['original_filename'])): ?><p class="photo-filename"><?= h($photo['original_filename']) ?></p><?php endif; ?>
              <?php if (!empty($photo['moderation_note'])): ?><p class="photo-note"><strong>Note:</strong> <?= h($photo['moderation_note']) ?></p><?php endif; ?>

              <form class="photo-review-actions" method="post">
                <input type="hidden" name="photo_id" value="<?= (int)$photo['id'] ?>">
                <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">
                <input type="hidden" name="status" value="<?= h($status) ?>">
                <label class="photo-note-field">
                  <span>Moderation note</span>
                  <input type="text" name="moderation_note" value="<?= h($photo['moderation_note'] ?? '') ?>" placeholder="Optional internal note">
                </label>
                <div class="photo-action-row">
                  <?php if ($photoStatus !== 'approved'): ?>
                    <button class="touch-btn green" type="submit" name="action" value="approve">Approve</button>
                  <?php endif; ?>
                  <?php if ($photoStatus !== 'rejected'): ?>
                    <button class="touch-btn danger" type="submit" name="action" value="reject">Reject</button>
                  <?php endif; ?>
                  <?php if ($photoStatus !== 'pending'): ?>
                    <button class="touch-btn muted" type="submit" name="action" value="pending">Back to Pending</button>
                  <?php endif; ?>
                  <a class="touch-btn blue" href="<?= h($photoUrl) ?>" target="_blank" rel="noopener">View</a>
                </div>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</main>
<?php admin_footer(); ?>
