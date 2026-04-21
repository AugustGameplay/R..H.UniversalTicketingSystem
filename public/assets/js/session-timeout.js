/**
 * session-timeout.js
 * ─────────────────────────────────────────────────────────────
 * Auto-logout por inactividad (15 min).
 *
 * - Monitorea: mousemove, mousedown, keydown, scroll, touchstart, click
 * - Muestra un modal de advertencia 2 min antes del timeout.
 * - Si el usuario interactúa durante la advertencia, se reinicia el timer.
 * - Si no, redirige a logout.php automáticamente.
 *
 * Configuración (ms):
 *   TIMEOUT       = 15 min  (900 000 ms)
 *   WARNING_BEFORE= 2 min   (120 000 ms)
 * ─────────────────────────────────────────────────────────────
 */
;(function () {
  'use strict';

  /* ── Config ────────────────────────────────────────────── */
  const TIMEOUT_MS        = 15 * 60 * 1000;   // 15 min
  const WARNING_BEFORE_MS = 2  * 60 * 1000;   //  2 min antes
  const TICK_INTERVAL_MS  = 1000;              //  actualizar countdown cada 1 s
  const LOGOUT_URL        = 'logout.php';
  const PING_URL          = 'api/session_ping.php';

  /* ── State ─────────────────────────────────────────────── */
  let lastActivity  = Date.now();
  let warningShown  = false;
  let tickInterval  = null;
  let modal         = null;
  let countdownEl   = null;

  /* ── Helpers ───────────────────────────────────────────── */
  function remaining()  { return TIMEOUT_MS - (Date.now() - lastActivity); }

  function fmtTime(ms) {
    const s   = Math.max(0, Math.ceil(ms / 1000));
    const min = Math.floor(s / 60);
    const sec = s % 60;
    return min > 0
      ? `${min}m ${String(sec).padStart(2, '0')}s`
      : `${sec}s`;
  }

  /* ── Build modal (once) ────────────────────────────────── */
  function ensureModal() {
    if (modal) return;

    modal = document.createElement('div');
    modal.id = 'sessionTimeoutOverlay';
    modal.className = 'sto-overlay';
    modal.innerHTML = `
      <div class="sto-card">
        <div class="sto-icon">
          <i class="fa-solid fa-clock" aria-hidden="true"></i>
        </div>
        <h2 class="sto-title">Session Expiring</h2>
        <p class="sto-body">
          Your session will expire due to inactivity in
          <strong id="stoCountdown" class="sto-countdown">--</strong>.
        </p>
        <p class="sto-body sto-hint">Move the mouse or press any key to stay connected.</p>
        <button type="button" class="sto-btn" id="stoContinueBtn">
          <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
          Continue Session
        </button>
      </div>
    `;

    document.body.appendChild(modal);
    countdownEl = document.getElementById('stoCountdown');

    document.getElementById('stoContinueBtn').addEventListener('click', function () {
      resetActivity();
    });
  }

  /* ── Show / hide warning ───────────────────────────────── */
  function showWarning() {
    if (warningShown) return;
    warningShown = true;
    ensureModal();
    modal.classList.add('sto-visible');
    startTick();
  }

  function hideWarning() {
    if (!warningShown) return;
    warningShown = false;
    if (modal) modal.classList.remove('sto-visible');
    stopTick();
  }

  /* ── Tick (1 s) ────────────────────────────────────────── */
  function startTick() {
    stopTick();
    tick(); // immediate
    tickInterval = setInterval(tick, TICK_INTERVAL_MS);
  }

  function stopTick() {
    if (tickInterval) { clearInterval(tickInterval); tickInterval = null; }
  }

  function tick() {
    const r = remaining();
    if (r <= 0) {
      forceLogout();
      return;
    }
    if (countdownEl) countdownEl.textContent = fmtTime(r);
  }

  /* ── Force logout ──────────────────────────────────────── */
  function forceLogout() {
    stopTick();
    window.location.href = LOGOUT_URL;
  }

  /* ── Ping server (keep PHP session alive) ──────────────── */
  function pingServer() {
    try {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', PING_URL, true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.send('ping=1');
    } catch (_) { /* silently fail */ }
  }

  /* ── Reset activity ────────────────────────────────────── */
  function resetActivity() {
    lastActivity = Date.now();
    hideWarning();
    pingServer();             // refresh PHP session
  }

  /* ── Activity listener (throttled) ─────────────────────── */
  let throttle = 0;
  function onActivity() {
    const now = Date.now();
    if (now - throttle < 5000) return;   // max 1 event cada 5 s
    throttle = now;

    if (warningShown) {
      // user moved / clicked while warning was visible → reset
      resetActivity();
    } else {
      lastActivity = now;
    }
  }

  /* ── Main check loop ───────────────────────────────────── */
  function checkLoop() {
    const r = remaining();
    if (r <= 0) {
      forceLogout();
    } else if (r <= WARNING_BEFORE_MS) {
      showWarning();
    }
  }

  /* ── Init ──────────────────────────────────────────────── */
  const events = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'];
  events.forEach(function (evt) {
    document.addEventListener(evt, onActivity, { passive: true });
  });

  // Check every 5 seconds
  setInterval(checkLoop, 5000);

  // Initial ping to sync PHP session timer
  pingServer();

})();
