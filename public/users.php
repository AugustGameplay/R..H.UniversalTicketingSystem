<?php
// users.php
require __DIR__ . '/config/db.php';
require __DIR__ . '/partials/auth.php';
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

// ============================
// CREATE USER (POST)
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_user') {
  $full_name = trim($_POST['full_name'] ?? '');
  $email     = trim($_POST['email'] ?? '');
  $area      = trim($_POST['area'] ?? '');
  $id_role   = (int)($_POST['id_role'] ?? 0);
  $phone     = trim($_POST['phone'] ?? '');

  // Foto (opcional)
  $profilePhotoPath = null;

  // Password opcional: si viene vacío, generamos una
  $plain_pass = trim($_POST['password_plain'] ?? '');

  // Validaciones
  if ($full_name === '') $createErrors[] = "El nombre es obligatorio.";
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $createErrors[] = "Email inválido.";
  if (!in_array($area, ['IT Support', 'Operaciones', 'Marketing'], true)) $createErrors[] = "Área inválida.";
  if ($id_role <= 0) $createErrors[] = "Rol inválido.";
  if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) $createErrors[] = "Teléfono inválido.";

  if ($plain_pass === '') {
    $plain_pass = 'RHR-' . generateStrongPassword(10);
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

      $sql = "
        INSERT INTO users (full_name, email, area, id_role, password_hash, profile_photo" . ($phoneCol ? (", `" . $phoneCol . "`") : "") . ")
        VALUES (:full_name, :email, :area, :id_role, :password_hash, :profile_photo" . ($phoneCol ? ", :phone" : "") . ")
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
      $ins->execute($params);

      $newId = (int)$pdo->lastInsertId();

      // Flash para mostrar la contraseña generada/ingresada una sola vez
      $_SESSION['flash_generated_pass'] = [
        'id_user' => $newId,
        'full_name' => $full_name,
        'email' => $email,
        'password' => $plain_pass,
      ];

      header("Location: users.php?created=1");
      exit;

    } catch (PDOException $ex) {
      if (($ex->errorInfo[0] ?? '') === '23000') {
        $createErrors[] = "Ese email ya existe.";
      } else {
        $createErrors[] = "Error al guardar: " . $ex->getMessage();
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
  $phone     = trim($_POST['phone'] ?? '');

  $editErrors = [];

  if ($id_user <= 0) $editErrors[] = "Usuario inválido.";
  if ($full_name === '') $editErrors[] = "El nombre es obligatorio.";
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $editErrors[] = "Email inválido.";
  if (!in_array($area, ['IT Support', 'Operaciones', 'Marketing'], true)) $editErrors[] = "Área inválida.";
  if ($id_role <= 0) $editErrors[] = "Rol inválido.";
  if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) $editErrors[] = "Teléfono inválido.";

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

      $sql = "
        UPDATE users
        SET full_name = :full_name,
            email = :email,
            area = :area,
            id_role = :id_role,
            profile_photo = :profile_photo" . ($phoneCol ? (",
            `" . $phoneCol . "` = :phone") : "") . "
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
        $editErrors[] = "Ese email ya existe.";
      } else {
        $editErrors[] = "Error al actualizar: " . $ex->getMessage();
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

  if ($id_user <= 0) $passErrors[] = "Usuario inválido.";
  if ($new_pass === '') $passErrors[] = "La contraseña no puede ir vacía.";
  if ($new_pass !== '' && !$isStrong) {
    $passErrors[] = "Contraseña débil: mínimo 8 caracteres e incluir mayúscula, número y símbolo.";
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
      $passErrors[] = "Error al actualizar contraseña: " . $ex->getMessage();
    }
  }
}

