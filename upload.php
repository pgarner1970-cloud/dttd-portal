<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/photo-uploads.php';

$public_current = 'gallery';
$facebookUrl = 'https://www.facebook.com/profile.php?id=61579454050951';

$events = array_values(array_filter(photo_selectable_events(), 'photo_can_select_event'));
$currentEvent = null;
$rememberedEvent = null;

try {
    if (function_exists('dttd_event_from_access_cookie')) {
        $candidate = dttd_event_from_access_cookie(false);
        if ($candidate && photo_can_select_event($candidate)) {
            $rememberedEvent = $candidate;
        }
    }
} catch (Throwable $e) {
    $rememberedEvent = null;
}

try {
    $candidate = active_event();
    if ($candidate && photo_can_select_event($candidate)) {
        $currentEvent = $candidate;
    }
} catch (Throwable $e) {
    $currentEvent = null;
}

// If the visitor has joined/scanned an event, default uploads to that remembered event.
// This avoids older still-selectable events appearing first when a live event is active.
$defaultEvent = $rememberedEvent ?: $currentEvent;
$selectedEventId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedEventId = (int)($_POST['event_id'] ?? 0);
} elseif (isset($_GET['event_id'])) {
    $selectedEventId = (int)$_GET['event_id'];
} elseif ($defaultEvent) {
    $selectedEventId = (int)$defaultEvent['id'];
}
$selectedEvent = $selectedEventId ? photo_get_event($selectedEventId) : $defaultEvent;
if (!$currentEvent && $rememberedEvent) {
    $currentEvent = $rememberedEvent;
}
$guestName = trim((string)($_POST['guest_name'] ?? ''));
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$selectedEvent || !photo_can_select_event($selectedEvent)) {
        $error = 'Please choose a valid current or past event.';
    } elseif (!isset($_FILES['photo_upload']) || !is_array($_FILES['photo_upload'])) {
        $error = 'Please choose a photo to upload.';
    } elseif (($_FILES['photo_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Please choose a photo to upload.';
    } else {
        try {
            $paths = photo_process_uploaded_file(
                $_FILES['photo_upload']['tmp_name'],
                $_FILES['photo_upload']['name'] ?? 'upload.jpg',
                $selectedEvent
            );
            photo_insert_upload($selectedEvent, $guestName, $_FILES['photo_upload']['name'] ?? '', $paths);
            $success = 'Thanks — your photo has been uploaded and is now waiting for moderation.';
            $guestName = '';
        } catch (Throwable $e) {
            $error = $e->getMessage() ?: 'The photo could not be uploaded right now.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Upload Photos | Dance Thru The Decades</title>
  <meta name="description" content="Upload your event photos for moderation and inclusion in the public gallery.">
  <link rel="stylesheet" href="/assets/public-site.css?v=260">
</head>
<body class="homepage-option-one public-gallery-page">
  <main class="home-option-one">
    <?php require __DIR__ . '/includes/public-nav.php'; ?>

    <section class="public-gallery-hero">
      <div class="option-one-logo-shell public-list-logo">
        <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=152" alt="Dance Thru The Decades Events logo">
      </div>
      <p class="option-one-eyebrow">Event Photos</p>
      <h1 class="public-gallery-title">Upload Photos</h1>
      <p class="option-one-subtitle"><?= $currentEvent ? 'You can upload straight to the current event, or choose a recent past event if you are sharing later.' : 'Choose a recent past event to upload your photos. All uploads are moderated before going live.' ?></p>
    </section>

    <section class="public-gallery-shell">
      <article class="public-filter-card upload-card">
        <p class="option-one-eyebrow">Photo Upload</p>
        <h2><?= $currentEvent ? 'Share your event memories' : 'Choose an event and upload' ?></h2>
        <p>Uploads are reviewed first, then approved photos appear in the public gallery and event photo sections.</p>

        <?php if ($success): ?>
          <div class="public-form-success"><?= photo_h($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="public-form-error"><?= photo_h($error) ?></div>
        <?php endif; ?>

        <form class="public-upload-form" method="post" enctype="multipart/form-data">
          <div class="public-filter-grid upload-grid">
            <label>
              <span>Event</span>
              <select name="event_id" required>
                <option value="">Choose event</option>
                <?php foreach ($events as $event): ?>
                  <option value="<?= (int)$event['id'] ?>" <?= $selectedEvent && (int)$selectedEvent['id'] === (int)$event['id'] ? 'selected' : '' ?>><?= photo_h(photo_event_label($event)) ?></option>
                <?php endforeach; ?>
              </select>
            </label>

            <label>
              <span>Your name (optional)</span>
              <input type="text" name="guest_name" maxlength="120" value="<?= photo_h($guestName) ?>" placeholder="How should we credit this photo?">
            </label>
          </div>

          <label>
            <span>Photo</span>
            <input type="file" name="photo_upload" accept="image/jpeg,image/png,image/webp,image/gif" required>
          </label>

          <p class="upload-help-copy">Landscape and portrait photos are both supported. We keep the original image and create a branded display version automatically.</p>

          <div class="public-upload-actions">
            <button class="public-neon-btn" type="submit">Upload Photo</button>
            <a class="public-secondary-btn" href="/gallery.php">View Public Gallery</a>
          </div>
        </form>
      </article>
    </section>

    <?php require __DIR__ . '/includes/public-footer.php'; ?>
  </main>
</body>
</html>
