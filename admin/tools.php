<?php
require_once __DIR__ . '/_auth.php';

admin_header('Admin Tools - DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Admin Tools</h1>
      </div>
    </div>

    <div class="admin-home-grid">
      <a class="admin-home-card" href="events.php">
        <span class="admin-home-icon">▦</span>
        <strong>Events</strong>
        <span>Create, edit and review event setup</span>
      </a>

      <a class="admin-home-card" href="venues.php">
        <span class="admin-home-icon">⌂</span>
        <strong>Venues</strong>
        <span>Maintain venue details and defaults</span>
      </a>

      <a class="admin-home-card" href="event-photos.php">
        <span class="admin-home-icon">◉</span>
        <strong>Photo moderation</strong>
        <span>Review guest uploads and gallery items</span>
      </a>

      <a class="admin-home-card" href="settings.php">
        <span class="admin-home-icon">⚙</span>
        <strong>Settings</strong>
        <span>Portal, request and Spotify settings</span>
      </a>

      <a class="admin-home-card" href="partners.php">
        <span class="admin-home-icon">★</span>
        <strong>Partners</strong>
        <span>DJ suppliers, party suppliers, banner printer and reusable business contacts</span>
      </a>

      <a class="admin-home-card" href="sponsors.php">
        <span class="admin-home-icon">£</span>
        <strong>Sponsors</strong>
        <span>Reusable sponsors ready for event-specific assignments</span>
      </a>

      <a class="admin-home-card" href="event-sponsors.php">
        <span class="admin-home-icon">⇄</span>
        <strong>Event sponsors</strong>
        <span>Per-event prizes, offers and sponsor display settings</span>
      </a>

      <a class="admin-home-card" href="quote-add.php">
        <span class="admin-home-icon">£</span>
        <strong>Quotes &amp; invoices</strong>
        <span>Create previews, save quotations and convert them to invoices</span>
      </a>
    </div>
  </section>

  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h2 class="touch-panel-title">Diagnostics</h2>
      </div>
    </div>

    <div class="admin-home-grid">
      <a class="admin-home-card" href="request-debug.php">
        <span class="admin-home-icon">◎</span>
        <strong>Queue Debug</strong>
        <span>Diagnose request update polling</span>
      </a>

      <a class="admin-home-card" href="events-diagnostic.php">
        <span class="admin-home-icon">!</span>
        <strong>Diagnostics</strong>
        <span>Check event and system diagnostics</span>
      </a>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
