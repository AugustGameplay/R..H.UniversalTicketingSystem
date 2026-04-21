<?php
// login.php
session_start();

// ✅ Flash toast (logout)
$flash_success = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

// ✅ Si ya hay sesión iniciada, TODOS los roles van a generarTickets.php
if (!empty($_SESSION['user_id']) || !empty($_SESSION['id_user'])) {
  header("Location: generarTickets.php");
  exit;
}

$msg = "";
$msg_type = "warning"; // errores ahora en amarillo (warning)

// Ajusta esta ruta según tu proyecto:
require_once __DIR__ . '/config/db.php';

function clean(string $s): string {
  return trim($s);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = clean($_POST['email'] ?? '');
  $password = (string)($_POST['password'] ?? '');

  if ($email === '' || $password === '') {
    $msg = "Please fill out email and password.";
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $msg = "Invalid email format.";
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
        $msg = "Incorrect credentials.";
      } else {
        $hash = $user['password_hash'] ?? '';

        if ($hash === '' || !password_verify($password, $hash)) {
          $msg = "Incorrect credentials.";
        } else {
          // ✅ Login OK — sesión robusta (compatible con módulos antiguos/nuevos)
          session_regenerate_id(true);

          $userId = (int)$user['id_user'];
          $roleId = (int)$user['id_role'];
          $fullName = (string)($user['full_name'] ?? '');
          $userEmail = (string)($user['email'] ?? '');

          // Formato principal
          $_SESSION['user_id']   = $userId;
          $_SESSION['full_name'] = $fullName;
          $_SESSION['email']     = $userEmail;
          $_SESSION['id_role']   = $roleId;

          // Compatibilidad extra (por si auth.php/menu usan otros nombres)
          $_SESSION['id_user'] = $userId;
          $_SESSION['id']      = $userId;
          $_SESSION['uid']     = $userId;
          $_SESSION['name']    = $fullName;

          // Algunos módulos esperan un arreglo "user"
          $_SESSION['user'] = [
            'id_user'   => $userId,
            'user_id'   => $userId,
            'id'        => $userId,
            'uid'       => $userId,
            'full_name' => $fullName,
            'name'      => $fullName,
            'email'     => $userEmail,
            'id_role'   => $roleId,
          ];

          // ✅ Inicializar marca de actividad para timeout automático
          $_SESSION['last_activity'] = time();

          // ✅ SOLUCIÓN: TODOS los roles autenticados van a generarTickets.php
          $_SESSION['flash_login'] = "You have successfully logged in.";
          header("Location: generarTickets.php");
          exit;
        }
      }
    } catch (Throwable $e) {
      // Si quieres depurar en local, descomenta la siguiente línea:
      // error_log('Login error: ' . $e->getMessage());
      $msg = "An error occurred during login.";
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
  <link rel="icon" type="image/png" href="./assets/img/isotopo.png" />

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">

  <!-- FontAwesome (para el icono check) -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <link rel="stylesheet" href="./assets/css/brand.css">
  <link rel="stylesheet" href="./assets/css/login.css">
  <link rel="stylesheet" href="./assets/css/rhr-toast.css">
  <script defer src="./assets/js/rhr-toast.js"></script>
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
        <div data-rhr-toast="<?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?>" data-rhr-toast-type="<?php echo htmlspecialchars($msg_type, ENT_QUOTES, 'UTF-8'); ?>"></div>
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
    <div data-rhr-toast="<?php echo htmlspecialchars($flash_success, ENT_QUOTES, 'UTF-8'); ?>" data-rhr-toast-type="success"></div>
  <?php endif; ?>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
          integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>

</body>
</html>