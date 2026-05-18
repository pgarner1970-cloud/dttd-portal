<?php
require_once __DIR__ . '/includes/db.php';

$event = null;
$access_ok = false;

$event_id = !empty($_GET['event']) ? (int)$_GET['event'] : (!empty($_POST['event_id']) ? (int)$_POST['event_id'] : 0);
$code = strtoupper(trim($_GET['code'] ?? $_POST['code'] ?? ''));
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

if ($code !== '') {
    $stmt = db()->prepare("SELECT * FROM events WHERE UPPER(event_code) = UPPER(?) LIMIT 1");
    $stmt->execute([$code]);
    $event = $stmt->fetch();
    $access_ok = (bool)$event;
} elseif ($token !== '') {
    $stmt = db()->prepare("SELECT * FROM events WHERE guest_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $event = $stmt->fetch();
    $access_ok = (bool)$event;
} elseif ($event_id) {
    // Backwards-compatible admin-style link support only if a matching event is found,
    // but guest submission is still blocked unless code/token is supplied.
    $event = get_event($event_id);
}

$available = event_is_available($event);
$requests_open = event_requests_open($event);
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $access_ok && $requests_open) {
    $guest_name = trim($_POST['guest_name'] ?? '');
    $song_title = trim($_POST['song_title'] ?? '');
    $artist = trim($_POST['artist'] ?? '');
    $dedication = trim($_POST['dedication'] ?? '');

    if ($guest_name === '' || $song_title === '' || $artist === '') {
        $error = 'Please enter your name, song title and artist.';
    } else {
        $stmt = db()->prepare("INSERT INTO song_requests (event_id, guest_name, song_title, artist, dedication) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$event['id'], $guest_name, $song_title, $artist, $dedication]);
        $success = true;
    }
}

function request_link_for_event($event) {
    if (!$event) return '/';
    if (!empty($event['event_code'])) {
        return '/event.php?code=' . urlencode($event['event_code']);
    }
    if (!empty($event['guest_token'])) {
        return '/event.php?token=' . urlencode($event['guest_token']);
    }
    return '/';
}

function request_self_link($event) {
    if (!$event) return '/request.php';
    if (!empty($event['event_code'])) {
        return '/request.php?code=' . urlencode($event['event_code']);
    }
    if (!empty($event['guest_token'])) {
        return '/request.php?token=' . urlencode($event['guest_token']);
    }
    return '/request.php?event=' . (int)$event['id'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Request a Song</title>
<link rel="stylesheet" href="/assets/style.css">
  <link rel="stylesheet" href="/assets/public.css?v=127">
</head>
<body class="public-page">
<nav class="topnav">
  <a href="/">Home</a>
  <?php if ($event && $access_ok): ?><a href="<?= h(request_link_for_event($event)) ?>">Event Portal</a><?php endif; ?>
</nav>

<section class="hero">
      <div class="homepage-logo-wrap"><img class="site-logo-img" src="/assets/dttd-logo.webp" alt="Dance Thru The Decades Events logo"></div>
  <span class="badge">Song Requests</span>
  <h1>Request a Song</h1>
  <?php if ($event): ?>
    <p class="subtitle"><?= h($event['event_name']) ?> at <?= h($event['venue_name']) ?></p>
  <?php else: ?>
    <p class="subtitle">Please scan the venue QR code or use the event link.</p>
  <?php endif; ?>
</section>

<main class="container">
      <div class="public-logo-wrap public-logo-primary"><img class="public-logo" src="/assets/dttd-logo.webp" alt="Dance Thru The Decades Events logo"></div>
<div class="card">
    <?php if (!$access_ok): ?>
      <h2>Event access required</h2>
      <p>Song requests are only available from a valid event link or QR code.</p>
      <p>Please scan the QR code at the venue, or open the event link provided by the DJ.</p>
      <a class="btn btn-secondary" href="/">Back to Website</a>
    <?php elseif (!$available): ?>
      <h2>Requests unavailable</h2>
      <p>Song requests are not currently available for this event. Please check back during the event.</p>
      <a class="btn btn-secondary" href="<?= h(request_link_for_event($event)) ?>">Back to Event Portal</a>
    <?php elseif (!$requests_open): ?>
      <h2>Requests closed</h2>
      <p>Song requests have closed for this event so the DJ can finish the night smoothly.</p>
      <a class="btn btn-secondary" href="<?= h(request_link_for_event($event)) ?>">Back to Event Portal</a>
    <?php elseif ($success): ?>
      <h2>Request Sent</h2>
      <p>Thanks — your request has been sent to the DJ.</p>
      <a class="btn btn-primary" href="<?= h(request_self_link($event)) ?>">Send Another Request</a>
      <a class="btn btn-secondary" href="<?= h(request_link_for_event($event)) ?>">Back to Event Portal</a>
    <?php else: ?>
      <?php if ($error): ?><div class="notice"><?= h($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
        <?php if (!empty($event['event_code'])): ?><input type="hidden" name="code" value="<?= h($event['event_code']) ?>"><?php endif; ?>
        <?php if (!empty($event['guest_token'])): ?><input type="hidden" name="token" value="<?= h($event['guest_token']) ?>"><?php endif; ?>

        <label>Your name *</label>
        <input name="guest_name" required maxlength="120" placeholder="Your name">

        <label>Song title *</label>
        <input name="song_title" required maxlength="190" placeholder="Example: September">

        <label>Artist *</label>
        <input name="artist" required maxlength="190" placeholder="Example: Earth, Wind & Fire">

        <label>Dedication / message</label>
        <textarea name="dedication" placeholder="Optional message or dedication"></textarea>

        <button class="btn btn-primary" type="submit">Send Request</button>
      </form>
      <p class="small">Song requests are linked to this event only.</p>
    <?php endif; ?>
  </div>
</main>
<footer class="footer">© <?= date('Y') ?> Dance Thru the Decades Events</footer>
</body>
</html>
