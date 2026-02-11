<?php
// logout.php
session_start();

// ✅ Mensaje flash para el login
$_SESSION['flash_success'] = "Has cerrado sesión correctamente.";

// ✅ Borra SOLO los datos de autenticación
unset($_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['email'], $_SESSION['id_role']);

// ✅ Regenera el ID de sesión (seguridad)
session_regenerate_id(true);

// ✅ Redirige al login
header("Location: login.php");
exit;