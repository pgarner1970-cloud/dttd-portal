<?php
require __DIR__ . '/includes/config.php';
$public_current = 'privacy';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Privacy Policy | Dance Thru The Decades</title>
<link rel="stylesheet" href="<?= h(dttd_asset_url('assets/public-site.css')) ?>">
<link rel="stylesheet" href="<?= h(dttd_asset_url('assets/legal-pages.css')) ?>">
</head>
<body class="homepage-option-one public-legal-page">
<main class="home-option-one">
<?php require __DIR__ . '/includes/public-nav.php'; ?>

<section class="public-legal-shell">
    <div class="public-legal-hero">
        <img class="public-legal-logo" src="/assets/dttd-logo-inner.png" alt="Dance Thru The Decades">
        <div class="public-legal-eyebrow">Dance Thru The Decades</div>
        <h1 class="public-legal-title">Privacy Policy</h1>
        <p class="public-legal-subtitle">
            How we handle website, event, photo upload and music request information across the public portal.
        </p>
    </div>

    <div class="public-legal-card">
        <div class="public-legal-updated">Last updated: June 2026</div>

        <h2>Who we are</h2>
        <p>
            Dance Thru The Decades operates public event pages, music requests, photo uploads,
            galleries and event-related features for party nights and DJ events.
        </p>

        <h2>Information we may collect</h2>
        <p>
            Depending on how you use the site, we may collect names, nicknames, messages,
            music requests, uploaded photos, event codes, timestamps and basic browser/device information.
        </p>

        <h2>Photo uploads</h2>
        <p>
            Uploaded photos may be reviewed before appearing publicly. We may store the uploaded image,
            upload time, event association and approval status.
        </p>

        <h2>Music requests</h2>
        <p>
            Music request details may be visible to authorised DJs and event operators in order to manage
            live request queues during events.
        </p>

        <h2>How information is used</h2>
        <p>
            Information is used to operate the website, manage event features, moderate uploads,
            improve the user experience and maintain event administration systems.
        </p>

        <h2>Removing photos</h2>
        <p>
            If you would like a photo reviewed or removed from the public gallery,
            please contact Dance Thru The Decades with enough information to identify the image.
        </p>

        <h2>Contact</h2>
        <p>
            Questions regarding privacy or uploaded content can be directed through the website
            or associated event contact channels.
        </p>
    </div>
</section>

<?php require __DIR__ . '/includes/public-footer.php'; ?>
</main>
</body>
</html>
