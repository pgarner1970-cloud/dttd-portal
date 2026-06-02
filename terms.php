<?php
$public_current = 'terms';
$metaTitle = 'Terms & Conditions | Dance Thru The Decades';
$metaDescription = 'Terms and conditions for Dance Thru The Decades events, public photo uploads, music requests and event services.';
require __DIR__ . '/includes/config.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($metaTitle) ?></title>
  <meta name="description" content="<?= h($metaDescription) ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="canonical" href="/terms">
  <link rel="stylesheet" href="/assets/public-site.css?v=terms-privacy-1">
</head>
<body class="homepage-option-one public-legal-page">
  <div class="home-option-one">
    <?php require __DIR__ . '/includes/public-nav.php'; ?>

    <main class="public-legal-shell">
      <section class="public-legal-hero">
        <div class="option-one-logo-shell">
          <img src="/assets/dttd-logo-inner.png" alt="Dance Thru The Decades">
        </div>
        <p class="option-one-eyebrow">Dance Thru The Decades</p>
        <h1>Terms & Conditions</h1>
        <p class="option-one-subtitle">
          These terms explain how our events, public website features, music requests and photo upload areas are intended to be used.
        </p>
      </section>

      <section class="public-legal-card">
        <p class="public-legal-updated">Last updated: <?= date('F Y') ?></p>

        <h2>1. About these terms</h2>
        <p>
          These terms apply when you use the Dance Thru The Decades website, attend one of our events, submit a music request,
          upload a photo, or interact with our public event features. By using the website or taking part in an event feature,
          you agree to use it sensibly and respectfully.
        </p>

        <h2>2. Event information</h2>
        <p>
          We aim to keep event information, timings, venues, prices and availability accurate. Details may occasionally change
          because of venue requirements, technical issues, supplier availability, weather, safety, licensing, or other circumstances
          outside our control.
        </p>

        <h2>3. Tickets, bookings and event entry</h2>
        <p>
          Where tickets, bookings or reserved places are used, entry may be subject to venue rules, capacity limits, age restrictions,
          licensing requirements and any conditions stated at the time of booking. The venue or organiser may refuse entry where it is
          reasonable to do so, including for safety, disorderly conduct, intoxication, abusive behaviour or breach of venue policy.
        </p>

        <h2>4. Cancellations and changes</h2>
        <p>
          If an event has to be cancelled, postponed or materially changed, we will try to provide reasonable notice using the contact
          or public channels available to us. Refunds or rearrangements, where applicable, will normally follow the policy advertised
          for that event or the booking/ticket provider’s terms.
        </p>
        <p>
          We are not responsible for additional costs incurred by guests, such as travel, accommodation, childcare, clothing or other
          arrangements, unless required by law.
        </p>

        <h2>5. Music requests</h2>
        <p>
          Music requests are welcomed but cannot be guaranteed. The DJ may choose whether, when and how to play requests based on
          the event style, available music sources, running order, suitability, explicit content, licensing restrictions, timing and
          the atmosphere on the night.
        </p>

        <h2>6. Photo uploads and gallery content</h2>
        <p>
          If you upload photos through the website, you confirm that you have the right to upload them and that they are suitable
          for a public event gallery. Photos may be reviewed, approved, edited, cropped, hidden or removed at our discretion.
        </p>
        <p>
          Please do not upload offensive, unsafe, private, abusive, misleading, copyrighted or inappropriate material. Do not upload
          images that deliberately embarrass or target someone else.
        </p>

        <h2>7. Use of images from events</h2>
        <p>
          Public event photography and guest uploads may be used to promote Dance Thru The Decades events, galleries and related
          activities. If you see an image of yourself that you would like removed, contact us and we will review it as soon as reasonably
          possible.
        </p>

        <h2>8. Sponsors and partners</h2>
        <p>
          Our website may show partners, suppliers or event sponsors. Links to third-party websites are provided for convenience only.
          We are not responsible for the content, availability, pricing, offers, services or policies of third-party websites.
        </p>

        <h2>9. Website availability</h2>
        <p>
          We try to keep the website and event tools available, but we cannot guarantee uninterrupted access. Features such as requests,
          galleries, QR links, sponsor displays and event pages may be unavailable during maintenance, technical problems, connectivity
          issues or supplier outages.
        </p>

        <h2>10. Acceptable use</h2>
        <p>
          You must not attempt to misuse the website, access admin areas, interfere with event systems, upload malicious files,
          submit abusive content, impersonate others, or use the site in a way that causes harm or disruption.
        </p>

        <h2>11. Liability</h2>
        <p>
          Nothing in these terms limits liability where it would be unlawful to do so. Otherwise, Dance Thru The Decades is not liable
          for indirect losses, loss of enjoyment, loss of opportunity, third-party service issues, or matters outside our reasonable
          control.
        </p>

        <h2>12. Contact</h2>
        <p>
          For questions about these terms, event information, photo removal requests or website content, please contact Dance Thru
          The Decades using the contact details provided on the website or event materials.
        </p>
      </section>
    </main>

    <?php require __DIR__ . '/includes/public-footer.php'; ?>
  </div>
</body>
</html>
