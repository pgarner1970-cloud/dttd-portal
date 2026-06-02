<?php
require_once __DIR__ . '/_auth.php';

admin_header('Partners - DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Partners</h1>
        <p class="touch-subtitle">Behind-the-scenes partner records for suppliers and trusted contacts.</p>
      </div>
      <div class="settings-actions">
        <a class="touch-btn ghost" href="tools.php">← Admin Tools</a>
      </div>
    </div>

    <div class="settings-card">
      <div class="empty-state">
        Partner maintenance is reserved for the next database stage. This page is now in place so the Admin Tools structure can be wired without adding more live DJ header buttons.
      </div>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
