<?php
if (!function_exists('public_h')) {
    function public_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!isset($public_current)) {
    $public_current = '';
}

if (!isset($facebookUrl)) {
    $facebookUrl = 'https://www.facebook.com/profile.php?id=61579454050951';
}

$navItems = [
    ['key' => 'home', 'label' => 'Home', 'href' => '/'],
    ['key' => 'events', 'label' => 'Events', 'href' => '/events'],
    ['key' => 'gallery', 'label' => 'Gallery', 'href' => '/gallery'],
    ['key' => 'partners', 'label' => 'Partners', 'href' => '/partners'],
];
?>
<header class="public-site-header">
  <a class="public-site-brand" href="/" aria-label="Dance Thru The Decades home">
    <img src="/assets/dttd-logo-inner.png?v=200" alt="" aria-hidden="true">
    <span>Dance Thru<br><strong>The Decades</strong></span>
  </a>

  <nav class="public-site-nav" aria-label="Public site navigation">
    <?php foreach ($navItems as $item): ?>
      <a class="<?= $public_current === $item['key'] ? 'is-active' : '' ?>" href="<?= public_h($item['href']) ?>">
        <?= public_h($item['label']) ?>
      </a>
    <?php endforeach; ?>

    <a class="public-nav-social facebook" href="<?= public_h($facebookUrl) ?>" target="_blank" rel="noopener" aria-label="Facebook">
      <span aria-hidden="true">f</span>
    </a>
  </nav>
</header>
