(function(){
  function ready(fn){
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  ready(function(){
    const isRequestsPage = !!window.DTTD_IS_REQUESTS_PAGE;
    const pingUrl = window.DTTD_REQUEST_PING_URL || 'request-ping.php';
    const requestsUrl = window.DTTD_REQUESTS_URL || 'requests.php';
    const storeKey = 'dttd_actionable_request_seen_fingerprint_v2';
    const newestKey = 'dttd_actionable_request_seen_newest_id_v3';
    const alertedKey = 'dttd_actionable_request_alert_fingerprint_v2';

    function injectStyles(){
      if (document.getElementById('dttd-request-alert-styles')) return;
      const style = document.createElement('style');
      style.id = 'dttd-request-alert-styles';
      style.textContent = `
        .header-admin-nav-btn.request-alert-pulse{border-color:rgba(255,70,85,.9)!important;box-shadow:0 0 0 1px rgba(255,70,85,.4),0 0 22px rgba(255,70,85,.72)!important;animation:dttdRequestPulse 1.05s ease-in-out infinite;}
        .header-admin-nav-btn.request-alert-pulse .admin-nav-icon{color:#ff6b75!important;}
        .header-admin-nav-btn.request-alert-pulse::after{content:'NEW';position:absolute;right:-6px;top:-6px;border-radius:999px;background:#ff3346;color:#fff;font-size:10px;font-weight:1000;line-height:1;padding:4px 6px;border:1px solid rgba(255,255,255,.36);box-shadow:0 0 16px rgba(255,51,70,.8);}
        @keyframes dttdRequestPulse{0%,100%{transform:translateY(0);filter:none;}50%{transform:translateY(-1px);filter:brightness(1.18);}}
        .mixer-new-request-alert{display:none;margin-left:8px;align-items:center;gap:6px;border-radius:999px;padding:3px 8px;border:1px solid rgba(255,70,85,.75);background:rgba(255,70,85,.14);color:#ffb5bb;font-size:11px;font-weight:1000;letter-spacing:.03em;text-decoration:none;animation:dttdRequestBadgePulse 1s ease-in-out infinite;}
        .mixer-new-request-alert.visible{display:inline-flex;}
        @keyframes dttdRequestBadgePulse{0%,100%{box-shadow:0 0 0 rgba(255,70,85,0);}50%{box-shadow:0 0 18px rgba(255,70,85,.62);}}
      `;
      document.head.appendChild(style);
    }

    function getEventId(){
      const params = new URLSearchParams(window.location.search);
      if (params.get('event')) return params.get('event');
      const eventInput = document.querySelector('input[name="event_id"], select[name="event"]');
      return eventInput && eventInput.value ? eventInput.value : '';
    }

    function urlWithCacheBust(){
      const url = new URL(pingUrl, window.location.href);
      const eventId = getEventId();
      if (eventId) url.searchParams.set('event', eventId);
      url.searchParams.set('_', Date.now());
      return url.toString();
    }

    function ensureMixerBadge(){
      const requestCount = document.getElementById('requestCount');
      if (!requestCount) return null;
      let badge = document.getElementById('mixerNewRequestAlert');
      if (!badge) {
        badge = document.createElement('a');
        badge.id = 'mixerNewRequestAlert';
        badge.className = 'mixer-new-request-alert';
        badge.href = requestsUrl;
        badge.textContent = 'NEW REQUESTS';
        badge.title = 'New public requests are waiting. Open Requests to review them.';
        requestCount.insertAdjacentElement('afterend', badge);
      }
      return badge;
    }

    function setAlert(on, data){
      const nav = document.getElementById('adminRequestsNavBtn') || document.querySelector('a[href$="requests.php"]');
      if (nav) nav.classList.toggle('request-alert-pulse', !!on);
      const badge = ensureMixerBadge();
      if (badge) {
        badge.classList.toggle('visible', !!on);
        if (on && data) {
          const actionable = Number(data.actionable_count || 0);
          badge.textContent = actionable ? 'NEW REQUESTS • ' + actionable + ' pending' : 'NEW REQUESTS';
        }
      }
    }

    function markSeen(fingerprint, newestId){
      if (fingerprint) localStorage.setItem(storeKey, fingerprint);
      if (Number.isFinite(Number(newestId))) localStorage.setItem(newestKey, String(Number(newestId)));
      localStorage.removeItem(alertedKey);
      setAlert(false);
    }

    function seenNewestId(){
      const value = Number(localStorage.getItem(newestKey) || 0);
      return Number.isFinite(value) ? value : 0;
    }

    async function fetchPing(){
      const response = await fetch(urlWithCacheBust(), {cache:'no-store', credentials:'same-origin'});
      if (!response.ok) return null;
      const data = await response.json();
      return data && data.ok ? data : null;
    }

    // Original Requests-page banner behaviour, kept for that page only.
    function textNumber(value){
      const n = parseInt(String(value || '').replace(/[^0-9]/g, ''), 10);
      return Number.isFinite(n) ? n : 0;
    }
    function readVisibleCounts(){
      const counts = {pending:0, maybe:0, played:0, duplicate:0, rejected:0};
      document.querySelectorAll('.stat-line').forEach(function(row){
        const labelEl = row.querySelector('span:nth-child(2)');
        const valueEl = row.querySelector('strong');
        if (!labelEl || !valueEl) return;
        const label = labelEl.textContent.trim().toLowerCase();
        const value = textNumber(valueEl.textContent);
        if (label.includes('pending')) counts.pending = value;
        if (label.includes('maybe')) counts.maybe = value;
        if (label.includes('played')) counts.played = value;
        if (label.includes('duplicate')) counts.duplicate = value;
        if (label.includes('rejected')) counts.rejected = value;
      });
      counts.total = counts.pending + counts.maybe + counts.played + counts.duplicate + counts.rejected;
      return counts;
    }
    function ensureBanner(){
      let banner = document.getElementById('requestUpdateBanner');
      if (banner) return banner;
      banner = document.createElement('div');
      banner.id = 'requestUpdateBanner';
      banner.className = 'request-update-banner';
      banner.hidden = true;
      banner.innerHTML = '<div><strong>Queue updates available</strong><span id="requestUpdateText">New or changed requests have arrived.</span></div><button type="button" id="requestUpdateRefresh">Refresh queue</button>';
      const main = document.querySelector('main.touch-wrap');
      if (main && main.firstChild) main.insertBefore(banner, main.firstChild);
      else if (main) main.appendChild(banner);
      else document.body.insertBefore(banner, document.body.firstChild);
      const btn = banner.querySelector('#requestUpdateRefresh');
      if (btn) btn.addEventListener('click', function(){ window.location.reload(); });
      return banner;
    }

    injectStyles();

    let loadedCounts = null;
    let banner = null;
    let bannerText = null;
    let hasBannerUpdate = false;

    if (isRequestsPage) {
      loadedCounts = readVisibleCounts();
      banner = ensureBanner();
      bannerText = document.getElementById('requestUpdateText');
    }

    async function checkForUpdates(){
      if (document.hidden) return;
      try {
        const data = await fetchPing();
        const actionableFingerprint = data.actionable_fingerprint || '';
        const actionableCount = Number(data.actionable_count || 0);
        const actionableNewestId = Number(data.actionable_newest_id || 0);
        if (!actionableFingerprint) return;

        if (isRequestsPage) {
          const seen = localStorage.getItem(storeKey);
          if (!seen) {
            markSeen(actionableFingerprint, actionableNewestId);
            return;
          }
          const changed = actionableCount > 0 && actionableNewestId > seenNewestId();
          if (changed && !hasBannerUpdate) {
            hasBannerUpdate = true;
            if (bannerText) bannerText.textContent = 'New requests needing DJ review arrived at ' + (data.checked_at || 'now') + '. Now: ' + actionableCount + ' pending.';
            if (banner) banner.hidden = false;
          }
          if (actionableCount === 0) markSeen(actionableFingerprint, actionableNewestId);
          return;
        }

        const seen = localStorage.getItem(storeKey);
        if (!seen) {
          markSeen(actionableFingerprint, actionableNewestId);
          return;
        }

        const changed = actionableCount > 0 && actionableNewestId > seenNewestId();
        setAlert(changed, data);
        if (changed) localStorage.setItem(alertedKey, actionableFingerprint);
        if (!changed && actionableCount === 0) markSeen(actionableFingerprint, actionableNewestId);
      } catch (error) {
        console.error('Queue update check failed', error);
      }
    }

    window.setTimeout(checkForUpdates, 1200);
    window.setInterval(checkForUpdates, isRequestsPage ? 5000 : 7000);
  });
})();
