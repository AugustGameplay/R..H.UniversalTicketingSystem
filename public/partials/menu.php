<?php
/**
 * partials/menu.php
 * Sidebar + Topbar móvil.
 * Requiere que auth.php ya haya sido incluido (variables $_AUTH_* y funciones auth_can()).
 */

// Leer datos de sesión
$_menuUserId   = (int)($_SESSION['user_id'] ?? 0);
$_menuRoleId   = (int)($_SESSION['id_role'] ?? 0);
$_menuFullName = (string)($_SESSION['full_name'] ?? 'Usuario');

// Foto de perfil (opcional)
$_menuPhoto = '';
if (isset($pdo)) {
    try {
        $stmtPhoto = $pdo->prepare("SELECT profile_photo FROM users WHERE id_user = :id LIMIT 1");
        $stmtPhoto->execute([':id' => $_menuUserId]);
        $_menuPhoto = (string)($stmtPhoto->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $_menuPhoto = '';
    }
}

$_menuShortName = explode(' ', trim($_menuFullName))[0];
?>

<!-- TOPBAR (solo móvil) -->
<header class="mobile-topbar" id="mobileTopbar">
  <button type="button"
          class="hamburger"
          id="btnSidebarOpen"
          aria-controls="sidebar"
          aria-expanded="false"
          aria-label="Abrir menú">
    <i class="fa-solid fa-bars" aria-hidden="true"></i>
  </button>

  <img class="mobile-topbar__logo" src="./assets/img/RHR UNIVERSAL-01.png" alt="RH&amp;R Universal">

  <a href="logout.php" class="hamburger" aria-label="Cerrar sesión" title="Cerrar sesión">
    <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
  </a>
</header>

<aside class="sidebar d-flex flex-column" id="sidebar">

    <!-- Logo -->
    <div class="sidebar__logo">
        <img src="./assets/img/RHR UNIVERSAL-01.png" alt="RH&amp;R Universal">
    </div>

    <!-- Badge usuario -->
    <div class="user-badge mx-auto">
        <?php if (!empty($_menuPhoto)): ?>
            <img class="user-badge__img" src="<?= htmlspecialchars($_menuPhoto, ENT_QUOTES, 'UTF-8') ?>" alt="Foto">
        <?php else: ?>
            <i class="fa-solid fa-user fa-xl" style="color:var(--sidebar,#0a3d63);"></i>
        <?php endif; ?>
    </div>

    <!-- Welcome -->
    <div class="welcome">
        <span class="welcome__label">Hi,</span>
        <span class="welcome__name"><?= htmlspecialchars($_menuShortName, ENT_QUOTES, 'UTF-8') ?>!</span>
    </div>

    <!-- Nav -->
    <nav class="menu mt-3" aria-label="Navegación principal">

        <!-- Generar Ticket (todos) -->
        <a href="generarTickets.php"
           class="menu__item nav-link<?= (($active ?? '') === 'generarTickets') ? ' active' : '' ?>"
           aria-current="<?= (($active ?? '') === 'generarTickets') ? 'page' : 'false' ?>">
            <i class="fa-solid fa-ticket me-2" aria-hidden="true"></i>
            Generate Ticket
        </a>

        <!-- Mis Tickets (todos) -->
        <a href="mis_tickets.php"
           class="menu__item nav-link<?= (($active ?? '') === 'mis_tickets') ? ' active' : '' ?>"
           aria-current="<?= (($active ?? '') === 'mis_tickets') ? 'page' : 'false' ?>">
            <i class="fa-solid fa-list-check me-2" aria-hidden="true"></i>
            My Tickets
        </a>

        <?php if (function_exists('auth_can') && auth_can('tickets')): ?>
        <a href="tickets.php"
           class="menu__item nav-link<?= (($active ?? '') === 'tickets') ? ' active' : '' ?>"
           aria-current="<?= (($active ?? '') === 'tickets') ? 'page' : 'false' ?>">
            <i class="fa-solid fa-table-list me-2" aria-hidden="true"></i>
            Tickets
        </a>
        <?php endif; ?>

        <?php if (function_exists('auth_can') && auth_can('history')): ?>
        <a href="history.php"
           class="menu__item nav-link<?= (($active ?? '') === 'history') ? ' active' : '' ?>"
           aria-current="<?= (($active ?? '') === 'history') ? 'page' : 'false' ?>">
            <i class="fa-solid fa-clock-rotate-left me-2" aria-hidden="true"></i>
            History
        </a>
        <?php endif; ?>

        <?php if (function_exists('auth_can') && auth_can('users')): ?>
        <a href="users.php"
           class="menu__item nav-link<?= (($active ?? '') === 'users') ? ' active' : '' ?>"
           aria-current="<?= (($active ?? '') === 'users') ? 'page' : 'false' ?>">
            <i class="fa-solid fa-users me-2" aria-hidden="true"></i>
            Users
        </a>
        <?php endif; ?>

    </nav>

    <!-- Spacer + logout -->
    <div class="mt-auto pt-3 d-flex justify-content-center">
        <a href="logout.php" class="logout d-flex align-items-center justify-content-center" title="Cerrar sesión">
            <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
        </a>
    </div>

</aside>

<!-- Overlay móvil -->
<div class="sidebar-overlay" id="sidebarOverlay" aria-hidden="true"></div>

<!-- JS del sidebar (móvil) -->
<script src="./assets/js/sidebar.js" defer></script>
