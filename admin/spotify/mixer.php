<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

admin_header('Spotify Mixer - DJ Portal');
?>
<style>
.spotify-mixer-app{padding:14px;max-width:1840px;margin:0 auto}.mixer-toast{position:fixed;right:18px;bottom:18px;z-index:50;max-width:420px;padding:13px 16px;border-radius:14px;border:1px solid rgba(80,140,210,.35);background:rgba(11,22,35,.96);color:#fff;box-shadow:0 18px 50px rgba(0,0,0,.38);display:none}.mixer-toast.ok{border-color:rgba(34,197,94,.65);color:#baffcf}.mixer-toast.err{border-color:rgba(255,70,85,.65);color:#ffb4bc}.mixer-top-note{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 12px}.mixer-top-note h1{margin:0;font-size:26px}.mixer-top-note p{margin:3px 0 0;color:#b9cbe0}.mixer-grid{display:grid;grid-template-columns:minmax(320px,.92fr) minmax(560px,1.38fr) minmax(320px,.92fr);gap:14px}.mixer-panel{background:linear-gradient(180deg,rgba(14,28,44,.94),rgba(8,18,30,.94));border:1px solid rgba(91,140,192,.28);border-radius:18px;overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,.26)}.mixer-panel-a{border-color:rgba(255,154,18,.42)}.mixer-panel-b{border-color:rgba(42,155,255,.42)}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid rgba(91,140,192,.22)}.deck-heading{display:flex;align-items:center;gap:12px}.deck-letter{width:58px;height:58px;border-radius:14px;display:grid;place-items:center;font-size:36px;font-weight:1000;line-height:1;background:rgba(255,154,18,.13);border:1px solid rgba(255,154,18,.38);color:#ff9f1a}.mixer-panel-b .deck-letter{background:rgba(42,155,255,.13);border-color:rgba(42,155,255,.38);color:#39b5ff}.deck-heading h2{margin:0;font-size:19px}.deck-device{color:#bbcade;font-size:13px;margin-top:3px}.deck-state{border-radius:999px;padding:7px 10px;font-weight:950;font-size:12px;border:1px solid rgba(245,158,11,.45);color:#ffc247;background:rgba(245,158,11,.12)}.deck-state.playing{border-color:rgba(34,197,94,.55);color:#57ff96;background:rgba(34,197,94,.12)}.deck-state.loaded{border-color:rgba(52,152,255,.55);color:#79c4ff;background:rgba(52,152,255,.12)}.panel-body{padding:14px 16px}.device-row{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center}.device-select,.search-input{width:100%;box-sizing:border-box;background:#0b1524;color:#fff;border:1px solid rgba(96,145,205,.38);border-radius:13px;padding:12px 12px;font-weight:800}.search-input{font-size:16px}.tiny-label{font-size:12px;color:#9fb5cd;font-weight:900;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px}.loaded-card{margin-top:12px;border:1px dashed rgba(100,150,210,.34);border-radius:16px;background:rgba(255,255,255,.025);min-height:110px;padding:13px}.loaded-track{display:grid;grid-template-columns:76px 1fr;gap:12px;align-items:center}.loaded-track img,.result-row img{width:76px;height:76px;border-radius:12px;object-fit:cover}.track-title{font-size:20px;font-weight:1000;line-height:1.1}.track-artist{color:#c8d7e8;margin-top:5px}.now-bar{height:6px;border-radius:99px;background:rgba(160,180,210,.22);overflow:hidden;margin:12px 0 3px}.now-bar span{display:block;height:100%;width:0;background:#2aa8ff;transition:width .25s ease}.now-bar.active span{background:linear-gradient(90deg,#24a7ff,#58ff99)}.track-progress-meta{display:flex;justify-content:space-between;gap:10px;margin-top:10px;color:#9fc2e9;font-size:12px;font-weight:900}.track-time-left{font-size:12px;color:#57ff96;font-weight:900;margin-top:5px}.spotify-mark{display:inline-flex;align-items:center;gap:7px;color:#41ff91;font-weight:950;margin-top:10px}.loaded-request-note-card{display:none;margin-top:10px;border:1px solid rgba(96,145,205,.26);border-radius:14px;background:rgba(255,255,255,.028);padding:10px 12px;align-items:flex-start;gap:10px}.loaded-request-note-card.visible{display:flex}.loaded-request-avatar{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;flex:0 0 34px;background:#dbeafe;color:#0f172a;font-weight:1000;font-size:15px}.loaded-request-copy{min-width:0}.loaded-request-name{font-weight:1000;color:#fff;line-height:1.15}.loaded-request-message{margin-top:3px;color:#dcecff;font-size:13px;line-height:1.25;word-break:break-word}.loaded-request-message.muted{color:#9fb5cd}.deck-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}.mixer-btn{border:1px solid rgba(100,150,210,.42);background:rgba(16,28,44,.9);color:#fff;border-radius:12px;padding:11px 12px;font-weight:1000;display:inline-flex;justify-content:center;align-items:center;gap:8px;text-decoration:none;cursor:pointer;white-space:nowrap;min-height:44px}.mixer-btn.green{border-color:#16c874;color:#42ff9a;background:rgba(22,200,116,.13)}.mixer-btn.blue{border-color:#3498ff;color:#72c0ff;background:rgba(52,152,255,.12)}.mixer-btn.orange{border-color:#ff9e16;color:#ffc455;background:rgba(255,158,22,.12)}.mixer-btn.red{border-color:#ff4655;color:#ff7780;background:rgba(255,70,85,.11)}.mixer-btn.dark{border-color:rgba(140,160,190,.32);color:#dfe8f3}.mixer-btn:disabled{opacity:.42;cursor:not-allowed}.middle-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.middle-head h1{font-size:24px;margin:0}.search-box{border:1px solid rgba(91,140,192,.22);background:rgba(255,255,255,.025);border-radius:16px;padding:12px;margin-bottom:12px}.search-row{display:grid;grid-template-columns:1fr auto;gap:10px}.search-results{margin-top:10px}.result-row{display:grid;grid-template-columns:50px 1fr auto;gap:10px;align-items:center;padding:9px;border-radius:13px;border:1px solid rgba(96,145,205,.2);background:rgba(255,255,255,.027);margin-bottom:7px}.result-row img{width:50px;height:50px;border-radius:9px}.result-title{font-weight:1000}.muted{color:#aebfd4}.mini{font-size:13px}.dj-section{border:1px solid rgba(91,140,192,.22);background:rgba(255,255,255,.025);border-radius:16px;overflow:hidden;margin-top:12px}.section-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 13px;border-bottom:1px solid rgba(91,140,192,.18)}.section-head h2{margin:0;font-size:18px}.playlist-row,.request-row{display:grid;grid-template-columns:44px 1fr auto;gap:10px;align-items:center;padding:9px 11px;border-bottom:1px solid rgba(91,140,192,.14)}.playlist-row:last-child,.request-row:last-child{border-bottom:0}.playlist-row img,.request-row img{width:44px;height:44px;border-radius:9px;object-fit:cover}.row-actions{display:flex;gap:6px;align-items:center;justify-content:flex-end;flex-wrap:wrap}.row-actions .mixer-btn{font-size:12px;padding:7px 9px;border-radius:9px;min-height:34px}.auto-btn{min-width:54px}.empty{padding:18px;color:#aebfd4}.request-badge{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;border:1px solid rgba(52,152,255,.36);background:rgba(52,152,255,.09);color:#88c8ff;font-size:12px;font-weight:900;margin-left:6px}.mixer-footer{margin-top:14px;border:1px solid rgba(91,140,192,.22);background:rgba(10,20,32,.9);border-radius:16px;padding:11px 14px;display:flex;justify-content:space-between;gap:12px;align-items:center}.spinner{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.2);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}.hidden{display:none!important}@media(max-width:1280px){.mixer-grid{grid-template-columns:1fr}.spotify-mixer-app{max-width:980px}.row-actions{justify-content:flex-start}.middle-head{align-items:flex-start;flex-direction:column}}@media(max-width:700px){.spotify-mixer-app{padding:10px}.panel-head,.mixer-top-note,.mixer-footer{align-items:flex-start;flex-direction:column}.search-row,.device-row{grid-template-columns:1fr}.playlist-row,.request-row,.result-row{grid-template-columns:44px 1fr}.row-actions{grid-column:1/-1}.deck-actions{grid-template-columns:1fr}.loaded-track{grid-template-columns:58px 1fr}.loaded-track img{width:58px;height:58px}.deck-letter{width:48px;height:48px;font-size:30px}}
.request-detail{display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap}.request-message{margin-top:4px;color:#e9f2ff}.request-time{color:#69c8ff;font-weight:900}.request-source{color:#8fa8c4;font-size:12px;margin-top:3px}.playlist-note{margin-top:3px;color:#cfe4ff}.playlist-note strong{color:#fff}.quick-actions{display:grid;grid-template-columns:1fr 1fr;gap:6px}.quick-actions .wide{grid-column:1/-1}.mixer-choice-modal{position:fixed;inset:0;z-index:80;background:rgba(0,0,0,.62);display:none;align-items:center;justify-content:center;padding:18px}.mixer-choice-modal.open{display:flex}.choice-card{width:min(620px,96vw);border-radius:20px;border:1px solid rgba(91,140,192,.42);background:linear-gradient(180deg,rgba(18,34,54,.98),rgba(8,18,30,.98));box-shadow:0 28px 90px rgba(0,0,0,.55);overflow:hidden}.choice-head{display:flex;align-items:center;gap:14px;padding:16px;border-bottom:1px solid rgba(91,140,192,.22)}.choice-head img{width:64px;height:64px;border-radius:12px;object-fit:cover}.choice-title{font-size:20px;font-weight:1000;line-height:1.12}.choice-artist{color:#c8d7e8;margin-top:4px}.choice-body{padding:16px}.choice-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.choice-grid .mixer-btn{min-height:56px;font-size:15px}.choice-grid .full{grid-column:1/-1}.choice-warning{margin-top:10px;color:#ffc455;font-size:13px}.choice-close{margin-left:auto}.mixer-btn.choice-disabled,.mixer-btn.choice-disabled:disabled{opacity:.34;cursor:not-allowed;filter:grayscale(.25)}.row-actions .mixer-mini-action{min-width:38px;width:38px;height:34px;min-height:34px;padding:0;font-size:15px;line-height:1}.row-actions .auto-btn{font-size:18px}.row-actions .mixer-mini-action[disabled]{opacity:.28;filter:grayscale(.5);cursor:not-allowed;box-shadow:none}
.deck-status-wrap{display:flex;align-items:center;gap:9px}.deck-vu{display:none;align-items:flex-end;gap:3px;height:22px;min-width:34px}.deck-vu span{display:block;width:4px;border-radius:999px;background:linear-gradient(180deg,#58ff99,#24a7ff);box-shadow:0 0 10px rgba(88,255,153,.45);animation:deckvu .72s ease-in-out infinite}.mixer-panel-a .deck-vu span{background:linear-gradient(180deg,#ffe066,#ff9f1a);box-shadow:0 0 10px rgba(255,159,26,.5)}.deck-vu span:nth-child(2){animation-delay:.12s}.deck-vu span:nth-child(3){animation-delay:.24s}.deck-vu span:nth-child(4){animation-delay:.36s}.deck-vu span:nth-child(5){animation-delay:.18s}.mixer-panel.deck-playing .deck-vu{display:flex}.mixer-panel.device-missing{border-color:rgba(255,70,85,.9);box-shadow:0 0 0 1px rgba(255,70,85,.35),0 0 28px rgba(255,70,85,.28)}.mixer-btn.device-alert{border-color:#ff4655!important;color:#fff!important;background:rgba(255,70,85,.2)!important;animation:devicealert 1s ease-in-out infinite}.mixer-btn.device-alert::before{content:'⚠';font-size:14px}.deck-device.device-alert-text{color:#ff9aa2;font-weight:950}@keyframes deckvu{0%,100%{height:5px;opacity:.55}45%{height:21px;opacity:1}}@keyframes devicealert{0%,100%{box-shadow:0 0 0 0 rgba(255,70,85,.2);filter:none}50%{box-shadow:0 0 0 4px rgba(255,70,85,.22),0 0 22px rgba(255,70,85,.7);filter:brightness(1.35)}}

/* Real transport controls */
.deck-actions{display:block;margin-top:14px}.transport-controls{display:grid;grid-template-columns:1fr 1fr 1.35fr 1fr 1fr;gap:8px;align-items:stretch;margin-top:14px}.transport-btn{min-height:72px;border-radius:14px;flex-direction:column;gap:5px;font-size:18px;line-height:1.05}.transport-btn small{display:block;font-size:10px;letter-spacing:.035em;text-transform:uppercase;color:#c8d7e8}.transport-play{width:96px;height:96px;min-height:96px;border-radius:999px;justify-self:center;font-size:32px;letter-spacing:-6px;padding-right:12px}.transport-play.transport-ready{border-color:#ffb11a;color:#fff;background:radial-gradient(circle,rgba(255,177,26,.28),rgba(255,177,26,.08));box-shadow:0 0 0 0 rgba(255,177,26,.26),0 0 26px rgba(255,177,26,.25);animation:transportReady 1.05s ease-in-out infinite}.transport-play.transport-playing{border-color:#16c874;color:#fff;background:radial-gradient(circle,rgba(22,200,116,.28),rgba(22,200,116,.08));box-shadow:0 0 0 3px rgba(22,200,116,.14),0 0 28px rgba(22,200,116,.38)}.transport-under{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}.transport-eject{min-height:52px}.transport-swap{min-height:52px;border-color:#ff9e16;color:#ffc455;background:rgba(255,158,22,.11)}@keyframes transportReady{0%,100%{box-shadow:0 0 0 0 rgba(255,177,26,.2),0 0 16px rgba(255,177,26,.3);filter:brightness(.95)}50%{box-shadow:0 0 0 5px rgba(255,177,26,.22),0 0 34px rgba(255,177,26,.72);filter:brightness(1.2)}}@media(max-width:700px){.transport-controls{grid-template-columns:repeat(5,minmax(54px,1fr));gap:6px}.transport-btn{min-height:62px;font-size:15px}.transport-play{width:78px;height:78px;min-height:78px;font-size:27px}.transport-under{grid-template-columns:1fr}.transport-btn small{font-size:9px}}

</style>
<main class="spotify-mixer-app" data-api="<?= h(admin_url('spotify/mixer-api.php')) ?>">
  <div class="mixer-toast" id="mixerToast"></div>
  <div class="mixer-choice-modal" id="mixerChoiceModal" aria-hidden="true">
    <div class="choice-card" role="dialog" aria-modal="true" aria-labelledby="choiceTitle">
      <div class="choice-head">
        <img id="choiceImage" src="https://dancethruthedecades.co.uk/assets/glitter-ball-clean.png" alt="">
        <div><div class="choice-title" id="choiceTitle">Choose action</div><div class="choice-artist" id="choiceArtist"></div></div>
        <button class="mixer-btn dark choice-close" id="choiceCancel" type="button">Cancel</button>
      </div>
      <div class="choice-body">
        <div class="choice-grid" id="choiceActions"></div>
        <div class="choice-warning" id="choiceWarning"></div>
      </div>
    </div>
  </div>

  <div class="mixer-top-note">
    <div><h1>Spotify Mixer</h1><p>Live search and public requests feed the DJ playlist, then load safely to Player A or Player B.</p></div>
    <a class="mixer-btn blue" href="<?= h(admin_url('spotify/index.php')) ?>">Spotify Tools</a>
  </div>
  <div class="mixer-grid">
    <section class="mixer-panel mixer-panel-a">
      <div class="panel-head">
        <div class="deck-heading"><div class="deck-letter">A</div><div><h2>Player A</h2><div class="deck-device" id="deckADevice">Not assigned</div></div></div>
        <div class="deck-status-wrap"><div class="deck-vu" id="deckAVu" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div><span class="deck-state" id="deckAState">Standby</span></div>
      </div>
      <div class="panel-body">
        <div class="tiny-label">Spotify device for A</div>
        <div class="device-row"><select class="device-select" id="deviceA"></select><button class="mixer-btn orange" data-save-devices data-save-deck="a">Save</button></div>
        <div class="loaded-card" id="loadedA"></div>
        <div class="deck-actions transport-area" data-transport-deck="a">
          <div class="transport-controls">
            <button class="mixer-btn dark transport-btn" data-deck-action="seek_start" data-deck="a" title="Go to start">|◀<small>To start</small></button>
            <button class="mixer-btn dark transport-btn" data-deck-action="seek_back" data-deck="a" title="Rewind 30 seconds">◀◀ 30<small>-30 sec</small></button>
            <button class="mixer-btn transport-play" data-deck-action="play_toggle" data-deck="a" title="Play / Pause">▶❚❚</button>
            <button class="mixer-btn dark transport-btn" data-deck-action="seek_forward" data-deck="a" title="Forward 30 seconds">30 ▶▶<small>+30 sec</small></button>
            <button class="mixer-btn dark transport-btn" data-deck-action="seek_end" data-deck="a" title="Go to end">▶|<small>To end</small></button>
          </div>
          <div class="transport-under">
            <button class="mixer-btn red transport-eject" data-deck-action="clear_loaded" data-deck="a">⏏ Eject A</button>
            <button class="mixer-btn orange transport-swap" data-deck-action="emergency_swap" data-deck="a">⇄ Swap to B<small>Emergency</small></button>
          </div>
        </div>
        <div class="loaded-request-note-card" id="deckANote"></div>
      </div>
    </section>

    <section class="mixer-panel mixer-centre">
      <div class="panel-body">
        <div class="search-box">
          <div class="tiny-label">Search Spotify</div>
          <div class="search-row"><input id="spotifySearch" class="search-input" placeholder="Start typing a track, artist or album…" autocomplete="off"><button class="mixer-btn dark" id="clearSearch">Clear</button></div>
          <div id="searchStatus" class="mini muted" style="margin-top:8px"></div>
          <div class="search-results" id="searchResults"></div>
        </div>

        <section class="dj-section">
          <div class="section-head"><h2>DJ Playlist <span class="request-badge" id="playlistCount">0</span></h2><button class="mixer-btn red" data-action="clear_playlist">Clear playlist</button></div>
          <div id="djPlaylist"></div>
        </section>

        <section class="dj-section">
          <div class="section-head"><h2>Public Requests <span class="request-badge" id="requestCount">0</span></h2><span class="mini muted">Live feed from the public request queue</span></div>
          <div id="publicRequests"></div>
        </section>
      </div>
    </section>

    <section class="mixer-panel mixer-panel-b">
      <div class="panel-head">
        <div class="deck-heading"><div class="deck-letter">B</div><div><h2>Player B</h2><div class="deck-device" id="deckBDevice">Not assigned</div></div></div>
        <div class="deck-status-wrap"><div class="deck-vu" id="deckBVu" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div><span class="deck-state" id="deckBState">Standby</span></div>
      </div>
      <div class="panel-body">
        <div class="tiny-label">Spotify device for B</div>
        <div class="device-row"><select class="device-select" id="deviceB"></select><button class="mixer-btn blue" data-save-devices data-save-deck="b">Save</button></div>
        <div class="loaded-card" id="loadedB"></div>
        <div class="deck-actions transport-area" data-transport-deck="b">
          <div class="transport-controls">
            <button class="mixer-btn dark transport-btn" data-deck-action="seek_start" data-deck="b" title="Go to start">|◀<small>To start</small></button>
            <button class="mixer-btn dark transport-btn" data-deck-action="seek_back" data-deck="b" title="Rewind 30 seconds">◀◀ 30<small>-30 sec</small></button>
            <button class="mixer-btn transport-play" data-deck-action="play_toggle" data-deck="b" title="Play / Pause">▶❚❚</button>
            <button class="mixer-btn dark transport-btn" data-deck-action="seek_forward" data-deck="b" title="Forward 30 seconds">30 ▶▶<small>+30 sec</small></button>
            <button class="mixer-btn dark transport-btn" data-deck-action="seek_end" data-deck="b" title="Go to end">▶|<small>To end</small></button>
          </div>
          <div class="transport-under">
            <button class="mixer-btn red transport-eject" data-deck-action="clear_loaded" data-deck="b">⏏ Eject B</button>
            <button class="mixer-btn orange transport-swap" data-deck-action="emergency_swap" data-deck="b">⇄ Swap to A<small>Emergency</small></button>
          </div>
        </div>
        <div class="loaded-request-note-card" id="deckBNote"></div>
      </div>
    </section>
  </div>
  <div class="mixer-footer"><div><strong>Spotify status:</strong> <span id="spotifyStatus">Checking…</span></div><div class="mini muted">Device status is polled; Spotify can lag briefly, so active players remain protected.</div></div>
</main>
<script src="<?= h(admin_url('assets/spotify-mixer.js')) ?>?v=20260523-real-transport-controls-v1"></script>
<?php admin_footer(); ?>
