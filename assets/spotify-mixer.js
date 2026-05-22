(function(){
  const app = document.querySelector('.spotify-mixer-app');
  if(!app) return;
  const api = app.dataset.api || 'mixer-api.php';
  let state = null;
  let searchTimer = null;
  let pollTimer = null;
  let busy = false;

  const $ = (sel) => document.querySelector(sel);
  const els = {
    toast: $('#mixerToast'),
    deviceA: $('#deviceA'), deviceB: $('#deviceB'),
    deckADevice: $('#deckADevice'), deckBDevice: $('#deckBDevice'),
    deckAState: $('#deckAState'), deckBState: $('#deckBState'),
    loadedA: $('#loadedA'), loadedB: $('#loadedB'),
    spotifyStatus: $('#spotifyStatus'),
    search: $('#spotifySearch'), searchResults: $('#searchResults'), searchStatus: $('#searchStatus'),
    publicRequests: $('#publicRequests'), djPlaylist: $('#djPlaylist'),
    requestCount: $('#requestCount'), playlistCount: $('#playlistCount'),
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
    return !!state?.['device_' + deck] && !deckIsPlaying(deck) && !deckHasLoaded(deck);
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
    if(src === 'track') clearSearchUi();
  }

  function renderDevices(){
    const devices = state?.devices || [];
    const opts = ['<option value="">Choose device…</option>'].concat(devices.map(d => `<option value="${esc(d.id)}">${esc(d.name)}${d.is_active ? ' — active' : ''}</option>`)).join('');
    if(els.deviceA){ const v = els.deviceA.value || state.device_a || ''; els.deviceA.innerHTML = opts; els.deviceA.value = v; }
    if(els.deviceB){ const v = els.deviceB.value || state.device_b || ''; els.deviceB.innerHTML = opts; els.deviceB.value = v; }
    if(els.deckADevice) els.deckADevice.textContent = deviceName(state.device_a);
    if(els.deckBDevice) els.deckBDevice.textContent = deviceName(state.device_b);
  }
  function setDeckState(el, playing){
    if(!el) return;
    el.textContent = playing ? 'Playing' : 'Standby';
    el.classList.toggle('playing', !!playing);
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
  function renderDecks(){
    const aPlaying = deckIsPlaying('a');
    const bPlaying = deckIsPlaying('b');
    const aLoaded = deckHasLoaded('a');
    const bLoaded = deckHasLoaded('b');
    setDeckState(els.deckAState, aPlaying); setDeckState(els.deckBState, bPlaying);
    if(els.loadedA) els.loadedA.innerHTML = trackBlock(state?.player_a?.loaded, 'a');
    if(els.loadedB) els.loadedB.innerHTML = trackBlock(state?.player_b?.loaded, 'b');
    document.querySelectorAll('[data-deck-action="play"][data-deck="a"]').forEach(b => b.disabled = !(state?.player_a?.loaded?.id) || !state?.device_a);
    document.querySelectorAll('[data-deck-action="play"][data-deck="b"]').forEach(b => b.disabled = !(state?.player_b?.loaded?.id) || !state?.device_b);
    document.querySelectorAll('[data-deck-action="pause"][data-deck="a"]').forEach(b => b.disabled = !state?.device_a);
    document.querySelectorAll('[data-deck-action="pause"][data-deck="b"]').forEach(b => b.disabled = !state?.device_b);
    document.querySelectorAll('[data-deck-action="clear_loaded"][data-deck="a"]').forEach(b => b.disabled = aPlaying);
    document.querySelectorAll('[data-deck-action="clear_loaded"][data-deck="b"]').forEach(b => b.disabled = bPlaying);
    document.querySelectorAll('[data-load-a]').forEach(b => {
      b.disabled = aPlaying || aLoaded || !state?.device_a;
      b.title = b.disabled ? (aPlaying ? 'Player A is currently playing' : (aLoaded ? 'Player A already has a loaded track' : 'Player A has no assigned device')) : 'Load to Player A';
    });
    document.querySelectorAll('[data-load-b]').forEach(b => {
      b.disabled = bPlaying || bLoaded || !state?.device_b;
      b.title = b.disabled ? (bPlaying ? 'Player B is currently playing' : (bLoaded ? 'Player B already has a loaded track' : 'Player B has no assigned device')) : 'Load to Player B';
    });
    document.querySelectorAll('[data-action="auto_load"]').forEach(b => {
      b.disabled = !deckCanLoad('a') && !deckCanLoad('b');
      b.title = b.disabled ? 'No empty standby player is available' : 'Load to the first empty standby player';
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
          ${t.source === 'request' ? `<div class="playlist-note mini"><strong>${esc(t.guest_name || 'Guest')}</strong>${t.message ? ': ' + esc(t.message) : ''}</div>` : ''}
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
          ${r.message ? `<div class="request-message mini">${esc(r.message)}</div>` : '<div class="request-message mini muted">No dedication/message</div>'}
          <div class="request-source">Public request • ${esc(r.status || 'pending')}</div>
        </div>
        <div class="row-actions quick-actions">
          <button class="mixer-btn green wide" data-select-request='${esc(JSON.stringify(r))}'>Choose action</button>
        </div>
      </div>`).join('');
  }
  function render(){ renderDevices(); renderPlaylist(); renderRequests(); renderDecks(); }
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
  async function search(q){
    if(!q || q.trim().length < 2){ els.searchResults.innerHTML=''; els.searchStatus.textContent=''; return; }
    els.searchStatus.innerHTML = '<span class="spinner"></span> Searching…';
    try{ const data = await apiGet({action:'search', q}); if(data.ok){ els.searchStatus.textContent = ''; renderSearchResults(data.tracks || []); } else { els.searchStatus.textContent = data.error || 'Search failed'; } }
    catch(e){ els.searchStatus.textContent = 'Search failed'; }
  }
  app.addEventListener('click', (e)=>{
    const save = e.target.closest('[data-save-devices]');
    if(save){ doAction({action:'assign_devices', device_a:els.deviceA.value, device_b:els.deviceB.value}); return; }
    const deckAction = e.target.closest('[data-deck-action]');
    if(deckAction){
      if(deckAction.disabled || deckAction.getAttribute('aria-disabled') === 'true') return; doAction({action:deckAction.dataset.deckAction, deck:deckAction.dataset.deck}); return; }
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
  refresh(false);
  pollTimer = setInterval(()=>refresh(true), 3000);
})();
