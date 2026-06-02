<?php
require_once __DIR__ . '/_auth.php';

admin_header('Sponsors - DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Sponsors</h1>
        <p class="touch-subtitle">Reusable sponsor records for prizes, offers and event promotions.</p>
      </div>
      <div class="settings-actions">
        <a class="touch-btn ghost" href="tools.php">← Admin Tools</a>
      </div>
    </div>

    <div class="settings-card">
      <div class="empty-state">
        Sponsor maintenance is reserved for the next database stage. Sponsors will be reusable records that can later be assigned to one or more events.
      </div>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
