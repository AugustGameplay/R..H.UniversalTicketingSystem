<?php
// users.php
require __DIR__ . '/config/db.php';
require __DIR__ . '/partials/auth.php';
require __DIR__ . '/config/mailer.php';
require_once __DIR__ . '/config/csrf.php';
$active = 'users';

// Flash en sesión (para mostrar contraseñas generadas sin exponerlas en URL)
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// Helpers
function e($str) {
  return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
function roleClass($rol) {
  if ($rol === 'Superadmin') return 'role-super';
  if ($rol === 'Admin') return 'role-admin';
  return 'role-user';
}

function getRoleNameEn($rol) {
  return match($rol) {
    'Superadmin', 'Super admin' => 'Super Admin',
    'Admin', 'Administrador' => 'Admin',
    'Usuario General', 'User' => 'General User',
    default => $rol
  };
}

function getAreaNameEn($area) {
  return match($area) {
    'Marketing e IT', 'Marketing and IT' => 'Marketing and IT',
    'RH', 'HR'                           => 'HR',
    'Operaciones', 'Operations'          => 'Operations',
    'Contabilidad', 'Accounting'         => 'Accounting',
    'Ventas', 'Sales'                    => 'Sales',
    'Soporte TI', 'IT Support'           => 'IT Support',
    'Recursos Humanos'                   => 'Human Resources',
    'Finanzas', 'Finance'                => 'Finance',
    'Legal'                              => 'Legal',
    'Marketing'                          => 'Marketing',
    'Managers'                           => 'Managers',
    'Corporate'                          => 'Corporate',
    'Recruiters'                         => 'Recruiters',
    'Workers Comp'                       => 'Workers Comp',
    default => $area
  };
}

function detectOrEnsurePhoneColumn(PDO $pdo): ?string {
  // Intentamos detectar una columna existente para teléfono.
  $candidates = ['phone', 'telefono', 'phone_number', 'mobile', 'cellphone', 'tel', 'telephone'];
  try {
    $q = $pdo->prepare("
      SELECT COLUMN_NAME
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'users'
        AND COLUMN_NAME = :col
      LIMIT 1
    ");

    foreach ($candidates as $col) {
      $q->execute([':col' => $col]);
      $found = $q->fetchColumn();
      if ($found) return (string)$found;
    }

    // Si no existe ninguna, intentamos crear 'phone' (seguro en local).
    try {
      $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL DEFAULT NULL AFTER email");
      return 'phone';
    } catch (Throwable $t) {
      // Si no hay permisos o ya existe, simplemente regresamos null.
      return null;
    }

  } catch (Throwable $t) {
    return null;
  }
}

function detectOrEnsureExtensionColumn(PDO $pdo): ?string {
  try {
    $q = $pdo->prepare("
      SELECT COLUMN_NAME
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'users'
        AND COLUMN_NAME = 'extension'
      LIMIT 1
    ");
    $q->execute();
    if ($q->fetchColumn()) return 'extension';

    // Crear columna si no existe
    try {
      $pdo->exec("ALTER TABLE users ADD COLUMN extension VARCHAR(20) NULL DEFAULT NULL AFTER phone");
      return 'extension';
    } catch (Throwable $t) {
      return null;
    }
  } catch (Throwable $t) {
    return null;
  }
}


function generateStrongPassword(int $length = 12): string {
  // Asegura al menos: 1 mayúscula, 1 minúscula, 1 número, 1 símbolo
  $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
  $lower = 'abcdefghijkmnopqrstuvwxyz';
  $nums  = '23456789';
  $sym   = '!@#$%*-_+';

  $all = $upper . $lower . $nums . $sym;
  $pass = [];
  $pass[] = $upper[random_int(0, strlen($upper)-1)];
  $pass[] = $lower[random_int(0, strlen($lower)-1)];
  $pass[] = $nums[random_int(0, strlen($nums)-1)];
  $pass[] = $sym[random_int(0, strlen($sym)-1)];

  for ($i = 4; $i < $length; $i++) {
    $pass[] = $all[random_int(0, strlen($all)-1)];
  }
  shuffle($pass);
  return implode('', $pass);
}

function saveProfilePhoto(array $file): ?string {
  if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
  if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Error al subir la imagen.');

  // Límite 2MB
  if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
    throw new RuntimeException('La imagen excede 2MB.');
  }

  $tmp = $file['tmp_name'] ?? '';
  if ($tmp === '' || !is_uploaded_file($tmp)) {
    throw new RuntimeException('Archivo inválido.');
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($tmp);
  $extMap = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
  ];
  if (!isset($extMap[$mime])) {
    throw new RuntimeException('Formato no permitido. Usa JPG, PNG o WEBP.');
  }

  $uploadsDir = __DIR__ . '/uploads/avatars';
  if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0755, true);
  }

  $filename = 'u_' . bin2hex(random_bytes(8)) . '.' . $extMap[$mime];
  $dest = $uploadsDir . '/' . $filename;

  if (!move_uploaded_file($tmp, $dest)) {
    throw new RuntimeException('No se pudo guardar la imagen.');
  }

  // Ruta pública relativa (asumiendo que users.php vive en la raíz pública)
  return 'uploads/avatars/' . $filename;
}

$createErrors = [];
$editErrors   = [];
$passErrors   = [];
$deleteErrors = [];

$createdPassword = null;

// Flash (sesión)
$flashGeneratedPass = $_SESSION['flash_generated_pass'] ?? null; // ['full_name','email','password']
unset($_SESSION['flash_generated_pass']);

// Flash (mensajes por URL)
$flashCreated   = isset($_GET['created']);
$flashPassUp    = isset($_GET['pass_updated']);
$flashDeleted   = isset($_GET['deleted']);
$flashUpdated   = isset($_GET['updated']);

// Traer roles para el select
$rolesStmt = $pdo->query("SELECT id_role, name FROM roles ORDER BY id_role");
$roles = $rolesStmt->fetchAll();

// Columna teléfono (detecta o crea si hace falta)
$phoneCol = detectOrEnsurePhoneColumn($pdo);
$extensionCol = detectOrEnsureExtensionColumn($pdo);

// ============================
// VALIDATE CSRF GLOBALLY
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate()) {
    die("CSRF validation failed");
  }
}

