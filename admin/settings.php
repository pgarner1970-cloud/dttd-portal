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
$spotify_queue_mode = app_setting('spotify_queue_mode', 'standard');
if (!in_array($spotify_queue_mode, ['standard', 'mixer'], true)) {
    $spotify_queue_mode = 'standard';
}

$spotify_account_plan = app_setting('spotify_account_plan', 'standard');
if (!in_array($spotify_account_plan, ['standard', 'duo'], true)) {
    $spotify_account_plan = 'standard';
}

$spotify_primary_label = app_setting('spotify_primary_label', 'Primary DJ Account');
$spotify_primary_email = app_setting('spotify_primary_email', '');
$spotify_deck_a_profile = app_setting('spotify_deck_a_profile', 'primary');
$spotify_deck_b_profile = app_setting('spotify_deck_b_profile', $spotify_account_plan === 'duo' ? 'duo_second' : 'primary');
if (!in_array($spotify_deck_a_profile, ['primary', 'duo_second'], true)) {
    $spotify_deck_a_profile = 'primary';
}
if (!in_array($spotify_deck_b_profile, ['primary', 'duo_second'], true)) {
    $spotify_deck_b_profile = $spotify_account_plan === 'duo' ? 'duo_second' : 'primary';
}

$duo_spotify_profile = dttd_spotify_profile_by_role('duo_second', true);
$duo_spotify_enabled = $duo_spotify_profile ? ((int)($duo_spotify_profile['enabled'] ?? 0) === 1) : false;
$duo_spotify_label = $duo_spotify_profile['label'] ?? 'Duo Account 2';
$duo_spotify_email = $duo_spotify_profile['account_email'] ?? '';

$public_search_source = app_setting('spotify_public_search_source', 'primary');
if (!in_array($public_search_source, ['primary', 'duo_second', 'public_search'], true)) {
    $public_search_source = 'primary';
}

