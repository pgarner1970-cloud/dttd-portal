<?php admin_header('Admin Tools', 'tools'); ?>

<div class="admin-page-card">
  <div class="admin-page-card-header">
    <h2>Admin Tools</h2>
  </div>

  <div class="admin-home-grid">
    <a class="admin-home-card" href="events.php">
      <span class="admin-home-icon" aria-hidden="true">▦</span>
      <strong>Events</strong>
      <span>Create, edit and review event setup</span>
    </a>

    <a class="admin-home-card" href="venues.php">
      <span class="admin-home-icon" aria-hidden="true">⌂</span>
      <strong>Venues</strong>
      <span>Maintain venue details and defaults</span>
    </a>

    <a class="admin-home-card" href="event-photos.php">
      <span class="admin-home-icon" aria-hidden="true">◎</span>
      <strong>Photo moderation</strong>
      <span>Review guest uploads and gallery items</span>
    </a>

    <a class="admin-home-card" href="settings.php">
      <span class="admin-home-icon" aria-hidden="true">⚙</span>
      <strong>Settings</strong>
      <span>Portal, request and Spotify settings</span>
    </a>

    <a class="admin-home-card" href="partners.php">
      <span class="admin-home-icon" aria-hidden="true">★</span>
      <strong>Partners</strong>
      <span>DJ suppliers, party suppliers, banner printer and reusable business contacts</span>
    </a>

    <a class="admin-home-card" href="sponsors.php">
      <span class="admin-home-icon" aria-hidden="true">£</span>
      <strong>Sponsors</strong>
      <span>Reusable sponsors ready for event-specific assignments</span>
    </a>

    <a class="admin-home-card" href="event-sponsors.php">
      <span class="admin-home-icon" aria-hidden="true">⇄</span>
      <strong>Event sponsors</strong>
      <span>Per-event prizes, offers and sponsor display settings</span>
    </a>

    <a class="admin-home-card" href="quote-add.php">
      <span class="admin-home-icon" aria-hidden="true">£</span>
      <strong>Quotes &amp; invoices</strong>
      <span>Create test previews, save quotations and convert them to invoices</span>
    </a>
  </div>
</div>

<div class="admin-page-card">
  <div class="admin-page-card-header">
    <h2>Diagnostics</h2>
  </div>

  <div class="admin-home-grid">
    <a class="admin-home-card" href="request-debug.php">
      <span class="admin-home-icon" aria-hidden="true">◎</span>
      <strong>Queue Debug</strong>
      <span>Diagnose request update polling</span>
    </a>

    <a class="admin-home-card" href="events-diagnostic.php">
      <span class="admin-home-icon" aria-hidden="true">!</span>
      <strong>Diagnostics</strong>
      <span>Check event and system diagnostics</span>
    </a>
  </div>
</div>

<?php admin_footer(); ?>
