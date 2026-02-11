<?php
// menu.php (sidebar reutilizable)
$active = $active ?? '';
?>
<aside class="sidebar d-flex flex-column">
  <div class="sidebar__logo text-center">
    <img src="./assets/img/logo-white.svg" alt="RH&R Universal" class="img-fluid">
  </div>

  <div class="welcome text-center">
    <span class="welcome__label">Welcome,</span>
    <span class="welcome__name">
      <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Usuario', ENT_QUOTES, 'UTF-8'); ?>
    </span>
  </div>

  <div class="user-badge mx-auto" aria-hidden="true">
    <i class="fa-regular fa-user fs-3 text-dark"></i>
  </div>

  <nav class="menu nav flex-column mt-3">
    <a class="menu__item nav-link <?php echo ($active === 'generar') ? 'active' : ''; ?>" href="./generarTickets.php">Generar Ticket</a>
    <a class="menu__item nav-link <?php echo ($active === 'tickets') ? 'active' : ''; ?>" href="./tickets.php">Tickets</a>
    <a class="menu__item nav-link <?php echo ($active === 'users') ? 'active' : ''; ?>" href="./users.php">Users</a>
    <a class="menu__item nav-link <?php echo ($active === 'history') ? 'active' : ''; ?>" href="./history.php">History</a>
  </nav>

  <!-- ✅ LOGOUT (POST) -->
  <div class="mt-auto d-flex justify-content-end pt-3">
    <form action="./logout.php" method="POST" class="m-0">
      <button class="logout" type="submit" title="Logout" aria-label="Logout">
        <i class="fa-solid fa-right-from-bracket"></i>
      </button>
    </form>
  </div>
</aside>