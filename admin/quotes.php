<?php
require_once __DIR__ . '/_auth.php';
$quotes = db()->query("SELECT q.*, i.invoice_number FROM quotations q LEFT JOIN invoices i ON i.quotation_id = q.id ORDER BY q.created_at DESC, q.id DESC")->fetchAll();
admin_header('Quotations - DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Quotations</h1>
        <p class="touch-subtitle">Saved quotations can be downloaded, printed and converted to invoices.</p>
      </div>
      <div class="settings-actions"><a class="touch-btn blue" href="quote-add.php">＋ Add Quotation</a></div>
    </div>
    <?php if (!empty($_GET['saved'])): ?><p class="notice success">Quotation saved.</p><?php endif; ?>
    <div class="event-list">
      <?php foreach ($quotes as $q): ?>
        <article class="event-row-card">
          <div class="event-row-date"><strong><?= h($q['quote_number']) ?></strong><small><?= h(date('d M Y', strtotime($q['created_at']))) ?></small></div>
          <div class="event-row-title"><strong><?= h($q['customer_name']) ?></strong><span><?= h($q['event_description']) ?></span><span><?= h($q['venue']) ?></span></div>
          <div class="event-row-close"><strong>Total</strong><span>£<?= h(number_format((float)$q['total_amount'], 2)) ?></span></div>
          <div class="event-row-actions event-row-actions-only">
            <a class="action-tile duplicate" target="_blank" href="quote-pdf.php?type=quote&id=<?= (int)$q['id'] ?>"><span class="big-icon">PDF</span><span>Quote</span></a>
            <?php if (!empty($q['invoice_number'])): ?>
              <a class="action-tile maybe" target="_blank" href="quote-pdf.php?type=invoice&id=<?= (int)$q['id'] ?>"><span class="big-icon">INV</span><span><?= h($q['invoice_number']) ?></span></a>
            <?php else: ?>
              <a class="action-tile maybe" href="quote-convert.php?id=<?= (int)$q['id'] ?>" onclick="return confirm('Convert this quotation to an invoice? This will allocate the next invoice number.');"><span class="big-icon">→</span><span>Invoice</span></a>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!$quotes): ?><p class="touch-subtitle">No quotations yet.</p><?php endif; ?>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
