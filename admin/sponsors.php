<?php
require_once __DIR__ . '/_auth.php';

function sponsors_table_exists() {
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = db()->query("SHOW TABLES LIKE 'sponsors'");
        $exists = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_sponsor') {
    $sponsor_id = (int)($_POST['sponsor_id'] ?? 0);

    if (!sponsors_table_exists()) {
        $error = 'The sponsors table does not exist yet.';
    } elseif ($sponsor_id > 0) {
        try {
            $stmt = db()->prepare("DELETE FROM sponsors WHERE id = ?");
            $stmt->execute([$sponsor_id]);
            $success = 'Sponsor deleted.';
        } catch (Throwable $e) {
            $error = 'Could not delete sponsor.';
        }
    }
}

$sponsors = [];

if (sponsors_table_exists()) {
    try {
        $sponsors = db()->query("
            SELECT *
            FROM sponsors
            ORDER BY is_active DESC, sort_order ASC, sponsor_name ASC, id ASC
        ")->fetchAll();
    } catch (Throwable $e) {
        $error = 'Could not load sponsors.';
    }
} else {
    $error = 'The sponsors table does not exist yet. Run the SQL supplied with this patch before adding sponsors.';
}

admin_header('Sponsors - DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Sponsors</h1>
        <p class="touch-subtitle">Reusable sponsor records. Event-specific prizes and wording will be added in Event Sponsors.</p>
      </div>
      <div class="settings-actions">
        <a class="touch-btn ghost" href="tools.php">← Admin Tools</a>
        <a class="touch-btn blue" href="sponsor-edit.php">+ Add Sponsor</a>
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

        <?php if (!$sponsors && sponsors_table_exists()): ?>
          <div class="empty-state">No sponsors yet. Use Add Sponsor to save your first reusable sponsor.</div>
        <?php endif; ?>

        <?php foreach ($sponsors as $sponsor): ?>
          <article class="venue-row">
            <div class="venue-row-main">
              <h4><?= h($sponsor['sponsor_name']) ?></h4>
              <p>
                <?= h($sponsor['category'] ?: 'Sponsor') ?>
                <?php if (!empty($sponsor['contact_name'])): ?>
                  · <?= h($sponsor['contact_name']) ?>
                <?php endif; ?>
              </p>
              <span><?= ((int)($sponsor['is_active'] ?? 1) === 1) ? 'Active' : 'Hidden' ?></span>
              <?php if (!empty($sponsor['default_offer'])): ?>
                <p><strong>Default offer:</strong> <?= h($sponsor['default_offer']) ?></p>
              <?php endif; ?>
              <?php if (!empty($sponsor['notes'])): ?>
                <p><?= h($sponsor['notes']) ?></p>
              <?php endif; ?>
            </div>

            <div class="venue-row-links">
              <?php if (!empty($sponsor['website_url'])): ?>
                <a href="<?= h($sponsor['website_url']) ?>" target="_blank" rel="noopener">⌂</a>
              <?php endif; ?>

              <?php if (!empty($sponsor['phone'])): ?>
                <a href="tel:<?= h(preg_replace('/\s+/', '', $sponsor['phone'])) ?>">☎</a>
              <?php endif; ?>

              <?php if (!empty($sponsor['email'])): ?>
                <a href="mailto:<?= h($sponsor['email']) ?>">@</a>
              <?php endif; ?>
            </div>

            <div class="venue-row-actions">
              <a class="action-tile maybe venue-square-action" href="sponsor-edit.php?id=<?= (int)$sponsor['id'] ?>">
                <span class="big-icon">⚙</span>
                <span>Edit</span>
              </a>

              <form method="post" onsubmit="return confirm('Delete this sponsor?');">
                <input type="hidden" name="action" value="delete_sponsor">
                <input type="hidden" name="sponsor_id" value="<?= (int)$sponsor['id'] ?>">
                <button class="action-tile reject venue-square-action" type="submit">
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
