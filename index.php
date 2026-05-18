<?php
require_once __DIR__ . '/includes/db.php';

$active_event = null;
try {
    $active_event = db()->query("
        SELECT *
        FROM events
        WHERE is_active = 1
        ORDER BY event_date DESC, id DESC
        LIMIT 1
    ")->fetch();
} catch (Throwable $e) {
    $active_event = null;
}

$facebookUrl = 'https://www.facebook.com/profile.php?id=61579454050951';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dance Thru the Decades Events</title>
  <meta name="description" content="Dance Thru the Decades Events — 60s, 70s, 80s, 90s and 00s party nights, DJ events, song requests and Facebook event updates.">
  <link rel="stylesheet" href="/assets/public-site.css?v=140">
</head>
<body class="homepage-option-one">
  <main class="home-option-one">
    <a class="public-dj-login" href="/admin/">
      <span class="login-icon">♬</span>
      <span>DJ Login</span>
    </a>
    <section class="option-one-hero">
      <img class="glitter-ball-img" src="/assets/glitter-ball-clean.png?v=140" alt="" aria-hidden="true">
      <div class="option-one-disco-ball" aria-hidden="true"></div>
      <div class="option-one-crowd" aria-hidden="true"></div>
      <div class="option-one-floor-glow" aria-hidden="true"></div>

      <div class="option-one-inner">
        <div class="option-one-logo-shell"><img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=140" alt="Dance Thru The Decades Events logo"></div>

        <p class="option-one-eyebrow">60s · 70s · 80s · 90s · 00s</p>

        <h1>
          <span class="headline-main">DANCE THRU</span>
          <span class="headline-the"><i></i><b>THE</b><i></i></span>
          <span class="headline-main">DECADES</span>
          <span class="events-line">EVENTS</span>
        </h1>

        <p class="option-one-subtitle">
          Feel-good party nights, classic floor-fillers and moments worth sharing.
        </p>

        <div class="option-one-action-strip" aria-label="Event actions">
          <?php if ($active_event && !empty($active_event['event_code'])): ?>
            <a class="option-one-action-card" href="/request.php?code=<?= htmlspecialchars($active_event['event_code']) ?>">
          <?php else: ?>
            <a class="option-one-action-card" href="/request.php">
          <?php endif; ?>
              <span class="option-one-icon">♪</span>
              <span>
                <strong>Request a Song</strong>
                <em>Scan the QR or make a request</em>
              </span>
            </a>

          <?php if ($active_event && !empty($active_event['event_code'])): ?>
            <a class="option-one-action-card" href="/event.php?code=<?= htmlspecialchars($active_event['event_code']) ?>">
          <?php else: ?>
            <a class="option-one-action-card" href="/event.php">
          <?php endif; ?>
              <span class="option-one-icon">▣</span>
              <span>
                <strong>This Event</strong>
                <em>See tonight’s event details</em>
              </span>
            </a>

          <a class="option-one-action-card" href="#memories">
            <span class="option-one-icon">▣</span>
            <span>
              <strong>Photos & Memories</strong>
              <em>Share your best dancefloor moments</em>
            </span>
          </a>
        </div>
      </div>
    </section>
<section class="home-info-section" id="memories">
      <div class="home-info-grid">
        <article class="home-info-card">
          <span>👍</span>
          <h2>Follow Us</h2>
          <p>Keep up with upcoming nights, event photos, playlists and announcements.</p>
        </article>

        <article class="home-info-card">
          <span>📍</span>
          <h2>Check In</h2>
          <p>At one of our events? Check in, tag us and let your friends know you’re there.</p>
        </article>

        <article class="home-info-card">
          <span>📸</span>
          <h2>Photos & Memories</h2>
          <p>Share your best dancefloor moments and tag the page so we can see them.</p>
        </article>
      </div>
    </section>
  </main>
</body>
</html>

