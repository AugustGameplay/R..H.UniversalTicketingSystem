/**
 * sidebar.js
 * Drawer del sidebar en móvil.
 * Requiere:
 *  - Botón:   #btnSidebarOpen
 *  - Sidebar: #sidebar
 *  - Overlay: #sidebarOverlay
 *
 * Clase usada: body.sidebar-open
 */

(() => {
  // Evita doble inicialización si el script se carga más de una vez
  if (window.__rhrSidebarInit) return;
  window.__rhrSidebarInit = true;

  const init = () => {
    const btn = document.getElementById("btnSidebarOpen");
    const overlay = document.getElementById("sidebarOverlay");
    const sidebar = document.getElementById("sidebar");

    if (!btn || !overlay || !sidebar) {
      console.log("[sidebar] Falta algún elemento:", { btn, overlay, sidebar });
      return;
    }

    const isMobile = () => window.matchMedia("(max-width: 767.98px)").matches;

    const setOpen = (open) => {
      document.body.classList.toggle("sidebar-open", open);
      btn.setAttribute("aria-expanded", open ? "true" : "false");
      overlay.setAttribute("aria-hidden", open ? "false" : "true");
    };

    const toggle = () => setOpen(!document.body.classList.contains("sidebar-open"));
    const close = () => setOpen(false);

    // Estado inicial
    overlay.setAttribute("aria-hidden", "true");
    btn.setAttribute("aria-expanded", "false");

    // Abrir/cerrar con el sandwich (solo móvil)
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      if (!isMobile()) return;
      toggle();
    });

    // Cerrar tocando fuera
    overlay.addEventListener("click", close);

    // Cerrar con ESC
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") close();
    });

    // Cerrar al dar click en un link del menú en móvil
    sidebar.addEventListener("click", (e) => {
      const a = e.target.closest("a");
      if (a && isMobile()) close();
    });

    // Si cambia a desktop, limpia el estado
    window.addEventListener("resize", () => {
      if (!isMobile()) close();
    });
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
