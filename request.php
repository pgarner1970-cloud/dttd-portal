<?php
require_once __DIR__ . '/includes/db.php';
$event = request_event();
$available = event_is_available($event);
$requests_open = event_requests_open($event);
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requests_open) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Request a Song</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="topnav"><a href="/">Home</a><a href="/request.php<?= $event ? '?event='.(int)$event['id'] : '' ?>">Song Request</a></nav>
<section class="hero">
  <span class="badge">Song Requests</span>
  <h1>Request a Song</h1>
  <?php if ($event): ?>
    <p class="subtitle"><?= h($event['event_name']) ?> at <?= h($event['venue_name']) ?></p>
  <?php else: ?>
    <p class="subtitle">There is no active event accepting requests right now.</p>
  <?php endif; ?>
</section>

<main class="container">
  <div class="card">
    <?php if (!$available): ?>
      <h2>Requests unavailable</h2>
      <p>Song requests are not currently available. Please check back during the event.</p>
      <a class="btn btn-secondary" href="/">Back to Portal</a>
    <?php elseif (!$requests_open): ?>
      <h2>Requests closed</h2>
      <p>Song requests have closed for this event so the DJ can finish the night smoothly.</p>
      <a class="btn btn-secondary" href="/">Back to Portal</a>
    <?php elseif ($success): ?>
      <h2>Request Sent</h2>
      <p>Thanks — your request has been sent to the DJ.</p>
      <a class="btn btn-primary" href="/request.php?event=<?= (int)$event['id'] ?>">Send Another Request</a>
      <a class="btn btn-secondary" href="/">Back to Portal</a>
    <?php else: ?>
      <?php if ($error): ?><div class="notice"><?= h($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
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
      <p class="small">API-powered song search will be added later. For now, manual requests are stored against this event in MySQL.</p>
    <?php endif; ?>
  </div>
</main>
<footer class="footer">© <?= date('Y') ?> Dance Thru the Decades Events</footer>
</body>
</html>
