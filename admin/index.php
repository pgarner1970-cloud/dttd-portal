<?php
require_once __DIR__ . '/_auth.php';

function dttd_event_image_column_exists() {
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

function dttd_handle_event_image_upload($field_name = 'event_image_upload') {
    if (!isset($_FILES[$field_name]) || !is_array($_FILES[$field_name])) {
        return null;
    }

    $file = $_FILES[$field_name];

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

    $upload_dir = dirname(__DIR__) . 'https://dancethruthedecades.co.uk/uploads/events';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0775, true);
    }

    $filename = 'event-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $target = $upload_dir . '/' . $filename;

    if (!move_uploaded_file($tmp, $target)) {
        return null;
    }

    return 'https://dancethruthedecades.co.uk/uploads/events/' . $filename;
}



function dttd_index_group_id_column_exists() {
    static $exists = null;
    if ($exists !== null) return $exists;

    try {
        $stmt = db()->query("SHOW COLUMNS FROM song_requests LIKE 'request_group_id'");
        $exists = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function dttd_index_new_request_group_id() {
    try {
        return 'grp_' . bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return 'grp_' . uniqid('', true);
    }
}

function dttd_index_open_group_id_for_request($event_id, $song_title, $artist) {
    if (!dttd_index_group_id_column_exists()) {
        return null;
    }

    $base_key = strtolower(trim((string)$song_title)) . '|' . strtolower(trim((string)$artist));

    $stmt = db()->prepare("
        SELECT request_group_id
        FROM song_requests
        WHERE event_id = ?
        AND request_group_id IS NOT NULL
        AND request_group_id <> ''
        AND status IN ('pending','maybe','duplicate')
        AND CONCAT(LOWER(TRIM(song_title)), '|', LOWER(TRIM(artist))) = ?
        ORDER BY created_at ASC, id ASC
        LIMIT 1
    ");
    $stmt->execute([(int)$event_id, $base_key]);
    $existing = $stmt->fetchColumn();

    return $existing ?: dttd_index_new_request_group_id();
}


function dttd_index_song_request_column_exists($column) {
    static $cache = [];

    if (isset($cache[$column])) {
        return $cache[$column];
    }

    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM song_requests LIKE ?");
        $stmt->execute([$column]);
        $cache[$column] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $cache[$column] = false;
    }

    return $cache[$column];
}

$success = '';
$error = '';

$event = function_exists('dttd_get_calculated_current_event') ? dttd_get_calculated_current_event() : null;

if (!$event) {
    $event = db()->query("
        SELECT *
        FROM events
        ORDER BY event_date ASC, start_time ASC, id ASC
        LIMIT 1
    ")->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_test_request') {
    if (!$event) {
        $error = 'No event exists to add a test request to.';
    } else {
        $guest_name = trim($_POST['guest_name'] ?? '');
        $song_title = trim($_POST['song_title'] ?? '');
        $artist = trim($_POST['artist'] ?? '');
        $message = trim((string)($_POST['message'] ?? $_POST['dedication'] ?? $_POST['test_message'] ?? ''));

        if ($guest_name === '' || $song_title === '' || $artist === '') {
            $error = 'Please enter guest name, song title and artist.';
        } else {
            try {
                $columns = ['event_id', 'guest_name', 'song_title', 'artist', 'status'];
                $values = [(int)$event['id'], $guest_name, $song_title, $artist, 'pending'];

                if (dttd_index_song_request_column_exists('message')) {
                    $columns[] = 'message';
                    $values[] = $message;
                }

                if (dttd_index_song_request_column_exists('dedication')) {
                    $columns[] = 'dedication';
                    $values[] = $message;
                }

                $spotify_fields = [
                    'spotify_track_id' => trim($_POST['spotify_track_id'] ?? ''),
                    'spotify_track_url' => trim($_POST['spotify_track_url'] ?? ''),
                    'spotify_artist_name' => trim($_POST['spotify_artist_name'] ?? ''),
                    'spotify_album_image' => trim($_POST['spotify_album_image'] ?? ''),
                    'request_source' => ($_POST['request_source'] ?? '') === 'spotify' ? 'spotify' : 'manual',
                ];

                foreach ($spotify_fields as $column => $value) {
                    if (dttd_index_song_request_column_exists($column)) {
                        $columns[] = $column;
                        $values[] = $value;
                    }
                }

                if (dttd_index_group_id_column_exists()) {
                    $request_group_id = dttd_index_open_group_id_for_request((int)$event['id'], $song_title, $artist);
                    $columns[] = 'request_group_id';
                    $values[] = $request_group_id;
                }

                if (dttd_index_song_request_column_exists('created_at')) {
                    $columns[] = 'created_at';
                    $values[] = date('Y-m-d H:i:s');
                }

                if (dttd_index_song_request_column_exists('updated_at')) {
                    $columns[] = 'updated_at';
                    $values[] = date('Y-m-d H:i:s');
                }

                $placeholders = array_fill(0, count($columns), '?');

                $stmt = db()->prepare(
                    "INSERT INTO song_requests (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")"
                );
                $stmt->execute($values);

                $success = 'Test request added.';
            } catch (Throwable $e) {
                // Fallback for older schemas without created_at/updated_at or message.
                try {
                    if (dttd_index_group_id_column_exists()) {
                        $request_group_id = dttd_index_open_group_id_for_request((int)$event['id'], $song_title, $artist);

                        $stmt = db()->prepare("
                            INSERT INTO song_requests
                            (event_id, guest_name, song_title, artist, status, request_group_id)
                            VALUES (?, ?, ?, ?, 'pending')
                        ");
                        $stmt->execute([
                            (int)$event['id'],
                            $guest_name,
                            $song_title,
                            $artist,
                            $request_group_id
                        ]);
                    } else {
                        $stmt = db()->prepare("
                            INSERT INTO song_requests
                            (event_id, guest_name, song_title, artist, status)
                            VALUES (?, ?, ?, ?, 'pending')
                        ");
                        $stmt->execute([
                            (int)$event['id'],
                            $guest_name,
                            $song_title,
                            $artist
                        ]);
                    }

                    $success = 'Test request added.';
                } catch (Throwable $e2) {
                    $error = 'Could not add test request. Please check the song_requests table columns.';
                }
            }
        }
    }
}

admin_header('DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">DJ Portal</h1>
        <p class="touch-subtitle">Quick admin tools for testing and managing the portal.</p>
      </div>
    </div>

    <div class="admin-home-grid">
      <a class="admin-home-card" href="requests.php">
        <span class="admin-home-icon">♫</span>
        <strong>Requests</strong>
        <span>Live song request queue</span>
      </a>

      <a class="admin-home-card" href="events.php">
        <span class="admin-home-icon">▦</span>
        <strong>Events</strong>
        <span>Create, edit and review events</span>
      </a>
      <a class="admin-home-card" href="/admin/request-debug.php">
        <span class="admin-home-icon">◎</span>
        <strong>Queue Debug</strong>
        <span>Diagnose request update polling</span>
      </a>
    </div>
  </section>

  <section class="touch-panel test-request-panel">
    <div class="touch-panel-header">
      <div>
        <h2 class="touch-panel-title">Add Test Request</h2>
        <p class="touch-subtitle">
          Adds a pending test request to <?= $event ? h($event['event_name']) : 'the current event' ?>.
        </p>
      </div>
    </div>

    <div class="touch-panel-pad">
      <?php if ($success): ?>
        <div class="settings-alert success"><?= h($success) ?></div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="settings-alert error"><?= h($error) ?></div>
      <?php endif; ?>

      <?php if (!$event): ?>
        <p class="muted-text">No event exists yet. Create an event before adding test requests.</p>
      <?php else: ?>
        <form method="post" class="test-request-form" enctype="multipart/form-data" data-spotify-request-form>
          <input type="hidden" name="action" value="add_test_request">

          <div class="test-request-grid">
            <label>
              <span>Guest name</span>
              <input name="guest_name" value="Tester" required>
            </label>

            <label>
              <span>Song title</span>
              <input name="song_title" placeholder="Take On Me" required>
            </label>

            <label>
              <span>Artist</span>
              <input name="artist" placeholder="A-ha" required>
            </label>

            <label>
              <span>Message / dedication</span>
              <input name="message" placeholder="Optional test message">
            </label>
          </div>

          <input type="hidden" name="spotify_track_id">
          <input type="hidden" name="spotify_track_url">
          <input type="hidden" name="spotify_artist_name">
          <input type="hidden" name="spotify_album_image">
          <input type="hidden" name="request_source" value="manual">

          <div class="spotify-search-box">
            <small class="spotify-search-status" data-spotify-status></small>
            <div class="spotify-results" data-spotify-results hidden></div>
            <div class="spotify-selected" data-spotify-selected hidden></div>
          </div>

          <div class="settings-actions">
            <button class="touch-btn blue" type="submit">Add Test Request</button>
            <a class="touch-btn green" href="requests.php">View Requests</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </section>
</main>
<script src="/assets/spotify-request-search.js?v=1"></script>
<?php admin_footer(); ?>
