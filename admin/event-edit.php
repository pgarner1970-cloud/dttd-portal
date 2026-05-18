<?php
require_once __DIR__ . '/_auth.php';

function event_image_column_exists() {
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = db()->query("SHOW COLUMNS FROM events LIKE 'event_image'");
        $exists = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function event_column_exists($column) {
    static $cache = [];

    if (isset($cache[$column])) {
        return $cache[$column];
    }

    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM events LIKE ?");
        $stmt->execute([$column]);
        $cache[$column] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $cache[$column] = false;
    }

    return $cache[$column];
}

function event_upload_image() {
    if (!isset($_FILES['event_image_upload']) || !is_array($_FILES['event_image_upload'])) {
        return null;
    }

    $file = $_FILES['event_image_upload'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return null;
    }

    $tmp = $file['tmp_name'] ?? '';
    if (!$tmp || !is_uploaded_file($tmp)) {
        return null;
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    $mime = mime_content_type($tmp);
    if (!isset($allowed[$mime])) {
        return null;
    }

    $upload_dir = dirname(__DIR__) . '/uploads/events';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0775, true);
    }

    $filename = 'event-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $target = $upload_dir . '/' . $filename;

    if (!move_uploaded_file($tmp, $target)) {
        return null;
    }

    return '/uploads/events/' . $filename;
}


function event_generate_code($length = 6) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';

    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $code;
}

function event_unique_code($current_id = 0) {
    do {
        $code = event_generate_code();
        $stmt = db()->prepare("SELECT id FROM events WHERE event_code = ? AND id <> ? LIMIT 1");
        $stmt->execute([$code, (int)$current_id]);
        $exists = $stmt->fetch();
    } while ($exists);

    return $code;
}

function request_close_options() {
    return [
        '0' => 'At event end',
        '15' => '15 minutes',
        '30' => '30 minutes',
        '45' => '45 minutes',
        '60' => '1 hour',
        '90' => '1 hour 30 minutes',
        '120' => '2 hours',
    ];
}

