<?php
require_once __DIR__ . '/../includes/upload-paths.php';
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
        mkdir($upload_dir, 0755, true);
    }

    $filename = 'event-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $target = $upload_dir . '/' . $filename;

    if (!move_uploaded_file($tmp, $target)) {
        return null;
    }

    return 'https://dancethruthedecades.co.uk/uploads/events/' . $filename;
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

function event_slugify($value) {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim($value, '-');
    return $value !== '' ? $value : 'event';
}

function event_unique_public_slug($base, $current_id = 0) {
    $base = event_slugify($base);
    $slug = $base;
    $suffix = 2;

    do {
        $stmt = db()->prepare("SELECT id FROM events WHERE public_slug = ? AND id <> ? LIMIT 1");
        $stmt->execute([$slug, (int)$current_id]);
        $exists = $stmt->fetch();

        if ($exists) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }
    } while ($exists);

    return $slug;
}


function venues_table_exists() {
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = db()->query("SHOW TABLES LIKE 'venues'");
        $exists = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function venue_column_exists($column) {
    static $cache = [];

    if (isset($cache[$column])) {
        return $cache[$column];
    }

    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM venues LIKE ?");
        $stmt->execute([$column]);
        $cache[$column] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $cache[$column] = false;
    }

    return $cache[$column];
}

