<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../includes/spotify.php';

$allowed_layouts = ['event_left', 'event_right', 'queue_only'];
$current_layout = app_setting('requests_layout', 'event_left');

if (!in_array($current_layout, $allowed_layouts, true)) {
    $current_layout = 'event_left';
}

$header_show_event_timer = app_setting('header_show_event_timer', '1') === '1';
$header_show_request_timer = app_setting('header_show_request_timer', '1') === '1';

$spotify_enabled = app_setting('spotify_enabled', '0') === '1';
$spotify_client_id = app_setting('spotify_client_id', '');
$spotify_secret_saved = trim((string)app_setting('spotify_client_secret', '')) !== '';
$spotify_connected = trim((string)app_setting('spotify_refresh_token', '')) !== '';
$spotify_queue_enabled = app_setting('spotify_queue_enabled', '0') === '1';

$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected = $_POST['requests_layout'] ?? 'event_left';

    if (!in_array($selected, $allowed_layouts, true)) {
        $error = 'Invalid layout selected.';
    } else {
        $ok = true;
        $ok = save_app_setting('requests_layout', $selected) && $ok;
        $new_spotify_client_id = trim((string)($_POST['spotify_client_id'] ?? ''));
        $new_spotify_client_secret = trim((string)($_POST['spotify_client_secret'] ?? ''));
        $new_spotify_enabled = !empty($_POST['spotify_enabled']);
        $new_spotify_queue_enabled = !empty($_POST['spotify_queue_enabled']);

        $ok = save_app_setting('header_show_event_timer', !empty($_POST['header_show_event_timer']) ? '1' : '0') && $ok;
        $ok = save_app_setting('header_show_request_timer', !empty($_POST['header_show_request_timer']) ? '1' : '0') && $ok;
        $ok = save_app_setting('spotify_enabled', $new_spotify_enabled ? '1' : '0') && $ok;
        $ok = save_app_setting('spotify_client_id', $new_spotify_client_id) && $ok;
        $ok = save_app_setting('spotify_queue_enabled', ($new_spotify_enabled && $new_spotify_queue_enabled) ? '1' : '0') && $ok;

        if ($new_spotify_client_secret !== '') {
            $ok = save_app_setting('spotify_client_secret', $new_spotify_client_secret) && $ok;
        }

        if ($ok) {
            $current_layout = $selected;
            $header_show_event_timer = !empty($_POST['header_show_event_timer']);
            $header_show_request_timer = !empty($_POST['header_show_request_timer']);
            $spotify_enabled = $new_spotify_enabled;
            $spotify_client_id = $new_spotify_client_id;
            $spotify_secret_saved = $new_spotify_client_secret !== '' || $spotify_secret_saved;
            $spotify_queue_enabled = $new_spotify_enabled && $new_spotify_queue_enabled;
            $saved = true;
        } else {
            $error = 'Settings could not be saved. Please check the app_settings table exists.';
        }
    }
}

