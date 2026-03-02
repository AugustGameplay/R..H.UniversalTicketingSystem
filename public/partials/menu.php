<?php
/**
 * partials/menu.php
 * Sidebar con navegación controlada por rol.
 * Requiere que auth.php ya haya sido incluido (variables $_AUTH_* disponibles).
 */

// Leer datos de sesión de forma segura
$_menuUserId   = (int)($_SESSION['user_id'] ?? 0);
$_menuRoleId   = (int)($_SESSION['id_role'] ?? 0);
$_menuFullName = (string)($_SESSION['full_name'] ?? 'Usuario');

// Foto de perfil (si existe en BD — opcional, sin romper si no hay)
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

// Nombre corto (primer nombre)
$_menuShortName = explode(' ', trim($_menuFullName))[0];

// Items de navegación
$_navItems = [];

// Generar Ticket — todos los roles
$_navItems[] = [
    'href'  => 'generarTickets.php',
    'key'   => 'generarTickets',
    'icon'  => 'fa-solid fa-ticket',
    'label' => 'Generar Ticket',
];

// Mis Tickets — todos los roles
$_navItems[] = [
    'href'  => 'mis_tickets.php',
    'key'   => 'mis_tickets',
    'icon'  => 'fa-solid fa-list-check',
    'label' => 'Mis Tickets',
];

// Solo roles con acceso avanzado
if (in_array($_menuRoleId, [1, 2, 3], true)) {
    $_navItems[] = [
        'href'  => 'tickets.php',
        'key'   => 'tickets',
        'icon'  => 'fa-solid fa-table-list',
        'label' => 'Tickets',
    ];
    $_navItems[] = [
        'href'  => 'history.php',
        'key'   => 'history',
        'icon'  => 'fa-solid fa-clock-rotate-left',
        'label' => 'History',
    ];
}

// Solo Superadmin y Admin
if (in_array($_menuRoleId, [1, 2], true)) {
    $_navItems[] = [
        'href'  => 'users.php',
        'key'   => 'users',
        'icon'  => 'fa-solid fa-users',
        'label' => 'Users',
    ];
}
?>

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
        <?php foreach ($_navItems as $_item): ?>
            <a href="<?= htmlspecialchars($_item['href'], ENT_QUOTES, 'UTF-8') ?>"
               class="menu__item nav-link<?= (($active ?? '') === $_item['key']) ? ' active' : '' ?>"
               aria-current="<?= (($active ?? '') === $_item['key']) ? 'page' : 'false' ?>">
                <i class="<?= htmlspecialchars($_item['icon'], ENT_QUOTES, 'UTF-8') ?> me-2" aria-hidden="true"></i>
                <?= htmlspecialchars($_item['label'], ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endforeach; ?>
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