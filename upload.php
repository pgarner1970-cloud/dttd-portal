<?php
require_once __DIR__ . '/includes/db.php';
dttd_no_cache_headers();
require_once __DIR__ . '/includes/photo-uploads.php';

dttd_redirect_public_feature_to_primary_domain();

$uploadAccessError = '';
$is_upload_access_attempt = (
    isset($_GET['code']) || isset($_GET['token']) || isset($_GET['access']) ||
    isset($_POST['event_access_code']) || isset($_POST['event_code']) || isset($_POST['code']) || isset($_POST['token']) || isset($_POST['access'])
);

if ($is_upload_access_attempt && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    [$access_event, $access_error] = dttd_handle_event_access_submission('/upload.php');
    $uploadAccessError = $access_error;
}


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
        if ($candidate && photo_can_select_event($candidate, true)) {
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
if ($rememberedEvent && !empty($rememberedEvent['id']) && photo_can_select_event($rememberedEvent, true)) {
    $selectedEvent = $rememberedEvent;
    $selectedEventId = (int)$rememberedEvent['id'];
    $uploadEventLocked = true;
}

$guestName = trim((string)($_POST['guest_name'] ?? ''));
$selfieMode = isset($_GET['selfie']) || (($_POST['upload_mode'] ?? '') === 'selfie');
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$selectedEvent || !photo_can_select_event($selectedEvent, $uploadEventLocked)) {
        $error = 'Please choose a valid current or recent public event.';
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
  <?= dttd_cache_meta_tags() ?>
  <title>Upload Photos | Dance Thru The Decades</title>
  <meta name="description" content="Upload your event photos for moderation and inclusion in the public gallery.">
  <link rel="stylesheet" href="<?= h(dttd_asset_url('assets/public-site.css')) ?>">
</head>
<body class="homepage-option-one public-gallery-page public-upload-page">
  <main class="home-option-one">
    <?php require __DIR__ . '/includes/public-nav.php'; ?>

    <section class="public-gallery-hero">
      <div class="option-one-logo-shell public-list-logo">
        <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=200" alt="Dance Thru The Decades Events logo">
      </div>
      <p class="option-one-eyebrow"><?= $selfieMode ? 'Event Selfie' : 'Event Photos' ?></p>
      <h1 class="public-gallery-title"><?= $selfieMode ? 'Take a Selfie' : 'Upload Photos' ?></h1>
      <p class="option-one-subtitle"><?= $uploadEventLocked ? 'You are connected to this event, so your photos will upload directly to it.' : ($currentEvent ? 'You can upload straight to the current event, or choose a recent past event if you are sharing later.' : 'Choose a recent past event to upload your photos. All uploads are moderated before going live.') ?></p>
    </section>

    <section class="public-gallery-shell">
      <?php if (!empty($uploadAccessError)): ?>
        <div class="public-alert error upload-access-error"><?= h($uploadAccessError) ?></div>
      <?php endif; ?>
      <article class="public-filter-card upload-card">
        <p class="option-one-eyebrow"><?= $selfieMode ? 'Selfie Upload' : 'Photo Upload' ?></p>
        <h2><?= $selfieMode ? 'Snap and share your event selfie' : ($uploadEventLocked ? 'Share photos from this event' : ($currentEvent ? 'Share your event memories' : 'Choose an event and upload')) ?></h2>
        <p><?= $selfieMode ? 'Use your phone camera, add your name if you want, and your selfie will wait for moderation like any other event photo.' : 'Uploads are reviewed first, then approved photos appear in the public gallery and event photo sections.' ?></p>

        <?php if ($success): ?>
          <div class="public-form-success"><?= photo_h($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="public-form-error"><?= photo_h($error) ?></div>
        <?php endif; ?>

        <form class="public-upload-form" method="post" enctype="multipart/form-data">
          <input type="hidden" name="upload_mode" value="<?= $selfieMode ? 'selfie' : 'photo' ?>">
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
            <span><?= $selfieMode ? 'Selfie photo' : 'Photo' ?></span>
            <input type="file" name="photo_upload" accept="image/jpeg,image/png,image/webp,image/gif" <?= $selfieMode ? 'capture="user"' : '' ?> required>
          </label>

          <p class="upload-help-copy"><?= $selfieMode ? 'On most phones this opens the front camera. You can still choose an existing photo if your browser offers that option.' : 'Landscape and portrait photos are both supported. We keep the original image and create a branded display version automatically.' ?></p>

          <div class="public-upload-actions">
            <button class="public-neon-btn public-upload-submit" type="submit"><?= $selfieMode ? 'Upload Selfie' : 'Upload Photo' ?></button>
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
<?= dttd_bfcache_reload_script() ?>
</body>
</html>