admin_header('Settings - DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Settings</h1>
        <p class="touch-subtitle">Configure the DJ portal display and workflow.</p>
      </div>
    </div>

    <div class="touch-panel-pad">
      <?php if ($saved): ?>
        <div class="settings-alert success">Settings saved.</div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="settings-alert error"><?= h($error) ?></div>
      <?php endif; ?>

      <form method="post" class="settings-form">
        <section class="settings-section">
          <div class="settings-section-header">
            <h2>Header</h2>
            <p>Choose which live timers appear in the admin header.</p>
          </div>

          <div class="settings-toggle-grid">
            <label class="settings-toggle-card">
              <input type="checkbox" name="header_show_event_timer" value="1" <?= $header_show_event_timer ? 'checked' : '' ?>>
              <span>
                <strong>Show event timer</strong>
                <small>Displays the countdown to the current event end time in the header.</small>
              </span>
            </label>

            <label class="settings-toggle-card">
              <input type="checkbox" name="header_show_request_timer" value="1" <?= $header_show_request_timer ? 'checked' : '' ?>>
              <span>
                <strong>Show requests close timer</strong>
                <small>Displays the countdown to when requests close in the header.</small>
              </span>
            </label>
          </div>
        </section>

        <section class="settings-section">
          <div class="settings-section-header">
            <h2>Requests Page Layout</h2>
            <p>Choose how the Requests screen should be arranged for the DJ view.</p>
          </div>

          <div class="layout-radio-grid">
            <label class="layout-radio-card <?= $current_layout === 'event_left' ? 'selected' : '' ?>">
              <input type="radio" name="requests_layout" value="event_left" <?= $current_layout === 'event_left' ? 'checked' : '' ?>>
              <span class="layout-preview layout-preview-left">
                <span class="preview-small"></span>
                <span class="preview-large"></span>
              </span>
              <strong>Event left, queue right</strong>
              <small>Event summary on the left and request queue on the right.</small>
            </label>

            <label class="layout-radio-card <?= $current_layout === 'event_right' ? 'selected' : '' ?>">
              <input type="radio" name="requests_layout" value="event_right" <?= $current_layout === 'event_right' ? 'checked' : '' ?>>
              <span class="layout-preview layout-preview-right">
                <span class="preview-large"></span>
                <span class="preview-small"></span>
              </span>
              <strong>Queue left, event right</strong>
              <small>Request queue gets priority on the left with event summary on the right.</small>
            </label>

            <label class="layout-radio-card <?= $current_layout === 'queue_only' ? 'selected' : '' ?>">
              <input type="radio" name="requests_layout" value="queue_only" <?= $current_layout === 'queue_only' ? 'checked' : '' ?>>
              <span class="layout-preview layout-preview-only">
                <span class="preview-large"></span>
              </span>
              <strong>Queue only</strong>
              <small>Hide the event summary card and use the full width for requests.</small>
            </label>
          </div>
        </section>

        <section class="settings-section spotify-settings-section">
          <div class="settings-section-header">
            <h2>Spotify Integration</h2>
            <p>Optional Spotify tools for track search, artwork, duplicate matching and sending requests to the DJ Spotify queue.</p>
          </div>

          <div class="spotify-settings-body">
            <label class="settings-toggle-card spotify-main-toggle">
              <input type="checkbox" name="spotify_enabled" value="1" <?= $spotify_enabled ? 'checked' : '' ?>>
              <span>
                <strong>Enable Spotify integration</strong>
                <small>When enabled, requests can use Spotify search and matched tracks can be sent to Spotify.</small>
              </span>
            </label>

            <div class="spotify-settings-grid">
              <label>
                <span>Spotify Client ID</span>
                <input type="text" name="spotify_client_id" value="<?= h($spotify_client_id) ?>" autocomplete="off" placeholder="Paste Client ID">
              </label>

              <label>
                <span>Spotify Client Secret</span>
                <input type="password" name="spotify_client_secret" value="" autocomplete="new-password" placeholder="<?= $spotify_secret_saved ? '•••••••• saved — leave blank to keep' : 'Paste Client Secret' ?>">
                <small><?= $spotify_secret_saved ? 'A secret is already saved. Enter a new one only if you want to replace it.' : 'Secret is not saved yet.' ?></small>
              </label>
            </div>

            <label class="settings-toggle-card spotify-main-toggle">
              <input type="checkbox" name="spotify_queue_enabled" value="1" <?= $spotify_queue_enabled ? 'checked' : '' ?>>
              <span>
                <strong>Enable Spotify queue controls</strong>
                <small>Shows DJ-only queue buttons when Spotify is configured and an account has been connected.</small>
              </span>
            </label>

            <div class="spotify-status-card">
              <div>
                <strong>Connected Spotify account</strong>
                <span class="spotify-status-pill <?= $spotify_connected ? 'connected' : 'not-connected' ?>">
                  <?= $spotify_connected ? 'Connected' : 'Not connected' ?>
                </span>
                <small>Use the Spotify Tools page to connect or reconnect the DJ Spotify account for playback and queue control.</small>
              </div>
              <a class="touch-btn green" href="spotify/">Spotify Tools</a>
            </div>
          </div>
        </section>

        <div class="settings-actions">
          <button class="touch-btn blue" type="submit">Save Settings</button>
        </div>
      </form>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
