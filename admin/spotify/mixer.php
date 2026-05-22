<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

admin_header('Spotify Mixer - DJ Portal');
?>
<style>
.spotify-mixer-app{padding:14px;max-width:1840px;margin:0 auto}.mixer-toast{position:fixed;right:18px;bottom:18px;z-index:50;max-width:420px;padding:13px 16px;border-radius:14px;border:1px solid rgba(80,140,210,.35);background:rgba(11,22,35,.96);color:#fff;box-shadow:0 18px 50px rgba(0,0,0,.38);display:none}.mixer-toast.ok{border-color:rgba(34,197,94,.65);color:#baffcf}.mixer-toast.err{border-color:rgba(255,70,85,.65);color:#ffb4bc}.mixer-top-note{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 12px}.mixer-top-note h1{margin:0;font-size:26px}.mixer-top-note p{margin:3px 0 0;color:#b9cbe0}.mixer-grid{display:grid;grid-template-columns:minmax(320px,.92fr) minmax(560px,1.38fr) minmax(320px,.92fr);gap:14px}.mixer-panel{background:linear-gradient(180deg,rgba(14,28,44,.94),rgba(8,18,30,.94));border:1px solid rgba(91,140,192,.28);border-radius:18px;overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,.26)}.mixer-panel-a{border-color:rgba(255,154,18,.42)}.mixer-panel-b{border-color:rgba(42,155,255,.42)}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid rgba(91,140,192,.22)}.deck-heading{display:flex;align-items:center;gap:12px}.deck-letter{width:58px;height:58px;border-radius:14px;display:grid;place-items:center;font-size:36px;font-weight:1000;line-height:1;background:rgba(255,154,18,.13);border:1px solid rgba(255,154,18,.38);color:#ff9f1a}.mixer-panel-b .deck-letter{background:rgba(42,155,255,.13);border-color:rgba(42,155,255,.38);color:#39b5ff}.deck-heading h2{margin:0;font-size:19px}.deck-device{color:#bbcade;font-size:13px;margin-top:3px}.deck-state{border-radius:999px;padding:7px 10px;font-weight:950;font-size:12px;border:1px solid rgba(245,158,11,.45);color:#ffc247;background:rgba(245,158,11,.12)}.deck-state.playing{border-color:rgba(34,197,94,.55);color:#57ff96;background:rgba(34,197,94,.12)}.panel-body{padding:14px 16px}.device-row{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center}.device-select,.search-input{width:100%;box-sizing:border-box;background:#0b1524;color:#fff;border:1px solid rgba(96,145,205,.38);border-radius:13px;padding:12px 12px;font-weight:800}.search-input{font-size:16px}.tiny-label{font-size:12px;color:#9fb5cd;font-weight:900;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px}.loaded-card{margin-top:12px;border:1px dashed rgba(100,150,210,.34);border-radius:16px;background:rgba(255,255,255,.025);min-height:110px;padding:13px}.loaded-track{display:grid;grid-template-columns:76px 1fr;gap:12px;align-items:center}.loaded-track img,.result-row img{width:76px;height:76px;border-radius:12px;object-fit:cover}.track-title{font-size:20px;font-weight:1000;line-height:1.1}.track-artist{color:#c8d7e8;margin-top:5px}.now-bar{height:6px;border-radius:99px;background:rgba(160,180,210,.22);overflow:hidden;margin:12px 0 3px}.now-bar span{display:block;height:100%;width:28%;background:#2aa8ff}.spotify-mark{display:inline-flex;align-items:center;gap:7px;color:#41ff91;font-weight:950;margin-top:10px}.deck-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}.mixer-btn{border:1px solid rgba(100,150,210,.42);background:rgba(16,28,44,.9);color:#fff;border-radius:12px;padding:11px 12px;font-weight:1000;display:inline-flex;justify-content:center;align-items:center;gap:8px;text-decoration:none;cursor:pointer;white-space:nowrap;min-height:44px}.mixer-btn.green{border-color:#16c874;color:#42ff9a;background:rgba(22,200,116,.13)}.mixer-btn.blue{border-color:#3498ff;color:#72c0ff;background:rgba(52,152,255,.12)}.mixer-btn.orange{border-color:#ff9e16;color:#ffc455;background:rgba(255,158,22,.12)}.mixer-btn.red{border-color:#ff4655;color:#ff7780;background:rgba(255,70,85,.11)}.mixer-btn.dark{border-color:rgba(140,160,190,.32);color:#dfe8f3}.mixer-btn:disabled{opacity:.42;cursor:not-allowed}.middle-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.middle-head h1{font-size:24px;margin:0}.search-box{border:1px solid rgba(91,140,192,.22);background:rgba(255,255,255,.025);border-radius:16px;padding:12px;margin-bottom:12px}.search-row{display:grid;grid-template-columns:1fr auto;gap:10px}.search-results{margin-top:10px}.result-row{display:grid;grid-template-columns:50px 1fr auto;gap:10px;align-items:center;padding:9px;border-radius:13px;border:1px solid rgba(96,145,205,.2);background:rgba(255,255,255,.027);margin-bottom:7px}.result-row img{width:50px;height:50px;border-radius:9px}.result-title{font-weight:1000}.muted{color:#aebfd4}.mini{font-size:13px}.dj-section{border:1px solid rgba(91,140,192,.22);background:rgba(255,255,255,.025);border-radius:16px;overflow:hidden;margin-top:12px}.section-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 13px;border-bottom:1px solid rgba(91,140,192,.18)}.section-head h2{margin:0;font-size:18px}.playlist-row,.request-row{display:grid;grid-template-columns:44px 1fr auto;gap:10px;align-items:center;padding:9px 11px;border-bottom:1px solid rgba(91,140,192,.14)}.playlist-row:last-child,.request-row:last-child{border-bottom:0}.playlist-row img,.request-row img{width:44px;height:44px;border-radius:9px;object-fit:cover}.row-actions{display:flex;gap:6px;align-items:center;justify-content:flex-end;flex-wrap:wrap}.row-actions .mixer-btn{font-size:12px;padding:7px 9px;border-radius:9px;min-height:34px}.auto-btn{min-width:54px}.empty{padding:18px;color:#aebfd4}.request-badge{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;border:1px solid rgba(52,152,255,.36);background:rgba(52,152,255,.09);color:#88c8ff;font-size:12px;font-weight:900;margin-left:6px}.mixer-footer{margin-top:14px;border:1px solid rgba(91,140,192,.22);background:rgba(10,20,32,.9);border-radius:16px;padding:11px 14px;display:flex;justify-content:space-between;gap:12px;align-items:center}.spinner{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.2);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}.hidden{display:none!important}@media(max-width:1280px){.mixer-grid{grid-template-columns:1fr}.spotify-mixer-app{max-width:980px}.row-actions{justify-content:flex-start}.middle-head{align-items:flex-start;flex-direction:column}}@media(max-width:700px){.spotify-mixer-app{padding:10px}.panel-head,.mixer-top-note,.mixer-footer{align-items:flex-start;flex-direction:column}.search-row,.device-row{grid-template-columns:1fr}.playlist-row,.request-row,.result-row{grid-template-columns:44px 1fr}.row-actions{grid-column:1/-1}.deck-actions{grid-template-columns:1fr}.loaded-track{grid-template-columns:58px 1fr}.loaded-track img{width:58px;height:58px}.deck-letter{width:48px;height:48px;font-size:30px}}
</style>
<main class="spotify-mixer-app" data-api="<?= h(admin_url('spotify/mixer-api.php')) ?>">
  <div class="mixer-toast" id="mixerToast"></div>
  <div class="mixer-top-note">
    <div><h1>Spotify Mixer</h1><p>Live search and public requests feed the DJ playlist, then load safely to Player A or Player B.</p></div>
    <a class="mixer-btn blue" href="<?= h(admin_url('spotify/index.php')) ?>">Spotify Tools</a>
  </div>
  <div class="mixer-grid">
    <section class="mixer-panel mixer-panel-a">
      <div class="panel-head">
        <div class="deck-heading"><div class="deck-letter">A</div><div><h2>Player A</h2><div class="deck-device" id="deckADevice">Not assigned</div></div></div>
        <span class="deck-state" id="deckAState">Standby</span>
      </div>
      <div class="panel-body">
        <div class="tiny-label">Spotify device for A</div>
        <div class="device-row"><select class="device-select" id="deviceA"></select><button class="mixer-btn orange" data-save-devices>Save</button></div>
        <div class="loaded-card" id="loadedA"></div>
        <div class="deck-actions">
          <button class="mixer-btn green" data-deck-action="play" data-deck="a">▶ Play A</button>
          <button class="mixer-btn orange" data-deck-action="pause" data-deck="a">⏸ Pause A</button>
          <button class="mixer-btn red" data-deck-action="clear_loaded" data-deck="a">✕ Clear A</button>
        </div>
      </div>
    </section>

    <section class="mixer-panel mixer-centre">
      <div class="panel-head">
        <div><h2>Search, Requests & DJ Playlist</h2><div class="deck-device">Adding a track clears search results and returns focus to the search box.</div></div>
        <button class="mixer-btn dark" id="refreshNow">↻ Refresh</button>
      </div>
      <div class="panel-body">
        <div class="search-box">
          <div class="tiny-label">Search Spotify</div>
          <div class="search-row"><input id="spotifySearch" class="search-input" placeholder="Start typing a track, artist or album…" autocomplete="off"><button class="mixer-btn dark" id="clearSearch">Clear</button></div>
          <div id="searchStatus" class="mini muted" style="margin-top:8px"></div>
          <div class="search-results" id="searchResults"></div>
        </div>

        <section class="dj-section">
          <div class="section-head"><h2>Public Requests <span class="request-badge" id="requestCount">0</span></h2><span class="mini muted">Auto-checks every few seconds</span></div>
          <div id="publicRequests"></div>
        </section>

        <section class="dj-section">
          <div class="section-head"><h2>DJ Playlist <span class="request-badge" id="playlistCount">0</span></h2><button class="mixer-btn red" data-action="clear_playlist">Clear playlist</button></div>
          <div id="djPlaylist"></div>
        </section>
      </div>
    </section>

    <section class="mixer-panel mixer-panel-b">
      <div class="panel-head">
        <div class="deck-heading"><div class="deck-letter">B</div><div><h2>Player B</h2><div class="deck-device" id="deckBDevice">Not assigned</div></div></div>
        <span class="deck-state" id="deckBState">Standby</span>
      </div>
      <div class="panel-body">
        <div class="tiny-label">Spotify device for B</div>
        <div class="device-row"><select class="device-select" id="deviceB"></select><button class="mixer-btn blue" data-save-devices>Save</button></div>
        <div class="loaded-card" id="loadedB"></div>
        <div class="deck-actions">
          <button class="mixer-btn green" data-deck-action="play" data-deck="b">▶ Play B</button>
          <button class="mixer-btn orange" data-deck-action="pause" data-deck="b">⏸ Pause B</button>
          <button class="mixer-btn red" data-deck-action="clear_loaded" data-deck="b">✕ Clear B</button>
        </div>
      </div>
    </section>
  </div>
  <div class="mixer-footer"><div><strong>Spotify status:</strong> <span id="spotifyStatus">Checking…</span></div><div class="mini muted">Device status is polled; Spotify can lag briefly, so active players remain protected.</div></div>
</main>
<script src="<?= h(admin_url('assets/spotify-mixer.js')) ?>?v=20260522-dynamic1"></script>
<?php admin_footer(); ?>