function get_venues_for_select() {
    if (!venues_table_exists()) {
        return [];
    }

    try {
        return db()->query("
            SELECT *
            FROM venues
            ORDER BY venue_name ASC, id ASC
        ")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function save_or_update_venue_from_event_form($selected_venue_id, $venue_name, $venue_address, $venue_postcode, $facebook_url, $website_url, $instagram_url, $ticket_url, $social_label) {
    if (!venues_table_exists()) {
        return null;
    }

    if (trim($venue_name) === '') {
        return null;
    }

    $data = [
        'venue_name' => trim($venue_name),
        'venue_address' => trim($venue_address),
        'venue_postcode' => trim($venue_postcode),
        'venue_facebook_url' => trim($facebook_url),
        'venue_website_url' => trim($website_url),
        'venue_instagram_url' => trim($instagram_url),
        'venue_ticket_url' => trim($ticket_url),
        'venue_social_label' => trim($social_label),
    ];

    $data = array_filter(
        $data,
        fn($value, $column) => venue_column_exists($column),
        ARRAY_FILTER_USE_BOTH
    );

    if ($selected_venue_id > 0) {
        $sets = [];
        $params = [];

        foreach ($data as $column => $value) {
            $sets[] = "{$column} = ?";
            $params[] = $value;
        }

        if ($sets) {
            $params[] = $selected_venue_id;
            $stmt = db()->prepare("UPDATE venues SET " . implode(', ', $sets) . " WHERE id = ?");
            $stmt->execute($params);
        }

        return $selected_venue_id;
    }

    $columns = array_keys($data);
    $placeholders = array_fill(0, count($columns), '?');

    $stmt = db()->prepare(
        "INSERT INTO venues (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")"
    );
    $stmt->execute(array_values($data));

    return (int)db()->lastInsertId();
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
    'venue_id' => null,
    'venue_name' => '',
    'venue_address' => '',
    'venue_postcode' => '',
    'venue_facebook_url' => '',
    'venue_website_url' => '',
    'venue_instagram_url' => '',
    'venue_ticket_url' => '',
    'venue_social_label' => '',
    'event_type' => 'public',
    'notes' => '',
    'event_date' => date('Y-m-d'),
    'start_time' => '19:30',
    'end_time' => '01:00',
    'requests_close_at' => '',
    'is_active' => 1,
    'event_code' => '',
    'status' => 'scheduled',
    'public_slug' => '',
    'queue_visibility' => 'public',
    'event_image' => '',
    'photo_overlay_theme' => 'standard',
    'photo_overlay_title' => '',
];

if ($is_edit) {
    $stmt = db()->prepare("SELECT * FROM events WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $loaded = $stmt->fetch();

    if ($loaded) {
        $event = array_merge($event, $loaded);
        if (($event['event_type'] ?? '') === 'private') {
            $event['event_type'] = 'private_party';
        }
    } else {
        $error = 'Event not found.';
        $is_edit = false;
        $id = 0;
    }
}


$venues_for_select = get_venues_for_select();

if (!empty($event['venue_id']) && venues_table_exists()) {
    try {
        $venue_stmt = db()->prepare("SELECT * FROM venues WHERE id = ? LIMIT 1");
        $venue_stmt->execute([(int)$event['venue_id']]);
        $selected_venue = $venue_stmt->fetch();

        if ($selected_venue) {
            foreach (['venue_name', 'venue_address', 'venue_postcode', 'venue_facebook_url', 'venue_website_url', 'venue_instagram_url', 'venue_ticket_url', 'venue_social_label'] as $venue_field) {
                if (array_key_exists($venue_field, $selected_venue) && empty($event[$venue_field])) {
                    $event[$venue_field] = $selected_venue[$venue_field];
                }
            }
        }
    } catch (Throwable $e) {
        // Keep event-level venue fields as fallback.
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $event_name = trim((string)($_POST['event_name'] ?? ''));
    $selected_venue_id = (int)($_POST['venue_id'] ?? 0);
    $venue_name = trim((string)($_POST['venue_name'] ?? ''));
    $venue_address = trim((string)($_POST['venue_address'] ?? ''));
    $venue_postcode = trim((string)($_POST['venue_postcode'] ?? ''));
    $venue_facebook_url = trim((string)($_POST['venue_facebook_url'] ?? ''));
    $venue_website_url = trim((string)($_POST['venue_website_url'] ?? ''));
    $venue_instagram_url = trim((string)($_POST['venue_instagram_url'] ?? ''));
    $venue_ticket_url = trim((string)($_POST['venue_ticket_url'] ?? ''));
    $venue_social_label = trim((string)($_POST['venue_social_label'] ?? ''));
    $event_type = trim((string)($_POST['event_type'] ?? 'public'));
    if ($event_type === 'private') {
        $event_type = 'private_party';
    }
    $allowed_event_types = ['public', 'private_party', 'wedding', 'corporate'];
    if (!in_array($event_type, $allowed_event_types, true)) {
        $event_type = 'public';
    }
    $photo_overlay_theme = trim((string)($_POST['photo_overlay_theme'] ?? 'standard'));
    $allowed_overlay_themes = ['standard', 'wedding_blush'];
    if (!in_array($photo_overlay_theme, $allowed_overlay_themes, true)) {
        $photo_overlay_theme = 'standard';
    }
    if ($event_type === 'wedding' && $photo_overlay_theme === 'standard') {
        $photo_overlay_theme = 'wedding_blush';
    }
    $photo_overlay_title = trim((string)($_POST['photo_overlay_title'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $event_date = trim((string)($_POST['event_date'] ?? ''));
    $start_time = trim((string)($_POST['start_time'] ?? ''));
    $end_time = trim((string)($_POST['end_time'] ?? ''));
    $close_before = (int)($_POST['close_before_end'] ?? 30);
    $is_active = !empty($_POST['is_active']) ? 1 : 0;
    $event_code = trim((string)($_POST['event_code'] ?? ''));
    $event_code = $event_code !== '' ? $event_code : event_unique_code($id);
    $queue_visibility = trim((string)($_POST['queue_visibility'] ?? 'public'));
    $status = trim((string)($_POST['status'] ?? 'scheduled'));
    $public_slug = trim((string)($_POST['public_slug'] ?? ''));
    if ($public_slug === '') {
        $public_slug = event_unique_public_slug($event_name . '-' . $venue_name . '-' . $event_date, $id);
    } else {
        $public_slug = event_unique_public_slug($public_slug, $id);
    }

    if ($event_name === '' || $venue_name === '' || $event_date === '' || $start_time === '') {
        $error = 'Please complete the required event fields.';
    } else {
        $timing_fields = build_event_times($event_date, $start_time, $end_time ?: $start_time, $close_before);
        $requests_close_at = $timing_fields['requests_close_at'];
        $uploaded_image = event_upload_image();


        $saved_venue_id = save_or_update_venue_from_event_form(
            $selected_venue_id,
            $venue_name,
            $venue_address,
            $venue_postcode,
            $venue_facebook_url,
            $venue_website_url,
            $venue_instagram_url,
            $venue_ticket_url,
            $venue_social_label
        );

        $data = [
            'event_name' => $event_name,
            'venue_id' => $saved_venue_id,
            'venue_name' => $venue_name,
            'venue_address' => $venue_address,
            'venue_postcode' => $venue_postcode,
            'venue_facebook_url' => $venue_facebook_url,
            'venue_website_url' => $venue_website_url,
            'venue_instagram_url' => $venue_instagram_url,
            'venue_ticket_url' => $venue_ticket_url,
            'venue_social_label' => $venue_social_label,
            'event_type' => $event_type,
            'photo_overlay_theme' => $photo_overlay_theme,
            'photo_overlay_title' => $photo_overlay_title,
            'notes' => $notes,
            'event_date' => $event_date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'requests_close_at' => $requests_close_at,
            'portal_available_from' => $timing_fields['portal_available_from'],
            'portal_available_until' => $timing_fields['portal_available_until'],
            'is_active' => $is_active,
            'event_code' => $event_code,
            'queue_visibility' => $queue_visibility,
            'status' => $status,
            'public_slug' => $public_slug,
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

        header('Location: events.php');
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
        <a class="touch-btn" href="/events">Back to Events</a>
      </div>
    </div>

    <form method="post" enctype="multipart/form-data" class="event-edit-form restored-event-form">
      <input type="hidden" name="event_code" value="<?= h($event['event_code'] ?? '') ?>">

      <?php if ($error): ?>
        <div class="settings-alert error"><?= h($error) ?></div>
      <?php endif; ?>

      


      




      


      <?php if ($is_edit): ?>
        <?php
          $has_event_code = !empty($event['event_code']);
          $event_request_url = $has_event_code
              ? dttd_public_event_join_url($event['event_code'], 'event')
              : '';
        ?>
        

      <?php endif; ?>





      


      <section class="settings-card event-details-card">
        <div class="settings-card-header">
          <div class="settings-card-icon">▦</div>
          <div>
            <h3>Event Details</h3>
            <p>Name, venue and type of event.</p>
          </div>
        </div>

        <div class="settings-grid">

          <label class="event-name-field">
            <span>Event name *</span>
            <input name="event_name" value="<?= h($event['event_name']) ?>" required>
          </label>

          <label class="event-type-field">
            <span>Event type</span>
            <select name="event_type">
              <option value="public" <?= ($event['event_type'] ?? '') === 'public' ? 'selected' : '' ?>>Public Night</option>
              <option value="private_party" <?= in_array(($event['event_type'] ?? ''), ['private_party', 'private'], true) ? 'selected' : '' ?>>Private Party</option>
              <option value="wedding" <?= ($event['event_type'] ?? '') === 'wedding' ? 'selected' : '' ?>>Wedding</option>
              <option value="corporate" <?= ($event['event_type'] ?? '') === 'corporate' ? 'selected' : '' ?>>Corporate Event</option>
            </select>
          </label>



          <label class="event-overlay-theme-field">
            <span>Photo overlay theme</span>
            <select name="photo_overlay_theme">
              <option value="standard" <?= ($event['photo_overlay_theme'] ?? 'standard') === 'standard' ? 'selected' : '' ?>>Standard event overlay</option>
              <option value="wedding_blush" <?= ($event['photo_overlay_theme'] ?? '') === 'wedding_blush' ? 'selected' : '' ?>>Wedding — blush floral</option>
            </select>
            <small>Wedding events can use a dedicated photo frame overlay.</small>
          </label>

          <label class="event-overlay-title-field">
            <span>Photo overlay wording</span>
            <input name="photo_overlay_title" value="<?= h($event['photo_overlay_title'] ?? '') ?>" placeholder="Example: Emily & James">
            <small>Leave blank to use the event name on framed photo uploads.</small>
          </label>

          <label class="event-notes-field">
            <span>Public event description</span>
            <textarea name="notes" placeholder="Describe the event for the public event detail page"><?= h($event['notes'] ?? '') ?></textarea>
          </label>
        </div>
      </section>
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
<section class="settings-card venue-social-card">
        <div class="settings-card-header">
          <div class="settings-card-icon">⌖</div>
          <div>
            <h3>Venue Details & Social</h3>
            <p>Store venue location and social links for maps, check-ins and public event displays.</p>
          </div>
        </div>

        <div class="settings-grid">
          
          <?php if (venues_table_exists()): ?>
            <label class="venue-select-field">
              <span>Select saved venue</span>
              <select name="venue_id" id="venue_id_select">
                <option value="0">Create new / manual venue</option>
                <?php foreach ($venues_for_select as $saved_venue): ?>
                  <option
                    value="<?= (int)$saved_venue['id'] ?>"
                    data-venue-name="<?= h($saved_venue['venue_name'] ?? '') ?>"
                    data-venue-address="<?= h($saved_venue['venue_address'] ?? '') ?>"
                    data-venue-postcode="<?= h($saved_venue['venue_postcode'] ?? '') ?>"
                    data-venue-facebook="<?= h($saved_venue['venue_facebook_url'] ?? '') ?>"
                    data-venue-website="<?= h($saved_venue['venue_website_url'] ?? '') ?>"
                    data-venue-instagram="<?= h($saved_venue['venue_instagram_url'] ?? '') ?>"
                    data-venue-ticket="<?= h($saved_venue['venue_ticket_url'] ?? '') ?>"
                    data-venue-social-label="<?= h($saved_venue['venue_social_label'] ?? '') ?>"
                    <?= (int)($event['venue_id'] ?? 0) === (int)$saved_venue['id'] ? 'selected' : '' ?>
                  >
                    <?= h($saved_venue['venue_name'] ?? '') ?><?= !empty($saved_venue['venue_postcode']) ? ' — ' . h($saved_venue['venue_postcode']) : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <small>Select an existing venue to auto-fill details, or leave as manual/new venue.</small>
            </label>
          <?php endif; ?>


          
          <label>
            <span>Venue name *</span>
            <input name="venue_name" id="venue_name_input" value="<?= h($event['venue_name']) ?>" required placeholder="Venue or location name">
          </label>

          <label>
            <span>Venue address</span>
            <input name="venue_address" id="venue_address_input" value="<?= h($event['venue_address'] ?? '') ?>" placeholder="Street address or venue location">
          </label>

          <label>
            <span>Postcode</span>
            <input name="venue_postcode" id="venue_postcode_input" value="<?= h($event['venue_postcode'] ?? '') ?>" placeholder="e.g. DY14 0NJ">
            <?php if (!empty($event['venue_postcode'])): ?>
              <small>
                <a href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode(($event['venue_name'] ?? '') . ' ' . ($event['venue_postcode'] ?? '')) ?>" target="_blank" rel="noopener">Open in Google Maps</a>
              </small>
            <?php endif; ?>
          </label>

          <label>
            <span>Venue Facebook URL</span>
            <input type="url" name="venue_facebook_url" id="venue_facebook_url_input" value="<?= h($event['venue_facebook_url'] ?? '') ?>" placeholder="https://facebook.com/...">
          </label>

          <label>
            <span>Venue website URL</span>
            <input type="url" name="venue_website_url" id="venue_website_url_input" value="<?= h($event['venue_website_url'] ?? '') ?>" placeholder="https://...">
          </label>

          <label>
            <span>Venue Instagram URL</span>
            <input type="url" name="venue_instagram_url" id="venue_instagram_url_input" value="<?= h($event['venue_instagram_url'] ?? '') ?>" placeholder="https://instagram.com/...">
          </label>

          
          <label>
            <span>Ticketing URL</span>
            <input type="url" name="venue_ticket_url" id="venue_ticket_url_input" value="<?= h($event['venue_ticket_url'] ?? '') ?>" placeholder="https://tickets.example.com/...">
            <small>Optional link for tickets, booking pages or external event listings.</small>
          </label>

          <label>
            <span>Venue display label</span>
            <input name="venue_social_label" id="venue_social_label_input" value="<?= h($event['venue_social_label'] ?? '') ?>" placeholder="e.g. Follow The Venue">
            <small>Optional short name for public displays, posters or QR pages.</small>
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
<section class="settings-card event-qr-card">
          <div class="settings-card-header">
            <div class="settings-card-icon">▦</div>
            <div>
              <h3>QR Code & Event Code</h3>
              <p>Use this for posters, flyers, table cards and screen displays. Scanning the QR joins the event and opens the event page.</p>
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
                <small><?= h($event_request_url) ?></small><em class="event-code-note">Event code is generated automatically and should only be changed if QR materials have not been printed yet.</em>
              </div>

              <div class="event-qr-preview">
                <canvas class="event-qr-canvas" width="220" height="220" aria-label="Event QR code"></canvas>
              </div>

              <div class="event-qr-actions">
                <a class="touch-btn blue" href="event-qr.php?id=<?= (int)$event['id'] ?>">Open QR Page</a>
                <button type="button" class="touch-btn qr-print-btn">Print QR</button>
                <button type="button" class="touch-btn qr-copy-btn">Copy Link</button>
              </div>
            <?php endif; ?>
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
            <small>Used for the public event join link and QR code.</small>
          </label>

          
          <label>
            <span>Event status</span>
            <select name="status">
              <?php
                $currentStatus = $event['status'] ?? 'scheduled';
                $statuses = [
                  'draft' => 'Draft',
                  'scheduled' => 'Scheduled',
                  'live' => 'Live',
                  'ended' => 'Ended',
                  'cancelled' => 'Cancelled',
                  'private' => 'Private',
                ];
              ?>
              <?php foreach ($statuses as $statusValue => $statusLabel): ?>
                <option value="<?= h($statusValue) ?>" <?= $currentStatus === $statusValue ? 'selected' : '' ?>>
                  <?= h($statusLabel) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small>Cancelled events stay online but show a cancellation notice.</small>
          </label>

          <label>
            <span>Public URL slug</span>
            <input name="public_slug" value="<?= h($event['public_slug'] ?? '') ?>" placeholder="Auto-generated if left blank">
            <small>Optional. Leave blank to auto-generate from event name, venue and date.</small>
          </label>

          <label>
            <span>Queue visibility</span>
            <select name="queue_visibility">
              <option value="public" <?= ($event['queue_visibility'] ?? 'public') === 'public' ? 'selected' : '' ?>>Public</option>
              <option value="private" <?= ($event['queue_visibility'] ?? 'public') === 'private' ? 'selected' : '' ?>>Private</option>
            </select>
          </label>

          <label class="settings-check">
            <input type="checkbox" name="is_active" value="1" <?= !empty($event['is_active']) ? 'checked' : '' ?>>
            <span>Active / available for portal selection</span>
          </label>
        </div>
      </section>
<div class="form-actions">
        <a class="touch-btn" href="/events">Cancel</a>
        <button class="touch-btn blue" type="submit"><?= $is_edit ? 'Save Event' : 'Create Event' ?></button>
      </div>
    </form>
  </section>
</main>

<?php admin_footer(); ?>
