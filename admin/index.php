<?php
require_once __DIR__ . '/_auth.php';

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
        $message = trim($_POST['message'] ?? '');

        if ($guest_name === '' || $song_title === '' || $artist === '') {
            $error = 'Please enter guest name, song title and artist.';
        } else {
            try {
                $stmt = db()->prepare("
                    INSERT INTO song_requests
                    (event_id, guest_name, song_title, artist, message, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NOW())
                ");
                $stmt->execute([
                    (int)$event['id'],
                    $guest_name,
                    $song_title,
                    $artist,
                    $message
                ]);

                $success = 'Test request added.';
            } catch (Throwable $e) {
                // Fallback for older schemas without created_at/updated_at or message.
                try {
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
      <a class="admin-home-card" href="/admin/requests.php">
        <span class="admin-home-icon">♫</span>
        <strong>Requests</strong>
        <span>Live song request queue</span>
      </a>

      <a class="admin-home-card" href="/admin/events.php">
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
        <form method="post" class="test-request-form">
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

          <div class="settings-actions">
            <button class="touch-btn blue" type="submit">Add Test Request</button>
            <a class="touch-btn green" href="/admin/requests.php">View Requests</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
