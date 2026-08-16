(function () {
  function debounce(fn, wait) {
    let timer = null;
    return function () {
      const args = arguments;
      clearTimeout(timer);
      timer = setTimeout(function () { fn.apply(null, args); }, wait);
    };
  }

  function initSpotifySearch(form) {
    const queryInput = form.querySelector('[data-spotify-query]');
    const titleInput = form.querySelector('[name="song_title"]');
    const artistInput = form.querySelector('[name="artist"]');
    const results = form.querySelector('[data-spotify-results]');
    const selected = form.querySelector('[data-spotify-selected]');
    const status = form.querySelector('[data-spotify-status]');
    const songStage = form.querySelector('[data-request-song-stage]');
    const completionStage = form.querySelector('[data-request-completion-stage]');
    const manualFields = form.querySelector('[data-request-manual-fields]');
    const manualToggle = form.querySelector('[data-request-manual-toggle]');
    const changeSong = form.querySelector('[data-request-change-song]');

    if (!queryInput || !titleInput || !artistInput || !results || !songStage || !completionStage) return;

    form.classList.add('spotify-request-enhanced');

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

    function setManualRequired(required) {
      titleInput.required = !!required;
      artistInput.required = !!required;
    }

    function clearSpotifyFields() {
      ['spotify_track_id', 'spotify_track_url', 'spotify_artist_name', 'spotify_album_image'].forEach(function (name) {
        const input = form.querySelector('[name="' + name + '"]');
        if (input) input.value = '';
      });
      const source = form.querySelector('[name="request_source"]');
      if (source) source.value = 'manual';
    }

    function setSpotifyFields(track) {
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
    }

    function showCompletion(manual) {
      songStage.hidden = true;
      completionStage.hidden = false;
      if (manualFields) manualFields.hidden = !manual;
      setManualRequired(manual);
      if (!manual && selected) selected.hidden = false;
      if (manual && selected) selected.hidden = true;
      window.setTimeout(function () {
        completionStage.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 30);
    }

    function showSearch(clearSong) {
      completionStage.hidden = true;
      songStage.hidden = false;
      if (selected) selected.hidden = true;
      if (manualFields) manualFields.hidden = true;
      setManualRequired(false);
      if (clearSong) {
        titleInput.value = '';
        artistInput.value = '';
        clearSpotifyFields();
      }
      window.setTimeout(function () {
        queryInput.focus({ preventScroll: true });
        songStage.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 30);
    }

    function selectedMarkup(track) {
      return (
        (track.image ? '<img src="' + escapeAttr(track.image) + '" alt="">' : '<span class="spotify-art-placeholder">♫</span>') +
        '<span class="spotify-selected-text"><small>YOUR SONG</small><strong>' + escapeHtml(track.title || '') + '</strong><em>' + escapeHtml(track.artist || '') + (track.album ? ' · ' + escapeHtml(track.album) : '') + '</em></span>'
      );
    }

    function selectTrack(track) {
      titleInput.value = track.title || '';
      artistInput.value = track.artist || '';
      setSpotifyFields(track);
      results.innerHTML = '';
      results.hidden = true;
      setStatus('');
      if (selected) {
        selected.innerHTML = selectedMarkup(track);
        selected.hidden = false;
      }
      showCompletion(false);
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
          (track.image ? '<img src="' + escapeAttr(track.image) + '" alt="">' : '<span class="spotify-art-placeholder">♫</span>') +
          '<span class="spotify-result-text"><strong>' + escapeHtml(track.title || '') + '</strong><small>' + escapeHtml(track.artist || '') + (track.album ? ' · ' + escapeHtml(track.album) : '') + '</small>' + badgeHtml(track) + '</span>';
        button.addEventListener('click', function () { selectTrack(track); });
        results.appendChild(button);
      });
      results.hidden = false;
    }

    function search() {
      const query = queryInput.value.trim();
      const queryKey = normaliseQuery(query);

      if (queryKey.length < 3) {
        results.hidden = true;
        results.innerHTML = '';
        setStatus(queryKey.length ? 'Type at least 3 characters to search.' : '');
        lastIssuedQuery = '';
        return;
      }

      const browserCached = getBrowserCachedResult(queryKey);
      if (browserCached) {
        lastIssuedQuery = queryKey;
        handleSearchResponse(browserCached, true);
        return;
      }

      if (queryKey === lastIssuedQuery) return;
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
          setStatus('Spotify search is unavailable. You can still enter the song manually.');
          results.hidden = true;
        });
    }

    function handleSearchResponse(data, browserCacheHit) {
      if (!data.configured) {
        setStatus('Spotify search is unavailable. You can still enter the song manually.');
        results.hidden = true;
        return;
      }
      if (!data.ok) {
        setStatus(data.message || 'Spotify search is unavailable. You can still enter the song manually.');
        results.hidden = true;
        return;
      }

      const tracks = data.tracks || [];
      if (tracks.length) {
        if (browserCacheHit || data.query_cache_hit || data.source === 'query_cache') {
          setStatus('Tap the song you want.');
        } else if (data.source === 'cache' || data.source === 'track_cache') {
          setStatus('Tap the song you want.');
        } else {
          setStatus('Tap the song you want.');
        }
      } else {
        setStatus(data.message || 'No matches found. Try another search or enter it manually.');
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

    function escapeAttr(text) {
      return escapeHtml(String(text || ''));
    }

    function escapeHtml(text) {
      return String(text).replace(/[&<>"']/g, function (ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
      });
    }

    const delayedSearch = debounce(search, 500);
    queryInput.addEventListener('input', delayedSearch);
    queryInput.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        search();
      }
    });

    if (manualToggle) {
      manualToggle.addEventListener('click', function () {
        if (controller) controller.abort();
        clearSpotifyFields();
        titleInput.value = '';
        artistInput.value = '';
        showCompletion(true);
        window.setTimeout(function () { titleInput.focus({ preventScroll: true }); }, 200);
      });
    }

    if (changeSong) {
      changeSong.addEventListener('click', function () {
        showSearch(true);
      });
    }

    form.addEventListener('submit', function (event) {
      if (!titleInput.value.trim() || !artistInput.value.trim()) {
        event.preventDefault();
        if (manualFields) manualFields.hidden = false;
        completionStage.hidden = false;
        songStage.hidden = true;
        setManualRequired(true);
        if (!titleInput.value.trim()) titleInput.focus();
        else artistInput.focus();
      }
    });

    const existingSource = form.querySelector('[name="request_source"]');
    const existingTrackId = form.querySelector('[name="spotify_track_id"]');
    if (existingSource && existingSource.value === 'spotify' && existingTrackId && existingTrackId.value && titleInput.value && artistInput.value) {
      const existingImage = form.querySelector('[name="spotify_album_image"]');
      if (selected) {
        selected.innerHTML = selectedMarkup({
          title: titleInput.value,
          artist: artistInput.value,
          image: existingImage ? existingImage.value : ''
        });
      }
      showCompletion(false);
    } else if (titleInput.value || artistInput.value) {
      showCompletion(true);
    } else {
      showSearch(false);
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-spotify-request-form]').forEach(initSpotifySearch);
  });
}());
