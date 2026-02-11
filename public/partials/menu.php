<?php
$active = $active ?? '';
?>

<!-- HEADER MÓVIL (solo se verá en pantallas pequeñas) -->
<header class="mobile-topbar d-md-none">

  <button id="btnSidebarOpen" class="hamburger" type="button" aria-label="Abrir menú" aria-controls="sidebar" aria-expanded="false">
    <i class="fa-solid fa-bars"></i>
  </button>

  <div class="mobile-topbar__brand">
    <img src="./assets/img/logo-white.svg" alt="RH&R Universal" class="mobile-topbar__logo">
  </div>
</header>

<!-- OVERLAY (para cerrar al tocar fuera) -->
<div id="sidebarOverlay" class="sidebar-overlay" aria-hidden="true"></div>

<aside id="sidebar" class="sidebar d-flex flex-column" aria-label="Menú lateral">
  <div class="sidebar__logo text-center d-none d-md-block">
    <img src="./assets/img/logo-white.svg" alt="RH&R Universal" class="img-fluid">
  </div>

  <div class="welcome text-center">
    <span class="welcome__label">Welcome,</span>
    <span class="welcome__name">Emilio</span>
  </div>

  <div class="user-badge mx-auto" aria-hidden="true">
    <i class="fa-regular fa-user fs-3 text-dark"></i>
  </div>

  <nav class="menu nav flex-column mt-3">
    <a class="menu__item nav-link <?= ($active === 'dashboard') ? 'active' : '' ?>" href="./generarTickets.php">Generar Ticket</a>
    <a class="menu__item nav-link <?= ($active === 'tickets') ? 'active' : '' ?>" href="./tickets.php">Tickets</a>
    <a class="menu__item nav-link <?= ($active === 'users') ? 'active' : '' ?>" href="./users.php">Users</a>
    <a class="menu__item nav-link <?= ($active === 'history') ? 'active' : '' ?>" href="./history.php">History</a>
  </nav>

  <div class="mt-auto d-flex justify-content-end pt-3">
    <button class="logout" type="button" title="Logout" aria-label="Logout">
      <i class="fa-solid fa-right-from-bracket"></i>
    </button>
  </div>
</aside>