(function(){
  function ready(fn){
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  ready(function(){
    const eventTimer = document.getElementById('headerEventTimer');
    const eventValue = document.getElementById('headerEventTimerValue');
    const requestTimer = document.getElementById('headerRequestTimer');
    const requestValue = document.getElementById('headerRequestTimerValue');

    if ((!eventTimer || !eventValue) && (!requestTimer || !requestValue)) {
      return;
    }

    let eventEndTarget = null;
    let requestCloseTarget = null;

    function pad(value){
      return String(value).padStart(2, '0');
    }

    function parseTarget(value){
      if (!value) return null;
      const parsed = new Date(String(value));
      return isNaN(parsed.getTime()) ? null : parsed;
    }

    function formatRemaining(ms){
      if (ms <= 0) return '00:00:00';
      const totalSeconds = Math.floor(ms / 1000);
      const hours = Math.floor(totalSeconds / 3600);
      const minutes = Math.floor((totalSeconds % 3600) / 60);
      const seconds = totalSeconds % 60;
      return pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);
    }

    function setState(el, ms){
      if (!el) return;

      el.classList.remove('timer-loading','timer-green','timer-amber','timer-red','timer-ended','timer-unavailable');

      if (ms <= 0) {
        el.classList.add('timer-ended');
      } else if (ms <= 15 * 60 * 1000) {
        el.classList.add('timer-red');
      } else if (ms <= 30 * 60 * 1000) {
        el.classList.add('timer-amber');
      } else {
        el.classList.add('timer-green');
      }
    }

    function setUnavailable(el, valueEl){
      if (!el || !valueEl) return;
      valueEl.textContent = '--:--:--';
      el.classList.remove('timer-loading','timer-green','timer-amber','timer-red','timer-ended');
      el.classList.add('timer-unavailable');
    }

    function updateTimer(el, valueEl, target){
      if (!el || !valueEl) return;

      if (!target) {
        setUnavailable(el, valueEl);
        return;
      }

      const remaining = target - new Date();
      valueEl.textContent = formatRemaining(remaining);
      setState(el, remaining);
    }

    function tick(){
      updateTimer(eventTimer, eventValue, eventEndTarget);
      updateTimer(requestTimer, requestValue, requestCloseTarget);
    }

    async function loadTargets(){
      try {
        const timerEndpoint = window.location.hostname.indexOf('dj.') === 0 ? '/header-timers.php' : '/admin/header-timers.php';
        const response = await fetch(timerEndpoint + '?_=' + Date.now(), {
          cache: 'no-store',
          credentials: 'same-origin'
        });

        if (!response.ok) return;

        const data = await response.json();

        if (!data.ok || !data.has_event) {
          eventEndTarget = null;
          requestCloseTarget = null;
          tick();
          return;
        }

        eventEndTarget = parseTarget(data.event_end);
        requestCloseTarget = parseTarget(data.requests_close);
        tick();
      } catch (error) {
        // Do not interrupt DJ workflow.
      }
    }

    tick();
    loadTargets();
    window.setInterval(tick, 1000);
    window.setInterval(loadTargets, 60000);
  });
})();
