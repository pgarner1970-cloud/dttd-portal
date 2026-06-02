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

// Footer artwork: full A4 width, complete image, no cropping. 1536 x 360 aspect ratio = 139.5pt high.
if (is_file($footer)) { $pdf->imageContain($footer, 0, 0, 595.28, 140); }

// Top company block. Logo remains square and undistorted.
if (is_file($logo)) { $pdf->imageContain($logo, 218, 664, 160, 160); }

$pdf->text(42, 788, 'Dance Thru The Decades Events', 12, true, $purple);
$pdf->text(42, 770, '1 Cooks Cross', 9, false, $dark);
$pdf->text(42, 756, 'Alveley, Shropshire WV15 6LS', 9, false, $dark);
$pdf->text(42, 733, 'www.dancethruthedecades.co.uk', 8, false, $purple);
$pdf->text(42, 718, 'Dance Thru The Decades Events', 8, false, $purple);

$pdf->text(425, 786, $title, 28, true, $purple);
$pdf->line(425, 776, 553, 776, 0.72);
$pdf->text(425, 756, 'Number:', 9, true, $dark);
$pdf->text(485, 756, (string)$doc['number'], 9, false, $dark);
$pdf->text(425, 738, 'Date:', 9, true, $dark);
$pdf->text(485, 738, date('d/m/Y'), 9, false, $dark);

if ($isPreview) {
    $pdf->rect(175, 640, 245, 18, [1.000, 0.940, 0.780], [1.000, 0.470, 0.110]);
    $pdf->text(205, 646, 'PREVIEW / TEST DOCUMENT - NOT SAVED', 10, true, $purple);
}

// Customer and event cards.
$pdf->rect(40, 508, 247, 114, [1,1,1], $line);
$pdf->rect(40, 600, 247, 22, $softPurple, $line);
$pdf->text(54, 607, 'BILL TO', 12, true, $purple);
$pdf->text(54, 582, $doc['customer_name'] ?: 'Customer name', 10, true, $dark);
$y = 566;
foreach (preg_split('/\r\n|\r|\n/', (string)$doc['customer_address']) as $addrLine) {
    $addrLine = trim($addrLine);
    if ($addrLine === '') { continue; }
    $pdf->multiline(54, $y, $addrLine, 8, 38, 10, 1, false, $dark);
    $y -= 10;
    if ($y < 527) { break; }
}
if (!empty($doc['customer_email']) && $y >= 522) { $pdf->text(54, $y, $doc['customer_email'], 8, false, $purple); }

$pdf->rect(308, 508, 247, 114, [1,1,1], $line);
$pdf->rect(308, 600, 247, 22, $softPurple, $line);
$pdf->text(322, 607, 'EVENT DETAILS', 12, true, $purple);
$pdf->text(322, 582, 'Event / Occasion:', 8, true, $dark);
$pdf->multiline(404, 582, $doc['event_description'] ?: 'Dance Thru The Decades Event', 8, 30, 10, 3, false, $dark);
$pdf->text(322, 544, 'Event Date:', 8, true, $dark);
$pdf->text(404, 544, !empty($doc['event_date']) ? date('d/m/Y', strtotime($doc['event_date'])) : 'TBC', 8, false, $dark);
$pdf->text(322, 526, 'Venue:', 8, true, $dark);
$pdf->multiline(404, 526, $doc['venue'] ?: 'TBC', 8, 30, 10, 2, false, $dark);

// Line item table.
$pdf->rect(40, 472, 515, 24, $purple, $purple);
$pdf->text(54, 481, 'DESCRIPTION', 10, true, [1,1,1]);
$pdf->text(403, 481, 'QTY', 10, true, [1,1,1]);
$pdf->text(465, 481, 'AMOUNT', 10, true, [1,1,1]);

$y = 440;
$total = 0;
$maxRows = 5;
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

// Notes and total area, safely above the footer.
$pdf->text(40, 287, 'NOTES / PAYMENT TERMS', 10, true, $purple);
$pdf->rect(40, 172, 300, 102, [1,1,1], $line);
$notes = trim((string)$doc['notes']);
if ($notes === '') {
    $notes = 'Thank you for booking Dance Thru The Decades Events. Payment details, deposit terms and balance due date can be shown here.';
}
$pdf->multiline(54, 252, $notes, 8, 58, 11, 7, false, $dark);

$pdf->rect(365, 248, 190, 28, [1,1,1], $line);
$pdf->text(382, 258, 'SUBTOTAL', 10, true, $purple);
$pdf->text(485, 258, dttd_pdf_money($total), 10, true, $dark);
$pdf->rect(365, 220, 190, 28, $softGold, $line);
$pdf->text(382, 230, 'TOTAL', 12, true, $purple);
$pdf->text(485, 230, dttd_pdf_money($total), 12, true, $dark);
$pdf->rect(365, 192, 190, 28, [1,1,1], $line);
$pdf->text(382, 202, 'DEPOSIT PAID', 10, true, $purple);
$pdf->text(485, 202, dttd_pdf_money(0), 10, true, $dark);
$pdf->rect(365, 164, 190, 28, $purple, $purple);
$pdf->text(382, 174, 'BALANCE DUE', 12, true, [1,1,1]);
$pdf->text(485, 174, dttd_pdf_money($total), 12, true, [1,1,1]);

$pdf->text(40, 148, $isPreview ? 'Preview mode: this document was not saved and no quote or invoice number has been allocated.' : 'Generated by Dance Thru The Decades Events.', 8, false, $purple);

$pdf->endPage();
$pdf->output(strtolower(str_replace(' ', '-', $title)) . '-' . preg_replace('/[^A-Za-z0-9-]/', '', $doc['number']) . '.pdf');
