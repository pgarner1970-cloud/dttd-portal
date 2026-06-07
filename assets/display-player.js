(function(){
  const shell = document.querySelector('.display-shell');
  const stage = document.querySelector('.display-stage');
  if (!shell || !stage) return;

  const stateUrl = shell.dataset.stateUrl || '/api/display-state.php';
  const nowPlayingUrl = shell.dataset.nowPlayingUrl || '/api/public-now-playing.php';
  const footerEvent = document.querySelector('[data-display-footer-event]');
  const clock = document.querySelector('[data-display-clock]');

  let state = null;
  let nowPlaying = null;
  let lastLiveNowPlaying = null;
  let slideIndex = 0;
  let slideTimer = null;
  let refreshTimer = null;
  let lastSlideSignature = '';

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function text(value, fallback) {
    const out = String(value == null ? '' : value).trim();
    return out || (fallback || '');
  }

  function formatDate(value) {
    if (!value) return '';
    const parts = String(value).split('-');
    if (parts.length !== 3) return value;
    const date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    try {
      return date.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
    } catch (e) {
      return value;
    }
  }

  function setClock() {
    if (!clock) return;
    try {
      clock.textContent = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    } catch (e) {
      clock.textContent = '';
    }
  }

  function trackLine(track) {
    const title = esc(text(track.track_name || track.title, 'Unknown track'));
    const artist = esc(text(track.artist_name || track.artist, ''));
    return '<strong>' + title + '</strong>' + (artist ? '<span>' + artist + '</span>' : '');
  }

  function currentTrack() {
    const source = nowPlaying && Array.isArray(nowPlaying.tracks) && nowPlaying.tracks.length ? nowPlaying : lastLiveNowPlaying;
    const tracks = source && Array.isArray(source.tracks) ? source.tracks : [];
    return tracks.find(t => t.status === 'current') || null;
  }

  function upNextTrack() {
    return nowPlaying && nowPlaying.up_next ? nowPlaying.up_next : null;
  }

  function slideAllowed(name) {
    if (name === 'now_playing') return !!currentTrack();
    if (name === 'up_next') return !!upNextTrack();
    return true;
  }

  function renderVenue() {
    const venue = state && state.venue ? state.venue : null;
    if (!venue || !venue.name) {
      return '<article class="display-slide" data-slide="venue"><div class="display-card display-card-centre"><p class="display-kicker">Tonight’s Venue</p><h1>Thank you</h1></div></article>';
    }

    const addressBits = [venue.address, venue.postcode].filter(Boolean).map(esc).join('<br>');
    const links = Array.isArray(venue.links) ? venue.links.slice(0, 3) : [];
    const qrTiles = links.map(link => '<div class="venue-qr-tile">'
      + '<img src="' + esc(link.qr_image_url || '') + '" alt="' + esc(link.label || 'Venue link') + ' QR code">'
      + '<strong>' + esc(link.label || 'Venue link') + '</strong>'
      + '</div>').join('');

    return '<article class="display-slide" data-slide="venue">'
      + '<div class="display-card venue-card">'
      + '<div class="venue-head"><p class="display-kicker">Tonight’s Venue</p><h1>Thank you to our hosts</h1></div>'
      + '<div class="venue-body">'
      + '<div class="venue-copy">'
      + '<strong>' + esc(venue.name) + '</strong>'
      + (addressBits ? '<p>' + addressBits + '</p>' : '')
      + (venue.phone ? '<p class="venue-phone">Tel: ' + esc(venue.phone) + '</p>' : '')
      + '<em>Give them a follow, tag your photos, and support the venue.</em>'
      + '</div>'
      + (qrTiles ? '<div class="venue-qr-grid">' + qrTiles + '</div>' : '<div class="venue-no-social">Venue social links can be added in the event details.</div>')
      + '</div>'
      + '</div></article>';
  }

  function renderWelcome() {
    const event = state && state.event ? state.event : {};
    const isWedding = String(event.event_type || '').toLowerCase() === 'wedding';
    const title = isWedding ? 'Congratulations' : 'Welcome';
    const subtitle = isWedding ? text(event.event_name, 'Tonight\'s Celebration') : text(event.event_name, 'Tonight\'s Event');
    return '<article class="display-slide" data-slide="welcome">'
      + '<div class="display-card display-card-centre display-card-hero">'
      + '<p class="display-kicker">Dance Through The Decades</p>'
      + '<h1>' + esc(title) + '</h1>'
      + '<h2>' + esc(subtitle) + '</h2>'
      + (event.venue_name ? '<p class="display-muted">' + esc(event.venue_name) + '</p>' : '')
      + '<p class="display-large-note">Request songs, upload photos and be part of the night.</p>'
      + '</div></article>';
  }

  function renderQr() {
    const event = state && state.event ? state.event : {};
    const code = text(event.event_code, '');
    return '<article class="display-slide" data-slide="qr">'
      + '<div class="display-grid two-col qr-layout">'
      + '<div class="display-card qr-copy-card">'
      + '<p class="display-kicker">Join tonight\'s event</p>'
      + '<h1 class="qr-title">Scan the QR code</h1>'
      + '<ul class="qr-benefits">'
      + '<li>Request songs</li>'
      + '<li>Upload your photos</li>'
      + '<li>See what\'s playing</li>'
      + '</ul>'
      + (code ? '<div class="event-code-big"><span>Event code</span><strong>' + esc(code) + '</strong></div>' : '')
      + '</div>'
      + '<div class="display-card qr-card">'
      + (event.qr_image_url ? '<img src="' + esc(event.qr_image_url) + '" alt="Event QR code">' : '<div class="display-empty">No event code available</div>')
      + '</div></div></article>';
  }

  function renderNowPlaying() {
    const current = currentTrack();
    if (!current) {
      return '<article class="display-slide" data-slide="now_playing">'
        + '<div class="display-card now-playing-feature now-playing-standby">'
        + '<div class="now-feature-head"><p class="display-kicker">Now Playing</p><h1>Music all night</h1></div>'
        + '<div class="now-feature-body">'
        + '<div class="now-feature-note"><strong>Current track</strong><span>The current track will appear here when playback is detected.</span></div>'
        + '</div></div></article>';
    }
    return '<article class="display-slide" data-slide="now_playing">'
      + '<div class="display-card now-playing-feature">'
      + '<div class="now-feature-head"><p class="display-kicker">Now Playing</p><h1>On the decks</h1></div>'
      + '<div class="now-feature-body">'
      + '<div class="now-feature-art">'
      + (current.image ? '<img src="' + esc(current.image) + '" alt="Album artwork">' : '<div class="artwork-placeholder">♪</div>')
      + '</div>'
      + '<div class="now-feature-copy">'
      + '<strong>' + esc(text(current.title, 'Unknown track')) + '</strong>'
      + (current.artist ? '<span>' + esc(current.artist) + '</span>' : '')
      + '<em>Keep the requests coming</em>'
      + '</div>'
      + '</div></div></article>';
  }

  function renderUpNext() {
    const next = upNextTrack();
    if (!next) {
      return '<article class="display-slide" data-slide="up_next">'
        + '<div class="display-card display-card-centre">'
        + '<p class="display-kicker">Up Next</p>'
        + '<h1>More music soon</h1>'
        + '</div></article>';
    }

    return '<article class="display-slide" data-slide="up_next">'
      + '<div class="display-card now-playing-feature up-next-feature-fixed">'
      + '<div class="now-feature-head"><p class="display-kicker">Up Next</p><h1>On the decks</h1></div>'
      + '<div class="now-feature-body">'
      + '<div class="now-feature-art">'
      + (next.image ? '<img src="' + esc(next.image) + '" alt="Album artwork">' : '<div class="artwork-placeholder">♪</div>')
      + '</div>'
      + '<div class="now-feature-copy">'
      + '<strong>' + esc(text(next.title, 'Unknown track')) + '</strong>'
      + (next.artist ? '<span>' + esc(next.artist) + '</span>' : '')
      + '<em>Loaded ready to play</em>'
      + '</div>'
      + '</div>'
      + '</div>'
      + '</article>';
  }

  function renderRecent() {
    // Strict history only: this slide must show real event_track_history rows.
    // Do not fall back to loaded/current mixer payloads, otherwise it can appear to
    // duplicate or invent entries before they have actually been logged as played.
    const tracks = Array.isArray(state.recent_tracks) ? state.recent_tracks : [];
    const rows = tracks.slice(0, 10).map((track, idx) => {
      const title = esc(text(track.track_name || track.title, 'Unknown track'));
      const artist = esc(text(track.artist_name || track.artist, ''));
      const artwork = text(track.artwork_url || track.image || track.spotify_album_image, '');
      return '<div class="played-tile played-tile-compact">'
        + (artwork ? '<img class="played-artwork" src="' + esc(artwork) + '" alt="">' : '<b>' + String(idx + 1).padStart(2, '0') + '</b>')
        + '<div><strong>' + title + '</strong>' + (artist ? '<span>' + artist + '</span>' : '') + '</div>'
        + '</div>';
    }).join('');
    return '<article class="display-slide" data-slide="recent">'
      + '<div class="display-card played-card played-card-compact">'
      + '<div class="played-head"><p class="display-kicker">Played Tonight</p><h1>What we’ve played</h1></div>'
      + (rows ? '<div class="played-grid played-grid-compact">' + rows + '</div>' : '<div class="display-empty">Played tracks will appear here.</div>')
      + '</div></article>';
  }

  function renderMusicBoard() {
    const requests = state.requests || [];
    const recent = state.recent_tracks || [];

    const requestItems = requests.slice(0, 5).map((req, idx) => {
      const person = text(req.requester_name, '');
      const dedication = text(req.dedication, '');
      return '<div class="music-board-request waiting">'
        + '<b>' + String(idx + 1).padStart(2, '0') + '</b>'
        + '<div><strong>' + esc(text(req.track_name || req.title, 'Unknown track')) + '</strong>'
        + (req.artist_name || req.artist ? '<span>' + esc(text(req.artist_name || req.artist, '')) + '</span>' : '')
        + (person ? '<em>Requested by ' + esc(person) + '</em>' : '')
        + (dedication ? '<small>' + esc(dedication) + '</small>' : '')
        + '</div></div>';
    }).join('');

    const playedTracks = recent.slice(0, 8).map((track, idx) => {
      return '<div class="music-board-played-track">'
        + '<b>' + String(idx + 1).padStart(2, '0') + '</b>'
        + '<div><strong>' + esc(text(track.track_name || track.title, 'Unknown track')) + '</strong>'
        + (track.artist_name || track.artist ? '<span>' + esc(text(track.artist_name || track.artist, '')) + '</span>' : '')
        + '</div></div>';
    }).join('');

    return '<article class="display-slide" data-slide="music_board">'
      + '<div class="display-card music-board-card music-board-card-stable">'
      + '<div class="music-board-head"><p class="display-kicker">Tonight’s Music</p><h1>Requests & played</h1></div>'
      + '<div class="music-board-body">'
      + '<section class="music-board-panel music-board-requests">'
      + '<h2>Request queue</h2>'
      + (requestItems ? '<div class="music-board-stack">' + requestItems + '</div>' : '<div class="music-board-empty">No waiting requests yet.</div>')
      + '</section>'
      + '<section class="music-board-panel music-board-played">'
      + '<h2>What we’ve played</h2>'
      + (playedTracks ? '<div class="music-board-played-list">' + playedTracks + '</div>' : '<div class="music-board-empty">Played tracks will appear once they are logged.</div>')
      + '</section>'
      + '</div></div></article>';
  }

  function renderRequests() {
    const requests = state.requests || [];
    const rows = requests.slice(0, 7).map((req) => {
      const status = text(req.status, 'request');
      const person = text(req.requester_name, '');
      return '<li><div>' + trackLine(req) + (person ? '<em>Requested by ' + esc(person) + '</em>' : '') + '</div><small>' + esc(status) + '</small></li>';
    }).join('');
    return '<article class="display-slide" data-slide="requests">'
      + '<div class="display-card list-card">'
      + '<p class="display-kicker">Requested Tonight</p>'
      + '<h1>On the request list</h1>'
      + (rows ? '<ul class="display-request-list">' + rows + '</ul>' : '<div class="display-empty">Requests will appear here once guests start sending them in.</div>')
      + '</div></article>';
  }


  function shuffledPhotos(photos, limit) {
    const pool = Array.isArray(photos) ? photos.slice() : [];
    for (let i = pool.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      const tmp = pool[i];
      pool[i] = pool[j];
      pool[j] = tmp;
    }
    return pool.slice(0, limit);
  }
  function renderPhotos() {
    const photos = state.photos || [];
    const selected = shuffledPhotos(photos, 3);
    const countClass = ' photo-count-' + Math.max(1, Math.min(selected.length, 3));
    const cells = selected.map(photo => '<div class="photo-cell"><img src="' + esc(photo.image_url) + '" alt="Guest photo"></div>').join('');
    return '<article class="display-slide" data-slide="photos">'
      + '<div class="display-card photos-card">'
      + '<div class="photos-title"><p class="display-kicker">Photos from tonight</p><h1>Shared memories</h1></div>'
      + (cells ? '<div class="photo-grid' + countClass + '">' + cells + '</div>' : '<div class="display-empty">Approved guest photos will appear here.</div>')
      + '</div></article>';
  }

  function renderUpcoming() {
    const events = state.upcoming_events || [];
    const cards = events.slice(0, 8).map((ev, idx) => {
      const label = idx === 0 ? 'Next event' : 'Coming soon';
      const date = formatDate(ev.event_date);
      const start = text(ev.start_time, '');
      const end = text(ev.end_time, '');
      const timeText = start && end ? start + ' – ' + end : (start || end || '');
      const dateTime = [date, timeText].filter(Boolean).join(' • ');
      const venue = text(ev.venue_name, '');
      return '<div class="display-coming-up-card">'
        + '<strong class="coming-eyebrow">' + esc(label) + '</strong>'
        + '<span class="coming-title">' + esc(text(ev.event_name, 'Dance Through The Decades')) + '</span>'
        + (dateTime ? '<em class="coming-datetime">' + esc(dateTime) + '</em>' : '')
        + (venue ? '<small class="coming-venue">' + esc(venue) + '</small>' : '')
        + '</div>';
    });
    const rail = cards.length ? cards.concat(cards).join('') : '';
    return '<article class="display-slide" data-slide="upcoming">'
      + '<div class="display-card display-coming-up-card-wrap">'
      + '<div class="display-coming-up-head display-coming-up-head-no-pill"><div><p class="display-kicker">Coming Up</p><h1>What’s happening</h1></div></div>'
      + (rail ? '<div class="display-coming-up-window"><div class="display-coming-up-track">' + rail + '</div></div>' : '<div class="display-empty">Upcoming public events will appear here.</div>')
      + '<div class="display-coming-up-footer"><span>See our website for full details</span><strong>dancethruthedecades.co.uk</strong></div>'
      + '</div></article>';
  }

  function shuffledPartners(partners, limit) {
    const pool = Array.isArray(partners) ? partners.slice() : [];
    for (let i = pool.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      const tmp = pool[i];
      pool[i] = pool[j];
      pool[j] = tmp;
    }
    return pool.slice(0, limit);
  }

  function renderPartners() {
    const selected = shuffledPartners(state.partners || [], 2);
    const cards = selected.map(partner => {
      const logoClass = ' partner-logo-bg-' + esc(text(partner.logo_background, 'dark'));
      const summary = text(partner.summary, '');
      return '<article class="display-partner-tile">'
        + '<div class="display-partner-logo' + logoClass + '">'
        + (partner.image_url ? '<img src="' + esc(partner.image_url) + '" alt="' + esc(text(partner.name, 'Partner')) + ' logo">' : '<span>★</span>')
        + '</div>'
        + '<div class="display-partner-copy">'
        + (partner.category ? '<p>' + esc(partner.category) + '</p>' : '')
        + '<h2>' + esc(text(partner.name, 'Partner')) + '</h2>'
        + (summary ? '<em>' + esc(summary) + '</em>' : '')
        + '</div>'
        + (partner.qr_image_url ? '<div class="display-partner-qr"><img src="' + esc(partner.qr_image_url) + '" alt="' + esc(text(partner.name, 'Partner')) + ' QR code"><strong>Scan to visit</strong></div>' : '')
        + '</article>';
    }).join('');

    return '<article class="display-slide" data-slide="partners">'
      + '<div class="display-card display-partners-card">'
      + '<div class="display-partners-head"><p class="display-kicker">Our Partners</p><h1>Event friends</h1></div>'
      + (cards ? '<div class="display-partners-grid">' + cards + '</div>' : '<div class="display-empty">Partner details will appear here.</div>')
      + '</div></article>';
  }

  function renderSponsors() {
    const sponsors = state.sponsors || [];
    const rows = sponsors.slice(0, 6).map(sp => '<div class="sponsor-tile">' + (sp.image_url ? '<img src="' + esc(sp.image_url) + '" alt="">' : '') + '<strong>' + esc(text(sp.name, 'Event sponsor')) + '</strong>' + (sp.offer ? '<span>' + esc(sp.offer) + '</span>' : '') + '</div>').join('');
    return '<article class="display-slide" data-slide="sponsors">'
      + '<div class="display-card sponsors-card">'
      + '<p class="display-kicker">Supported by</p>'
      + '<h1>Event sponsors</h1>'
      + (rows ? '<div class="sponsor-grid">' + rows + '</div>' : '<div class="display-empty">Sponsor slides can be added per event.</div>')
      + '</div></article>';
  }

  function renderStandby() {
    const standby = state && state.standby ? state.standby : {};
    return '<article class="display-slide" data-slide="standby">'
      + '<div class="display-card standby-social-card standby-social-card-refined">'
      + '<div class="standby-social-head">'
      + '<p class="display-kicker">No current event</p>'
      + '<h1>Dance Thru The Decades</h1>'
      + '</div>'
      + '<div class="standby-social-body">'
      + '<div class="standby-social-copy">'
      + '<strong>Find us online</strong>'
      + '<span>Events • photos • requests • memories</span>'
      + '<em>Scan to visit the website or follow us on Facebook.</em>'
      + '</div>'
      + '<div class="standby-social-qr-grid">'
      + '<div class="standby-social-qr"><img src="' + esc(standby.website_qr_image_url || '') + '" alt="Website QR code"><strong>Website</strong><span>' + esc(standby.website_label || 'dancethruthedecades.co.uk') + '</span></div>'
      + '<div class="standby-social-qr"><img src="' + esc(standby.facebook_qr_image_url || '') + '" alt="Facebook QR code"><strong>Facebook</strong><span>Follow us</span></div>'
      + '</div>'
      + '</div>'
      + '</div></article>';
  }

  function renderSlide(name) {
    switch (name) {
      case 'welcome': return renderWelcome();
      case 'venue': return renderVenue();
      case 'qr': return renderQr();
      case 'now_playing': return renderNowPlaying();
      case 'up_next': return renderUpNext();
      case 'recent': return renderRecent();
      case 'music_board': return renderMusicBoard();
      case 'requests': return renderRequests();
      case 'photos': return renderPhotos();
      case 'upcoming': return renderUpcoming();
      case 'partners': return renderPartners();
      case 'sponsors': return renderSponsors();
      default: return renderStandby();
    }
  }

  function slideContentSignature(data) {
    if (!data) return '';
    const event = data.event || {};
    const keyRows = (rows) => Array.isArray(rows) ? rows.map(row => row && (row.id || row.event_code || row.event_name || row.track_name || row.title || row.image_url || row.name || '')).join(',') : '';
    return [
      data.active_event ? 'active' : 'standby',
      event.id || '',
      Array.isArray(data.slides) ? data.slides.join('|') : '',
      keyRows(data.requests),
      keyRows(data.played_requests),
      keyRows(data.recent_tracks),
      keyRows(data.photos),
      keyRows(data.upcoming_events),
      keyRows(data.partners),
      keyRows(data.sponsors),
      data.venue && data.venue.name ? data.venue.name : ''
    ].join('::');
  }

  function updateActiveNowPlayingSlide() {
    const active = stage.querySelector('.display-slide.active[data-slide="now_playing"]');
    if (!active) return false;
    const replacement = document.createElement('div');
    replacement.innerHTML = renderNowPlaying();
    const next = replacement.firstElementChild;
    if (!next) return false;
    next.classList.add('active');
    active.replaceWith(next);
    return true;
  }

  function normaliseSlides(baseSlides) {
    let slides = Array.isArray(baseSlides) ? baseSlides.slice() : [];
    if (state && state.active_event && slides.indexOf('music_board') === -1) {
      const qrIndex = slides.indexOf('qr');
      slides.splice(qrIndex >= 0 ? qrIndex + 1 : 1, 0, 'music_board');
    }
    if (state && state.active_event && slides.indexOf('now_playing') !== -1 && slides.indexOf('up_next') === -1) {
      slides.splice(slides.indexOf('now_playing') + 1, 0, 'up_next');
    }
    slides = slides.filter(slideAllowed);

    // Keep ordering stable and avoid duplicate QR slides bunching near the music slides.
    const cleaned = [];
    slides.forEach(name => {
      if (cleaned.indexOf(name) === -1 || name === 'qr') cleaned.push(name);
    });
    return cleaned;
  }

  function buildSlides(force) {
    if (!state) return;
    if (footerEvent && state.event) footerEvent.textContent = state.event.event_name || 'Dance Through The Decades';

    let slides = state.active_event ? (state.slides || ['welcome', 'qr', 'now_playing']) : (state.slides || ['standby']);
    slides = normaliseSlides(slides);

    const signature = slideContentSignature(state)
      + '::slides:' + slides.join('|')
      + '::np:' + (currentTrack() ? (currentTrack().id || currentTrack().title || '') : '')
      + '::next:' + (upNextTrack() ? (upNextTrack().id || upNextTrack().title || '') : '');

    if (!force && signature === lastSlideSignature && stage.querySelector('.display-slide')) {
      updateActiveNowPlayingSlide();
      return;
    }

    lastSlideSignature = signature;
    const currentActive = stage.querySelector('.display-slide.active');
    const currentName = currentActive ? currentActive.getAttribute('data-slide') : '';
    stage.innerHTML = slides.map(renderSlide).join('');

    if (currentName) {
      const newIndex = slides.indexOf(currentName);
      slideIndex = newIndex >= 0 ? newIndex : Math.min(slideIndex, Math.max(0, slides.length - 1));
    } else {
      slideIndex = Math.min(slideIndex, Math.max(0, slides.length - 1));
    }

    showSlide(slideIndex);
  }

  function showSlide(index) {
    const slides = Array.from(stage.querySelectorAll('.display-slide'));
    if (!slides.length) return;
    slides.forEach(slide => slide.classList.remove('active'));
    const active = slides[index % slides.length];
    if (active && active.getAttribute('data-slide') === 'partners') {
      const replacement = document.createElement('div');
      replacement.innerHTML = renderPartners();
      const next = replacement.firstElementChild;
      if (next) {
        active.replaceWith(next);
        next.classList.add('active');
        return;
      }
    }
    active.classList.add('active');
  }

  function startLoop() {
    if (slideTimer) clearInterval(slideTimer);
    slideTimer = setInterval(function(){
      const slides = stage.querySelectorAll('.display-slide');
      if (!slides.length) return;
      slideIndex = (slideIndex + 1) % slides.length;
      showSlide(slideIndex);
    }, 12000);
  }

  function fetchJson(url) {
    const sep = url.indexOf('?') === -1 ? '?' : '&';
    return fetch(url + sep + '_=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' }).then(r => r.ok ? r.json() : null).catch(() => null);
  }

  function nowPlayingEndpoint() {
    if (state && state.event && state.event.id) {
      return '/api/public-now-playing.php?event_id=' + encodeURIComponent(state.event.id);
    }
    return nowPlayingUrl;
  }

  function refreshNowPlaying() {
    return fetchJson(nowPlayingEndpoint()).then(np => {
      if (np && np.ok) {
        nowPlaying = np;
        if (Array.isArray(np.tracks) && np.tracks.some(t => t.status === 'current')) {
          lastLiveNowPlaying = np;
        }
      }

      // Do not rebuild the whole slideshow during 5-second music polling.
      // The full 15-second refresh handles slide availability; this keeps the
      // carousel sequence stable and stops it falling into a music/QR mini-loop.
      updateActiveNowPlayingSlide();
    });
  }

  function refresh() {
    return fetchJson(stateUrl).then(data => {
      const hadSlides = !!stage.querySelector('.display-slide');
      if (data && data.ok) state = data;
      return fetchJson(nowPlayingEndpoint()).then(np => {
        if (np && np.ok) {
          nowPlaying = np;
          if (Array.isArray(np.tracks) && np.tracks.some(t => t.status === 'current')) {
            lastLiveNowPlaying = np;
          }
        }
        buildSlides(!hadSlides);
      });
    }).then(() => {
      startLoop();
    });
  }

  setClock();
  setInterval(setClock, 15000);
  refresh();
  refreshTimer = setInterval(refresh, 15000);
  setInterval(refreshNowPlaying, 5000);
  document.addEventListener('visibilitychange', function(){ if (!document.hidden) refresh(); });
})();
