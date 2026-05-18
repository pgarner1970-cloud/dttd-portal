<?php
require_once __DIR__ . '/_auth.php';

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
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_venue') {
    $venue_id = (int)($_POST['venue_id'] ?? 0);

    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM events WHERE venue_id = ?");
        $stmt->execute([$venue_id]);
        $event_count = (int)$stmt->fetchColumn();

        if ($event_count > 0) {
            $error = 'This venue is linked to existing events, so it cannot be deleted.';
        } else {
            $stmt = db()->prepare("DELETE FROM venues WHERE id = ?");
            $stmt->execute([$venue_id]);
            $success = 'Venue deleted.';
        }
    } catch (Throwable $e) {
        $error = 'Could not delete venue.';
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
} else {
    $error = 'The venues table does not exist yet.';
}

admin_header('Venues - DJ Portal');
?>

<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Venues</h1>
        <p class="touch-subtitle">Saved venues can be reused when adding or editing events.</p>
      </div>
      <div class="settings-actions">
        <a class="touch-btn blue" href="venue-edit.php">+ Add Venue</a>
      </div>
    </div>

    <div class="settings-card venue-list-card">
      <div class="venue-list">
        <?php if ($error): ?>
          <div class="settings-alert error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
          <div class="settings-alert success"><?= h($success) ?></div>
        <?php endif; ?>

        <?php if (!$venues): ?>
          <div class="empty-state">No saved venues yet. Use Add Venue to create your first saved venue.</div>
        <?php endif; ?>

        <?php foreach ($venues as $venue): ?>
          <article class="venue-row">
            <div class="venue-row-main">
              <h4><?= h($venue['venue_name']) ?></h4>
              <p>
                <?= h(trim(($venue['venue_address'] ?? '') . ' ' . ($venue['venue_postcode'] ?? ''))) ?: 'No address saved' ?>
              </p>
              <span><?= (int)($venue['event_count'] ?? 0) ?> event<?= (int)($venue['event_count'] ?? 0) === 1 ? '' : 's' ?></span>
            </div>

            <div class="venue-row-links">
              <?php if (!empty($venue['venue_postcode'])): ?>
                <a href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode(($venue['venue_name'] ?? '') . ' ' . ($venue['venue_postcode'] ?? '')) ?>" target="_blank" rel="noopener">⌖</a>
              <?php endif; ?>

              <?php if (!empty($venue['venue_facebook_url'])): ?>
                <a href="<?= h($venue['venue_facebook_url']) ?>" target="_blank" rel="noopener">f</a>
              <?php endif; ?>

              <?php if (!empty($venue['venue_website_url'])): ?>
                <a href="<?= h($venue['venue_website_url']) ?>" target="_blank" rel="noopener">⌂</a>
              <?php endif; ?>

              <?php if (!empty($venue['venue_ticket_url'])): ?>
                <a href="<?= h($venue['venue_ticket_url']) ?>" target="_blank" rel="noopener">🎟</a>
              <?php endif; ?>
            </div>

            <div class="venue-row-actions">
              <a class="action-tile maybe venue-square-action" href="/admin/venue-edit.php?id=<?= (int)$venue['id'] ?>">
                <span class="big-icon">⚙</span>
                <span>Edit</span>
              </a>

              <form method="post" onsubmit="return confirm('Delete this venue?');">
                <input type="hidden" name="action" value="delete_venue">
                <input type="hidden" name="venue_id" value="<?= (int)$venue['id'] ?>">
                <button class="action-tile reject venue-square-action" type="submit" <?= ((int)($venue['event_count'] ?? 0) > 0) ? 'disabled' : '' ?>>
                  <span class="big-icon">×</span>
                  <span>Delete</span>
                </button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</main>

<?php admin_footer(); ?>