function calculate_requests_close_at($event_date, $end_time, $minutes_before) {
    if (!$event_date || !$end_time) {
        return null;
    }

    $end_ts = strtotime($event_date . ' ' . $end_time);
    $start_of_day = strtotime($event_date . ' 12:00');

    // If end time is early morning, treat it as after midnight.
    if ($end_ts && $end_ts < $start_of_day) {
        $end_ts = strtotime('+1 day', $end_ts);
    }

    if (!$end_ts) {
        return null;
    }

    $close_ts = $end_ts - ((int)$minutes_before * 60);
    return date('Y-m-d H:i:s', $close_ts);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_edit = $id > 0;
$error = '';
$success = '';

$event = [
    'id' => 0,
    'event_name' => '',
    'venue_name' => '',
    'venue_address' => '',
    'venue_postcode' => '',
    'venue_facebook_url' => '',
    'venue_website_url' => '',
    'venue_instagram_url' => '',
    'venue_social_label' => '',
    'event_type' => 'public',
    'notes' => '',
    'event_date' => date('Y-m-d'),
    'start_time' => '19:30',
    'end_time' => '01:00',
    'requests_close_at' => '',
    'is_active' => 1,
    'event_code' => '',
    'event_image' => '',
];

if ($is_edit) {
    $stmt = db()->prepare("SELECT * FROM events WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $loaded = $stmt->fetch();

    if ($loaded) {
        $event = array_merge($event, $loaded);
    } else {
        $error = 'Event not found.';
        $is_edit = false;
        $id = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $event_name = trim((string)($_POST['event_name'] ?? ''));
    $venue_name = trim((string)($_POST['venue_name'] ?? ''));
    $venue_address = trim((string)($_POST['venue_address'] ?? ''));
    $venue_postcode = trim((string)($_POST['venue_postcode'] ?? ''));
    $venue_facebook_url = trim((string)($_POST['venue_facebook_url'] ?? ''));
    $venue_website_url = trim((string)($_POST['venue_website_url'] ?? ''));
    $venue_instagram_url = trim((string)($_POST['venue_instagram_url'] ?? ''));
    $venue_social_label = trim((string)($_POST['venue_social_label'] ?? ''));
    $event_type = trim((string)($_POST['event_type'] ?? 'public'));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $event_date = trim((string)($_POST['event_date'] ?? ''));
    $start_time = trim((string)($_POST['start_time'] ?? ''));
    $end_time = trim((string)($_POST['end_time'] ?? ''));
    $close_before = (int)($_POST['close_before_end'] ?? 30);
    $is_active = !empty($_POST['is_active']) ? 1 : 0;
    $event_code = trim((string)($_POST['event_code'] ?? ''));
    $event_code = $event_code !== '' ? $event_code : event_unique_code($id);

    if ($event_name === '' || $venue_name === '' || $event_date === '' || $start_time === '') {
        $error = 'Please complete the required event fields.';
    } else {
        $requests_close_at = calculate_requests_close_at($event_date, $end_time ?: $start_time, $close_before);
        $uploaded_image = event_upload_image();

        $data = [
            'event_name' => $event_name,
            'venue_name' => $venue_name,
            'venue_address' => $venue_address,
            'venue_postcode' => $venue_postcode,
            'venue_facebook_url' => $venue_facebook_url,
            'venue_website_url' => $venue_website_url,
            'venue_instagram_url' => $venue_instagram_url,
            'venue_social_label' => $venue_social_label,
            'event_type' => $event_type,
            'notes' => $notes,
            'event_date' => $event_date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'requests_close_at' => $requests_close_at,
            'is_active' => $is_active,
            'event_code' => $event_code,
        ];

        if (event_image_column_exists() && $uploaded_image) {
            $data['event_image'] = $uploaded_image;
        }

        // Only use columns that exist on this install.
        $data = array_filter(
            $data,
            fn($value, $column) => event_column_exists($column),
            ARRAY_FILTER_USE_BOTH
        );

        if ($is_edit) {
            $sets = [];
            $params = [];

            foreach ($data as $column => $value) {
                $sets[] = "{$column} = ?";
                $params[] = $value;
            }

            $params[] = $id;

            $stmt = db()->prepare("UPDATE events SET " . implode(', ', $sets) . " WHERE id = ?");
            $stmt->execute($params);
        } else {
            $columns = array_keys($data);
            $placeholders = array_fill(0, count($columns), '?');

            $stmt = db()->prepare(
                "INSERT INTO events (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")"
            );
            $stmt->execute(array_values($data));
        }

        header('Location: /admin/events.php');
        exit;
    }

    $event = array_merge($event, $_POST);
}

$close_before_value = '30';
if (!empty($event['requests_close_at']) && !empty($event['end_time']) && !empty($event['event_date'])) {
    $end_ts = strtotime($event['event_date'] . ' ' . input_time($event['end_time']));
    if ($end_ts && $end_ts < strtotime($event['event_date'] . ' 12:00')) {
        $end_ts = strtotime('+1 day', $end_ts);
    }

    $close_ts = strtotime($event['requests_close_at']);
    if ($end_ts && $close_ts) {
        $close_before_value = (string)max(0, round(($end_ts - $close_ts) / 60));
    }
}

admin_header(($is_edit ? 'Edit Event' : 'Add Event') . ' - DJ Portal');
?>

<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title"><?= $is_edit ? 'Edit Event' : 'Add Event' ?></h1>
        <p class="touch-subtitle">Set event details, timing and request behaviour.</p>
      </div>
      <div>
        <a class="touch-btn" href="/admin/events.php">Back to Events</a>
      </div>
    </div>

    <form method="post" enctype="multipart/form-data" class="event-edit-form restored-event-form">
      <?php if ($error): ?>
        <div class="settings-alert error"><?= h($error) ?></div>
      <?php endif; ?>

      <section class="settings-card">
        <div class="settings-card-header">
          <div class="settings-card-icon">▦</div>
          <div>
            <h3>Event Details</h3>
            <p>Name, venue and type of event.</p>
          </div>
        </div>

        <div class="settings-grid">
          <label>
            <span>Event name *</span>
            <input name="event_name" value="<?= h($event['event_name']) ?>" required>
          </label>

          <label>
            <span>Venue name *</span>
            <input name="venue_name" value="<?= h($event['venue_name']) ?>" required>
          </label>

          <label>
            <span>Event type</span>
            <select name="event_type">
              <option value="public" <?= ($event['event_type'] ?? '') === 'public' ? 'selected' : '' ?>>Public Night</option>
              <option value="private" <?= ($event['event_type'] ?? '') === 'private' ? 'selected' : '' ?>>Private Party</option>
            </select>
          </label>

          <label>
            <span>Notes</span>
            <textarea name="notes" placeholder="Internal event notes"><?= h($event['notes'] ?? '') ?></textarea>
          </label>
        </div>
      </section>

      <section class="settings-card venue-social-card">
        <div class="settings-card-header">
          <div class="settings-card-icon">⌖</div>
          <div>
            <h3>Venue Details & Social</h3>
            <p>Store venue location and social links for maps, check-ins and public event displays.</p>
          </div>
        </div>

        <div class="settings-grid">
          <label>
            <span>Venue address</span>
            <input name="venue_address" value="<?= h($event['venue_address'] ?? '') ?>" placeholder="Street address or venue location">
          </label>

          <label>
            <span>Postcode</span>
            <input name="venue_postcode" value="<?= h($event['venue_postcode'] ?? '') ?>" placeholder="e.g. DY14 0NJ">
            <?php if (!empty($event['venue_postcode'])): ?>
              <small>
                <a href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode(($event['venue_name'] ?? '') . ' ' . ($event['venue_postcode'] ?? '')) ?>" target="_blank" rel="noopener">Open in Google Maps</a>
              </small>
            <?php endif; ?>
          </label>

          <label>
            <span>Venue Facebook URL</span>
            <input type="url" name="venue_facebook_url" value="<?= h($event['venue_facebook_url'] ?? '') ?>" placeholder="https://facebook.com/...">
          </label>

          <label>
            <span>Venue website URL</span>
            <input type="url" name="venue_website_url" value="<?= h($event['venue_website_url'] ?? '') ?>" placeholder="https://...">
          </label>

          <label>
            <span>Venue Instagram URL</span>
            <input type="url" name="venue_instagram_url" value="<?= h($event['venue_instagram_url'] ?? '') ?>" placeholder="https://instagram.com/...">
          </label>

          <label>
            <span>Social display label</span>
            <input name="venue_social_label" value="<?= h($event['venue_social_label'] ?? '') ?>" placeholder="e.g. Follow The Venue">
            <small>Optional label for public displays, posters or QR pages.</small>
          </label>
        </div>
      </section>



      <section class="settings-card event-image-upload-card">
        <div class="settings-card-header">
          <div class="settings-card-icon">▣</div>
          <div>
            <h3>Event Image / Flyer</h3>
            <p>Upload a flyer or promotional image for this event.</p>
          </div>
        </div>

        <div class="settings-card-body event-image-upload-body">
          <?php if (!event_image_column_exists()): ?>
            <div class="settings-alert warning">
              The upload field is ready, but the database column is missing. Run:
              <code>ALTER TABLE events ADD COLUMN event_image VARCHAR(255) NULL;</code>
            </div>
          <?php endif; ?>

          <?php if (event_image_column_exists() && !empty($event['event_image'])): ?>
            <div class="event-image-preview">
              <img src="<?= h($event['event_image']) ?>" alt="Current event image">
            </div>
          <?php endif; ?>

          <label class="event-image-upload-label" for="event_image_upload">
            <span>Choose image</span>
            <input type="file" name="event_image_upload" id="event_image_upload" accept="image/jpeg,image/png,image/webp,image/gif">
          </label>

          <p class="settings-help-text">
            Supported formats: JPG, PNG, WebP and GIF. This can later be used on public displays or event pages.
          </p>
        </div>
      </section>

      <?php if ($is_edit): ?>
        <?php
          $public_request_url = rtrim(app_setting('public_request_base_url', ''), '/');
          if ($public_request_url === '') {
              $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
              $host = $_SERVER['HTTP_HOST'] ?? '';
              $public_request_url = $scheme . '://' . $host;
          }

          $has_event_code = !empty($event['event_code']);
          $event_request_url = $has_event_code
              ? $public_request_url . '/request.php?code=' . rawurlencode($event['event_code'])
              : '';
        ?>
        <section class="settings-card event-qr-card">
          <div class="settings-card-header">
            <div class="settings-card-icon">▦</div>
            <div>
              <h3>QR Code & Event Code</h3>
              <p>Use this for posters, flyers, table cards and screen displays.</p>
            </div>
          </div>

          <div class="event-qr-body <?= $has_event_code ? '' : 'event-qr-missing' ?>" data-qr-url="<?= h($event_request_url) ?>">
            <?php if (!$has_event_code): ?>
              <div class="settings-alert warning">
                This event does not have an event code yet. Add one in Portal Behaviour, or save the event and a code will be generated.
              </div>
            <?php else: ?>
              <div class="event-code-panel">
                <span>Event code</span>
                <strong><?= h($event['event_code']) ?></strong>
                <small><?= h($event_request_url) ?></small>
              </div>

              <div class="event-qr-preview">
                <canvas class="event-qr-canvas" width="220" height="220" aria-label="Event QR code"></canvas>
              </div>

              <div class="event-qr-actions">
                <a class="touch-btn blue" href="/admin/event-qr.php?id=<?= (int)$event['id'] ?>">Open QR Page</a>
                <button type="button" class="touch-btn qr-print-btn">Print QR</button>
                <button type="button" class="touch-btn qr-copy-btn">Copy Link</button>
              </div>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>


<section class="settings-card">
        <div class="settings-card-header">
          <div class="settings-card-icon">◷</div>
          <div>
            <h3>Timing</h3>
            <p>End times earlier than start times are treated as after midnight.</p>
          </div>
        </div>

        <div class="settings-grid four">
          <label>
            <span>Event date</span>
            <input type="date" name="event_date" value="<?= h($event['event_date']) ?>">
          </label>

          <label>
            <span>Start time</span>
            <input type="time" name="start_time" value="<?= h(input_time($event['start_time'])) ?>">
          </label>

          <label>
            <span>End time</span>
            <input type="time" name="end_time" value="<?= h(input_time($event['end_time'])) ?>">
            <small>Optional. Example: 19:30 to 01:30 spans midnight.</small>
          </label>

          <label>
            <span>Close requests before end</span>
            <select name="close_before_end">
              <?php foreach (request_close_options() as $minutes => $label): ?>
                <option value="<?= h($minutes) ?>" <?= (string)$minutes === (string)$close_before_value ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
      </section>

      <section class="settings-card">
        <div class="settings-card-header">
          <div class="settings-card-icon">⚙</div>
          <div>
            <h3>Portal Behaviour</h3>
            <p>Control request visibility and whether this event is available.</p>
          </div>
        </div>

        <div class="settings-grid">
          
          <label>
            <span>Event code</span>
            <input name="event_code" value="<?= h($event['event_code'] ?? '') ?>" placeholder="Auto-generated if left blank">
            <small>Used for the public request link and QR code.</small>
          </label>

          <label>
            <span>Queue visibility</span>
            <select name="queue_visibility">
              <option value="public">Public</option>
            </select>
          </label>

          <label class="settings-check">
            <input type="checkbox" name="is_active" value="1" <?= !empty($event['is_active']) ? 'checked' : '' ?>>
            <span>Active / available for portal selection</span>
          </label>
        </div>
      </section>

      <div class="form-actions">
        <a class="touch-btn" href="/admin/events.php">Cancel</a>
        <button class="touch-btn blue" type="submit"><?= $is_edit ? 'Save Event' : 'Create Event' ?></button>
      </div>
    </form>
  </section>
</main>

<?php admin_footer(); ?>
