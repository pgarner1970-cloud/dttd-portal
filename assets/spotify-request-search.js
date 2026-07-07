(function () {
  function debounce(fn, wait) {
    let timer = null;
    return function () {
      const args = arguments;
      clearTimeout(timer);
      timer = setTimeout(function () { fn.apply(null, args); }, wait);
    };
  }

  function clearHidden(form) {
    ['spotify_track_id', 'spotify_track_url', 'spotify_artist_name', 'spotify_album_image'].forEach(function (name) {
      const input = form.querySelector('[name="' + name + '"]');
      if (input) input.value = '';
    });
    const source = form.querySelector('[name="request_source"]');
    if (source) source.value = 'manual';
  }

  function initSpotifySearch(form) {
    const titleInput = form.querySelector('[name="song_title"]');
    const artistInput = form.querySelector('[name="artist"]');
    const results = form.querySelector('[data-spotify-results]');
    const selected = form.querySelector('[data-spotify-selected]');
    const status = form.querySelector('[data-spotify-status]');

    if (!titleInput || !artistInput || !results) return;

    let controller = null;
    let lastIssuedQuery = '';
    const browserQueryCache = new Map();
    const browserCacheTtlMs = 10 * 60 * 1000;

    function normaliseQuery(text) {
      return String(text || '')
        .toLowerCase()
        .replace(/[^a-z0-9\s]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    }

    function getBrowserCachedResult(queryKey) {
      const entry = browserQueryCache.get(queryKey);
      if (!entry) return null;
      if ((Date.now() - entry.time) > browserCacheTtlMs) {
        browserQueryCache.delete(queryKey);
        return null;
      }
      return entry.data;
    }

    function storeBrowserCachedResult(queryKey, data) {
      if (!queryKey || !data || !data.ok) return;
      browserQueryCache.set(queryKey, { time: Date.now(), data: data });
      if (browserQueryCache.size > 30) {
        const firstKey = browserQueryCache.keys().next().value;
        browserQueryCache.delete(firstKey);
      }
    }

    function setStatus(text) {
      if (status) status.textContent = text || '';
    }

    function renderTracks(tracks) {
      results.innerHTML = '';
      if (!tracks.length) {
        results.hidden = true;
        return;
      }

      tracks.slice(0, 8).forEach(function (track) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'spotify-result';
        button.innerHTML =
          (track.image ? '<img src="' + track.image.replace(/"/g, '&quot;') + '" alt="">' : '<span class="spotify-art-placeholder">♫</span>') +
          '<span class="spotify-result-text"><strong>' + escapeHtml(track.title || '') + '</strong><small>' + escapeHtml(track.artist || '') + (track.album ? ' · ' + escapeHtml(track.album) : '') + '</small>' + badgeHtml(track) + '</span>';

        button.addEventListener('click', function () {
          titleInput.value = track.title || '';
          artistInput.value = track.artist || '';

          const fields = {
            spotify_track_id: track.id || '',
            spotify_track_url: track.url || '',
            spotify_artist_name: track.artist || '',
            spotify_album_image: track.image || '',
            request_source: 'spotify'
          };

          Object.keys(fields).forEach(function (name) {
            const input = form.querySelector('[name="' + name + '"]');
            if (input) input.value = fields[name];
          });

          results.innerHTML = '';
          results.hidden = true;

          if (selected) {
            selected.hidden = false;
            selected.innerHTML =
              (track.image ? '<img src="' + track.image.replace(/"/g, '&quot;') + '" alt="">' : '<span class="spotify-art-placeholder">♫</span>') +
              '<span class="spotify-selected-text"><small>Selected Spotify match</small><strong>' + escapeHtml(track.title || '') + '</strong><em>' + escapeHtml(track.artist || '') + '</em></span>';
          }
          setStatus('');
        });

        results.appendChild(button);
      });
      results.hidden = false;
    }

    function search() {
      const query = (titleInput.value + ' ' + artistInput.value).trim();
      const queryKey = normaliseQuery(query);
      clearHidden(form);
      if (selected) selected.hidden = true;

      if (queryKey.length < 4) {
        results.hidden = true;
        setStatus('');
        lastIssuedQuery = '';
        return;
      }

      const browserCached = getBrowserCachedResult(queryKey);
      if (browserCached) {
        lastIssuedQuery = queryKey;
        handleSearchResponse(browserCached, true);
        return;
      }

      if (queryKey === lastIssuedQuery) {
        return;
      }
      lastIssuedQuery = queryKey;

      if (controller) controller.abort();
      controller = new AbortController();
      setStatus('Searching music…');

      fetch('https://dancethruthedecades.co.uk/api/spotify-search.php?q=' + encodeURIComponent(query), { signal: controller.signal })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          storeBrowserCachedResult(queryKey, data);
          handleSearchResponse(data, false);
        })
        .catch(function (err) {
          if (err.name === 'AbortError') return;
          setStatus('Spotify search unavailable. Manual entry still works.');
          results.hidden = true;
        });
    }

    function handleSearchResponse(data, browserCacheHit) {
      if (!data.configured) {
        setStatus('Spotify API is not configured yet. Manual entry still works.');
        results.hidden = true;
        return;
      }
      if (!data.ok) {
        setStatus(data.message || 'Spotify search unavailable. Manual entry still works.');
        results.hidden = true;
        return;
      }

      const tracks = data.tracks || [];
      if (tracks.length) {
        if (browserCacheHit || data.query_cache_hit || data.source === 'query_cache') {
          setStatus('Select a saved Spotify match, or keep typing for manual entry.');
        } else if (data.source === 'cache' || data.source === 'track_cache') {
          setStatus('Select a cached match, or keep typing for manual entry.');
        } else {
          setStatus('Select a Spotify match, or keep typing for manual entry.');
        }
      } else {
        setStatus(data.message || 'No Spotify matches found. Manual entry still works.');
      }
      renderTracks(tracks);
    }

    function badgeHtml(track) {
      const badges = Array.isArray(track.badges) ? track.badges : [];
      if (!badges.length) return '';
      return '<span class="spotify-result-badges">' + badges.slice(0, 4).map(function (badge) {
        const typeClass = String(badge.type || '').toLowerCase().replace(/[^a-z0-9_-]+/g, '-');
        const labelClass = String(badge.label || '').toLowerCase().replace(/[^a-z0-9_-]+/g, '-');
        return '<em class="spotify-result-badge ' + escapeHtml(typeClass) + ' ' + escapeHtml(typeClass + '-' + labelClass) + '">' + escapeHtml(badge.label || '') + '</em>';
      }).join('') + '</span>';
    }

    function escapeHtml(text) {
      return String(text).replace(/[&<>"']/g, function (ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
      });
    }

    const delayedSearch = debounce(search, 1200);
    titleInput.addEventListener('input', delayedSearch);
    artistInput.addEventListener('input', delayedSearch);
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-spotify-request-form]').forEach(initSpotifySearch);
  });
}());
