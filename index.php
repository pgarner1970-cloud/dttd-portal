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

$connected_requests_open = $has_event_access && $connected_event ? event_requests_open($connected_event) : false;
$connected_request_close_iso = $connected_event ? dttd_event_request_close_iso($connected_event) : '';
$connected_request_timer_label = $connected_event ? dttd_event_request_timer_label($connected_event) : '';
$active_requests_open = $active_event ? event_requests_open($active_event) : false;
$active_request_close_iso = $active_event ? dttd_event_request_close_iso($active_event) : '';
$active_request_timer_label = $active_event ? dttd_event_request_timer_label($active_event) : '';
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
  <link rel="stylesheet" href="/assets/public-site.css?v=170">
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

        <?php if ($has_event_access && $connected_event): ?>
          <section class="home-connected-event-panel" aria-label="Connected event actions">
            <p class="homepage-state-note connected"><strong>You’re connected to <?= h($connected_event['event_name'] ?? 'this event') ?></strong><span>Request songs and upload photos from this device until the event closes.</span></p>
            <div class="option-one-action-strip dynamic-action-strip event-action-strip">
              <?php if ($connected_requests_open): ?>
                <a class="option-one-action-card primary-action request-window-card is-open" href="/request.php">
                  <span class="option-one-icon">♪</span>
                  <span>
                    <strong>Request a Song</strong>
                    <em>Send a request to the DJ queue</em>
                    <small class="public-request-timer" <?= $connected_request_close_iso ? 'data-countdown-to="' . h($connected_request_close_iso) . '"' : '' ?>><?= h($connected_request_timer_label) ?></small>
                  </span>
                </a>
              <?php else: ?>
                <span class="option-one-action-card primary-action request-window-card is-closed" aria-disabled="true">
                  <span class="option-one-icon">♪</span>
                  <span><strong>Requests Closed</strong><em>Song requests are now closed for this event</em><small class="public-request-timer is-closed">Requests closed</small></span>
                </span>
              <?php endif; ?>
              <a class="option-one-action-card" href="/event.php">
                <span class="option-one-icon">▣</span>
                <span><strong>This Event</strong><em>Live event hub and details</em></span>
              </a>
              <a class="option-one-action-card" href="/gallery.php">
                <span class="option-one-icon">▧</span>
                <span><strong>Upload Photos</strong><em>Uploads wait for moderation</em></span>
              </a>
            </div>
          </section>
        <?php elseif (($homepage_state === 'public-event' || $homepage_state === 'private-event') && $active_event && !empty($active_event['event_code'])): ?>
          <section class="home-connected-event-panel home-event-open-panel" aria-label="Live event actions">
            <p class="homepage-state-note"><strong>At tonight’s event?</strong><span>Scan the venue QR code or enter the event code to request songs and upload photos.</span></p>
            <div class="option-one-action-strip dynamic-action-strip event-action-strip">
              <?php if ($active_requests_open): ?>
                <a class="option-one-action-card primary-action request-window-card is-open" href="/request.php">
                  <span class="option-one-icon">♪</span>
                  <span>
                    <strong>Request a Song</strong>
                    <em>Enter the venue code to continue</em>
                    <small class="public-request-timer" <?= $active_request_close_iso ? 'data-countdown-to="' . h($active_request_close_iso) . '"' : '' ?>><?= h($active_request_timer_label) ?></small>
                  </span>
                </a>
              <?php else: ?>
                <span class="option-one-action-card primary-action request-window-card is-closed" aria-disabled="true">
                  <span class="option-one-icon">♪</span>
                  <span><strong>Requests Closed</strong><em>Song requests are now closed for this event</em><small class="public-request-timer is-closed">Requests closed</small></span>
                </span>
              <?php endif; ?>
              <a class="option-one-action-card" href="/event.php">
                <span class="option-one-icon">▣</span>
                <span><strong>This Event</strong><em>Join the event hub</em></span>
              </a>
              <a class="option-one-action-card" href="/gallery.php">
                <span class="option-one-icon">▧</span>
                <span><strong>Upload Photos</strong><em>Available via event QR/code</em></span>
              </a>
            </div>
          </section>
        <?php endif; ?>

        <div class="option-one-action-strip dynamic-action-strip public-home-action-strip" aria-label="Public website actions">
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
        </div>

        <?php if (!$has_event_access && $homepage_state === 'no-event'): ?>
          <p class="homepage-state-note">Song requests open automatically when an event is live.</p>
        <?php endif; ?>
      </div>
    </section>
      <?php require __DIR__ . '/includes/public-footer.php'; ?>
  </main>
<script>
(function(){
  function labelFor(ms){
    if(ms <= 0) return 'Requests closed';
    var total = Math.ceil(ms / 60000);
    var h = Math.floor(total / 60);
    var m = total % 60;
    if(h > 0) return 'Requests close in ' + h + 'h' + (m ? ' ' + m + 'm' : '');
    return 'Requests close in ' + Math.max(1, m) + 'm';
  }
  function tick(){
    document.querySelectorAll('[data-countdown-to]').forEach(function(el){
      var target = Date.parse(el.getAttribute('data-countdown-to'));
      if(!target) return;
      var remaining = target - Date.now();
      el.textContent = labelFor(remaining);
      if(remaining <= 0){
        el.classList.add('is-closed');
        var card = el.closest('.request-window-card');
        if(card){
          card.classList.remove('is-open');
          card.classList.add('is-closed');
        }
      }
    });
  }
  tick();
  setInterval(tick, 30000);
})();
</script>
</body>
</html>

