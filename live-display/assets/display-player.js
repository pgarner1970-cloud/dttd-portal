(function(){
  const shell = document.querySelector('.display-shell');
  const stage = document.querySelector('.display-stage');
  if (!shell || !stage) return;

  const stateUrl = shell.dataset.stateUrl || '/api/display-state.php';
  const nowPlayingUrl = shell.dataset.nowPlayingUrl || '/api/public-now-playing.php';
  const isLite = shell.dataset.displayMode === 'lite' || (new URLSearchParams(window.location.search).get('mode') || '').toLowerCase() === 'lite';
  const footerEvent = document.querySelector('[data-display-footer-event]');
  const footerSlideId = document.querySelector('[data-slide-id]');
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
    if (name === 'standby' && state && (state.active_event || (state.event && state.event.id))) return false;
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

  function renderGoodnight() {
    const info = (state && state.goodnight) ? state.goodnight : {};
    const websiteQr = text(info.website_qr_image_url || '', '');
    const facebookQr = text(info.facebook_qr_image_url || '', '');
    const websiteLabel = text(info.website_label || 'dancethruthedecades.co.uk', 'dancethruthedecades.co.uk');

    return '<article class="display-slide" data-slide="goodnight">'
      + '<div class="display-card goodnight-card goodnight-card-horizontal">'
      + '<div class="goodnight-heading-row"><p class="display-kicker">Thank You</p><h1>Goodnight <span class="goodnight-heart" aria-hidden="true">♥</span></h1></div>'
      + '<div class="goodnight-copy">'
      + '<h2>Have a safe journey home</h2>'
      + '<p>Thanks for dancing with us tonight. Follow us on Facebook, share your memories, and check the website for future dates.</p>'
      + '<strong>Hope to see you again soon.</strong>'
      + '</div>'
      + '<div class="goodnight-qr-grid">'
      + '<div class="goodnight-qr-card">' + (facebookQr ? '<img src="' + esc(facebookQr) + '" alt="Facebook QR code">' : '') + '<div><b>Facebook</b><span>Follow us</span></div></div>'
      + '<div class="goodnight-qr-card">' + (websiteQr ? '<img src="' + esc(websiteQr) + '" alt="Website QR code">' : '') + '<div><b>Website</b><span>' + esc(websiteLabel) + '</span></div></div>'
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
      + '<div class="now-feature-head"><p class="display-kicker">Up Next</p><h1>Coming next</h1></div>'
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

  
  
  function trackIdentityKey(row) {
    if (!row) return '';
    const spotify = text(row.spotify_track_id || row.spotify_uri || row.uri || '', '').toLowerCase().trim();
    if (spotify) return 'id:' + spotify.replace(/^spotify:track:/, '');
    const title = trackTitleValue(row).toLowerCase().replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
    const artist = trackArtistValue(row).toLowerCase().replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
    if (!title) return '';
    return 'text:' + title + '|' + artist;
  }

  function requestCloseSecondsRemaining() {
    const raw = state && state.event ? text(state.event.requests_close_at, '') : '';
    if (!raw) return null;
    const target = Date.parse(raw);
    if (!target) return null;
    return Math.floor((target - Date.now()) / 1000);
  }

  function formatCountdown(seconds) {
    seconds = Math.max(0, Number(seconds) || 0);
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);
    if (h > 0) return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
  }

  function renderRequestQueueEmpty() {
    const remaining = requestCloseSecondsRemaining();
    if (remaining !== null && remaining <= 0) {
      return '<div class="music-board-empty music-board-empty-cta"><strong>Requests are closed for tonight</strong><span>Thanks for helping shape the soundtrack.</span></div>';
    }
    const timer = remaining !== null
      ? '<b class="request-empty-timer" data-request-close-countdown>' + esc(formatCountdown(remaining)) + '</b><small>left to send requests</small>'
      : '';
    return '<div class="music-board-empty music-board-empty-cta"><strong>Keep the requests coming!</strong><span>Scan the QR code or use the event page to send your favourite track.</span>' + timer + '</div>';
  }

function trackTitleValue(row) {
    return text(row && (row.track_name || row.title || row.name), 'Unknown track');
  }

  function trackArtistValue(row) {
    return text(row && (row.artist_name || row.artist || row.artists), '');
  }

  function trackArtworkValue(row) {
    return text(row && (row.artwork_url || row.image_url || row.spotify_album_image || row.image || row.album_image || row.album_artwork), '');
  }

  function trackRequesterValue(row) {
    return text(row && (row.requester_name || row.requested_by || row.requester), '');
  }

  function trackDedicationValue(row) {
    return text(row && (row.dedication || row.request_text || row.message || row.note), '');
  }

    function trackKey(row) {
    const identity = trackIdentityKey(row);
    if (identity) return identity;
    const rowId = text(row && (row.request_id || row.id || row.track_id), '').toLowerCase();
    return rowId ? 'row:' + rowId : '';
  }

    function sameTrack(a, b) {
    if (!a || !b) return false;
    const ak = trackIdentityKey(a);
    const bk = trackIdentityKey(b);
    if (ak && bk && ak === bk) return true;

    const at = trackTitleValue(a).toLowerCase().replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
    const bt = trackTitleValue(b).toLowerCase().replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
    const aa = trackArtistValue(a).toLowerCase().replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
    const ba = trackArtistValue(b).toLowerCase().replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
    return !!at && at === bt && (!aa || !ba || aa === ba);
  }

    function uniqueTracks(rows) {
    const out = [];
    const seen = {};
    (Array.isArray(rows) ? rows : []).forEach(row => {
      const key = trackIdentityKey(row) || trackKey(row);
      if (!key) return;
      if (seen[key] !== undefined) {
        const existing = out[seen[key]];
        const existingScore = (trackRequesterValue(existing) ? 4 : 0) + (trackArtworkValue(existing) ? 2 : 0) + (existing && existing.played_at ? 1 : 0);
        const rowScore = (trackRequesterValue(row) ? 4 : 0) + (trackArtworkValue(row) ? 2 : 0) + (row && row.played_at ? 1 : 0);
        if (rowScore > existingScore) out[seen[key]] = row;
        return;
      }
      seen[key] = out.length;
      out.push(row);
    });
    return out;
  }

  function displayStatusForTrack(row, fallback) {
    const current = currentTrack();
    const next = upNextTrack();
    if (current && sameTrack(row, current)) return 'Currently playing';
    if (next && sameTrack(row, next)) return 'Coming up next';

    const raw = text(row && row.status, fallback || '');
    const lower = raw.toLowerCase();
    if (lower === 'played' || lower === 'recent' || lower === 'latest') return 'Played';
    if (lower === 'queued' || lower === 'approved' || lower === 'accepted') return 'Waiting';
    if (lower === 'pending') return 'Pending';
    if (lower === 'rejected') return 'Rejected';
    return raw || (fallback || '');
  }

  function statusClass(label) {
    const key = text(label, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    return key ? ' status-' + key : '';
  }

    function renderTrackRow(row, options) {
    options = options || {};
    const artwork = trackArtworkValue(row);
    const title = trackTitleValue(row);
    const artist = trackArtistValue(row);
    const requester = trackRequesterValue(row);
    const status = displayStatusForTrack(row, options.status || row.status || '');
    const showRequester = options.showRequester !== false && requester;
    const classes = 'display-track-row ' + text(options.className, '') + statusClass(status);

    return '<article class="' + esc(classes) + '">'
      + (artwork ? '<img class="display-track-artwork" src="' + esc(artwork) + '" alt="">' : '<div class="display-track-artwork display-track-artwork-placeholder">♪</div>')
      + '<div class="display-track-copy">'
      + '<strong>' + esc(title) + '</strong>'
      + (artist ? '<span>' + esc(artist) + '</span>' : '')
      + (showRequester ? '<em>Requested by ' + esc(requester) + '</em>' : '')
      + '</div>'
      + (status ? '<p class="display-track-status">' + esc(status) + '</p>' : '')
      + '</article>';
  }

  function trackRank(row) {
    const status = displayStatusForTrack(row, row && row.status).toLowerCase();
    if (status === 'currently playing') return 0;
    if (status === 'coming up next') return 1;
    if (status === 'waiting' || status === 'queued' || status === 'approved' || status === 'accepted') return 2;
    if (status === 'pending') return 3;
    if (status === 'played' || status === 'recent' || status === 'latest') return 4;
    if (status === 'rejected') return 9;
    return 5;
  }

  function sortTrackRows(rows) {
    return uniqueTracks(rows).sort((a, b) => {
      const ar = trackRank(a);
      const br = trackRank(b);
      if (ar !== br) return ar - br;
      return String(a.created_at || a.approved_at || a.played_at || '').localeCompare(String(b.created_at || b.approved_at || b.played_at || ''));
    });
  }


  
  function sortTrackRowsKeepingRequests(rows) {
    return (Array.isArray(rows) ? rows.slice() : []).sort((a, b) => {
      const ar = trackRank(a);
      const br = trackRank(b);
      if (ar !== br) return ar - br;
      const at = String(a.created_at || a.approved_at || a.played_at || '');
      const bt = String(b.created_at || b.approved_at || b.played_at || '');
      if (at !== bt) return bt.localeCompare(at);
      return Number(b.id || 0) - Number(a.id || 0);
    });
  }



    function mergePlayedRows() {
    const playedRequests = Array.isArray(state.played_requests) ? state.played_requests : [];
    const recent = Array.isArray(state.recent_tracks) ? state.recent_tracks : [];

    return uniqueTracks(playedRequests.concat(recent))
      .sort((a, b) => String(b.played_at || b.created_at || '').localeCompare(String(a.played_at || a.created_at || '')));
  }

  function mergeRequestListRows() {
    const allRequests = Array.isArray(state.all_requests) ? state.all_requests : [];
    const requests = Array.isArray(state.requests) ? state.requests : [];
    const playedRequests = Array.isArray(state.played_requests) ? state.played_requests : [];
    const recent = Array.isArray(state.recent_tracks) ? state.recent_tracks : [];

    const requestRows = allRequests.length ? allRequests.slice() : requests.concat(playedRequests);

    // Only pull recent played-history rows onto the Request List when they match a
    // request row. That fills in played requests that only arrived through history,
    // without turning the Request List into a general played-music board.
    recent.forEach(track => {
      const matchesRequest = requestRows.some(req => sameTrack(req, track));
      const alreadyPresent = requestRows.some(req => sameTrack(req, track) && displayStatusForTrack(req, req.status).toLowerCase() === 'played');
      if (matchesRequest && !alreadyPresent) {
        requestRows.push(Object.assign({}, track, {
          requester_name: (requestRows.find(req => sameTrack(req, track)) || {}).requester_name || track.requester_name || '',
          status: 'played'
        }));
      }
    });

    return sortTrackRowsKeepingRequests(requestRows);
  }


function renderRecent() {
    const tracks = Array.isArray(state.recent_tracks) ? state.recent_tracks : [];

    // This slide is the raw event play history. Do not de-duplicate here:
    // if the same track was played twice during the event, or if several rows
    // share weak IDs, the board should still show the latest event-history rows.
    const rows = tracks.slice(0, 10).map(track => renderTrackRow(track, {
      className: 'played-tile played-tile-compact',
      status: sameTrack(track, currentTrack()) ? 'Currently playing' : 'Played',
      showRequester: !!track.requester_name
    })).join('');

    return '<article class="display-slide" data-slide="recent">'
      + '<div class="display-card played-card played-card-compact">'
      + '<div class="played-head"><p class="display-kicker">Played Tonight</p><h1>What we’ve played</h1></div>'
      + (rows ? '<div class="played-grid played-grid-compact display-track-grid">' + rows + '</div>' : '<div class="display-empty">Played tracks will appear here.</div>')
      + '</div></article>';
  }

            
  function requestCloseSecondsRemaining() {
    if (!state || !state.event) return null;
    const raw = state.event.requests_close_at || state.event.request_close_at || state.event.requests_close_time || '';
    if (!raw) return null;
    const closeMs = Date.parse(raw);
    if (!Number.isFinite(closeMs)) return null;
    return Math.max(0, Math.floor((closeMs - Date.now()) / 1000));
  }

  function formatRequestCountdown(seconds) {
    seconds = Math.max(0, Number(seconds) || 0);
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    if (mins >= 60) {
      const hours = Math.floor(mins / 60);
      const remMins = mins % 60;
      return String(hours).padStart(2, '0') + ':' + String(remMins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
    }
    return String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
  }

  function renderRequestQueueEmptyStateSafe() {
    const seconds = requestCloseSecondsRemaining();
    const closed = seconds !== null && seconds <= 0;

    if (closed) {
      return '<div class="music-board-empty music-board-empty-requests">'
        + '<strong>Requests are closed for tonight</strong>'
        + '<span>Thanks for helping shape the soundtrack.</span>'
        + '</div>';
    }

    return '<div class="music-board-empty music-board-empty-requests" data-request-empty-countdown>'
      + '<strong>Keep the requests coming!</strong>'
      + '<span>Scan the QR code or use the event page to send your favourite track.</span>'
      + (seconds !== null ? '<b data-request-countdown data-live-countdown="' + esc(text(state && state.event ? state.event.requests_close_at : '', '')) + '">' + esc(formatHmsCountdown(seconds)) + '</b><em>left to send requests</em>' : '')
      + '</div>';
  }


  function renderMusicBoard() {
    const requests = Array.isArray(state.requests) ? state.requests : [];
    const playedRequests = Array.isArray(state.played_requests) ? state.played_requests : [];
    const current = currentTrack();
    const next = upNextTrack();

    // Requests & played is request-only:
    // left = request queue / loaded request coming up
    // right = requests that have been played
    // Do not pull in general DJ playback history here.
    const liveRequestRows = playedRequests.filter(row => sameTrack(row, current) || sameTrack(row, next));
    const queueRows = sortTrackRowsKeepingRequests(liveRequestRows.concat(requests))
      .filter(row => displayStatusForTrack(row, row.status || '').toLowerCase() !== 'played')
      .slice(0, 5);

    const playedRows = playedRequests.slice()
      .filter(row => !sameTrack(row, current) && !sameTrack(row, next))
      .sort((a, b) => String(b.played_at || b.created_at || '').localeCompare(String(a.played_at || a.created_at || '')))
      .slice(0, 5);

    const requestItems = queueRows.map(row => renderTrackRow(row, {
      className: 'music-board-request',
      status: displayStatusForTrack(row, 'Waiting'),
      showRequester: true,
      showDedication: false
    })).join('');

    const playedTracks = playedRows.map(row => renderTrackRow(row, {
      className: 'music-board-played-track',
      status: 'Played',
      showRequester: !!row.requester_name,
      showDedication: false
    })).join('');

    return '<article class="display-slide" data-slide="music_board">'
      + '<div class="display-card music-board-card music-board-card-stable">'
      + '<div class="music-board-head"><p class="display-kicker">Tonight’s Music</p><h1>Requests & played</h1></div>'
      + '<div class="music-board-body">'
      + '<section class="music-board-panel music-board-requests">'
      + '<h2>Request queue</h2>'
      + (requestItems ? '<div class="music-board-stack display-track-stack">' + requestItems + '</div>' : renderRequestQueueEmptyStateSafe())
      + '</section>'
      + '<section class="music-board-panel music-board-played">'
      + '<h2>What we’ve played</h2>'
      + (playedTracks ? '<div class="music-board-played-list display-track-stack">' + playedTracks + '</div>' : '<div class="music-board-empty">Played requests will appear once they are logged.</div>')
      + '</section>'
      + '</div></div></article>';
  }

          function renderRequests() {
    const rows = mergeRequestListRows().slice(0, 10).map(row => renderTrackRow(row, {
      className: 'request-board-item',
      status: displayStatusForTrack(row, row.status || 'Pending'),
      showRequester: true,
      showDedication: false
    })).join('');

    return '<article class="display-slide" data-slide="requests">'
      + '<div class="display-card request-board-card">'
      + '<div class="request-board-head"><p class="display-kicker">Requested Tonight</p><h1>Request list</h1></div>'
      + (rows ? '<div class="request-board-grid display-track-grid">' + rows + '</div>' : '<div class="display-empty">Requests will appear here once guests start sending them in.</div>')
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
    const selected = shuffledPhotos(photos, isLite ? 2 : 3);
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
    const currentEventId = state && state.event ? Number(state.event.id || 0) : 0;
    const limit = isLite ? 4 : 8;
    const cards = events.slice(0, limit).map((ev, idx) => {
      const evId = Number(ev.id || 0);
      const isCurrent = !!ev.is_current_event || (currentEventId > 0 && evId === currentEventId);
      const label = isCurrent ? 'Current Event' : (idx === 0 ? 'Next event' : 'Coming soon');
      const date = formatDate(ev.event_date);
      const start = text(ev.start_time, '');
      const end = text(ev.end_time, '');
      const timeText = start && end ? start + ' – ' + end : (start || end || '');
      const dateTime = [date, timeText].filter(Boolean).join(' • ');
      const venue = text(ev.venue_name, '');
      return '<div class="display-coming-up-card' + (isCurrent ? ' display-coming-up-card-current' : '') + '">'
        + '<strong class="coming-eyebrow">' + esc(label) + '</strong>'
        + '<span class="coming-title">' + esc(text(ev.event_name, 'Dance Through The Decades')) + '</span>'
        + (dateTime ? '<em class="coming-datetime">' + esc(dateTime) + '</em>' : '')
        + (venue ? '<small class="coming-venue">' + esc(venue) + '</small>' : '')
        + '</div>';
    });
    const rail = cards.length ? (isLite ? cards.join('') : cards.concat(cards).join('')) : '';
    return '<article class="display-slide" data-slide="upcoming">'
      + '<div class="display-card display-coming-up-card-wrap' + ((typeof isLite !== 'undefined' && isLite) ? ' display-coming-up-card-wrap-lite' : '') + '">'
      + '<div class="display-coming-up-head display-coming-up-head-no-pill"><div><p class="display-kicker">Coming Up</p><h1>What’s happening</h1></div></div>'
      + (rail ? '<div class="display-coming-up-window' + (isLite ? ' display-coming-up-window-lite' : '') + '"><div class="' + (isLite ? 'display-coming-up-static-grid' : 'display-coming-up-track') + '">' + rail + '</div></div>' : '<div class="display-empty">Upcoming public events will appear here.</div>')
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

  function secondsUntil(value) {
    if (!value) return null;
    const target = Date.parse(value);
    if (!Number.isFinite(target)) return null;
    return Math.max(0, Math.floor((target - Date.now()) / 1000));
  }

  function formatHmsCountdown(seconds) {
    seconds = Math.max(0, Number(seconds) || 0);
    const hours = Math.floor(seconds / 3600);
    const mins = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    return String(hours).padStart(2, '0') + ':' + String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
  }

  function renderEventTimer() {
    const event = state && state.event ? state.event : {};
    const eventSeconds = secondsUntil(event.event_end_iso || '');
    const requestSeconds = secondsUntil(event.requests_close_iso || event.requests_close_at || '');
    const requestsOpen = event.requests_open !== false && (requestSeconds === null || requestSeconds > 0);

    const eventTarget = text(event.event_end_iso || '', '');
    const requestTarget = text(event.requests_close_iso || event.requests_close_at || '', '');
    const eventTimer = eventSeconds !== null ? formatHmsCountdown(eventSeconds) : '--:--:--';
    const requestTimer = requestSeconds !== null ? formatHmsCountdown(requestSeconds) : '--:--:--';

    return '<article class="display-slide" data-slide="event_timer">'
      + '<div class="display-card event-timer-card">'
      + '<div class="event-timer-head"><p class="display-kicker">Keep Dancing</p><h1>' + esc(text(event.event_name, 'Tonight’s Event')) + '</h1></div>'
      + '<div class="event-timer-grid">'
      + '<section class="event-timer-panel event-timer-main"><span>Event time remaining</span><strong data-live-countdown="' + esc(eventTarget) + '">' + esc(eventTimer) + '</strong><em>until the planned finish</em></section>'
      + '<section class="event-timer-panel"><span>Requests</span><strong' + (requestsOpen && requestTarget ? ' data-live-countdown="' + esc(requestTarget) + '"' : '') + '>' + esc(requestsOpen ? requestTimer : 'Closed') + '</strong><em>' + esc(requestsOpen ? 'left to send requests' : 'Thanks for the requests tonight') + '</em></section>'
      + '</div>'
      + '<p class="event-timer-footer">Requests • Photos • Music • Memories</p>'
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
      case 'goodnight': return renderGoodnight();
      case 'welcome': return renderWelcome();
      case 'venue': return renderVenue();
      case 'qr': return renderQr();
      case 'event_timer': return renderEventTimer();
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
      event.requests_close_at || '',
      Array.isArray(data.slides) ? data.slides.join('|') : '',
      keyRows(data.requests),
      keyRows(data.played_requests),
      keyRows(data.recent_tracks),
      keyRows(data.photos),
      keyRows(data.upcoming_events),
      keyRows(data.partners),
      keyRows(data.sponsors),
      data.venue && data.venue.name ? data.venue.name : '',
      data.goodnight && data.goodnight.website_label ? data.goodnight.website_label : ''
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
    const settings = state && state.slide_settings ? state.slide_settings : {};
    const hasEventContext = !!(state && (state.active_event || (state.event && state.event.id)));

    function enabled(name) {
      if (!settings || !settings[name]) return true;
      return settings[name].enabled !== false && settings[name].enabled !== 0 && settings[name].enabled !== '0';
    }

    slides = slides.filter(name => enabled(name) && slideAllowed(name));
    if (hasEventContext) {
      slides = slides.filter(name => name !== 'standby');
    }

    if (state && state.active_event && slides.indexOf('now_playing') !== -1 && slides.indexOf('up_next') === -1 && upNextTrack()) {
      slides.splice(slides.indexOf('now_playing') + 1, 0, 'up_next');
    }

    // Preserve API order and priority weighting. Only remove accidental adjacent duplicates,
    // because intentional repeated high-priority slides may appear later in the loop.
    const cleaned = [];
    slides.forEach(name => {
      if (cleaned.length && cleaned[cleaned.length - 1] === name) return;
      cleaned.push(name);
    });
    return cleaned;
  }

    function buildSlides(force) {
    if (!state) return false;
    if (footerEvent && state.event) footerEvent.textContent = state.event.event_name || 'Dance Through The Decades';

    let slides = state.active_event ? (state.slides || ['welcome', 'qr', 'now_playing']) : (state.slides || ['standby']);
    slides = normaliseSlides(slides);

    const signature = slideContentSignature(state)
      + '::slides:' + slides.join('|')
      + '::np:' + (currentTrack() ? (currentTrack().id || currentTrack().title || '') : '')
      + '::next:' + (upNextTrack() ? (upNextTrack().id || upNextTrack().title || '') : '')
      + '::durations:' + JSON.stringify(state.slide_durations || {});

    if (!force && signature === lastSlideSignature && stage.querySelector('.display-slide')) {
      updateActiveNowPlayingSlide();
      return false;
    }

    lastSlideSignature = signature;
    const currentActive = stage.querySelector('.display-slide.active');
    const currentName = currentActive ? currentActive.getAttribute('data-slide') : '';
    const currentOffset = currentActive ? Array.from(stage.querySelectorAll('.display-slide')).indexOf(currentActive) : -1;

    stage.innerHTML = slides.map(renderSlide).join('');

    if (currentName) {
      // When duplicates are present because of priority weighting, preserve the
      // current occurrence where possible rather than jumping back to the first one.
      const matchingIndexes = slides.map((name, idx) => name === currentName ? idx : -1).filter(idx => idx >= 0);
      if (matchingIndexes.length) {
        slideIndex = matchingIndexes.find(idx => idx >= currentOffset) ?? matchingIndexes[0];
      } else {
        slideIndex = Math.min(slideIndex, Math.max(0, slides.length - 1));
      }
    } else {
      slideIndex = Math.min(slideIndex, Math.max(0, slides.length - 1));
    }

    showSlide(slideIndex);
    return true;
  }


  function slideCountdownElement() {
    let el = document.querySelector('[data-slide-countdown]');
    if (el) return el;

    const dot = document.querySelector('.display-footer-dot');
    if (!dot || !dot.parentNode) return null;

    let wrap = dot.closest('.display-progress-wrap');
    if (!wrap) {
      wrap = document.createElement('span');
      wrap.className = 'display-progress-wrap';
      dot.parentNode.insertBefore(wrap, dot);
      wrap.appendChild(dot);
    }

    el = document.createElement('span');
    el.className = 'display-slide-countdown';
    el.setAttribute('data-slide-countdown', '');
    el.textContent = '--';
    wrap.appendChild(el);
    return el;
  }

  function updateFooterSlideId(slideName) {
    if (!footerSlideId) return;
    const safeName = text(slideName, 'loading');
    footerSlideId.textContent = safeName;
    footerSlideId.setAttribute('aria-label', 'Current slide: ' + safeName);
  }

  function slideDurationMs(slideName) {
    const durations = state && state.slide_durations ? state.slide_durations : {};
    const raw = durations && slideName ? Number(durations[slideName]) : 0;
    if (raw && raw > 0) return Math.max(5000, Math.min(60000, raw * 1000));
    return (typeof isLite !== 'undefined' && isLite) ? 14500 : 12000;
  }

  function updateSlideCountdownDisplay(secondsRemaining, totalSeconds) {
    const el = slideCountdownElement();
    if (!el) return;
    const safeRemaining = Math.max(0, Math.ceil(Number(secondsRemaining) || 0));
    const safeTotal = Math.max(1, Math.ceil(Number(totalSeconds) || 1));
    el.textContent = String(safeRemaining).padStart(2, '0');
    el.setAttribute('aria-label', String(safeRemaining) + ' seconds until next slide');
    const progress = Math.max(0, Math.min(1, safeRemaining / safeTotal));
    el.style.setProperty('--slide-countdown-progress', String(progress));
  }

  function updateLiveCountdowns() {
    stage.querySelectorAll('[data-live-countdown]').forEach(el => {
      const target = Date.parse(el.getAttribute('data-live-countdown') || '');
      if (!Number.isFinite(target)) return;
      const seconds = Math.max(0, Math.floor((target - Date.now()) / 1000));
      el.textContent = formatHmsCountdown(seconds);
    });
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
        updateFooterSlideId(next.getAttribute('data-slide') || 'partners');
        return;
      }
    }
    active.classList.add('active');
    updateFooterSlideId(active.getAttribute('data-slide') || '');
    updateLiveCountdowns();
  }

      function stopSlideLoop() {
    if (slideTimer) {
      clearTimeout(slideTimer);
      slideTimer = null;
    }
    if (window.dttdSlideCountdownTimer) {
      clearInterval(window.dttdSlideCountdownTimer);
      window.dttdSlideCountdownTimer = null;
    }
  }

  function startLoop(force) {
    if (slideTimer && !force) return;

    stopSlideLoop();

    function scheduleNext() {
      const slides = stage.querySelectorAll('.display-slide');
      if (!slides.length) {
        slideTimer = null;
        return;
      }

      if (slideIndex >= slides.length) slideIndex = 0;

      const current = slides[slideIndex] || slides[0];
      const currentName = current ? current.getAttribute('data-slide') : '';
      updateFooterSlideId(currentName || 'loading');
      const durationMs = slideDurationMs(currentName);
      const totalSeconds = Math.max(1, Math.round(durationMs / 1000));
      const startedAt = Date.now();

      updateSlideCountdownDisplay(totalSeconds, totalSeconds);

      if (window.dttdSlideCountdownTimer) clearInterval(window.dttdSlideCountdownTimer);

      window.dttdSlideCountdownTimer = setInterval(function(){
        const elapsed = Date.now() - startedAt;
        const remainingMs = Math.max(0, durationMs - elapsed);
        updateSlideCountdownDisplay(remainingMs / 1000, totalSeconds);
        updateLiveCountdowns();
      }, 250);

      slideTimer = setTimeout(function(){
        slideTimer = null;
        if (window.dttdSlideCountdownTimer) {
          clearInterval(window.dttdSlideCountdownTimer);
          window.dttdSlideCountdownTimer = null;
        }

        const latestSlides = stage.querySelectorAll('.display-slide');
        if (!latestSlides.length) return;

        slideIndex = (slideIndex + 1) % latestSlides.length;
        showSlide(slideIndex);
        scheduleNext();
      }, durationMs);
    }

    scheduleNext();
  }

  function fetchJson(url) {
    const sep = url.indexOf('?') === -1 ? '?' : '&';
    return fetch(url + sep + '_=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' }).then(r => r.ok ? r.json() : null).catch(() => null);
  }

  function endpointWithEventId(url, eventId) {
    if (!eventId) return url;
    try {
      const parsed = new URL(url, window.location.href);
      parsed.searchParams.set('event_id', String(eventId));
      return parsed.pathname + parsed.search;
    } catch (e) {
      const base = String(url || '/api/public-now-playing.php').split('?')[0];
      return base + '?event_id=' + encodeURIComponent(eventId);
    }
  }

  function nowPlayingEndpoint() {
    if (state && state.event && state.event.id) {
      return endpointWithEventId(nowPlayingUrl, state.event.id);
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

        const rebuilt = buildSlides(!hadSlides);
        if (rebuilt || !slideTimer) {
          startLoop(true);
        } else {
          startLoop(false);
        }
      });
    });
  }

  setClock();
  setInterval(setClock, 15000);
  refresh();
  refreshTimer = setInterval(refresh, 15000);
  setInterval(refreshNowPlaying, 5000);
  document.addEventListener('visibilitychange', function(){ if (!document.hidden) refresh(); });
})();
