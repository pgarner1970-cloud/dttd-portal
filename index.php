<?php
require_once __DIR__ . '/includes/db.php';

$active_event = null;
$active_event_is_public = false;
$active_event_is_private = false;
$homepage_state = 'no-event';
$connected_event = null;
$has_event_access = false;

try {
    /*
     * Important:
     * is_active means the event is available/selectable in the portal.
     * It does NOT mean the event is happening right now.
     *
     * The public homepage should only show live-event actions when the current
     * date/time sits inside the event start/end window.
     *
     * End times earlier than start times are treated as after midnight.
     */
    $active_event = db()->query("
        SELECT *,
            TIMESTAMP(event_date, start_time) AS live_start_at,
            CASE
                WHEN end_time IS NULL OR end_time = '' THEN TIMESTAMP(event_date, start_time)
                WHEN end_time < start_time THEN TIMESTAMP(DATE_ADD(event_date, INTERVAL 1 DAY), end_time)
                ELSE TIMESTAMP(event_date, end_time)
            END AS live_end_at
        FROM events
        WHERE is_active = 1
          AND event_date IS NOT NULL
          AND start_time IS NOT NULL
          AND start_time <> ''
          AND NOW() >= TIMESTAMP(event_date, start_time)
          AND NOW() <= CASE
                WHEN end_time IS NULL OR end_time = '' THEN DATE_ADD(TIMESTAMP(event_date, start_time), INTERVAL 6 HOUR)
                WHEN end_time < start_time THEN TIMESTAMP(DATE_ADD(event_date, INTERVAL 1 DAY), end_time)
                ELSE TIMESTAMP(event_date, end_time)
          END
        ORDER BY event_date ASC, start_time ASC, id ASC
        LIMIT 1
    ")->fetch();

    if ($active_event) {
        $visibility = strtolower((string)($active_event['queue_visibility'] ?? $active_event['visibility'] ?? 'public'));
        $eventType = strtolower((string)($active_event['event_type'] ?? ''));

        $active_event_is_private = (
            $visibility === 'private'
            || str_contains($eventType, 'private')
            || str_contains($eventType, 'wedding')
            || str_contains($eventType, 'birthday')
        );

        $active_event_is_public = !$active_event_is_private;
        $homepage_state = $active_event_is_private ? 'private-event' : 'public-event';
    }
} catch (Throwable $e) {
    $active_event = null;
    $homepage_state = 'no-event';
}

try {
    $connected_event = dttd_event_from_access_cookie(false);
    $has_event_access = $connected_event && dttd_event_access_allowed($connected_event);
} catch (Throwable $e) {
    $connected_event = null;
    $has_event_access = false;
}

$facebookUrl = 'https://www.facebook.com/profile.php?id=61579454050951';
$public_current = 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dance Thru the Decades Events</title>
  <meta name="description" content="Dance Thru the Decades Events — 60s, 70s, 80s, 90s and 00s party nights, DJ events, song requests and Facebook event updates.">
  <link rel="stylesheet" href="/assets/public-site.css?v=167">
</head>
<body class="homepage-option-one">
  <main class="home-option-one">
    <?php require __DIR__ . '/includes/public-nav.php'; ?>
<section class="option-one-hero">
<div class="option-one-crowd" aria-hidden="true"></div>
      <div class="option-one-floor-glow" aria-hidden="true"></div>

      <div class="option-one-inner">
        <div class="option-one-logo-shell"><img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=152" alt="Dance Thru The Decades Events logo"></div>

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

        <div class="option-one-action-strip dynamic-action-strip" data-homepage-state="<?= htmlspecialchars($homepage_state) ?>" aria-label="Event actions">
          <?php if ($homepage_state === 'public-event' && $active_event && !empty($active_event['event_code'])): ?>
            <a class="option-one-action-card primary-action" href="/request.php">
              <span class="option-one-icon">♪</span>
              <span>
                <strong>Request a Song</strong>
                <?php if ($has_event_access): ?><em>You’re connected — request now</em><?php else: ?><em>Available via event QR/code at the venue</em><?php endif; ?>
              </span>
            </a>

            <a class="option-one-action-card" href="/event.php">
              <span class="option-one-icon">▣</span>
              <span>
                <strong>This Event</strong>
                <?php if ($has_event_access): ?><em>Live event hub and details</em><?php else: ?><em>Venue, times and event info</em><?php endif; ?>
              </span>
            </a>

            <a class="option-one-action-card" href="/gallery.php">
              <span class="option-one-icon">▧</span>
              <span>
                <strong>Upload Photos</strong>
                <?php if ($has_event_access): ?><em>Upload photos for this event</em><?php else: ?><em>Upload page opens from event QR/code</em><?php endif; ?>
              </span>
            </a>

          <?php elseif ($homepage_state === 'private-event' && $active_event && !empty($active_event['event_code'])): ?>
            <a class="option-one-action-card primary-action" href="/request.php">
              <span class="option-one-icon">♪</span>
              <span>
                <strong>Guest Requests</strong>
                <em>Use the private event QR/code</em>
              </span>
            </a>

            <a class="option-one-action-card" href="/gallery.php">
              <span class="option-one-icon">▧</span>
              <span>
                <strong>Upload Photos</strong>
                <em>Moderated before display</em>
              </span>
            </a>

            <a class="option-one-action-card" href="/event.php">
              <span class="option-one-icon">▣</span>
              <span>
                <strong>Event Info</strong>
                <em>Private guest event page</em>
              </span>
            </a>

          <?php else: ?>
            <a class="option-one-action-card primary-action" href="/events.php">
              <span class="option-one-icon">▦</span>
              <span>
                <strong>Upcoming Events</strong>
                <em>Public nights and future dates</em>
              </span>
            </a>

            <a class="option-one-action-card" href="/gallery.php">
              <span class="option-one-icon">▧</span>
              <span>
                <strong>Photos & Memories</strong>
                <em>View and share event moments</em>
              </span>
            </a>

            <a class="option-one-action-card" href="<?= htmlspecialchars($facebookUrl) ?>" target="_blank" rel="noopener">
              <span class="option-one-icon">f</span>
              <span>
                <strong>Follow Us</strong>
                <em>Updates, photos and announcements</em>
              </span>
            </a>
          <?php endif; ?>
        </div>

        <?php if ($has_event_access && $connected_event): ?>
          <p class="homepage-state-note connected">You’re connected to <?= h($connected_event['event_name'] ?? 'this event') ?>. This device will be remembered until the event closes.</p>
        <?php elseif ($homepage_state === 'no-event'): ?>
          <p class="homepage-state-note">Song requests open automatically when an event is live.</p>
        <?php endif; ?>
      </div>
    </section>
      <?php require __DIR__ . '/includes/public-footer.php'; ?>
  </main>
</body>
</html>

