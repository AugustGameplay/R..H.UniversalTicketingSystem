<?php
// public/partials/menu.php
$active = $active ?? '';

// ✅ Primer nombre desde sesión
$fullName  = trim($_SESSION['full_name'] ?? 'Usuario');
$firstName = ($fullName !== '') ? explode(' ', $fullName)[0] : 'Usuario';

/* ====== SOLO LO NUEVO: traer foto del usuario ====== */
require_once __DIR__ . '/../config/db.php';

// Detecta el id del usuario en sesión (ajusta si tu sesión usa otro key)
$sessionUserId = $_SESSION['id_user'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;

$avatarUrl = null;

if ($sessionUserId) {
  // En tu BD el PK se llama id_user (según tu captura)
  $stmt = $pdo->prepare("SELECT profile_photo FROM users WHERE id_user = ? LIMIT 1");
  $stmt->execute([$sessionUserId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!empty($row['profile_photo'])) {
    // Como estás en public/ y tus uploads están en public/uploads/avatars/
    $photo = $row['profile_photo'];

if (!empty($photo)) {
  // Si ya viene como "uploads/avatars/archivo.webp"
  if (strpos($photo, 'uploads/') === 0) {
    $avatarUrl = './' . $photo;
  } else {
    // Si solo viene el nombre "archivo.webp"
    $avatarUrl = './uploads/avatars/' . $photo;
  }
}
  }
}
/* ================================================ */
?>

<!-- HEADER MÓVIL (solo pantallas < md) -->
<header class="mobile-topbar d-md-none">
  <button
    id="btnSidebarOpen"
    class="hamburger"
    type="button"
    aria-label="Abrir menú"
    aria-controls="sidebar"
    aria-expanded="false"
  >
    <i class="fa-solid fa-bars"></i>
  </button>

  <div class="mobile-topbar__brand">
    <img src="./assets/img/RHR UNIVERSAL-01.png" alt="RH&R Universal" class="mobile-topbar__logo">
  </div>
</header>

<!-- OVERLAY -->
<div id="sidebarOverlay" class="sidebar-overlay" aria-hidden="true"></div>

<!-- SIDEBAR -->
<aside id="sidebar" class="sidebar d-flex flex-column" aria-label="Menú lateral">

  <!-- Logo solo desktop -->
  <div class="sidebar__logo text-center d-none d-md-block">
    <img src="./assets/img/RHR UNIVERSAL-01.png" alt="RH&R Universal" class="img-fluid">
  </div>

  <div class="welcome text-center">
    <span class="welcome__label">Welcome,</span>
    <span class="welcome__name">
      <?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?>
    </span>
  </div>

  <!-- ✅ AQUÍ: ya no personita, ahora foto si existe -->
  <div class="user-badge mx-auto" aria-hidden="true">
    <?php if ($avatarUrl): ?>
      <img
        class="user-badge__img"
        src="<?php echo htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8'); ?>"
        alt="Foto de perfil"
      >
    <?php else: ?>
      <i class="fa-regular fa-user fs-3 text-dark"></i>
    <?php endif; ?>
  </div>

  <nav class="menu nav flex-column mt-3">
    <a class="menu__item nav-link <?php echo ($active === 'generarTickets') ? 'active' : ''; ?>" href="./generarTickets.php">
      Generar Ticket
    </a>

    <a class="menu__item nav-link <?php echo ($active === 'tickets') ? 'active' : ''; ?>" href="./tickets.php">
      Tickets
    </a>

    <a class="menu__item nav-link <?php echo ($active === 'users') ? 'active' : ''; ?>" href="./users.php">
      Users
    </a>

    <a class="menu__item nav-link <?php echo ($active === 'history') ? 'active' : ''; ?>" href="./history.php">
      History
    </a>
  </nav>

  <!-- LOGOUT (POST) -->
  <div class="mt-auto d-flex justify-content-end pt-3">
    <form action="./logout.php" method="POST" class="m-0">
      <button class="logout" type="submit" title="Logout" aria-label="Logout">
        <i class="fa-solid fa-right-from-bracket"></i>
      </button>
    </form>
  </div>

</aside>