<?php
require_once __DIR__ . '/_auth.php';

function event_sponsors_table_exists($table) {
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        $cache[$table] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

function event_sponsors_column_exists($column) {
    static $cache = [];

    if (isset($cache[$column])) {
        return $cache[$column];
    }

    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM event_sponsors LIKE ?");
        $stmt->execute([$column]);
        $cache[$column] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $cache[$column] = false;
    }

    return $cache[$column];
}

function event_sponsors_required_tables_ready() {
    return event_sponsors_table_exists('events')
        && event_sponsors_table_exists('sponsors')
        && event_sponsors_table_exists('event_sponsors');
}

function event_sponsors_event_label($event) {
    $parts = [];

    if (!empty($event['event_date'])) {
        $parts[] = date('d/m/Y', strtotime($event['event_date']));
    }

    $parts[] = $event['event_name'] ?? 'Event';

    if (!empty($event['venue_name'])) {
        $parts[] = $event['venue_name'];
    }

    return implode(' · ', array_filter($parts));
}

$error = '';
$success = '';
$editing = null;

if (!event_sponsors_required_tables_ready()) {
    $missing = [];
    foreach (['events', 'sponsors', 'event_sponsors'] as $table) {
        if (!event_sponsors_table_exists($table)) {
            $missing[] = $table;
        }
    }
    $error = 'Missing database table' . (count($missing) === 1 ? '' : 's') . ': ' . implode(', ', $missing) . '. Run the SQL supplied with this patch before assigning sponsors.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && event_sponsors_required_tables_ready()) {
    $action = $_POST['action'] ?? 'save_assignment';

    if ($action === 'delete_assignment') {
        $assignment_id = (int)($_POST['assignment_id'] ?? 0);

        if ($assignment_id > 0) {
            try {
                $stmt = db()->prepare("DELETE FROM event_sponsors WHERE id = ?");
                $stmt->execute([$assignment_id]);
                $success = 'Event sponsor assignment removed.';
            } catch (Throwable $e) {
                $error = 'Could not remove event sponsor assignment.';
            }
        }
    } else {
        $assignment_id = (int)($_POST['assignment_id'] ?? 0);
        $data = [
            'event_id' => (int)($_POST['event_id'] ?? 0),
            'sponsor_id' => (int)($_POST['sponsor_id'] ?? 0),
            'sponsor_title' => trim((string)($_POST['sponsor_title'] ?? '')),
            'sponsor_offer' => trim((string)($_POST['sponsor_offer'] ?? '')),
            'sponsor_image_url' => trim((string)($_POST['sponsor_image_url'] ?? '')),
            'website_url' => trim((string)($_POST['website_url'] ?? '')),
            'display_on_public' => isset($_POST['display_on_public']) ? 1 : 0,
            'sort_order' => (int)($_POST['sort_order'] ?? 100),
        ];

        if ($data['event_id'] <= 0) {
            $error = 'Choose an event.';
        } elseif ($data['sponsor_id'] <= 0) {
            $error = 'Choose a sponsor.';
        } else {
            try {
                $save_data = array_filter(
                    $data,
                    fn($value, $column) => event_sponsors_column_exists($column),
                    ARRAY_FILTER_USE_BOTH
                );

                if ($assignment_id > 0) {
                    $sets = [];
                    $params = [];

                    foreach ($save_data as $column => $value) {
                        $sets[] = "{$column} = ?";
                        $params[] = $value;
                    }

                    if ($sets) {
                        $params[] = $assignment_id;
                        $stmt = db()->prepare("UPDATE event_sponsors SET " . implode(', ', $sets) . " WHERE id = ?");
                        $stmt->execute($params);
                    }

                    $success = 'Event sponsor assignment updated.';
                } else {
                    $columns = array_keys($save_data);
                    $placeholders = array_fill(0, count($columns), '?');
                    $stmt = db()->prepare(
                        "INSERT INTO event_sponsors (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")"
                    );
                    $stmt->execute(array_values($save_data));
                    $success = 'Event sponsor assignment added.';
                }
            } catch (Throwable $e) {
                $error = 'Could not save event sponsor assignment.';
            }
        }
    }
}

$events = [];
$sponsors = [];
$assignments = [];

