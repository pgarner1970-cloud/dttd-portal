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

    <div class="touch-panel-pad">
      <form class="settings-form event-form-shell" method="post" action="quote-save.php">
        <section class="form-section">
          <div class="form-section-header">
            <div class="form-section-icon">👤</div>
            <div>
              <h2 class="form-section-title">Customer Details</h2>
              <p class="form-section-subtitle">Who the quotation is for.</p>
            </div>
          </div>

          <div class="form-section-body">
            <div class="form-grid">
              <div class="form-field span-6">
                <label for="customer_name">Customer name</label>
                <input id="customer_name" type="text" name="customer_name" required>
              </div>

              <div class="form-field span-6">
                <label for="customer_email">Customer email</label>
                <input id="customer_email" type="email" name="customer_email">
              </div>

              <div class="form-field span-12">
                <label for="customer_address">Customer address</label>
                <textarea id="customer_address" name="customer_address" rows="3"></textarea>
              </div>
            </div>
          </div>
        </section>

        <section class="form-section">
          <div class="form-section-header">
            <div class="form-section-icon">📅</div>
            <div>
              <h2 class="form-section-title">Event Details</h2>
              <p class="form-section-subtitle">The event, date and venue for the booking.</p>
            </div>
          </div>

          <div class="form-section-body">
            <div class="form-grid">
              <div class="form-field span-6">
                <label for="event_description">Event / quotation description</label>
                <input id="event_description" type="text" name="event_description" value="<?= h($defaults['event_description']) ?>">
              </div>

              <div class="form-field span-3">
                <label for="event_date">Event date</label>
                <input id="event_date" type="date" name="event_date">
              </div>

              <div class="form-field span-3">
                <label for="venue">Venue</label>
                <input id="venue" type="text" name="venue">
              </div>
            </div>
          </div>
        </section>

        <section class="form-section">
          <div class="form-section-header">
            <div class="form-section-icon">£</div>
            <div>
              <h2 class="form-section-title">Items</h2>
              <p class="form-section-subtitle">Add the main package and any optional extras.</p>
            </div>
          </div>

          <div class="form-section-body">
            <div class="form-grid">
              <div class="form-field span-8">
                <label for="line_description_1">Description</label>
                <input id="line_description_1" type="text" name="line_description[]" value="Dance Thru The Decades DJ Entertainment Package">
              </div>
              <div class="form-field span-4">
                <label for="line_price_1">Price</label>
                <input id="line_price_1" type="number" step="0.01" min="0" name="line_price[]" value="350.00">
              </div>

              <div class="form-field span-8">
                <label for="line_description_2">Description</label>
                <input id="line_description_2" type="text" name="line_description[]" placeholder="Optional extra item">
              </div>
              <div class="form-field span-4">
                <label for="line_price_2">Price</label>
                <input id="line_price_2" type="number" step="0.01" min="0" name="line_price[]" placeholder="0.00">
              </div>

              <div class="form-field span-8">
                <label for="line_description_3">Description</label>
                <input id="line_description_3" type="text" name="line_description[]" placeholder="Optional extra item">
              </div>
              <div class="form-field span-4">
                <label for="line_price_3">Price</label>
                <input id="line_price_3" type="number" step="0.01" min="0" name="line_price[]" placeholder="0.00">
              </div>
            </div>
          </div>
        </section>

        <section class="form-section">
          <div class="form-section-header">
            <div class="form-section-icon">📝</div>
            <div>
              <h2 class="form-section-title">Notes / Payment Terms</h2>
              <p class="form-section-subtitle">Deposit, balance due date, bank details or other terms.</p>
            </div>
          </div>

          <div class="form-section-body">
            <div class="form-grid">
              <div class="form-field span-12">
                <label for="notes">Notes / payment terms</label>
                <textarea id="notes" name="notes" rows="4" placeholder="Deposit, balance due date, bank details or other terms can go here."></textarea>
              </div>
            </div>
          </div>
        </section>

        <p class="touch-subtitle">Preview mode does not save details and does not allocate a quote or invoice number.</p>

        <div class="form-actions">
          <button class="touch-btn ghost" type="submit" formaction="quote-pdf.php?mode=preview" formtarget="_blank">Preview Test PDF</button>
          <button class="touch-btn blue" type="submit">Save Quotation</button>
        </div>
      </form>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
