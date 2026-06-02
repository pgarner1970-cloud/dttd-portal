<?php
require __DIR__ . '/includes/config.php';
$public_current = 'terms';
$metaTitle = 'Terms & Conditions | Dance Thru The Decades';
$metaDescription = 'Terms for events, bookings, photo uploads, music requests and public website features.';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($metaTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8') ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="canonical" href="/terms">
  <link rel="stylesheet" href="<?= htmlspecialchars(dttd_asset_url('assets/public-site.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(dttd_asset_url('assets/legal-pages.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="public-legal-page">
  <?php require __DIR__ . '/includes/public-nav.php'; ?>

  <main class="public-legal-shell">
    <section class="public-legal-hero">
      <img class="public-legal-logo" src="/assets/dttd-logo-inner.png" alt="Dance Thru The Decades">
      <p class="public-legal-eyebrow">Dance Thru The Decades</p>
      <h1>Terms & Conditions</h1>
      <p class="public-legal-subtitle">Terms for events, bookings, photo uploads, music requests and public website features.</p>
    </section>

    <section class="public-legal-card">
      <p class="public-legal-updated">Last updated: <?= date('F Y') ?></p>
      <h2>About these terms</h2>
      <p>These terms apply when you use the Dance Thru The Decades website, attend one of our events, submit a music request, upload a photo, or interact with our public event features.</p>

      <h2>Event information</h2>
      <p>We aim to keep event information, timings, venues, prices and availability accurate. Details may occasionally change because of venue requirements, technical issues, supplier availability, weather, safety, licensing, or other circumstances outside our control.</p>

      <h2>Tickets, bookings and entry</h2>
      <p>Where tickets, bookings or reserved places are used, entry may be subject to venue rules, capacity limits, age restrictions, licensing requirements and any conditions stated at the time of booking.</p>

      <h2>Cancellations and changes</h2>
      <p>If an event has to be cancelled, postponed or materially changed, we will try to provide reasonable notice using the contact or public channels available to us.</p>

      <h2>Music requests</h2>
      <p>Music requests are welcomed but cannot be guaranteed. The DJ may choose whether, when and how to play requests based on the event style, suitability, timing and the atmosphere on the night.</p>

      <h2>Photo uploads and gallery content</h2>
      <p>If you upload photos through the website, you confirm that you have the right to upload them and that they are suitable for a public event gallery. Photos may be reviewed, approved, edited, hidden or removed at our discretion.</p>

      <h2>Contact</h2>
      <p>For questions about these terms, event information, photo removal requests or website content, please contact Dance Thru The Decades using the contact details provided on the website or event materials.</p>
    </section>
  </main>

  <?php require __DIR__ . '/includes/public-footer.php'; ?>
</body>
</html>
