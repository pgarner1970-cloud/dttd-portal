<?php
require __DIR__ . '/includes/config.php';
$public_current = 'privacy';
$metaTitle = 'Privacy Policy | Dance Thru The Decades';
$metaDescription = 'Privacy information for Dance Thru The Decades events, music requests, photo uploads, galleries and website use.';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($metaTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES, 'UTF-8') ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="canonical" href="/privacy">
  <link rel="stylesheet" href="/assets/public-site.css?v=legal-sticky-1">
</head>
<body class="homepage-option-one public-legal-page">
  <div class="home-option-one">
    <?php require __DIR__ . '/includes/public-nav.php'; ?>

    <main class="public-legal-shell">
      <section class="public-legal-hero">
        <div class="option-one-logo-shell">
          <img src="/assets/dttd-logo-inner.png" alt="Dance Thru The Decades">
        </div>
        <p class="option-one-eyebrow">Dance Thru The Decades</p>
        <h1>Privacy Policy</h1>
        <p class="option-one-subtitle">
          This policy explains what information we may collect through the website, event pages, music requests and photo upload features.
        </p>
      </section>

      <section class="public-legal-card">
        <p class="public-legal-updated">Last updated: <?= date('F Y') ?></p>

        <h2>Who we are</h2>
        <p>Dance Thru The Decades operates event-related website features including public event pages, music requests, photo uploads, galleries, partner information and sponsor displays.</p>

        <h2>Information we may collect</h2>
        <p>Depending on how you use the site or attend an event, we may collect information such as your name, contact details, music requests, messages, event codes, uploaded photos, photo captions, event attendance context and basic technical information such as IP address, browser type, device type and timestamps.</p>

        <h2>Photo uploads and galleries</h2>
        <p>If you upload photos, we may store the image file, upload time, linked event, approval status and any information submitted with the upload. Approved photos may appear in public galleries or event displays.</p>
        <p>If you would like a photo removed from a public gallery, contact us with enough detail to identify the image and event.</p>

        <h2>Music requests</h2>
        <p>Music requests may include the requested track, artist, name or nickname, message, event code and timing information. These details are used to manage requests for the DJ and may be visible to authorised event/admin users.</p>

        <h2>Why we use information</h2>
        <ul>
          <li>to run event pages and public event features;</li>
          <li>to manage music requests and photo uploads;</li>
          <li>to moderate and approve gallery content;</li>
          <li>to respond to enquiries or removal requests;</li>
          <li>to maintain website security and prevent misuse;</li>
          <li>to display partners, sponsors and event information;</li>
          <li>to improve the website and event experience.</li>
        </ul>

        <h2>Sharing information</h2>
        <p>We do not sell personal information. Information may be shared with trusted service providers where needed for hosting, website operation, email, storage, security, event administration or legal compliance. Publicly approved photos, sponsor information and partner links may be visible on the public website.</p>

        <h2>Third-party links</h2>
        <p>The website may link to venues, suppliers, partners, sponsors, social platforms or booking services. Those sites have their own privacy practices and are not controlled by Dance Thru The Decades.</p>

        <h2>Cookies and analytics</h2>
        <p>The site may use essential cookies or basic technical logging to operate securely and reliably. If analytics, embedded media or third-party tools are added, they may set their own cookies or collect usage information.</p>

        <h2>How long we keep information</h2>
        <p>We keep information only for as long as reasonably needed for event operation, moderation, record keeping, technical support, safety, dispute handling or legal reasons. Public gallery content may remain available until removed or archived.</p>

        <h2>Your rights</h2>
        <p>You may ask to access, correct or delete personal information we hold about you, subject to reasonable identification and any legal or operational requirements. You can also ask us to review or remove a public photo.</p>

        <h2>Security</h2>
        <p>We use reasonable technical and organisational measures to protect information. No website or online system can be guaranteed completely secure, so please avoid submitting sensitive information through public forms.</p>

        <h2>Contact</h2>
        <p>For privacy questions, data requests or photo removal requests, please contact Dance Thru The Decades using the contact details shown on the website or event materials.</p>
      </section>
    </main>

    <?php require __DIR__ . '/includes/public-footer.php'; ?>
  </div>
</body>
</html>
