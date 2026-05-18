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
  <link rel="stylesheet" href="/assets/public-site.css">
</head>
<body>
  <header class="site-header">
    <a class="brand" href="/">
      <span class="brand-mark">♫</span>
      <span>Dance Thru the Decades</span>
    </a>

    <nav class="site-nav">
      <a href="<?= htmlspecialchars($facebookUrl) ?>" target="_blank" rel="noopener">Facebook</a>
      <?php if ($active_event && !empty($active_event['event_code'])): ?>
        <a href="/event.php?code=<?= htmlspecialchars($active_event['event_code']) ?>">Tonight’s Event</a>
      <?php endif; ?>
    </nav>
  </header>

  <main>
    <section class="hero">
      <div class="hero-glow"></div>
      <div class="hero-content">
        <p class="eyebrow">60s · 70s · 80s · 90s · 00s</p>
        <h1>Dance Thru the Decades Events</h1>
        <p class="subtitle">
          Feel-good party nights, classic floor-fillers and event moments worth sharing.
        </p>

        <div class="hero-actions">
          <a class="btn btn-facebook" href="<?= htmlspecialchars($facebookUrl) ?>" target="_blank" rel="noopener">
            Follow us on Facebook
          </a>
          <a class="btn btn-secondary" href="<?= htmlspecialchars($facebookUrl) ?>" target="_blank" rel="noopener">
            Check in / Tag us
          </a>
        </div>

        <p class="hero-note">
          Follow the page for event updates, photos, song posts and future dance nights.
        </p>
      </div>
    </section>

    <section class="cards">
      <article class="card">
        <div class="card-icon">👍</div>
        <h2>Follow Us</h2>
        <p>Keep up with upcoming nights, event photos, playlists and announcements.</p>
        <a href="<?= htmlspecialchars($facebookUrl) ?>" target="_blank" rel="noopener">Open Facebook Page</a>
      </article>

      <article class="card">
        <div class="card-icon">📍</div>
        <h2>Check In</h2>
        <p>At one of our events? Check in, tag us and let your friends know you’re there.</p>
        <a href="<?= htmlspecialchars($facebookUrl) ?>" target="_blank" rel="noopener">Check in on Facebook</a>
      </article>

      <article class="card">
        <div class="card-icon">📸</div>
        <h2>Photos & Memories</h2>
        <p>Share your best dancefloor moments and tag the page so we can see them.</p>
        <a href="<?= htmlspecialchars($facebookUrl) ?>" target="_blank" rel="noopener">Share / Tag Photos</a>
      </article>
    </section>

    <section class="event-strip">
      <div>
        <p class="eyebrow">At an event?</p>
        <h2>Scan the event QR code</h2>
        <p>
          At selected events, guests can scan a venue QR code to open the event portal,
          request songs and access event-specific features.
        </p>
      </div>

      <?php if ($active_event && !empty($active_event['event_code'])): ?>
        <a class="btn btn-primary" href="/event.php?code=<?= htmlspecialchars($active_event['event_code']) ?>">
          Open Current Event
        </a>
      <?php else: ?>
        <span class="coming-soon">Event access coming soon</span>
      <?php endif; ?>
    </section>

    <section class="wifi-strip">
      <div class="wifi-icon">📶</div>
      <div>
        <h2>Guest Wi‑Fi at events</h2>
        <p>
          We’re building a branded Wi‑Fi welcome page so guests can follow, check in,
          accept terms and then continue into the event portal.
        </p>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <p>© <?= date('Y') ?> Dance Thru the Decades Events</p>
    <p>
      <a href="/privacy.php">Privacy</a>
      <span>·</span>
      <a href="/terms.php">Wi‑Fi Terms</a>
      <span>·</span>
      <a href="<?= htmlspecialchars($facebookUrl) ?>" target="_blank" rel="noopener">Facebook</a>
    </p>
  </footer>
</body>
</html>
