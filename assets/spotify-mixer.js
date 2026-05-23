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
  let state = null;
  let searchTimer = null;
  let pollTimer = null;
  let busy = false;
  let activeSource = 'search';
  let playlistsLoaded = false;
  let activePlaylistId = '';
  let activePlaylistName = '';

  const $ = (sel) => document.querySelector(sel);
  const els = {
    toast: $('#mixerToast'),
    deviceA: $('#deviceA'), deviceB: $('#deviceB'),
    deckADevice: $('#deckADevice'), deckBDevice: $('#deckBDevice'),
    deckAState: $('#deckAState'), deckBState: $('#deckBState'), deckAVu: $('#deckAVu'), deckBVu: $('#deckBVu'),
    loadedA: $('#loadedA'), loadedB: $('#loadedB'), deckANote: $('#deckANote'), deckBNote: $('#deckBNote'),
    spotifyStatus: $('#spotifyStatus'),
    search: $('#spotifySearch'), searchResults: $('#searchResults'), searchStatus: $('#searchStatus'),
    publicRequests: $('#publicRequests'), djPlaylist: $('#djPlaylist'),
    requestCount: $('#requestCount'), playlistCount: $('#playlistCount'),
    sourceTabs: document.querySelectorAll('[data-source-tab]'), sourcePanels: document.querySelectorAll('[data-source-panel]'),
    spotifyPlaylists: $('#spotifyPlaylists'), spotifyPlaylistTracks: $('#spotifyPlaylistTracks'), spotifyPlaylistStatus: $('#spotifyPlaylistStatus'), historyList: $('#historyList'), refreshPlaylists: $('#refreshPlaylists'),
    choiceModal: $('#mixerChoiceModal'), choiceImage: $('#choiceImage'), choiceTitle: $('#choiceTitle'), choiceArtist: $('#choiceArtist'), choiceActions: $('#choiceActions'), choiceWarning: $('#choiceWarning'), choiceCancel: $('#choiceCancel')
  };

  function esc(s){ return String(s ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c])); }
  function duration(ms){ if(!ms && ms !== 0) return ''; const sec=Math.max(0, Math.round(Number(ms)/1000)); return Math.floor(sec/60)+':'+String(sec%60).padStart(2,'0'); }
  function deckProgress(track, deck){
    const deviceId = state?.['device_' + deck] || '';
    const active = state?.active_device_id === deviceId && !!state?.is_playing;
    const current = state?.track || {};
    const sameTrack = active && track?.id && current?.id && String(track.id) === String(current.id);
    const durationMs = sameTrack ? (Number(current.duration_ms) || Number(track.duration_ms) || 0) : (Number(track?.duration_ms) || 0);
    const progressMs = sameTrack ? (Number(current.progress_ms) || 0) : 0;
    const pct = durationMs ? Math.min(100, Math.max(0, (progressMs / durationMs) * 100)) : 0;
    const remainingMs = durationMs ? Math.max(0, durationMs - progressMs) : 0;
    return {active, sameTrack, durationMs, progressMs, remainingMs, pct};
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
    const res = await fetch(api + '?' + new URLSearchParams(params).toString() + '&_=' + Date.now(), {cache:'no-store'});
    return await res.json();
  }
  async function apiPost(params){
    const body = new URLSearchParams();
    Object.keys(params).forEach(k => body.append(k, params[k]));
    const res = await fetch(api, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body, cache:'no-store'});
    return await res.json();
  }
  function image(src){ return src || 'https://dancethruthedecades.co.uk/assets/glitter-ball-clean.png'; }

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
    const deviceId = state?.['device_' + deck] || '';
    const reported = state?.['player_' + deck]?.state === 'playing';
    const active = !!deviceId && state?.active_device_id === deviceId && !!state?.is_playing;
    return reported || active;
  }
  function deckHasLoaded(deck){
    return !!state?.['player_' + deck]?.loaded?.id;
  }
  function deckCanLoad(deck){
    return !!state?.['device_' + deck] && !deckIsPlaying(deck);
  }
  function clearSearchUi(){
    if(els.search){ els.search.value=''; els.search.focus(); }
    if(els.searchResults) els.searchResults.innerHTML='';
    if(els.searchStatus) els.searchStatus.textContent='';
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
  function openChoice(item, source){
    if(!els.choiceModal || !item) return;
    const title = item.title || item.song_title || 'Selected track';
    const artist = item.artist || '';
    if(els.choiceImage) els.choiceImage.src = image(item.image || item.spotify_album_image || '');
    if(els.choiceTitle) els.choiceTitle.textContent = title;
    if(els.choiceArtist) els.choiceArtist.textContent = artist + (source === 'request' && item.guest_name ? ' • requested by ' + item.guest_name : '');
    const aBlocked = !deckCanLoad('a');
    const bBlocked = !deckCanLoad('b');
    let html = '';
    html += choiceButton('+ Add to DJ playlist', 'green full', 'playlist');
    html += choiceButton('Load to A', 'orange', 'load_a', aBlocked);
    html += choiceButton('Load to B', 'blue', 'load_b', bBlocked);
    html += choiceButton('▶ Play on A now', 'green', 'play_a', aBlocked);
    html += choiceButton('▶ Play on B now', 'green', 'play_b', bBlocked);
    if(els.choiceActions) els.choiceActions.innerHTML = html;
    if(els.choiceWarning){
      const notes=[];
      if(aBlocked) notes.push('A is unavailable or currently playing');
      if(bBlocked) notes.push('B is unavailable or currently playing');
      els.choiceWarning.textContent = notes.length ? notes.join(' • ') : 'Choose a safe action. Play now loads the track and starts it immediately.';
    }
    els.choiceModal._choice = {item, source};
    els.choiceModal.classList.add('open');
    els.choiceModal.setAttribute('aria-hidden','false');
  }
  function choiceAction(action){
    const choice = els.choiceModal?._choice;
    if(!choice) return;
    const src = choice.source;
    const item = choice.item;
    const params = {};
    if(src === 'request'){
      if(action === 'playlist') Object.assign(params, {action:'accept_request', request_id:item.id});
      if(action === 'load_a' || action === 'load_b') Object.assign(params, {action:'load_request', request_id:item.id, deck:action.slice(-1)});
      if(action === 'play_a' || action === 'play_b') Object.assign(params, {action:'play_request_direct', request_id:item.id, deck:action.slice(-1)});
    } else {
      const trackJson = JSON.stringify(item);
      if(action === 'playlist') Object.assign(params, {action:'add_track', track_json:trackJson});
      if(action === 'load_a' || action === 'load_b') Object.assign(params, {action:'load_track_direct', track_json:trackJson, deck:action.slice(-1)});
      if(action === 'play_a' || action === 'play_b') Object.assign(params, {action:'play_track_direct', track_json:trackJson, deck:action.slice(-1)});
    }
    closeChoice();
    doAction(params);
    if(src === 'track' && activeSource === 'search') clearSearchUi();
  }

  function renderDevices(){
    const devices = state?.devices || [];
    const opts = ['<option value="">Choose device…</option>'].concat(devices.map(d => `<option value="${esc(d.id)}">${esc(d.name)}${d.is_active ? ' — active' : ''}</option>`)).join('');
    if(els.deviceA){ const v = els.deviceA.value || state.device_a || ''; els.deviceA.innerHTML = opts; els.deviceA.value = v; }
    if(els.deviceB){ const v = els.deviceB.value || state.device_b || ''; els.deviceB.innerHTML = opts; els.deviceB.value = v; }
    const missingA = !!state?.device_a && !deviceIsOnline(state.device_a);
    const missingB = !!state?.device_b && !deviceIsOnline(state.device_b);
    if(els.deckADevice) els.deckADevice.textContent = missingA ? 'Assigned device offline' : deviceName(state.device_a);
    if(els.deckBDevice) els.deckBDevice.textContent = missingB ? 'Assigned device offline' : deviceName(state.device_b);
    setDeviceAlert('a', missingA);
    setDeviceAlert('b', missingB);
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
    const remainingLabel = prog.durationMs ? (prog.sameTrack ? `-${duration(prog.remainingMs)}` : duration(prog.durationMs)) : '';
    const elapsedLabel = prog.sameTrack ? duration(prog.progressMs) : '0:00';
    return `<div class="loaded-track"><img src="${esc(image(track.image))}" alt=""><div><div class="track-title">${esc(track.title)}</div><div class="track-artist">${esc(track.artist)}</div></div></div>
      <div class="track-progress-meta"><span>${prog.sameTrack ? elapsedLabel : 'Ready'}</span><span>${remainingLabel}</span></div>
      <div class="now-bar ${prog.sameTrack ? 'active' : ''}"><span style="width:${prog.sameTrack ? prog.pct : 0}%"></span></div>
      ${prog.sameTrack ? `<div class="track-time-left">${duration(prog.remainingMs)} remaining</div>` : (prog.durationMs ? `<div class="track-time-left muted">Track length ${duration(prog.durationMs)}</div>` : '')}`;
  }
  function requestInitial(name){
    const s = String(name || 'G').trim();
    return (s ? s[0] : 'G').toUpperCase();
  }
  function renderDeckRequestNote(el, track){
    if(!el) return;
    const guest = String(track?.guest_name || '').trim();
    const msg = String(track?.message || '').trim();
    if(!(track?.source === 'request') || (!guest && !msg)){
      el.classList.remove('visible');
      el.innerHTML = '';
      return;
    }
    el.innerHTML = `<div class="loaded-request-avatar">${esc(requestInitial(guest))}</div>
      <div class="loaded-request-copy">
        <div class="loaded-request-name">${esc(guest || 'Guest')}</div>
        <div class="loaded-request-message${msg ? '' : ' muted'}">${msg ? esc(msg) : 'No dedication/message'}</div>
      </div>`;
    el.classList.add('visible');
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
      const device = deck === 'a' ? state?.device_a : state?.device_b;
      const otherDeck = deck === 'a' ? 'b' : 'a';
      const otherDevice = otherDeck === 'a' ? state?.device_a : state?.device_b;
      document.querySelectorAll(`[data-deck-action="play_toggle"][data-deck="${deck}"]`).forEach(b => {
        b.disabled = !loaded || !device;
        b.classList.toggle('transport-playing', !!playing);
        b.classList.toggle('transport-ready', !!loaded && !playing);
        b.title = playing ? 'Pause Player ' + deck.toUpperCase() : 'Play / resume Player ' + deck.toUpperCase();
      });
      ["seek_start","seek_back","seek_forward","seek_end"].forEach(act => document.querySelectorAll(`[data-deck-action="${act}"][data-deck="${deck}"]`).forEach(b => b.disabled = !loaded || !device));
      document.querySelectorAll(`[data-deck-action="clear_loaded"][data-deck="${deck}"]`).forEach(b => b.disabled = playing || !loaded);
      document.querySelectorAll(`[data-deck-action="emergency_swap"][data-deck="${deck}"]`).forEach(b => {
        b.disabled = !loaded || !device || !otherDevice;
        b.title = 'Emergency transfer Player ' + deck.toUpperCase() + ' to Player ' + otherDeck.toUpperCase();
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
    if(els.spotifyStatus){
      if(state?.is_playing){ els.spotifyStatus.textContent = `Playing on ${state.active_device_name || 'active device'}${state.track?.title ? ' — ' + state.track.title : ''}`; }
      else els.spotifyStatus.textContent = state?.connected ? 'Standby / no active playback' : 'Spotify is not connected';
    }
  }
  function renderPlaylist(){
    const list = state?.playlist || [];
    if(els.playlistCount) els.playlistCount.textContent = list.length;
    if(!els.djPlaylist) return;
    if(!list.length){ els.djPlaylist.innerHTML = '<div class="empty">DJ playlist is empty.</div>'; return; }
    els.djPlaylist.innerHTML = list.map((t,i)=>`
      <div class="playlist-row">
        <img src="${esc(image(t.image))}" alt="">
        <div>
          <strong>${esc(t.title)}</strong><br>
          <span class="mini muted">${esc(t.artist)}${duration(t.duration_ms) ? ' • ' + duration(t.duration_ms) : ''}${t.source === 'request' ? ' • public request' : ''}</span>
          ${t.source === 'request' ? `<div class="playlist-note mini" title="${esc((t.guest_name || 'Guest') + (t.message ? ': ' + t.message : ''))}"><strong>${esc(t.guest_name || 'Guest')}</strong>${t.message ? ': ' + esc(t.message) : ''}</div>` : ''}
        </div>
        <div class="row-actions">
          <button class="mixer-btn green auto-btn mixer-mini-action" data-action="auto_load" data-idx="${i}" title="Auto-load to the first empty standby player" aria-label="Auto-load to the first empty standby player">⇄</button>
          <button class="mixer-btn orange mixer-mini-action" data-action="load" data-deck="a" data-load-a data-idx="${i}" title="Load to Player A" aria-label="Load to Player A">A</button>
          <button class="mixer-btn blue mixer-mini-action" data-action="load" data-deck="b" data-load-b data-idx="${i}" title="Load to Player B" aria-label="Load to Player B">B</button>
          <button class="mixer-btn red mixer-mini-action" data-action="remove_playlist" data-idx="${i}" title="Remove from DJ playlist" aria-label="Remove from DJ playlist">×</button>
        </div>
      </div>`).join('');
  }
  function renderRequests(){
    const reqs = state?.requests || [];
    if(els.requestCount) els.requestCount.textContent = reqs.length;
    if(!els.publicRequests) return;
    if(!reqs.length){ els.publicRequests.innerHTML = '<div class="empty">No new Spotify-matched public requests waiting.</div>'; return; }
    els.publicRequests.innerHTML = reqs.map(r => `
      <div class="request-row">
        <img src="${esc(image(r.image))}" alt="">
        <div>
          <strong>${esc(r.title)}</strong> <span class="muted">— ${esc(r.artist)}</span>
          <div class="request-detail mini"><span class="request-time">${esc((r.created_at || '').slice(11,16))}</span><span><strong>${esc(r.guest_name || 'Guest')}</strong></span></div>
          ${r.message ? `<div class="request-message mini" title="${esc(r.message)}">${esc(r.message)}</div>` : '<div class="request-message mini muted">No dedication/message</div>'}
          <div class="request-source">Public request • ${esc(r.status || 'pending')}</div>
        </div>
        <div class="row-actions quick-actions">
          <button class="mixer-btn green wide" data-select-request='${esc(JSON.stringify(r))}'>Choose action</button>
        </div>
      </div>`).join('');
  }
  function render(){ renderDevices(); renderPlaylist(); renderRequests(); renderDecks(); if(activeSource === 'history') renderHistory(); }
  async function refresh(silent=true){
    try{ const data = await apiGet({action:'state'}); if(data.ok){ state = data.state; render(); } else { if(data.state){state=data.state; render();} if(!silent) toast(data.error || 'Update failed', false); } }
    catch(e){ if(!silent) toast('Mixer update failed', false); }
  }
  async function doAction(params){
    if(busy) return;
    busy = true;
    try{
      const data = await apiPost(params);
      if(data.state){ state = data.state; render(); }
      toast(data.ok ? (data.message || 'Done') : (data.error || 'Action failed'), !!data.ok);
    }catch(e){ toast('Action failed', false); }
    finally{ busy = false; }
  }
  function renderSearchResults(tracks){
    if(!els.searchResults) return;
    if(!tracks.length){ els.searchResults.innerHTML = '<div class="mini muted">No matches yet.</div>'; return; }
    els.searchResults.innerHTML = tracks.map(t=>`
      <div class="result-row">
        <img src="${esc(image(t.image))}" alt="">
        <div><div class="result-title">${esc(t.title)}</div><div class="mini muted">${esc(t.artist)}${t.album ? ' • ' + esc(t.album) : ''}</div></div>
        <button class="mixer-btn green" data-select-track='${esc(JSON.stringify(t))}'>Choose</button>
      </div>`).join('');
  }
  function setSourceTab(name){
    activeSource = name || 'search';
    els.sourceTabs.forEach(t => t.classList.toggle('active', t.dataset.sourceTab === activeSource));
    els.sourcePanels.forEach(p => p.classList.toggle('active', p.dataset.sourcePanel === activeSource));
    if(activeSource === 'playlists' && !playlistsLoaded) loadSpotifyPlaylists();
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
          <button class="mixer-btn green" data-select-track='${esc(JSON.stringify(t))}'>Choose</button>
        </div>
      </div>`).join('');
  }
  function renderSpotifyPlaylists(playlists){
    if(!els.spotifyPlaylists) return;
    if(!playlists.length){ els.spotifyPlaylists.innerHTML = '<div class="empty">No Spotify playlists found for this account.</div>'; return; }
    els.spotifyPlaylists.innerHTML = playlists.map(p => `
      <div class="spotify-playlist-row">
        <img src="${esc(image(p.image))}" alt="">
        <div>
          <strong>${esc(p.name)}</strong><br>
          <span class="mini muted">${Number(p.tracks_total || 0)} tracks${p.owner ? ' • ' + esc(p.owner) : ''}</span>
        </div>
        <div class="row-actions"><button class="mixer-btn blue" data-open-spotify-playlist="${esc(p.id)}" data-playlist-name="${esc(p.name)}">Open</button></div>
      </div>`).join('');
  }
  function renderSpotifyPlaylistTracks(tracks){
    if(!els.spotifyPlaylistTracks) return;
    const heading = activePlaylistName ? `<div class="tiny-label" style="margin-top:10px">${esc(activePlaylistName)} tracks</div>` : '';
    if(!tracks.length){ els.spotifyPlaylistTracks.innerHTML = heading + '<div class="empty">No playable tracks found in this playlist.</div>'; return; }
    els.spotifyPlaylistTracks.innerHTML = heading + tracks.map(t => `
      <div class="result-row">
        <img src="${esc(image(t.image))}" alt="">
        <div><div class="result-title">${esc(t.title)}</div><div class="mini muted">${esc(t.artist)}${t.album ? ' • ' + esc(t.album) : ''}</div></div>
        <button class="mixer-btn green" data-select-track='${esc(JSON.stringify(t))}'>Choose</button>
      </div>`).join('');
  }
  async function loadSpotifyPlaylists(force=false){
    if(!force && playlistsLoaded) return;
    if(els.spotifyPlaylistStatus) els.spotifyPlaylistStatus.innerHTML = '<span class="spinner"></span> Loading Spotify playlists…';
    if(els.spotifyPlaylistTracks) els.spotifyPlaylistTracks.innerHTML = '';
    try{
      const data = await apiGet({action:'spotify_playlists'});
      if(data.ok){ playlistsLoaded = true; if(els.spotifyPlaylistStatus) els.spotifyPlaylistStatus.textContent = ''; renderSpotifyPlaylists(data.playlists || []); }
      else { if(els.spotifyPlaylistStatus) els.spotifyPlaylistStatus.textContent = data.error || 'Could not load playlists.'; }
    } catch(e){ if(els.spotifyPlaylistStatus) els.spotifyPlaylistStatus.textContent = 'Could not load playlists.'; }
  }
  async function loadSpotifyPlaylistTracks(id, name){
    activePlaylistId = id || ''; activePlaylistName = name || 'Spotify playlist';
    if(els.spotifyPlaylistStatus) els.spotifyPlaylistStatus.innerHTML = '<span class="spinner"></span> Loading playlist tracks…';
    try{
      const data = await apiGet({action:'spotify_playlist_tracks', playlist_id:id});
      if(data.ok){ if(els.spotifyPlaylistStatus) els.spotifyPlaylistStatus.textContent = ''; renderSpotifyPlaylistTracks(data.tracks || []); }
      else { if(els.spotifyPlaylistStatus) els.spotifyPlaylistStatus.textContent = data.error || 'Could not load playlist tracks.'; }
    } catch(e){ if(els.spotifyPlaylistStatus) els.spotifyPlaylistStatus.textContent = 'Could not load playlist tracks.'; }
  }

  async function search(q){
    if(!q || q.trim().length < 2){ els.searchResults.innerHTML=''; els.searchStatus.textContent=''; return; }
    els.searchStatus.innerHTML = '<span class="spinner"></span> Searching…';
    try{ const data = await apiGet({action:'search', q}); if(data.ok){ els.searchStatus.textContent = ''; renderSearchResults(data.tracks || []); } else { els.searchStatus.textContent = data.error || 'Search failed'; } }
    catch(e){ els.searchStatus.textContent = 'Search failed'; }
  }
  app.addEventListener('click', (e)=>{
    const sourceTab = e.target.closest('[data-source-tab]');
    if(sourceTab){ setSourceTab(sourceTab.dataset.sourceTab || 'search'); return; }
    const openSpotifyPlaylist = e.target.closest('[data-open-spotify-playlist]');
    if(openSpotifyPlaylist){ loadSpotifyPlaylistTracks(openSpotifyPlaylist.dataset.openSpotifyPlaylist, openSpotifyPlaylist.dataset.playlistName || 'Spotify playlist'); return; }
    const save = e.target.closest('[data-save-devices]');
    if(save){ doAction({action:'assign_devices', device_a:els.deviceA.value, device_b:els.deviceB.value}); return; }
    const deckAction = e.target.closest('[data-deck-action]');
    if(deckAction){
      if(deckAction.disabled || deckAction.getAttribute('aria-disabled') === 'true') return;
      const actionMap = {seek_back:'seek_relative', seek_forward:'seek_relative'};
      const params = {action: actionMap[deckAction.dataset.deckAction] || deckAction.dataset.deckAction, deck: deckAction.dataset.deck};
      if(deckAction.dataset.deckAction === 'seek_back') params.delta_ms = -30000;
      if(deckAction.dataset.deckAction === 'seek_forward') params.delta_ms = 30000;
      doAction(params); return; }
    const choiceBtn = e.target.closest('[data-choice-action]');
    if(choiceBtn){ choiceAction(choiceBtn.dataset.choiceAction); return; }
    if(e.target.closest('#choiceCancel') || (e.target === els.choiceModal)){ closeChoice(); return; }
    const selectTrack = e.target.closest('[data-select-track]');
    if(selectTrack){
      try{ openChoice(JSON.parse(selectTrack.dataset.selectTrack), 'track'); }catch(err){ toast('Could not read track selection', false); }
      return;
    }
    const selectRequest = e.target.closest('[data-select-request]');
    if(selectRequest){
      try{ openChoice(JSON.parse(selectRequest.dataset.selectRequest), 'request'); }catch(err){ toast('Could not read request selection', false); }
      return;
    }
    const actionBtn = e.target.closest('[data-action]');
    if(actionBtn){
      if(actionBtn.disabled || actionBtn.getAttribute('aria-disabled') === 'true') return;
      const params = {action:actionBtn.dataset.action};
      if(actionBtn.dataset.idx !== undefined) params.idx = actionBtn.dataset.idx;
      if(actionBtn.dataset.deck) params.deck = actionBtn.dataset.deck;
      if(actionBtn.dataset.requestId) params.request_id = actionBtn.dataset.requestId;
      doAction(params); return;
    }
  });
  if(els.search){
    els.search.addEventListener('input', ()=>{ clearTimeout(searchTimer); searchTimer = setTimeout(()=>search(els.search.value), 320); });
  }
  const clearSearch = $('#clearSearch'); if(clearSearch) clearSearch.addEventListener('click', ()=>{ els.search.value=''; els.search.focus(); els.searchResults.innerHTML=''; els.searchStatus.textContent=''; });
  const refreshNow = $('#refreshNow'); if(refreshNow) refreshNow.addEventListener('click', ()=>refresh(false));
  if(els.refreshPlaylists) els.refreshPlaylists.addEventListener('click', ()=>{ playlistsLoaded = false; loadSpotifyPlaylists(true); });
  refresh(false);
  pollTimer = setInterval(()=>refresh(true), 3000);
})();
