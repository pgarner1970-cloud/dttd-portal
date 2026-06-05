(function () {
  'use strict';

  var section = document.querySelector('[data-now-playing-section]');
  if (!section) return;

  var endpoint = section.getAttribute('data-endpoint') || '/api/public-now-playing.php';
  var track = section.querySelector('[data-now-playing-track]');
  var updated = section.querySelector('[data-now-playing-updated]');
  var empty = section.querySelector('[data-now-playing-empty]');
  var lastSignature = '';
  var refreshMs = 10000;

  function escapeText(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function card(trackItem, index) {
    var status = trackItem.status === 'current' ? 'Currently playing' : (trackItem.status === 'latest' ? 'Latest track' : 'Recently played');
    var image = String(trackItem.image || '').trim();
    var title = String(trackItem.title || 'Unknown track').trim();
    var artist = String(trackItem.artist || '').trim();
    var classes = 'home-now-playing-card' + (index === 0 ? ' is-current' : '');
    var art = image
      ? '<span class="home-now-playing-art"><img src="' + escapeText(image) + '" alt="" loading="lazy"></span>'
      : '<span class="home-now-playing-art is-fallback" aria-hidden="true">♪</span>';

    return '' +
      '<article class="' + classes + '">' +
        art +
        '<span class="home-now-playing-copy">' +
          '<span class="home-now-playing-status">' + escapeText(status) + '</span>' +
          '<strong>' + escapeText(title) + '</strong>' +
          '<em>' + escapeText(artist || 'Dance Thru The Decades') + '</em>' +
        '</span>' +
      '</article>';
  }

  function render(payload) {
    var tracks = Array.isArray(payload.tracks) ? payload.tracks : [];
    if (!tracks.length) {
      section.hidden = true;
      if (empty) empty.hidden = false;
      return;
    }

    section.hidden = false;
    if (empty) empty.hidden = true;

    var signature = tracks.map(function (item) {
      return [item.status, item.id, item.title, item.artist].join('|');
    }).join('||');

    if (signature !== lastSignature) {
      lastSignature = signature;
      var cards = tracks.map(card).join('');
      // Duplicate the same cards so the animated rail can loop smoothly while still
      // behaving like a normal horizontal swipe row on touch screens.
      track.innerHTML = '<div class="home-now-playing-rail">' + cards + cards + '</div>';
    }

    if (updated) {
      updated.textContent = payload.has_live_current ? 'Live update' : 'Recently played update';
    }
  }

  function load() {
    fetch(endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now(), {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (payload) {
        if (!payload || !payload.ok || !payload.active_event) {
          section.hidden = true;
          return;
        }
        render(payload);
      })
      .catch(function () {
        // Keep the last known rail visible if a single poll fails.
      });
  }

  load();
  window.setInterval(load, refreshMs);
})();