// ============================
// CREATE USER (POST)
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_user') {
  $full_name = trim($_POST['full_name'] ?? '');
  $email     = trim($_POST['email'] ?? '');
  $area      = trim($_POST['area'] ?? '');
  $id_role   = (int)($_POST['id_role'] ?? 0);
  $phone     = trim($_POST['phone'] ?? '');
  $extension = trim($_POST['extension'] ?? '');

  // Foto (opcional)
  $profilePhotoPath = null;

  // Password opcional: si viene vacío, generamos una
  $plain_pass = trim($_POST['password_plain'] ?? '');
  $passwordWasGenerated = false;

  // Validaciones
  if ($full_name === '') $createErrors[] = "Name is required.";
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $createErrors[] = "Invalid email.";
  if (!in_array($area, ['Accounting', 'Corporate', 'HR', 'IT Support', 'Managers', 'Marketing and IT', 'Operations', 'Recruiters', 'Workers Comp'], true)) $createErrors[] = "Invalid area.";
  if ($id_role <= 0) $createErrors[] = "Invalid role.";
  if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) $createErrors[] = "Invalid phone number.";

  if ($plain_pass === '') {
    $plain_pass = 'RHR-' . generateStrongPassword(10);
    $passwordWasGenerated = true;
  }

  // Guardar foto si viene
  try {
    if (isset($_FILES['profile_photo'])) {
      $profilePhotoPath = saveProfilePhoto($_FILES['profile_photo']);
    }
  } catch (Throwable $t) {
    $createErrors[] = $t->getMessage();
  }

  if (!$createErrors) {
    try {
      $hash = password_hash($plain_pass, PASSWORD_BCRYPT);

      $extraCols = '';
      $extraVals = '';
      if ($phoneCol) {
        $extraCols .= ", `" . $phoneCol . "`";
        $extraVals .= ", :phone";
      }
      if ($extensionCol) {
        $extraCols .= ", `extension`";
        $extraVals .= ", :extension";
      }
      $sql = "
        INSERT INTO users (full_name, email, area, id_role, password_hash, profile_photo{$extraCols})
        VALUES (:full_name, :email, :area, :id_role, :password_hash, :profile_photo{$extraVals})
      ";
      $ins = $pdo->prepare($sql);
      $params = [
        ':full_name' => $full_name,
        ':email' => $email,
        ':area' => $area,
        ':id_role' => $id_role,
        ':password_hash' => $hash,
        ':profile_photo' => $profilePhotoPath,
      ];
      if ($phoneCol) {
        $params[':phone'] = ($phone !== '') ? $phone : null;
      }
      if ($extensionCol) {
        $params[':extension'] = ($extension !== '') ? $extension : null;
      }
      $ins->execute($params);

      $newId = (int)$pdo->lastInsertId();

      // Flash para mostrar la contraseña generada una sola vez (solo si fue auto-generada)
      if ($passwordWasGenerated) {
        $_SESSION['flash_generated_pass'] = [
          'id_user' => $newId,
          'full_name' => $full_name,
          'email' => $email,
          'password' => $plain_pass,
        ];
      }

      // Enviar email de bienvenida con credenciales
      $roleName = 'General User';
      foreach ($roles as $r) {
        if ((int)$r['id_role'] === $id_role) {
          $roleName = getRoleNameEn($r['name']);
          break;
        }
      }
      try {
        notify_user_created([
          'full_name' => $full_name,
          'email'     => $email,
          'password'  => $plain_pass,
          'area'      => getAreaNameEn($area),
          'role_name' => $roleName,
        ]);
      } catch (Throwable $mailErr) {
        error_log('[USERS] Could not send welcome email: ' . $mailErr->getMessage());
      }

      header("Location: users.php?created=1");
      exit;

    } catch (PDOException $ex) {
      if (($ex->errorInfo[0] ?? '') === '23000') {
        $createErrors[] = "That email already exists.";
      } else {
        $createErrors[] = "Error saving: " . $ex->getMessage();
      }
    }
  }
}


// ============================
// UPDATE USER (POST)
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_user') {
  $id_user   = (int)($_POST['id_user'] ?? 0);
  $full_name = trim($_POST['full_name'] ?? '');
  $email     = trim($_POST['email'] ?? '');
  $area      = trim($_POST['area'] ?? '');
  $id_role   = (int)($_POST['id_role'] ?? 0);
  $phone     = trim($_POST['phone'] ?? '');
  $extension = trim($_POST['extension'] ?? '');

  $editErrors = [];

  if ($id_user <= 0) $editErrors[] = "Invalid user.";
  if ($full_name === '') $editErrors[] = "Name is required.";
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $editErrors[] = "Invalid email.";
  if (!in_array($area, ['Accounting', 'Corporate', 'HR', 'IT Support', 'Managers', 'Marketing and IT', 'Operations', 'Recruiters', 'Workers Comp'], true)) $editErrors[] = "Invalid area.";
  if ($id_role <= 0) $editErrors[] = "Invalid role.";
  if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) $editErrors[] = "Invalid phone number.";

  // Obtener foto actual para conservar o reemplazar
  $currentPhoto = null;
  if (!$editErrors) {
    $q = $pdo->prepare("SELECT profile_photo FROM users WHERE id_user = :id");
    $q->execute([':id' => $id_user]);
    $currentPhoto = $q->fetchColumn() ?: null;
  }

  $newPhotoPath = null;
  $hasNewPhoto = isset($_FILES['profile_photo']) && ($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

  if ($hasNewPhoto) {
    try {
      $newPhotoPath = saveProfilePhoto($_FILES['profile_photo']); // devuelve ruta pública relativa
    } catch (Throwable $t) {
      $editErrors[] = $t->getMessage();
    }
  }

  if (!$editErrors) {
    try {
      $pdo->beginTransaction();

      $extraSet = '';
      if ($phoneCol) {
        $extraSet .= ", `" . $phoneCol . "` = :phone";
      }
      if ($extensionCol) {
        $extraSet .= ", `extension` = :extension";
      }
      $sql = "
        UPDATE users
        SET full_name = :full_name,
            email = :email,
            area = :area,
            id_role = :id_role,
            profile_photo = :profile_photo{$extraSet}
        WHERE id_user = :id_user
      ";
      $up = $pdo->prepare($sql);

      $finalPhoto = $hasNewPhoto ? $newPhotoPath : $currentPhoto;

      $params = [
        ':full_name' => $full_name,
        ':email' => $email,
        ':area' => $area,
        ':id_role' => $id_role,
        ':profile_photo' => $finalPhoto,
        ':id_user' => $id_user
      ];
      if ($phoneCol) {
        $params[':phone'] = ($phone !== '') ? $phone : null;
      }
      if ($extensionCol) {
        $params[':extension'] = ($extension !== '') ? $extension : null;
      }
      $up->execute($params);

      $pdo->commit();

      // Si se subió nueva foto, intenta borrar la anterior (opcional y seguro)
      if ($hasNewPhoto && $currentPhoto && str_starts_with($currentPhoto, 'uploads/avatars/')) {
        $oldPath = __DIR__ . '/' . $currentPhoto;
        if (is_file($oldPath)) { @unlink($oldPath); }
      }

      header("Location: users.php?updated=1");
      exit;

    } catch (PDOException $ex) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      if (($ex->errorInfo[0] ?? '') === '23000') {
        $editErrors[] = "That email already exists.";
      } else {
        $editErrors[] = "Error updating: " . $ex->getMessage();
      }
    }
  }

  }

