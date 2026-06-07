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
  let slideIndex = 0;
  let slideTimer = null;
  let refreshTimer = null;

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
    const tracks = nowPlaying && Array.isArray(nowPlaying.tracks) ? nowPlaying.tracks : [];
    const current = tracks.find(t => t.status === 'current') || tracks[0] || null;
    if (!current) {
      return '<article class="display-slide" data-slide="now_playing">'
        + '<div class="display-card display-card-centre">'
        + '<p class="display-kicker">Now Playing</p>'
        + '<h1>Music all night</h1>'
        + '<p class="display-muted">The current track will appear here when playback is detected.</p>'
        + '</div></article>';
    }
    return '<article class="display-slide" data-slide="now_playing">'
      + '<div class="display-grid two-col now-layout">'
      + '<div class="display-card artwork-card">'
      + (current.image ? '<img src="' + esc(current.image) + '" alt="Album artwork">' : '<div class="artwork-placeholder">♪</div>')
      + '</div>'
      + '<div class="display-card now-copy">'
      + '<p class="display-kicker">Now Playing' + (current.deck ? ' • Deck ' + esc(current.deck) : '') + '</p>'
      + '<h1>' + esc(text(current.title, 'Unknown track')) + '</h1>'
      + (current.artist ? '<h2>' + esc(current.artist) + '</h2>' : '')
      + '<p class="display-large-note">Keep the requests coming.</p>'
      + '</div></div></article>';
  }

  function renderRecent() {
    const tracks = nowPlaying && Array.isArray(nowPlaying.tracks) && nowPlaying.tracks.length ? nowPlaying.tracks.filter(t => t.status !== 'current') : (state.recent_tracks || []);
    const rows = tracks.slice(0, 6).map((track, idx) => '<li><b>' + String(idx + 1).padStart(2, '0') + '</b><div>' + trackLine(track) + '</div></li>').join('');
    return '<article class="display-slide" data-slide="recent">'
      + '<div class="display-card list-card">'
      + '<p class="display-kicker">Recently Played</p>'
      + '<h1>Tracks played tonight</h1>'
      + (rows ? '<ol class="display-track-list">' + rows + '</ol>' : '<div class="display-empty">Played tracks will appear here.</div>')
      + '</div></article>';
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
      const details = [
        formatDate(ev.event_date),
        ev.start_time ? esc(ev.start_time) : '',
        ev.venue_name ? esc(ev.venue_name) : ''
      ].filter(Boolean).join(' • ');
      return '<div class="display-coming-up-card">'
        + '<strong>' + esc(label) + '</strong>'
        + '<span>' + esc(text(ev.event_name, 'Dance Through The Decades')) + '</span>'
        + (details ? '<em>' + details + '</em>' : '')
        + '</div>';
    });
    const rail = cards.length ? cards.concat(cards).join('') : '';
    return '<article class="display-slide" data-slide="upcoming">'
      + '<div class="display-card display-coming-up-card-wrap">'
      + '<div class="display-coming-up-head"><div><p class="display-kicker">Coming Up</p><h1>What’s happening</h1></div><span>Public events</span></div>'
      + (rail ? '<div class="display-coming-up-window"><div class="display-coming-up-track">' + rail + '</div></div>' : '<div class="display-empty">Upcoming public events will appear here.</div>')
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
    return '<article class="display-slide" data-slide="standby">'
      + '<div class="display-card display-card-centre display-card-hero">'
      + '<p class="display-kicker">Dance Through The Decades</p>'
      + '<h1>Event display ready</h1>'
      + '<p class="display-large-note">Set an active event in the DJ portal to show live requests, QR codes, photos and music.</p>'
      + '</div></article>';
  }

  function renderSlide(name) {
    switch (name) {
      case 'welcome': return renderWelcome();
      case 'qr': return renderQr();
      case 'now_playing': return renderNowPlaying();
      case 'recent': return renderRecent();
      case 'requests': return renderRequests();
      case 'photos': return renderPhotos();
      case 'upcoming': return renderUpcoming();
      case 'sponsors': return renderSponsors();
      default: return renderStandby();
    }
  }

  function buildSlides() {
    if (!state) return;
    if (footerEvent && state.event) footerEvent.textContent = state.event.event_name || 'Dance Through The Decades';
    const slides = state.active_event ? (state.slides || ['welcome', 'qr', 'now_playing']) : ['standby', 'upcoming'];
    stage.innerHTML = slides.map(renderSlide).join('');
    slideIndex = Math.min(slideIndex, Math.max(0, slides.length - 1));
    showSlide(slideIndex);
  }

  function showSlide(index) {
    const slides = Array.from(stage.querySelectorAll('.display-slide'));
    if (!slides.length) return;
    slides.forEach(slide => slide.classList.remove('active'));
    slides[index % slides.length].classList.add('active');
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

  function refresh() {
    return fetchJson(stateUrl).then(data => {
      if (data && data.ok) state = data;
      const npUrl = state && state.event && state.event.id ? '/api/public-now-playing.php?event_id=' + encodeURIComponent(state.event.id) : nowPlayingUrl;
      return fetchJson(npUrl);
    }).then(np => {
      if (np && np.ok) nowPlaying = np;
      buildSlides();
      startLoop();
    });
  }

  setClock();
  setInterval(setClock, 15000);
  refresh();
  refreshTimer = setInterval(refresh, 15000);
  document.addEventListener('visibilitychange', function(){ if (!document.hidden) refresh(); });
})();
