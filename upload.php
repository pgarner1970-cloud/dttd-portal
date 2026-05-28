<?php
require_once __DIR__ . '/includes/db.php';
dttd_redirect_public_feature_to_primary_domain();

if (!function_exists('public_h')) {
    function public_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$facebookUrl = defined('FACEBOOK_URL') ? FACEBOOK_URL : 'https://www.facebook.com/profile.php?id=61579454050951';
$public_current = 'gallery';
$gate_error = '';
$upload_error = '';
$upload_success = '';

$is_access_attempt = isset($_GET['code']) || isset($_GET['token']) || isset($_GET['access']) || isset($_POST['event_access_code']) || isset($_POST['event_code']) || isset($_POST['code']) || isset($_POST['token']) || isset($_POST['access']);

if ($is_access_attempt && !isset($_FILES['photo_upload'])) {
    [$access_event, $access_error] = dttd_handle_event_access_submission('/upload.php');
    $gate_error = $access_error;
}

$event = dttd_event_from_access_cookie(true);

function dttd_gallery_event_label($event) {
    if (!$event) return 'Event Photos';

    $bits = [];
    if (!empty($event['event_name'])) $bits[] = $event['event_name'];
    if (!empty($event['venue_name'])) $bits[] = $event['venue_name'];

    return $bits ? implode(' at ', $bits) : 'Tonight\'s event';
}

function dttd_gallery_table_ready() {
    return dttd_table_exists('event_photo_uploads')
        && dttd_table_column_exists('event_photo_uploads', 'event_id')
        && dttd_table_column_exists('event_photo_uploads', 'file_path');
}

function dttd_gallery_store_db_record($event_id, $guest_name, $original_filename, $file_path) {
    if (!dttd_gallery_table_ready()) {
        return false;
    }

    $data = [
        'event_id' => (int)$event_id,
        'guest_name' => trim((string)$guest_name),
        'original_filename' => trim((string)$original_filename),
        'file_path' => $file_path,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
        'uploaded_at' => date('Y-m-d H:i:s'),
    ];

    $data = array_filter(
        $data,
        fn($value, $column) => dttd_table_column_exists('event_photo_uploads', $column),
        ARRAY_FILTER_USE_BOTH
    );

    if (!$data) {
        return false;
    }

    try {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $stmt = db()->prepare("INSERT INTO event_photo_uploads (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")");
        $stmt->execute(array_values($data));
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function dttd_gallery_approved_photos($event_id) {
    $photos = [];

    if (dttd_gallery_table_ready()) {
        try {
            $statusColumn = dttd_table_column_exists('event_photo_uploads', 'status') ? "AND status = 'approved'" : '';
            $orderColumn = dttd_table_column_exists('event_photo_uploads', 'approved_at') ? 'approved_at DESC' : (dttd_table_column_exists('event_photo_uploads', 'created_at') ? 'created_at DESC' : 'id DESC');
            $stmt = db()->prepare("SELECT * FROM event_photo_uploads WHERE event_id = ? $statusColumn ORDER BY $orderColumn LIMIT 60");
            $stmt->execute([(int)$event_id]);
            foreach ($stmt->fetchAll() as $row) {
                if (!empty($row['file_path'])) {
                    $photos[] = ltrim((string)$row['file_path'], '/');
                }
            }
        } catch (Throwable $e) {
            // Fall back to approved folder scan.
        }
    }

    $approvedDir = __DIR__ . '/uploads/event-photos/approved';
    if (is_dir($approvedDir)) {
        foreach (glob($approvedDir . '/event-' . (int)$event_id . '-*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [] as $file) {
            $rel = 'uploads/event-photos/approved/' . basename($file);
            if (!in_array($rel, $photos, true)) {
                $photos[] = $rel;
            }
        }
    }

    return array_slice($photos, 0, 60);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo_upload']) && $event) {
    $guest_name = trim((string)($_POST['guest_name'] ?? ''));
    $file = $_FILES['photo_upload'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $upload_error = 'Please choose a photo to upload.';
    } elseif (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $upload_error = 'The photo could not be uploaded. Please try again.';
    } elseif (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        $upload_error = 'Please choose a photo smaller than 8 MB.';
    } else {
        $tmp = $file['tmp_name'] ?? '';
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        $mime = '';
        if ($tmp && is_uploaded_file($tmp)) {
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
                if ($finfo) finfo_close($finfo);
            }
            if ($mime === '' && function_exists('mime_content_type')) {
                $mime = (string)mime_content_type($tmp);
            }
        }

        if (!$tmp || !is_uploaded_file($tmp) || !isset($allowed[$mime])) {
            $upload_error = 'Please upload a JPG, PNG, WebP or GIF image.';
        } else {
            $pendingDir = __DIR__ . '/uploads/event-photos/pending';
            if (!is_dir($pendingDir)) {
                mkdir($pendingDir, 0755, true);
            }

            $filename = 'event-' . (int)$event['id'] . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
            $target = $pendingDir . '/' . $filename;
            $relativePath = 'uploads/event-photos/pending/' . $filename;

            if (!move_uploaded_file($tmp, $target)) {
                $upload_error = 'The photo could not be saved. Please try again.';
            } else {
                @chmod($target, 0644);
                dttd_gallery_store_db_record((int)$event['id'], $guest_name, (string)($file['name'] ?? ''), $relativePath);

                $meta = [
                    'event_id' => (int)$event['id'],
                    'guest_name' => $guest_name,
                    'original_filename' => (string)($file['name'] ?? ''),
                    'file_path' => $relativePath,
                    'status' => 'pending',
                    'created_at' => date('c'),
                ];
                @file_put_contents($target . '.json', json_encode($meta, JSON_PRETTY_PRINT));

                $upload_success = 'Thanks — your photo has been uploaded and is waiting for approval.';
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo_upload']) && !$event) {
    $gate_error = 'Please enter the event code first, then upload your photo.';
}

$eventLabel = dttd_gallery_event_label($event);
$approvedPhotos = $event ? dttd_gallery_approved_photos((int)$event['id']) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Upload Photos | Dance Thru the Decades</title>
  <meta name="description" content="Upload event photos for Dance Thru the Decades. Photos are moderated before they appear publicly.">
  <link rel="stylesheet" href="/assets/public-site.css?v=168">
</head>
<body class="homepage-option-one public-event-feature-page public-gallery-page">
  <main class="home-option-one">
    <?php require __DIR__ . '/includes/public-nav.php'; ?>

    <section class="public-event-detail-hero public-feature-hero">
      <div class="option-one-logo-shell public-list-logo">
        <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=152" alt="Dance Thru The Decades Events logo">
      </div>
      <p class="option-one-eyebrow">Event Photos</p>
      <h1 class="event-detail-title">Upload Photos</h1>
      <p class="option-one-subtitle"><?= $event ? public_h($eventLabel) : 'Scan the QR code at the venue or enter the event code to continue.' ?></p>
    </section>

    <section class="public-event-detail-section public-feature-section">
      <?php if (!$event): ?>
        <article class="public-empty-card public-access-card">
          <h2>Join this event</h2>
          <p>Enter the event code displayed at the venue. We will remember this device until the event closes.</p>

          <?php if ($gate_error): ?>
            <div class="public-alert error"><?= public_h($gate_error) ?></div>
          <?php endif; ?>

          <form class="public-access-form" method="post" action="/upload.php">
            <label for="event_access_code">Event code</label>
            <input id="event_access_code" name="event_access_code" inputmode="text" autocomplete="off" autocapitalize="characters" placeholder="Example: 5MKDP2" required>
            <button class="public-neon-btn" type="submit">Continue</button>
          </form>

          <div class="public-event-actions public-centred-actions">
            <a class="public-neon-btn subtle" href="/">Back to Website</a>
            <a class="public-neon-btn subtle" href="/events">Public Events</a>
          </div>
        </article>
      <?php else: ?>
        <article class="public-feature-card public-upload-card">
          <div class="public-feature-card-header">
            <div>
              <span class="public-feature-kicker">Connected to this event</span>
              <h2>Share a photo</h2>
            </div>
            <a class="public-neon-btn subtle" href="/event.php">Event Info</a>
          </div>

          <p>Photos are saved as pending and must be approved before they appear on the live site.</p>

          <?php if ($upload_success): ?>
            <div class="public-alert success"><?= public_h($upload_success) ?></div>
          <?php endif; ?>

          <?php if ($upload_error): ?>
            <div class="public-alert error"><?= public_h($upload_error) ?></div>
          <?php endif; ?>

          <form class="public-request-form public-upload-form" method="post" action="/upload.php" enctype="multipart/form-data">
            <label>Your name</label>
            <input name="guest_name" maxlength="120" placeholder="Optional">

            <label>Choose photo *</label>
            <input type="file" name="photo_upload" accept="image/jpeg,image/png,image/webp,image/gif" required>

            <button class="public-neon-btn public-submit-btn" type="submit">Upload Photo</button>
          </form>

          <p class="public-small-note">Maximum file size: 8 MB. JPG, PNG, WebP and GIF are supported.</p>
        </article>

        <article class="public-feature-card public-approved-gallery-card">
          <div class="public-feature-card-header">
            <div>
              <span class="public-feature-kicker">Approved Photos</span>
              <h2>Event gallery</h2>
            </div>
          </div>

          <?php if ($approvedPhotos): ?>
            <div class="public-photo-grid">
              <?php foreach ($approvedPhotos as $photo): ?>
                <a href="/<?= public_h($photo) ?>" target="_blank" rel="noopener" class="public-photo-thumb">
                  <img src="/<?= public_h($photo) ?>" alt="Approved event photo">
                </a>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p>No approved photos are live yet. Uploaded photos will appear here after moderation.</p>
          <?php endif; ?>
        </article>
      <?php endif; ?>
    </section>

    <?php require __DIR__ . '/includes/public-footer.php'; ?>
  </main>
</body>
</html>
