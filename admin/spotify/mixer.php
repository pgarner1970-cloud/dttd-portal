<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

admin_header('Spotify Mixer - DJ Portal');
?>
<style>
.spotify-mixer-app{padding:14px;max-width:1840px;margin:0 auto}.mixer-toast{position:fixed;right:18px;bottom:18px;z-index:50;max-width:420px;padding:13px 16px;border-radius:14px;border:1px solid rgba(80,140,210,.35);background:rgba(11,22,35,.96);color:#fff;box-shadow:0 18px 50px rgba(0,0,0,.38);display:none}.mixer-toast.ok{border-color:rgba(34,197,94,.65);color:#baffcf}.mixer-toast.err{border-color:rgba(255,70,85,.65);color:#ffb4bc}.mixer-top-note{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 12px}.mixer-top-note h1{margin:0;font-size:26px}.mixer-top-note p{margin:3px 0 0;color:#b9cbe0}.mixer-grid{display:grid;grid-template-columns:minmax(320px,.92fr) minmax(560px,1.38fr) minmax(320px,.92fr);gap:14px}.mixer-panel{background:linear-gradient(180deg,rgba(14,28,44,.94),rgba(8,18,30,.94));border:1px solid rgba(91,140,192,.28);border-radius:18px;overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,.26)}.mixer-panel-a{border-color:rgba(255,154,18,.42)}.mixer-panel-b{border-color:rgba(42,155,255,.42)}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid rgba(91,140,192,.22)}.deck-heading{display:flex;align-items:center;gap:12px}.deck-letter{width:58px;height:58px;border-radius:14px;display:grid;place-items:center;font-size:36px;font-weight:1000;line-height:1;background:rgba(255,154,18,.13);border:1px solid rgba(255,154,18,.38);color:#ff9f1a}.mixer-panel-b .deck-letter{background:rgba(42,155,255,.13);border-color:rgba(42,155,255,.38);color:#39b5ff}.deck-heading h2{margin:0;font-size:19px}.deck-device{color:#bbcade;font-size:13px;margin-top:3px}.deck-state{border-radius:999px;padding:7px 10px;font-weight:950;font-size:12px;border:1px solid rgba(245,158,11,.45);color:#ffc247;background:rgba(245,158,11,.12)}.deck-state.playing{border-color:rgba(34,197,94,.55);color:#57ff96;background:rgba(34,197,94,.12)}.deck-state.loaded{border-color:rgba(52,152,255,.55);color:#79c4ff;background:rgba(52,152,255,.12)}.workflow-badge.preparing{border-color:rgba(255,70,85,.6);color:#ff9ba2;background:rgba(255,70,85,.13)}.panel-body{padding:14px 16px}.device-row{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center}.device-select,.search-input{width:100%;box-sizing:border-box;background:#0b1524;color:#fff;border:1px solid rgba(96,145,205,.38);border-radius:13px;padding:12px 12px;font-weight:800}.search-input{font-size:16px}.tiny-label{font-size:12px;color:#9fb5cd;font-weight:900;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px}.loaded-card{margin-top:12px;border:1px dashed rgba(100,150,210,.34);border-radius:16px;background:rgba(255,255,255,.025);min-height:110px;padding:13px}.loaded-track{display:grid;grid-template-columns:76px 1fr;gap:12px;align-items:center}.loaded-track img,.result-row img{width:76px;height:76px;border-radius:12px;object-fit:cover}.track-title{font-size:20px;font-weight:1000;line-height:1.1}.track-artist{color:#c8d7e8;margin-top:5px}.now-bar{height:6px;border-radius:99px;background:rgba(160,180,210,.22);overflow:hidden;margin:12px 0 3px}.now-bar span{display:block;height:100%;width:0;background:#2aa8ff;transition:width .25s ease}.now-bar.active span{background:linear-gradient(90deg,#24a7ff,#58ff99)}.track-progress-meta{display:flex;justify-content:space-between;gap:10px;margin-top:10px;color:#9fc2e9;font-size:12px;font-weight:900}.track-time-left{font-size:12px;color:#57ff96;font-weight:900;margin-top:5px}.spotify-mark{display:inline-flex;align-items:center;gap:7px;color:#41ff91;font-weight:950;margin-top:10px}.loaded-request-note-card{display:none;margin-top:10px;border:1px solid rgba(96,145,205,.26);border-radius:14px;background:rgba(255,255,255,.028);padding:10px 12px;align-items:flex-start;gap:10px}.loaded-request-note-card.visible{display:flex}.loaded-request-avatar{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;flex:0 0 34px;background:#dbeafe;color:#0f172a;font-weight:1000;font-size:15px}.loaded-request-copy{min-width:0}.loaded-request-name{font-weight:1000;color:#fff;line-height:1.15}.loaded-request-message{margin-top:3px;color:#dcecff;font-size:13px;line-height:1.25;word-break:break-word}.loaded-request-message.muted{color:#9fb5cd}.deck-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}.mixer-btn{border:1px solid rgba(100,150,210,.42);background:rgba(16,28,44,.9);color:#fff;border-radius:12px;padding:11px 12px;font-weight:1000;display:inline-flex;justify-content:center;align-items:center;gap:8px;text-decoration:none;cursor:pointer;white-space:nowrap;min-height:44px}.mixer-btn.green{border-color:#16c874;color:#42ff9a;background:rgba(22,200,116,.13)}.mixer-btn.blue{border-color:#3498ff;color:#72c0ff;background:rgba(52,152,255,.12)}.mixer-btn.orange{border-color:#ff9e16;color:#ffc455;background:rgba(255,158,22,.12)}.mixer-btn.red{border-color:#ff4655;color:#ff7780;background:rgba(255,70,85,.11)}.mixer-btn.dark{border-color:rgba(140,160,190,.32);color:#dfe8f3}.mixer-btn:disabled{opacity:.42;cursor:not-allowed}.middle-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.middle-head h1{font-size:24px;margin:0}.search-box{border:1px solid rgba(91,140,192,.22);background:rgba(255,255,255,.025);border-radius:16px;padding:12px;margin-bottom:12px}.search-row{display:grid;grid-template-columns:1fr auto;gap:10px}.search-results{margin-top:10px}.result-row{display:grid;grid-template-columns:50px 1fr auto;gap:10px;align-items:center;padding:9px;border-radius:13px;border:1px solid rgba(96,145,205,.2);background:rgba(255,255,255,.027);margin-bottom:7px}.result-row img{width:50px;height:50px;border-radius:9px}.result-title{font-weight:1000}.muted{color:#aebfd4}.mini{font-size:13px}.dj-section{border:1px solid rgba(91,140,192,.22);background:rgba(255,255,255,.025);border-radius:16px;overflow:hidden;margin-top:12px}.section-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 13px;border-bottom:1px solid rgba(91,140,192,.18)}.section-head h2{margin:0;font-size:18px}.playlist-row,.request-row{display:grid;grid-template-columns:44px 1fr auto;gap:10px;align-items:center;padding:9px 11px;border-bottom:1px solid rgba(91,140,192,.14)}.playlist-row:last-child,.request-row:last-child{border-bottom:0}.playlist-row img,.request-row img{width:44px;height:44px;border-radius:9px;object-fit:cover}.row-actions{display:flex;gap:6px;align-items:center;justify-content:flex-end;flex-wrap:wrap}.row-actions .mixer-btn{font-size:12px;padding:7px 9px;border-radius:9px;min-height:34px}.auto-btn{min-width:54px}.empty{padding:18px;color:#aebfd4}.request-badge{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;border:1px solid rgba(52,152,255,.36);background:rgba(52,152,255,.09);color:#88c8ff;font-size:12px;font-weight:900;margin-left:6px}.mixer-footer{margin-top:14px;border:1px solid rgba(91,140,192,.22);background:rgba(10,20,32,.9);border-radius:16px;padding:11px 14px;display:flex;justify-content:space-between;gap:12px;align-items:center}.spinner{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.2);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}.deck-account{margin-top:4px;color:#9fc8ff;font-size:12px;font-weight:900}.deck-account.warning{color:#ffbe55}.mixer-mode-pill{display:inline-flex;align-items:center;border:1px solid rgba(96,145,205,.35);background:rgba(52,152,255,.1);color:#a8d7ff;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:1000;margin-left:8px}.mixer-mode-pill.duo{border-color:rgba(34,197,94,.48);background:rgba(34,197,94,.12);color:#8dffbb}.mixer-mode-pill.warn{border-color:rgba(245,158,11,.55);background:rgba(245,158,11,.14);color:#ffd178}.deck-warning-note{display:none;margin-top:8px;border:1px solid rgba(245,158,11,.45);background:rgba(245,158,11,.11);color:#ffd178;border-radius:12px;padding:8px 10px;font-size:12px;font-weight:900}.deck-warning-note.visible{display:block}.search-result-row{position:relative;grid-template-columns:50px 1fr auto auto}.search-result-badges{display:flex;gap:5px;align-items:flex-start;justify-content:flex-end;flex-wrap:wrap;max-width:132px}.search-result-badge{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;font-size:10px;font-weight:1000;text-transform:uppercase;letter-spacing:.035em;border:1px solid rgba(148,163,184,.45);color:#dbeafe;background:rgba(148,163,184,.1)}.search-result-badge.decade{border-color:rgba(236,72,153,.65);background:rgba(236,72,153,.18);color:#ffd6f0}.search-result-badge.original{border-color:rgba(34,197,94,.72);background:rgba(34,197,94,.14);color:#8dffbb}.search-result-badge.original-era,.search-result-badge.remaster{border-color:rgba(59,130,246,.66);background:rgba(59,130,246,.13);color:#aad1ff}.search-result-badge.live,.search-result-badge.karaoke,.search-result-badge.cover{border-color:rgba(248,113,113,.68);background:rgba(248,113,113,.12);color:#ffc4c4}.search-result-badge.remix,.search-result-badge.acoustic,.search-result-badge.instrumental{border-color:rgba(168,85,247,.66);background:rgba(168,85,247,.13);color:#e6ccff}.search-result-badge.compilation,.search-result-badge.soundtrack{border-color:rgba(245,158,11,.65);background:rgba(245,158,11,.13);color:#ffd68a}.search-result-badge.spotify{border-color:rgba(34,197,94,.72);background:rgba(34,197,94,.13);color:#8dffbb}.search-result-badge.local{border-color:rgba(251,191,36,.78);background:rgba(251,191,36,.15);color:#ffe7a6}.search-result-badge.review{border-color:rgba(248,113,113,.72);background:rgba(248,113,113,.14);color:#ffc4c4}.search-result-badge.matched{border-color:rgba(52,152,255,.68);background:rgba(52,152,255,.14);color:#bde1ff}
.hidden{display:none!important}@media(max-width:1280px){.mixer-grid{grid-template-columns:1fr}.spotify-mixer-app{max-width:980px}.row-actions{justify-content:flex-start}.middle-head{align-items:flex-start;flex-direction:column}}@media(max-width:700px){.search-result-row{grid-template-columns:44px 1fr}.search-result-badges{grid-column:2/-1;justify-content:flex-start}.spotify-mixer-app{padding:10px}.panel-head,.mixer-top-note,.mixer-footer{align-items:flex-start;flex-direction:column}.search-row,.device-row{grid-template-columns:1fr}.playlist-row,.request-row,.result-row{grid-template-columns:44px 1fr}.row-actions{grid-column:1/-1}.deck-actions{grid-template-columns:1fr}.loaded-track{grid-template-columns:58px 1fr}.loaded-track img{width:58px;height:58px}.deck-letter{width:48px;height:48px;font-size:30px}}
.request-detail{display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap}.request-message{margin-top:4px;color:#e9f2ff}.request-time{color:#69c8ff;font-weight:900}.request-source{color:#8fa8c4;font-size:12px;margin-top:3px}.playlist-note{margin-top:3px;color:#cfe4ff}.playlist-note strong{color:#fff}.quick-actions{display:grid;grid-template-columns:1fr 1fr;gap:6px}.quick-actions .wide{grid-column:1/-1}.mixer-choice-modal{position:fixed;inset:0;z-index:80;background:rgba(0,0,0,.62);display:none;align-items:center;justify-content:center;padding:18px}.mixer-choice-modal.open{display:flex}.choice-card{width:min(620px,96vw);border-radius:20px;border:1px solid rgba(91,140,192,.42);background:linear-gradient(180deg,rgba(18,34,54,.98),rgba(8,18,30,.98));box-shadow:0 28px 90px rgba(0,0,0,.55);overflow:hidden}.choice-head{display:flex;align-items:center;gap:14px;padding:16px;border-bottom:1px solid rgba(91,140,192,.22)}.choice-head img{width:64px;height:64px;border-radius:12px;object-fit:cover}.choice-title{font-size:20px;font-weight:1000;line-height:1.12}.choice-artist{color:#c8d7e8;margin-top:4px}.choice-body{padding:16px}.choice-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.choice-grid .mixer-btn{min-height:56px;font-size:15px}.choice-grid .full{grid-column:1/-1}.choice-warning{margin-top:10px;color:#ffc455;font-size:13px}.choice-close{margin-left:auto}.choice-crate-save{border:1px solid rgba(58,151,255,.55);border-radius:14px;padding:10px;background:rgba(12,48,86,.28)}.choice-crate-save label{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#9fc8ff;margin-bottom:7px;font-weight:900}.choice-crate-row{display:grid;grid-template-columns:1fr auto;gap:8px}.choice-crate-row .mixer-select{width:100%;min-height:46px;border-radius:12px;border:1px solid rgba(91,140,192,.45);background:#071323;color:#fff;font-weight:900;padding:0 12px}.choice-crate-row .mixer-btn{min-height:46px;white-space:nowrap}.mixer-btn.choice-disabled,.mixer-btn.choice-disabled:disabled{opacity:.34;cursor:not-allowed;filter:grayscale(.25)}.row-actions .mixer-mini-action{min-width:38px;width:38px;height:34px;min-height:34px;padding:0;font-size:15px;line-height:1}.row-actions .auto-btn{font-size:18px}.row-actions .mixer-mini-action[disabled]{opacity:.28;filter:grayscale(.5);cursor:not-allowed;box-shadow:none}
.deck-status-wrap{display:flex;align-items:center;gap:9px}.deck-vu{display:none;align-items:flex-end;gap:3px;height:22px;min-width:34px}.deck-vu span{display:block;width:4px;border-radius:999px;background:linear-gradient(180deg,#58ff99,#24a7ff);box-shadow:0 0 10px rgba(88,255,153,.45);animation:deckvu .72s ease-in-out infinite}.mixer-panel-a .deck-vu span{background:linear-gradient(180deg,#ffe066,#ff9f1a);box-shadow:0 0 10px rgba(255,159,26,.5)}.deck-vu span:nth-child(2){animation-delay:.12s}.deck-vu span:nth-child(3){animation-delay:.24s}.deck-vu span:nth-child(4){animation-delay:.36s}.deck-vu span:nth-child(5){animation-delay:.18s}.mixer-panel.deck-playing .deck-vu{display:flex}.mixer-panel.device-missing{border-color:rgba(255,70,85,.9);box-shadow:0 0 0 1px rgba(255,70,85,.35),0 0 28px rgba(255,70,85,.28)}.mixer-btn.device-alert{border-color:#ff4655!important;color:#fff!important;background:rgba(255,70,85,.2)!important;animation:devicealert 1s ease-in-out infinite}.mixer-btn.device-alert::before{content:'⚠';font-size:14px}.deck-device.device-alert-text{color:#ff9aa2;font-weight:950}@keyframes deckvu{0%,100%{height:5px;opacity:.55}45%{height:21px;opacity:1}}@keyframes devicealert{0%,100%{box-shadow:0 0 0 0 rgba(255,70,85,.2);filter:none}50%{box-shadow:0 0 0 4px rgba(255,70,85,.22),0 0 22px rgba(255,70,85,.7);filter:brightness(1.35)}}

/* Real transport controls */
.deck-actions{display:block;margin-top:14px}.transport-controls{display:grid;grid-template-columns:1fr 1fr 1.35fr 1fr 1fr;gap:8px;align-items:stretch;margin-top:14px}.transport-btn{min-height:72px;border-radius:14px;flex-direction:column;gap:5px;font-size:18px;line-height:1.05}.transport-btn small{display:block;font-size:10px;letter-spacing:.035em;text-transform:uppercase;color:#c8d7e8}.transport-play{width:96px;height:96px;min-height:96px;border-radius:999px;justify-self:center;font-size:32px;letter-spacing:-6px;padding-right:12px}.transport-play.transport-ready{border-color:#ffb11a;color:#fff;background:radial-gradient(circle,rgba(255,177,26,.28),rgba(255,177,26,.08));box-shadow:0 0 0 0 rgba(255,177,26,.26),0 0 26px rgba(255,177,26,.25);animation:transportReady 1.05s ease-in-out infinite}.transport-play.transport-preparing{border-color:#ff4655;color:#fff;background:radial-gradient(circle,rgba(255,70,85,.34),rgba(255,70,85,.1));box-shadow:0 0 0 0 rgba(255,70,85,.28),0 0 28px rgba(255,70,85,.3);animation:transportPreparing .62s ease-in-out infinite}.transport-play.transport-playing{border-color:#16c874;color:#fff;background:radial-gradient(circle,rgba(22,200,116,.28),rgba(22,200,116,.08));box-shadow:0 0 0 3px rgba(22,200,116,.14),0 0 28px rgba(22,200,116,.38)}.transport-under{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}.transport-eject{min-height:52px}.transport-swap{min-height:52px;border-color:#ff9e16;color:#ffc455;background:rgba(255,158,22,.11)}@keyframes transportReady{0%,100%{box-shadow:0 0 0 0 rgba(255,177,26,.2),0 0 16px rgba(255,177,26,.3);filter:brightness(.95)}50%{box-shadow:0 0 0 5px rgba(255,177,26,.22),0 0 34px rgba(255,177,26,.72);filter:brightness(1.2)}}@keyframes transportPreparing{0%,100%{box-shadow:0 0 0 0 rgba(255,70,85,.18),0 0 14px rgba(255,70,85,.34);filter:brightness(.9)}50%{box-shadow:0 0 0 6px rgba(255,70,85,.28),0 0 36px rgba(255,70,85,.82);filter:brightness(1.28)}}@media(max-width:700px){.transport-controls{grid-template-columns:repeat(5,minmax(54px,1fr));gap:6px}.transport-btn{min-height:62px;font-size:15px}.transport-play{width:78px;height:78px;min-height:78px;font-size:27px}.transport-under{grid-template-columns:1fr}.transport-btn small{font-size:9px}}


.source-tabs{display:flex;gap:8px;align-items:center;margin-bottom:10px}.source-tab{border:1px solid rgba(96,145,205,.35);background:rgba(16,28,44,.92);color:#cfe4ff;border-radius:11px;padding:9px 11px;font-weight:1000;cursor:pointer}.source-tab.active{border-color:#3498ff;color:#fff;background:rgba(52,152,255,.17);box-shadow:0 0 18px rgba(52,152,255,.16)}.source-panel{display:none}.source-panel.active{display:block}.spotify-playlist-row,.history-row{display:grid;grid-template-columns:44px 1fr auto;gap:10px;align-items:center;padding:9px;border-radius:13px;border:1px solid rgba(96,145,205,.2);background:rgba(255,255,255,.027);margin-top:7px}.spotify-playlist-row img,.history-row img{width:44px;height:44px;border-radius:9px;object-fit:cover}.source-list{margin-top:8px}.history-meta{color:#9fc2e9;font-size:12px;margin-top:3px;font-weight:800}.source-tools{display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:8px}.source-tools .mixer-btn{min-height:36px;padding:7px 10px;font-size:12px}.crate-create-row{display:grid;grid-template-columns:1fr auto;gap:8px;margin:8px 0 10px}.crate-icon{width:44px;height:44px;border-radius:9px;display:grid;place-items:center;background:rgba(52,152,255,.15);border:1px solid rgba(52,152,255,.28);font-size:21px;color:#bfe1ff}.active-crate{border-color:rgba(255,193,7,.55)!important;box-shadow:0 0 16px rgba(255,193,7,.12)}@media(max-width:700px){.source-tabs{flex-wrap:wrap}.spotify-playlist-row,.history-row{grid-template-columns:44px 1fr}.spotify-playlist-row .row-actions,.history-row .row-actions{grid-column:1/-1;justify-content:flex-start}}


.workflow-row{display:flex;flex-wrap:wrap;gap:5px;margin-top:6px;align-items:center}.workflow-badge{display:inline-flex;align-items:center;border-radius:999px;padding:3px 7px;font-size:11px;font-weight:1000;line-height:1;border:1px solid rgba(150,170,200,.28);background:rgba(150,170,200,.08);color:#dbeafe;text-transform:uppercase;letter-spacing:.025em}.workflow-badge.loaded{border-color:rgba(52,152,255,.45);background:rgba(52,152,255,.11);color:#9bd3ff}.workflow-badge.playing{border-color:rgba(34,197,94,.55);background:rgba(34,197,94,.13);color:#89ffb8}.workflow-badge.played{border-color:rgba(168,85,247,.55);background:rgba(168,85,247,.13);color:#d9b5ff}.workflow-badge.progress{border-color:rgba(245,158,11,.55);background:rgba(245,158,11,.12);color:#ffd37a}.workflow-badge.queued{border-color:rgba(34,197,94,.42);background:rgba(34,197,94,.09);color:#afffce}.workflow-badge.waiting{border-color:rgba(255,177,26,.5);background:rgba(255,177,26,.11);color:#ffd270}.workflow-badge.source{border-color:rgba(96,145,205,.32);background:rgba(96,145,205,.08);color:#b8d8ff}.transport-manual{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px}.transport-manual .mixer-btn{min-height:44px;font-size:12px}.mixer-btn.purple{border-color:#a855f7;color:#d9b5ff;background:rgba(168,85,247,.12)}@media(max-width:700px){.transport-manual{grid-template-columns:1fr}}


.playlist-row.grouped-playlist-row,.request-row.has-multiple-requests{align-items:flex-start}.playlist-request-summary{display:flex;flex-wrap:wrap;gap:6px;margin-top:5px;color:#cfe4ff;font-weight:900}.playlist-request-summary span:first-child{color:#fff}.mixer-request-note-list{display:grid;gap:6px;margin-top:7px}.mixer-request-note{display:grid;grid-template-columns:28px 1fr;gap:8px;align-items:flex-start;border:1px solid rgba(96,145,205,.22);background:rgba(255,255,255,.028);border-radius:11px;padding:7px 8px}.loaded-request-avatar.small{width:28px;height:28px;flex-basis:28px;font-size:12px}.mixer-request-note-copy{min-width:0}.mixer-request-note-name{display:flex;gap:7px;align-items:center;flex-wrap:wrap;line-height:1.1}.mixer-request-note-name span{color:#69c8ff;font-size:11px;font-weight:900}.mixer-request-note-message{margin-top:3px;color:#e9f2ff;font-size:12px;line-height:1.3;white-space:normal;overflow:visible;text-overflow:clip;word-break:break-word}.mixer-request-note-message.muted,.mixer-request-note-list.empty-notes{color:#9fb5cd}.loaded-request-note-card.has-request-list.visible{display:block}.loaded-request-notes-head{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#9fc8ff;font-weight:1000;margin-bottom:7px}.deck-request-note-list{margin-top:0}.deck-request-note-list .mixer-request-note{background:rgba(12,26,42,.58);border-color:rgba(96,145,205,.28)}.playlist-request-note-list{max-width:100%}.public-request-note-list{max-width:100%}@media(max-width:700px){.mixer-request-note{grid-template-columns:26px 1fr}.loaded-request-avatar.small{width:26px;height:26px;flex-basis:26px}.playlist-request-summary{font-size:12px}}

.deck-node{color:#9fe7ff;font-size:12px;margin-top:3px;font-weight:850}.deck-node.online{color:#74ff9b}.deck-node.warning{color:#ffc55a}.deck-node.offline{color:#ff9ca3}


/* Stage 1 music library overlay: source browser moves out of centre column */
.music-library-launch{border:1px solid rgba(91,140,192,.28);background:linear-gradient(180deg,rgba(11,25,44,.72),rgba(8,18,30,.72));border-radius:16px;padding:12px;margin-bottom:14px}.music-library-launch .full{width:100%;min-height:46px;font-size:15px}.music-library-launch .mini{margin-top:8px;text-align:center}.music-library-modal{position:fixed;inset:0;z-index:70;background:rgba(0,0,0,.64);display:none;align-items:center;justify-content:center;padding:18px}.music-library-modal.open{display:flex}.music-library-shell{width:min(1180px,96vw);height:min(860px,94vh);display:flex;flex-direction:column;border-radius:22px;border:1px solid rgba(91,140,192,.46);background:linear-gradient(180deg,rgba(18,34,54,.99),rgba(8,18,30,.99));box-shadow:0 30px 100px rgba(0,0,0,.62);overflow:hidden}.music-library-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:13px 18px;border-bottom:1px solid rgba(91,140,192,.24)}.music-library-head h2{margin:1px 0 0;font-size:27px;line-height:1.02}.music-library-body{flex:1;min-height:0;overflow:hidden;padding:12px 16px 16px}.music-library-body .source-box{height:100%;display:flex;flex-direction:column;margin:0}.music-library-body .source-tabs{margin-bottom:8px}.music-library-body .source-panel.active{display:flex;flex-direction:column;min-height:0}.music-library-body .music-library-search-row{display:grid;grid-template-columns:minmax(260px,1fr) auto;align-items:center;gap:9px}.music-library-body .music-library-search-row .search-input{min-height:50px}.music-library-body .music-library-search-row .search-mode-row{margin:0;flex-wrap:nowrap}.clear-search-x{width:48px;min-width:48px;height:34px;min-height:34px;padding:0;font-size:22px;line-height:1}.music-library-body #searchStatus{margin-top:5px!important;min-height:16px}.music-library-body .search-results{display:grid;grid-template-columns:1fr 1fr;gap:8px;align-content:start;min-height:0;overflow:hidden}.music-library-body .source-list{min-height:0;overflow:auto;overscroll-behavior:contain;padding-right:8px}.music-library-body #sourcePanelSearch{overflow:hidden}.music-library-body #sourcePanelCrates{overflow:hidden}.music-library-body #sourcePanelCrates #djCrateTracks{max-height:none;flex:1;overflow:auto;overscroll-behavior:contain}.music-library-body #sourcePanelCrates #djCrates{max-height:170px;overflow:auto}.music-library-body #historyList{overflow:auto;overscroll-behavior:contain;min-height:0;flex:1}.music-library-body .search-result-row{grid-template-columns:44px minmax(0,1fr);min-height:60px;padding-top:8px;padding-bottom:8px}.music-library-body .search-result-row img{width:38px;height:38px}.music-library-body .search-result-row .result-meta{grid-column:2/-1;justify-content:flex-start}.music-library-body .artist-search-btn,.music-library-body .search-result-badge,.music-library-body .source-pill{min-height:21px;padding-top:2px;padding-bottom:2px}.music-library-body .search-pager{margin-top:auto;padding-top:8px}.music-library-body .search-page-count{min-height:34px}.music-library-body .search-page-btn{min-height:38px}@media(max-width:1020px){.music-library-body .music-library-search-row{grid-template-columns:1fr}.music-library-body .music-library-search-row .search-mode-row{flex-wrap:wrap}.music-library-body .search-results{grid-template-columns:1fr}.music-library-shell{height:95vh}.music-library-head{align-items:flex-start}.music-library-head h2{font-size:24px}}@media(max-width:700px){.music-library-modal{padding:8px}.music-library-shell{width:98vw;height:96vh;border-radius:16px}.music-library-head{padding:12px}.music-library-body{padding:10px}.music-library-body .search-result-row{min-height:60px}.clear-search-x{width:44px;min-width:44px}}

/* Compact touch-friendly source browser/search rows */
.tappable-row{width:100%;text-align:left;font:inherit;color:inherit;cursor:pointer;border-color:rgba(96,145,205,.24);transition:border-color .15s ease,background .15s ease,transform .15s ease}
.tappable-row:hover,.tappable-row:focus-visible{border-color:rgba(52,152,255,.64);background:rgba(52,152,255,.08);outline:none;transform:translateY(-1px)}
.search-mode-row{display:flex;gap:7px;flex-wrap:wrap;margin:8px 0 10px}.search-mode-btn{min-height:34px;padding:7px 11px;border-radius:11px;border:1px solid rgba(96,145,205,.46);background:rgba(11,25,44,.86);color:#dbeafe;font:inherit;font-size:12px;font-weight:1000;cursor:pointer}.search-mode-btn.active,.search-mode-btn[aria-pressed="true"]{border-color:rgba(96,165,250,.92);background:rgba(37,99,235,.34);color:#fff;box-shadow:0 0 14px rgba(37,99,235,.20)}.search-mode-btn:hover,.search-mode-btn:focus-visible{border-color:rgba(147,197,253,.95);outline:none}.search-result-row,.crate-track-row{grid-template-columns:50px minmax(0,1fr) minmax(190px,auto);min-height:62px}.search-result-row img,.crate-track-row img{width:42px;height:42px}.result-main{min-width:0;display:block}.result-main .result-title{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.result-main .mini{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.result-meta{display:flex;justify-content:flex-end;align-items:center;gap:6px;flex-wrap:wrap;min-width:0}.artist-search-btn{display:inline-flex;align-items:center;justify-content:center;min-height:24px;padding:3px 9px;border-radius:8px;border:1px solid rgba(96,165,250,.72);background:rgba(37,99,235,.18);color:#dbeafe;font:inherit;font-size:10px;font-weight:1000;text-transform:uppercase;letter-spacing:.035em;cursor:pointer}.artist-search-btn:hover,.artist-search-btn:focus-visible{border-color:rgba(147,197,253,.95);background:rgba(37,99,235,.32);outline:none}.search-pager{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:9px}.search-page-count{min-height:36px;display:inline-flex;align-items:center;justify-content:center;padding:6px 12px;border-radius:12px;border:1px solid rgba(96,145,205,.28);background:rgba(11,25,44,.66);color:#c7d8ee;font-size:12px;font-weight:950;white-space:nowrap}.search-page-btn{min-width:120px}.search-result-badges{max-width:220px}.source-pill{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;font-size:10px;font-weight:1000;text-transform:uppercase;letter-spacing:.035em;border:1px solid rgba(148,163,184,.45);background:rgba(148,163,184,.1);color:#dbeafe}.source-pill.spotify{border-color:rgba(34,197,94,.72);background:rgba(34,197,94,.13);color:#8dffbb}.source-pill.local{border-color:rgba(251,191,36,.78);background:rgba(251,191,36,.15);color:#ffe7a6}.source-panel[data-source-panel="crates"] .source-list{max-height:238px;overflow:auto;overscroll-behavior:contain;padding-right:4px}.source-panel[data-source-panel="crates"] #djCrateTracks{max-height:430px}.crate-row{grid-template-columns:44px minmax(0,1fr);min-height:58px}.crate-row-copy{min-width:0;display:block}.crate-row-copy strong,.crate-row-copy .mini{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.crate-track-heading{margin-top:10px;padding-top:8px;border-top:1px solid rgba(91,140,192,.18)}
@media(max-width:700px){.search-mode-row{gap:6px}.search-mode-btn{min-height:32px;padding:6px 9px}.search-result-row,.crate-track-row{grid-template-columns:44px minmax(0,1fr);min-height:62px}.result-meta{grid-column:2/-1;justify-content:flex-start}.artist-search-btn{min-height:22px;padding:3px 8px}.search-pager{align-items:stretch}.search-page-btn{min-width:0;flex:1}.search-page-count{flex:1;font-size:11px;text-align:center}.search-result-badges{max-width:none}.source-panel[data-source-panel="crates"] .source-list{max-height:280px}.source-panel[data-source-panel="crates"] #djCrateTracks{max-height:460px}}

/* Stage 2 compact modal result cards */
.music-library-body .search-results{
  grid-template-columns:1fr 1fr;
  gap:7px;
}
.music-library-body .search-result-row{
  position:relative;
  display:grid;
  grid-template-columns:38px minmax(0,1fr);
  min-height:54px;
  padding:7px 96px 7px 9px;
  align-items:center;
}
.music-library-body .search-result-row img{
  width:34px;
  height:34px;
  border-radius:9px;
}
.music-library-body .search-result-row .result-main{
  padding-right:0;
}
.music-library-body .search-result-row .result-title{
  font-size:13px;
  line-height:1.18;
  padding-right:0;
}
.music-library-body .search-result-row .result-subline{
  display:flex;
  align-items:center;
  gap:7px;
  margin-top:2px;
  min-height:22px;
  font-size:12px;
}
.music-library-body .artist-search-btn{
  min-height:22px;
  padding:2px 7px;
  border-radius:7px;
  font-size:9px;
  line-height:1;
  white-space:nowrap;
}
.music-library-body .result-corner-badges{
  position:absolute;
  top:7px;
  right:8px;
  display:flex;
  align-items:center;
  justify-content:flex-end;
  gap:4px;
  max-width:92px;
  flex-wrap:wrap;
  pointer-events:none;
}
.music-library-body .result-corner-badges .search-result-badges{
  display:flex;
  gap:4px;
  align-items:center;
  justify-content:flex-end;
  flex-wrap:wrap;
}
.music-library-body .search-result-badge,
.music-library-body .source-pill{
  min-height:18px;
  padding:2px 6px;
  font-size:9px;
  line-height:1;
}
.music-library-body .source-pill{
  min-width:20px;
  justify-content:center;
  padding-left:5px;
  padding-right:5px;
}
.music-library-body .source-pill.spotify{
  font-weight:1000;
}
.music-library-body .source-pill.local{
  font-weight:1000;
}
.music-library-body .search-pager{
  padding-top:7px;
}
@media(max-width:1020px){
  .music-library-body .search-results{grid-template-columns:1fr}
  .music-library-body .search-result-row{padding-right:112px}
  .music-library-body .result-corner-badges{max-width:108px}
}


/* Stage 2 badge/icon refinement */
.music-library-body .search-result-row{
  padding-right:118px;
}
.music-library-body .result-subline{
  gap:5px;
}
.music-library-body .artist-search-btn{
  width:28px;
  min-width:28px;
  height:22px;
  min-height:22px;
  padding:0;
  border-radius:999px;
  font-size:15px;
  line-height:1;
  font-family:Arial, Helvetica, sans-serif;
  text-transform:none;
  letter-spacing:0;
}
.music-library-body .result-corner-badges{
  top:7px;
  right:8px;
  width:104px;
  max-width:104px;
  display:grid;
  grid-template-columns:repeat(3, max-content);
  justify-content:end;
  justify-items:end;
  align-items:start;
  gap:4px;
  pointer-events:none;
}
.music-library-body .result-corner-badges .search-result-badges{
  display:contents;
}
.music-library-body .search-result-badge{
  max-width:42px;
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}
.music-library-body .source-pill{
  width:22px;
  min-width:22px;
  height:20px;
  min-height:20px;
  padding:0;
  border-radius:999px;
  font-size:13px;
  line-height:1;
  font-family:Arial, Helvetica, sans-serif;
}
.music-library-body .source-pill.spotify{
  color:#9dffbd;
  border-color:rgba(34,197,94,.84);
  background:rgba(34,197,94,.18);
}
.music-library-body .source-pill.local{
  color:#ffe8a3;
  border-color:rgba(251,191,36,.88);
  background:rgba(251,191,36,.18);
}
@media(max-width:1020px){
  .music-library-body .search-result-row{padding-right:124px}
  .music-library-body .result-corner-badges{width:110px;max-width:110px}
}


/* Stage 2 alignment polish: right-justified badge strip + centred artist icon */
.music-library-body .search-result-row{
  padding-right:156px;
}
.music-library-body .result-corner-badges{
  top:7px;
  right:8px;
  width:142px;
  max-width:142px;
  display:flex;
  flex-wrap:nowrap;
  justify-content:flex-end;
  align-items:flex-start;
  gap:4px;
  pointer-events:none;
}
.music-library-body .result-corner-badges .search-result-badges{
  display:flex;
  flex-wrap:nowrap;
  justify-content:flex-end;
  align-items:flex-start;
  gap:4px;
  min-width:0;
}
.music-library-body .search-result-badge{
  flex:0 0 auto;
  max-width:none;
  white-space:nowrap;
}
.music-library-body .source-pill{
  flex:0 0 auto;
  display:inline-flex;
  align-items:center;
  justify-content:center;
}
.music-library-body .artist-search-btn{
  position:relative;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:28px;
  min-width:28px;
  height:22px;
  min-height:22px;
  padding:0;
  vertical-align:middle;
}
.music-library-body .artist-search-btn::before{
  content:"";
  width:9px;
  height:9px;
  border:2px solid currentColor;
  border-radius:999px;
  transform:translate(-1px,-1px);
}
.music-library-body .artist-search-btn::after{
  content:"";
  position:absolute;
  width:7px;
  height:2px;
  border-radius:999px;
  background:currentColor;
  transform:translate(6px,6px) rotate(45deg);
}
.music-library-body .search-pager{
  margin-top:auto;
}
@media(max-width:1020px){
  .music-library-body .search-result-row{padding-right:156px}
  .music-library-body .result-corner-badges{width:142px;max-width:142px}
}


/* 16-result Music Library fit: tighter 2-column x 8-row modal cards */
.music-library-body .search-results{
  gap:4px;
}
.music-library-body .search-result-row{
  min-height:46px;
  padding:5px 150px 5px 8px;
}
.music-library-body .search-result-row img{
  width:30px;
  height:30px;
  border-radius:8px;
}
.music-library-body .search-result-row .result-title{
  font-size:12px;
  line-height:1.12;
}
.music-library-body .search-result-row .result-subline{
  min-height:18px;
  margin-top:1px;
  font-size:11px;
  line-height:1.1;
}
.music-library-body .artist-search-btn{
  width:25px;
  min-width:25px;
  height:20px;
  min-height:20px;
}
.music-library-body .artist-search-btn::before{
  width:8px;
  height:8px;
  border-width:2px;
}
.music-library-body .artist-search-btn::after{
  width:6px;
  height:2px;
  transform:translate(5px,5px) rotate(45deg);
}
.music-library-body .result-corner-badges{
  top:5px;
  right:7px;
  width:136px;
  max-width:136px;
  gap:3px;
}
.music-library-body .result-corner-badges .search-result-badges{
  gap:3px;
}
.music-library-body .search-result-badge,
.music-library-body .source-pill{
  min-height:17px;
  height:17px;
  padding:1px 5px;
  font-size:8.5px;
  line-height:1;
}
.music-library-body .source-pill{
  width:20px;
  min-width:20px;
  padding:0;
  font-size:12px;
}
.music-library-body .search-pager{
  padding-top:5px;
}
.music-library-body .search-page-count{
  min-height:30px;
}
.music-library-body .search-page-btn{
  min-height:34px;
}
@media(max-width:1020px){
  .music-library-body .search-result-row{
    padding-right:150px;
  }
  .music-library-body .result-corner-badges{
    width:136px;
    max-width:136px;
  }
}


/* Music Library view modes + badge key */
.music-library-view-row{display:flex;align-items:center;justify-content:flex-end;gap:6px;flex-wrap:wrap;margin-top:7px}.music-library-view-row .view-label{color:#9fb5cd;font-size:11px;font-weight:1000;text-transform:uppercase;letter-spacing:.05em}.view-mode-btn{min-height:28px;padding:5px 9px;border-radius:999px;border:1px solid rgba(96,145,205,.42);background:rgba(11,25,44,.76);color:#dbeafe;font:inherit;font-size:11px;font-weight:1000;cursor:pointer}.view-mode-btn.active,.view-mode-btn[aria-pressed="true"]{border-color:rgba(96,165,250,.9);background:rgba(37,99,235,.32);color:#fff}.music-library-key{margin-top:6px;padding:5px 8px;border-radius:10px;border:1px solid rgba(91,140,192,.22);background:rgba(11,25,44,.44);color:#9fb5cd;font-size:11px;line-height:1.25;font-weight:850}.music-library-key span{color:#dbeafe;font-weight:950}

/* Comfortable view: clearer cards, fewer per page */
.spotify-mixer-app.library-view-comfortable .music-library-body .search-results{gap:7px}.spotify-mixer-app.library-view-comfortable .music-library-body .search-result-row{min-height:58px;padding:7px 142px 7px 9px}.spotify-mixer-app.library-view-comfortable .music-library-body .search-result-row img{width:36px;height:36px}.spotify-mixer-app.library-view-comfortable .music-library-body .search-result-row .result-title{font-size:13px}.spotify-mixer-app.library-view-comfortable .music-library-body .search-result-row .result-subline{font-size:12px}

/* Compact view: 16 per page */
.spotify-mixer-app.library-view-compact .music-library-body .search-results{gap:4px}.spotify-mixer-app.library-view-compact .music-library-body .search-result-row{min-height:46px;padding:5px 150px 5px 8px}.spotify-mixer-app.library-view-compact .music-library-body .search-result-row img{width:30px;height:30px}.spotify-mixer-app.library-view-compact .music-library-body .search-result-row .result-title{font-size:12px}.spotify-mixer-app.library-view-compact .music-library-body .search-result-row .result-subline{font-size:11px}

/* List view: densest text-first rows for larger result sets */
.spotify-mixer-app.library-view-list .music-library-body .search-results{grid-template-columns:1fr 1fr;gap:3px}.spotify-mixer-app.library-view-list .music-library-body .search-result-row{min-height:38px;padding:4px 138px 4px 7px;grid-template-columns:28px minmax(0,1fr)}.spotify-mixer-app.library-view-list .music-library-body .search-result-row img{width:24px;height:24px;border-radius:6px}.spotify-mixer-app.library-view-list .music-library-body .search-result-row .result-title{font-size:11px;line-height:1.08}.spotify-mixer-app.library-view-list .music-library-body .search-result-row .result-subline{min-height:16px;font-size:10px;margin-top:0}.spotify-mixer-app.library-view-list .music-library-body .artist-search-btn{width:22px;min-width:22px;height:18px;min-height:18px}
@media(max-width:1020px){.music-library-view-row{justify-content:flex-start}.music-library-key{font-size:10px}}


/* Music Library view dropdown and footer badge key */
.music-library-panel-top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}.music-library-panel-top .source-tabs{margin:0}.music-library-view-select-wrap{display:flex;align-items:center;gap:7px;margin-left:auto;color:#9fb5cd;font-size:11px;font-weight:1000;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}.music-library-view-select{min-height:36px;border-radius:12px;border:1px solid rgba(96,145,205,.42);background:#0b192c;color:#f3f8ff;padding:7px 30px 7px 10px;font:inherit;font-size:12px;font-weight:950;text-transform:none;letter-spacing:0}.music-library-view-select:focus{outline:none;border-color:rgba(96,165,250,.92);box-shadow:0 0 0 2px rgba(52,152,255,.18)}.music-library-view-row,.music-library-key{display:none!important}.music-library-body .source-panel.active{display:flex;flex-direction:column;min-height:0;flex:1}.music-library-body #sourcePanelSearch.active{overflow:hidden}.music-library-footer-key{margin-top:auto;padding-top:8px;display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;color:#9fb5cd;font-size:11px;font-weight:900;line-height:1.2;flex:0 0 auto}.music-library-footer-key .key-label{color:#dbeafe;font-weight:1000;text-transform:uppercase;letter-spacing:.05em}.music-library-footer-key .key-item{display:inline-flex;align-items:center;gap:4px;white-space:nowrap}.music-library-footer-key .search-result-badge,.music-library-footer-key .source-pill{min-height:18px;height:18px;padding:2px 6px;font-size:9px;line-height:1;margin:0}.music-library-footer-key .source-pill{width:22px;min-width:22px;padding:0;justify-content:center}.music-library-body .search-pager{flex:0 0 auto}@media(max-width:1020px){.music-library-panel-top{align-items:flex-start;flex-direction:column}.music-library-view-select-wrap{margin-left:0}.music-library-footer-key{justify-content:flex-start;gap:6px;font-size:10px}}


/* Music Library in-modal action bar */
.music-library-action-bar{
  flex:0 0 auto;
  margin-top:8px;
  padding:8px;
  border-radius:14px;
  border:1px solid rgba(96,145,205,.28);
  background:rgba(6,16,30,.62);
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  min-height:58px;
}
.music-library-action-empty{
  width:100%;
  text-align:center;
  color:#9fb5cd;
  font-size:12px;
  font-weight:900;
}
.library-selected-track{
  display:grid;
  grid-template-columns:42px minmax(0,1fr);
  align-items:center;
  gap:9px;
  min-width:210px;
  flex:1 1 260px;
}
.library-selected-track img{
  width:42px;
  height:42px;
  border-radius:10px;
  object-fit:cover;
  border:1px solid rgba(96,145,205,.24);
}
.library-selected-copy{
  min-width:0;
}
.library-selected-copy strong,
.library-selected-copy span,
.library-selected-copy em{
  display:block;
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}
.library-selected-copy strong{
  color:#fff;
  font-size:13px;
  line-height:1.15;
}
.library-selected-copy span{
  color:#b7cbe3;
  font-size:11px;
  font-weight:850;
  margin-top:2px;
}
.library-selected-copy em{
  color:#ffd178;
  font-style:normal;
  font-size:10px;
  font-weight:900;
  margin-top:2px;
}
.library-action-buttons{
  display:flex;
  align-items:center;
  justify-content:flex-end;
  flex-wrap:wrap;
  gap:6px;
  flex:2 1 520px;
}
.library-action-btn{
  min-height:32px;
  padding:6px 9px;
  font-size:11px;
}
.library-crate-save{
  display:inline-flex;
  align-items:center;
  gap:5px;
  color:#9fb5cd;
  font-size:10px;
  font-weight:1000;
  text-transform:uppercase;
  letter-spacing:.04em;
}
.library-crate-save select{
  min-height:32px;
  max-width:150px;
  font-size:11px;
  padding:5px 24px 5px 8px;
}
.library-selected{
  border-color:rgba(96,165,250,.95)!important;
  background:rgba(37,99,235,.22)!important;
  box-shadow:0 0 0 1px rgba(96,165,250,.34),0 0 18px rgba(37,99,235,.18)!important;
}
.music-library-footer-key{
  padding-top:6px;
}
@media(max-width:1020px){
  .music-library-action-bar{
    align-items:stretch;
    flex-direction:column;
  }
  .library-action-buttons{
    justify-content:flex-start;
  }
}


/* Music Library action bar refinement + always-visible pager */
.music-library-body .search-pager[hidden]{display:flex!important}
.music-library-body .search-page-btn:disabled{
  opacity:.42;
  cursor:not-allowed;
}
.library-crate-save{
  gap:6px;
}
.library-crate-save select{
  min-width:190px;
  max-width:230px;
}
.library-action-buttons{
  gap:7px;
}
.library-action-btn{
  min-height:34px;
}
@media(max-width:1180px){
  .library-crate-save select{
    min-width:160px;
    max-width:190px;
  }
}


/* Stage 5 DJ Crates compact browser */
.crate-browser-toolbar{display:flex;align-items:end;gap:8px;margin-bottom:8px}.crate-current-wrap{display:grid;gap:4px;flex:1 1 auto}.crate-current-wrap label{color:#9fb5cd;font-size:11px;font-weight:1000;text-transform:uppercase;letter-spacing:.05em}.crate-current-wrap select{min-height:38px;border-radius:12px}.crate-create-panel{display:grid;grid-template-columns:minmax(240px,1fr) auto auto;gap:8px;margin:8px 0}.crate-create-panel[hidden]{display:none!important}.crate-browser-tracks{flex:1 1 auto;min-height:0;overflow:hidden}.crate-track-grid{display:grid;grid-template-columns:1fr 1fr;gap:4px;align-content:start;min-height:0}.music-library-body .crate-track-row{position:relative;display:grid;grid-template-columns:38px minmax(0,1fr);align-items:center;min-height:46px;padding:5px 150px 5px 8px}.music-library-body .crate-track-row img{width:30px;height:30px;border-radius:8px}.music-library-body .crate-track-row .result-title{font-size:12px;line-height:1.12}.music-library-body .crate-track-row .result-subline{min-height:18px;margin-top:1px;font-size:11px;line-height:1.1}.music-library-body .crate-track-row .result-corner-badges{top:5px;right:7px;width:136px;max-width:136px}.spotify-mixer-app.library-view-comfortable .music-library-body .crate-track-grid{gap:7px}.spotify-mixer-app.library-view-comfortable .music-library-body .crate-track-row{min-height:58px;padding:7px 142px 7px 9px}.spotify-mixer-app.library-view-comfortable .music-library-body .crate-track-row img{width:36px;height:36px}.spotify-mixer-app.library-view-comfortable .music-library-body .crate-track-row .result-title{font-size:13px}.spotify-mixer-app.library-view-comfortable .music-library-body .crate-track-row .result-subline{font-size:12px}.spotify-mixer-app.library-view-list .music-library-body .crate-track-grid{gap:3px}.spotify-mixer-app.library-view-list .music-library-body .crate-track-row{min-height:38px;padding:4px 138px 4px 7px;grid-template-columns:28px minmax(0,1fr)}.spotify-mixer-app.library-view-list .music-library-body .crate-track-row img{width:24px;height:24px;border-radius:6px}.spotify-mixer-app.library-view-list .music-library-body .crate-track-row .result-title{font-size:11px;line-height:1.08}.spotify-mixer-app.library-view-list .music-library-body .crate-track-row .result-subline{min-height:16px;font-size:10px;margin-top:0}.crate-pager{flex:0 0 auto;padding-top:5px}@media(max-width:1020px){.crate-browser-toolbar{align-items:stretch;flex-direction:column}.crate-create-panel{grid-template-columns:1fr}.crate-track-grid{grid-template-columns:1fr}}


/* Stage 5b DJ Crates tile drawer */
.crate-browser-toolbar{
  align-items:stretch;
}
.crate-selected-summary{
  flex:1 1 auto;
  display:grid;
  grid-template-columns:34px minmax(0,1fr) auto;
  align-items:center;
  gap:9px;
  min-height:44px;
  padding:7px 10px;
  border-radius:13px;
  border:1px solid rgba(96,145,205,.34);
  background:rgba(11,25,44,.72);
  color:#f3f8ff;
  text-align:left;
  cursor:pointer;
}
.crate-selected-summary:hover,
.crate-selected-summary:focus-visible{
  border-color:rgba(96,165,250,.8);
  outline:none;
  background:rgba(37,99,235,.16);
}
.crate-summary-icon{
  width:30px;
  height:30px;
  border-radius:9px;
  display:grid;
  place-items:center;
  border:1px solid rgba(52,152,255,.32);
  background:rgba(52,152,255,.12);
  color:#bfe1ff;
  font-weight:1000;
}
.crate-summary-copy{
  min-width:0;
}
.crate-summary-copy strong,
.crate-summary-copy small{
  display:block;
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}
.crate-summary-copy strong{
  font-size:13px;
  font-weight:1000;
}
.crate-summary-copy small{
  margin-top:2px;
  color:#9fb5cd;
  font-size:11px;
  font-weight:850;
}
.crate-summary-change{
  color:#9bd3ff;
  font-size:11px;
  font-weight:1000;
  text-transform:uppercase;
  letter-spacing:.04em;
}
.crate-tile-drawer{
  margin:0 0 8px;
  padding:8px;
  border-radius:14px;
  border:1px solid rgba(96,145,205,.22);
  background:rgba(6,16,30,.36);
  max-height:156px;
  overflow:auto;
  overscroll-behavior:contain;
}
.crate-tile-drawer[hidden]{
  display:none!important;
}
.crate-tile-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(150px,1fr));
  gap:6px;
  margin-top:6px;
}
.crate-tile{
  display:grid;
  grid-template-columns:30px minmax(0,1fr);
  align-items:center;
  gap:8px;
  min-height:42px;
  padding:6px 8px;
  border-radius:12px;
  border:1px solid rgba(96,145,205,.24);
  background:rgba(255,255,255,.028);
  color:#f3f8ff;
  text-align:left;
  cursor:pointer;
}
.crate-tile:hover,
.crate-tile:focus-visible{
  outline:none;
  border-color:rgba(96,165,250,.8);
  background:rgba(52,152,255,.12);
}
.crate-tile.active-crate{
  border-color:rgba(255,193,7,.64)!important;
  box-shadow:0 0 0 1px rgba(255,193,7,.18),0 0 14px rgba(255,193,7,.13);
}
.crate-tile-icon{
  width:30px;
  height:30px;
  border-radius:9px;
  display:grid;
  place-items:center;
  border:1px solid rgba(52,152,255,.32);
  background:rgba(52,152,255,.13);
  color:#bfe1ff;
  font-size:16px;
}
.crate-tile-copy{
  min-width:0;
}
.crate-tile-copy strong,
.crate-tile-copy small{
  display:block;
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}
.crate-tile-copy strong{
  font-size:12px;
  line-height:1.1;
}
.crate-tile-copy small{
  margin-top:2px;
  color:#9fb5cd;
  font-size:10px;
  font-weight:850;
}
.crate-empty{
  grid-column:1/-1;
}
@media(max-width:1020px){
  .crate-selected-summary{
    grid-template-columns:34px minmax(0,1fr);
  }
  .crate-summary-change{
    grid-column:1/-1;
  }
  .crate-tile-grid{
    grid-template-columns:repeat(auto-fill,minmax(132px,1fr));
  }
}

</style>
<main class="spotify-mixer-app" data-api="<?= h(admin_url('spotify/mixer-api.php')) ?>" data-search-api="/api/spotify-search.php" data-local-search-api="<?= h(admin_url('api/local-music-search.php')) ?>">
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

  <div class="music-library-modal" id="musicLibraryModal" aria-hidden="true">
    <div class="music-library-shell" role="dialog" aria-modal="true" aria-labelledby="musicLibraryTitle">
      <div class="music-library-head">
        <div>
          <div class="tiny-label">Mixer source browser</div>
          <h2 id="musicLibraryTitle">Music Library</h2>
          <div class="mini muted">Search Spotify + local music, browse DJ crates, or reuse history without moving the decks.</div>
        </div>
        <button class="mixer-btn dark" id="closeMusicLibrary" type="button">Close</button>
      </div>
      <div class="music-library-body">
        <div class="search-box source-box">
          <div class="music-library-panel-top">
            <div class="source-tabs" role="tablist" aria-label="Track source">
              <button class="source-tab active" type="button" data-source-tab="search">Search Music</button>
              <button class="source-tab" type="button" data-source-tab="crates">DJ Crates</button>
              <button class="source-tab" type="button" data-source-tab="history">History</button>
            </div>
            <label class="music-library-view-select-wrap" for="musicLibraryViewSelect">
              <span>View</span>
              <select id="musicLibraryViewSelect" class="music-library-view-select">
                <option value="comfortable">▦ Comfortable</option>
                <option value="compact">▤ Compact</option>
                <option value="list">☰ List</option>
              </select>
            </label>
          </div>
          <div class="source-panel active" id="sourcePanelSearch" data-source-panel="search">
            <div class="tiny-label">Search Spotify + Local Music</div>
            <div class="search-row music-library-search-row">
              <input id="spotifySearch" class="search-input" placeholder="Start typing a track, artist, album or local filename…" autocomplete="off">
              <div class="search-mode-row" role="group" aria-label="Search mode">
                <button class="search-mode-btn active" type="button" data-search-mode="broad" aria-pressed="true">Broad</button>
                <button class="search-mode-btn" type="button" data-search-mode="track" aria-pressed="false">Track title</button>
                <button class="search-mode-btn" type="button" data-search-mode="track_artist" aria-pressed="false">Track + artist</button>
                <button class="mixer-btn red clear-search-x" id="clearSearch" type="button" title="Clear search" aria-label="Clear search">×</button>
              </div>
            </div>
            <div id="searchStatus" class="mini muted" style="margin-top:8px"></div>
            <div class="search-results" id="searchResults"></div>
            <div class="search-pager" id="searchPager" hidden></div>
          </div>
          <div class="source-panel" id="sourcePanelCrates" data-source-panel="crates">
            <div class="crate-browser-toolbar">
              <button class="crate-selected-summary" id="crateDrawerToggle" type="button" aria-expanded="true">
                <span class="crate-summary-icon">▦</span>
                <span class="crate-summary-copy">
                  <strong id="crateSummaryName">Choose a crate</strong>
                  <small id="crateSummaryCount">Tap a tile below</small>
                </span>
                <span class="crate-summary-change">Change crate</span>
              </button>
              <button class="mixer-btn blue" id="refreshCrates" type="button">Refresh</button>
              <button class="mixer-btn green" id="showNewCrate" type="button">+ New Crate</button>
            </div>
            <div class="crate-tile-drawer" id="crateTileDrawer">
              <div class="tiny-label">Choose crate</div>
              <div class="crate-tile-grid" id="djCrateTiles"></div>
            </div>
            <div class="crate-create-panel" id="newCratePanel" hidden>
              <input id="newCrateName" class="search-input" placeholder="New crate name, e.g. 80s, Floorfillers…">
              <button class="mixer-btn green" id="createCrate" type="button">Create</button>
              <button class="mixer-btn dark" id="cancelNewCrate" type="button">Cancel</button>
            </div>
            <div id="djCrateStatus" class="mini muted"></div>
            <div id="djCrateTracks" class="crate-browser-tracks"></div>
            <div class="search-pager crate-pager" id="cratePager"></div>
          </div>
          <div class="source-panel" id="sourcePanelHistory" data-source-panel="history">
            <div class="source-tools"><div><div class="tiny-label">Playback history</div><div class="mini muted">Tracks played during this mixer session/event.</div></div></div>
            <div id="historyList" class="source-list"></div>
          </div>
          <div class="music-library-action-bar" id="musicLibraryActionBar" aria-live="polite">
            <div class="music-library-action-empty">Select a track to choose an action.</div>
          </div>
          <div class="music-library-footer-key" aria-label="Search result badge key">
            <span class="key-label">Key:</span>
            <span class="key-item"><span class="search-result-badge decade">80s</span><span>Decade</span></span>
            <span class="key-item"><span class="search-result-badge original">Orig</span><span>Original</span></span>
            <span class="key-item"><span class="search-result-badge original-era">Era</span><span>Original-era</span></span>
            <span class="key-item"><span class="search-result-badge remaster">Rem</span><span>Remaster</span></span>
            <span class="key-item"><span class="search-result-badge compilation">Comp</span><span>Compilation</span></span>
            <span class="key-item"><span class="source-pill spotify" title="Spotify">♬</span><span>Spotify</span></span>
            <span class="key-item"><span class="source-pill local" title="Local music">▣</span><span>Local</span></span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="mixer-top-note">
    <div><h1>Spotify Mixer</h1></div>
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
        <div class="device-row"><select class="device-select" id="deviceA"></select><button class="mixer-btn orange" data-save-devices data-save-deck="a">Save</button></div><div class="deck-warning-note" id="deckAWarning"></div>
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
          <div class="transport-manual">
            <button class="mixer-btn blue" data-deck-action="return_loaded" data-deck="a">↩ Return if unplayed</button>
            <button class="mixer-btn purple" data-deck-action="mark_loaded_played" data-deck="a">✓ Mark played</button>
          </div>
        </div>
        <div class="loaded-request-note-card" id="deckANote"></div>
      </div>
    </section>

    <section class="mixer-panel mixer-centre">
      <div class="panel-body">
        <div class="music-library-launch">
          <button class="mixer-btn blue full" type="button" id="openMusicLibrary">♫ Open Music Library</button>
          <div class="mini muted">Search, DJ crates and history open in a large overlay. Playlist and public requests stay visible here.</div>
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
        <div class="device-row"><select class="device-select" id="deviceB"></select><button class="mixer-btn blue" data-save-devices data-save-deck="b">Save</button></div><div class="deck-warning-note" id="deckBWarning"></div>
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
          <div class="transport-manual">
            <button class="mixer-btn blue" data-deck-action="return_loaded" data-deck="b">↩ Return if unplayed</button>
            <button class="mixer-btn purple" data-deck-action="mark_loaded_played" data-deck="b">✓ Mark played</button>
          </div>
        </div>
        <div class="loaded-request-note-card" id="deckBNote"></div>
      </div>
    </section>
  </div>
  
</main>
<script src="<?= h(dttd_asset_url('assets/spotify-mixer.js', true)) ?>"></script>
<?php admin_footer(); ?>
