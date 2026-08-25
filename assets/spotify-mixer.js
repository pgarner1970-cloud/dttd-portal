// Keep DJ Playlist/Public Request rows compact: truncate request notes in overview lists only.
  // Loaded deck request notes remain full length.
const overviewStyle = document.createElement('style');
overviewStyle.textContent = `
    .playlist-note, .request-message {
      display: block;
      max-width: 100%;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .playlist-note strong { white-space: nowrap; }
    .playlist-row > div, .request-row > div { min-width: 0; }
  `;
document.head.appendChild(overviewStyle);
(function(){
  const app = document.querySelector('.spotify-mixer-app');
  if(!app) return;
  const api = app.dataset.api || 'mixer-api.php';
  const searchApi = app.dataset.searchApi || '/api/spotify-search.php';
  const localSearchApi = app.dataset.localSearchApi || '/api/local-music-search.php';
  let state = null;
  let searchTimer = null;
  let pollTimer = null;
  let prepareTimer = null;
  let uiTimer = null;
  let lastStateSyncAt = 0;
  let stateRefreshInFlight = false;
  let lastStateRefreshStartedAt = 0;
  const STATE_POLL_MS = 15000;
  const TRANSPORT_SETTLE_MS = 6000;
  const SEEK_SETTLE_MS = 6000;
  const SEEK_SYNC_TOLERANCE_MS = 2500;
  let busy = false;
  let actionSequence = 0;
  const pendingTransportHolds = {};
  const pendingSeekHolds = {};
  const endCheckSent = {};
  const END_CHECK_REMAINING_MS = 1600;
  const END_CHECK_REPEAT_MS = 12000;
  let activeSource = 'search';
  let cratesLoaded = false;
  let activeCrateId = '';
  let activeCrateName = '';
  let activeCrateTracks = [];
  let cratePickerPage = 0;
  let crateArtistMode = false;
  let crateArtistLoaded = false;
  let crateArtistLoadPromise = null;
  let crateArtistTracks = [];
  let crateArtistLetter = '';
  let crateArtistName = '';
  let crateArtistPage = 0;
  let crateArtistTrackPage = 0;
  let cratePage = 0;
  let crateDrawerOpen = true;
  let availableCrates = [];
  let searchMode = 'broad';
  let lastSearchQuery = '';
  let lastSearchTracks = [];
  let searchPage = 0;
  let playlistPage = 0;
  const PLAYLIST_PAGE_SIZE = 8;
  let selectedLibraryChoice = null;
  let selectedLibraryChoices = [];
  let debugEnabled = false;
  let debugBuffer = [];
  let debugLastProgress = {};
  const DEBUG_BUFFER_LIMIT = 600;
  const MIXER_TAB_ID = (() => {
    try {
      const key = 'dttd_mixer_tab_id';
      let id = sessionStorage.getItem(key);
      if(!id){
        id = 'tab-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
        sessionStorage.setItem(key, id);
      }
      return id;
    } catch(e) {
      return 'tab-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
    }
  })();
  let libraryViewMode = localStorage.getItem('dttd_music_library_view') || 'comfortable';
  function searchPageSize(){
    if(libraryViewMode === 'list') return 20;
    if(libraryViewMode === 'compact') return 16;
    return 10;
  }
  function crateTrackPageSize(){
    if(libraryViewMode === 'list') return 20;
    if(libraryViewMode === 'compact') return 16;
    return 12;
  }
  function crateArtistTrackPageSize(){
    if(libraryViewMode === 'list') return 20;
    if(libraryViewMode === 'compact') return 16;
    return 10;
  }
  function libraryTestPagesEnabled(query){
    try{
      if(new URLSearchParams(window.location.search).get('library_test_pages') === '1') return true;
    }catch(e){}
    return /\b(testpages|duplicate)\b/i.test(String(query || ''));
  }
  function expandTracksForPagingTest(tracks, query){
    if(!libraryTestPagesEnabled(query) || !Array.isArray(tracks) || !tracks.length) return tracks;
    const out = [];
    for(let i = 0; i < 3; i++){
      tracks.forEach((track, idx) => {
        out.push(Object.assign({}, track, {
          id: String(track.id || track.spotify_id || track.title || 'track') + ':test:' + i + ':' + idx,
          title: String(track.title || 'Track') + (i ? ' · test copy ' + (i + 1) : '')
        }));
      });
    }
    return out;
  }

  const $ = (sel) => document.querySelector(sel);
  const els = {
    toast: $('#mixerToast'),
    deviceA: $('#deviceA'), deviceB: $('#deviceB'),
    deckADevice: $('#deckADevice'), deckBDevice: $('#deckBDevice'),
    deckAState: $('#deckAState'), deckBState: $('#deckBState'), deckAVu: $('#deckAVu'), deckBVu: $('#deckBVu'), mixerModePill: $('#mixerModePill'), deckAAccount: $('#deckAAccount'), deckBAccount: $('#deckBAccount'), deckANode: $('#deckANode'), deckBNode: $('#deckBNode'), deckAWarning: $('#deckAWarning'), deckBWarning: $('#deckBWarning'),
    loadedA: $('#loadedA'), loadedB: $('#loadedB'), deckANote: $('#deckANote'), deckBNote: $('#deckBNote'),
    spotifyStatus: $('#spotifyStatus'),
    search: $('#spotifySearch'), searchResults: $('#searchResults'), searchStatus: $('#searchStatus'),
    searchModeButtons: document.querySelectorAll('[data-search-mode]'), searchPager: $('#searchPager'), libraryViewSelect: $('#musicLibraryViewSelect'),
    publicRequests: $('#publicRequests'), djPlaylist: $('#djPlaylist'), playlistPager: $('#playlistPager'),
    requestCount: $('#requestCount'), playlistCount: $('#playlistCount'),
    sourceTabs: document.querySelectorAll('[data-source-tab]'), sourcePanels: document.querySelectorAll('[data-source-panel]'),
    djCrateTiles: $('#djCrateTiles'), crateTileDrawer: $('#crateTileDrawer'), crateDrawerToggle: $('#crateDrawerToggle'), crateSummaryName: $('#crateSummaryName'), crateSummaryCount: $('#crateSummaryCount'), annotateCrates: $('#annotateCrates'), djCrateTracks: $('#djCrateTracks'), cratePager: $('#cratePager'), djCrateStatus: $('#djCrateStatus'), refreshCrates: $('#refreshCrates'), showNewCrate: $('#showNewCrate'), newCratePanel: $('#newCratePanel'), cancelNewCrate: $('#cancelNewCrate'), newCrateName: $('#newCrateName'), createCrate: $('#createCrate'), historyList: $('#historyList'),
    choiceModal: $('#mixerChoiceModal'), choiceImage: $('#choiceImage'), choiceTitle: $('#choiceTitle'), choiceArtist: $('#choiceArtist'), choiceActions: $('#choiceActions'), choiceWarning: $('#choiceWarning'), choiceCancel: $('#choiceCancel'),
    musicLibraryModal: $('#musicLibraryModal'), openMusicLibrary: $('#openMusicLibrary'), closeMusicLibrary: $('#closeMusicLibrary'), musicLibraryActionBar: $('#musicLibraryActionBar'),
    debugPanel: $('#mixerDebugPanel'), debugToggle: $('#mixerDebugToggle'), debugFlush: $('#mixerDebugFlush'), debugClear: $('#mixerDebugClear'), debugDownload: $('#mixerDebugDownload'), debugStatus: $('#mixerDebugStatus')
  };

  function esc(s){ return String(s ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c])); }
  function duration(ms){ if(!ms && ms !== 0) return ''; const sec=Math.max(0, Math.round(Number(ms)/1000)); return Math.floor(sec/60)+':'+String(sec%60).padStart(2,'0'); }

  function debugNow(){ return new Date().toISOString(); }
  function debugSanitize(value, depth=0){
    if(depth > 4) return '[max-depth]';
    if(value === null || value === undefined) return value;
    if(Array.isArray(value)) return value.slice(0, 80).map(v => debugSanitize(v, depth + 1));
    if(typeof value === 'object'){
      const out = {};
      Object.keys(value).slice(0, 80).forEach(k => {
        if(/token|secret|password|cookie|authorization|client_secret|refresh/i.test(k)) out[k] = '[redacted]';
        else out[k] = debugSanitize(value[k], depth + 1);
      });
      return out;
    }
    if(typeof value === 'string'){
      value = value.replace(/Bearer\s+[A-Za-z0-9._~+/=:-]+/ig, 'Bearer [redacted]');
      if(value.length > 900) return value.slice(0, 900) + '…[truncated]';
    }
    return value;
  }
  function debugTrace(event, data={}){
    if(!debugEnabled) return;
    debugBuffer.push({
      t: debugNow(),
      source: 'frontend',
      event: String(event || 'event'),
      data: debugSanitize(data)
    });
    if(debugBuffer.length > DEBUG_BUFFER_LIMIT) debugBuffer.splice(0, debugBuffer.length - DEBUG_BUFFER_LIMIT);
  }
  function updateDebugUi(meta){
    if(meta && typeof meta.enabled !== 'undefined') debugEnabled = !!meta.enabled;
    if(els.debugToggle){
      els.debugToggle.textContent = debugEnabled ? 'Disable Debug' : 'Enable Debug';
      els.debugToggle.classList.toggle('green', debugEnabled);
      els.debugToggle.classList.toggle('dark', !debugEnabled);
      els.debugToggle.setAttribute('aria-pressed', debugEnabled ? 'true' : 'false');
    }
    if(els.debugStatus){
      const size = meta && meta.size_bytes ? Math.round(Number(meta.size_bytes) / 1024) + ' KB' : '0 KB';
      els.debugStatus.textContent = debugEnabled ? `Debug enabled · ${size} · ${debugBuffer.length} browser events buffered` : 'Debug disabled — open Mixer diagnostics and click Enable Debug to start capture';
    }
    if(els.debugDownload){
      els.debugDownload.href = api + '?action=debug_download&_=' + Date.now();
      els.debugDownload.toggleAttribute('hidden', !debugEnabled && !(meta && meta.size_bytes));
    }
  }
  function flushDebug(reason='manual'){
    if(!debugEnabled || !debugBuffer.length) return;
    const events = debugBuffer.splice(0, debugBuffer.length);
    const body = new URLSearchParams();
    body.append('action', 'debug_client');
    body.append('reason', reason);
    body.append('events_json', JSON.stringify(events));
    try{
      if(navigator.sendBeacon){
        const blob = new Blob([body.toString()], {type:'application/x-www-form-urlencoded;charset=UTF-8'});
        if(navigator.sendBeacon(api, blob)) return;
      }
    }catch(e){}
    fetch(api, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body, cache:'no-store', keepalive:true}).catch(()=>{});
  }
  function trackSummary(track){
    if(!track) return null;
    return {id:track.id||'', title:track.title||'', artist:track.artist||'', duration_ms:track.duration_ms||null, position_base_ms:track.position_base_ms||null, paused_position_ms:track.paused_position_ms||null, position_updated_at:track.position_updated_at||null, played_on_deck:!!track.played_on_deck};
  }

  function resultMetaLine(track){
    const parts = [];
    const artist = String(track?.artist || '').trim();
    const len = duration(track?.duration_ms);
    if(artist) parts.push(artist);
    if(len) parts.push(len);
    return parts.join(' • ');
  }
  function artistSearchButton(track){
    const artist = String(track?.artist || '').trim();
    if(!artist) return '';
    const title = cleanTrackTitleForSearch(track?.title || '');
    return `<button type="button" class="artist-search-btn" data-artist-search="${esc(artist)}" data-track-title="${esc(title)}" title="Search this song by ${esc(artist)}" aria-label="Search this artist"></button>`;
  }
  function cleanTrackTitleForSearch(title){
    return String(title || '')
      .replace(/\s*[-–—:]\s*(original\s+)?(extended|single|radio|club|dance|album|remaster(ed)?|remix|reprise|version|edit|mix).*$/i, '')
      .replace(/\((original\s+)?(extended|single|radio|club|dance|album|remaster(ed)?|remix|reprise|version|edit|mix)[^)]*\)/ig, '')
      .replace(/\s+/g, ' ')
      .trim();
  }
  function spotifyFieldQuote(value){
    return '"' + String(value || '').replace(/"/g, '').trim() + '"';
  }
  function buildSpotifySearchQuery(query, mode, artist){
    query = String(query || '').trim();
    artist = String(artist || '').trim();
    if(!query) return '';
    if(mode === 'track_artist' && artist){
      return 'track:' + spotifyFieldQuote(query) + ' artist:' + spotifyFieldQuote(artist);
    }
    if(mode === 'track'){
      return 'track:' + spotifyFieldQuote(query);
    }
    return query;
  }
  function updateSearchModeButtons(){
    if(!els.searchModeButtons) return;
    els.searchModeButtons.forEach(btn => {
      const active = btn.dataset.searchMode === searchMode;
      btn.classList.toggle('active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }
  function updateLibraryView(){
    libraryViewMode = ['comfortable','compact','list'].includes(libraryViewMode) ? libraryViewMode : 'comfortable';
    if(app){
      app.classList.toggle('library-view-comfortable', libraryViewMode === 'comfortable');
      app.classList.toggle('library-view-compact', libraryViewMode === 'compact');
      app.classList.toggle('library-view-list', libraryViewMode === 'list');
    }
    if(els.libraryViewSelect && els.libraryViewSelect.value !== libraryViewMode){
      els.libraryViewSelect.value = libraryViewMode;
    }
  }
  function setLibraryView(mode){
    libraryViewMode = ['comfortable','compact','list'].includes(mode) ? mode : 'comfortable';
    try{ localStorage.setItem('dttd_music_library_view', libraryViewMode); }catch(e){}
    searchPage = 0;
    cratePage = 0;
    updateLibraryView();
    renderSearchResults(lastSearchTracks);
    if(crateArtistMode) renderCrateArtistIndex();
    else renderDjCrateTracks(activeCrateTracks);
    apiPost({action:'set_music_library_view', view:libraryViewMode})
      .then(data => { if(data && data.state) acceptState(data.state); })
      .catch(() => {});
  }
  function invalidateCrateArtistIndex(){
    crateArtistLoaded = false;
    crateArtistTracks = [];
    crateArtistTrackPage = 0;
  }
  function refreshCrateArtistIndexAfterMutation(){
    invalidateCrateArtistIndex();
    if(crateArtistMode) return loadCrateArtistIndex(true);
    return ensureCrateTrackIndex(true).then(() => {
      if(activeSource === 'search') renderSearchResults(lastSearchTracks);
    });
  }
  function normalizeCrateMatchText(value){
    return String(value || '')
      .toLowerCase()
      .replace(/['’]/g, '')
      .replace(/[^a-z0-9]+/g, ' ')
      .trim();
  }
  function crateTrackMatchKeys(track){
    const id = String(track?.id || track?.spotify_id || '').trim().toLowerCase();
    const text = normalizeCrateMatchText(track?.title) + '|' + normalizeCrateMatchText(track?.artist);
    return {id, text};
  }
  function crateTrackIndex(){
    const ids = {};
    const text = {};
    (Array.isArray(crateArtistTracks) ? crateArtistTracks : []).forEach(track => {
      const keys = crateTrackMatchKeys(track);
      if(keys.id) ids[keys.id] = true;
      if(keys.text !== '|') text[keys.text] = true;
    });
    return {ids, text};
  }
  function searchTrackIsInCrate(track){
    if(!crateArtistLoaded) return false;
    const keys = crateTrackMatchKeys(track);
    const index = crateTrackIndex();
    return !!((keys.id && index.ids[keys.id]) || (keys.text !== '|' && index.text[keys.text]));
  }
  function refreshSearchCrateHighlights(){
    if(activeSource === 'search' && lastSearchTracks.length) renderSearchResults(lastSearchTracks);
  }
  function ensureCrateTrackIndex(force=false){
    if(!force && crateArtistLoaded) return Promise.resolve(crateArtistTracks);
    if(crateArtistLoadPromise && !force) return crateArtistLoadPromise;
    crateArtistLoadPromise = apiGet({action:'crate_artist_index'})
      .then(data => {
        if(data.ok){
          crateArtistLoaded = true;
          crateArtistTracks = Array.isArray(data.tracks) ? data.tracks : [];
        }
        return crateArtistTracks;
      })
      .finally(() => { crateArtistLoadPromise = null; });
    return crateArtistLoadPromise;
  }
  function refreshDjCratesAfterMutation(){
    cratesLoaded = false;
    if(activeSource === 'crates') return loadDjCrates(true);
    return Promise.resolve();
  }
  function currentSearchLooksLikeTrackTitle(query, sourceTrack){
    query = String(query || '').trim();
    if(!query) return false;

    const trackTitle = cleanTrackTitleForSearch(sourceTrack?.title || '');
    if(trackTitle && query.length >= 6){
      const q = query.toLowerCase();
      const t = trackTitle.toLowerCase();
      if(t.includes(q) || q.includes(t)) return true;
    }

    // One-word broad searches such as "Michael" are normally artist/category
    // probes, not enough evidence for a track+artist refinement.
    if(!/\s/.test(query)) return false;

    // Short two-word searches are often partial artist names; keep Artist search
    // literal unless the query resembles a song title.
    const songTitleSignals = /\b(love|eyes|heart|night|dance|song|baby|girl|boy|you|me|my|your|the|of|to|in|on|with|without|take|want|need|get|give|make|feel|can't|cant|don't|dont|won't|wont)\b/i;
    return query.length >= 10 && songTitleSignals.test(query);
  }
  function runArtistSearch(artist, sourceTrack){
    artist = String(artist || '').trim();
    if(!artist || !els.search) return;

    const currentQuery = String(els.search.value || lastSearchQuery || '').trim();
    const sourceTitle = String(sourceTrack?.title || '').trim();
    const trackTitle = cleanTrackTitleForSearch(currentQuery || sourceTitle);
    const useTrackArtist = currentSearchLooksLikeTrackTitle(currentQuery, sourceTrack);

    setSourceTab('search');

    if(useTrackArtist && trackTitle){
      searchMode = 'track_artist';
      els.search.value = trackTitle;
      updateSearchModeButtons();
      els.search.focus();
      clearTimeout(searchTimer);
      search(trackTitle, {artist});
      return;
    }

    searchMode = 'broad';
    updateSearchModeButtons();
    els.search.value = artist;
    els.search.focus();
    clearTimeout(searchTimer);
    search(artist);
  }
  function sourceLabel(track){
    const src = String(track?.loaded_origin || track?.source || '').toLowerCase();
    if(src === 'dj_playlist') return 'DJ Playlist';
    if(src === 'public_request' || src === 'request') return 'Public Request';
    if(src === 'dj_crate' || src === 'crate') return 'DJ Crate';
    if(src === 'history') return 'History';
    if(src === 'local') return 'Local Music';
    if(src === 'search' || src === 'track') return 'Search';
    return src ? src.replace(/_/g, ' ') : 'Manual';
  }
  function playedThresholdLabel(track){
    const ms = Number(track?.duration_ms || 0);
    if(!ms) return '50% or 90s';
    const threshold = Math.min(ms * 0.5, 90000);
    return duration(threshold) + ' / ' + duration(ms);
  }
  function progressStatus(track, deck){
    if(!track || !track.id) return {label:'Empty', cls:'empty', detail:''};
    if(track.played_qualified) return {label:'Played', cls:'played', detail:'Played threshold reached'};
    const playing = deck ? deckIsPlaying(deck) : false;
    if(playing) return {label:'Playing', cls:'playing', detail:'Will count as played at ' + playedThresholdLabel(track)};
    if(deck && deckIsPreparingLocal(deck)) return {label:(track.local_autoplay_after_prepare ? 'Preparing to play' : 'Preparing'), cls:'preparing', detail:(track.local_autoplay_after_prepare ? 'Will start automatically once the Raspberry Pi confirms the local track is queued' : 'Waiting for the Raspberry Pi to confirm the local track is queued')};
    if(isLocalTrack(track) && track.local_prepare_error) return {label:'Prepare failed', cls:'preparing', detail:String(track.local_prepare_error || 'The Raspberry Pi could not prepare this local track')};
    if(isLocalTrack(track) && track.local_is_prepared && !track.played_on_deck) return {label:'Ready', cls:'loaded', detail:'Local track is confirmed ready on the Raspberry Pi'};
    if(track.played_on_deck) return {label:'Paused / in progress', cls:'progress', detail:'Not marked played yet'};
    return {label:'Loaded', cls:'loaded', detail:'Not marked played until ' + playedThresholdLabel(track)};
  }
  function workflowBadge(label, cls='info', title=''){
    return `<span class="workflow-badge ${esc(cls)}"${title ? ` title="${esc(title)}"` : ''}>${esc(label)}</span>`;
  }

  function debugProgressDecision(deck, track, decision){
    if(!debugEnabled || !track || !track.id) return;
    const now = Date.now();
    const key = deck + ':' + String(track.id || '');
    const last = debugLastProgress[key] || {};
    const progressBucket = Math.floor(Number(decision.progressMs || 0) / 1000);
    const shouldLog = !last.t || now - last.t > 1500 || last.active !== decision.active || last.sameTrack !== decision.sameTrack || Math.abs((last.progressBucket || 0) - progressBucket) >= 5;
    if(!shouldLog) return;
    debugLastProgress[key] = {t:now, active:decision.active, sameTrack:decision.sameTrack, progressBucket};
    debugTrace('progress_decision', {deck, track:trackSummary(track), decision});
  }

  function deckProgress(track, deck){
    const durationMs = Number(track?.duration_ms) || 0;
    if(isLocalTrack(track)){
      const active = !!track?.local_is_playing;
      let progressMs = Number(track?.position_base_ms || track?.paused_position_ms || 0) || 0;
      if(active && track?.position_updated_at){
        progressMs += Math.max(0, (Date.now() / 1000 - Number(track.position_updated_at)) * 1000);
      }
      if(durationMs) progressMs = Math.min(progressMs, durationMs);
      const pct = durationMs ? Math.min(100, Math.max(0, (progressMs / durationMs) * 100)) : 0;
      const remainingMs = durationMs ? Math.max(0, durationMs - progressMs) : 0;
      debugProgressDecision(deck, track, {kind:'local', active, sameTrack: active || progressMs > 0, durationMs, progressMs, remainingMs, pct});
      return {active, sameTrack: active || progressMs > 0, durationMs, progressMs, remainingMs, pct};
    }
    const deviceId = state?.['device_' + deck] || '';
    const player = state?.['player_' + deck] || {};
    const playback = player.playback || {};
    const playbackTrack = playback.track || {};
    const active = !!deviceId && playback.device_id === deviceId && !!playback.is_playing;
    const sameTrack = !!track?.id && !!playbackTrack.id && String(track.id) === String(playbackTrack.id);
    const spotifyDurationMs = sameTrack ? (Number(playback.duration_ms) || Number(track.duration_ms) || 0) : durationMs;
    let progressMs = sameTrack ? (Number(playback.progress_ms) || 0) : (Number(track?.position_base_ms || track?.paused_position_ms || 0) || 0);
    if(active && sameTrack){
      progressMs += Math.max(0, Date.now() - Number(state?._receivedAtMs || Date.now()));
    }
    if(spotifyDurationMs) progressMs = Math.min(progressMs, spotifyDurationMs);
    const pct = spotifyDurationMs ? Math.min(100, Math.max(0, (progressMs / spotifyDurationMs) * 100)) : 0;
    const remainingMs = spotifyDurationMs ? Math.max(0, spotifyDurationMs - progressMs) : 0;
    const decision = {kind:'spotify', active, sameTrack: sameTrack || progressMs > 0, rawSameTrack:sameTrack, durationMs: spotifyDurationMs, progressMs, remainingMs, pct, playback_track_id:playbackTrack.id||'', playback_device_id:playback.device_id||'', deck_device_id:deviceId};
    debugProgressDecision(deck, track, decision);
    return decision;
  }

  function maybeRequestFastEndCheck(deck, track, prog){
    if(!deck || !track || !track.id || !prog || !prog.active || !prog.sameTrack || !prog.durationMs) return;
    if(Number(prog.remainingMs || 0) > END_CHECK_REMAINING_MS) return;
    const key = deck + ':' + String(track.id || '');
    const now = Date.now();
    const last = Number(endCheckSent[key] || 0);
    if(last && (now - last) < END_CHECK_REPEAT_MS) return;
    endCheckSent[key] = now;
    debugTrace('fast_end_check_requested', {
      deck,
      track: trackSummary(track),
      remaining_ms: Math.round(Number(prog.remainingMs || 0)),
      progress_ms: Math.round(Number(prog.progressMs || 0)),
      duration_ms: Math.round(Number(prog.durationMs || 0))
    });
    setTimeout(() => {
      refresh(true, {forcePlayback:true, forceReason:'fast_end_check'});
    }, 250);
  }

  function toast(msg, ok=true){
    if(!els.toast) return;
    els.toast.textContent = msg;
    els.toast.className = 'mixer-toast ' + (ok ? 'ok' : 'err');
    els.toast.style.display = 'block';
    clearTimeout(els.toast._t);
    els.toast._t = setTimeout(()=>{ els.toast.style.display='none'; }, 4200);
  }
  async function apiGet(params){
    const started = performance.now();
    debugTrace('api_get_start', {params});
    const res = await fetch(api + '?' + new URLSearchParams(params).toString() + '&_=' + Date.now(), {cache:'no-store'});
    const data = await res.json();
    debugTrace('api_get_end', {params, status:res.status, elapsed_ms:Math.round(performance.now() - started), ok:data?.ok, message:data?.message||'', error:data?.error||''});
    if(data?.debug) updateDebugUi(data.debug);
    return data;
  }
  async function apiPost(params){
    const started = performance.now();
    const body = new URLSearchParams();
    Object.keys(params).forEach(k => body.append(k, params[k]));
    debugTrace('api_post_start', {params});
    const res = await fetch(api, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body, cache:'no-store'});
    const data = await res.json();
    debugTrace('api_post_end', {params, status:res.status, elapsed_ms:Math.round(performance.now() - started), ok:data?.ok, message:data?.message||'', error:data?.error||''});
    if(data?.debug) updateDebugUi(data.debug);
    return data;
  }
  function image(src){ return src || 'https://dancethruthedecades.co.uk/assets/glitter-ball-clean.png'; }

  function isLocalTrack(track){
    return String(track?.source || '').toLowerCase() === 'local' || String(track?.id || '').indexOf('local:') === 0 || !!track?.local_track_id;
  }
  function prepareSearchTrack(track, fallbackSource){
    const t = Object.assign({}, track || {});
    const local = isLocalTrack(t) || fallbackSource === 'local';
    if(local){
      t.source = 'local';
      t.source_label = t.source_label || 'Local';
      const badges = Array.isArray(t.badges) ? t.badges.slice() : [];
      if(!badges.some(b => String(b?.type || '') === 'local')) badges.unshift({type:'local', label:'Local'});
      if(t.needs_review && !badges.some(b => String(b?.type || '') === 'review')) badges.push({type:'review', label:'Review'});
      if(String(t.spotify_match_status || '').toLowerCase() === 'matched' && !badges.some(b => String(b?.type || '') === 'matched')) badges.push({type:'matched', label:'Spotify match'});
      t.badges = badges;
      return t;
    }
    t.source = t.source || 'spotify';
    const badges = Array.isArray(t.badges) ? t.badges.slice() : [];
    if(!badges.some(b => String(b?.type || '') === 'spotify')) badges.unshift({type:'spotify', label:'Spotify'});
    t.badges = badges;
    return t;
  }

  function deviceIsOnline(id){
    if(!id) return false;
    return (state?.devices || []).some(d => String(d.id) === String(id));
  }
  function setDeckPlayingVisual(deck, playing){
    const panel = document.querySelector('.mixer-panel-' + deck);
    if(panel) panel.classList.toggle('deck-playing', !!playing);
  }
  function setDeviceAlert(deck, missing){
    const panel = document.querySelector('.mixer-panel-' + deck);
    if(panel) panel.classList.toggle('device-missing', !!missing);
    const deviceEl = deck === 'a' ? els.deckADevice : els.deckBDevice;
    if(deviceEl) deviceEl.classList.toggle('device-alert-text', !!missing);
    document.querySelectorAll('[data-save-deck="' + deck + '"]').forEach(btn => {
      btn.classList.toggle('device-alert', !!missing);
      btn.title = missing ? 'Assigned Spotify device is offline - choose an available device and save' : 'Save Spotify device assignment';
    });
  }
  function deviceName(id){
    const d = (state?.devices || []).find(x => x.id === id);
    return d ? d.name : (id ? 'Selected device' : 'Not assigned');
  }
  function deckIsPlaying(deck){
    const loaded = state?.['player_' + deck]?.loaded || null;
    if(isLocalTrack(loaded)) return !!loaded.local_is_playing;
    const deviceId = state?.['device_' + deck] || '';
    const reported = state?.['player_' + deck]?.state === 'playing';
    const active = !!deviceId && state?.active_device_id === deviceId && !!state?.is_playing;
    return reported || active;
  }
  function deckHasLoaded(deck){
    return !!state?.['player_' + deck]?.loaded?.id;
  }
  function deckLoadedTrack(deck){
    return state?.['player_' + deck]?.loaded || null;
  }
  function deckLoadedIsLocal(deck){
    return isLocalTrack(deckLoadedTrack(deck));
  }
  function deckLocalPrepareAgeSeconds(deck){
    const track = deckLoadedTrack(deck);
    if(!isLocalTrack(track) || !track.local_prepare_requested_at) return null;
    const requested = Number(track.local_prepare_requested_at) || 0;
    if(!requested) return null;
    return (Date.now() / 1000) - requested;
  }
  function deckIsPreparingLocal(deck){
    const track = deckLoadedTrack(deck);
    if(!isLocalTrack(track) || deckIsPlaying(deck)) return false;
    if(track.local_prepare_error || track.local_is_prepared) return false;
    const age = deckLocalPrepareAgeSeconds(deck);
    // Red now means a real pending prepare, not just a timer. The Pi clears this
    // state when node-command completion confirms local_prepare succeeded/failed.
    // Keep a safety timeout so a lost command cannot leave the deck red forever.
    if(track.local_autoplay_after_prepare) return age === null || (age > -30 && age < 90);
    return age !== null && age > -30 && age < 45;
  }
  function cleanupTransportHolds(){
    const now = Date.now();
    Object.keys(pendingTransportHolds).forEach(deck => {
      if(!pendingTransportHolds[deck] || Number(pendingTransportHolds[deck].expiresAt || 0) <= now){
        delete pendingTransportHolds[deck];
      }
    });
  }
  function forceDeckPlaybackFlag(deck, playing){
    if(!state) return;
    const key = 'player_' + deck;
    const player = state[key] || (state[key] = {});
    const loaded = player.loaded || null;
    if(!loaded) return;
    player.state = playing ? 'playing' : 'standby';
    if(isLocalTrack(loaded)){
      loaded.local_is_playing = !!playing;
      loaded.position_updated_at = Date.now() / 1000;
    }
    if(player.playback){
      player.playback.is_playing = !!playing;
      if(playing){
        player.playback.device_id = state['device_' + deck] || player.playback.device_id || '';
        player.playback.track = player.playback.track || {id: loaded.id, title: loaded.title, artist: loaded.artist, image: loaded.image};
      }
    }
    if(playing){
      state.is_playing = true;
      state.active_device_id = state['device_' + deck] || state.active_device_id || '';
      const selectedDevice = (state.devices || []).find(d => String(d.id) === String(state.active_device_id));
      if(selectedDevice) state.active_device_name = selectedDevice.name || state.active_device_name || '';
      state.track = Object.assign({}, state.track || {}, {
        id: loaded.id || state.track?.id || '',
        title: loaded.title || state.track?.title || '',
        artist: loaded.artist || state.track?.artist || '',
        image: loaded.image || state.track?.image || '',
        duration_ms: Number(loaded.duration_ms || player.playback?.duration_ms || 0) || null
      });
    } else {
      const deviceId = state['device_' + deck] || '';
      if(deviceId && state.active_device_id === deviceId){
        const other = deck === 'a' ? 'b' : 'a';
        const otherHold = pendingTransportHolds[other];
        const otherPlaying = otherHold ? !!otherHold.playing : deckIsPlaying(other);
        if(!otherPlaying){
          state.is_playing = false;
          state.active_device_id = '';
          state.active_device_name = '';
        }
      }
    }
  }
  function holdDeckPlayback(deck, playing){
    pendingTransportHolds[deck] = {playing: !!playing, expiresAt: Date.now() + TRANSPORT_SETTLE_MS};
    forceDeckPlaybackFlag(deck, playing);
  }
  function applyPendingTransportHolds(){
    cleanupTransportHolds();
    Object.keys(pendingTransportHolds).forEach(deck => forceDeckPlaybackFlag(deck, pendingTransportHolds[deck].playing));
  }
  function cleanupSeekHolds(){
    const now = Date.now();
    Object.keys(pendingSeekHolds).forEach(deck => {
      if(!pendingSeekHolds[deck] || Number(pendingSeekHolds[deck].expiresAt || 0) <= now){
        delete pendingSeekHolds[deck];
      }
    });
  }
  function holdDeckSeek(deck, targetMs, playing){
    const now = Date.now();
    pendingSeekHolds[deck] = {
      targetMs: Math.max(0, Number(targetMs || 0) || 0),
      heldAt: now,
      playing: !!playing,
      expiresAt: now + SEEK_SETTLE_MS
    };
    applyDeckSeekHold(deck, pendingSeekHolds[deck], false);
  }
  function applyDeckSeekHold(deck, hold, allowServerSettle=true){
    if(!state || !hold) return;
    const key = 'player_' + deck;
    const player = state[key] || (state[key] = {});
    const loaded = player.loaded || null;
    if(!loaded) return;
    const durationMs = Number(loaded.duration_ms || player.playback?.duration_ms || 0) || 0;
    const elapsedMs = hold.playing ? Math.max(0, Date.now() - Number(hold.heldAt || Date.now())) : 0;
    let expectedMs = Math.max(0, Number(hold.targetMs || 0) + elapsedMs);
    if(durationMs) expectedMs = Math.min(expectedMs, durationMs);
    const serverMs = Number(player.playback?.progress_ms);
    const serverHasTrack = !!player.playback?.track?.id && String(player.playback.track.id) === String(loaded.id || '');
    if(allowServerSettle && serverHasTrack && Number.isFinite(serverMs) && Math.abs(serverMs - expectedMs) <= SEEK_SYNC_TOLERANCE_MS){
      debugTrace('seek_hold_synced', {deck, expected_ms:Math.round(expectedMs), server_ms:Math.round(serverMs)});
      delete pendingSeekHolds[deck];
      return;
    }
    updateLoadedPositionForDeck(deck, expectedMs);
    if(loaded){
      loaded.position_base_ms = expectedMs;
      loaded.paused_position_ms = hold.playing ? null : expectedMs;
      loaded.position_updated_at = Date.now() / 1000;
    }
    if(player.playback){
      player.playback.progress_ms = expectedMs;
      if(durationMs) player.playback.duration_ms = durationMs;
    }
    if(state.track && String(state.track.id || '') === String(loaded.id || '')){
      state.track.progress_ms = expectedMs;
      if(durationMs) state.track.duration_ms = durationMs;
    }
    state._receivedAtMs = Date.now();
  }
  function applyPendingSeekHolds(){
    cleanupSeekHolds();
    Object.keys(pendingSeekHolds).forEach(deck => applyDeckSeekHold(deck, pendingSeekHolds[deck], true));
  }
  function deckCanLoad(deck){
    return !!state?.['device_' + deck] && !deckIsPlaying(deck);
  }
  function clearSearchUi(){
    if(els.search){ els.search.value=''; els.search.focus(); }
    if(els.searchResults) els.searchResults.innerHTML='';
    if(els.searchStatus) els.searchStatus.textContent='';
  }
  function bindDebugControls(){
    if(els.debugToggle && !els.debugToggle.dataset.bound){
      els.debugToggle.dataset.bound = '1';
      els.debugToggle.addEventListener('click', async ()=>{
        const next = !debugEnabled;
        try{
          const data = await apiPost({action:'debug_set', enabled:next ? '1' : '0'});
          if(data.state) acceptState(data.state);
          else updateDebugUi(data.debug || {enabled:next});
          toast(data.message || (next ? 'Debug enabled' : 'Debug disabled'), !!data.ok);
          if(next) debugTrace('debug_enabled_from_browser', {});
          else flushDebug('debug_disabled');
        }catch(e){
          toast('Could not change debug mode', false);
          debugTrace('debug_toggle_error', {error:String(e && e.message || e)});
        }
      });
    }
    if(els.debugFlush && !els.debugFlush.dataset.bound){
      els.debugFlush.dataset.bound = '1';
      els.debugFlush.addEventListener('click', ()=>{
        debugTrace('manual_flush_clicked', {});
        flushDebug('manual');
        toast(debugEnabled ? 'Debug buffer flushed' : 'Debug is currently disabled');
      });
    }
    if(els.debugClear && !els.debugClear.dataset.bound){
      els.debugClear.dataset.bound = '1';
      els.debugClear.addEventListener('click', async ()=>{
        if(!confirm('Clear the mixer debug log?')) return;
        try{
          const data = await apiPost({action:'debug_clear'});
          debugBuffer = [];
          updateDebugUi(data.debug || data.state?.debug || null);
          toast(data.message || 'Debug log cleared', !!data.ok);
        }catch(e){
          toast('Could not clear debug log', false);
          debugTrace('debug_clear_error', {error:String(e && e.message || e)});
        }
      });
    }
  }

  function openMusicLibrary(){
    if(!els.musicLibraryModal) return;
    els.musicLibraryModal.classList.add('open');
    els.musicLibraryModal.setAttribute('aria-hidden','false');
    setTimeout(()=>{ if(els.search && activeSource === 'search') els.search.focus(); }, 80);
  }
  function closeMusicLibrary(){
    if(!els.musicLibraryModal) return;
    els.musicLibraryModal.classList.remove('open');
    els.musicLibraryModal.setAttribute('aria-hidden','true');
    clearLibrarySelection();
  }
  function closeChoice(){
    if(!els.choiceModal) return;
    els.choiceModal.classList.remove('open');
    els.choiceModal.setAttribute('aria-hidden','true');
    if(els.choiceActions) els.choiceActions.innerHTML = '';
  }
  function choiceButton(label, cls, action, disabled=false){
    return `<button class="mixer-btn ${cls}${disabled ? ' choice-disabled' : ''}" data-choice-action="${esc(action)}" ${disabled ? 'disabled' : ''}>${label}</button>`;
  }
  function crateSaveControls(){
    const crates = availableCrates.length ? availableCrates : (state?.crates || []);
    if(!crates.length){
      return choiceButton('+ Save to DJ crate', 'blue full', 'crate', true);
    }
    const selected = activeCrateId || String(crates[0].id || '');
    const opts = crates.map(c => `<option value="${esc(c.id)}" ${String(c.id) === String(selected) ? 'selected' : ''}>${esc(c.name)}</option>`).join('');
    return `<div class="choice-crate-save full"><label for="choiceCrateSelect">Save to crate</label><div class="choice-crate-row"><select id="choiceCrateSelect" class="mixer-select">${opts}</select><button class="mixer-btn blue" data-choice-action="crate">+ Save</button></div></div>`;
  }
  function libraryCrateSaveControls(){
    const crates = availableCrates.length ? availableCrates : (state?.crates || []);
    if(!crates.length){
      return '<button class="mixer-btn blue library-action-btn" data-library-action="crate" disabled>+ Save</button>';
    }
    const saved = selectedLibraryChoice?.saveCrateId || '';
    const fallback = activeCrateId || String(crates[0].id || '');
    const selected = crates.some(c => String(c.id) === String(saved)) ? String(saved) : fallback;
    const opts = crates.map(c => `<option value="${esc(c.id)}" ${String(c.id) === String(selected) ? 'selected' : ''}>${esc(c.name)}</option>`).join('');
    return `<label class="library-crate-save"><select id="libraryCrateSelect" class="mixer-select" aria-label="Choose DJ crate">${opts}</select><button class="mixer-btn blue library-action-btn" data-library-action="crate">+ Save</button></label>`;
  }
  function selectedLibraryList(){
    return Array.isArray(selectedLibraryChoices) ? selectedLibraryChoices.filter(choice => choice && choice.item) : [];
  }
  function clearLibrarySelection(){
    selectedLibraryChoice = null;
    selectedLibraryChoices = [];
    document.querySelectorAll('.library-selected').forEach(el => el.classList.remove('library-selected'));
    renderLibraryActionBar();
  }
  function updateLibrarySelectedElements(){
    document.querySelectorAll('[data-select-track], [data-select-crate-track], [data-select-history-track]').forEach(node => {
      let item = null;
      let source = '';
      try{
        if(node.dataset.selectCrateTrack){ item = JSON.parse(node.dataset.selectCrateTrack); source = 'crate'; }
        else if(node.dataset.selectHistoryTrack){ item = JSON.parse(node.dataset.selectHistoryTrack); source = 'history'; }
        else if(node.dataset.selectTrack){ item = JSON.parse(node.dataset.selectTrack); source = 'track'; }
      }catch(e){ item = null; }
      if(item && isLibrarySelected(item, source)) node.classList.add('library-selected');
      else node.classList.remove('library-selected');
    });
  }
  function renderLibraryActionBar(){
    if(!els.musicLibraryActionBar) return;
    const selected = selectedLibraryList();
    if(!selected.length){
      els.musicLibraryActionBar.innerHTML = '<div class="music-library-action-empty">Select one or more tracks to choose an action.</div>';
      return;
    }
    const isMulti = selected.length > 1;
    const item = selectedLibraryChoice?.item || selected[0].item;
    const src = selectedLibraryChoice?.source || selected[0].source;
    const title = item.title || item.song_title || 'Selected track';
    const artist = item.artist || '';
    const local = isLocalTrack(item);
    const aBlocked = !deckCanLoad('a');
    const bBlocked = !deckCanLoad('b');
    const localDirectPlayBlocked = false;
    const allCrate = selected.every(choice => choice.source === 'crate');
    const removableCrate = allCrate && selected.every(choice => choice.item && (choice.item.crate_track_id || choice.item.id) && (choice.item.crate_id || activeCrateId));
    const sourceNote = src === 'crate' ? 'DJ crate' : (src === 'history' ? 'History' : (local ? 'Local music' : 'Search result'));
    let actions = '';
    actions += `<button class="mixer-btn green library-action-btn" data-library-action="playlist">+ DJ Playlist${isMulti ? ` (${selected.length})` : ''}</button>`;
    if(isMulti){
      actions += `<button class="mixer-btn red library-action-btn" data-library-action="remove_crate" ${removableCrate ? '' : 'disabled'}>Remove${allCrate ? ` (${selected.length})` : ''}</button>`;
      actions += '<button class="mixer-btn dark library-action-btn" data-library-action="clear_selection">Clear</button>';
    } else {
      if(src === 'track' || src === 'history') actions += local ? '<button class="mixer-btn blue library-action-btn" data-library-action="crate" disabled>+ Save to crate</button>' : libraryCrateSaveControls();
      actions += `<button class="mixer-btn orange library-action-btn" data-library-action="load_a" ${aBlocked ? 'disabled' : ''}>Load A</button>`;
      actions += `<button class="mixer-btn green library-action-btn" data-library-action="play_a" ${aBlocked || localDirectPlayBlocked ? 'disabled' : ''}>▶ A</button>`;
      actions += `<button class="mixer-btn blue library-action-btn" data-library-action="load_b" ${bBlocked ? 'disabled' : ''}>Load B</button>`;
      actions += `<button class="mixer-btn green library-action-btn" data-library-action="play_b" ${bBlocked || localDirectPlayBlocked ? 'disabled' : ''}>▶ B</button>`;
      if(src === 'crate') actions += `<button class="mixer-btn red library-action-btn" data-library-action="remove_crate" ${!(item.crate_id || activeCrateId) || !(item.crate_track_id || item.id) ? 'disabled' : ''}>Remove</button>`;
    }
    const notes = [];
    if(isMulti){
      notes.push('Bulk mode: load/play disabled');
      if(!allCrate) notes.push('Remove only available for DJ crate tracks');
    } else {
      if(local) notes.push('Local track');
      if(aBlocked) notes.push('A unavailable/playing');
      if(bBlocked) notes.push('B unavailable/playing');
    }
    const selectedTitle = isMulti ? `${selected.length} tracks selected` : title;
    const selectedSubline = isMulti
      ? selected.map(choice => choice.item?.title || choice.item?.song_title || 'Track').slice(0, 3).join(' • ') + (selected.length > 3 ? ` • +${selected.length - 3} more` : '')
      : [artist, sourceNote].filter(Boolean).join(' • ');
    els.musicLibraryActionBar.innerHTML = `
      <div class="library-selected-track${isMulti ? ' library-selected-track-multi' : ''}">
        ${isMulti ? `<div class="library-selected-count">${selected.length}</div>` : `<img src="${esc(image(item.image || item.spotify_album_image || ''))}" alt="">`}
        <div class="library-selected-copy">
          <strong>${esc(selectedTitle)}</strong>
          <span>${esc(selectedSubline)}</span>
          ${notes.length ? `<em>${esc(notes.join(' • '))}</em>` : ''}
        </div>
      </div>
      <div class="library-action-buttons">${actions}</div>`;
  }
  function selectLibraryItem(item, source, el){
    if(!item) return;
    const key = libraryChoiceKey(item, source);
    const existingIndex = selectedLibraryChoices.findIndex(choice => libraryChoiceKey(choice.item, choice.source) === key);
    const saveCrateId = selectedLibraryChoice?.saveCrateId || activeCrateId || '';
    if(existingIndex >= 0){
      selectedLibraryChoices.splice(existingIndex, 1);
    } else {
      selectedLibraryChoices.push({item, source, saveCrateId});
    }
    selectedLibraryChoice = selectedLibraryChoices.length ? selectedLibraryChoices[selectedLibraryChoices.length - 1] : null;
    updateLibrarySelectedElements();
    renderLibraryActionBar();
  }
  function libraryActionParamsFor(choice, action){
    const src = choice.source;
    const item = choice.item;
    const params = {};
    if(src === 'request'){
      const requestParams = {request_id:item.id};
      if(item.request_group_id) requestParams.request_group_id = item.request_group_id;
      if(action === 'playlist') Object.assign(params, requestParams, {action:'accept_request'});
      if(action === 'load_a' || action === 'load_b') Object.assign(params, requestParams, {action:'load_request', deck:action.slice(-1)});
      if(action === 'play_a' || action === 'play_b') Object.assign(params, requestParams, {action:'play_request_direct', deck:action.slice(-1)});
    } else {
      const trackJson = JSON.stringify(item);
      if(action === 'playlist') Object.assign(params, {action:'add_track', track_json:trackJson});
      if(action === 'crate') {
        const select = document.getElementById('libraryCrateSelect');
        const crateId = select ? select.value : (selectedLibraryChoice?.saveCrateId || activeCrateId);
        if(selectedLibraryChoice) selectedLibraryChoice.saveCrateId = crateId;
        Object.assign(params, {action:'add_crate_track', crate_id:crateId, track_json:trackJson});
      }
      if(action === 'remove_crate') Object.assign(params, {action:'remove_crate_track', crate_id:item.crate_id || activeCrateId, track_id:item.crate_track_id || item.id});
      if(action === 'load_a' || action === 'load_b') Object.assign(params, {action:'load_track_direct', track_json:trackJson, deck:action.slice(-1)});
      if(action === 'play_a' || action === 'play_b') Object.assign(params, {action:'play_track_direct', track_json:trackJson, deck:action.slice(-1)});
    }
    return params;
  }
  async function libraryAction(action){
    if(action === 'clear_selection'){ clearLibrarySelection(); return; }
    const selected = selectedLibraryList();
    if(!selected.length) return;
    const isMulti = selected.length > 1;
    if(isMulti && !['playlist','remove_crate'].includes(action)) return;
    if(isMulti && action === 'remove_crate' && !selected.every(choice => choice.source === 'crate')) return;
    const choices = isMulti ? selected.slice() : [selectedLibraryChoice || selected[0]];
    let successCount = 0;
    let mutationAffectsCrates = false;
    let removedCrate = false;
    for(const choice of choices){
      if(!choice || !choice.item) continue;
      const params = libraryActionParamsFor(choice, action);
      if(!params.action) continue;
      try{
        await doAction(params);
        successCount += 1;
        if(action === 'crate' || action === 'remove_crate') mutationAffectsCrates = true;
        if(action === 'remove_crate' && choice.source === 'crate') removedCrate = true;
      }catch(err){
        console.warn('Library action failed', action, err);
      }
    }
    if(isMulti && successCount){
      toast(`${successCount} track${successCount === 1 ? '' : 's'} ${action === 'remove_crate' ? 'removed' : 'added to DJ playlist'}.`, true);
    }
    clearLibrarySelection();
    if(mutationAffectsCrates) {
      refreshCrateArtistIndexAfterMutation();
      refreshDjCratesAfterMutation();
    }
    if(removedCrate && !crateArtistMode) {
      setTimeout(()=>{
        loadDjCrateTracks(activeCrateId, activeCrateName);
      }, 350);
    }
  }
  function openChoice(item, source){
    if(!els.choiceModal || !item) return;
    closeMusicLibrary();
    const title = item.title || item.song_title || 'Selected track';
    const artist = item.artist || '';
    const local = isLocalTrack(item);
    if(els.choiceImage) els.choiceImage.src = image(item.image || item.spotify_album_image || '');
    if(els.choiceTitle) els.choiceTitle.textContent = title;
    if(els.choiceArtist){
      let suffix = local ? ' • local music' : '';
      if(source === 'request'){
        if(Number(item.request_count || 0) > 1) suffix = ' • ' + Number(item.request_count || 0) + ' requests';
        else if(item.guest_name) suffix = ' • requested by ' + item.guest_name;
      }
      els.choiceArtist.textContent = artist + suffix;
    }
    const aBlocked = !deckCanLoad('a');
    const bBlocked = !deckCanLoad('b');
    const localDirectPlayBlocked = false;
    let html = '';
    html += choiceButton('+ Add to DJ playlist', 'green full', 'playlist', false);
    // Save to crate is only for direct Spotify Search results.
    // Public Requests, DJ Crates and History are action-only selections.
    if(source === 'track') html += local ? choiceButton('+ Save to DJ crate', 'blue full', 'crate', true) : crateSaveControls();
    html += choiceButton('Load to A', 'orange', 'load_a', aBlocked);
    html += choiceButton('Load to B', 'blue', 'load_b', bBlocked);
    html += choiceButton('▶ Play on A now', 'green', 'play_a', aBlocked || localDirectPlayBlocked);
    html += choiceButton('▶ Play on B now', 'green', 'play_b', bBlocked || localDirectPlayBlocked);
    if(source === 'crate') html += choiceButton('Remove from this crate', 'red full', 'remove_crate', !activeCrateId || !item.id);
    if(els.choiceActions) els.choiceActions.innerHTML = html;
    if(els.choiceWarning){
      const notes=[];
      if(local) notes.push('Local track will play via MPD on the assigned Raspberry Pi');
      if(aBlocked) notes.push('A is unavailable or currently playing');
      if(bBlocked) notes.push('B is unavailable or currently playing');
      els.choiceWarning.textContent = notes.length ? notes.join(' • ') : 'Choose a safe action. Play now loads the track and starts it immediately.';
    }
    els.choiceModal._choice = {item, source};
    els.choiceModal.classList.add('open');
    els.choiceModal.setAttribute('aria-hidden','false');
  }
  async function choiceAction(action){
    const choice = els.choiceModal?._choice;
    if(!choice) return;
    const src = choice.source;
    const item = choice.item;
    const params = {};
    if(src === 'request'){
      const requestParams = {request_id:item.id};
      if(item.request_group_id) requestParams.request_group_id = item.request_group_id;
      if(action === 'playlist') Object.assign(params, requestParams, {action:'accept_request'});
      if(action === 'load_a' || action === 'load_b') Object.assign(params, requestParams, {action:'load_request', deck:action.slice(-1)});
      if(action === 'play_a' || action === 'play_b') Object.assign(params, requestParams, {action:'play_request_direct', deck:action.slice(-1)});
    } else {
      const trackJson = JSON.stringify(item);
      if(action === 'playlist') Object.assign(params, {action:'add_track', track_json:trackJson});
      if(action === 'crate') {
        const select = document.getElementById('choiceCrateSelect');
        const crateId = select ? select.value : activeCrateId;
        Object.assign(params, {action:'add_crate_track', crate_id:crateId, track_json:trackJson});
      }
      if(action === 'remove_crate') Object.assign(params, {action:'remove_crate_track', crate_id:activeCrateId, track_id:item.crate_track_id || item.id});
      if(action === 'load_a' || action === 'load_b') Object.assign(params, {action:'load_track_direct', track_json:trackJson, deck:action.slice(-1)});
      if(action === 'play_a' || action === 'play_b') Object.assign(params, {action:'play_track_direct', track_json:trackJson, deck:action.slice(-1)});
    }
    closeChoice();
    await doAction(params);
    if(action === 'crate' || action === 'remove_crate') {
      refreshCrateArtistIndexAfterMutation();
      refreshDjCratesAfterMutation();
    }
    if(src === 'track' && activeSource === 'search') clearSearchUi();
    if(src === 'crate' && action === 'remove_crate' && !crateArtistMode) setTimeout(()=>loadDjCrateTracks(activeCrateId, activeCrateName), 350);
  }

  function deckNode(deck){
    return deck === 'b' ? state?.deck_nodes?.b : state?.deck_nodes?.a;
  }
  function renderDeckNode(deck){
    const el = deck === 'b' ? els.deckBNode : els.deckANode;
    if(!el) return;

    const node = deckNode(deck);
    const selectedDevice = deck === 'b' ? state?.device_b : state?.device_a;
    const spotifyOnline = !!selectedDevice && deviceIsOnline(selectedDevice);

    if(!node){
      el.textContent = spotifyOnline ? 'Player status: Spotify device ready' : 'Player status: no Pi assigned';
      el.className = 'deck-node ' + (spotifyOnline ? 'online' : 'offline');
      return;
    }

    const status = node.live_status || 'offline';

    if(status === 'offline'){
      el.textContent = 'Player status: Pi offline';
      el.className = 'deck-node offline';
      return;
    }

    if(!node.raspotify_running){
      el.textContent = 'Player status: Pi online · Spotify stopped';
      el.className = 'deck-node warning';
      return;
    }

    if(spotifyOnline || node.matched_device?.name){
      el.textContent = 'Player status: online and ready';
      el.className = 'deck-node online';
      return;
    }

    el.textContent = 'Player status: Pi online · waiting for Spotify';
    el.className = 'deck-node warning';
  }

  function renderDevices(){
    const devices = state?.devices || [];
    const opts = ['<option value="">Choose device…</option>'].concat(devices.map(d => `<option value="${esc(d.id)}">${esc(d.name)}${d.is_active ? ' — active' : ''}</option>`)).join('');
    if(els.deviceA){ const v = els.deviceA.value || state.device_a || ''; els.deviceA.innerHTML = opts; els.deviceA.value = v; }
    if(els.deviceB){ const v = els.deviceB.value || state.device_b || ''; els.deviceB.innerHTML = opts; els.deviceB.value = v; }
    const missingA = !!state?.device_a && !deviceIsOnline(state.device_a);
    const missingB = !!state?.device_b && !deviceIsOnline(state.device_b);
    if(els.deckADevice) els.deckADevice.textContent = missingA ? 'Assigned Spotify device offline' : deviceName(state.device_a);
    if(els.deckBDevice) els.deckBDevice.textContent = missingB ? 'Assigned Spotify device offline' : deviceName(state.device_b);
renderAccountStatus();
    setDeviceAlert('a', missingA || accountHasWarning('a'));
    setDeviceAlert('b', missingB || accountHasWarning('b'));
  }
  function accountInfo(deck){ return deck === 'b' ? state?.accounts?.deck_b : state?.accounts?.deck_a; }
  function accountHasWarning(deck){ const a = accountInfo(deck); return !!(a && a.warning); }
  function setWarn(el, msg){ if(!el) return; el.textContent = msg || ''; el.classList.toggle('visible', !!msg); }
  function setAccount(el, info, deck){
    if(!el) return;
    el.textContent = info?.warning ? ('Spotify account warning: ' + info.warning) : '';
    el.classList.toggle('warning', !!(info && info.warning));
    el.style.display = info?.warning ? '' : 'none';
  }
  function renderAccountStatus(){
    setAccount(els.deckAAccount, state?.accounts?.deck_a, 'a');
    setAccount(els.deckBAccount, state?.accounts?.deck_b, 'b');
    setWarn(els.deckAWarning, state?.accounts?.deck_a?.warning || '');
    setWarn(els.deckBWarning, state?.accounts?.deck_b?.warning || '');
  }
  function setDeckState(el, playing, loaded){
    if(!el) return;
    el.textContent = playing ? 'Playing' : (loaded ? 'Loaded' : 'Standby');
    el.classList.toggle('playing', !!playing);
    el.classList.toggle('loaded', !playing && !!loaded);
  }
  function trackBlock(track, deck){
    if(!track || !track.id){
      return `<div class="muted">No track loaded. Load from the DJ playlist when ${deck.toUpperCase()} is safe.</div>`;
    }
    const prog = deckProgress(track, deck);
    maybeRequestFastEndCheck(deck, track, prog);
    const remainingLabel = prog.durationMs ? (prog.sameTrack ? `-${duration(prog.remainingMs)}` : duration(prog.durationMs)) : '';
    const elapsedLabel = prog.sameTrack ? duration(prog.progressMs) : '0:00';
    const st = progressStatus(track, deck);
    return `<div class="loaded-track"><img src="${esc(image(track.image))}" alt=""><div><div class="track-title">${esc(track.title)}</div><div class="track-artist">${esc(track.artist)}</div><div class="workflow-row">${workflowBadge(st.label, st.cls, st.detail)}${workflowBadge('From ' + sourceLabel(track), 'source')}</div></div></div>
      <div class="track-progress-meta"><span>${prog.sameTrack ? elapsedLabel : 'Ready'}</span><span>${remainingLabel}</span></div>
      <div class="now-bar ${prog.sameTrack ? 'active' : ''}"><span style="width:${prog.sameTrack ? prog.pct : 0}%"></span></div>
      ${prog.sameTrack ? `<div class="track-time-left">${duration(prog.remainingMs)} remaining</div>` : (prog.durationMs ? `<div class="track-time-left muted">Track length ${duration(prog.durationMs)}</div>` : '')}`;
  }
  function requestInitial(name){
    const s = String(name || 'G').trim();
    return (s ? s[0] : 'G').toUpperCase();
  }
  function requestTime(value){
    const s = String(value || '');
    return s.length >= 16 ? s.slice(11,16) : '';
  }
  function requestNotesFrom(item){
    const notes = Array.isArray(item?.request_notes)
      ? item.request_notes.map(n => ({
          guest_name: String(n?.guest_name || 'Guest').trim() || 'Guest',
          message: String(n?.message || '').trim(),
          created_at: String(n?.created_at || item?.created_at || item?.added_at || '')
        }))
      : [];
    if(notes.length) return notes;
    const guest = String(item?.guest_name || '').trim();
    const msg = String(item?.message || '').trim();
    if(guest || msg){
      return [{guest_name: guest || 'Guest', message: msg, created_at: String(item?.created_at || item?.added_at || '')}];
    }
    return [];
  }
  function renderRequestNotesList(item, cls='mixer-request-note-list', emptyText='No dedication/message'){
    const notes = requestNotesFrom(item);
    if(!notes.length) return `<div class="${esc(cls)} empty-notes mini muted">${esc(emptyText)}</div>`;
    return `<div class="${esc(cls)}">` + notes.map(note => {
      const time = requestTime(note.created_at);
      return `<div class="mixer-request-note">
        <div class="loaded-request-avatar small">${esc(requestInitial(note.guest_name))}</div>
        <div class="mixer-request-note-copy">
          <div class="mixer-request-note-name"><strong>${esc(note.guest_name || 'Guest')}</strong>${time ? ` <span>${esc(time)}</span>` : ''}</div>
          <div class="mixer-request-note-message${note.message ? '' : ' muted'}">${note.message ? esc(note.message) : esc(emptyText)}</div>
        </div>
      </div>`;
    }).join('') + `</div>`;
  }
  function renderDeckRequestNote(el, track){
    if(!el) return;
    const notes = requestNotesFrom(track);
    if(!(track?.source === 'request') || !notes.length){
      el.classList.remove('visible', 'has-request-list');
      el.innerHTML = '';
      return;
    }
    const count = Number(track?.request_count || notes.length || 1);
    const title = count > 1 ? `${count} dedications / requests` : 'Request dedication';
    el.innerHTML = `<div class="loaded-request-notes-head">${esc(title)}</div>${renderRequestNotesList(track, 'mixer-request-note-list deck-request-note-list')}`;
    el.classList.add('visible', 'has-request-list');
  }
  function renderDecks(){
    const aPlaying = deckIsPlaying('a');
    const bPlaying = deckIsPlaying('b');
    const aLoaded = deckHasLoaded('a');
    const bLoaded = deckHasLoaded('b');
    setDeckState(els.deckAState, aPlaying, aLoaded); setDeckState(els.deckBState, bPlaying, bLoaded);
    setDeckPlayingVisual('a', aPlaying); setDeckPlayingVisual('b', bPlaying);
    if(els.loadedA) els.loadedA.innerHTML = trackBlock(state?.player_a?.loaded, 'a');
    if(els.loadedB) els.loadedB.innerHTML = trackBlock(state?.player_b?.loaded, 'b');
    renderDeckRequestNote(els.deckANote, state?.player_a?.loaded);
    renderDeckRequestNote(els.deckBNote, state?.player_b?.loaded);
    ['a','b'].forEach(deck => {
      const playing = deck === 'a' ? aPlaying : bPlaying;
      const loaded = deck === 'a' ? aLoaded : bLoaded;
      const loadedLocal = deckLoadedIsLocal(deck);
      const preparingLocal = deckIsPreparingLocal(deck);
      const device = deck === 'a' ? state?.device_a : state?.device_b;
      const otherDeck = deck === 'a' ? 'b' : 'a';
      const otherDevice = otherDeck === 'a' ? state?.device_a : state?.device_b;
      document.querySelectorAll(`[data-deck-action="play_toggle"][data-deck="${deck}"]`).forEach(b => {
        b.disabled = !loaded || preparingLocal || (!loadedLocal && (!device || accountHasWarning(deck)));
        b.classList.toggle('transport-playing', !!playing);
        b.classList.toggle('transport-preparing', !!preparingLocal);
        b.classList.toggle('transport-ready', !!loaded && !playing && !preparingLocal);
        const loadedTrack = deckLoadedTrack(deck);
        b.title = loadedLocal
          ? (preparingLocal ? 'Preparing local track on Player ' + deck.toUpperCase() : (loadedTrack?.local_prepare_error ? 'Local prepare failed: ' + loadedTrack.local_prepare_error : (playing ? 'Pause local MPD on Player ' + deck.toUpperCase() : (loadedTrack?.local_is_prepared ? 'Play local MPD on Player ' + deck.toUpperCase() + ' - ready confirmed' : 'Play local MPD on Player ' + deck.toUpperCase()))))
          : (accountHasWarning(deck) ? (accountInfo(deck)?.warning || 'Spotify account warning') : (playing ? 'Pause Player ' + deck.toUpperCase() : 'Play / resume Player ' + deck.toUpperCase()));
      });
      ["seek_start","seek_back","seek_forward","seek_end"].forEach(act => document.querySelectorAll(`[data-deck-action="${act}"][data-deck="${deck}"]`).forEach(b => {
        b.disabled = !loaded || preparingLocal || (!loadedLocal && (!device || accountHasWarning(deck)));
        if(loadedLocal) b.title = 'Seek local MPD on Player ' + deck.toUpperCase();
      }));
      document.querySelectorAll(`[data-deck-action="clear_loaded"][data-deck="${deck}"]`).forEach(b => b.disabled = playing || !loaded);
      document.querySelectorAll(`[data-deck-action="return_loaded"][data-deck="${deck}"]`).forEach(b => {
        b.disabled = playing || !loaded;
        b.title = 'Return unplayed Player ' + deck.toUpperCase() + ' track to the appropriate queue';
      });
      document.querySelectorAll(`[data-deck-action="mark_loaded_played"][data-deck="${deck}"]`).forEach(b => {
        b.disabled = playing || !loaded;
        b.title = 'Manually mark Player ' + deck.toUpperCase() + ' as played and unload it';
      });
      document.querySelectorAll(`[data-deck-action="emergency_swap"][data-deck="${deck}"]`).forEach(b => {
        b.disabled = !loaded || loadedLocal || !device || !otherDevice;
        b.title = loadedLocal ? 'Local emergency swap is not enabled yet' : 'Emergency transfer Player ' + deck.toUpperCase() + ' to Player ' + otherDeck.toUpperCase();
      });
    });
    document.querySelectorAll('[data-load-a]').forEach(b => {
      // A loaded-but-standby deck is safe to replace. Only block if actively playing or no device is assigned.
      b.disabled = aPlaying || !state?.device_a;
      b.title = b.disabled ? (aPlaying ? 'Player A is currently playing' : 'Player A has no assigned device') : (aLoaded ? 'Replace loaded track on Player A' : 'Load to Player A');
    });
    document.querySelectorAll('[data-load-b]').forEach(b => {
      // A loaded-but-standby deck is safe to replace. Only block if actively playing or no device is assigned.
      b.disabled = bPlaying || !state?.device_b;
      b.title = b.disabled ? (bPlaying ? 'Player B is currently playing' : 'Player B has no assigned device') : (bLoaded ? 'Replace loaded track on Player B' : 'Load to Player B');
    });
    document.querySelectorAll('[data-action="auto_load"]').forEach(b => {
      const canA = !!state?.device_a && !aPlaying;
      const canB = !!state?.device_b && !bPlaying;
      b.disabled = !canA && !canB;
      b.title = b.disabled ? 'No standby player is available' : 'Auto-load to a standby player';
    });
    if (els.spotifyStatus) {
      if (state?.is_playing) {
        els.spotifyStatus.textContent = `Playing on ${state.active_device_name || 'active device'}${state.track?.title ? ' — ' + state.track.title : ''}`;
      } else {
        els.spotifyStatus.textContent = '';
      }
    }
  }
  function renderPlaylist(){
    const list = state?.playlist || [];
    if(els.playlistCount) els.playlistCount.textContent = list.length;
    if(!els.djPlaylist) return;
    if(!list.length){
      playlistPage = 0;
      els.djPlaylist.innerHTML = '<div class="empty">DJ playlist is empty.</div>';
      if(els.playlistPager){ els.playlistPager.hidden = true; els.playlistPager.innerHTML = ''; }
      return;
    }
    const pageCount = Math.max(1, Math.ceil(list.length / PLAYLIST_PAGE_SIZE));
    playlistPage = Math.max(0, Math.min(playlistPage, pageCount - 1));
    const startIdx = playlistPage * PLAYLIST_PAGE_SIZE;
    const visible = list.slice(startIdx, startIdx + PLAYLIST_PAGE_SIZE);
    els.djPlaylist.innerHTML = visible.map((t,localIdx)=>{
      const i = startIdx + localIdx;
      // Layout lock: DJ Playlist rows stay compact. Do not add workflow/source badges
      // or duplicate request summary lines here; requester/time already live in the
      // dedication/request notes card below the track details.
      const requestNotes = t.source === 'request' ? renderRequestNotesList(t, 'mixer-request-note-list playlist-request-note-list') : '';
      return `
      <div class="playlist-row${t.source === 'request' ? ' grouped-playlist-row' : ''}">
        <img src="${esc(image(t.image))}" alt="">
        <div>
          <strong>${esc(t.title)}</strong><br>
          <span class="mini muted">${esc(t.artist)}${duration(t.duration_ms) ? ' • ' + duration(t.duration_ms) : ''}${isLocalTrack(t) ? ' • local' : ''}${t.source === 'request' ? ' • public request' : ''}</span>
          ${requestNotes}
        </div>
        <div class="row-actions playlist-row-actions">
          <button class="mixer-btn dark mixer-mini-action playlist-order-btn" data-action="move_playlist" data-direction="up" data-idx="${i}" title="Move earlier in DJ playlist" aria-label="Move earlier in DJ playlist" ${i === 0 ? 'disabled aria-disabled="true"' : ''}>↑</button>
          <button class="mixer-btn dark mixer-mini-action playlist-order-btn" data-action="move_playlist" data-direction="down" data-idx="${i}" title="Move later in DJ playlist" aria-label="Move later in DJ playlist" ${i === list.length - 1 ? 'disabled aria-disabled="true"' : ''}>↓</button>
          <button class="mixer-btn green auto-btn mixer-mini-action" data-action="auto_load" data-idx="${i}" title="Auto-load to the first empty standby player" aria-label="Auto-load to the first empty standby player">⇄</button>
          <button class="mixer-btn orange mixer-mini-action" data-action="load" data-deck="a" data-load-a data-idx="${i}" title="Load to Player A" aria-label="Load to Player A">A</button>
          <button class="mixer-btn blue mixer-mini-action" data-action="load" data-deck="b" data-load-b data-idx="${i}" title="Load to Player B" aria-label="Load to Player B">B</button>
          <button class="mixer-btn red mixer-mini-action" data-action="remove_playlist" data-idx="${i}" title="Remove from DJ playlist" aria-label="Remove from DJ playlist">×</button>
        </div>
      </div>`;
    }).join('');
    if(els.playlistPager){
      if(pageCount <= 1){
        els.playlistPager.hidden = true;
        els.playlistPager.innerHTML = '';
      }else{
        els.playlistPager.hidden = false;
        els.playlistPager.innerHTML = `
          <button class="mixer-btn dark" type="button" data-playlist-page="prev" ${playlistPage === 0 ? 'disabled' : ''}>← Previous</button>
          <span class="playlist-page-label">Page ${playlistPage + 1} of ${pageCount}</span>
          <button class="mixer-btn dark" type="button" data-playlist-page="next" ${playlistPage >= pageCount - 1 ? 'disabled' : ''}>Next →</button>`;
      }
    }
  }
  function renderRequests(){
    const reqs = state?.requests || [];
    if(els.requestCount) els.requestCount.textContent = reqs.length;
    if(!els.publicRequests) return;
    if(!reqs.length){ els.publicRequests.innerHTML = '<div class="empty">No new Spotify-matched public requests waiting.</div>'; return; }
    els.publicRequests.innerHTML = reqs.map(r => {
      const count = Number(r.request_count || 1);
      // Layout lock: Public Requests rows mirror DJ Playlist rows. Do not show
      // duplicated requester/time lines or workflow/status badges; the dedication
      // card already contains requester, time and message.
      return `
      <div class="request-row grouped-request-row${count > 1 ? ' has-multiple-requests' : ''}">
        <img src="${esc(image(r.image))}" alt="">
        <div>
          <strong>${esc(r.title)}</strong> <span class="muted">— ${esc(r.artist)}</span>
          ${renderRequestNotesList(r, 'mixer-request-note-list public-request-note-list')}
        </div>
        <div class="row-actions quick-actions">
          <button class="mixer-btn green wide" data-select-request='${esc(JSON.stringify(r))}'>Choose action</button>
        </div>
      </div>`;
    }).join('');
  }
  function render(){ applyPendingTransportHolds(); applyPendingSeekHolds(); if(state?.crates) availableCrates = sortCratesByName(state.crates); renderDevices(); renderPlaylist(); renderRequests(); renderDecks(); if(activeSource === 'crates') renderDjCrates(availableCrates.length ? availableCrates : sortCratesByName(state?.crates || [])); if(activeSource === 'history') renderHistory(); updateLibrarySelectedElements(); renderLibraryActionBar(); }
  function acceptState(nextState){
    if(!nextState) return;
    state = nextState;
    updateDebugUi(state.debug || nextState.debug || null);
    debugTrace('state_accepted', {server_time:state.server_time, duo_mode:!!state.duo_mode, deck_a:{state:state.player_a?.state, loaded:trackSummary(state.player_a?.loaded), playback:state.player_a?.playback}, deck_b:{state:state.player_b?.state, loaded:trackSummary(state.player_b?.loaded), playback:state.player_b?.playback}});
    if(state.music_library_view && ['comfortable','compact','list'].includes(state.music_library_view) && state.music_library_view !== libraryViewMode){
      libraryViewMode = state.music_library_view;
      try{ localStorage.setItem('dttd_music_library_view', libraryViewMode); }catch(e){}
      updateLibraryView();
      renderSearchResults(lastSearchTracks);
    }
    state._receivedAtMs = Date.now();
    lastStateSyncAt = Date.now();
    render();
    if((deckIsPreparingLocal('a') || deckIsPreparingLocal('b')) && !busy){
      clearTimeout(prepareTimer);
      prepareTimer = setTimeout(()=>refresh(true), 2500);
    }
  }
  async function refresh(silent=true, options={}){
    if(busy && silent){ debugTrace('state_poll_skipped_busy', {silent}); return; }
    if(stateRefreshInFlight){ debugTrace('state_poll_skipped_inflight', {silent}); return; }
    const now = Date.now();
    if(silent && (now - lastStateRefreshStartedAt) < 1500){
      debugTrace('state_poll_skipped_recent', {silent, age_ms: now - lastStateRefreshStartedAt});
      return;
    }
    stateRefreshInFlight = true;
    lastStateRefreshStartedAt = now;
    debugTrace('state_poll_start', {silent, busy, force_devices:!!options.forceDevices});
    const requestActionSeq = actionSequence;
    try{
      const params = {action:'state'};
      if(options.forceDevices) params.force_devices = 1;
      if(options.forcePlayback) params.force_playback = 1;
      if(options.forceReason) params.force_reason = options.forceReason;
      const data = await apiGet(params);
      if(requestActionSeq !== actionSequence) return;
      if(data.ok){ acceptState(data.state); }
      else { if(data.state){acceptState(data.state);} if(!silent) toast(data.error || 'Update failed', false); }
    }
    catch(e){ if(!silent) toast('Mixer update failed', false); }
    finally{ stateRefreshInFlight = false; }
  }
  async function doAction(params){
    params = Object.assign({_client_tab_id: MIXER_TAB_ID}, params || {});
    if(busy){ debugTrace('action_ignored_busy', {params}); return; }
    debugTrace('action_begin', {params, deck_state_a:state?.player_a?.state, deck_state_b:state?.player_b?.state});
    const thisActionSeq = ++actionSequence;
    const optimisticallyUpdated = optimisticDeckAction(params);
    busy = true;
    try{
      const data = await apiPost(params);
      if(thisActionSeq !== actionSequence) return;
      if(data.state){ acceptState(data.state); }
      else if(optimisticallyUpdated && !data.ok){ refresh(true); }
      toast(data.ok ? (data.message || 'Done') : (data.error || data.message || 'Action failed'), !!data.ok);
      debugTrace('action_complete', {params, ok:data.ok, message:data.message||'', error:data.error||''});
      setTimeout(()=>flushDebug('action_complete'), 0);
    }catch(e){ if(thisActionSeq === actionSequence){ debugTrace('action_exception', {params, error:String(e && e.message || e)}); if(optimisticallyUpdated) refresh(true); toast('Action failed', false); setTimeout(()=>flushDebug('action_error'), 0); } }
    finally{ if(thisActionSeq === actionSequence) busy = false; }
  }
  function compactBadgeLabel(label, type){
    label = String(label || '').trim();
    type = String(type || '').toLowerCase();
    if(type === 'original') return 'Orig';
    if(type === 'compilation') return 'Comp';
    if(type === 'soundtrack') return 'Sound';
    if(type === 'original-era') return 'Era';
    if(type === 'remaster') return 'Rem';
    if(type === 'instrumental') return 'Inst';
    if(type === 'acoustic') return 'Ac';
    if(type === 'karaoke') return 'Kar';
    if(type === 'review') return 'Review';
    if(type === 'matched') return 'Match';
    return label;
  }
  function searchBadgeHtml(track){
    const badges = (Array.isArray(track?.badges) ? track.badges : []).filter(b => {
      const type = String(b?.type || '').toLowerCase();
      return type !== 'spotify' && type !== 'local';
    });
    if(!badges.length) return '';
    return `<span class="search-result-badges">${badges.slice(0,4).map(b=>{
      const type = String(b.type || '');
      const label = String(b.label || '');
      return `<span class="search-result-badge ${esc(type)}" title="${esc(label)}">${esc(compactBadgeLabel(label, type))}</span>`;
    }).join('')}</span>`;
  }
  function trackSourceBadge(track){
    const src = String(track?.source || '').toLowerCase();
    if(src === 'local') return '<span class="source-pill local" title="Local music">▣</span>';
    return '<span class="source-pill spotify" title="Spotify">♬</span>';
  }
  function updateLoadedPositionForDeck(deck, progressMs){
    const key = 'player_' + deck;
    const player = state?.[key] || null;
    const loaded = player?.loaded || null;
    if(!loaded) return;
    const durationMs = Number(loaded.duration_ms || player?.playback?.duration_ms || 0) || 0;
    const safeProgress = Math.max(0, Math.min(Number(progressMs || 0) || 0, durationMs || Number(progressMs || 0) || 0));
    loaded.position_base_ms = safeProgress;
    loaded.paused_position_ms = safeProgress;
    loaded.position_updated_at = Date.now() / 1000;
    if(player.playback){
      player.playback.progress_ms = safeProgress;
      if(durationMs) player.playback.duration_ms = durationMs;
    }
  }
  function setOptimisticDeckPlayback(deck, playing){
    if(!state) return;
    const key = 'player_' + deck;
    const player = state[key] || (state[key] = {});
    const loaded = player.loaded || null;
    if(!loaded) return;
    const prog = deckProgress(loaded, deck);
    updateLoadedPositionForDeck(deck, prog.progressMs);
    player.state = playing ? 'playing' : 'standby';
    if(isLocalTrack(loaded)){
      loaded.local_is_playing = !!playing;
      loaded.position_updated_at = Date.now() / 1000;
    }
    if(player.playback){
      player.playback.is_playing = !!playing;
      player.playback.progress_ms = Number(loaded.position_base_ms || loaded.paused_position_ms || prog.progressMs || 0) || 0;
      if(playing){
        player.playback.device_id = state['device_' + deck] || player.playback.device_id || '';
        player.playback.track = player.playback.track || {id: loaded.id, title: loaded.title, artist: loaded.artist, image: loaded.image};
      }
    }
    if(playing){
      state.is_playing = true;
      state.active_device_id = state['device_' + deck] || state.active_device_id || '';
      const selectedDevice = (state.devices || []).find(d => String(d.id) === String(state.active_device_id));
      if(selectedDevice) state.active_device_name = selectedDevice.name || state.active_device_name || '';
      state.track = Object.assign({}, state.track || {}, {
        id: loaded.id || state.track?.id || '',
        title: loaded.title || state.track?.title || '',
        artist: loaded.artist || state.track?.artist || '',
        image: loaded.image || state.track?.image || '',
        progress_ms: Number(loaded.position_base_ms || loaded.paused_position_ms || prog.progressMs || 0) || 0,
        duration_ms: Number(loaded.duration_ms || player.playback?.duration_ms || 0) || null
      });
    } else {
      const other = deck === 'a' ? 'b' : 'a';
      const otherPlaying = deckIsPlaying(other);
      if(!otherPlaying){
        state.is_playing = false;
        state.active_device_id = '';
        state.active_device_name = '';
      }
    }
    state._receivedAtMs = Date.now();
    lastStateSyncAt = Date.now();
  }
  function optimisticDeckAction(params){
    if(!state || !params || !params.deck) return false;
    debugTrace('optimistic_action_check', {params});
    const action = String(params.action || '');
    const deck = params.deck === 'b' ? 'b' : 'a';
    const other = deck === 'a' ? 'b' : 'a';
    if(action === 'play_toggle'){
      const loaded = deckLoadedTrack(deck);
      if(!loaded || deckIsPreparingLocal(deck)) return false;
      const wasPlaying = deckIsPlaying(deck);
      if(wasPlaying){
        setOptimisticDeckPlayback(deck, false);
        holdDeckPlayback(deck, false);
        debugTrace('optimistic_pause', {deck, track:trackSummary(loaded)});
      } else {
        if(!state.duo_mode && deckIsPlaying(other)){
          setOptimisticDeckPlayback(other, false);
          holdDeckPlayback(other, false);
        }
        setOptimisticDeckPlayback(deck, true);
        holdDeckPlayback(deck, true);
        debugTrace('optimistic_play', {deck, track:trackSummary(loaded)});
      }
      renderDecks();
      return true;
    }
    if(action === 'seek_start' || action === 'seek_end' || action === 'seek_relative'){
      const loaded = deckLoadedTrack(deck);
      if(!loaded) return false;
      const prog = deckProgress(loaded, deck);
      let nextMs = prog.progressMs;
      if(action === 'seek_start') nextMs = 0;
      else if(action === 'seek_end') nextMs = Math.max(0, Number(prog.durationMs || loaded.duration_ms || 0) - 2500);
      else nextMs = Math.max(0, nextMs + (Number(params.delta_ms || 0) || 0));
      holdDeckSeek(deck, nextMs, deckIsPlaying(deck));
      debugTrace('optimistic_seek', {deck, action, target_ms:Math.round(nextMs), playing:deckIsPlaying(deck)});
      state._receivedAtMs = Date.now();
      lastStateSyncAt = Date.now();
      renderDecks();
      return true;
    }
    return false;
  }
  function sortCratesByName(crates){
    return (crates || []).slice().sort((a,b)=>String(a?.name || '').localeCompare(String(b?.name || ''), undefined, {sensitivity:'base', numeric:true}));
  }
  function libraryChoiceKey(item, source){
    return String(source || '') + ':' + String(item?.crate_track_id || item?.id || item?.spotify_id || item?.title || '');
  }
  function isLibrarySelected(item, source){
    const key = libraryChoiceKey(item, source);
    return selectedLibraryList().some(choice => libraryChoiceKey(choice.item, choice.source) === key);
  }
  function searchResultRows(tracks){
    return tracks.map(t=>`
      <div role="button" tabindex="0" class="result-row search-result-row tappable-row${isLibrarySelected(t, 'track') ? ' library-selected' : ''}" data-select-track='${esc(JSON.stringify(t))}' aria-label="Choose ${esc(t.title || 'track')}">
        <img src="${esc(image(t.image))}" alt="">
        <span class="result-main">
          <span class="result-title${searchTrackIsInCrate(t) ? ' in-crate-title' : ''}">${esc(t.title)}</span>
          <span class="mini muted result-subline">${esc(resultMetaLine(t))}${artistSearchButton(t)}</span>
        </span>
        <span class="result-corner-badges">${searchBadgeHtml(t)}${trackSourceBadge(t)}</span>
      </div>`).join('');
  }
  function renderSearchPager(total){
    if(!els.searchPager) return;
    total = Number(total || 0) || 0;
    const pageSize = searchPageSize();
    const pages = Math.max(1, Math.ceil(total / pageSize));
    const from = total ? (searchPage * pageSize + 1) : 0;
    const to = total ? Math.min(total, from + pageSize - 1) : 0;
    els.searchPager.hidden = false;
    els.searchPager.innerHTML = `
      <button type="button" class="mixer-btn dark search-page-btn" data-search-page="prev" ${searchPage <= 0 ? 'disabled' : ''}>‹ Previous</button>
      <span class="search-page-count">${total ? `Showing ${from}–${to} of ${total}` : 'Showing 0 of 0'}</span>
      <button type="button" class="mixer-btn dark search-page-btn" data-search-page="next" ${searchPage >= pages - 1 ? 'disabled' : ''}>Next ›</button>`;
  }
  function renderSearchResults(tracks){
    if(!els.searchResults) return;
    lastSearchTracks = Array.isArray(tracks) ? tracks.slice() : [];
    const total = lastSearchTracks.length;
    if(!total){
      els.searchResults.innerHTML = '<div class="mini muted">No matches yet.</div>';
      renderSearchPager(0);
      return;
    }
    const pages = Math.max(1, Math.ceil(total / searchPageSize()));
    searchPage = Math.max(0, Math.min(searchPage, pages - 1));
    const start = searchPage * searchPageSize();
    els.searchResults.innerHTML = searchResultRows(lastSearchTracks.slice(start, start + searchPageSize()));
    renderSearchPager(total);
  }
  function setSourceTab(name){
    const nextSource = name || 'search';
    if(nextSource !== activeSource) clearLibrarySelection();
    activeSource = nextSource;
    els.sourceTabs.forEach(t => t.classList.toggle('active', t.dataset.sourceTab === activeSource));
    els.sourcePanels.forEach(p => p.classList.toggle('active', p.dataset.sourcePanel === activeSource));
    if(activeSource === 'crates' && !cratesLoaded) loadDjCrates();
    if(activeSource === 'history') renderHistory();
  }
  function renderHistory(){
    if(!els.historyList) return;
    const list = state?.history || [];
    if(!list.length){ els.historyList.innerHTML = '<div class="empty">No played tracks in history yet.</div>'; return; }
    els.historyList.innerHTML = list.map(t => `
      <div class="history-row">
        <img src="${esc(image(t.image))}" alt="">
        <div>
          <strong>${esc(t.title)}</strong><br>
          <span class="mini muted">${esc(t.artist)}${duration(t.duration_ms) ? ' • ' + duration(t.duration_ms) : ''}</span>
          <div class="history-meta">${t.played_at ? esc(String(t.played_at).slice(11,16)) : ''}${t.history_deck ? ' • played on ' + esc(t.history_deck) : ''}</div>
        </div>
        <div class="row-actions">
          <button class="mixer-btn green" data-select-history-track='${esc(JSON.stringify(t))}'>Choose</button>
        </div>
      </div>`).join('');
  }
  function setCrateDrawer(open){
    crateDrawerOpen = !!open;
    if(els.crateTileDrawer) els.crateTileDrawer.hidden = true;
    if(els.crateDrawerToggle) els.crateDrawerToggle.setAttribute('aria-expanded', crateDrawerOpen ? 'true' : 'false');
    if(crateDrawerOpen){
      crateArtistMode = false;
      if(els.annotateCrates){
        els.annotateCrates.classList.remove('active');
        els.annotateCrates.setAttribute('aria-pressed', 'false');
        els.annotateCrates.textContent = 'Annotate';
      }
      renderCratePicker();
    } else {
      renderDjCrateTracks(activeCrateTracks);
    }
  }
  function setCrateArtistMode(on){
    crateArtistMode = !!on;
    crateDrawerOpen = false;
    if(els.annotateCrates){
      els.annotateCrates.classList.toggle('active', crateArtistMode);
      els.annotateCrates.setAttribute('aria-pressed', crateArtistMode ? 'true' : 'false');
      els.annotateCrates.textContent = crateArtistMode ? 'Crate View' : 'Annotate';
    }
    if(crateArtistMode){
      if(els.crateTileDrawer) els.crateTileDrawer.hidden = true;
      if(els.crateDrawerToggle) els.crateDrawerToggle.setAttribute('aria-expanded', 'false');
      if(els.cratePager) els.cratePager.hidden = true;
      crateArtistName = '';
      crateArtistTrackPage = 0;
      loadCrateArtistIndex(true);
    } else {
      renderDjCrateTracks(activeCrateTracks);
    }
  }
  function updateCrateSummary(){
    const crate = availableCrates.find(c => String(c.id) === String(activeCrateId));
    const name = activeCrateName || String(crate?.name || 'Choose a crate');
    const count = crate ? Number(crate.track_count || 0) : 0;
    if(els.crateSummaryName) els.crateSummaryName.textContent = name;
    if(els.crateSummaryCount) els.crateSummaryCount.textContent = crate ? (count + ' saved track' + (count === 1 ? '' : 's')) : 'Tap a tile below';
    if(els.crateDrawerToggle) els.crateDrawerToggle.classList.toggle('has-selection', !!crate);
  }
  function renderDjCrates(crates){
    crates = sortCratesByName(crates || []);
    availableCrates = crates;
    if(!els.djCrateTiles) return;
    if(!crates.length){
      activeCrateId = '';
      activeCrateName = '';
      els.djCrateTiles.innerHTML = '<div class="empty crate-empty">No DJ crates yet. Press + New Crate to create one.</div>';
      updateCrateSummary();
      setCrateDrawer(true);
      renderDjCrateTracks([]);
      return;
    }
    if(!activeCrateId || !crates.some(c => String(c.id) === String(activeCrateId))){
      activeCrateId = String(crates[0].id || '');
      activeCrateName = String(crates[0].name || 'DJ crate');
    }
    els.djCrateTiles.innerHTML = crates.map(c => `
      <button type="button" class="crate-tile${String(c.id) === String(activeCrateId) ? ' active-crate' : ''}" data-open-dj-crate="${esc(c.id)}" data-crate-name="${esc(c.name)}" aria-label="Open DJ crate ${esc(c.name)}">
        <span class="crate-tile-icon">♫</span>
        <span class="crate-tile-copy">
          <strong>${esc(c.name)}</strong>
          <small>${Number(c.track_count || 0)} track${Number(c.track_count || 0) === 1 ? '' : 's'}</small>
        </span>
      </button>`).join('');
    updateCrateSummary();
    if(crateDrawerOpen) renderCratePicker();
    else if(els.crateTileDrawer) els.crateTileDrawer.hidden = true;
  }
  function renderCratePager(total){
    if(!els.cratePager) return;
    if(crateArtistMode || crateDrawerOpen){
      els.cratePager.hidden = true;
      els.cratePager.innerHTML = '';
      return;
    }
    total = Number(total || 0) || 0;
    const pageSize = crateTrackPageSize();
    const pages = Math.max(1, Math.ceil(total / pageSize));
    cratePage = Math.max(0, Math.min(cratePage, pages - 1));
    const from = total ? (cratePage * pageSize + 1) : 0;
    const to = total ? Math.min(total, from + pageSize - 1) : 0;
    els.cratePager.hidden = false;
    els.cratePager.innerHTML = `
      <button type="button" class="mixer-btn dark search-page-btn" data-crate-page="prev" ${cratePage <= 0 ? 'disabled' : ''}>‹ Previous</button>
      <span class="search-page-count">${total ? `Showing ${from}–${to} of ${total}` : 'Showing 0 of 0'}</span>
      <button type="button" class="mixer-btn dark search-page-btn" data-crate-page="next" ${cratePage >= pages - 1 ? 'disabled' : ''}>Next ›</button>`;
  }
  function renderInlinePager(total, page, pageSize, attr){
    total = Number(total || 0) || 0;
    const pages = Math.max(1, Math.ceil(total / pageSize));
    const current = Math.max(0, Math.min(page, pages - 1));
    const from = total ? (current * pageSize + 1) : 0;
    const to = total ? Math.min(total, from + pageSize - 1) : 0;
    return `<div class="search-pager crate-picker-pager">
      <button type="button" class="mixer-btn dark search-page-btn" data-${attr}="prev" ${current <= 0 ? 'disabled' : ''}>‹ Previous</button>
      <span class="search-page-count">${total ? `Showing ${from}–${to} of ${total}` : 'Showing 0 of 0'}</span>
      <button type="button" class="mixer-btn dark search-page-btn" data-${attr}="next" ${current >= pages - 1 ? 'disabled' : ''}>Next ›</button>
    </div>`;
  }
  function renderCratePicker(){
    if(!els.djCrateTracks) return;
    if(els.cratePager){
      els.cratePager.hidden = true;
      els.cratePager.innerHTML = '';
    }
    const crates = sortCratesByName(availableCrates.length ? availableCrates : (state?.crates || []));
    if(!crates.length){
      els.djCrateTracks.innerHTML = '<div class="empty">No DJ crates yet. Press + New Crate to create one.</div>';
      return;
    }
    const pageSize = libraryViewMode === 'list' ? 30 : (libraryViewMode === 'compact' ? 24 : 18);
    const pages = Math.max(1, Math.ceil(crates.length / pageSize));
    cratePickerPage = Math.max(0, Math.min(cratePickerPage, pages - 1));
    const rows = crates.slice(cratePickerPage * pageSize, cratePickerPage * pageSize + pageSize);
    els.djCrateTracks.innerHTML = `
      <div class="crate-picker-full">
        <div class="crate-track-heading crate-picker-heading"><div><div class="tiny-label">Choose crate</div><div class="mini muted">Pick a crate to show its tracks.</div></div><div class="mini muted">${crates.length} crates</div></div>
        <div class="crate-picker-grid">${rows.map(c => `
          <button type="button" class="crate-tile crate-picker-tile${String(c.id) === String(activeCrateId) ? ' active-crate' : ''}" data-open-dj-crate="${esc(c.id)}" data-crate-name="${esc(c.name)}" aria-label="Open DJ crate ${esc(c.name)}">
            <span class="crate-tile-icon">♫</span>
            <span class="crate-tile-copy">
              <strong>${esc(c.name)}</strong>
              <small>${Number(c.track_count || 0)} track${Number(c.track_count || 0) === 1 ? '' : 's'}</small>
            </span>
          </button>`).join('')}</div>
        ${renderInlinePager(crates.length, cratePickerPage, pageSize, 'crate-picker-page')}
      </div>`;
  }
  function crateArtistValue(track){
    return String(track?.artist || 'Unknown Artist').trim() || 'Unknown Artist';
  }
  function crateArtistSortName(name){
    return String(name || '').replace(/^(the|a|an)\s+/i, '').trim() || String(name || '');
  }
  function crateArtistLetterFor(name){
    const clean = crateArtistSortName(name).toUpperCase();
    const first = clean.replace(/^[^A-Z0-9]+/, '').charAt(0);
    if(!first) return '#';
    return /^[A-Z]$/.test(first) ? first : '#';
  }
  function crateArtistGroups(){
    const artists = {};
    (Array.isArray(crateArtistTracks) ? crateArtistTracks : []).forEach(track => {
      const artist = crateArtistValue(track);
      const key = artist.toLowerCase();
      if(!artists[key]){
        artists[key] = {
          name: artist,
          letter: crateArtistLetterFor(artist),
          sort: crateArtistSortName(artist).toLowerCase(),
          tracks: []
        };
      }
      artists[key].tracks.push(track);
    });
    return Object.values(artists).sort((a,b) => a.sort.localeCompare(b.sort, undefined, {sensitivity:'base', numeric:true}));
  }
  function renderCrateArtistIndex(){
    if(!els.djCrateTracks) return;
    const groups = crateArtistGroups();
    if(!groups.length){
      els.djCrateTracks.innerHTML = '<div class="empty">No crate tracks found to annotate yet.</div>';
      if(els.cratePager){
        els.cratePager.hidden = true;
        els.cratePager.innerHTML = '';
      }
      return;
    }
    const letters = ['#'].concat('ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split(''));
    const activeLetters = {};
    groups.forEach(g => { activeLetters[g.letter] = true; });
    if(!crateArtistLetter || !activeLetters[crateArtistLetter]) crateArtistLetter = groups[0].letter;
    const artistsForLetter = groups.filter(g => g.letter === crateArtistLetter);
    const selectedArtist = crateArtistName ? (artistsForLetter.find(g => g.name === crateArtistName) || null) : null;
    const tracks = selectedArtist ? selectedArtist.tracks.slice().sort((a,b) => String(a.title || '').localeCompare(String(b.title || ''), undefined, {sensitivity:'base', numeric:true})) : [];
    const trackPageSize = crateArtistTrackPageSize();
    const trackPages = Math.max(1, Math.ceil(tracks.length / trackPageSize));
    crateArtistTrackPage = Math.max(0, Math.min(crateArtistTrackPage, trackPages - 1));
    const trackRows = tracks.slice(crateArtistTrackPage * trackPageSize, crateArtistTrackPage * trackPageSize + trackPageSize);
    const artistPageSize = libraryViewMode === 'list' ? 36 : (libraryViewMode === 'compact' ? 30 : 24);
    const artistPages = Math.max(1, Math.ceil(artistsForLetter.length / artistPageSize));
    crateArtistPage = Math.max(0, Math.min(crateArtistPage, artistPages - 1));
    const artistRows = artistsForLetter.slice(crateArtistPage * artistPageSize, crateArtistPage * artistPageSize + artistPageSize);

    els.djCrateTracks.innerHTML = `
      <div class="crate-artist-index">
        <div class="crate-track-heading crate-artist-heading"><div><div class="tiny-label">Artist index</div><div class="mini muted">${selectedArtist ? 'Tap a track for actions, or pick another letter/artist.' : 'Pick a letter, then choose an artist to show tracks.'}</div></div><div class="mini muted">${crateArtistTracks.length} tracks</div></div>
        <div class="crate-alpha-row" aria-label="Artist letters">${letters.map(letter => `<button type="button" class="crate-alpha-btn${letter === crateArtistLetter ? ' active' : ''}" data-crate-artist-letter="${esc(letter)}" ${activeLetters[letter] ? '' : 'disabled'}>${esc(letter)}</button>`).join('')}</div>
        ${selectedArtist ? `
          <div class="crate-artist-tracks">
            <div class="crate-track-heading"><div class="tiny-label">${esc(selectedArtist.name)}</div></div>
            <div class="crate-track-grid">${trackRows.map(t => `
              <button type="button" class="result-row crate-track-row tappable-row${isLibrarySelected(t, 'crate') ? ' library-selected' : ''}" data-select-crate-track='${esc(JSON.stringify(t))}' aria-label="Choose ${esc(t.title || 'track')}">
                <img src="${esc(image(t.image))}" alt="">
                <span class="result-main"><span class="result-title">${esc(t.title)}</span><span class="mini muted result-subline">${esc([t.artist, t.crate_name, duration(t.duration_ms)].filter(Boolean).join(' • '))}</span></span>
                <span class="result-corner-badges">${searchBadgeHtml(t)}${trackSourceBadge(t)}</span>
              </button>`).join('')}</div>
            ${renderInlinePager(tracks.length, crateArtistTrackPage, trackPageSize, 'crate-artist-track-page')}
          </div>
        ` : `
          <div class="crate-artist-list crate-artist-list-full" aria-label="Artists">${artistRows.map(g => `<button type="button" class="crate-artist-btn" data-crate-artist-name="${esc(g.name)}"><strong>${esc(g.name)}</strong><small>${g.tracks.length} track${g.tracks.length === 1 ? '' : 's'}</small></button>`).join('')}</div>
          ${renderInlinePager(artistsForLetter.length, crateArtistPage, artistPageSize, 'crate-artist-page')}
        `}
      </div>`;
    if(els.cratePager){
      els.cratePager.hidden = true;
      els.cratePager.innerHTML = '';
    }
  }
  function renderDjCrateTracks(tracks){
    if(!els.djCrateTracks) return;
    activeCrateTracks = Array.isArray(tracks) ? tracks.slice() : [];
    if(crateArtistMode){
      renderCrateArtistIndex();
      return;
    }
    if(crateDrawerOpen){
      renderCratePicker();
      return;
    }
    const total = activeCrateTracks.length;
    if(!activeCrateId){
      els.djCrateTracks.innerHTML = '<div class="empty">Choose or create a DJ crate.</div>';
      renderCratePager(0);
      return;
    }
    if(!total){
      els.djCrateTracks.innerHTML = '<div class="empty">This crate is empty. Search Spotify, tap a track, then press Save.</div>';
      renderCratePager(0);
      return;
    }
    const pageSize = crateTrackPageSize();
    const pages = Math.max(1, Math.ceil(total / pageSize));
    cratePage = Math.max(0, Math.min(cratePage, pages - 1));
    const start = cratePage * pageSize;
    const rows = activeCrateTracks.slice(start, start + pageSize);
    els.djCrateTracks.innerHTML = `
      <div class="crate-track-heading"><div class="tiny-label">${esc(activeCrateName || 'DJ crate')} tracks</div><div class="mini muted">Tap a track to choose an action. New search/history selections will save into this crate.</div></div>
      <div class="crate-track-grid">${rows.map(t => `
        <button type="button" class="result-row crate-track-row tappable-row${isLibrarySelected(t, 'crate') ? ' library-selected' : ''}" data-select-crate-track='${esc(JSON.stringify(t))}' aria-label="Choose ${esc(t.title || 'track')}">
          <img src="${esc(image(t.image))}" alt="">
          <span class="result-main"><span class="result-title">${esc(t.title)}</span><span class="mini muted result-subline">${esc(resultMetaLine(t))}</span></span>
          <span class="result-corner-badges">${searchBadgeHtml(t)}${trackSourceBadge(t)}</span>
        </button>`).join('')}</div>`;
    renderCratePager(total);
  }
  async function loadDjCrates(force=false){
    if(!force && cratesLoaded){
      renderDjCrates(availableCrates);
      if(activeCrateId && !activeCrateTracks.length) loadDjCrateTracks(activeCrateId, activeCrateName);
      return;
    }
    if(els.djCrateStatus) els.djCrateStatus.innerHTML = '<span class="spinner"></span> Loading DJ crates…';
    try{
      const data = await apiGet({action:'crates'});
      if(data.ok){
        cratesLoaded = true;
        availableCrates = sortCratesByName(data.crates || []);
        renderDjCrates(availableCrates);
        if(els.djCrateStatus) els.djCrateStatus.textContent = '';
        if(activeCrateId) setTimeout(()=>loadDjCrateTracks(activeCrateId, activeCrateName), 0);
        else renderDjCrateTracks([]);
      }
      else { if(els.djCrateStatus) els.djCrateStatus.textContent = data.error || 'Could not load DJ crates.'; }
    } catch(e){ if(els.djCrateStatus) els.djCrateStatus.textContent = 'Could not load DJ crates.'; }
  }
  async function loadDjCrateTracks(id, name){
    activeCrateId = id || '';
    crateArtistMode = false;
    if(els.annotateCrates){
      els.annotateCrates.classList.remove('active');
      els.annotateCrates.setAttribute('aria-pressed', 'false');
      els.annotateCrates.textContent = 'Annotate';
    }
    const crate = availableCrates.find(c => String(c.id) === String(activeCrateId));
    activeCrateName = name || String(crate?.name || 'DJ crate');
    renderDjCrates(availableCrates.length ? availableCrates : sortCratesByName(state?.crates || []));
    if(activeCrateId) setCrateDrawer(false);
    if(!activeCrateId){ renderDjCrateTracks([]); return; }
    if(els.djCrateStatus) els.djCrateStatus.innerHTML = '<span class="spinner"></span> Loading crate tracks…';
    try{
      const data = await apiGet({action:'crate_tracks', crate_id:id});
      if(data.ok){ if(els.djCrateStatus) els.djCrateStatus.textContent = ''; renderDjCrateTracks(data.tracks || []); }
      else { if(els.djCrateStatus) els.djCrateStatus.textContent = data.error || 'Could not load crate tracks.'; }
    } catch(e){ if(els.djCrateStatus) els.djCrateStatus.textContent = 'Could not load crate tracks.'; }
  }
  async function loadCrateArtistIndex(force=false){
    if(els.djCrateStatus) els.djCrateStatus.innerHTML = '<span class="spinner"></span> Annotating DJ crates…';
    try{
      await ensureCrateTrackIndex(force);
      if(els.djCrateStatus) els.djCrateStatus.textContent = '';
      renderCrateArtistIndex();
    } catch(e){
      if(els.djCrateStatus) els.djCrateStatus.textContent = 'Could not annotate DJ crates.';
    }
  }

  async function fetchSearchJson(url){
    const res = await fetch(url, {cache:'no-store', credentials:'same-origin'});
    const text = await res.text();
    try { return JSON.parse(text); }
    catch(e) {
      console.warn('Mixer search returned non-JSON response', {url, status: res.status, body: text.slice(0, 250)});
      throw e;
    }
  }
  async function search(q, options = {}){
    if(!q || q.trim().length < 3){ els.searchResults.innerHTML=''; els.searchStatus.textContent=''; lastSearchTracks=[]; renderSearchPager(0); return; }
    els.searchStatus.innerHTML = '<span class="spinner"></span> Searching Spotify + local music…';
    const query = q.trim();
    lastSearchQuery = query;
    searchPage = 0;
    const artist = String(options.artist || '').trim();
    const spotifyQuery = buildSpotifySearchQuery(query, searchMode, artist);
    const spotifyUrls = [
      searchApi + '?' + new URLSearchParams({q:spotifyQuery, limit:'16', _:Date.now()}).toString(),
      api + '?' + new URLSearchParams({action:'search', q:spotifyQuery, limit:'16', _:Date.now()}).toString()
    ];
    const localUrl = localSearchApi + '?q=' + encodeURIComponent(query) + '&limit=16&_=' + Date.now();
    let spotifyData = null;
    let spotifyError = null;
    for(const url of spotifyUrls){
      try{
        const data = await fetchSearchJson(url);
        if(data && data.ok){ spotifyData = data; break; }
        spotifyError = (data && (data.error || data.message)) || 'Spotify search failed';
      } catch(e){ spotifyError = e; }
    }
    let localData = null;
    let localError = null;
    try{
      const data = await fetchSearchJson(localUrl);
      if(data && data.ok) localData = data;
      else localError = (data && (data.error || data.message)) || 'Local music search failed';
    } catch(e){ localError = e; }

    const spotifyTracks = (spotifyData?.tracks || []).map(t => prepareSearchTrack(t, 'spotify'));
    const localTracks = (localData?.tracks || []).map(t => prepareSearchTrack(t, 'local'));
    let tracks = spotifyTracks.concat(localTracks);
    tracks = expandTracksForPagingTest(tracks, query);
    if(tracks.length){
      const notes = [];
      if(spotifyData?.rate_limited) notes.push('Spotify cooling down — cached matches shown');
      else if(spotifyData?.source === 'cache') notes.push('Cached Spotify matches shown');
      if(searchMode === 'track') notes.push('Track title search');
      if(searchMode === 'track_artist' && artist) notes.push('Track + artist search');
      if(localTracks.length) notes.push(localTracks.length + ' local match' + (localTracks.length === 1 ? '' : 'es'));
      if(libraryTestPagesEnabled(query)) notes.push('Paging test mode');
      if(!localData && localError) notes.push('Local music unavailable');
      if(!spotifyData && spotifyError) notes.push('Spotify unavailable');
      els.searchStatus.textContent = notes.join(' • ');
      renderSearchResults(tracks);
      ensureCrateTrackIndex(false).then(refreshSearchCrateHighlights).catch(() => {});
      return;
    }
    console.warn('Mixer search failed', {spotifyError, localError});
    els.searchResults.innerHTML = '';
    els.searchStatus.textContent = 'No matches found';
  }
  app.addEventListener('click', (e)=>{
    if(e.target.closest('#openMusicLibrary')){ openMusicLibrary(); return; }
    if(e.target.closest('#closeMusicLibrary') || (e.target === els.musicLibraryModal)){ closeMusicLibrary(); return; }
    const artistSearch = e.target.closest('[data-artist-search]');
    if(artistSearch){
      runArtistSearch(artistSearch.dataset.artistSearch || '', {title: artistSearch.dataset.trackTitle || ''});
      return;
    }
    const cratePageBtn = e.target.closest('[data-crate-page]');
    if(cratePageBtn){
      if(cratePageBtn.disabled) return;
      cratePage += cratePageBtn.dataset.cratePage === 'next' ? 1 : -1;
      renderDjCrateTracks(activeCrateTracks);
      return;
    }
    const cratePickerPageBtn = e.target.closest('[data-crate-picker-page]');
    if(cratePickerPageBtn){
      if(cratePickerPageBtn.disabled) return;
      cratePickerPage += cratePickerPageBtn.dataset.cratePickerPage === 'next' ? 1 : -1;
      renderCratePicker();
      return;
    }
    const crateArtistPageBtn = e.target.closest('[data-crate-artist-page]');
    if(crateArtistPageBtn){
      if(crateArtistPageBtn.disabled) return;
      crateArtistPage += crateArtistPageBtn.dataset.crateArtistPage === 'next' ? 1 : -1;
      renderCrateArtistIndex();
      return;
    }
    const crateArtistTrackPageBtn = e.target.closest('[data-crate-artist-track-page]');
    if(crateArtistTrackPageBtn){
      if(crateArtistTrackPageBtn.disabled) return;
      crateArtistTrackPage += crateArtistTrackPageBtn.dataset.crateArtistTrackPage === 'next' ? 1 : -1;
      renderCrateArtistIndex();
      return;
    }
    if(e.target.closest('#crateDrawerToggle')){
      setCrateDrawer(!crateDrawerOpen);
      return;
    }
    if(e.target.closest('#annotateCrates')){
      setCrateArtistMode(!crateArtistMode);
      return;
    }
    const artistLetter = e.target.closest('[data-crate-artist-letter]');
    if(artistLetter){
      if(artistLetter.disabled) return;
      crateArtistLetter = artistLetter.dataset.crateArtistLetter || '';
      crateArtistName = '';
      crateArtistPage = 0;
      crateArtistTrackPage = 0;
      renderCrateArtistIndex();
      return;
    }
    const artistName = e.target.closest('[data-crate-artist-name]');
    if(artistName){
      crateArtistName = artistName.dataset.crateArtistName || '';
      crateArtistTrackPage = 0;
      renderCrateArtistIndex();
      return;
    }
    if(e.target.closest('#showNewCrate')){
      if(els.newCratePanel) els.newCratePanel.hidden = false;
      if(els.newCrateName) els.newCrateName.focus();
      return;
    }
    if(e.target.closest('#cancelNewCrate')){
      if(els.newCratePanel) els.newCratePanel.hidden = true;
      if(els.newCrateName) els.newCrateName.value = '';
      return;
    }
    const searchPageBtn = e.target.closest('[data-search-page]');
    if(searchPageBtn){
      if(searchPageBtn.disabled) return;
      searchPage += searchPageBtn.dataset.searchPage === 'next' ? 1 : -1;
      renderSearchResults(lastSearchTracks);
      return;
    }
    const modeBtn = e.target.closest('[data-search-mode]');
    if(modeBtn){
      searchMode = modeBtn.dataset.searchMode || 'broad';
      updateSearchModeButtons();
      if(els.search && els.search.value.trim().length >= 3){
        clearTimeout(searchTimer);
        search(els.search.value);
      }
      return;
    }
    const sourceTab = e.target.closest('[data-source-tab]');
    if(sourceTab){ setSourceTab(sourceTab.dataset.sourceTab || 'search'); return; }
    const openDjCrate = e.target.closest('[data-open-dj-crate]');
    if(openDjCrate){ loadDjCrateTracks(openDjCrate.dataset.openDjCrate, openDjCrate.dataset.crateName || 'DJ crate'); return; }
    const removeCrateTrack = e.target.closest('[data-remove-crate-track]');
    if(removeCrateTrack){
      if(activeCrateId){
        doAction({action:'remove_crate_track', crate_id:activeCrateId, track_id:removeCrateTrack.dataset.removeCrateTrack}).then(()=>{
          refreshCrateArtistIndexAfterMutation();
          refreshDjCratesAfterMutation();
          if(!crateArtistMode) setTimeout(()=>loadDjCrateTracks(activeCrateId, activeCrateName), 350);
        });
      } else {
        setTimeout(()=>loadDjCrateTracks(activeCrateId, activeCrateName), 350);
      }
      return;
    }
    const save = e.target.closest('[data-save-devices]');
    if(save){ doAction({action:'assign_devices', device_a:els.deviceA.value, device_b:els.deviceB.value}); return; }
    const deckAction = e.target.closest('[data-deck-action]');
    if(deckAction){
      if(deckAction.disabled || deckAction.getAttribute('aria-disabled') === 'true') return;
      const rawDeckAction = deckAction.dataset.deckAction || '';
      const deck = deckAction.dataset.deck === 'b' ? 'b' : 'a';
      if(typeof deckAction.blur === 'function') deckAction.blur();
      if(rawDeckAction === 'play_toggle' && e.detail === 0){
        debugTrace('deck_action_ignored_keyboard_click', {deck, rawDeckAction, detail:e.detail, tab_id:MIXER_TAB_ID});
        return;
      }
      const actionMap = {seek_back:'seek_relative', seek_forward:'seek_relative'};
      const params = {action: actionMap[rawDeckAction] || rawDeckAction, deck, ui_event_detail:e.detail || 0};
      if(rawDeckAction === 'play_toggle'){
        const loaded = deckLoadedTrack(deck);
        params.transport_intent = deckIsPlaying(deck) ? 'pause' : 'play';
        params.track_id = loaded?.id || '';
      }
      if(rawDeckAction === 'seek_back') params.delta_ms = -30000;
      if(rawDeckAction === 'seek_forward') params.delta_ms = 30000;
      debugTrace('deck_action_click', {deck, rawDeckAction, params, detail:e.detail || 0, tab_id:MIXER_TAB_ID});
      doAction(params); return; }
    const libraryBtn = e.target.closest('[data-library-action]');
    if(libraryBtn){ if(!libraryBtn.disabled) libraryAction(libraryBtn.dataset.libraryAction); return; }
    const choiceBtn = e.target.closest('[data-choice-action]');
    if(choiceBtn){ choiceAction(choiceBtn.dataset.choiceAction); return; }
    if(e.target.closest('#choiceCancel') || (e.target === els.choiceModal)){ closeChoice(); return; }
    const selectCrateTrack = e.target.closest('[data-select-crate-track]');
    if(selectCrateTrack){
      try{ selectLibraryItem(JSON.parse(selectCrateTrack.dataset.selectCrateTrack), 'crate', selectCrateTrack); }catch(err){ toast('Could not read crate track selection', false); }
      return;
    }
    const selectHistoryTrack = e.target.closest('[data-select-history-track]');
    if(selectHistoryTrack){
      try{ selectLibraryItem(JSON.parse(selectHistoryTrack.dataset.selectHistoryTrack), 'history', selectHistoryTrack.closest('.history-row') || selectHistoryTrack); }catch(err){ toast('Could not read history track selection', false); }
      return;
    }
    const selectTrack = e.target.closest('[data-select-track]');
    if(selectTrack){
      try{
        const item = JSON.parse(selectTrack.dataset.selectTrack);
        if(els.musicLibraryModal && els.musicLibraryModal.classList.contains('open')) selectLibraryItem(item, 'track', selectTrack);
        else openChoice(item, 'track');
      }catch(err){ toast('Could not read track selection', false); }
      return;
    }
    const selectRequest = e.target.closest('[data-select-request]');
    if(selectRequest){
      try{ openChoice(JSON.parse(selectRequest.dataset.selectRequest), 'request'); }catch(err){ toast('Could not read request selection', false); }
      return;
    }
    const playlistPageBtn = e.target.closest('[data-playlist-page]');
    if(playlistPageBtn){
      if(playlistPageBtn.disabled) return;
      const pageCount = Math.max(1, Math.ceil((state?.playlist || []).length / PLAYLIST_PAGE_SIZE));
      if(playlistPageBtn.dataset.playlistPage === 'prev') playlistPage = Math.max(0, playlistPage - 1);
      if(playlistPageBtn.dataset.playlistPage === 'next') playlistPage = Math.min(pageCount - 1, playlistPage + 1);
      renderPlaylist();
      renderDecks();
      return;
    }
    const actionBtn = e.target.closest('[data-action]');
    if(actionBtn){
      if(actionBtn.disabled || actionBtn.getAttribute('aria-disabled') === 'true') return;
      const params = {action:actionBtn.dataset.action};
      if(actionBtn.dataset.idx !== undefined) params.idx = actionBtn.dataset.idx;
      if(actionBtn.dataset.deck) params.deck = actionBtn.dataset.deck;
      if(actionBtn.dataset.direction) params.direction = actionBtn.dataset.direction;
      if(actionBtn.dataset.requestId) params.request_id = actionBtn.dataset.requestId;
      doAction(params); return;
    }
  });
  app.addEventListener('change', (e)=>{
    const libraryCrateSelect = e.target.closest('#libraryCrateSelect');
    if(libraryCrateSelect && selectedLibraryChoice){
      selectedLibraryChoice.saveCrateId = libraryCrateSelect.value || '';
      return;
    }
  });
  app.addEventListener('keydown', (e)=>{
    if((e.key === 'Enter' || e.key === ' ') && e.target && e.target.matches && e.target.matches('[data-select-track], [data-select-crate-track]')){
      e.preventDefault();
      e.target.click();
      return;
    }
    if(e.key === 'Escape'){
      if(els.choiceModal && els.choiceModal.classList.contains('open')) closeChoice();
      else closeMusicLibrary();
      return;
    }
    if(e.key !== 'Enter' && e.key !== ' ') return;
    const selectTrack = e.target.closest('[data-select-track]');
    if(selectTrack && selectTrack.getAttribute('role') === 'button'){
      e.preventDefault();
      try{
        const item = JSON.parse(selectTrack.dataset.selectTrack);
        if(els.musicLibraryModal && els.musicLibraryModal.classList.contains('open')) selectLibraryItem(item, 'track', selectTrack);
        else openChoice(item, 'track');
      }catch(err){ toast('Could not read track selection', false); }
    }
  });
  if(els.search){
    els.search.addEventListener('input', ()=>{ clearTimeout(searchTimer); searchTimer = setTimeout(()=>search(els.search.value), 750); });
  }
  const clearSearch = $('#clearSearch'); if(clearSearch) clearSearch.addEventListener('click', ()=>{ els.search.value=''; els.search.focus(); els.searchResults.innerHTML=''; els.searchStatus.textContent=''; lastSearchTracks=[]; searchPage=0; renderSearchPager(0); });
  if(els.libraryViewSelect) els.libraryViewSelect.addEventListener('change', ()=>setLibraryView(els.libraryViewSelect.value || 'comfortable'));
  const refreshNow = $('#refreshNow'); if(refreshNow) refreshNow.addEventListener('click', ()=>refresh(false, {forceDevices:true}));
  if(els.refreshCrates) els.refreshCrates.addEventListener('click', ()=>{ cratesLoaded = false; invalidateCrateArtistIndex(); if(crateArtistMode) loadCrateArtistIndex(true); else loadDjCrates(true); });
  if(els.createCrate) els.createCrate.addEventListener('click', async ()=>{ const name = els.newCrateName ? els.newCrateName.value : ''; if(!String(name || '').trim()) return; await doAction({action:'create_crate', name}); if(els.newCrateName) els.newCrateName.value=''; if(els.newCratePanel) els.newCratePanel.hidden = true; crateDrawerOpen = true; cratesLoaded=false; invalidateCrateArtistIndex(); loadDjCrates(true); });
  function tickDeckTimers(){
    if(!state) return;
    cleanupTransportHolds();
    cleanupSeekHolds();
    applyPendingSeekHolds();
    renderDecks();
    if(Date.now() - lastStateSyncAt > STATE_POLL_MS + 1500 && !busy){
      refresh(true);
    }
  }
  document.addEventListener('visibilitychange', ()=>{
    if(!document.hidden) refresh(true);
  });
  window.addEventListener('focus', ()=>refresh(true));
  window.addEventListener('beforeunload', ()=>flushDebug('beforeunload'));
  bindDebugControls();
  updateSearchModeButtons();
  updateLibraryView();
  refresh(false);
  pollTimer = setInterval(()=>refresh(true), STATE_POLL_MS);
  uiTimer = setInterval(tickDeckTimers, 1000);
})();
