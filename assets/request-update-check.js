(function(){
  function ready(fn){
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  ready(function(){
    const stats = document.querySelectorAll('.stat-line');
    const requestPage = document.querySelector('main.touch-wrap, .request-queue-panel');
    if (!requestPage) return;

    function textNumber(value){
      const n = parseInt(String(value || '').replace(/[^0-9]/g, ''), 10);
      return Number.isFinite(n) ? n : 0;
    }

    function readVisibleCounts(){
      const counts = {
        pending: 0,
        maybe: 0,
        played: 0,
        duplicate: 0,
        rejected: 0
      };

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

    function getEventId(){
      const params = new URLSearchParams(window.location.search);
      if (params.get('event')) return params.get('event');

      const eventInput = document.querySelector('input[name="event_id"], select[name="event"]');
      if (eventInput && eventInput.value) return eventInput.value;

      return '';
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
      if (main && main.firstChild) {
        main.insertBefore(banner, main.firstChild);
      } else if (main) {
        main.appendChild(banner);
      } else {
        document.body.insertBefore(banner, document.body.firstChild);
      }

      const btn = banner.querySelector('#requestUpdateRefresh');
      if (btn) {
        btn.addEventListener('click', function(){
          window.location.reload();
        });
      }

      return banner;
    }

    const loadedCounts = readVisibleCounts();
    const eventId = getEventId();
    const banner = ensureBanner();
    const text = document.getElementById('requestUpdateText');
    let hasUpdate = false;

    function countsChanged(data){
      const server = data.status_counts || {};
      const serverTotal = Number(data.total_requests || 0);

      if (serverTotal !== loadedCounts.total) return true;

      for (const key of ['pending','maybe','played','duplicate','rejected']) {
        if (Number(server[key] || 0) !== Number(loadedCounts[key] || 0)) {
          return true;
        }
      }

      return false;
    }

    function showUpdate(data){
      hasUpdate = true;

      const server = data.status_counts || {};
      const parts = [];

      if (Number(server.pending || 0) !== loadedCounts.pending) {
        parts.push(Number(server.pending || 0) + ' pending');
      }
      if (Number(server.maybe || 0) !== loadedCounts.maybe) {
        parts.push(Number(server.maybe || 0) + ' maybe');
      }
      if (Number(server.played || 0) !== loadedCounts.played) {
        parts.push(Number(server.played || 0) + ' played');
      }

      const summary = parts.length ? ' Now: ' + parts.join(', ') + '.' : '';
      if (text) text.textContent = 'The request queue changed at ' + (data.checked_at || 'now') + '.' + summary;
      banner.hidden = false;
    }

    async function checkForUpdates(){
      if (document.hidden || hasUpdate) return;

      try {
        const url = eventId
          ? 'request-ping.php?event=' + encodeURIComponent(eventId) + '&_=' + Date.now()
          : 'request-ping.php?_=' + Date.now();

        const response = await fetch(url, {
          cache: 'no-store',
          credentials: 'same-origin'
        });

        if (!response.ok) return;

        const data = await response.json();

        if (!data.ok) return;

        if (countsChanged(data)) {
          showUpdate(data);
        }
      } catch (error) {
        console.error('Queue update check failed', error);
      }
    }

    window.setTimeout(checkForUpdates, 1200);
    window.setInterval(checkForUpdates, 5000);
  });
})();
