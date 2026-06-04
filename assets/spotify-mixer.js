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
  let busy = false;
  let activeSource = 'search';
  let cratesLoaded = false;
  let activeCrateId = '';
  let activeCrateName = '';
  let activeCrateTracks = [];
  let availableCrates = [];

  const $ = (sel) => document.querySelector(sel);
  const els = {
    toast: $('#mixerToast'),
    deviceA: $('#deviceA'), deviceB: $('#deviceB'),
    deckADevice: $('#deckADevice'), deckBDevice: $('#deckBDevice'),
    deckAState: $('#deckAState'), deckBState: $('#deckBState'), deckAVu: $('#deckAVu'), deckBVu: $('#deckBVu'), mixerModePill: $('#mixerModePill'), deckAAccount: $('#deckAAccount'), deckBAccount: $('#deckBAccount'), deckANode: $('#deckANode'), deckBNode: $('#deckBNode'), deckAWarning: $('#deckAWarning'), deckBWarning: $('#deckBWarning'),
    loadedA: $('#loadedA'), loadedB: $('#loadedB'), deckANote: $('#deckANote'), deckBNote: $('#deckBNote'),
    spotifyStatus: $('#spotifyStatus'),
    search: $('#spotifySearch'), searchResults: $('#searchResults'), searchStatus: $('#searchStatus'),
    publicRequests: $('#publicRequests'), djPlaylist: $('#djPlaylist'),
    requestCount: $('#requestCount'), playlistCount: $('#playlistCount'),
    sourceTabs: document.querySelectorAll('[data-source-tab]'), sourcePanels: document.querySelectorAll('[data-source-panel]'),
    djCrates: $('#djCrates'), djCrateTracks: $('#djCrateTracks'), djCrateStatus: $('#djCrateStatus'), refreshCrates: $('#refreshCrates'), newCrateName: $('#newCrateName'), createCrate: $('#createCrate'), historyList: $('#historyList'),
    choiceModal: $('#mixerChoiceModal'), choiceImage: $('#choiceImage'), choiceTitle: $('#choiceTitle'), choiceArtist: $('#choiceArtist'), choiceActions: $('#choiceActions'), choiceWarning: $('#choiceWarning'), choiceCancel: $('#choiceCancel')
  };

  function esc(s){ return String(s ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c])); }
  function duration(ms){ if(!ms && ms !== 0) return ''; const sec=Math.max(0, Math.round(Number(ms)/1000)); return Math.floor(sec/60)+':'+String(sec%60).padStart(2,'0'); }
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
    if(track.played_on_deck) return {label:'Paused / in progress', cls:'progress', detail:'Not marked played yet'};
    return {label:'Loaded', cls:'loaded', detail:'Not marked played until ' + playedThresholdLabel(track)};
  }
  function workflowBadge(label, cls='info', title=''){
    return `<span class="workflow-badge ${esc(cls)}"${title ? ` title="${esc(title)}"` : ''}>${esc(label)}</span>`;
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
      return {active, sameTrack: active || progressMs > 0, durationMs, progressMs, remainingMs, pct};
    }
    const deviceId = state?.['device_' + deck] || '';
    const active = state?.active_device_id === deviceId && !!state?.is_playing;
    const current = state?.track || {};
    const sameTrack = active && track?.id && current?.id && String(track.id) === String(current.id);
    const spotifyDurationMs = sameTrack ? (Number(current.duration_ms) || Number(track.duration_ms) || 0) : durationMs;
    const progressMs = sameTrack ? (Number(current.progress_ms) || 0) : 0;
    const pct = spotifyDurationMs ? Math.min(100, Math.max(0, (progressMs / spotifyDurationMs) * 100)) : 0;
    const remainingMs = spotifyDurationMs ? Math.max(0, spotifyDurationMs - progressMs) : 0;
    return {active, sameTrack, durationMs: spotifyDurationMs, progressMs, remainingMs, pct};
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
  function crateSaveControls(){
    const crates = availableCrates.length ? availableCrates : (state?.crates || []);
    if(!crates.length){
      return choiceButton('+ Save to DJ crate', 'blue full', 'crate', true);
    }
    const selected = activeCrateId || String(crates[0].id || '');
    const opts = crates.map(c => `<option value="${esc(c.id)}" ${String(c.id) === String(selected) ? 'selected' : ''}>${esc(c.name)}</option>`).join('');
    return `<div class="choice-crate-save full"><label for="choiceCrateSelect">Save to crate</label><div class="choice-crate-row"><select id="choiceCrateSelect" class="mixer-select">${opts}</select><button class="mixer-btn blue" data-choice-action="crate">+ Save</button></div></div>`;
  }
  function openChoice(item, source){
    if(!els.choiceModal || !item) return;
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
  function choiceAction(action){
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
      if(action === 'load_a' || action === 'load_b') Object.assign(params, {action:'load_track_direct', track_json:trackJson, deck:action.slice(-1)});
      if(action === 'play_a' || action === 'play_b') Object.assign(params, {action:'play_track_direct', track_json:trackJson, deck:action.slice(-1)});
    }
    closeChoice();
    doAction(params);
    if(src === 'track' && activeSource === 'search') clearSearchUi();
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
      const device = deck === 'a' ? state?.device_a : state?.device_b;
      const otherDeck = deck === 'a' ? 'b' : 'a';
      const otherDevice = otherDeck === 'a' ? state?.device_a : state?.device_b;
      document.querySelectorAll(`[data-deck-action="play_toggle"][data-deck="${deck}"]`).forEach(b => {
        b.disabled = !loaded || (!loadedLocal && (!device || accountHasWarning(deck)));
        b.classList.toggle('transport-playing', !!playing);
        b.classList.toggle('transport-ready', !!loaded && !playing);
        b.title = loadedLocal ? (playing ? 'Pause local MPD on Player ' + deck.toUpperCase() : 'Play local MPD on Player ' + deck.toUpperCase()) : (accountHasWarning(deck) ? (accountInfo(deck)?.warning || 'Spotify account warning') : (playing ? 'Pause Player ' + deck.toUpperCase() : 'Play / resume Player ' + deck.toUpperCase()));
      });
      ["seek_start","seek_back","seek_forward","seek_end"].forEach(act => document.querySelectorAll(`[data-deck-action="${act}"][data-deck="${deck}"]`).forEach(b => {
        b.disabled = !loaded || (!loadedLocal && (!device || accountHasWarning(deck)));
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
    if(!list.length){ els.djPlaylist.innerHTML = '<div class="empty">DJ playlist is empty.</div>'; return; }
    els.djPlaylist.innerHTML = list.map((t,i)=>{
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
        <div class="row-actions">
          <button class="mixer-btn green auto-btn mixer-mini-action" data-action="auto_load" data-idx="${i}" title="Auto-load to the first empty standby player" aria-label="Auto-load to the first empty standby player">⇄</button>
          <button class="mixer-btn orange mixer-mini-action" data-action="load" data-deck="a" data-load-a data-idx="${i}" title="Load to Player A" aria-label="Load to Player A">A</button>
          <button class="mixer-btn blue mixer-mini-action" data-action="load" data-deck="b" data-load-b data-idx="${i}" title="Load to Player B" aria-label="Load to Player B">B</button>
          <button class="mixer-btn red mixer-mini-action" data-action="remove_playlist" data-idx="${i}" title="Remove from DJ playlist" aria-label="Remove from DJ playlist">×</button>
        </div>
      </div>`;
    }).join('');
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
  function render(){ if(state?.crates) availableCrates = state.crates; renderDevices(); renderPlaylist(); renderRequests(); renderDecks(); if(activeSource === 'crates') renderDjCrates(availableCrates.length ? availableCrates : (state?.crates || [])); if(activeSource === 'history') renderHistory(); }
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
  function searchBadgeHtml(track){
    const badges = Array.isArray(track?.badges) ? track.badges : [];
    if(!badges.length) return '';
    return `<div class="search-result-badges">${badges.slice(0,4).map(b=>`<span class="search-result-badge ${esc(b.type || '')}">${esc(b.label || '')}</span>`).join('')}</div>`;
  }
  function renderSearchResults(tracks){
    if(!els.searchResults) return;
    if(!tracks.length){ els.searchResults.innerHTML = '<div class="mini muted">No matches yet.</div>'; return; }
    els.searchResults.innerHTML = tracks.map(t=>`
      <div class="result-row search-result-row">
        <img src="${esc(image(t.image))}" alt="">
        <div><div class="result-title">${esc(t.title)}</div><div class="mini muted">${esc(t.artist)}${t.album ? ' • ' + esc(t.album) : ''}</div></div>
        ${searchBadgeHtml(t)}
        <button class="mixer-btn green" data-select-track='${esc(JSON.stringify(t))}'>Choose</button>
      </div>`).join('');
  }
  function setSourceTab(name){
    activeSource = name || 'search';
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
          <button class="mixer-btn green" data-select-track='${esc(JSON.stringify(t))}'>Choose</button>
        </div>
      </div>`).join('');
  }
  function renderDjCrates(crates){
    if(!els.djCrates) return;
    if(!crates.length){ els.djCrates.innerHTML = '<div class="empty">No DJ crates yet. Create one above, then save tracks from search or history.</div>'; return; }
    els.djCrates.innerHTML = crates.map(c => `
      <div class="spotify-playlist-row${String(c.id) === String(activeCrateId) ? ' active-crate' : ''}">
        <div class="crate-icon">♫</div>
        <div>
          <strong>${esc(c.name)}</strong><br>
          <span class="mini muted">${Number(c.track_count || 0)} saved tracks${String(c.id) === String(activeCrateId) ? ' • selected' : ''}</span>
        </div>
        <div class="row-actions"><button class="mixer-btn blue" data-open-dj-crate="${esc(c.id)}" data-crate-name="${esc(c.name)}">Open</button></div>
      </div>`).join('');
  }
  function renderDjCrateTracks(tracks){
    if(!els.djCrateTracks) return;
    activeCrateTracks = tracks || [];
    const heading = activeCrateName ? `<div class="tiny-label" style="margin-top:10px">${esc(activeCrateName)} tracks</div><div class="mini muted">New search/history selections will be saved into this crate.</div>` : '';
    if(!tracks.length){ els.djCrateTracks.innerHTML = heading + '<div class="empty">This crate is empty. Search Spotify, choose a track, then press Save to DJ crate.</div>'; return; }
    els.djCrateTracks.innerHTML = heading + tracks.map(t => `
      <div class="result-row">
        <img src="${esc(image(t.image))}" alt="">
        <div><div class="result-title">${esc(t.title)}</div><div class="mini muted">${esc(t.artist)}${t.album ? ' • ' + esc(t.album) : ''}</div></div>
        <div class="row-actions">
          <button class="mixer-btn green" data-select-crate-track='${esc(JSON.stringify(t))}'>Choose</button>
          <button class="mixer-btn red" data-remove-crate-track="${esc(t.id)}">×</button>
        </div>
      </div>`).join('');
  }
  async function loadDjCrates(force=false){
    if(!force && cratesLoaded) return;
    if(els.djCrateStatus) els.djCrateStatus.innerHTML = '<span class="spinner"></span> Loading DJ crates…';
    try{
      const data = await apiGet({action:'crates'});
      if(data.ok){ cratesLoaded = true; availableCrates = data.crates || []; if(!activeCrateId && availableCrates.length){ activeCrateId = String(availableCrates[0].id || ''); activeCrateName = String(availableCrates[0].name || 'DJ crate'); } if(els.djCrateStatus) els.djCrateStatus.textContent = ''; renderDjCrates(availableCrates); }
      else { if(els.djCrateStatus) els.djCrateStatus.textContent = data.error || 'Could not load DJ crates.'; }
    } catch(e){ if(els.djCrateStatus) els.djCrateStatus.textContent = 'Could not load DJ crates.'; }
  }
  async function loadDjCrateTracks(id, name){
    activeCrateId = id || ''; activeCrateName = name || 'DJ crate';
    if(els.djCrateStatus) els.djCrateStatus.innerHTML = '<span class="spinner"></span> Loading crate tracks…';
    try{
      const data = await apiGet({action:'crate_tracks', crate_id:id});
      if(data.ok){ if(els.djCrateStatus) els.djCrateStatus.textContent = ''; renderDjCrates(availableCrates.length ? availableCrates : (state?.crates || [])); renderDjCrateTracks(data.tracks || []); }
      else { if(els.djCrateStatus) els.djCrateStatus.textContent = data.error || 'Could not load crate tracks.'; }
    } catch(e){ if(els.djCrateStatus) els.djCrateStatus.textContent = 'Could not load crate tracks.'; }
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
  async function search(q){
    if(!q || q.trim().length < 3){ els.searchResults.innerHTML=''; els.searchStatus.textContent=''; return; }
    els.searchStatus.innerHTML = '<span class="spinner"></span> Searching Spotify + local music…';
    const query = q.trim();
    const spotifyUrls = [
      searchApi + '?q=' + encodeURIComponent(query) + '&_=' + Date.now(),
      api + '?' + new URLSearchParams({action:'search', q:query, _:Date.now()}).toString()
    ];
    const localUrl = localSearchApi + '?q=' + encodeURIComponent(query) + '&limit=10&_=' + Date.now();
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
    const tracks = spotifyTracks.concat(localTracks);
    if(tracks.length){
      const notes = [];
      if(spotifyData?.rate_limited) notes.push('Spotify cooling down — cached matches shown');
      else if(spotifyData?.source === 'cache') notes.push('Cached Spotify matches shown');
      if(localTracks.length) notes.push(localTracks.length + ' local match' + (localTracks.length === 1 ? '' : 'es'));
      if(!localData && localError) notes.push('Local music unavailable');
      if(!spotifyData && spotifyError) notes.push('Spotify unavailable');
      els.searchStatus.textContent = notes.join(' • ');
      renderSearchResults(tracks);
      return;
    }
    console.warn('Mixer search failed', {spotifyError, localError});
    els.searchResults.innerHTML = '';
    els.searchStatus.textContent = 'No matches found';
  }
  app.addEventListener('click', (e)=>{
    const sourceTab = e.target.closest('[data-source-tab]');
    if(sourceTab){ setSourceTab(sourceTab.dataset.sourceTab || 'search'); return; }
    const openDjCrate = e.target.closest('[data-open-dj-crate]');
    if(openDjCrate){ loadDjCrateTracks(openDjCrate.dataset.openDjCrate, openDjCrate.dataset.crateName || 'DJ crate'); return; }
    const removeCrateTrack = e.target.closest('[data-remove-crate-track]');
    if(removeCrateTrack){ if(activeCrateId) doAction({action:'remove_crate_track', crate_id:activeCrateId, track_id:removeCrateTrack.dataset.removeCrateTrack}); setTimeout(()=>loadDjCrateTracks(activeCrateId, activeCrateName), 350); return; }
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
    const selectCrateTrack = e.target.closest('[data-select-crate-track]');
    if(selectCrateTrack){
      try{ openChoice(JSON.parse(selectCrateTrack.dataset.selectCrateTrack), 'crate'); }catch(err){ toast('Could not read crate track selection', false); }
      return;
    }
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
    els.search.addEventListener('input', ()=>{ clearTimeout(searchTimer); searchTimer = setTimeout(()=>search(els.search.value), 750); });
  }
  const clearSearch = $('#clearSearch'); if(clearSearch) clearSearch.addEventListener('click', ()=>{ els.search.value=''; els.search.focus(); els.searchResults.innerHTML=''; els.searchStatus.textContent=''; });
  const refreshNow = $('#refreshNow'); if(refreshNow) refreshNow.addEventListener('click', ()=>refresh(false));
  if(els.refreshCrates) els.refreshCrates.addEventListener('click', ()=>{ cratesLoaded = false; loadDjCrates(true); });
  if(els.createCrate) els.createCrate.addEventListener('click', async ()=>{ const name = els.newCrateName ? els.newCrateName.value : ''; await doAction({action:'create_crate', name}); if(els.newCrateName) els.newCrateName.value=''; cratesLoaded=false; loadDjCrates(true); });
  refresh(false);
  pollTimer = setInterval(()=>refresh(true), 5000);
})();
