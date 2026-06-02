<?php
if (!function_exists('public_h')) {
    function public_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!isset($facebookUrl)) {
    $facebookUrl = 'https://www.facebook.com/profile.php?id=61579454050951';
}

/*
 * Optional future WhatsApp link.
 * Prefer a proper WhatsApp Business/API/contact route later rather than exposing a personal number.
 * Example when ready:
 * $whatsappUrl = 'https://wa.me/44XXXXXXXXXX';
 */
$whatsappUrl = $whatsappUrl ?? '';
?>
<footer class="public-site-footer">
  <div class="public-footer-inner">
    <div class="public-footer-brand">
      <img src="/assets/dttd-logo-inner.png?v=152" alt="" aria-hidden="true">
      <div>
        <strong>Dance Thru The Decades Events</strong>
        <span>Feel-good party nights, classic floor-fillers and moments worth sharing.</span>
      </div>
    </div>

    <div class="public-footer-links">
      <a href="/">Home</a>
      <a href="/events">Events</a>
      <a href="/gallery">Gallery</a>
      <a href="/partners">Partners</a>
      <a href="/privacy.php">Privacy</a>
      <a href="/terms.php">Terms</a>
    </div>

    <div class="public-footer-socials">
      <a class="public-footer-social" href="<?= public_h($facebookUrl) ?>" target="_blank" rel="noopener" aria-label="Facebook">
        f
      </a>

      <?php if ($whatsappUrl): ?>
        <a class="public-footer-social whatsapp" href="<?= public_h($whatsappUrl) ?>" target="_blank" rel="noopener" aria-label="WhatsApp">
          ☎
        </a>
      <?php endif; ?>
    </div>
  </div>

  <div class="public-footer-bottom">
    <span>&copy; <?= date('Y') ?> Dance Thru The Decades Events</span>
    <span>Website provided by <a href="https://yellowarrow.co.uk" target="_blank" rel="noopener">Yellow Arrow</a></span>
  </div>
</footer>
