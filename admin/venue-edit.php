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

$error = '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_edit = $id > 0;

$venue = [
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

if (!venues_table_exists()) {
    $error = 'The venues table does not exist yet.';
} elseif ($is_edit) {
    $stmt = db()->prepare("SELECT * FROM venues WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $loaded = $stmt->fetch();

    if ($loaded) {
        $venue = array_merge($venue, $loaded);
    } else {
        $error = 'Venue not found.';
        $is_edit = false;
        $id = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && venues_table_exists()) {
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
        $venue = array_merge($venue, $data);
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
        } else {
            $columns = array_keys($data);
            $placeholders = array_fill(0, count($columns), '?');

            $stmt = db()->prepare(
                "INSERT INTO venues (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")"
            );
            $stmt->execute(array_values($data));
        }

        header('Location: /admin/venues.php');
        exit;
    }
}

admin_header(($is_edit ? 'Edit Venue' : 'Add Venue') . ' - DJ Portal');
?>

<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title"><?= $is_edit ? 'Edit Venue' : 'Add Venue' ?></h1>
        <p class="touch-subtitle">Maintain saved venue details for repeat events.</p>
      </div>
      <div>
        <a class="touch-btn" href="/admin/venues.php">Back to Venues</a>
      </div>
    </div>

    <form method="post" class="venue-edit-form">
      <input type="hidden" name="venue_id" value="<?= (int)$venue['id'] ?>">

      <?php if ($error): ?>
        <div class="settings-alert error"><?= h($error) ?></div>
      <?php endif; ?>

      <section class="settings-card venue-social-card venue-edit-simple-card">
<div class="settings-grid">
          <label>
            <span>Venue name *</span>
            <input name="venue_name" value="<?= h($venue['venue_name']) ?>" required>
          </label>

          <label>
            <span>Postcode</span>
            <input name="venue_postcode" value="<?= h($venue['venue_postcode']) ?>" placeholder="e.g. WV16 5NQ">
          </label>

          <label class="venue-address-wide">
            <span>Venue address</span>
            <input name="venue_address" value="<?= h($venue['venue_address']) ?>" placeholder="Street address or venue location">
          </label>

          <label>
            <span>Facebook URL</span>
            <input type="url" name="venue_facebook_url" value="<?= h($venue['venue_facebook_url']) ?>" placeholder="https://facebook.com/...">
          </label>

          <label>
            <span>Website URL</span>
            <input type="url" name="venue_website_url" value="<?= h($venue['venue_website_url']) ?>" placeholder="https://...">
          </label>

          <label>
            <span>Instagram URL</span>
            <input type="url" name="venue_instagram_url" value="<?= h($venue['venue_instagram_url']) ?>" placeholder="https://instagram.com/...">
          </label>

          <label>
            <span>Ticketing URL</span>
            <input type="url" name="venue_ticket_url" value="<?= h($venue['venue_ticket_url']) ?>" placeholder="https://tickets.example.com/...">
          </label>

          <label>
            <span>Venue display label</span>
            <input name="venue_social_label" value="<?= h($venue['venue_social_label']) ?>" placeholder="e.g. Follow The Venue">
          </label>
        </div>
      </section>

      <div class="form-actions">
        <a class="touch-btn" href="/admin/venues.php">Cancel</a>
        <button class="touch-btn blue" type="submit"><?= $is_edit ? 'Save Venue' : 'Add Venue' ?></button>
      </div>
    </form>
  </section>
</main>

<?php admin_footer(); ?>
