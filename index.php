<?php
require_once __DIR__ . '/includes/db.php';
$event = current_event();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(SITE_NAME) ?> Guest Wi‑Fi</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<section class="hero">
  <span class="badge">Guest Wi‑Fi Portal</span>
  <h1>Dance Thru the Decades</h1>
  <p class="subtitle"><?= h($event['event_name']) ?> at <?= h($event['venue_name']) ?> — follow, check in, share photos and request your favourite songs.</p>
</section>

<main class="container">
  <div class="grid">
    <div class="card">
      <h2>Follow Us</h2>
      <p>Follow Dance Thru the Decades Events on Facebook for events, photos and updates.</p>
      <a class="btn btn-secondary" href="<?= h(FACEBOOK_URL) ?>" target="_blank">Follow on Facebook</a>
    </div>

    <div class="card">
      <h2>Check In</h2>
      <p>Let people know you’re here and tag us in your photos from tonight.</p>
      <a class="btn btn-green" href="<?= h(FACEBOOK_URL) ?>" target="_blank">Open Facebook Page</a>
    </div>

    <div class="card">
      <h2>Request a Song</h2>
      <p>Send your request to the DJ. Dedications welcome.</p>
      <a class="btn btn-primary" href="/request.php">Request a Song</a>
    </div>

    <div class="card">
      <h2>Photos</h2>
      <p>Share your best dancefloor moments.</p>
      <p><strong>#DanceThruTheDecades</strong></p>
      <a class="btn btn-gold" href="<?= h(FACEBOOK_URL) ?>" target="_blank">Tag Us on Facebook</a>
    </div>
  </div>

  <div class="notice">
    <h2>Guest Internet Access</h2>
    <p>When the router is added, this page can become the captive portal landing page. For now, it works as your public event hub.</p>
    <a class="btn btn-green" href="/request.php">Continue</a>
  </div>
</main>

<footer class="footer">
  © <?= date('Y') ?> Dance Thru the Decades Events · <a href="/privacy.php">Privacy</a> · <a href="/terms.php">Wi‑Fi Terms</a>
</footer>
</body>
</html>
