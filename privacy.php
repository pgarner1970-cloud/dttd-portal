<?php
require __DIR__ . '/includes/config.php';
$public_current = 'privacy';
$metaTitle = 'Privacy Policy | Dance Thru The Decades';
$metaDescription = 'How we handle website, event, music request and photo upload information.';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($metaTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8') ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="canonical" href="/privacy">
  <link rel="stylesheet" href="<?= htmlspecialchars(dttd_asset_url('assets/public-site.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(dttd_asset_url('assets/legal-pages.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="public-legal-page">
  <?php require __DIR__ . '/includes/public-nav.php'; ?>

  <main class="public-legal-shell">
    <section class="public-legal-hero">
      <img class="public-legal-logo" src="/assets/dttd-logo-inner.png" alt="Dance Thru The Decades">
      <p class="public-legal-eyebrow">Dance Thru The Decades</p>
      <h1>Privacy Policy</h1>
      <p class="public-legal-subtitle">How we handle website, event, music request and photo upload information.</p>
    </section>

    <section class="public-legal-card">
      <p class="public-legal-updated">Last updated: <?= date('F Y') ?></p>
      <h2>Who we are</h2>
      <p>Dance Thru The Decades operates event-related website features including public event pages, music requests, photo uploads, galleries, partner information and sponsor displays.</p>

      <h2>Information we may collect</h2>
      <p>Depending on how you use the site or attend an event, we may collect information such as your name, contact details, music requests, messages, event codes, uploaded photos, photo captions, event attendance context and basic technical information such as IP address, browser type, device type and timestamps.</p>

      <h2>Photo uploads and galleries</h2>
      <p>If you upload photos, we may store the image file, upload time, linked event, approval status and any information submitted with the upload. Approved photos may appear in public galleries or event displays.</p>

      <h2>Music requests</h2>
      <p>Music requests may include the requested track, artist, name or nickname, message, event code and timing information. These details are used to manage requests for the DJ and may be visible to authorised event/admin users.</p>

      <h2>Sharing information</h2>
      <p>We do not sell personal information. Information may be shared with trusted service providers where needed for hosting, website operation, email, storage, security, event administration or legal compliance.</p>

      <h2>Your rights</h2>
      <p>You may ask to access, correct or delete personal information we hold about you, subject to reasonable identification and any legal or operational requirements. You can also ask us to review or remove a public photo.</p>

      <h2>Contact</h2>
      <p>For privacy questions, data requests or photo removal requests, please contact Dance Thru The Decades using the contact details shown on the website or event materials.</p>
    </section>
  </main>

  <?php require __DIR__ . '/includes/public-footer.php'; ?>
</body>
</html>
