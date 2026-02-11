<?php
// login.php
session_start();

// ✅ Flash toast (logout)
$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

// Si ya hay sesión iniciada, redirige (ajusta la ruta a tu dashboard)
if (!empty($_SESSION['user_id'])) {
  header("Location: generarTickets.php");
  exit;
}

$msg = "";
$msg_type = "danger"; // errores: danger (rojo)

// Ajusta esta ruta según tu proyecto:
require_once __DIR__ . '/config/db.php';

function clean(string $s): string {
  return trim($s);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = clean($_POST['email'] ?? '');
  $password = (string)($_POST['password'] ?? '');

  if ($email === '' || $password === '') {
    $msg = "Completa email y password.";
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $msg = "Email no válido.";
  } else {
    try {
      // ✅ Ajustado a tu estructura: id_user
      $stmt = $pdo->prepare("
        SELECT id_user, full_name, email, password_hash, id_role
        FROM users
        WHERE email = :email
        LIMIT 1
      ");
      $stmt->execute([':email' => $email]);
      $user = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$user) {
        $msg = "Credenciales incorrectas.";
      } else {
        $hash = $user['password_hash'] ?? '';

        if ($hash === '' || !password_verify($password, $hash)) {
          $msg = "Credenciales incorrectas.";
        } else {
          // ✅ Login OK
          $_SESSION['user_id']   = (int)$user['id_user'];
          $_SESSION['full_name'] = $user['full_name'];
          $_SESSION['email']     = $user['email'];
          $_SESSION['id_role']   = (int)$user['id_role'];

          header("Location: generarTickets.php");
          exit;
        }
      }
    } catch (Throwable $e) {
      $msg = "Ocurrió un error al iniciar sesión.";
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login | RH&R Ticketing</title>

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">

  <!-- FontAwesome (para el icono check) -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <link rel="stylesheet" href="./assets/css/brand.css">
  <link rel="stylesheet" href="./assets/css/login.css">
</head>
<body>

  <main class="page">
    <section class="login-card" aria-labelledby="title">

      <div class="avatar" aria-hidden="true">
        <div class="avatar__head"></div>
        <div class="avatar__body"></div>
      </div>

      <div class="mini-logo">
        <img src="./assets/img/icono-rh.svg" alt="RH&R Universal" class="mini-logo__img">
      </div>

      <h1 id="title" class="title">Login</h1>

      <!-- Alerta bootstrap (errores login) -->
      <?php if (!empty($msg)): ?>
        <div class="alert alert-<?php echo htmlspecialchars($msg_type, ENT_QUOTES, 'UTF-8'); ?> mt-3" role="alert" aria-live="polite">
          <?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?>
        </div>
      <?php endif; ?>

      <form class="form" id="loginForm" method="POST" action="login.php" novalidate>
        <div class="input-wrap">
          <input
            class="input"
            id="email"
            type="email"
            name="email"
            placeholder="Email"
            autocomplete="username"
            required
            value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
          >
          <img class="input-icon" src="./assets/img/mail.svg?v=1" alt="" aria-hidden="true">
          <small class="help" id="emailHelp"></small>
        </div>

        <div class="input-wrap">
          <input
            class="input"
            id="password"
            type="password"
            name="password"
            placeholder="Password"
            autocomplete="current-password"
            minlength="6"
            required
          >
          <img class="input-icon" src="./assets/img/lock.svg?v=1" alt="" aria-hidden="true">
          <small class="help" id="passHelp"></small>
        </div>

        <button class="btn-login" type="submit" id="submitBtn">Login</button>
      </form>

    </section>
  </main>

  <!-- ✅ Toast logout éxito (arriba derecha) -->
  <?php if (!empty($flash_success)): ?>
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 2000;">
      <div id="logoutToast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
          <div class="toast-body">
            <i class="fa-solid fa-circle-check me-2"></i>
            <?php echo htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8'); ?>
          </div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
          integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>

  <?php if (!empty($flash_success)): ?>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const el = document.getElementById('logoutToast');
      if (!el) return;
      const t = new bootstrap.Toast(el, { delay: 2600 });
      t.show();
    });
  </script>
  <?php endif; ?>

</body>
</html>