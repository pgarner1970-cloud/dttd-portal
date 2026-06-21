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

$settings_flash = $_SESSION['settings_flash'] ?? '';
$settings_debug_error = '';
unset($_SESSION['settings_flash']);

function dttd_settings_table_has_column($table, $column) {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $column = (string)$column;
    if ($table === '' || $column === '') return false;
    if (!isset($cache[$table])) {
        try {
            $rows = db()->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll();
            $cache[$table] = [];
            foreach ($rows as $row) {
                if (!empty($row['Field'])) $cache[$table][$row['Field']] = true;
            }
        } catch (Throwable $e) {
            $cache[$table] = [];
        }
    }
    return isset($cache[$table][$column]);
}

function dttd_default_spotify_profiles() {
    return [
        1 => ['id' => null, 'label' => 'Account 1', 'account_email' => '', 'spotify_user_id' => '', 'spotify_display_name' => '', 'use_for_deck_a' => 1, 'use_for_deck_b' => 0, 'use_for_public_search' => 0, 'enabled' => 1, 'refresh_token' => '', 'granted_scopes' => '', 'connected_email' => ''],
        2 => ['id' => null, 'label' => 'Account 2', 'account_email' => '', 'spotify_user_id' => '', 'spotify_display_name' => '', 'use_for_deck_a' => 0, 'use_for_deck_b' => 1, 'use_for_public_search' => 0, 'enabled' => 1, 'refresh_token' => '', 'granted_scopes' => '', 'connected_email' => ''],
        3 => ['id' => null, 'label' => 'Account 3', 'account_email' => '', 'spotify_user_id' => '', 'spotify_display_name' => '', 'use_for_deck_a' => 0, 'use_for_deck_b' => 0, 'use_for_public_search' => 1, 'enabled' => 0, 'refresh_token' => '', 'granted_scopes' => '', 'connected_email' => ''],
    ];
}

function dttd_spotify_profile_columns_for_settings() {
    return [
        'profile_slot','label','account_email','role','enabled','use_for_deck_a','use_for_deck_b','use_for_public_search',
        'refresh_token','granted_scopes','spotify_user_id','spotify_display_name','access_token','expires_at'
    ];
}

function dttd_profile_row_value(array $row, $key, $default = '') {
    return array_key_exists($key, $row) ? $row[$key] : $default;
}

function dttd_load_spotify_profiles() {
    $profiles = dttd_default_spotify_profiles();

    try {
        $rows = db()->query('SELECT * FROM spotify_profiles ORDER BY ' . (dttd_settings_table_has_column('spotify_profiles', 'profile_slot') ? 'COALESCE(profile_slot, id), id' : 'id') . ' ASC')->fetchAll();
        $fallbackSlot = 1;
        foreach ($rows as $row) {
            $slot = 0;
            if (dttd_settings_table_has_column('spotify_profiles', 'profile_slot') && !empty($row['profile_slot'])) {
                $slot = (int)$row['profile_slot'];
            }
            if ($slot < 1 || $slot > 3) {
                while ($fallbackSlot <= 3 && !empty($profiles[$fallbackSlot]['id'])) {
                    $fallbackSlot++;
                }
                $slot = $fallbackSlot;
                $fallbackSlot++;
            }
            if ($slot < 1 || $slot > 3) {
                continue;
            }
            $connectedEmail = trim((string)dttd_profile_row_value($row, 'account_email', ''));
            $displayName = trim((string)dttd_profile_row_value($row, 'spotify_display_name', ''));
            $spotifyUserId = trim((string)dttd_profile_row_value($row, 'spotify_user_id', ''));
            $profiles[$slot] = array_merge($profiles[$slot], [
                'id' => dttd_profile_row_value($row, 'id', null),
                'label' => dttd_profile_row_value($row, 'label', $profiles[$slot]['label']),
                'account_email' => $connectedEmail,
                'spotify_user_id' => $spotifyUserId,
                'spotify_display_name' => $displayName,
                'use_for_deck_a' => (int)dttd_profile_row_value($row, 'use_for_deck_a', 0),
                'use_for_deck_b' => (int)dttd_profile_row_value($row, 'use_for_deck_b', 0),
                'use_for_public_search' => (int)dttd_profile_row_value($row, 'use_for_public_search', 0),
                'enabled' => (int)dttd_profile_row_value($row, 'enabled', 1),
                'refresh_token' => dttd_profile_row_value($row, 'refresh_token', ''),
                'granted_scopes' => dttd_profile_row_value($row, 'granted_scopes', ''),
                'connected_email' => $connectedEmail !== '' ? $connectedEmail : ($displayName !== '' ? $displayName : $spotifyUserId),
                'profile_slot' => $slot,
            ]);
        }
    } catch (Throwable $e) {
        // Table may not exist yet. The page should still render so SQL can be applied separately.
    }

    return $profiles;
}

