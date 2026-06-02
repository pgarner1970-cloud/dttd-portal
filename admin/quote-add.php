<?php
require_once __DIR__ . '/_auth.php';

$defaults = [
    'customer_name' => '', 'customer_email' => '', 'customer_address' => '',
    'event_description' => 'Dance Thru The Decades DJ Entertainment Package',
    'event_date' => '', 'venue' => '', 'notes' => '',
    'line_description' => ['Dance Thru The Decades DJ Entertainment Package'],
    'line_price' => ['350.00'],
    'deposit_percentage' => '20.00',
    'deposit_due_date' => date('Y-m-d', strtotime('+30 days')),
    'balance_due_date' => '',
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
            <div class="form-section-icon">💷</div>
            <div>
              <h2 class="form-section-title">Payment Schedule</h2>
              <p class="form-section-subtitle">Defaults are 20% deposit, due 30 days from quote date, with the balance due 14 days before the event.</p>
            </div>
          </div>

          <div class="form-section-body">
            <div class="form-grid">
              <div class="form-field span-4">
                <label for="deposit_percentage">Deposit percentage</label>
                <input id="deposit_percentage" type="number" step="0.01" min="0" max="100" name="deposit_percentage" value="<?= h($defaults['deposit_percentage']) ?>">
                <p class="field-help">Set to 0 if no deposit is required.</p>
              </div>

              <div class="form-field span-4">
                <label for="deposit_due_date">Deposit due date</label>
                <input id="deposit_due_date" type="date" name="deposit_due_date" value="<?= h($defaults['deposit_due_date']) ?>">
                <p class="field-help">Not applicable when the deposit is 0%.</p>
              </div>

              <div class="form-field span-4">
                <label for="balance_due_date">Balance due date</label>
                <input id="balance_due_date" type="date" name="balance_due_date">
                <label class="checkbox-row" style="margin-top:10px;">
                  <input id="balance_due_event_date" type="checkbox" name="balance_due_event_date" value="1">
                  <span>Use event date</span>
                </label>
                <p class="field-help">If left blank, it will default to 14 days before the event.</p>
              </div>
            </div>
          </div>
        </section>

        <section class="form-section">
          <div class="form-section-header">
            <div class="form-section-icon">📝</div>
            <div>
              <h2 class="form-section-title">Notes / Payment Terms</h2>
              <p class="form-section-subtitle">Optional extra wording. If left blank, the PDF will use a simple thank-you message.</p>
            </div>
          </div>

          <div class="form-section-body">
            <div class="form-grid">
              <div class="form-field span-12">
                <label for="notes">Notes / payment terms</label>
                <textarea id="notes" name="notes" rows="4" placeholder="Optional. Leave blank to use the default thank-you message."></textarea>
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

<script>
(function () {
  const eventDate = document.getElementById('event_date');
  const depositPercentage = document.getElementById('deposit_percentage');
  const depositDue = document.getElementById('deposit_due_date');
  const balanceDue = document.getElementById('balance_due_date');
  const useEventDate = document.getElementById('balance_due_event_date');

  function isoDate(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  function refreshDepositDateState() {
    if (!depositPercentage || !depositDue) return;
    const pct = parseFloat(depositPercentage.value || '0');
    depositDue.disabled = pct <= 0;
    if (pct <= 0) {
      depositDue.value = '';
    } else if (!depositDue.value) {
      const d = new Date();
      d.setDate(d.getDate() + 30);
      depositDue.value = isoDate(d);
    }
  }

  function refreshBalanceDueDate(forceDefault) {
    if (!eventDate || !balanceDue || !eventDate.value) return;
    const event = new Date(eventDate.value + 'T12:00:00');
    if (Number.isNaN(event.getTime())) return;
    if (useEventDate && useEventDate.checked) {
      balanceDue.value = eventDate.value;
      return;
    }
    if (forceDefault || !balanceDue.value) {
      event.setDate(event.getDate() - 14);
      balanceDue.value = isoDate(event);
    }
  }

  if (depositPercentage) depositPercentage.addEventListener('input', refreshDepositDateState);
  if (eventDate) eventDate.addEventListener('change', function () { refreshBalanceDueDate(true); });
  if (useEventDate) useEventDate.addEventListener('change', function () { refreshBalanceDueDate(true); });
  refreshDepositDateState();
})();
</script>
<?php admin_footer(); ?>