if (event_sponsors_required_tables_ready()) {
    try {
        $events = db()->query("
            SELECT id, event_name, event_date, start_time, venue_name
            FROM events
            ORDER BY event_date DESC, start_time DESC, id DESC
        ")->fetchAll();
    } catch (Throwable $e) {
        $error = 'Could not load events.';
    }

    try {
        $sponsors = db()->query("
            SELECT id, sponsor_name, default_offer, logo_url, website_url, is_active, sort_order
            FROM sponsors
            WHERE is_active = 1
            ORDER BY sort_order ASC, sponsor_name ASC, id ASC
        ")->fetchAll();
    } catch (Throwable $e) {
        $error = 'Could not load sponsors.';
    }

    try {
        $assignments = db()->query("
            SELECT es.*, e.event_name, e.event_date, e.start_time, e.venue_name,
                   s.sponsor_name, s.default_offer, s.logo_url, s.website_url AS sponsor_website_url
            FROM event_sponsors es
            INNER JOIN events e ON e.id = es.event_id
            INNER JOIN sponsors s ON s.id = es.sponsor_id
            ORDER BY e.event_date DESC, e.start_time DESC, es.sort_order ASC, s.sponsor_name ASC, es.id ASC
        ")->fetchAll();
    } catch (Throwable $e) {
        $error = 'Could not load event sponsor assignments.';
    }

    $edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
    if ($edit_id > 0) {
        foreach ($assignments as $assignment) {
            if ((int)$assignment['id'] === $edit_id) {
                $editing = $assignment;
                break;
            }
        }
    }
}

$form = [
    'id' => 0,
    'event_id' => '',
    'sponsor_id' => '',
    'sponsor_title' => '',
    'sponsor_offer' => '',
    'sponsor_image_url' => '',
    'website_url' => '',
    'display_on_public' => 1,
    'sort_order' => 100,
];

if ($editing) {
    $form = array_merge($form, $editing);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete_assignment' && $error) {
    $form = array_merge($form, [
        'event_id' => $_POST['event_id'] ?? '',
        'sponsor_id' => $_POST['sponsor_id'] ?? '',
        'sponsor_title' => $_POST['sponsor_title'] ?? '',
        'sponsor_offer' => $_POST['sponsor_offer'] ?? '',
        'sponsor_image_url' => $_POST['sponsor_image_url'] ?? '',
        'website_url' => $_POST['website_url'] ?? '',
        'display_on_public' => isset($_POST['display_on_public']) ? 1 : 0,
        'sort_order' => $_POST['sort_order'] ?? 100,
    ]);
}

admin_header('Event Sponsors - DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Event Sponsors</h1>
        <p class="touch-subtitle">Assign sponsors, prizes and promotional wording to individual events.</p>
      </div>
      <div class="settings-actions">
        <a class="touch-btn ghost" href="tools.php">← Admin Tools</a>
        <a class="touch-btn" href="sponsors.php">Sponsors</a>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="settings-alert error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="settings-alert success"><?= h($success) ?></div>
    <?php endif; ?>

    <form method="post" class="venue-edit-form">
      <input type="hidden" name="action" value="save_assignment">
      <input type="hidden" name="assignment_id" value="<?= (int)$form['id'] ?>">

      <section class="settings-card venue-social-card venue-edit-simple-card">
        <div class="settings-grid">
          <label>
            <span>Event *</span>
            <select name="event_id" required <?= event_sponsors_required_tables_ready() ? '' : 'disabled' ?>>
              <option value="">Choose event...</option>
              <?php foreach ($events as $event): ?>
                <option value="<?= (int)$event['id'] ?>" <?= ((int)$form['event_id'] === (int)$event['id']) ? 'selected' : '' ?>><?= h(event_sponsors_event_label($event)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            <span>Sponsor *</span>
            <select name="sponsor_id" required <?= event_sponsors_required_tables_ready() ? '' : 'disabled' ?>>
              <option value="">Choose sponsor...</option>
              <?php foreach ($sponsors as $sponsor): ?>
                <option
                  value="<?= (int)$sponsor['id'] ?>"
                  data-offer="<?= h($sponsor['default_offer'] ?? '') ?>"
                  data-logo="<?= h($sponsor['logo_url'] ?? '') ?>"
                  data-website="<?= h($sponsor['website_url'] ?? '') ?>"
                  <?= ((int)$form['sponsor_id'] === (int)$sponsor['id']) ? 'selected' : '' ?>
                ><?= h($sponsor['sponsor_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            <span>Display title</span>
            <input name="sponsor_title" value="<?= h($form['sponsor_title']) ?>" placeholder="e.g. Main raffle sponsor">
          </label>

          <label>
            <span>Sort order</span>
            <input type="number" name="sort_order" value="<?= h((string)$form['sort_order']) ?>">
          </label>

          <label class="venue-address-wide">
            <span>Event offer / prize wording</span>
            <textarea name="sponsor_offer" rows="3" placeholder="e.g. Win a weekend away for two..."><?= h($form['sponsor_offer']) ?></textarea>
          </label>

          <label>
            <span>Image/logo URL override</span>
            <input type="url" name="sponsor_image_url" value="<?= h($form['sponsor_image_url']) ?>" placeholder="Leave blank to use sponsor logo">
          </label>

          <label>
            <span>Website URL override</span>
            <input type="url" name="website_url" value="<?= h($form['website_url']) ?>" placeholder="Leave blank to use sponsor website">
          </label>

          <label>
            <span>Public display</span>
            <label style="display:flex;align-items:center;gap:10px;margin-top:10px;">
              <input type="checkbox" name="display_on_public" value="1" <?= ((int)$form['display_on_public'] === 1) ? 'checked' : '' ?> style="width:auto;">
              <span>Show on public/event pages when supported</span>
            </label>
          </label>
        </div>
      </section>

      <div class="form-actions">
        <?php if ($editing): ?>
          <a class="touch-btn" href="event-sponsors.php">Cancel Edit</a>
        <?php endif; ?>
        <button class="touch-btn blue" type="submit" <?= event_sponsors_required_tables_ready() ? '' : 'disabled' ?>><?= $editing ? 'Save Assignment' : 'Add Assignment' ?></button>
      </div>
    </form>
  </section>

  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h2 class="touch-panel-title">Current assignments</h2>
        <p class="touch-subtitle">Reusable sponsors linked to specific events with event-specific wording.</p>
      </div>
    </div>

    <div class="settings-card venue-list-card">
      <div class="venue-list">
        <?php if (!$assignments && event_sponsors_required_tables_ready()): ?>
          <div class="empty-state">No event sponsor assignments yet.</div>
        <?php endif; ?>

        <?php foreach ($assignments as $assignment): ?>
          <article class="venue-row">
            <div class="venue-row-main">
              <h4><?= h($assignment['sponsor_title'] ?: $assignment['sponsor_name']) ?></h4>
              <p><?= h(event_sponsors_event_label($assignment)) ?></p>
              <span><?= ((int)($assignment['display_on_public'] ?? 1) === 1) ? 'Public display on' : 'Hidden from public display' ?></span>
              <?php if (!empty($assignment['sponsor_offer'])): ?>
                <p><strong>Offer:</strong> <?= h($assignment['sponsor_offer']) ?></p>
              <?php elseif (!empty($assignment['default_offer'])): ?>
                <p><strong>Default offer:</strong> <?= h($assignment['default_offer']) ?></p>
              <?php endif; ?>
            </div>

            <div class="venue-row-links">
              <?php $assignment_url = $assignment['website_url'] ?: ($assignment['sponsor_website_url'] ?? ''); ?>
              <?php if (!empty($assignment_url)): ?>
                <a href="<?= h($assignment_url) ?>" target="_blank" rel="noopener">⌂</a>
              <?php endif; ?>
            </div>

            <div class="venue-row-actions">
              <a class="action-tile maybe venue-square-action" href="event-sponsors.php?edit=<?= (int)$assignment['id'] ?>">
                <span class="big-icon">⚙</span>
                <span>Edit</span>
              </a>

              <form method="post" onsubmit="return confirm('Remove this sponsor from the event?');">
                <input type="hidden" name="action" value="delete_assignment">
                <input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>">
                <button class="action-tile reject venue-square-action" type="submit">
                  <span class="big-icon">×</span>
                  <span>Remove</span>
                </button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</main>

<script>
(function () {
  const sponsorSelect = document.querySelector('select[name="sponsor_id"]');
  if (!sponsorSelect) return;

  const offer = document.querySelector('textarea[name="sponsor_offer"]');
  const image = document.querySelector('input[name="sponsor_image_url"]');
  const website = document.querySelector('input[name="website_url"]');

  sponsorSelect.addEventListener('change', function () {
    const option = sponsorSelect.options[sponsorSelect.selectedIndex];
    if (!option) return;

    if (offer && !offer.value.trim() && option.dataset.offer) offer.value = option.dataset.offer;
    if (image && !image.value.trim() && option.dataset.logo) image.value = option.dataset.logo;
    if (website && !website.value.trim() && option.dataset.website) website.value = option.dataset.website;
  });
})();
</script>
<?php admin_footer(); ?>
