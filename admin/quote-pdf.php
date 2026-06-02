<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../includes/simple-pdf.php';

function dttd_post_items() {
    $items = [];
    $descs = $_POST['line_description'] ?? [];
    $prices = $_POST['line_price'] ?? [];
    foreach ($descs as $i => $desc) {
        $desc = trim((string)$desc);
        $price = (float)($prices[$i] ?? 0);
        if ($desc !== '' && $price >= 0) {
            $items[] = ['description' => $desc, 'price' => number_format($price, 2, '.', '')];
        }
    }
    return $items ?: [['description' => 'Dance Thru The Decades DJ Entertainment Package', 'price' => '0.00']];
}

function dttd_pdf_money($amount) {
    return chr(163) . number_format((float)$amount, 2);
}

$mode = $_GET['mode'] ?? '';
$type = $_GET['type'] ?? 'quote';
$isPreview = $mode === 'preview';
$invoiceNumber = '';

if ($isPreview) {
    $items = dttd_post_items();
    $total = 0; foreach ($items as $item) { $total += (float)$item['price']; }
    $doc = [
        'number' => 'PREVIEW',
        'customer_name' => trim($_POST['customer_name'] ?? 'Preview Customer'),
        'customer_email' => trim($_POST['customer_email'] ?? ''),
        'customer_address' => trim($_POST['customer_address'] ?? ''),
        'event_description' => trim($_POST['event_description'] ?? 'Dance Thru The Decades Event'),
        'event_date' => trim($_POST['event_date'] ?? ''),
        'venue' => trim($_POST['venue'] ?? ''),
        'items' => $items,
        'notes' => trim($_POST['notes'] ?? ''),
        'total_amount' => $total,
        'quote_date' => date('Y-m-d'),
    ];
} else {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM quotations WHERE id = ?');
    $stmt->execute([$id]);
    $q = $stmt->fetch();
    if (!$q) { http_response_code(404); echo 'Quotation not found'; exit; }
    if ($type === 'invoice') {
        $stmt = db()->prepare('SELECT * FROM invoices WHERE quotation_id = ? LIMIT 1');
        $stmt->execute([$id]);
        $inv = $stmt->fetch();
        if (!$inv) { http_response_code(404); echo 'Invoice not found'; exit; }
        $invoiceNumber = $inv['invoice_number'];
    }
    $doc = [
        'number' => $type === 'invoice' ? $invoiceNumber : $q['quote_number'],
        'customer_name' => $q['customer_name'],
        'customer_email' => $q['customer_email'],
        'customer_address' => $q['customer_address'],
        'event_description' => $q['event_description'],
        'event_date' => $q['event_date'],
        'venue' => $q['venue'],
        'items' => json_decode($q['items_json'], true) ?: [],
        'notes' => $q['notes'],
        'total_amount' => $q['total_amount'],
        'quote_date' => !empty($q['created_at']) ? date('Y-m-d', strtotime($q['created_at'])) : date('Y-m-d'),
    ];
}

$title = $type === 'invoice' ? 'INVOICE' : 'QUOTATION';
if ($isPreview) { $title = 'TEST PREVIEW'; }

$pdf = new DttdSimplePdf();
$pdf->beginPage();
$logo = dirname(__DIR__) . '/assets/dttd-invoice-logo.jpg';
$footer = dirname(__DIR__) . '/assets/dttd-invoice-footer.jpg';

$purple = [0.29, 0.00, 0.33];
$orange = [1.00, 0.47, 0.11];
$softPurple = [0.985, 0.965, 1.000];
$softGold = [1.000, 0.940, 0.780];
$line = [0.78, 0.70, 0.86];
$dark = [0.08, 0.05, 0.12];

// Footer artwork: full A4 width using a safer crop of the Facebook banner so the central wording is visible.
if (is_file($footer)) { $pdf->imageContain($footer, 0, 0, 595.28, 140); }

// Top company block. Logo remains square and undistorted.
if (is_file($logo)) { $pdf->imageContain($logo, 222, 690, 150, 150); }

