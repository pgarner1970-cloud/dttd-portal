<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/photo-uploads.php';

$public_current = 'gallery';
$facebookUrl = 'https://www.facebook.com/profile.php?id=61579454050951';

$events = array_values(array_filter(photo_selectable_events(), 'photo_can_select_event'));
$currentEvent = null;
$rememberedEvent = null;

try {
    $candidate = function_exists('photo_current_upload_event') ? photo_current_upload_event() : active_event();
    if ($candidate && photo_can_select_event($candidate)) {
        $currentEvent = $candidate;
    }
} catch (Throwable $e) {
    $currentEvent = null;
}

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

// Default to the actual current event before any remembered/scanned event. This
// stops an old access cookie from overriding tonight's event on the upload form.
$defaultEvent = $currentEvent ?: $rememberedEvent;
if (!$defaultEvent && !empty($events)) {
    $defaultEvent = end($events) ?: null;
    reset($events);
}

$selectedEventId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedEventId = (int)($_POST['event_id'] ?? 0);
} elseif (isset($_GET['event_id'])) {
    $selectedEventId = (int)$_GET['event_id'];
} elseif ($defaultEvent) {
    $selectedEventId = (int)$defaultEvent['id'];
}

$selectedEvent = $selectedEventId ? photo_get_event($selectedEventId) : $defaultEvent;
if (!$selectedEvent || !photo_can_select_event($selectedEvent)) {
    $selectedEvent = $defaultEvent;
    $selectedEventId = $selectedEvent ? (int)$selectedEvent['id'] : 0;
}

if (!$currentEvent && $rememberedEvent) {
    $currentEvent = $rememberedEvent;
}

/*
 * If the visitor has accessed an event through the event QR/code, lock uploads
 * to that event. This prevents guests at a live event from accidentally changing
 * the upload target to another public or past event.
 */
$uploadEventLocked = false;
if ($rememberedEvent && !empty($rememberedEvent['id']) && photo_can_select_event($rememberedEvent)) {
    $selectedEvent = $rememberedEvent;
    $selectedEventId = (int)$rememberedEvent['id'];
    $uploadEventLocked = true;
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
                $selectedEvent,
                $guestName
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
  <link rel="stylesheet" href="/assets/public-site.css?v=282">
</head>
<body class="homepage-option-one public-gallery-page public-upload-page">
  <main class="home-option-one">
    <?php require __DIR__ . '/includes/public-nav.php'; ?>

    <section class="public-gallery-hero">
      <div class="option-one-logo-shell public-list-logo">
        <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=152" alt="Dance Thru The Decades Events logo">
      </div>
      <p class="option-one-eyebrow">Event Photos</p>
      <h1 class="public-gallery-title">Upload Photos</h1>
      <p class="option-one-subtitle"><?= $uploadEventLocked ? 'You are connected to this event, so your photos will upload directly to it.' : ($currentEvent ? 'You can upload straight to the current event, or choose a recent past event if you are sharing later.' : 'Choose a recent past event to upload your photos. All uploads are moderated before going live.') ?></p>
    </section>

    <section class="public-gallery-shell">
      <article class="public-filter-card upload-card">
        <p class="option-one-eyebrow">Photo Upload</p>
        <h2><?= $uploadEventLocked ? 'Share photos from this event' : ($currentEvent ? 'Share your event memories' : 'Choose an event and upload') ?></h2>
        <p>Uploads are reviewed first, then approved photos appear in the public gallery and event photo sections.</p>

        <?php if ($success): ?>
          <div class="public-form-success"><?= photo_h($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="public-form-error"><?= photo_h($error) ?></div>
        <?php endif; ?>

        <form class="public-upload-form" method="post" enctype="multipart/form-data">
          <div class="public-filter-grid upload-grid">
            <?php if ($uploadEventLocked && $selectedEvent): ?>
              <label>
                <span>Event</span>
                <input type="text" value="<?= photo_h(photo_event_label($selectedEvent)) ?>" readonly aria-readonly="true">
                <input type="hidden" name="event_id" value="<?= (int)$selectedEvent['id'] ?>">
              </label>
            <?php else: ?>
              <label>
                <span>Event</span>
                <select name="event_id" required>
                  <option value="">Choose event</option>
                  <?php foreach ($events as $event): ?>
                    <option value="<?= (int)$event['id'] ?>" <?= $selectedEvent && (int)$selectedEvent['id'] === (int)$event['id'] ? 'selected' : '' ?>><?= photo_h(photo_event_label($event)) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            <?php endif; ?>

            <label>
              <span>Your name (optional)</span>
              <input type="text" name="guest_name" maxlength="120" value="<?= photo_h($guestName) ?>" placeholder="Name to display with this photo">
            </label>
          </div>

          <label>
            <span>Photo</span>
            <input type="file" name="photo_upload" accept="image/jpeg,image/png,image/webp,image/gif" required>
          </label>

          <p class="upload-help-copy">Landscape and portrait photos are both supported. We keep the original image and create a branded display version automatically.</p>

          <div class="public-upload-actions">
            <button class="public-neon-btn public-upload-submit" type="submit">Upload Photo</button>
            <span class="public-upload-progress" hidden aria-live="polite">
              <span class="public-upload-spinner" aria-hidden="true"></span>
              Uploading your photo… please don’t close this page.
            </span>
          </div>
        </form>

        <script>
          (function () {
            var form = document.querySelector('.public-upload-form');
            if (!form) return;
            var button = form.querySelector('.public-upload-submit');
            var progress = form.querySelector('.public-upload-progress');
            form.addEventListener('submit', function () {
              if (!form.checkValidity()) return;
              if (button) {
                button.disabled = true;
                button.classList.add('is-uploading');
                button.textContent = 'Uploading…';
              }
              if (progress) progress.hidden = false;
            });
          })();
        </script>
      </article>
    </section>

    <?php require __DIR__ . '/includes/public-footer.php'; ?>
  </main>
</body>
</html>
