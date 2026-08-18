<?php
require_once __DIR__ . '/includes/db.php';
dttd_no_cache_headers();

$public_current = 'partners';
$facebookUrl = 'https://www.facebook.com/profile.php?id=61579454050951';
$partners = [];

try {
    $stmt = db()->query("\n        SELECT partner_name, category, website_url, image_url, logo_background, notes\n        FROM partners\n        WHERE is_active = 1\n        ORDER BY sort_order ASC, partner_name ASC\n    ");
    $partners = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $partners = [];
}

function partner_public_url($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if (!preg_match('~^https?://~i', $value)) {
        $value = 'https://' . $value;
    }
    return $value;
}

function partner_public_image($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $value)) {
        return $value;
    }
    return '/' . ltrim($value, '/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?= dttd_cache_meta_tags() ?>
  <title>Partners | Dance Thru The Decades Events</title>
  <meta name="description" content="Meet the local partners and suppliers connected with Dance Thru The Decades Events.">
  <link rel="stylesheet" href="<?= h(dttd_asset_url('assets/public-site.css')) ?>">
</head>
<body class="public-partners-page">
  <main class="home-option-one">
    <?php require __DIR__ . '/includes/public-nav.php'; ?>

    <section class="public-partners-hero">
      <div class="option-one-logo-shell"><img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=200" alt="Dance Thru The Decades Events logo"></div>
      <p class="option-one-eyebrow">Local suppliers · event friends · trusted contacts</p>
      <h1>Our Partners</h1>
      <p class="option-one-subtitle">A simple directory of the businesses and people we work with around events, supplies, print, party extras and production support.</p>
    </section>

    <section class="public-partners-section" aria-label="Partners">
      <?php if ($partners): ?>
        <div class="public-partners-grid">
          <?php foreach ($partners as $partner): ?>
            <?php
              $name = trim((string)($partner['partner_name'] ?? '')) ?: 'Partner';
              $category = trim((string)($partner['category'] ?? ''));
              $notes = trim((string)($partner['notes'] ?? ''));
              $website = partner_public_url($partner['website_url'] ?? '');
              $image = partner_public_image($partner['image_url'] ?? '');
              $logoBackground = (string)($partner['logo_background'] ?? 'dark');
              if (!in_array($logoBackground, ['dark', 'light', 'neon_pink', 'starburst', 'radial_neon'], true)) {
                  $logoBackground = 'dark';
              }
            ?>
            <article class="public-partner-card">
              <div class="public-partner-logo public-partner-logo--<?= h($logoBackground) ?>">
                <?php if ($image): ?>
                  <img src="<?= h($image) ?>" alt="<?= h($name) ?> logo or image">
                <?php else: ?>
                  <span aria-hidden="true">★</span>
                <?php endif; ?>
              </div>
              <div class="public-partner-body">
                <?php if ($category): ?><p class="public-partner-category"><?= h($category) ?></p><?php endif; ?>
                <h2><?= h($name) ?></h2>
                <?php if ($notes): ?><p><?= nl2br(h($notes)) ?></p><?php endif; ?>
                <?php if ($website): ?>
                  <a class="public-neon-btn" href="<?= h($website) ?>" target="_blank" rel="noopener">Visit website</a>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="public-empty-card">
          <h2>Partners coming soon</h2>
          <p>We’ll add partner details here as they are confirmed.</p>
          <a class="public-neon-btn" href="/">Back to home</a>
        </div>
      <?php endif; ?>
    </section>
  </main>

  <?php require __DIR__ . '/includes/public-footer.php'; ?>
  <?= dttd_bfcache_reload_script() ?>
</body>
</html>
