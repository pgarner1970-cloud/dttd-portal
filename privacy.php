<?php
if (!function_exists('public_h')) {
    function public_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$facebookUrl = 'https://www.facebook.com/profile.php?id=61579454050951';
$public_current = 'privacy';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?= dttd_cache_meta_tags() ?>
  <title>Privacy & Cookies | Dance Thru the Decades</title>
  <meta name="description" content="Privacy and cookie information for Dance Thru the Decades event song requests and photo uploads.">
  <link rel="stylesheet" href="<?= h(dttd_asset_url('assets/public-site.css')) ?>">
</head>
<body class="homepage-option-one public-list-page public-policy-page">
  <main class="home-option-one">
    <?php require __DIR__ . '/includes/public-nav.php'; ?>

    <section class="public-list-hero public-feature-hero">
      <div class="option-one-logo-shell public-list-logo">
        <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=152" alt="Dance Thru The Decades Events logo">
      </div>
      <p class="option-one-eyebrow">Privacy & Cookies</p>
      <h1><span class="headline-main">PRIVACY</span><span class="headline-the"><i></i><b>NOTICE</b><i></i></span></h1>
      <p class="option-one-subtitle">How we use event requests, uploads and essential event access cookies.</p>
    </section>

    <section class="public-event-detail-section public-feature-section">
      <article class="public-feature-card public-policy-card">
        <h2>Information you submit</h2>
        <p>Dance Thru The Decades Events may collect the name you enter, song requests, artist names, dedications/messages and photos uploaded through the event portal. This information is used to run the event, manage the DJ queue, moderate uploads and support guest interaction.</p>

        <h2>Photo uploads</h2>
        <p>Photos uploaded through the event portal are saved as pending first. They are not intended to appear publicly until they have been reviewed and approved.</p>

        <h2>Essential event access cookie</h2>
        <p>When you scan an event QR code or enter an event code, we use an essential event access cookie to remember which event you have joined on that device. This lets you request songs, upload photos and open event features without entering the code every time.</p>
        <p>The event access cookie is short-lived, event-specific and expires after the event access period ends. It is used to provide the event feature you have requested, not for advertising or marketing tracking.</p>

        <h2>Optional cookies</h2>
        <p>The event access cookie is separate from optional analytics, advertising or tracking cookies. If optional tracking tools are added in future, they should be handled separately with the appropriate consent controls.</p>

        <h2>Contact</h2>
        <p>For questions about event requests or uploaded content, please contact Dance Thru The Decades Events through the public Facebook page.</p>

        <div class="public-event-actions public-centred-actions">
          <a class="public-neon-btn" href="/">Back to Website</a>
          <a class="public-neon-btn subtle" href="<?= public_h($facebookUrl) ?>" target="_blank" rel="noopener">Facebook</a>
        </div>
      </article>
    </section>

    <?php require __DIR__ . '/includes/public-footer.php'; ?>
  </main>
<?= dttd_bfcache_reload_script() ?>
</body>
</html>
