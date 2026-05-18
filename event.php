<?php
require_once __DIR__ . '/includes/db.php';

$code = strtoupper(trim($_GET['code'] ?? ''));
$token = trim($_GET['token'] ?? '');

$event = null;

if ($code !== '') {
    $stmt = db()->prepare("SELECT * FROM events WHERE UPPER(event_code) = UPPER(?) LIMIT 1");
    $stmt->execute([$code]);
    $event = $stmt->fetch();
}

if (!$event && $token !== '') {
    $stmt = db()->prepare("SELECT * FROM events WHERE guest_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $event = $stmt->fetch();
}

function public_event_status($event) {
    if (!$event) return 'missing';

    $today = date('Y-m-d');

    if (!empty($event['event_date']) && $event['event_date'] < $today) {
        return 'past';
    }

    if (!empty($event['event_date']) && $event['event_date'] > $today) {
        return 'future';
    }

    return 'today';
}

$status = public_event_status($event);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $event ? h($event['event_name']) . ' | ' : '' ?>Dance Thru the Decades Events</title>
<link rel="stylesheet" href="/assets/style.css">
<style>
.event-hero-card{
  max-width:920px;
  margin:0 auto;
  text-align:center;
}
.event-code-pill{
  display:inline-block;
  padding:8px 14px;
  border-radius:999px;
  background:rgba(255,255,255,.10);
  border:1px solid rgba(255,255,255,.18);
  color:#ffd15c;
  font-weight:800;
  margin-top:12px;
}
.event-actions{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
  gap:14px;
  margin-top:18px;
}
.event-meta-grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
  gap:12px;
  margin-top:16px;
  text-align:left;
}
.event-meta-box{
  background:rgba(0,0,0,.20);
  border:1px solid rgba(255,255,255,.12);
  border-radius:16px;
  padding:14px;
}
.event-meta-box strong{
  display:block;
  color:#ffd15c;
  margin-bottom:4px;
}
</style>
  <link rel="stylesheet" href="/assets/public.css?v=125">
</head>
<body class="public-page">
<nav class="topnav"><a href="/">Home</a></nav>

<section class="hero">
  <span class="badge">Event Portal</span>
  <?php if ($event): ?>
    <h1><?= h($event['event_name']) ?></h1>
    <p class="subtitle"><?= h($event['venue_name']) ?></p>
    <?php if (!empty($event['event_code'])): ?>
      <span class="event-code-pill">Event Code: <?= h($event['event_code']) ?></span>
    <?php endif; ?>
  <?php else: ?>
    <h1>Event Not Found</h1>
    <p class="subtitle">This event link is not recognised.</p>
  <?php endif; ?>
</section>

<main class="container">
      <div class="public-logo-wrap"><img class="public-logo" src="/assets/dttd-logo.webp" alt="Dance Thru The Decades Events logo"></div>
  <div class="card event-hero-card">
    <?php if (!$event): ?>
      <h2>Check the link or QR code</h2>
      <p>Please check that the event code has been entered correctly, or scan the QR code again at the venue.</p>
      <a class="btn btn-secondary" href="/">Back to Website</a>
    <?php else: ?>
      <h2>Welcome</h2>

      <div class="event-meta-grid">
        <div class="event-meta-box">
          <strong>Date</strong>
          <?= h($event['event_date'] ? date('D, j M Y', strtotime($event['event_date'])) : 'Date to be confirmed') ?>
        </div>
        <div class="event-meta-box">
          <strong>Time</strong>
          <?= h(input_time($event['start_time'])) ?><?= !empty($event['end_time']) ? ' - ' . h(input_time($event['end_time'])) : '' ?>
        </div>
        <div class="event-meta-box">
          <strong>Status</strong>
          <?php if ($status === 'today'): ?>
            Live / today
          <?php elseif ($status === 'future'): ?>
            Upcoming event
          <?php else: ?>
            Past event
          <?php endif; ?>
        </div>
      </div>

      <div class="event-actions">
        <?php if (!empty($event['event_code'])): ?>
        <a class="btn btn-primary" href="/request.php?code=<?= h($event['event_code']) ?>">Request a Song</a>
        <?php elseif (!empty($event['guest_token'])): ?>
        <a class="btn btn-primary" href="/request.php?token=<?= h($event['guest_token']) ?>">Request a Song</a>
        <?php else: ?>
        <a class="btn btn-primary" href="/request.php?event=<?= (int)$event['id'] ?>">Request a Song</a>
        <?php endif; ?>
        <a class="btn btn-secondary" href="<?= h(FACEBOOK_URL) ?>" target="_blank">Follow on Facebook</a>
        <a class="btn btn-green" href="<?= h(FACEBOOK_URL) ?>" target="_blank">Check In / Tag Us</a>
        <a class="btn btn-gold" href="<?= h(FACEBOOK_URL) ?>" target="_blank">Share Photos</a>
      </div>

      <p class="small" style="margin-top:18px">
        This event page is intended for guests with the event link or QR code. Song request gating will be added in the next stage.
      </p>
    <?php endif; ?>
  </div>
</main>

<footer class="footer">
  © <?= date('Y') ?> Dance Thru the Decades Events · <a href="/privacy.php">Privacy</a> · <a href="/terms.php">Wi‑Fi Terms</a>
</footer>
</body>
</html>
