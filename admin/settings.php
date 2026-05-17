<?php
require_once __DIR__ . '/_auth.php';

$allowed_layouts = ['event_left', 'event_right', 'queue_only'];
$current_layout = app_setting('requests_layout', 'event_left');

if (!in_array($current_layout, $allowed_layouts, true)) {
    $current_layout = 'event_left';
}

$header_show_event_timer = app_setting('header_show_event_timer', '1') === '1';
$header_show_request_timer = app_setting('header_show_request_timer', '1') === '1';

$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected = $_POST['requests_layout'] ?? 'event_left';

    if (!in_array($selected, $allowed_layouts, true)) {
        $error = 'Invalid layout selected.';
    } else {
        $ok = true;
        $ok = save_app_setting('requests_layout', $selected) && $ok;
        $ok = save_app_setting('header_show_event_timer', !empty($_POST['header_show_event_timer']) ? '1' : '0') && $ok;
        $ok = save_app_setting('header_show_request_timer', !empty($_POST['header_show_request_timer']) ? '1' : '0') && $ok;

        if ($ok) {
            $current_layout = $selected;
            $header_show_event_timer = !empty($_POST['header_show_event_timer']);
            $header_show_request_timer = !empty($_POST['header_show_request_timer']);
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

        <div class="settings-actions">
          <button class="touch-btn blue" type="submit">Save Settings</button>
        </div>
      </form>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
