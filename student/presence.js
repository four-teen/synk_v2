(function () {
  'use strict';
  const source = document.getElementById('studentPresenceConfig');
  if (!source) return;
  const config = JSON.parse(source.textContent);
  let pendingActivity = false, sending = false, stopped = false;
  let lastSent = 0, expiresAt = Date.now() + config.idle_seconds * 1000;
  let lastInteraction = 0;
  let wakeTimer;
  function signedOut(reason) {
    stopped = true;
    clearTimeout(wakeTimer);
    const allowed = ['student_idle_timeout', 'student_session_changed', 'student_signed_out'];
    location.replace('login.php?auth_status=' + (allowed.includes(reason) ? reason : 'student_signed_out'));
  }
  async function heartbeat() {
    if (sending || stopped) return;
    sending = true;
    const activity = pendingActivity && document.visibilityState === 'visible' && Date.now() - lastInteraction <= 30000;
    pendingActivity = false;
    const controller = new AbortController();
    const requestTimeout = setTimeout(() => controller.abort(), Math.max(1000, Math.min(10000, expiresAt - Date.now())));
    try {
      const response = await fetch('../backend/student_presence.php', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        signal: controller.signal,
        body: new URLSearchParams({ csrf_token: config.csrf_token, action: activity ? 'activity' : 'status' })
      });
      const data = await response.json();
      if (response.status === 401 || data.status === 'signed_out') return signedOut(data.reason);
      if (data.status === 'ok') {
        lastSent = Date.now();
        expiresAt = Date.now() + Math.max(0, Number(data.seconds_remaining)) * 1000;
      } else if (response.status === 403) return signedOut('student_session_changed');
    } catch (_) {
      // Retry after a connection failure. The server still enforces expiry.
      if (Date.now() >= expiresAt) return signedOut('student_idle_timeout');
      if (activity && Date.now() - lastInteraction <= 30000) pendingActivity = true;
    } finally {
      clearTimeout(requestTimeout);
      sending = false;
      clearTimeout(wakeTimer);
      if (!stopped) wakeTimer = setTimeout(heartbeat, Math.max(1000, Math.min(30000, expiresAt - Date.now())));
    }
  }
  function activity(event) {
    if (!event.isTrusted || document.visibilityState !== 'visible') return;
    pendingActivity = true;
    lastInteraction = Date.now();
    if (Date.now() - lastSent >= 15000) heartbeat();
    else {
      clearTimeout(wakeTimer);
      wakeTimer = setTimeout(heartbeat, Math.max(1000, 15000 - (Date.now() - lastSent)));
    }
  }
  ['pointerdown', 'pointermove', 'keydown', 'input', 'scroll', 'touchstart'].forEach(type => {
    document.addEventListener(type, activity, { passive: true, capture: true });
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) heartbeat(); });
  window.addEventListener('pageshow', heartbeat);
  heartbeat();
})();
