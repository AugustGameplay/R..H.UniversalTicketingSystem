/**
 * session-timeout.js
 * ─────────────────────────────────────────────────────────────
 * Cierre automático de sesión por inactividad (15 min).
 *
 * - Escucha eventos de interacción del usuario.
 * - Muestra modal de advertencia 1 minuto antes del cierre.
 * - Redirige a logout.php al expirar el tiempo.
 * ─────────────────────────────────────────────────────────────
 */
(function () {
  'use strict';

  const TIMEOUT_MS   = 15 * 60 * 1000;   // 15 minutos
  const WARNING_MS   = 14 * 60 * 1000;   // Aviso a los 14 min (1 min antes)
  const EVENTS       = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'];

  let warningTimer  = null;
  let logoutTimer   = null;
  let countdownId   = null;
  let modalShown    = false;

  /* ── Elementos del modal ──────────────────────────────────── */
  function getModal()     { return document.getElementById('sessionTimeoutModal'); }
  function getCountdown() { return document.getElementById('sessionCountdown'); }
  function getBtnStay()   { return document.getElementById('btnStaySession'); }

  /* ── Actualizar countdown ─────────────────────────────────── */
  function startCountdown(seconds) {
    const el = getCountdown();
    if (!el) return;

    let remaining = seconds;
    el.textContent = remaining;

    countdownId = setInterval(() => {
      remaining--;
      if (remaining <= 0) {
        clearInterval(countdownId);
        remaining = 0;
      }
      el.textContent = remaining;
    }, 1000);
  }

  function stopCountdown() {
    if (countdownId) {
      clearInterval(countdownId);
      countdownId = null;
    }
  }

  /* ── Mostrar / ocultar modal ──────────────────────────────── */
  function showWarning() {
    const modal = getModal();
    if (!modal) return;
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    modalShown = true;
    startCountdown(60);
  }

  function hideWarning() {
    const modal = getModal();
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
    modalShown = false;
    stopCountdown();
  }

  /* ── Redirigir a logout ───────────────────────────────────── */
  function doLogout() {
    window.location.href = 'logout.php?reason=timeout';
  }

  /* ── Reset de timers ──────────────────────────────────────── */
  function resetTimers() {
    // Si el modal de warning ya está visible, el usuario interactuó → ocultar
    if (modalShown) hideWarning();

    clearTimeout(warningTimer);
    clearTimeout(logoutTimer);

    warningTimer = setTimeout(showWarning, WARNING_MS);
    logoutTimer  = setTimeout(doLogout, TIMEOUT_MS);
  }

  /* ── Inicializar ──────────────────────────────────────────── */
  function init() {
    // Crear el modal de warning si no existe en el DOM
    if (!getModal()) createModal();

    // Escuchar interacciones del usuario
    EVENTS.forEach(evt => {
      document.addEventListener(evt, resetTimers, { passive: true });
    });

    // Botón "Seguir trabajando"
    const btnStay = getBtnStay();
    if (btnStay) {
      btnStay.addEventListener('click', () => {
        // Ping al servidor para renovar la sesión
        fetch(window.location.href, { method: 'HEAD', cache: 'no-store' }).catch(() => {});
        resetTimers();
      });
    }

    // Arrancar timers
    resetTimers();
  }

  /* ── Crear modal dinámicamente ────────────────────────────── */
  function createModal() {
    const overlay = document.createElement('div');
    overlay.id = 'sessionTimeoutModal';
    overlay.className = 'session-timeout-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-labelledby', 'sessionTimeoutTitle');

    overlay.innerHTML = `
      <div class="session-timeout-card">
        <div class="session-timeout-icon">
          <i class="fa-solid fa-clock" aria-hidden="true"></i>
        </div>
        <h2 id="sessionTimeoutTitle" class="session-timeout-title">Sesión a punto de expirar</h2>
        <p class="session-timeout-text">
          Tu sesión se cerrará automáticamente en
          <strong id="sessionCountdown" class="session-timeout-countdown">60</strong> segundos
          por inactividad.
        </p>
        <div class="session-timeout-actions">
          <button type="button" id="btnStaySession" class="session-timeout-btn session-timeout-btn--stay">
            <i class="fa-solid fa-rotate-right me-2" aria-hidden="true"></i>
            Seguir trabajando
          </button>
          <a href="logout.php" class="session-timeout-btn session-timeout-btn--logout">
            <i class="fa-solid fa-right-from-bracket me-2" aria-hidden="true"></i>
            Cerrar sesión
          </a>
        </div>
      </div>
    `;

    document.body.appendChild(overlay);

    // Re-bind el botón después de insertar en el DOM
    const btnStay = document.getElementById('btnStaySession');
    if (btnStay) {
      btnStay.addEventListener('click', () => {
        fetch(window.location.href, { method: 'HEAD', cache: 'no-store' }).catch(() => {});
        resetTimers();
      });
    }
  }

  // Iniciar cuando el DOM esté listo
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
