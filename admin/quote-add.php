<?php
require_once __DIR__ . '/_auth.php';

$defaults = [
    'customer_name' => '', 'customer_email' => '', 'customer_address' => '',
    'event_description' => 'Dance Thru The Decades DJ Entertainment Package',
    'event_date' => '', 'venue' => '', 'notes' => '',
    'line_description' => ['Dance Thru The Decades DJ Entertainment Package'],
    'line_price' => ['350.00'],
];

admin_header('Add Quotation - DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Add Quotation</h1>
        <p class="touch-subtitle">Preview test PDFs without saving, then save as a quotation when ready.</p>
      </div>
      <div class="settings-actions">
        <a class="touch-btn ghost" href="quotes.php">Quotations</a>
        <a class="touch-btn ghost" href="invoices.php">Invoices</a>
      </div>
    </div>

    <form class="settings-form" method="post" action="quote-save.php">
      <div class="settings-grid">
        <label>Customer name
          <input type="text" name="customer_name" required>
        </label>
        <label>Customer email
          <input type="email" name="customer_email">
        </label>
        <label>Event date
          <input type="date" name="event_date">
        </label>
        <label>Venue
          <input type="text" name="venue">
        </label>
      </div>

      <label>Customer address
        <textarea name="customer_address" rows="3"></textarea>
      </label>

      <label>Event / quotation description
        <input type="text" name="event_description" value="<?= h($defaults['event_description']) ?>">
      </label>

      <h2 class="touch-panel-title" style="font-size:1.2rem;margin-top:1rem;">Items</h2>
      <div class="settings-grid">
        <label>Description
          <input type="text" name="line_description[]" value="Dance Thru The Decades DJ Entertainment Package">
        </label>
        <label>Price
          <input type="number" step="0.01" min="0" name="line_price[]" value="350.00">
        </label>
        <label>Description
          <input type="text" name="line_description[]" placeholder="Optional extra item">
        </label>
        <label>Price
          <input type="number" step="0.01" min="0" name="line_price[]" placeholder="0.00">
        </label>
        <label>Description
          <input type="text" name="line_description[]" placeholder="Optional extra item">
        </label>
        <label>Price
          <input type="number" step="0.01" min="0" name="line_price[]" placeholder="0.00">
        </label>
      </div>

      <label>Notes / payment terms
        <textarea name="notes" rows="4" placeholder="Deposit, balance due date, bank details or other terms can go here."></textarea>
      </label>

      <div class="settings-actions">
        <button class="touch-btn ghost" type="submit" formaction="quote-pdf.php?mode=preview" formtarget="_blank">Preview Test PDF</button>
        <button class="touch-btn blue" type="submit">Save Quotation</button>
      </div>
      <p class="touch-subtitle">Preview mode does not save details and does not allocate a quote or invoice number.</p>
    </form>
  </section>
</main>
<?php admin_footer(); ?>
