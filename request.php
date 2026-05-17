<?php
require_once __DIR__ . '/includes/db.php';
$event = current_event();
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
<nav class="topnav"><a href="/">Home</a><a href="/request.php">Song Request</a></nav>
<section class="hero">
  <span class="badge">Song Requests</span>
  <h1>Request a Song</h1>
  <p class="subtitle">Request a track from the 60s, 70s, 80s, 90s, 00s or anything that gets the party moving.</p>
</section>

<main class="container">
  <div class="card">
    <?php if ($success): ?>
      <h2>Request Sent</h2>
      <p>Thanks — your request has been sent to the DJ.</p>
      <a class="btn btn-primary" href="/request.php">Send Another Request</a>
      <a class="btn btn-secondary" href="/">Back to Portal</a>
    <?php else: ?>
      <?php if ($error): ?><div class="notice"><?= h($error) ?></div><?php endif; ?>
      <form method="post">
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
      <p class="small">API-powered song search will be added later. For now, manual requests are stored in MySQL.</p>
    <?php endif; ?>
  </div>
</main>
<footer class="footer">© <?= date('Y') ?> Dance Thru the Decades Events</footer>
</body>
</html>
