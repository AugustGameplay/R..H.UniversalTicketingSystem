document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("btnSidebarOpen");
  const overlay = document.getElementById("sidebarOverlay");
  const sidebar = document.getElementById("sidebar");

  if (!btn || !overlay || !sidebar) {
    console.log("[sidebar] Falta algún elemento:", { btn, overlay, sidebar });
    return;
  }

  const toggle = () => {
    document.body.classList.toggle("sidebar-open");
    btn.setAttribute(
      "aria-expanded",
      document.body.classList.contains("sidebar-open") ? "true" : "false"
    );
  };

  const close = () => {
    document.body.classList.remove("sidebar-open");
    btn.setAttribute("aria-expanded", "false");
  };

  // Abrir/cerrar con el sandwich
  btn.addEventListener("click", (e) => {
    e.preventDefault();
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
    if (a && window.matchMedia("(max-width: 767.98px)").matches) close();
  });
});