function dttd_find_spotify_profile_id_for_slot($slot) {
    $slot = (int)$slot;
    if ($slot < 1 || $slot > 3) {
        return null;
    }

    // Preferred: an existing row explicitly bound to this account slot.
    if (dttd_settings_table_has_column('spotify_profiles', 'profile_slot')) {
        $stmt = db()->prepare('SELECT id FROM spotify_profiles WHERE profile_slot = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$slot]);
        $row = $stmt->fetch();
        if (!empty($row['id'])) {
            return (int)$row['id'];
        }
    }

    // Legacy fallback: before profile_slot existed, the first three rows acted as Account 1/2/3.
    // Bind the selected legacy row to the requested slot so future saves are deterministic.
    $rows = db()->query('SELECT id FROM spotify_profiles ORDER BY id ASC LIMIT 3')->fetchAll();
    if (!empty($rows[$slot - 1]['id'])) {
        $id = (int)$rows[$slot - 1]['id'];
        if (dttd_settings_table_has_column('spotify_profiles', 'profile_slot')) {
            $stmt = db()->prepare('UPDATE spotify_profiles SET profile_slot = ? WHERE id = ?');
            $stmt->execute([$slot, $id]);
        }
        return $id;
    }

    return null;
}

function dttd_spotify_profile_id_is_valid($id) {
    $id = (int)$id;
    if ($id <= 0) {
        return false;
    }
    try {
        $stmt = db()->prepare('SELECT id FROM spotify_profiles WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function dttd_save_spotify_profiles(array $postedProfiles, &$debugError = null, array $roleAssignments = []) {
    try {
        // Ensure Account 1/2/3 role assignments are saved by slot, not by fragile row order.
        // This lets the checkboxes work even after OAuth has inserted/updated rows.
        for ($slot = 1; $slot <= 3; $slot++) {
            $posted = $postedProfiles[$slot] ?? [];
            $label = trim((string)($posted['label'] ?? ('Account ' . $slot)));
            $email = trim((string)($posted['account_email'] ?? ''));
            $enabled = !empty($posted['enabled']) ? 1 : 0;

            // Role assignment is exclusive per role: one account for Deck A, one for Deck B,
            // and one for Public Search. A single account may still hold multiple roles.
            $deckASlot = isset($roleAssignments['deck_a']) ? (int)$roleAssignments['deck_a'] : 0;
            $deckBSlot = isset($roleAssignments['deck_b']) ? (int)$roleAssignments['deck_b'] : 0;
            $publicSearchSlot = isset($roleAssignments['public_search']) ? (int)$roleAssignments['public_search'] : 0;

            $deckA = $deckASlot > 0 ? ($slot === $deckASlot ? 1 : 0) : (!empty($posted['use_for_deck_a']) ? 1 : 0);
            $deckB = $deckBSlot > 0 ? ($slot === $deckBSlot ? 1 : 0) : (!empty($posted['use_for_deck_b']) ? 1 : 0);
            $publicSearch = $publicSearchSlot > 0 ? ($slot === $publicSearchSlot ? 1 : 0) : (!empty($posted['use_for_public_search']) ? 1 : 0);

            if ($slot === 1 || $deckA || $deckB || $publicSearch) {
                $enabled = 1;
            }
            if ($label === '') {
                $label = 'Account ' . $slot;
            }

            $role = ($publicSearch && !$deckA && !$deckB) ? 'public_search' : 'playback';
            $data = [
                'profile_slot' => $slot,
                'label' => $label,
                'account_email' => $email,
                'role' => $role,
                'enabled' => $enabled,
                'use_for_deck_a' => $deckA,
                'use_for_deck_b' => $deckB,
                'use_for_public_search' => $publicSearch,
            ];

            $postedId = isset($posted['id']) ? (int)$posted['id'] : 0;
            $id = dttd_spotify_profile_id_is_valid($postedId) ? $postedId : dttd_find_spotify_profile_id_for_slot($slot);
            if ($id) {
                $sets = [];
                $values = [];
                foreach ($data as $column => $value) {
                    if (dttd_settings_table_has_column('spotify_profiles', $column)) {
                        $sets[] = '`' . $column . '` = ?';
                        $values[] = $value;
                    }
                }
                if ($sets) {
                    $values[] = $id;
                    $stmt = db()->prepare('UPDATE spotify_profiles SET ' . implode(', ', $sets) . ' WHERE id = ?');
                    $stmt->execute($values);
                }
            } else {
                $cols = [];
                $marks = [];
                $values = [];
                foreach ($data as $column => $value) {
                    if (dttd_settings_table_has_column('spotify_profiles', $column)) {
                        $cols[] = '`' . $column . '`';
                        $marks[] = '?';
                        $values[] = $value;
                    }
                }
                if (!$cols) {
                    throw new RuntimeException('spotify_profiles has no writable profile columns.');
                }
                $stmt = db()->prepare('INSERT INTO spotify_profiles (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $marks) . ')');
                $stmt->execute($values);
            }
        }
        return true;
    } catch (Throwable $e) {
        $debugError = $e->getMessage();
        error_log('Spotify profile settings save failed: ' . $e->getMessage());
        return false;
    }
}

$spotify_profiles = dttd_load_spotify_profiles();

function dttd_selected_spotify_role_slot(array $profiles, $field, $fallbackSlot) {
    foreach ($profiles as $slot => $profile) {
        if (!empty($profile[$field])) {
            return (int)$slot;
        }
    }
    return (int)$fallbackSlot;
}

function dttd_spotify_settings_slot_hint($slot) {
    $slot = (int)$slot;
    if ($slot === 3) return 'Optional account slot';
    return 'Assignable account slot';
}

$spotify_role_deck_a_slot = dttd_selected_spotify_role_slot($spotify_profiles, 'use_for_deck_a', 1);
$spotify_role_deck_b_slot = dttd_selected_spotify_role_slot($spotify_profiles, 'use_for_deck_b', 2);
$spotify_role_public_search_slot = dttd_selected_spotify_role_slot($spotify_profiles, 'use_for_public_search', 3);

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

        if ($new_spotify_client_secret !== '') {
            $ok = save_app_setting('spotify_client_secret', $new_spotify_client_secret) && $ok;
        }

        if (!empty($_POST['spotify_profiles']) && is_array($_POST['spotify_profiles'])) {
            $profileSaveError = '';
            $roleAssignments = [
                'deck_a' => isset($_POST['spotify_role_deck_a']) ? (int)$_POST['spotify_role_deck_a'] : 0,
                'deck_b' => isset($_POST['spotify_role_deck_b']) ? (int)$_POST['spotify_role_deck_b'] : 0,
                'public_search' => isset($_POST['spotify_role_public_search']) ? (int)$_POST['spotify_role_public_search'] : 0,
            ];
            $ok = dttd_save_spotify_profiles($_POST['spotify_profiles'], $profileSaveError, $roleAssignments) && $ok;
            if ($profileSaveError !== '') {
                $settings_debug_error = $profileSaveError;
            }
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
            $spotify_profiles = dttd_load_spotify_profiles();
            $_SESSION['settings_flash'] = 'Settings saved. Spotify account role assignments updated.';
            header('Location: settings.php#spotify-accounts');
            exit;
        } else {
            $error = 'Settings could not be saved. Please check the app_settings and spotify_profiles tables exist.' . ($settings_debug_error !== '' ? ' Detail: ' . $settings_debug_error : '');
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

      <?php if ($settings_flash): ?>
        <div class="settings-alert success"><?= h($settings_flash) ?></div>
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
            <p>Turn Spotify features on/off, set the developer app details, then assign connected Spotify accounts to Deck A, Deck B and Public Search.</p>
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
                <small>Developer app Client ID.</small>
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
                <small>Choose Standard Queue for basic queueing or Full DJ Mixer for deck-based control.</small>
              </span>
            </label>

            <div class="spotify-settings-grid spotify-mode-grid">
              <label class="spotify-field-card spotify-mode-card <?= $spotify_queue_mode === 'standard' ? 'selected' : '' ?>">
                <input type="radio" name="spotify_queue_mode" value="standard" <?= $spotify_queue_mode === 'standard' ? 'checked' : '' ?>>
                <strong>Standard Queue</strong>
                <small>One Spotify account/device flow. Starting playback on one device may stop the other because it is the same account.</small>
              </label>

              <label class="spotify-field-card spotify-mode-card <?= $spotify_queue_mode === 'mixer' ? 'selected' : '' ?>">
                <input type="radio" name="spotify_queue_mode" value="mixer" <?= $spotify_queue_mode === 'mixer' ? 'checked' : '' ?>>
                <strong>Full DJ Mixer</strong>
                <small>Use the mixer workflow. Duo-style independent decks are enabled by assigning different accounts to Deck A and Deck B.</small>
              </label>
            </div>

            <div id="spotify-accounts" class="settings-section-header" style="margin-top:1rem;">
              <h2>Spotify Accounts</h2>
              <p>Choose exactly one account for Deck A, one for Deck B and one for Public Search. The same account can be used for more than one role.</p>
            </div>

            <div class="spotify-account-grid">
              <?php foreach ($spotify_profiles as $slot => $profile): ?>
                <div class="spotify-field-card spotify-account-card">
                  <div class="spotify-account-card-head">
                    <strong>Spotify Account <?= (int)$slot ?></strong>
                    <small><?= h(dttd_spotify_settings_slot_hint($slot)) ?></small>
                  </div>

                  <label>Account label / expected username</label>
                  <input type="hidden" name="spotify_profiles[<?= (int)$slot ?>][id]" value="<?= h((string)($profile['id'] ?? '')) ?>">
                  <input class="spotify-settings-input" type="text" name="spotify_profiles[<?= (int)$slot ?>][label]" value="<?= h($profile['label']) ?>" placeholder="Friendly name or expected Spotify username">

                  <input type="hidden" name="spotify_profiles[<?= (int)$slot ?>][account_email]" value="<?= h($profile['account_email']) ?>">
                  <div class="spotify-connected-identity">
                    <small><strong>Connected identity</strong></small>
                    <?php if (trim((string)($profile['spotify_display_name'] ?? '')) !== ''): ?>
                      <small>Display name: <?= h((string)$profile['spotify_display_name']) ?></small>
                    <?php endif; ?>
                    <?php if (trim((string)($profile['spotify_user_id'] ?? '')) !== ''): ?>
                      <small>Spotify user ID: <?= h((string)$profile['spotify_user_id']) ?></small>
                    <?php endif; ?>
                    <?php if (trim((string)($profile['account_email'] ?? '')) !== ''): ?>
                      <small>Email returned by Spotify: <?= h((string)$profile['account_email']) ?></small>
                    <?php endif; ?>
                    <?php if (trim((string)($profile['refresh_token'] ?? '')) === ''): ?>
                      <small>Not connected yet. Use the button below and log into the correct Spotify account on Spotify's page.</small>
                    <?php endif; ?>
                  </div>

                  <div class="spotify-account-connect-row">
                    <?php $profileConnected = trim((string)($profile['refresh_token'] ?? '')) !== ''; ?>
                    <span class="spotify-status-pill <?= $profileConnected ? 'connected' : 'not-connected' ?>">
                      <?= $profileConnected ? 'Connected' : 'Not connected' ?>
                    </span>
                    <a class="touch-btn blue spotify-connect-btn" href="spotify/connect.php?profile_slot=<?= (int)$slot ?>">
                      <?= $profileConnected ? 'Reconnect Account ' . (int)$slot : 'Connect Account ' . (int)$slot ?>
                    </a>
                  </div>
                  <small class="spotify-account-help">This opens Spotify login for this account slot. The portal stores OAuth tokens only, never the Spotify password.</small>

                  <div class="settings-toggle-grid spotify-role-grid">
                    <label class="settings-toggle-card compact-role-toggle">
                      <input type="checkbox" name="spotify_profiles[<?= (int)$slot ?>][enabled]" value="1" <?= !empty($profile['enabled']) ? 'checked' : '' ?> <?= $slot === 1 ? 'disabled' : '' ?>>
                      <span><strong>Enabled</strong></span>
                    </label>
                    <?php if ($slot === 1): ?>
                      <input type="hidden" name="spotify_profiles[1][enabled]" value="1">
                    <?php endif; ?>

                    <label class="settings-toggle-card compact-role-toggle">
                      <input type="radio" name="spotify_role_deck_a" value="<?= (int)$slot ?>" <?= (int)$spotify_role_deck_a_slot === (int)$slot ? 'checked' : '' ?>>
                      <span><strong>Deck A</strong><small>Only one account can control Deck A.</small></span>
                    </label>

                    <label class="settings-toggle-card compact-role-toggle">
                      <input type="radio" name="spotify_role_deck_b" value="<?= (int)$slot ?>" <?= (int)$spotify_role_deck_b_slot === (int)$slot ? 'checked' : '' ?>>
                      <span><strong>Deck B</strong><small>Only one account can control Deck B.</small></span>
                    </label>

                    <label class="settings-toggle-card compact-role-toggle">
                      <input type="radio" name="spotify_role_public_search" value="<?= (int)$slot ?>" <?= (int)$spotify_role_public_search_slot === (int)$slot ? 'checked' : '' ?>>
                      <span><strong>Public Search</strong><small>Only one account can provide public search.</small></span>
                    </label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="spotify-status-card">
              <div>
                <strong>Spotify Tools</strong>
                <span class="spotify-status-pill <?= $spotify_connected ? 'connected' : 'not-connected' ?>">
                  <?= $spotify_connected ? 'Primary connected' : 'Primary not connected' ?>
                </span>
                <small>Use the account Connect buttons above for each Spotify login. Spotify Tools remains available for diagnostics.</small>
              </div>
              <a class="touch-btn green" href="spotify/">Spotify Tools</a>
            </div>
          </div>
        </section>

        <style>
          .spotify-account-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem;}
          .spotify-account-card{display:flex;flex-direction:column;gap:.55rem;}
          .spotify-account-card-head{display:flex;align-items:center;justify-content:space-between;gap:.75rem;}
          .spotify-role-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:.5rem;margin-top:.35rem;}
          .compact-role-toggle{padding:.65rem .7rem;min-height:auto;}
          .compact-role-toggle span strong{font-size:.9rem;}
          .spotify-account-connect-row{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin:.35rem 0 .15rem;}
          .spotify-connect-btn{padding:.7rem .9rem;font-size:.9rem;white-space:nowrap;}
          .spotify-account-help{color:#9ec7ee;line-height:1.35;}
          .spotify-connected-identity{display:grid;gap:.25rem;padding:.65rem .75rem;border:1px solid rgba(96,145,205,.22);border-radius:12px;background:rgba(2,6,23,.28);}
          .spotify-connected-identity small{color:#bcd6f0;line-height:1.35;overflow-wrap:anywhere;}
          @media(max-width:1100px){.spotify-account-grid{grid-template-columns:1fr;}}
        </style>

        <div class="settings-actions">
          <button class="touch-btn blue" type="submit">Save Settings</button>
        </div>
      </form>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