// ============================
// UPDATE PASSWORD (POST)
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_password') {
  $id_user   = (int)($_POST['id_user'] ?? 0);
  $new_pass  = trim($_POST['new_password'] ?? '');

  // Reglas: min 8, 1 mayúscula, 1 número, 1 símbolo
  $isStrong = (bool)preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/', $new_pass);

  if ($id_user <= 0) $passErrors[] = "Invalid user.";
  if ($new_pass === '') $passErrors[] = "Password cannot be empty.";
  if ($new_pass !== '' && !$isStrong) {
    $passErrors[] = "Weak password: minimum 8 characters, including an uppercase letter, a number, and a symbol.";
  }

  if (!$passErrors) {
    try {
      $hash = password_hash($new_pass, PASSWORD_BCRYPT);

      $up = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id_user = :id_user");
      $up->execute([
        ':hash'    => $hash,
        ':id_user' => $id_user
      ]);

      header("Location: users.php?pass_updated=1");
      exit;

    } catch (PDOException $ex) {
      $passErrors[] = "Error updating password: " . $ex->getMessage();
    }
  }
}

// ============================
// DELETE USER (POST)
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user') {
  $id_user = (int)($_POST['id_user'] ?? 0);
  $currentUserId = (int)($_SESSION['user_id'] ?? 0);

  if ($id_user <= 0) $deleteErrors[] = "Invalid user.";
  if ($currentUserId > 0 && $id_user === $currentUserId) {
    $deleteErrors[] = "You cannot delete your own user.";
  }

  if (!$deleteErrors) {
    try {
      $del = $pdo->prepare("DELETE FROM users WHERE id_user = :id_user");
      $del->execute([':id_user' => $id_user]);

      header("Location: users.php?deleted=1");
      exit;

    } catch (PDOException $ex) {
      $deleteErrors[] = "Error deleting user: " . $ex->getMessage();
    }
  }
}

// ============================
// BÚSQUEDA (por nombre)
// ============================
$q = trim($_GET['q'] ?? '');
$hasQ = ($q !== '');


// ============================
// SORT (igual que tickets.php)
// ============================
$CURRENT_SORT  = $_GET['sort'] ?? 'id';
$CURRENT_DIRIN = strtolower($_GET['dir'] ?? 'desc');
if (!in_array($CURRENT_DIRIN, ['asc','desc'], true)) { $CURRENT_DIRIN = 'desc'; }

// Expresión segura para teléfono (si no existe, ordena por email como fallback)
$phoneExpr = $phoneCol ? ('u.`' . $phoneCol . '`') : 'u.email';

// Whitelist columnas (evita SQL Injection)
$sortMap = [
  'id'    => 'u.id_user',
  'name'  => 'u.full_name',
  'area'  => 'u.area',
  'email' => 'u.email',
  'phone' => $phoneExpr,
  'role'  => 'r.name',
];

$sortExpr  = $sortMap[$CURRENT_SORT] ?? $sortMap['id'];
$orderBySql = $sortExpr . ' ' . strtoupper($CURRENT_DIRIN);

function sort_url(string $col): string {
  global $CURRENT_SORT, $CURRENT_DIRIN;
  $params = $_GET;
  unset($params['created'], $params['updated'], $params['deleted'], $params['pass_updated']);

  $currentSort = $CURRENT_SORT ?? ($params['sort'] ?? '');
  $currentDir  = strtolower($CURRENT_DIRIN ?? ($params['dir'] ?? 'desc'));

  $nextDir = ($currentSort === $col && $currentDir === 'asc') ? 'desc' : 'asc';

  $params['sort'] = $col;
  $params['dir']  = $nextDir;

  unset($params['page']);
  return 'users.php?' . http_build_query($params);
}

function sort_icon(string $col): string {
  global $CURRENT_SORT, $CURRENT_DIRIN;
  $currentSort = $CURRENT_SORT ?? ($_GET['sort'] ?? '');
  $currentDir  = strtolower($CURRENT_DIRIN ?? ($_GET['dir'] ?? 'desc'));

  if ($currentSort !== $col) {
    return '<span class="sort-ico"><i class="fa-solid fa-sort text-muted" aria-hidden="true"></i></span>';
  }
  if ($currentDir === 'asc') {
    return '<span class="sort-ico"><i class="fa-solid fa-sort-up" aria-hidden="true"></i></span>';
  }
  return '<span class="sort-ico"><i class="fa-solid fa-sort-down" aria-hidden="true"></i></span>';
}

// ============================
// PAGINACIÓN (adaptativa por pantalla)
// ============================
$limit = max(5, min(25, (int)($_GET['per_page'] ?? 5)));

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

if ($hasQ) {
  $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE full_name LIKE :q");
  $countStmt->execute([':q' => '%' . $q . '%']);
  $totalUsers = (int)$countStmt->fetchColumn();
} else {
  $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
}
$totalPages = (int)ceil($totalUsers / $limit);
if ($totalPages < 1) $totalPages = 1;

if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $limit;

