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

    function setStatus(text) {
      if (status) status.textContent = text || '';
    }

    function renderTracks(tracks) {
      results.innerHTML = '';
      if (!tracks.length) {
        results.hidden = true;
        return;
      }

      tracks.slice(0, 5).forEach(function (track) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'spotify-result';
        button.innerHTML =
          (track.image ? '<img src="' + track.image.replace(/"/g, '&quot;') + '" alt="">' : '<span class="spotify-art-placeholder">♫</span>') +
          '<span><strong>' + escapeHtml(track.title || '') + '</strong><small>' + escapeHtml(track.artist || '') + (track.album ? ' · ' + escapeHtml(track.album) : '') + '</small></span>';

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

          if (selected) {
            selected.hidden = false;
            selected.innerHTML = '<span class="spotify-selected-label">Spotify match selected</span><strong>' + escapeHtml(track.title || '') + '</strong><small>' + escapeHtml(track.artist || '') + '</small>';
          }
          results.hidden = true;
          setStatus('');
        });

        results.appendChild(button);
      });
      results.hidden = false;
    }

    function search() {
      const query = (titleInput.value + ' ' + artistInput.value).trim();
      clearHidden(form);
      if (selected) selected.hidden = true;

      if (query.length < 3) {
        results.hidden = true;
        setStatus('');
        return;
      }

      if (controller) controller.abort();
      controller = new AbortController();
      setStatus('Searching Spotify…');

      fetch('https://dancethruthedecades.co.uk/api/spotify-search.php?q=' + encodeURIComponent(query), { signal: controller.signal })
        .then(function (res) { return res.json(); })
        .then(function (data) {
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
          setStatus(data.tracks && data.tracks.length ? (data.source === 'cache' ? 'Select a cached match, or keep typing for manual entry.' : 'Select a Spotify match, or keep typing for manual entry.') : (data.message || 'No Spotify matches found. Manual entry still works.'));
          renderTracks(data.tracks || []);
        })
        .catch(function (err) {
          if (err.name === 'AbortError') return;
          setStatus('Spotify search unavailable. Manual entry still works.');
          results.hidden = true;
        });
    }

    function escapeHtml(text) {
      return String(text).replace(/[&<>"']/g, function (ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
      });
    }

    const delayedSearch = debounce(search, 700);
    titleInput.addEventListener('input', delayedSearch);
    artistInput.addEventListener('input', delayedSearch);
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-spotify-request-form]').forEach(initSpotifySearch);
  });
}());
