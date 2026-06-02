<?php
require_once __DIR__ . '/_auth.php';

function partners_table_exists() {
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = db()->query("SHOW TABLES LIKE 'partners'");
        $exists = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_partner') {
    $partner_id = (int)($_POST['partner_id'] ?? 0);

    if (!partners_table_exists()) {
        $error = 'The partners table does not exist yet.';
    } elseif ($partner_id > 0) {
        try {
            $stmt = db()->prepare("DELETE FROM partners WHERE id = ?");
            $stmt->execute([$partner_id]);
            $success = 'Partner deleted.';
        } catch (Throwable $e) {
            $error = 'Could not delete partner.';
        }
    }
}

$partners = [];

if (partners_table_exists()) {
    try {
        $partners = db()->query("
            SELECT *
            FROM partners
            ORDER BY is_active DESC, sort_order ASC, partner_name ASC, id ASC
        ")->fetchAll();
    } catch (Throwable $e) {
        $error = 'Could not load partners.';
    }
} else {
    $error = 'The partners table does not exist yet. Run the SQL supplied with this patch before adding partners.';
}

admin_header('Partners - DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Partners</h1>
        <p class="touch-subtitle">Behind-the-scenes supplier and trusted-contact records.</p>
      </div>
      <div class="settings-actions">
        <a class="touch-btn ghost" href="tools.php">← Admin Tools</a>
        <a class="touch-btn blue" href="partner-edit.php">+ Add Partner</a>
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

        <?php if (!$partners && partners_table_exists()): ?>
          <div class="empty-state">No partners yet. Use Add Partner to save your first supplier or trusted contact.</div>
        <?php endif; ?>

        <?php foreach ($partners as $partner): ?>
          <article class="venue-row">
            <div class="venue-row-main">
              <h4><?= h($partner['partner_name']) ?></h4>
              <p>
                <?= h($partner['category'] ?: 'Partner') ?>
                <?php if (!empty($partner['contact_name'])): ?>
                  · <?= h($partner['contact_name']) ?>
                <?php endif; ?>
              </p>
              <span><?= ((int)($partner['is_active'] ?? 1) === 1) ? 'Active' : 'Hidden' ?></span>
              <?php if (!empty($partner['notes'])): ?>
                <p><?= h($partner['notes']) ?></p>
              <?php endif; ?>
            </div>

            <div class="venue-row-links">
              <?php if (!empty($partner['website_url'])): ?>
                <a href="<?= h($partner['website_url']) ?>" target="_blank" rel="noopener">⌂</a>
              <?php endif; ?>

              <?php if (!empty($partner['phone'])): ?>
                <a href="tel:<?= h(preg_replace('/\s+/', '', $partner['phone'])) ?>">☎</a>
              <?php endif; ?>

              <?php if (!empty($partner['email'])): ?>
                <a href="mailto:<?= h($partner['email']) ?>">@</a>
              <?php endif; ?>
            </div>

            <div class="venue-row-actions">
              <a class="action-tile maybe venue-square-action" href="partner-edit.php?id=<?= (int)$partner['id'] ?>">
                <span class="big-icon">⚙</span>
                <span>Edit</span>
              </a>

              <form method="post" onsubmit="return confirm('Delete this partner?');">
                <input type="hidden" name="action" value="delete_partner">
                <input type="hidden" name="partner_id" value="<?= (int)$partner['id'] ?>">
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