// ============================
// LISTADO USERS (con LIMIT/OFFSET + búsqueda + teléfono)
// ============================
$phoneSelect = $phoneCol ? ("u.`" . $phoneCol . "` AS phone") : "NULL AS phone";
$extensionSelect = $extensionCol ? "u.`extension` AS extension" : "NULL AS extension";

$whereSql = $hasQ ? "WHERE u.full_name LIKE :q" : "";

$sql = "
  SELECT
    u.id_user,
    u.full_name,
    u.profile_photo,
    u.id_role,
    u.area,
    u.email,
    $phoneSelect,
    $extensionSelect,
    r.name AS rol
  FROM users u
  JOIN roles r ON r.id_role = u.id_role
  $whereSql
  ORDER BY $orderBySql
  LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
if ($hasQ) {
  $stmt->bindValue(':q', '%' . $q . '%', PDO::PARAM_STR);
}
$stmt->execute();
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Users | RH&R Ticketing</title>
  <link rel="icon" type="image/png" href="./assets/img/isotopo.png" />

  <!-- Bootstrap + FontAwesome -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <!-- Base -->
  <link rel="stylesheet" href="./assets/css/generarTickets.css">
  <link rel="stylesheet" href="./assets/css/menu.css">
  <link rel="stylesheet" href="./assets/css/movil.css">
  <script defer src="./assets/js/sidebar.js"></script>

  <!-- Users -->
  <link rel="stylesheet" href="./assets/css/users.css?v=2">

  <!-- Adaptive rows per page (runs before render to avoid flash) -->
  <script>
    (function () {
      var PANEL_CHROME = 300;
      var ROW_H        = 54;
      var panelH = Math.min(900, Math.max(640, window.innerHeight * 0.86));
      var ideal  = Math.max(5, Math.min(25, Math.floor((panelH - PANEL_CHROME) / ROW_H)));

      var params  = new URLSearchParams(window.location.search);
      var current = parseInt(params.get('per_page') || '0', 10);

      if (current !== ideal) {
        params.set('per_page', String(ideal));
        params.set('page', '1');
        window.location.replace(window.location.pathname + '?' + params.toString());
      }
    })();
  </script>

  <style>
    /* Force English text on file inputs regardless of browser language */
    input[type="file"].pro-input {
      color: transparent; /* hides 'No file chosen' */
    }
    input[type="file"].pro-input::-webkit-file-upload-button {
      visibility: hidden;
    }
    input[type="file"].pro-input::before {
      content: 'Choose file';
      display: inline-block;
      background: #f8f9fa;
      border: 1px solid #dee2e6;
      border-radius: 6px;
      padding: 5px 12px;
      outline: none;
      white-space: nowrap;
      cursor: pointer;
      font-weight: 500;
      color: #212529;
      visibility: visible;
      margin-right: 8px;
    }
    input[type="file"].pro-input:hover::before {
      background: #e2e6ea;
    }
    input[type="file"].pro-input::after {
      content: 'No file chosen';
      color: #6c757d;
      font-size: 0.9em;
      visibility: visible;
      pointer-events: none;
    }
  </style>

  <link rel="stylesheet" href="./assets/css/rhr-toast.css">
  <script defer src="./assets/js/rhr-toast.js"></script>
</head>

