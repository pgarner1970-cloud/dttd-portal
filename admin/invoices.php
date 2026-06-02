<?php
require_once __DIR__ . '/_auth.php';
$invoices = db()->query("SELECT i.*, q.customer_name, q.event_description, q.venue FROM invoices i INNER JOIN quotations q ON q.id = i.quotation_id ORDER BY i.created_at DESC, i.id DESC")->fetchAll();
admin_header('Invoices - DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div><h1 class="touch-panel-title">Invoices</h1><p class="touch-subtitle">Invoices are only created by converting a saved quotation.</p></div>
      <div class="settings-actions"><a class="touch-btn ghost" href="quotes.php">Quotations</a></div>
    </div>
    <?php if (!empty($_GET['created'])): ?><p class="notice success">Invoice created.</p><?php endif; ?>
    <div class="event-list">
      <?php foreach ($invoices as $i): ?>
        <article class="event-row-card">
          <div class="event-row-date"><strong><?= h($i['invoice_number']) ?></strong><small><?= h(date('d M Y', strtotime($i['created_at']))) ?></small></div>
          <div class="event-row-title"><strong><?= h($i['customer_name']) ?></strong><span><?= h($i['event_description']) ?></span><span><?= h($i['venue']) ?></span></div>
          <div class="event-row-close"><strong>Total</strong><span>£<?= h(number_format((float)$i['total_amount'], 2)) ?></span></div>
          <div class="event-row-actions event-row-actions-only"><a class="action-tile duplicate" target="_blank" href="quote-pdf.php?type=invoice&id=<?= (int)$i['quotation_id'] ?>"><span class="big-icon">PDF</span><span>Invoice</span></a></div>
        </article>
      <?php endforeach; ?>
      <?php if (!$invoices): ?><p class="touch-subtitle">No invoices yet.</p><?php endif; ?>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
