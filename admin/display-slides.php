<?php
require_once __DIR__ . '/_auth.php';

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
            $settings = dttd_display_slide_fetch_settings();
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

      <div class="display-slide-actions">
        <button class="touch-btn primary" type="submit" <?= !$tableReady ? 'disabled' : '' ?>>Save slide settings</button>
      </div>
    </form>
  </section>
</main>

<?php admin_footer(); ?>