// ============================
// DELETE USER (POST)
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user') {
  $id_user = (int)($_POST['id_user'] ?? 0);
  $currentUserId = (int)($_SESSION['user_id'] ?? 0);

  if ($id_user <= 0) $deleteErrors[] = "Usuario inválido.";
  if ($currentUserId > 0 && $id_user === $currentUserId) {
    $deleteErrors[] = "No puedes eliminar tu propio usuario.";
  }

  if (!$deleteErrors) {
    try {
      $del = $pdo->prepare("DELETE FROM users WHERE id_user = :id_user");
      $del->execute([':id_user' => $id_user]);

      header("Location: users.php?deleted=1");
      exit;

    } catch (PDOException $ex) {
      $deleteErrors[] = "Error al eliminar usuario: " . $ex->getMessage();
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
// PAGINACIÓN (5 por página)
// ============================
$limit = 5;

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
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Users | RH&R Ticketing</title>

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
          <div class="alert alert-success mb-3">Usuario creado correctamente.</div>
        <?php endif; ?>

        <?php if ($flashPassUp): ?>
          <div class="alert alert-success mb-3">Contraseña actualizada correctamente.</div>
        <?php endif; ?>

        <?php if ($flashDeleted): ?>
          <div class="alert alert-success mb-3">Usuario eliminado correctamente.</div>
        <?php endif; ?>

        <?php if ($flashUpdated): ?>
          <div class="alert alert-success mb-3">Usuario actualizado correctamente.</div>
        <?php endif; ?>

        <?php if (!empty($passErrors)): ?>
          <div class="alert alert-danger mb-3">
            <ul class="mb-0">
              <?php foreach ($passErrors as $err): ?>
                <li><?= e($err) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if (!empty($deleteErrors)): ?>
          <div class="alert alert-danger mb-3">
            <ul class="mb-0">
              <?php foreach ($deleteErrors as $err): ?>
                <li><?= e($err) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if (!empty($editErrors)): ?>
          <div class="alert alert-danger mb-3">
            <ul class="mb-0">
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
                  placeholder="Buscar por nombre..."
                >
                <?php if ($hasQ): ?>
                  <a class="users-search__clear" href="users.php" title="Limpiar búsqueda">
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
                <th><a class="th-sort" href="<?= sort_url('name') ?>">Usuario <?= sort_icon('name') ?></a></th>
                <th>Area</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Role</th>
                <th class="th-center">Actions</th>
              </tr>
            </thead>

            <tbody>
              <?php if (empty($users)): ?>
                <tr>
                  <td colspan="6" class="text-center text-muted py-4">No hay usuarios.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($users as $u): ?>
                  <tr>
                    <td>
                      <div class="user-cell">
                        <div class="user-avatar" aria-hidden="true">
                          <?php if (!empty($u['profile_photo'])): ?>
                            <img src="<?= e($u['profile_photo']) ?>" alt="Foto">
                          <?php else: ?>
                            <i class="fa-solid fa-user"></i>
                          <?php endif; ?>
                        </div>
                        <div class="user-meta">
                          <span class="name"><?= e($u['full_name']) ?></span>
                        </div>
                      </div>
                    </td>
                    <td><?= e($u['area']) ?></td>
                    <td class="col-email"><?= e($u['email']) ?></td>
                    <td class="col-phone"><?= e($u['phone'] ?? '—') ?></td>

                    <td>
                      <span class="badge role-badge <?= e(roleClass($u['rol'])) ?>">
                        <?= e($u['rol']) ?>
                      </span>
                    </td>

                    <td class="th-center">
                      <div class="actions-cell">
                        <button
                          class="icon-action"
                          type="button"
                          title="Editar usuario"
                          data-bs-toggle="modal"
                          data-bs-target="#editUserModal"
                          data-user-id="<?= e($u['id_user']) ?>"
                          data-user-name="<?= e($u['full_name']) ?>"
                          data-user-email="<?= e($u['email']) ?>"
                          data-user-phone="<?= e($u['phone'] ?? '') ?>"
                          data-user-area="<?= e($u['area']) ?>"
                          data-user-role="<?= e($u['id_role']) ?>"
                          data-user-photo="<?= e($u['profile_photo'] ?? '') ?>"
                        >
                          <i class="fa-solid fa-pen"></i>
                        </button>

                        <button
                          class="icon-action icon-action--pass"
                          type="button"
                          title="Cambiar contraseña"
                          data-bs-toggle="modal"
                          data-bs-target="#modPassModal"
                          data-user-id="<?= e($u['id_user']) ?>"
                          data-user-name="<?= e($u['full_name']) ?>"
                        >
                          <i class="fa-solid fa-key"></i>
                        </button>

                        <button
                          class="icon-action icon-action--danger"
                          type="button"
                          title="Eliminar usuario"
                          data-bs-toggle="modal"
                          data-bs-target="#deleteUserModal"
                          data-user-id="<?= e($u['id_user']) ?>"
                          data-user-name="<?= e($u['full_name']) ?>"
                        >
                          <i class="fa-solid fa-trash"></i>
                        </button>
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
            Mostrando <?= e($from) ?>–<?= e($to) ?> de <?= e($totalUsers) ?> usuarios
          </span>

          <?php if ($totalPages > 1): ?>
            <nav aria-label="Paginación">
              <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                  <a class="page-link" href="?page=<?= $page - 1 ?><?= $hasQ ? '&q=' . urlencode($q) : '' ?>">Anterior</a>
                </li>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                  <li class="page-item <?= ($i === $page) ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $i ?><?= $hasQ ? '&q=' . urlencode($q) : '' ?>"><?= $i ?></a>
                  </li>
                <?php endfor; ?>

                <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                  <a class="page-link" href="?page=<?= $page + 1 ?><?= $hasQ ? '&q=' . urlencode($q) : '' ?>">Siguiente</a>
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
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <form method="POST" action="users.php" enctype="multipart/form-data">
          <input type="hidden" name="action" value="create_user">

          <div class="modal-body">

            <?php if (!empty($createErrors)): ?>
              <div class="alert alert-danger">
                <ul class="mb-0">
                  <?php foreach ($createErrors as $err): ?>
                    <li><?= e($err) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <div class="row g-2">
              <!-- Foto primero (como pidió el visto bueno) -->
              <div class="col-12">
                <label class="form-label">Foto de perfil (opcional)</label>

                <div class="avatar-uploader">
                  <div class="avatar-preview" aria-hidden="true">
                    <img id="profilePhotoPreview" alt="Vista previa" style="display:none;">
                    <span class="initials" id="profilePhotoPreviewFallback"><i class="fa-solid fa-user"></i></span>
                  </div>

                  <div class="flex-grow-1">
                    <input class="form-control pro-input" id="profilePhotoInput" name="profile_photo" type="file" accept="image/png,image/jpeg,image/webp">
                    <div class="hint">JPG, PNG o WEBP. Máx 2MB.</div>
                    <div class="text-muted" id="profilePhotoPreviewText">Sin foto seleccionada.</div>
                  </div>
                </div>
              </div>

              <div class="col-12">
                <label class="form-label">Nombre</label>
                <input class="form-control pro-input" name="full_name" type="text" placeholder="Nombre completo" required>
              </div>

              <div class="col-12">
                <label class="form-label">Email</label>
                <input class="form-control pro-input" name="email" type="email" placeholder="correo@dominio.com" required>
              </div>

              <div class="col-12">
                <label class="form-label">Teléfono</label>
                <input class="form-control pro-input" name="phone" type="text" placeholder="Ej. +52 999 123 4567">
              </div>

              <div class="col-12">
                <label class="form-label">Area</label>
                <select class="form-select pro-input" name="area" required>
                  <option value="" selected disabled>Selecciona un área</option>
                  <option value="IT Support">IT Support</option>
                  <option value="Operaciones">Operaciones</option>
                  <option value="Marketing">Marketing</option>
                </select>
              </div>

              <div class="col-12">
                <label class="form-label">Rol</label>
                <select class="form-select pro-input" name="id_role" required>
                  <option value="" selected disabled>Selecciona un rol</option>
                  <?php foreach ($roles as $r): ?>
                    <option value="<?= e($r['id_role']) ?>"><?= e($r['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-12">
                <label class="form-label">Contraseña (opcional)</label>
                <input class="form-control pro-input" name="password_plain" type="text" placeholder="Dejar vacío para generar">
              </div>

              <!-- (quitamos el bloque viejo de foto porque ya está arriba) -->
            </div>
          </div>

          <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancelar</button>
            <button class="btn-pro" type="submit">Guardar</button>
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
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <form method="POST" action="users.php" enctype="multipart/form-data">
          <input type="hidden" name="action" value="update_user">
          <input type="hidden" id="editUserId" name="id_user" value="">

          <div class="modal-body">

            <div class="row g-2">
              <!-- Foto primero -->
              <div class="col-12">
                <label class="form-label">Foto de perfil (opcional)</label>

                <div class="avatar-uploader">
                  <div class="avatar-preview" aria-hidden="true">
                    <img id="editProfilePhotoPreview" alt="Vista previa" style="display:none;">
                    <span class="initials" id="editProfilePhotoPreviewFallback"><i class="fa-solid fa-user"></i></span>
                  </div>

                  <div class="flex-grow-1">
                    <input class="form-control pro-input" id="editProfilePhotoInput" name="profile_photo" type="file" accept="image/png,image/jpeg,image/webp">
                    <div class="hint">JPG, PNG o WEBP. Máx 2MB.</div>
                    <div class="text-muted" id="editProfilePhotoPreviewText">Se conservará la foto actual si no subes una nueva.</div>
                  </div>
                </div>
              </div>

              <div class="col-12">
                <label class="form-label">Nombre</label>
                <input class="form-control pro-input" id="editFullName" name="full_name" type="text" placeholder="Nombre completo" required>
              </div>

              <div class="col-12">
                <label class="form-label">Email</label>
                <input class="form-control pro-input" id="editEmail" name="email" type="email" placeholder="correo@dominio.com" required>
              </div>

              <div class="col-12">
                <label class="form-label">Teléfono</label>
                <input class="form-control pro-input" id="editPhone" name="phone" type="text" placeholder="Ej. +52 999 123 4567">
              </div>

              <div class="col-12">
                <label class="form-label">Area</label>
                <select class="form-select pro-input" id="editArea" name="area" required>
                  <option value="" disabled>Selecciona un área</option>
                  <option value="IT Support">IT Support</option>
                  <option value="Operaciones">Operaciones</option>
                  <option value="Marketing">Marketing</option>
                </select>
              </div>

              <div class="col-12">
                <label class="form-label">Rol</label>
                <select class="form-select pro-input" id="editRole" name="id_role" required>
                  <option value="" disabled>Selecciona un rol</option>
                  <?php foreach ($roles as $r): ?>
                    <option value="<?= e($r['id_role']) ?>"><?= e($r['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

            </div>
          </div>

          <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancelar</button>
            <button class="btn-pro" type="submit">Guardar cambios</button>
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
          Contraseña <span class="text-muted" id="modPassUserName" style="font-size: 14px;"></span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="action" value="update_password">
        <input type="hidden" id="userIdToUpdate" name="id_user" value="">

        <label class="form-label">Nueva contraseña</label>

        <div class="input-group">
          <input
            class="form-control pro-input"
            id="newPassInput"
            name="new_password"
            type="text"
            placeholder="Genera o escribe una contraseña"
            autocomplete="off"
            required
          >
          <button class="btn-pro btn-pro--sm" type="button" id="btnGenPass" title="Generar contraseña">
            <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Generar
          </button>
        </div>

        <small class="text-muted d-block mt-1">Mínimo 8 caracteres e incluir mayúscula, número y símbolo.</small>

        <div class="d-flex justify-content-end mt-3">
          <button class="btn-secondary btn-secondary--sm" type="button" id="btnCopyPass" title="Copiar contraseña">
            <i class="fa-regular fa-copy me-1"></i>Copiar
          </button>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancelar</button>
        <button class="btn-pro" type="submit">Guardar</button>
      </div>
    </form>
  </div>
</div>

  <!-- MODAL: Delete User -->
  <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content modal-pro" method="POST" action="users.php">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">
            Eliminar usuario <span class="text-muted" id="delUserName" style="font-size: 14px;"></span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="action" value="delete_user">
          <input type="hidden" id="userIdToDelete" name="id_user" value="">

          <p class="mb-0">
            ¿Seguro que deseas eliminar este usuario? Esta acción no se puede deshacer.
          </p>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancelar</button>
          <button class="btn btn-danger" type="submit">
            <i class="fa-solid fa-trash me-2"></i>Eliminar
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
            if (photoPreviewText) photoPreviewText.textContent = 'Sin foto seleccionada.';
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
            if (editPhotoPreviewText) editPhotoPreviewText.textContent = 'Se conservará la foto actual si no subes una nueva.';
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
          btnCopyPass.innerHTML = '<i class="fa-solid fa-check"></i> Copiado';
          setTimeout(() => btnCopyPass.innerHTML = old, 1200);
        });
      }

      // Si venimos de crear usuario (o de algún flujo que setee flash), abre el modal con la contraseña lista
      const flashGen = <?php echo json_encode($flashGeneratedPass, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
      if (flashGen && modPassModal) {
        if (userIdToUpdate) userIdToUpdate.value = flashGen.id_user || '';
        if (modPassUserName) {
          const name = flashGen.full_name || '';
          modPassUserName.textContent = name ? `(${name})` : '';
        }
        if (newPassInput) newPassInput.value = flashGen.password || '';

        const modal = bootstrap.Modal.getOrCreateInstance(modPassModal);
        modal.show();
      }

      if (editUserModal) {
        editUserModal.addEventListener('show.bs.modal', function (event) {
          const btn = event.relatedTarget;
          const id = btn?.getAttribute('data-user-id') || '';
          const name = btn?.getAttribute('data-user-name') || '';
          const email = btn?.getAttribute('data-user-email') || '';
          const phone = btn?.getAttribute('data-user-phone') || '';
          const area = btn?.getAttribute('data-user-area') || '';
          const role = btn?.getAttribute('data-user-role') || '';
          const photo = btn?.getAttribute('data-user-photo') || '';

          const editUserId = document.getElementById('editUserId');
          const editFullName = document.getElementById('editFullName');
          const editEmail = document.getElementById('editEmail');
          const editPhone = document.getElementById('editPhone');
          const editArea = document.getElementById('editArea');
          const editRole = document.getElementById('editRole');
          const editUserTitleName = document.getElementById('editUserTitleName');

          if (editUserId) editUserId.value = id;
          if (editFullName) editFullName.value = name;
          if (editEmail) editEmail.value = email;
          if (editPhone) editPhone.value = phone;
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
            if (editPhotoPreviewText) editPhotoPreviewText.textContent = 'Foto actual cargada. Sube otra para reemplazar.';
          } else {
            if (editPhotoPreview) editPhotoPreview.style.display = 'none';
            if (editPhotoPreviewFallback) editPhotoPreviewFallback.style.display = 'inline-flex';
            if (editPhotoPreviewText) editPhotoPreviewText.textContent = 'Sin foto. Puedes subir una si quieres.';
          }
        });
      }

      if (modPassModal) {
        modPassModal.addEventListener('show.bs.modal', function (event) {
          const btn = event.relatedTarget;
          const id = btn?.getAttribute('data-user-id') || '';
          const name = btn?.getAttribute('data-user-name') || '';

          userIdToUpdate.value = id;
          modPassUserName.textContent = name ? `(${name})` : '';
          if (newPassInput && !newPassInput.value) newPassInput.value = '';
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

</body>
</html>