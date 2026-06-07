<?php
require_once __DIR__ . '/_auth.php';


function dttd_admin_setting_get($key, $default = '') {
    try {
        $stmt = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([(string)$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? (string)$default : (string)$value;
    } catch (Throwable $e) {
        return (string)$default;
    }
}

function dttd_admin_setting_set($key, $value) {
    $stmt = db()->prepare("
        INSERT INTO app_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");
    $stmt->execute([(string)$key, (string)$value]);
}

function dttd_admin_display_final_stretch_settings() {
    return [
        'enabled' => dttd_admin_setting_get('display_final_stretch_enabled', '1') === '1',
        'trigger' => dttd_admin_setting_get('display_final_stretch_trigger', 'requests_closed_or_30_minutes'),
        'duration_seconds' => (int)dttd_admin_setting_get('display_final_stretch_duration_seconds', '10'),
        'hide_low_priority' => dttd_admin_setting_get('display_final_stretch_hide_low_priority', '1') === '1',
    ];
}

function dttd_admin_display_final_stretch_save($posted) {
    $enabled = !empty($posted['enabled']) ? '1' : '0';
    $trigger = (string)($posted['trigger'] ?? 'requests_closed_or_30_minutes');
    if (!in_array($trigger, ['requests_closed_or_30_minutes', 'requests_closed', '30_minutes', '15_minutes'], true)) {
        $trigger = 'requests_closed_or_30_minutes';
    }

    $duration = (int)($posted['duration_seconds'] ?? 10);
    if (!in_array($duration, [10, 15, 20], true)) {
        $duration = 10;
    }

    $hideLow = !empty($posted['hide_low_priority']) ? '1' : '0';

    dttd_admin_setting_set('display_final_stretch_enabled', $enabled);
    dttd_admin_setting_set('display_final_stretch_trigger', $trigger);
    dttd_admin_setting_set('display_final_stretch_duration_seconds', (string)$duration);
    dttd_admin_setting_set('display_final_stretch_hide_low_priority', $hideLow);
}

function dttd_display_slide_table_exists() {
    static $exists = null;
    if ($exists !== null) return $exists;

    try {
        $stmt = db()->query("SHOW TABLES LIKE 'display_slide_settings'");
        $exists = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function dttd_display_slide_defaults() {
    return [
        'venue' => [
            'label' => 'Venue / hosts',
            'description' => 'Venue thank-you and host QR/social details.',
            'enabled' => 1,
            'duration_preset' => 'long',
            'duration_seconds' => 20,
            'priority' => 'low',
            'weight' => 1,
            'sort_order' => 10,
        ],
        'qr' => [
            'label' => 'Event QR code',
            'description' => 'Main guest QR code for event requests, photos and event page.',
            'enabled' => 1,
            'duration_preset' => 'long',
            'duration_seconds' => 20,
            'priority' => 'high',
            'weight' => 3,
            'sort_order' => 20,
        ],
        'event_timer' => [
            'label' => 'Keep dancing timer',
            'description' => 'Event countdown and request countdown.',
            'enabled' => 1,
            'duration_preset' => 'long',
            'duration_seconds' => 20,
            'priority' => 'high',
            'weight' => 3,
            'sort_order' => 30,
        ],
        'music_board' => [
            'label' => 'Request board',
            'description' => 'Active request queue and played requests.',
            'enabled' => 1,
            'duration_preset' => 'medium',
            'duration_seconds' => 15,
            'priority' => 'high',
            'weight' => 3,
            'sort_order' => 40,
        ],
        'now_playing' => [
            'label' => 'Now playing',
            'description' => 'Current track on the active deck.',
            'enabled' => 1,
            'duration_preset' => 'medium',
            'duration_seconds' => 15,
            'priority' => 'normal',
            'weight' => 2,
            'sort_order' => 50,
        ],
        'up_next' => [
            'label' => 'Up next',
            'description' => 'Loaded track ready on the other deck.',
            'enabled' => 1,
            'duration_preset' => 'medium',
            'duration_seconds' => 15,
            'priority' => 'normal',
            'weight' => 2,
            'sort_order' => 60,
        ],
        'recent' => [
            'label' => 'What we’ve played',
            'description' => 'Recently played track history.',
            'enabled' => 1,
            'duration_preset' => 'medium',
            'duration_seconds' => 15,
            'priority' => 'normal',
            'weight' => 2,
            'sort_order' => 70,
        ],
        'requests' => [
            'label' => 'DJ playlist / coming up',
            'description' => 'DJ playlist plus request fill-in where available.',
            'enabled' => 1,
            'duration_preset' => 'medium',
            'duration_seconds' => 15,
            'priority' => 'normal',
            'weight' => 2,
            'sort_order' => 80,
        ],
        'photos' => [
            'label' => 'Photos',
            'description' => 'Approved guest photos.',
            'enabled' => 1,
            'duration_preset' => 'medium',
            'duration_seconds' => 15,
            'priority' => 'normal',
            'weight' => 2,
            'sort_order' => 90,
        ],
        'partners' => [
            'label' => 'Partners',
            'description' => 'Event friends / partner promotion.',
            'enabled' => 1,
            'duration_preset' => 'long',
            'duration_seconds' => 20,
            'priority' => 'low',
            'weight' => 1,
            'sort_order' => 100,
        ],
        'upcoming' => [
            'label' => 'What’s happening',
            'description' => 'Current and upcoming public events.',
            'enabled' => 1,
            'duration_preset' => 'long',
            'duration_seconds' => 20,
            'priority' => 'low',
            'weight' => 1,
            'sort_order' => 110,
        ],
        'sponsors' => [
            'label' => 'Sponsors',
            'description' => 'Event sponsor display where configured.',
            'enabled' => 1,
            'duration_preset' => 'long',
            'duration_seconds' => 20,
            'priority' => 'low',
            'weight' => 1,
            'sort_order' => 120,
        ],
    ];
}

function dttd_display_slide_priority_weight($priority) {
    $priority = strtolower((string)$priority);
    if ($priority === 'high') return 3;
    if ($priority === 'low') return 1;
    return 2;
}

function dttd_display_slide_duration_seconds($preset) {
    $preset = strtolower((string)$preset);
    if ($preset === 'short') return 10;
    if ($preset === 'long') return 20;
    return 15;
}

function dttd_display_slide_fetch_settings() {
    $defaults = dttd_display_slide_defaults();
    if (!dttd_display_slide_table_exists()) {
        return $defaults;
    }

    try {
        $rows = db()->query("SELECT * FROM display_slide_settings ORDER BY sort_order ASC, id ASC")->fetchAll();
        foreach ($rows as $row) {
            $key = (string)($row['slide_key'] ?? '');
            if ($key === '' || !isset($defaults[$key])) continue;

            $defaults[$key]['label'] = (string)($row['slide_label'] ?? $defaults[$key]['label']);
            $defaults[$key]['enabled'] = !empty($row['enabled']) ? 1 : 0;
            $defaults[$key]['duration_preset'] = (string)($row['duration_preset'] ?? $defaults[$key]['duration_preset']);
            $defaults[$key]['duration_seconds'] = (int)($row['duration_seconds'] ?? dttd_display_slide_duration_seconds($defaults[$key]['duration_preset']));
            $defaults[$key]['priority'] = (string)($row['priority'] ?? $defaults[$key]['priority']);
            $defaults[$key]['weight'] = (int)($row['weight'] ?? dttd_display_slide_priority_weight($defaults[$key]['priority']));
            $defaults[$key]['sort_order'] = (int)($row['sort_order'] ?? $defaults[$key]['sort_order']);
        }
    } catch (Throwable $e) {}

    uasort($defaults, function($a, $b) {
        return ((int)$a['sort_order']) <=> ((int)$b['sort_order']);
    });

    return $defaults;
}

function dttd_display_slide_save_settings($settings) {
    if (!dttd_display_slide_table_exists()) {
        return false;
    }

    $sql = "
        INSERT INTO display_slide_settings
            (slide_key, slide_label, enabled, duration_preset, duration_seconds, priority, weight, sort_order, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            slide_label = VALUES(slide_label),
            enabled = VALUES(enabled),
            duration_preset = VALUES(duration_preset),
            duration_seconds = VALUES(duration_seconds),
            priority = VALUES(priority),
            weight = VALUES(weight),
            sort_order = VALUES(sort_order),
            updated_at = NOW()
    ";
    $stmt = db()->prepare($sql);

    foreach ($settings as $key => $setting) {
        $preset = in_array($setting['duration_preset'], ['short','medium','long'], true) ? $setting['duration_preset'] : 'medium';
        $priority = in_array($setting['priority'], ['low','normal','high'], true) ? $setting['priority'] : 'normal';
        $stmt->execute([
            $key,
            $setting['label'],
            !empty($setting['enabled']) ? 1 : 0,
            $preset,
            dttd_display_slide_duration_seconds($preset),
            $priority,
            dttd_display_slide_priority_weight($priority),
            (int)$setting['sort_order'],
        ]);
    }

    return true;
}

$tableReady = dttd_display_slide_table_exists();
$settings = dttd_display_slide_fetch_settings();
$finalStretch = dttd_admin_display_final_stretch_settings();
$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tableReady) {
        $error = 'The display_slide_settings table has not been created yet. Run the SQL first, then save again.';
    } else {
        $posted = $_POST['slides'] ?? [];
        foreach ($settings as $key => &$setting) {
            $row = is_array($posted[$key] ?? null) ? $posted[$key] : [];
            $setting['enabled'] = !empty($row['enabled']) ? 1 : 0;
            $setting['duration_preset'] = in_array(($row['duration_preset'] ?? ''), ['short','medium','long'], true) ? $row['duration_preset'] : $setting['duration_preset'];
            $setting['priority'] = in_array(($row['priority'] ?? ''), ['low','normal','high'], true) ? $row['priority'] : $setting['priority'];
            $setting['sort_order'] = isset($row['sort_order']) ? (int)$row['sort_order'] : (int)$setting['sort_order'];
        }
        unset($setting);

        try {
            dttd_display_slide_save_settings($settings);
            dttd_admin_display_final_stretch_save(is_array($_POST['final_stretch'] ?? null) ? $_POST['final_stretch'] : []);
            $settings = dttd_display_slide_fetch_settings();
            $finalStretch = dttd_admin_display_final_stretch_settings();
            $saved = true;
        } catch (Throwable $e) {
            $error = 'Unable to save display slide settings.';
        }
    }
}

admin_header('Live Display Slides - DJ Portal');
?>

<main class="touch-wrap display-slides-admin">
  <section class="touch-panel">
    <div class="touch-panel-head">
      <div>
        <p class="touch-kicker">Live Display</p>
        <h1>Slide settings</h1>
        <p class="touch-muted">Choose which cards appear on the HDMI/live display, how long they stay on screen, and which cards get repeated more often.</p>
      </div>
      <a class="touch-btn secondary" href="<?= h(admin_url('spotify/index.php')) ?>">Back to tools</a>
    </div>

    <?php if (!$tableReady): ?>
      <div class="touch-alert warning">
        The display slide settings table does not exist yet. Run the SQL supplied with the patch, then reload this page.
      </div>
    <?php endif; ?>

    <?php if ($saved): ?>
      <div class="touch-alert success">Display slide settings saved.</div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="touch-alert danger"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" class="display-slide-settings-form">
      <div class="display-slide-settings-grid">
        <?php foreach ($settings as $key => $slide): ?>
          <article class="display-slide-setting-card">
            <input type="hidden" name="slides[<?= h($key) ?>][sort_order]" value="<?= (int)$slide['sort_order'] ?>">
            <div class="display-slide-setting-main">
              <label class="display-slide-toggle">
                <input type="checkbox" name="slides[<?= h($key) ?>][enabled]" value="1" <?= !empty($slide['enabled']) ? 'checked' : '' ?>>
                <span></span>
              </label>
              <div>
                <h2><?= h($slide['label']) ?></h2>
                <p><?= h($slide['description']) ?></p>
                <code><?= h($key) ?></code>
              </div>
            </div>

            <div class="display-slide-setting-controls">
              <label>
                <span>Duration</span>
                <select name="slides[<?= h($key) ?>][duration_preset]">
                  <option value="short" <?= $slide['duration_preset'] === 'short' ? 'selected' : '' ?>>Short - 10s</option>
                  <option value="medium" <?= $slide['duration_preset'] === 'medium' ? 'selected' : '' ?>>Medium - 15s</option>
                  <option value="long" <?= $slide['duration_preset'] === 'long' ? 'selected' : '' ?>>Long - 20s</option>
                </select>
              </label>

              <label>
                <span>Priority</span>
                <select name="slides[<?= h($key) ?>][priority]">
                  <option value="low" <?= $slide['priority'] === 'low' ? 'selected' : '' ?>>Low</option>
                  <option value="normal" <?= $slide['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
                  <option value="high" <?= $slide['priority'] === 'high' ? 'selected' : '' ?>>High</option>
                </select>
              </label>
            </div>
          </article>
        <?php endforeach; ?>
      </div>


      <section class="display-final-stretch-panel">
        <div>
          <p class="touch-kicker">End of Night</p>
          <h2>Final stretch mode</h2>
          <p>Automatically tighten the display loop near the end of the night. Recommended: start when requests close or 30 minutes before event end, hide low-priority slides, and show the remaining cards for 10 seconds.</p>
        </div>

        <div class="display-final-stretch-grid">
          <label class="display-final-toggle">
            <input type="checkbox" name="final_stretch[enabled]" value="1" <?= !empty($finalStretch['enabled']) ? 'checked' : '' ?>>
            <span></span>
            <strong>Enable final stretch mode</strong>
          </label>

          <label>
            <span>Start when</span>
            <select name="final_stretch[trigger]">
              <option value="requests_closed_or_30_minutes" <?= $finalStretch['trigger'] === 'requests_closed_or_30_minutes' ? 'selected' : '' ?>>Requests close or 30 minutes left</option>
              <option value="requests_closed" <?= $finalStretch['trigger'] === 'requests_closed' ? 'selected' : '' ?>>Requests close</option>
              <option value="30_minutes" <?= $finalStretch['trigger'] === '30_minutes' ? 'selected' : '' ?>>30 minutes before event end</option>
              <option value="15_minutes" <?= $finalStretch['trigger'] === '15_minutes' ? 'selected' : '' ?>>15 minutes before event end</option>
            </select>
          </label>

          <label>
            <span>Final stretch duration</span>
            <select name="final_stretch[duration_seconds]">
              <option value="10" <?= (int)$finalStretch['duration_seconds'] === 10 ? 'selected' : '' ?>>10 seconds</option>
              <option value="15" <?= (int)$finalStretch['duration_seconds'] === 15 ? 'selected' : '' ?>>15 seconds</option>
              <option value="20" <?= (int)$finalStretch['duration_seconds'] === 20 ? 'selected' : '' ?>>20 seconds</option>
            </select>
          </label>

          <label class="display-final-toggle">
            <input type="checkbox" name="final_stretch[hide_low_priority]" value="1" <?= !empty($finalStretch['hide_low_priority']) ? 'checked' : '' ?>>
            <span></span>
            <strong>Hide low-priority slides</strong>
          </label>
        </div>
      </section>

      <div class="display-slide-actions">
        <button class="touch-btn primary" type="submit" <?= !$tableReady ? 'disabled' : '' ?>>Save slide settings</button>
      </div>
    </form>
  </section>
</main>

<?php admin_footer(); ?>
