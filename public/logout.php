<?php
// logout.php
session_start();

// ✅ Mensaje flash para mostrar en login
$_SESSION['flash_success'] = "You have successfully logged out.";

// ✅ Eliminar SOLO variables de autenticación (y aliases)
unset(
    $_SESSION['user_id'],
    $_SESSION['id_user'],
    $_SESSION['id'],
    $_SESSION['uid'],
    $_SESSION['full_name'],
    $_SESSION['name'],
    $_SESSION['email'],
    $_SESSION['id_role'],
    $_SESSION['user']
);

// ✅ Regenerar ID de sesión por seguridad (evita arrastrar estado)
session_regenerate_id(true);

// ✅ Redirigir al login
header("Location: login.php");
exit;