<body>

  <div class="layout d-flex">

    <!-- SIDEBAR reutilizable -->
    <?php include __DIR__ . '/partials/menu.php'; ?>

    <!-- MAIN -->
    <main class="main flex-grow-1 d-flex justify-content-center align-items-start">
      <section class="panel card users-panel">

        <!-- Alerts -->
        <?php if ($flashCreated): ?>
          <div data-rhr-toast="User created successfully." data-rhr-toast-type="success"></div>
        <?php endif; ?>

        <?php if ($flashPassUp): ?>
          <div data-rhr-toast="Password updated successfully." data-rhr-toast-type="success"></div>
        <?php endif; ?>

        <?php if ($flashDeleted): ?>
          <div data-rhr-toast="User deleted successfully." data-rhr-toast-type="error"></div>
        <?php endif; ?>

        <?php if ($flashUpdated): ?>
          <div data-rhr-toast="User updated successfully." data-rhr-toast-type="success"></div>
        <?php endif; ?>

        <?php if (!empty($passErrors)): ?>
          <div data-rhr-toast data-rhr-toast-type="error">
            <ul>
              <?php foreach ($passErrors as $err): ?>
                <li><?= e($err) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if (!empty($deleteErrors)): ?>
          <div data-rhr-toast data-rhr-toast-type="error">
            <ul>
              <?php foreach ($deleteErrors as $err): ?>
                <li><?= e($err) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if (!empty($editErrors)): ?>
          <div data-rhr-toast data-rhr-toast-type="error">
            <ul>
              <?php foreach ($editErrors as $err): ?>
                <li><?= e($err) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <!-- Header panel -->
        <div class="users-head">
          <div class="users-head__left">
            <h1 class="panel__title m-0">Users</h1>

            <form class="users-search" method="GET" action="users.php" autocomplete="off">
              <div class="users-search__group">
                <i class="fa-solid fa-magnifying-glass users-search__icon" aria-hidden="true"></i>
                <input
                  class="form-control pro-input users-search__input"
                  type="text"
                  name="q"
                  value="<?= e($q) ?>"
                  placeholder="Search by name..."
                >
                <?php if ($hasQ): ?>
                  <a class="users-search__clear" href="users.php" title="Clear search">
                    <i class="fa-solid fa-xmark"></i>
                  </a>
                <?php endif; ?>
              </div>
            </form>
          </div>

          <div class="users-actions">
            <button class="btn-pro" type="button" data-bs-toggle="modal" data-bs-target="#createUserModal">
              Create User
            </button>
          </div>
        </div>

        <!-- Tabla -->
        <!-- Nota: quitamos table-responsive para evitar scroll horizontal y mostrar toda la info -->
        <div class="users-table mt-3">
          <table class="table table-borderless align-middle mb-0">
            <thead>
              <tr>
                <th><a class="th-sort" href="<?= sort_url('name') ?>">User<?= sort_icon('name') ?></a></th>
                <th>Department/Area</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Role</th>
                <th class="th-center">Actions</th>
              </tr>
            </thead>

            <tbody>
              <?php if (empty($users)): ?>
                <tr>
                  <td colspan="6" class="text-center text-muted py-4">No users found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($users as $u): ?>
                  <tr>
                    <td>
                      <div class="user-cell">
                        <div class="user-avatar" aria-hidden="true">
                          <?php if (!empty($u['profile_photo'])): ?>
                            <img src="<?= e($u['profile_photo']) ?>" alt="Photo">
                          <?php else: ?>
                            <i class="fa-solid fa-user"></i>
                          <?php endif; ?>
                        </div>
                        <div class="user-meta">
                          <span class="name"><?= e($u['full_name']) ?></span>
                        </div>
                      </div>
                    </td>
                    <td><?= e(getAreaNameEn($u['area'])) ?></td>
                    <td class="col-email"><?= e($u['email']) ?></td>
                    <td class="col-phone">
                      <?php 
                        $ph = trim($u['phone'] ?? '');
                        $ex = trim($u['extension'] ?? '');
                        if ($ph === '') {
                          echo '—';
                        } else {
                          echo ($ex !== '') ? e('(' . $ex . ') ' . $ph) : e($ph);
                        }
                      ?>
                    </td>

                    <td>
                      <span class="badge role-badge <?= e(roleClass($u['rol'])) ?>">
                        <?= e(getRoleNameEn($u['rol'])) ?>
                      </span>
                    </td>

                    <td class="th-center">
                      <div class="actions-cell">
                        <button
                          class="icon-action"
                          type="button"
                          title="Edit user"
                          data-bs-toggle="modal"
                          data-bs-target="#editUserModal"
                          data-user-id="<?= e($u['id_user']) ?>"
                          data-user-name="<?= e($u['full_name']) ?>"
                          data-user-email="<?= e($u['email']) ?>"
                          data-user-phone="<?= e($u['phone'] ?? '') ?>"
                          data-user-extension="<?= e($u['extension'] ?? '') ?>"
                          data-user-area="<?= e($u['area']) ?>"
                          data-user-role="<?= e($u['id_role']) ?>"
                          data-user-photo="<?= e($u['profile_photo'] ?? '') ?>"
                        >
                          <i class="fa-solid fa-pen"></i>
                        </button>

                        <button
                          class="icon-action icon-action--pass"
                          type="button"
                          title="Change password"
                          data-bs-toggle="modal"
                          data-bs-target="#modPassModal"
                          data-user-id="<?= e($u['id_user']) ?>"
                          data-user-name="<?= e($u['full_name']) ?>"
                        >
                          <i class="fa-solid fa-key"></i>
                        </button>

                        <?php if ((int)$u['id_user'] === $_AUTH_USER_ID): ?>
                        <button
                          class="icon-action icon-action--danger"
                          type="button"
                          title="You cannot delete yourself"
                          disabled
                          style="opacity: 0.3; cursor: not-allowed;"
                        >
                          <i class="fa-solid fa-trash"></i>
                        </button>
                        <?php else: ?>
                        <button
                          class="icon-action icon-action--danger"
                          type="button"
                          title="Delete user"
                          data-bs-toggle="modal"
                          data-bs-target="#deleteUserModal"
                          data-user-id="<?= e($u['id_user']) ?>"
                          data-user-name="<?= e($u['full_name']) ?>"
                        >
                          <i class="fa-solid fa-trash"></i>
                        </button>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>

          </table>
        </div>

        <!-- Footer tabla (real) -->
        <?php
          $from = ($totalUsers === 0) ? 0 : ($offset + 1);
          $to   = min($offset + $limit, $totalUsers);
        ?>
        <div class="table-foot d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 mt-3">
          <span class="foot-text">
            Showing <?= e($from) ?>–<?= e($to) ?> of <?= e($totalUsers) ?> users
          </span>

          <?php if ($totalPages > 1):
            // Helper para construir URLs de paginación conservando per_page y búsqueda
            $pgBase = '?per_page=' . $limit . ($hasQ ? '&q=' . urlencode($q) : '');
          ?>
            <nav aria-label="Paginación">
              <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= $pgBase ?>&page=<?= $page - 1 ?>">Back</a>
                </li>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                  <li class="page-item <?= ($i === $page) ? 'active' : '' ?>">
                    <a class="page-link" href="<?= $pgBase ?>&page=<?= $i ?>"><?= $i ?></a>
                  </li>
                <?php endfor; ?>

                <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= $pgBase ?>&page=<?= $page + 1 ?>">Next</a>
                </li>
              </ul>
            </nav>
          <?php endif; ?>
        </div>

      </section>
    </main>

  </div>

  <!-- MODAL: Create User -->
  <div class="modal fade" id="createUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content modal-pro">

        <div class="modal-header">
          <h5 class="modal-title fw-bold">Create User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <form id="createUserForm" method="POST" action="users.php" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="create_user">

          <div class="modal-body">

            <?php if (!empty($createErrors)): ?>
              <div data-rhr-toast data-rhr-toast-type="error">
                <ul>
                  <?php foreach ($createErrors as $err): ?>
                    <li><?= e($err) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <div class="row g-2">
              <!-- Foto primero (como pidió el visto bueno) -->
              <div class="col-12">
                <label class="form-label">Profile Photo (optional)</label>

                <div class="avatar-uploader">
                  <div class="avatar-preview" aria-hidden="true">
                    <img id="profilePhotoPreview" alt="Preview" style="display:none;">
                    <span class="initials" id="profilePhotoPreviewFallback"><i class="fa-solid fa-user"></i></span>
                  </div>

                  <div class="flex-grow-1">
                    <input class="form-control pro-input" id="profilePhotoInput" name="profile_photo" type="file" accept="image/png,image/jpeg,image/webp">
                    <div class="hint">JPG, PNG or WEBP. Max 2MB.</div>
                    <div class="text-muted" id="profilePhotoPreviewText">No photo selected.</div>
                  </div>
                </div>
              </div>

              <div class="col-12">
                <label class="form-label">Name</label>
                <input class="form-control pro-input" name="full_name" type="text" placeholder="Full name" required>
              </div>

              <div class="col-12">
                <label class="form-label">Email</label>
                <input class="form-control pro-input" name="email" type="email" placeholder="email@domain.com" required>
              </div>

              <div class="col-12">
                <label class="form-label">Phone Number</label>
                <input class="form-control pro-input" name="phone" type="text" placeholder="E.g. +52 999 123 4567">
              </div>

              <div class="col-12">
                <label class="form-label">Extension</label>
                <input class="form-control pro-input" name="extension" type="text" placeholder="E.g. 101">
              </div>

              <div class="col-12">
                <label class="form-label">Area</label>
                <select class="form-select pro-input" name="area" required>
                  <option value="" selected disabled>Select an area</option>
                  <option value="Accounting">Accounting</option>
                  <option value="Corporate">Corporate</option>
                  <option value="HR">HR</option>
                  <option value="IT Support">IT Support</option>
                  <option value="Managers">Managers</option>
                  <option value="Marketing and IT">Marketing and IT</option>
                  <option value="Operations">Operations</option>
                  <option value="Recruiters">Recruiters</option>
                  <option value="Workers Comp">Workers Comp</option>
                </select>
              </div>

              <div class="col-12">
                <label class="form-label">Role</label>
                <select class="form-select pro-input" name="id_role" required>
                  <option value="" selected disabled>Select a role</option>
                  <?php foreach ($roles as $r): ?>
                    <option value="<?= e($r['id_role']) ?>"><?= e(getRoleNameEn($r['name'])) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-12">
                <label class="form-label">Password (optional)</label>
                <input class="form-control pro-input" id="createPassInput" name="password_plain" type="text" placeholder="Leave empty to generate">
              </div>

              <!-- (quitamos el bloque viejo de foto porque ya está arriba) -->
            </div>
          </div>

          <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
            <button class="btn-pro" type="submit">Save</button>
          </div>
        </form>

      </div>
    </div>
  </div>

  
  <!-- MODAL: Edit User -->
  <div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content modal-pro">

        <div class="modal-header">
          <h5 class="modal-title fw-bold">Edit User <span class="text-muted" id="editUserTitleName" style="font-size: 14px;"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <form method="POST" action="users.php" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="update_user">
          <input type="hidden" id="editUserId" name="id_user" value="">

          <div class="modal-body">

            <div class="row g-2">
              <!-- Foto primero -->
              <div class="col-12">
                <label class="form-label">Profile Photo (optional)</label>

                <div class="avatar-uploader">
                  <div class="avatar-preview" aria-hidden="true">
                    <img id="editProfilePhotoPreview" alt="Preview" style="display:none;">
                    <span class="initials" id="editProfilePhotoPreviewFallback"><i class="fa-solid fa-user"></i></span>
                  </div>

                  <div class="flex-grow-1">
                    <input class="form-control pro-input" id="editProfilePhotoInput" name="profile_photo" type="file" accept="image/png,image/jpeg,image/webp">
                    <div class="hint">JPG, PNG or WEBP. Max 2MB.</div>
                    <div class="text-muted" id="editProfilePhotoPreviewText">The current photo will be kept if no new one is uploaded.</div>
                  </div>
                </div>
              </div>

              <div class="col-12">
                <label class="form-label">Name</label>
                <input class="form-control pro-input" id="editFullName" name="full_name" type="text" placeholder="Full name" required>
              </div>

              <div class="col-12">
                <label class="form-label">Email</label>
                <input class="form-control pro-input" id="editEmail" name="email" type="email" placeholder="email@domain.com" required>
              </div>

              <div class="col-12">
                <label class="form-label">Phone</label>
                <input class="form-control pro-input" id="editPhone" name="phone" type="text" placeholder="E.g. +52 999 123 4567">
              </div>

              <div class="col-12">
                <label class="form-label">Extension</label>
                <input class="form-control pro-input" id="editExtension" name="extension" type="text" placeholder="E.g. 101">
              </div>

              <div class="col-12">
                <label class="form-label">Area</label>
                <select class="form-select pro-input" id="editArea" name="area" required>
                  <option value="" disabled>Select an area</option>
                  <option value="Accounting">Accounting</option>
                  <option value="Corporate">Corporate</option>
                  <option value="HR">HR</option>
                  <option value="IT Support">IT Support</option>
                  <option value="Managers">Managers</option>
                  <option value="Marketing and IT">Marketing and IT</option>
                  <option value="Operations">Operations</option>
                  <option value="Recruiters">Recruiters</option>
                  <option value="Workers Comp">Workers Comp</option>
                </select>
              </div>

              <div class="col-12">
                <label class="form-label">Role</label>
                <select class="form-select pro-input" id="editRole" name="id_role" required>
                  <option value="" disabled>Select a role</option>
                  <?php foreach ($roles as $r): ?>
                    <option value="<?= e($r['id_role']) ?>"><?= e(getRoleNameEn($r['name'])) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

            </div>
          </div>

          <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
            <button class="btn-pro" type="submit">Save Changes</button>
          </div>
        </form>

      </div>
    </div>
  </div>

  <!-- MODAL: Modify Password -->