$pdf->text(40, 800, 'Dance Thru The Decades Events', 13, true, $purple);
$pdf->text(40, 780, '1 Cooks Cross', 10, false, $dark);
$pdf->text(40, 764, 'Alveley, Shropshire WV15 6LS', 10, false, $dark);
$pdf->text(40, 738, 'www.dancethruthedecades.co.uk', 8, false, $purple);

$pdf->text(415, 794, $title, 23, true, $purple);
$pdf->line(415, 780, 555, 780, 0.72);
$pdf->text(415, 758, 'Number:', 9, true, $dark);
$pdf->text(485, 758, (string)$doc['number'], 9, false, $dark);
$pdf->line(415, 749, 555, 749, 0.82);
$pdf->text(415, 732, 'Date:', 9, true, $dark);
$pdf->text(485, 732, date('d/m/Y'), 9, false, $dark);

if ($isPreview) {
    $pdf->rect(185, 665, 225, 18, [1.000, 0.940, 0.780], [1.000, 0.470, 0.110]);
    $pdf->text(208, 671, 'PREVIEW / TEST DOCUMENT - NOT SAVED', 9, true, $purple);
}

// Customer and event cards moved higher and given more balanced spacing.
$pdf->rect(40, 518, 247, 112, [1,1,1], $line);
$pdf->rect(40, 608, 247, 22, $softPurple, $line);
$pdf->text(54, 615, 'BILL TO', 12, true, $purple);
$pdf->text(54, 590, $doc['customer_name'] ?: 'Customer name', 10, true, $dark);
$y = 572;
foreach (preg_split('/
|
|
/', (string)$doc['customer_address']) as $addrLine) {
    $addrLine = trim($addrLine);
    if ($addrLine === '') { continue; }
    $pdf->multiline(54, $y, $addrLine, 8, 38, 10, 1, false, $dark);
    $y -= 10;
    if ($y < 538) { break; }
}
if (!empty($doc['customer_email']) && $y >= 532) { $pdf->text(54, $y, $doc['customer_email'], 8, false, $purple); }

$pdf->rect(308, 518, 247, 112, [1,1,1], $line);
$pdf->rect(308, 608, 247, 22, $softPurple, $line);
$pdf->text(322, 615, 'EVENT DETAILS', 12, true, $purple);
$pdf->text(322, 590, 'Event / Occasion:', 8, true, $dark);
$pdf->multiline(404, 590, $doc['event_description'] ?: 'Dance Thru The Decades Event', 8, 30, 10, 3, false, $dark);
$pdf->line(322, 557, 540, 557, 0.82);
$pdf->text(322, 544, 'Event Date:', 8, true, $dark);
$pdf->text(404, 544, !empty($doc['event_date']) ? date('d/m/Y', strtotime($doc['event_date'])) : 'TBC', 8, false, $dark);
$pdf->line(322, 535, 540, 535, 0.82);
$pdf->text(322, 522, 'Venue:', 8, true, $dark);
$pdf->multiline(404, 522, $doc['venue'] ?: 'TBC', 8, 30, 10, 2, false, $dark);

// Line item table with fewer blank rows to free vertical space for the footer artwork.
$pdf->rect(40, 485, 515, 23, $purple, $purple);
$pdf->text(54, 493, 'DESCRIPTION', 10, true, [1,1,1]);
$pdf->text(407, 493, 'QTY', 10, true, [1,1,1]);
$pdf->text(468, 493, 'AMOUNT', 10, true, [1,1,1]);

$y = 453;
$total = 0;
$maxRows = 4;
$row = 0;
foreach ($doc['items'] as $item) {
    if ($row >= $maxRows) { break; }
    $price = (float)($item['price'] ?? 0);
    $total += $price;
    $pdf->rect(40, $y-6, 360, 32, [1,1,1], $line);
    $pdf->rect(400, $y-6, 48, 32, [1,1,1], $line);
    $pdf->rect(448, $y-6, 107, 32, [1,1,1], $line);
    $pdf->multiline(54, $y+9, (string)($item['description'] ?? ''), 8, 58, 10, 2, false, $dark);
    $pdf->text(420, $y+5, '1', 9, false, $dark);
    $pdf->text(468, $y+5, dttd_pdf_money($price), 9, true, $dark);
    $y -= 32;
    $row++;
}
while ($row < 3) {
    $pdf->rect(40, $y-6, 360, 32, [1,1,1], $line);
    $pdf->rect(400, $y-6, 48, 32, [1,1,1], $line);
    $pdf->rect(448, $y-6, 107, 32, [1,1,1], $line);
    $y -= 32;
    $row++;
}

// Notes and payment schedule area compacted to leave a clean full-width footer.
$pdf->text(40, 300, 'NOTES / PAYMENT TERMS', 10, true, $purple);
$pdf->rect(40, 195, 300, 90, [1,1,1], $line);
$notes = trim((string)$doc['notes']);
if ($notes === '') {
    $notes = 'Thank you for booking Dance Thru The Decades Events.';
}
$pdf->multiline(54, 265, $notes, 8, 58, 11, 6, false, $dark);

$quoteDateTs = !empty($doc['quote_date']) ? strtotime($doc['quote_date']) : time();
if ($quoteDateTs === false) { $quoteDateTs = time(); }
$depositDue = date('d/m/Y', strtotime('+30 days', $quoteDateTs));
$eventDateTs = !empty($doc['event_date']) ? strtotime($doc['event_date']) : false;
$balanceDue = $eventDateTs ? date('d/m/Y', strtotime('-14 days', $eventDateTs)) : '14 days before event';
$bookingDeposit = round($total * 0.20, 2);
$remainingBalance = max(0, $total - $bookingDeposit);

if ($type === 'invoice') {
    $summaryRows = [
        ['TOTAL INVOICE', dttd_pdf_money($total), [1,1,1], $purple, 12, true],
        ['AMOUNT PAID', dttd_pdf_money(0), [1,1,1], $line, 10, true],
        ['BALANCE DUE', dttd_pdf_money($total), $purple, $purple, 13, true],
    ];
} else {
    $summaryRows = [
        ['TOTAL QUOTATION', dttd_pdf_money($total), $softGold, $line, 12, true],
        ['BOOKING DEPOSIT 20%', dttd_pdf_money($bookingDeposit), [1,1,1], $line, 10, true],
        ['DEPOSIT DUE BY', $depositDue, [1,1,1], $line, 10, true],
        ['REMAINING BALANCE', dttd_pdf_money($remainingBalance), [1,1,1], $line, 10, true],
        ['BALANCE DUE BY', $balanceDue, $purple, $purple, 10, true],
    ];
}

// Payment summary aligned with the top of the notes box. Wider columns and taller rows
// prevent the labels and amounts from colliding when quotation payment dates are shown.
$summaryX = 355;
$summaryY = 285;
$summaryW = 200;
$rowH = $type === 'invoice' ? 30 : 23;
$labelX = $summaryX + 12;
$valueX = $summaryX + 128;
foreach ($summaryRows as $summaryRow) {
    [$label, $value, $fill, $stroke, $fontSize, $bold] = $summaryRow;
    $pdf->rect($summaryX, $summaryY - $rowH, $summaryW, $rowH, $fill, $stroke);
    $textColour = ($fill === $purple) ? [1,1,1] : $dark;
    $labelColour = ($fill === $purple) ? [1,1,1] : $purple;
    $displaySize = min((float)$fontSize, 10.5);
    if ($label === 'TOTAL QUOTATION') { $displaySize = 10; }
    if ($label === 'BALANCE DUE BY') { $displaySize = 9.5; }
    $pdf->text($labelX, $summaryY - $rowH + 8, $label, $displaySize, true, $labelColour);
    $pdf->text($valueX, $summaryY - $rowH + 8, $value, $displaySize, true, $textColour);
    $summaryY -= $rowH;
}

$pdf->text(40, 155, $isPreview ? 'Preview mode: this document was not saved and no quote or invoice number has been allocated.' : 'Generated by Dance Thru The Decades Events.', 8, false, $purple);

$pdf->endPage();
$pdf->output(strtolower(str_replace(' ', '-', $title)) . '-' . preg_replace('/[^A-Za-z0-9-]/', '', $doc['number']) . '.pdf');
