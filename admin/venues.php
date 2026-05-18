<?php
require_once __DIR__ . '/_auth.php';

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

function venue_event_count($venue_id) {
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM events WHERE venue_id = ?");
        $stmt->execute([(int)$venue_id]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

$error = '';
$success = '';

$blank_venue = [
    'id' => 0,
    'venue_name' => '',
    'venue_address' => '',
    'venue_postcode' => '',
    'venue_facebook_url' => '',
    'venue_website_url' => '',
    'venue_instagram_url' => '',
    'venue_ticket_url' => '',
    'venue_social_label' => '',
];

$editing_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing_venue = $blank_venue;

if (!venues_table_exists()) {
    $error = 'The venues table does not exist yet.';
} elseif ($editing_id > 0) {
    $stmt = db()->prepare("SELECT * FROM venues WHERE id = ? LIMIT 1");
    $stmt->execute([$editing_id]);
    $loaded = $stmt->fetch();

    if ($loaded) {
        $editing_venue = array_merge($editing_venue, $loaded);
    } else {
        $error = 'Venue not found.';
        $editing_id = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && venues_table_exists()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_venue') {
        $venue_id = (int)($_POST['venue_id'] ?? 0);
        $data = [
            'venue_name' => trim((string)($_POST['venue_name'] ?? '')),
            'venue_address' => trim((string)($_POST['venue_address'] ?? '')),
            'venue_postcode' => trim((string)($_POST['venue_postcode'] ?? '')),
            'venue_facebook_url' => trim((string)($_POST['venue_facebook_url'] ?? '')),
            'venue_website_url' => trim((string)($_POST['venue_website_url'] ?? '')),
            'venue_instagram_url' => trim((string)($_POST['venue_instagram_url'] ?? '')),
            'venue_ticket_url' => trim((string)($_POST['venue_ticket_url'] ?? '')),
            'venue_social_label' => trim((string)($_POST['venue_social_label'] ?? '')),
        ];

        if ($data['venue_name'] === '') {
            $error = 'Venue name is required.';
            $editing_venue = array_merge($blank_venue, $data, ['id' => $venue_id]);
            $editing_id = $venue_id;
        } else {
            $data = array_filter(
                $data,
                fn($value, $column) => venue_column_exists($column),
                ARRAY_FILTER_USE_BOTH
            );

            if ($venue_id > 0) {
                $sets = [];
                $params = [];

                foreach ($data as $column => $value) {
                    $sets[] = "{$column} = ?";
                    $params[] = $value;
                }

                if ($sets) {
                    $params[] = $venue_id;
                    $stmt = db()->prepare("UPDATE venues SET " . implode(', ', $sets) . " WHERE id = ?");
                    $stmt->execute($params);
                }

                $success = 'Venue updated.';
                $editing_id = 0;
                $editing_venue = $blank_venue;
            } else {
                $columns = array_keys($data);
                $placeholders = array_fill(0, count($columns), '?');

                $stmt = db()->prepare(
                    "INSERT INTO venues (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")"
                );
                $stmt->execute(array_values($data));

                $success = 'Venue added.';
                $editing_venue = $blank_venue;
            }
        }
    }

    if ($action === 'delete_venue') {
        $venue_id = (int)($_POST['venue_id'] ?? 0);
        $count = venue_event_count($venue_id);

        if ($count > 0) {
            $error = 'This venue is linked to existing events, so it cannot be deleted.';
        } else {
            $stmt = db()->prepare("DELETE FROM venues WHERE id = ?");
            $stmt->execute([$venue_id]);
            $success = 'Venue deleted.';
        }
    }
}

$venues = [];

if (venues_table_exists()) {
    try {
        $venues = db()->query("
            SELECT v.*,
                   (SELECT COUNT(*) FROM events e WHERE e.venue_id = v.id) AS event_count
            FROM venues v
            ORDER BY v.venue_name ASC, v.id ASC
        ")->fetchAll();
    } catch (Throwable $e) {
        $error = 'Could not load venues.';
    }
}

admin_header('Venues - DJ Portal');
?>

<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Venues</h1>
        <p class="touch-subtitle">Maintain saved venue details for repeat events.</p>
      </div>
      <div>
        <a class="touch-btn" href="/admin/events.php">Back to Events</a>
      </div>
    </div>

    <div class="venue-maintenance-layout">
      <section class="settings-card venue-maintenance-form-card">
        <div class="settings-card-header">
          <div class="settings-card-icon">⌖</div>
          <div>
            <h3><?= $editing_id > 0 ? 'Edit Venue' : 'Add Venue' ?></h3>
            <p>Saved venues can be selected when adding or editing events.</p>
          </div>
        </div>

        <form method="post" class="venue-maintenance-form">
          <input type="hidden" name="action" value="save_venue">
          <input type="hidden" name="venue_id" value="<?= (int)$editing_venue['id'] ?>">

          <?php if ($error): ?>
            <div class="settings-alert error"><?= h($error) ?></div>
          <?php endif; ?>

          <?php if ($success): ?>
            <div class="settings-alert success"><?= h($success) ?></div>
          <?php endif; ?>

          <div class="settings-grid">
            <label>
              <span>Venue name *</span>
              <input name="venue_name" value="<?= h($editing_venue['venue_name']) ?>" required>
            </label>

            <label>
              <span>Postcode</span>
              <input name="venue_postcode" value="<?= h($editing_venue['venue_postcode']) ?>" placeholder="e.g. WV16 5NQ">
            </label>

            <label class="venue-address-wide">
              <span>Venue address</span>
              <input name="venue_address" value="<?= h($editing_venue['venue_address']) ?>" placeholder="Street address or venue location">
            </label>

            <label>
              <span>Facebook URL</span>
              <input type="url" name="venue_facebook_url" value="<?= h($editing_venue['venue_facebook_url']) ?>" placeholder="https://facebook.com/...">
            </label>

            <label>
              <span>Website URL</span>
              <input type="url" name="venue_website_url" value="<?= h($editing_venue['venue_website_url']) ?>" placeholder="https://...">
            </label>

            <label>
              <span>Instagram URL</span>
              <input type="url" name="venue_instagram_url" value="<?= h($editing_venue['venue_instagram_url']) ?>" placeholder="https://instagram.com/...">
            </label>

            <label>
              <span>Ticketing URL</span>
              <input type="url" name="venue_ticket_url" value="<?= h($editing_venue['venue_ticket_url']) ?>" placeholder="https://tickets.example.com/...">
            </label>

            <label>
              <span>Venue display label</span>
              <input name="venue_social_label" value="<?= h($editing_venue['venue_social_label']) ?>" placeholder="e.g. Follow The Venue">
            </label>
          </div>

          <div class="form-actions">
            <?php if ($editing_id > 0): ?>
              <a class="touch-btn" href="/admin/venues.php">Cancel Edit</a>
            <?php endif; ?>
            <button class="touch-btn blue" type="submit"><?= $editing_id > 0 ? 'Save Venue' : 'Add Venue' ?></button>
          </div>
        </form>
      </section>

      <section class="settings-card venue-list-card">
        <div class="settings-card-header">
          <div class="settings-card-icon">▤</div>
          <div>
            <h3>Saved Venues</h3>
            <p><?= count($venues) ?> saved venue<?= count($venues) === 1 ? '' : 's' ?>.</p>
          </div>
        </div>

        <div class="venue-list">
          <?php if (!$venues): ?>
            <div class="empty-state">No saved venues yet.</div>
          <?php endif; ?>

          <?php foreach ($venues as $venue): ?>
            <article class="venue-row">
              <div>
                <h4><?= h($venue['venue_name']) ?></h4>
                <p>
                  <?= h(trim(($venue['venue_address'] ?? '') . ' ' . ($venue['venue_postcode'] ?? ''))) ?: 'No address saved' ?>
                </p>
                <span><?= (int)($venue['event_count'] ?? 0) ?> event<?= (int)($venue['event_count'] ?? 0) === 1 ? '' : 's' ?></span>
              </div>

              <div class="venue-row-links">
                <?php if (!empty($venue['venue_postcode'])): ?>
                  <a href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode(($venue['venue_name'] ?? '') . ' ' . ($venue['venue_postcode'] ?? '')) ?>" target="_blank" rel="noopener">Map</a>
                <?php endif; ?>

                <?php if (!empty($venue['venue_facebook_url'])): ?>
                  <a href="<?= h($venue['venue_facebook_url']) ?>" target="_blank" rel="noopener">Facebook</a>
                <?php endif; ?>

                <?php if (!empty($venue['venue_website_url'])): ?>
                  <a href="<?= h($venue['venue_website_url']) ?>" target="_blank" rel="noopener">Website</a>
                <?php endif; ?>
              </div>

              <div class="venue-row-actions">
                <a class="touch-btn" href="/admin/venues.php?edit=<?= (int)$venue['id'] ?>">Edit</a>

                <form method="post" onsubmit="return confirm('Delete this venue?');">
                  <input type="hidden" name="action" value="delete_venue">
                  <input type="hidden" name="venue_id" value="<?= (int)$venue['id'] ?>">
                  <button class="touch-btn danger" type="submit" <?= ((int)($venue['event_count'] ?? 0) > 0) ? 'disabled' : '' ?>>Delete</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    </div>
  </section>
</main>

<?php admin_footer(); ?>
