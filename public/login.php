<?php
// login.php
session_start();

// Si ya hay sesión iniciada, redirige (ajusta la ruta a tu dashboard)
if (!empty($_SESSION['user_id'])) {
  header("Location: dashboard.php");
  exit;
}

$msg = "";
$msg_type = "danger"; // Por defecto, errores son 'danger' (rojo). Cambia a 'success' si es un mensaje positivo.

// Ajusta esta ruta según tu proyecto:
require_once __DIR__ . '/config/db.php'; // <-- ejemplo: public/config/db.php

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
      // Ajusta nombres de tabla/columnas según tu BD:
      // Ejemplo típico: users(email, password_hash, id_role, full_name, status)
      $stmt = $pdo->prepare("
        SELECT id, full_name, email, password_hash, id_role
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
          // ✅ Opcional: Mostrar mensaje de éxito antes de redirigir (descomenta si quieres)
          // $msg = "Login exitoso. Redirigiendo...";
          // $msg_type = "success";
          // header("refresh:2;url=dashboard.php"); // Redirige después de 2 segundos
          // exit;

          // ✅ Login OK (sin mensaje, redirige directo)
          $_SESSION['user_id']   = (int)$user['id'];
          $_SESSION['full_name'] = $user['full_name'];
          $_SESSION['email']     = $user['email'];
          $_SESSION['id_role']   = (int)$user['id_role'];

          header("Location: dashboard.php"); // <-- ajusta destino
          exit;
        }
      }
    } catch (Throwable $e) {
      // En producción no muestres el error real
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

  <!-- ✅ NUEVO: Incluir Bootstrap CSS (primero, para que tu CSS lo sobrescriba) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">

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

      <!-- ✅ NUEVO: Mostrar alerta de Bootstrap solo si hay mensaje -->
      <?php if (!empty($msg)): ?>
        <div class="alert alert-<?php echo htmlspecialchars($msg_type, ENT_QUOTES, 'UTF-8'); ?> mt-3" role="alert" aria-live="polite">
          <?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?>
        </div>
      <?php endif; ?>

      <!-- ✅ Cambios clave: method="POST" y action apuntando al mismo archivo -->
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

      <!-- ✅ Eliminé el párrafo <p class="msg"> original, ahora usamos la alerta arriba -->
    </section>
  </main>

  <!-- ✅ NUEVO: Incluir Bootstrap JS (para funcionalidades como alertas dismissibles, si las agregas) -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>

</body>
</html>