<div class="modal fade" id="modPassModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content modal-pro" method="POST" action="users.php">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">
          Password <span class="text-muted" id="modPassUserName" style="font-size: 14px;"></span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="update_password">
        <input type="hidden" id="userIdToUpdate" name="id_user" value="">

        <label class="form-label">New password</label>

        <div class="input-group">
          <input
            class="form-control pro-input"
            id="newPassInput"
            name="new_password"
            type="text"
            placeholder="Generate or type a password"
            autocomplete="off"
            required
          >
          <button class="btn-pro btn-pro--sm" type="button" id="btnGenPass" title="Generate password">
            <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Generate
          </button>
        </div>

        <small class="text-muted d-block mt-1">Minimum 8 characters, including an uppercase letter, a number, and a symbol.</small>

        <div class="d-flex justify-content-end mt-3">
          <button class="btn-secondary btn-secondary--sm" type="button" id="btnCopyPass" title="Copy password">
            <i class="fa-regular fa-copy me-1"></i>Copy to clipboard
          </button>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
        <button class="btn-pro" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

  <!-- MODAL: Generate Password for Create User -->
  <div class="modal fade" id="createPassModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content modal-pro">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">
            Generate Password
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <label class="form-label">New password</label>

          <div class="input-group">
            <input
              class="form-control pro-input"
              id="createNewPassInput"
              type="text"
              placeholder="Generate or type a password"
              autocomplete="off"
            >
            <button class="btn-pro btn-pro--sm" type="button" id="btnCreateGenPass" title="Generate password">
              <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Generate
            </button>
          </div>

          <small class="text-muted d-block mt-1">Minimum 8 characters, including an uppercase letter, a number, and a symbol.</small>

          <div class="d-flex justify-content-end mt-3">
            <button class="btn-secondary btn-secondary--sm" type="button" id="btnCreateCopyPass" title="Copy password">
              <i class="fa-regular fa-copy me-1"></i>Copy to clipboard
            </button>
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
          <button class="btn-pro" type="button" id="btnCreatePassConfirm">Save</button>
        </div>
      </div>
    </div>
  </div>

  <!-- MODAL: Delete User -->
  <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content modal-pro" method="POST" action="users.php">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">
            Delete user <span class="text-muted" id="delUserName" style="font-size: 14px;"></span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="delete_user">
          <input type="hidden" id="userIdToDelete" name="id_user" value="">

          <p class="mb-0">
            Are you sure you want to delete this user? This action cannot be undone.
          </p>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancel</button>
          <button class="btn btn-danger" type="submit">
            <i class="fa-solid fa-trash me-2"></i>Delete
          </button>
        </div>
      </form>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <!-- ✅ JS: Cargar id_user en modales (esto arregla "Usuario inválido") -->
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const modPassModal = document.getElementById('modPassModal');
      const editUserModal = document.getElementById('editUserModal');
      const deleteUserModal = document.getElementById('deleteUserModal'); 

      const userIdToUpdate = document.getElementById('userIdToUpdate');
      const modPassUserName = document.getElementById('modPassUserName');
      const newPassInput = document.getElementById('newPassInput');

      const userIdToDelete = document.getElementById('userIdToDelete');
      const delUserName = document.getElementById('delUserName');

      // Foto de perfil: vista previa
      const photoInput = document.getElementById('profilePhotoInput');
      const photoPreview = document.getElementById('profilePhotoPreview');
      const photoPreviewText = document.getElementById('profilePhotoPreviewText');
      const photoPreviewFallback = document.getElementById('profilePhotoPreviewFallback');
      if (photoInput) {
        photoInput.addEventListener('change', function () {
          const file = photoInput.files && photoInput.files[0] ? photoInput.files[0] : null;
          if (!file) {
            if (photoPreview) photoPreview.style.display = 'none';
            if (photoPreviewFallback) photoPreviewFallback.style.display = 'inline-flex';
            if (photoPreviewText) photoPreviewText.textContent = 'No photo selected.';
            return;
          }
          const url = URL.createObjectURL(file);
          if (photoPreview) {
            photoPreview.src = url;
            photoPreview.style.display = 'block';
          }
          if (photoPreviewFallback) photoPreviewFallback.style.display = 'none';
          if (photoPreviewText) photoPreviewText.textContent = `${file.name}`;
        });
      }

      // Foto de perfil (EDIT): vista previa
      const editPhotoInput = document.getElementById('editProfilePhotoInput');
      const editPhotoPreview = document.getElementById('editProfilePhotoPreview');
      const editPhotoPreviewText = document.getElementById('editProfilePhotoPreviewText');
      const editPhotoPreviewFallback = document.getElementById('editProfilePhotoPreviewFallback');
      if (editPhotoInput) {
        editPhotoInput.addEventListener('change', function () {
          const file = editPhotoInput.files && editPhotoInput.files[0] ? editPhotoInput.files[0] : null;
          if (!file) {
            if (editPhotoPreview) editPhotoPreview.style.display = 'none';
            if (editPhotoPreviewFallback) editPhotoPreviewFallback.style.display = 'inline-flex';
            if (editPhotoPreviewText) editPhotoPreviewText.textContent = 'The current photo will be kept if no new one is uploaded.';
            return;
          }
          const url = URL.createObjectURL(file);
          if (editPhotoPreview) {
            editPhotoPreview.src = url;
            editPhotoPreview.style.display = 'block';
          }
          if (editPhotoPreviewFallback) editPhotoPreviewFallback.style.display = 'none';
          if (editPhotoPreviewText) editPhotoPreviewText.textContent = `${file.name}`;
        });
      }

      // ===== Contraseña (Generar + Copiar + Flash) =====
      const btnGenPass = document.getElementById('btnGenPass');
      const btnCopyPass = document.getElementById('btnCopyPass');

      function genStrongPassword(len = 12){
        const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        const lower = 'abcdefghijkmnopqrstuvwxyz';
        const nums  = '23456789';
        const sym   = '!@#$%&*?_-';
        const all   = upper + lower + nums + sym;

        const pick = (s) => s[Math.floor(Math.random() * s.length)];
        let out = pick(upper) + pick(nums) + pick(sym) + pick(lower);
        for(let i = out.length; i < len; i++) out += pick(all);

        // shuffle
        out = out.split('').sort(() => Math.random() - 0.5).join('');
        return 'RHR-' + out;
      }

      if (btnGenPass && newPassInput){
        btnGenPass.addEventListener('click', () => {
          newPassInput.value = genStrongPassword(10); // total aprox 14-15 con prefijo
          newPassInput.focus();
        });
      }

      if (btnCopyPass && newPassInput){
        btnCopyPass.addEventListener('click', async () => {
          const val = newPassInput.value || '';
          if (!val) return;

          try{
            await navigator.clipboard.writeText(val);
          }catch(e){
            newPassInput.select();
            document.execCommand('copy');
          }

          const old = btnCopyPass.innerHTML;
          btnCopyPass.innerHTML = '<i class="fa-solid fa-check"></i> Copied';
          setTimeout(() => btnCopyPass.innerHTML = old, 1200);
        });
      }

      // ===== Create User: interceptar si contraseña vacía =====
      const createUserForm = document.getElementById('createUserForm');
      const createPassInput = document.getElementById('createPassInput');
      const createPassModalEl = document.getElementById('createPassModal');
      const createNewPassInput = document.getElementById('createNewPassInput');
      const btnCreateGenPass = document.getElementById('btnCreateGenPass');
      const btnCreateCopyPass = document.getElementById('btnCreateCopyPass');
      const btnCreatePassConfirm = document.getElementById('btnCreatePassConfirm');
      const createUserModalEl = document.getElementById('createUserModal');

      if (createUserForm && createPassInput && createPassModalEl) {
        createUserForm.addEventListener('submit', function (e) {
          // Si la contraseña no está vacía, dejar que se envíe normalmente
          if (createPassInput.value.trim() !== '') return;

          // Contraseña vacía → detener envío, abrir modal de generar contraseña
          e.preventDefault();

          // Cerrar el modal de crear usuario
          const createModal = bootstrap.Modal.getInstance(createUserModalEl);
          if (createModal) createModal.hide();

          // Esperar a que se cierre y abrir el de contraseña
          setTimeout(() => {
            if (createNewPassInput) createNewPassInput.value = '';
            const passModal = bootstrap.Modal.getOrCreateInstance(createPassModalEl);
            passModal.show();
          }, 300);
        });
      }

      // Botón Generate del modal de crear contraseña
      if (btnCreateGenPass && createNewPassInput) {
        btnCreateGenPass.addEventListener('click', () => {
          createNewPassInput.value = genStrongPassword(10);
          createNewPassInput.focus();
        });
      }

      // Botón Copy del modal de crear contraseña
      if (btnCreateCopyPass && createNewPassInput) {
        btnCreateCopyPass.addEventListener('click', async () => {
          const val = createNewPassInput.value || '';
          if (!val) return;
          try {
            await navigator.clipboard.writeText(val);
          } catch(e) {
            createNewPassInput.select();
            document.execCommand('copy');
          }
          const old = btnCreateCopyPass.innerHTML;
          btnCreateCopyPass.innerHTML = '<i class="fa-solid fa-check"></i> Copied';
          setTimeout(() => btnCreateCopyPass.innerHTML = old, 1200);
        });
      }

      // Botón Save/Confirm: llena la contraseña en el form de crear y envía
      if (btnCreatePassConfirm && createNewPassInput && createPassInput && createUserForm) {
        btnCreatePassConfirm.addEventListener('click', () => {
          const pass = createNewPassInput.value.trim();
          if (!pass) {
            createNewPassInput.focus();
            return;
          }
          // Llenar la contraseña en el formulario de crear usuario
          createPassInput.value = pass;

          // Cerrar el modal de contraseña
          const passModal = bootstrap.Modal.getInstance(createPassModalEl);
          if (passModal) passModal.hide();

          // Enviar el formulario de crear usuario
          setTimeout(() => {
            createUserForm.submit();
          }, 300);
        });
      }

      if (editUserModal) {
        editUserModal.addEventListener('show.bs.modal', function (event) {
          const btn = event.relatedTarget;
          const id = btn?.getAttribute('data-user-id') || '';
          const name = btn?.getAttribute('data-user-name') || '';
          const email = btn?.getAttribute('data-user-email') || '';
          const phone = btn?.getAttribute('data-user-phone') || '';
          const extension = btn?.getAttribute('data-user-extension') || '';
          const area = btn?.getAttribute('data-user-area') || '';
          const role = btn?.getAttribute('data-user-role') || '';
          const photo = btn?.getAttribute('data-user-photo') || '';

          const editUserId = document.getElementById('editUserId');
          const editFullName = document.getElementById('editFullName');
          const editEmail = document.getElementById('editEmail');
          const editPhone = document.getElementById('editPhone');
          const editExtension = document.getElementById('editExtension');
          const editArea = document.getElementById('editArea');
          const editRole = document.getElementById('editRole');
          const editUserTitleName = document.getElementById('editUserTitleName');

          if (editUserId) editUserId.value = id;
          if (editFullName) editFullName.value = name;
          if (editEmail) editEmail.value = email;
          if (editPhone) editPhone.value = phone;
          if (editExtension) editExtension.value = extension;
          if (editUserTitleName) editUserTitleName.textContent = name ? `(${name})` : '';

          // Set selects
          if (editArea) editArea.value = area || '';
          if (editRole) editRole.value = role || '';

          // Reset file input (para no arrastrar la selección anterior)
          const editPhotoInput = document.getElementById('editProfilePhotoInput');
          if (editPhotoInput) editPhotoInput.value = '';

          // Pintar preview con la foto actual
          const editPhotoPreview = document.getElementById('editProfilePhotoPreview');
          const editPhotoPreviewFallback = document.getElementById('editProfilePhotoPreviewFallback');
          const editPhotoPreviewText = document.getElementById('editProfilePhotoPreviewText');

          if (photo) {
            if (editPhotoPreview) {
              editPhotoPreview.src = photo;
              editPhotoPreview.style.display = 'block';
            }
            if (editPhotoPreviewFallback) editPhotoPreviewFallback.style.display = 'none';
            if (editPhotoPreviewText) editPhotoPreviewText.textContent = 'Current photo loaded. Upload another to replace it.';
          } else {
            if (editPhotoPreview) editPhotoPreview.style.display = 'none';
            if (editPhotoPreviewFallback) editPhotoPreviewFallback.style.display = 'inline-flex';
            if (editPhotoPreviewText) editPhotoPreviewText.textContent = 'No photo. You can upload one if you want.';
          }
        });
      }

      if (modPassModal) {
        modPassModal.addEventListener('show.bs.modal', function (event) {
          const btn = event.relatedTarget;
          // Si no hay botón (apertura programática por flash), no sobrescribir los valores ya seteados
          if (!btn) return;

          const id = btn.getAttribute('data-user-id') || '';
          const name = btn.getAttribute('data-user-name') || '';

          userIdToUpdate.value = id;
          modPassUserName.textContent = name ? `(${name})` : '';
          if (newPassInput) newPassInput.value = '';
        });
      }

      if (deleteUserModal) {
        deleteUserModal.addEventListener('show.bs.modal', function (event) {
          const btn = event.relatedTarget;
          const id = btn?.getAttribute('data-user-id') || '';
          const name = btn?.getAttribute('data-user-name') || '';

          userIdToDelete.value = id;
          delUserName.textContent = name ? `(${name})` : '';
        });
      }
    });
  </script>

  <?php if ($flashCreated): ?>
  <script>
  // Process queued emails in background (non-blocking) — sends welcome email
  fetch('api/process_queue.php', {headers:{'X-Requested-With':'XMLHttpRequest'}}).catch(()=>{});
  </script>
  <?php endif; ?>

</body>
</html>