$public_spotify_profile = dttd_spotify_profile_by_role('public_search', true);
$public_spotify_enabled = $public_spotify_profile ? ((int)($public_spotify_profile['enabled'] ?? 0) === 1) : false;
$public_spotify_label = $public_spotify_profile['label'] ?? 'Public Search';
$public_spotify_client_id = $public_spotify_profile['client_id'] ?? '';
$public_spotify_secret_saved = $public_spotify_profile && trim((string)($public_spotify_profile['client_secret'] ?? '')) !== '';

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
        $new_spotify_account_plan = $_POST['spotify_account_plan'] ?? 'standard';
        if (!in_array($new_spotify_account_plan, ['standard', 'duo'], true)) {
            $new_spotify_account_plan = 'standard';
        }
        $new_spotify_primary_label = trim((string)($_POST['spotify_primary_label'] ?? 'Primary DJ Account'));
        $new_spotify_primary_email = trim((string)($_POST['spotify_primary_email'] ?? ''));
        $new_duo_spotify_enabled = !empty($_POST['duo_spotify_enabled']);
        $new_duo_spotify_label = trim((string)($_POST['duo_spotify_label'] ?? 'Duo Account 2'));
        $new_duo_spotify_email = trim((string)($_POST['duo_spotify_email'] ?? ''));
        $new_spotify_deck_a_profile = $_POST['spotify_deck_a_profile'] ?? 'primary';
        $new_spotify_deck_b_profile = $_POST['spotify_deck_b_profile'] ?? ($new_spotify_account_plan === 'duo' ? 'duo_second' : 'primary');
        if (!in_array($new_spotify_deck_a_profile, ['primary', 'duo_second'], true)) {
            $new_spotify_deck_a_profile = 'primary';
        }
        if (!in_array($new_spotify_deck_b_profile, ['primary', 'duo_second'], true)) {
            $new_spotify_deck_b_profile = $new_spotify_account_plan === 'duo' ? 'duo_second' : 'primary';
        }
        if ($new_spotify_account_plan === 'standard') {
            $new_spotify_deck_a_profile = 'primary';
            $new_spotify_deck_b_profile = 'primary';
        }
        $new_public_search_source = $_POST['spotify_public_search_source'] ?? 'primary';
        if (!in_array($new_public_search_source, ['primary', 'duo_second', 'public_search'], true)) {
            $new_public_search_source = 'primary';
        }
        $new_public_spotify_enabled = !empty($_POST['public_spotify_enabled']);
        $new_public_spotify_label = trim((string)($_POST['public_spotify_label'] ?? 'Public Search'));
        $new_public_spotify_client_id = trim((string)($_POST['public_spotify_client_id'] ?? ''));
        $new_public_spotify_client_secret = trim((string)($_POST['public_spotify_client_secret'] ?? ''));
        $new_spotify_enabled = !empty($_POST['spotify_enabled']);
        $new_spotify_queue_enabled = !empty($_POST['spotify_queue_enabled']);
        $new_spotify_queue_mode = $_POST['spotify_queue_mode'] ?? 'standard';
        if (!in_array($new_spotify_queue_mode, ['standard', 'mixer'], true)) {
            $new_spotify_queue_mode = 'standard';
        }

        $ok = save_app_setting('header_show_event_timer', !empty($_POST['header_show_event_timer']) ? '1' : '0') && $ok;
        $ok = save_app_setting('header_show_request_timer', !empty($_POST['header_show_request_timer']) ? '1' : '0') && $ok;
        $ok = save_app_setting('spotify_enabled', $new_spotify_enabled ? '1' : '0') && $ok;
        $ok = save_app_setting('spotify_client_id', $new_spotify_client_id) && $ok;
        $ok = save_app_setting('spotify_queue_enabled', ($new_spotify_enabled && $new_spotify_queue_enabled) ? '1' : '0') && $ok;
        $ok = save_app_setting('spotify_queue_mode', $new_spotify_queue_mode) && $ok;
        $ok = save_app_setting('spotify_account_plan', $new_spotify_account_plan) && $ok;
        $ok = save_app_setting('spotify_primary_label', $new_spotify_primary_label ?: 'Primary DJ Account') && $ok;
        $ok = save_app_setting('spotify_primary_email', $new_spotify_primary_email) && $ok;
        $ok = save_app_setting('spotify_deck_a_profile', $new_spotify_deck_a_profile) && $ok;
        $ok = save_app_setting('spotify_deck_b_profile', $new_spotify_deck_b_profile) && $ok;
        $ok = save_app_setting('spotify_public_search_source', $new_public_search_source) && $ok;

        if ($new_spotify_client_secret !== '') {
            $ok = save_app_setting('spotify_client_secret', $new_spotify_client_secret) && $ok;
        }

        $duoProfileOkToSave = $new_duo_spotify_enabled || $new_duo_spotify_label !== '' || $new_duo_spotify_email !== '';
        if ($duoProfileOkToSave) {
            $ok = dttd_spotify_save_profile_credentials('duo_second', $new_duo_spotify_label ?: 'Duo Account 2', '', null, $new_duo_spotify_enabled) && $ok;
            try {
                $stmt = db()->prepare("UPDATE spotify_profiles SET account_email=? WHERE role='duo_second' ORDER BY id ASC LIMIT 1");
                $ok = $stmt->execute([$new_duo_spotify_email]) && $ok;
            } catch (Throwable $e) {
                // Older spotify_profiles tables may not have account_email yet; account labels still save.
            }
        }

        if ($new_public_spotify_client_id !== '' || $new_public_spotify_client_secret !== '' || $new_public_spotify_enabled) {
            $secretToSave = $new_public_spotify_client_secret !== '' ? $new_public_spotify_client_secret : null;
            $ok = dttd_spotify_save_profile_credentials('public_search', $new_public_spotify_label, $new_public_spotify_client_id, $secretToSave, $new_public_spotify_enabled) && $ok;
        }

        if ($ok) {
            $current_layout = $selected;
            $header_show_event_timer = !empty($_POST['header_show_event_timer']);
            $header_show_request_timer = !empty($_POST['header_show_request_timer']);
            $spotify_enabled = $new_spotify_enabled;
            $spotify_client_id = $new_spotify_client_id;
            $spotify_secret_saved = $new_spotify_client_secret !== '' || $spotify_secret_saved;
            $spotify_queue_enabled = $new_spotify_enabled && $new_spotify_queue_enabled;
            $spotify_queue_mode = $new_spotify_queue_mode;
            $spotify_account_plan = $new_spotify_account_plan;
            $spotify_primary_label = $new_spotify_primary_label ?: 'Primary DJ Account';
            $spotify_primary_email = $new_spotify_primary_email;
            $duo_spotify_enabled = $new_duo_spotify_enabled;
            $duo_spotify_label = $new_duo_spotify_label ?: 'Duo Account 2';
            $duo_spotify_email = $new_duo_spotify_email;
            $spotify_deck_a_profile = $new_spotify_deck_a_profile;
            $spotify_deck_b_profile = $new_spotify_deck_b_profile;
            $public_search_source = $new_public_search_source;
            $public_spotify_enabled = $new_public_spotify_enabled;
            $public_spotify_label = $new_public_spotify_label;
            $public_spotify_client_id = $new_public_spotify_client_id;
            $public_spotify_secret_saved = $new_public_spotify_client_secret !== '' || $public_spotify_secret_saved;
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
              <div class="spotify-field-card">
                <label for="spotify_client_id">Spotify Client ID</label>
                <input id="spotify_client_id" class="spotify-settings-input" type="text" name="spotify_client_id" value="<?= h($spotify_client_id) ?>" autocomplete="off" placeholder="Paste Client ID">
                <small>Public app identifier from your Spotify Developer app.</small>
              </div>

              <div class="spotify-field-card">
                <label for="spotify_client_secret">Spotify Client Secret</label>
                <input id="spotify_client_secret" class="spotify-settings-input" type="password" name="spotify_client_secret" value="" autocomplete="new-password" placeholder="<?= $spotify_secret_saved ? '•••••••• saved — leave blank to keep' : 'Paste Client Secret' ?>">
                <small><?= $spotify_secret_saved ? 'A secret is already saved. Enter a new one only if you want to replace it.' : 'Secret is not saved yet.' ?></small>
              </div>
            </div>

            <label class="settings-toggle-card spotify-main-toggle">
              <input type="checkbox" name="spotify_queue_enabled" value="1" <?= $spotify_queue_enabled ? 'checked' : '' ?>>
              <span>
                <strong>Enable Spotify queue controls</strong>
                <small>Enables DJ-only Spotify queue actions. Choose Standard mode for the device picker, or Full DJ Mixer mode for the mixer workflow.</small>
              </span>
            </label>

            <div class="spotify-settings-grid spotify-mode-grid">
              <label class="spotify-field-card spotify-mode-card <?= $spotify_queue_mode === 'standard' ? 'selected' : '' ?>">
                <input type="radio" name="spotify_queue_mode" value="standard" <?= $spotify_queue_mode === 'standard' ? 'checked' : '' ?>>
                <strong>Standard queue mode</strong>
                <small>Request Queue → choose Spotify device → add track to that device queue.</small>
              </label>

              <label class="spotify-field-card spotify-mode-card <?= $spotify_queue_mode === 'mixer' ? 'selected' : '' ?>">
                <input type="radio" name="spotify_queue_mode" value="mixer" <?= $spotify_queue_mode === 'mixer' ? 'checked' : '' ?>>
                <strong>Full DJ Mixer mode</strong>
                <small>Request Queue → send to Live Mixer DJ Playlist. The mixer controls loading/playing on A or B.</small>
              </label>
            </div>

            <div class="spotify-account-planner">
              <div class="settings-section-header compact">
                <h3>DJ playback account mode</h3>
                <p>Standard keeps the current single-account behaviour. Duo prepares the mixer for one Spotify account per deck so A and B can play at the same time through the external DJ mixer.</p>
              </div>

              <div class="spotify-settings-grid spotify-mode-grid">
                <label class="spotify-field-card spotify-mode-card <?= $spotify_account_plan === 'standard' ? 'selected' : '' ?>">
                  <input type="radio" name="spotify_account_plan" value="standard" <?= $spotify_account_plan === 'standard' ? 'checked' : '' ?>>
                  <strong>Standard Spotify</strong>
                  <small>One Spotify account controls playback. Starting one deck will stop the other, matching the existing logic.</small>
                </label>

                <label class="spotify-field-card spotify-mode-card <?= $spotify_account_plan === 'duo' ? 'selected' : '' ?>">
                  <input type="radio" name="spotify_account_plan" value="duo" <?= $spotify_account_plan === 'duo' ? 'checked' : '' ?>>
                  <strong>Spotify Duo / dual account</strong>
                  <small>Deck A and Deck B can be assigned to different Spotify accounts for simultaneous playback.</small>
                </label>
              </div>

              <div class="spotify-account-grid">
                <div class="spotify-account-card primary">
                  <div class="spotify-account-card-head">
                    <span class="spotify-account-badge">Account 1</span>
                    <strong>Primary DJ playback account</strong>
                  </div>
                  <div class="spotify-settings-grid">
                    <div class="spotify-field-card">
                      <label for="spotify_primary_label">Display label</label>
                      <input id="spotify_primary_label" class="spotify-settings-input" type="text" name="spotify_primary_label" value="<?= h($spotify_primary_label) ?>" autocomplete="off" placeholder="Primary DJ Account">
                      <small>Shown in settings and future deck/device diagnostics.</small>
                    </div>
                    <div class="spotify-field-card">
                      <label for="spotify_primary_email">Spotify login email / note</label>
                      <input id="spotify_primary_email" class="spotify-settings-input" type="text" name="spotify_primary_email" value="<?= h($spotify_primary_email) ?>" autocomplete="off" placeholder="name@example.com">
                      <small>For operator reference only. OAuth tokens are still managed through Spotify Tools.</small>
                    </div>
                  </div>
                </div>

                <div class="spotify-account-card secondary">
                  <div class="spotify-account-card-head">
                    <span class="spotify-account-badge amber">Account 2</span>
                    <strong>Optional Duo deck account</strong>
                  </div>
                  <label class="settings-toggle-card spotify-main-toggle compact-toggle">
                    <input type="checkbox" name="duo_spotify_enabled" value="1" <?= $duo_spotify_enabled ? 'checked' : '' ?>>
                    <span>
                      <strong>Enable second playback account</strong>
                      <small>Use when running Spotify Duo/two Spotify accounts for independent Deck A and Deck B playback.</small>
                    </span>
                  </label>
                  <div class="spotify-settings-grid">
                    <div class="spotify-field-card">
                      <label for="duo_spotify_label">Display label</label>
                      <input id="duo_spotify_label" class="spotify-settings-input" type="text" name="duo_spotify_label" value="<?= h($duo_spotify_label) ?>" autocomplete="off" placeholder="Duo Account 2">
                    </div>
                    <div class="spotify-field-card">
                      <label for="duo_spotify_email">Spotify login email / note</label>
                      <input id="duo_spotify_email" class="spotify-settings-input" type="text" name="duo_spotify_email" value="<?= h($duo_spotify_email) ?>" autocomplete="off" placeholder="duo-account@example.com">
                      <small>Add this email as a user in the same Spotify Developer playback app.</small>
                    </div>
                  </div>
                </div>
              </div>

              <div class="spotify-deck-map-card">
                <div class="settings-section-header compact">
                  <h3>Deck account allocation</h3>
                  <p>In Standard mode both decks stay on Account 1. In Duo mode, the usual setup is Deck A → Account 1 and Deck B → Account 2.</p>
                </div>
                <div class="spotify-deck-map-grid">
                  <div class="spotify-deck-map">
                    <strong>Player A</strong>
                    <label><input type="radio" name="spotify_deck_a_profile" value="primary" <?= $spotify_deck_a_profile === 'primary' ? 'checked' : '' ?>> Account 1</label>
                    <label><input type="radio" name="spotify_deck_a_profile" value="duo_second" <?= $spotify_deck_a_profile === 'duo_second' ? 'checked' : '' ?>> Account 2</label>
                  </div>
                  <div class="spotify-deck-map">
                    <strong>Player B</strong>
                    <label><input type="radio" name="spotify_deck_b_profile" value="primary" <?= $spotify_deck_b_profile === 'primary' ? 'checked' : '' ?>> Account 1</label>
                    <label><input type="radio" name="spotify_deck_b_profile" value="duo_second" <?= $spotify_deck_b_profile === 'duo_second' ? 'checked' : '' ?>> Account 2</label>
                  </div>
                </div>
              </div>
            </div>

            <div class="spotify-settings-grid">
              <div class="spotify-field-card">
                <label for="spotify_public_search_source">Public request search source</label>
                <select id="spotify_public_search_source" class="spotify-settings-input" name="spotify_public_search_source">
                  <option value="primary" <?= $public_search_source === 'primary' ? 'selected' : '' ?>>Account 1 / Primary playback app</option>
                  <option value="duo_second" <?= $public_search_source === 'duo_second' ? 'selected' : '' ?>>Account 2 / Duo account</option>
                  <option value="public_search" <?= $public_search_source === 'public_search' ? 'selected' : '' ?>>Dedicated public-search app</option>
                </select>
                <small>Public search should normally use cache first, then the selected search profile, then fall back safely.</small>
              </div>
            </div>

            <div class="spotify-settings-grid">
              <div class="spotify-field-card">
                <label for="public_spotify_label">Public search profile label</label>
                <input id="public_spotify_label" class="spotify-settings-input" type="text" name="public_spotify_label" value="<?= h($public_spotify_label) ?>" autocomplete="off" placeholder="Public Search">
                <small>Used by the public request site for Spotify search only.</small>
              </div>

              <label class="spotify-field-card spotify-mode-card <?= $public_spotify_enabled ? 'selected' : '' ?>">
                <input type="checkbox" name="public_spotify_enabled" value="1" <?= $public_spotify_enabled ? 'checked' : '' ?>>
                <strong>Use secondary Spotify app for public search</strong>
                <small>If enabled and configured, public requests search with this app first. If unavailable, search falls back to the primary DJ Spotify app, then cache/text-only.</small>
              </label>
            </div>

            <div class="spotify-settings-grid">
              <div class="spotify-field-card">
                <label for="public_spotify_client_id">Public Search Spotify Client ID</label>
                <input id="public_spotify_client_id" class="spotify-settings-input" type="text" name="public_spotify_client_id" value="<?= h($public_spotify_client_id) ?>" autocomplete="off" placeholder="Secondary app Client ID">
                <small>Use a separate Spotify Developer app to keep public search traffic away from DJ playback/control.</small>
              </div>

              <div class="spotify-field-card">
                <label for="public_spotify_client_secret">Public Search Spotify Client Secret</label>
                <input id="public_spotify_client_secret" class="spotify-settings-input" type="password" name="public_spotify_client_secret" value="" autocomplete="new-password" placeholder="<?= $public_spotify_secret_saved ? '•••••••• saved — leave blank to keep' : 'Secondary app Client Secret' ?>">
                <small><?= $public_spotify_secret_saved ? 'A public-search secret is already saved. Enter a new one only if replacing it.' : 'Secret is not saved yet.' ?></small>
              </div>
            </div>

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
