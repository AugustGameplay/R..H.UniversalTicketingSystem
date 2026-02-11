<?php
// users.php
require __DIR__ . '/config/db.php'; // <-- ajusta si tu db.php está en otra ruta
$active = 'users';

// Helpers
function e($str) {
  return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
function roleClass($rol) {
  if ($rol === 'Superadmin') return 'role-super';
  if ($rol === 'Admin') return 'role-admin';
  return 'role-user'; // Usuario General
}

$createErrors = [];
$passErrors   = [];
$createdPassword = null;

// Flash (mensajes por URL)
$flashCreated   = isset($_GET['created']);
$flashPassUp    = isset($_GET['pass_updated']);

// Traer roles para el select
$rolesStmt = $pdo->query("SELECT id_role, name FROM roles ORDER BY id_role");
$roles = $rolesStmt->fetchAll();

// ============================
// CREATE USER (POST)
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_user') {
  $full_name = trim($_POST['full_name'] ?? '');
  $email     = trim($_POST['email'] ?? '');
  $area      = trim($_POST['area'] ?? '');
  $id_role   = (int)($_POST['id_role'] ?? 0);

  // Password opcional: si viene vacío, generamos una
  $plain_pass = trim($_POST['password_plain'] ?? '');

  // Validaciones
  if ($full_name === '') $createErrors[] = "El nombre es obligatorio.";
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $createErrors[] = "Email inválido.";
  if (!in_array($area, ['IT Support', 'Operaciones', 'Marketing'], true)) $createErrors[] = "Área inválida.";
  if ($id_role <= 0) $createErrors[] = "Rol inválido.";

  if ($plain_pass === '') {
    // Genera contraseña para compartir (solo se muestra una vez aquí)
    $plain_pass = 'RHR-' . bin2hex(random_bytes(4)) . '!';
  }

  if (!$createErrors) {
    try {
      $hash = password_hash($plain_pass, PASSWORD_BCRYPT);

      $ins = $pdo->prepare("
        INSERT INTO users (full_name, email, area, id_role, password_hash)
        VALUES (:full_name, :email, :area, :id_role, :password_hash)
      ");
      $ins->execute([
        ':full_name' => $full_name,
        ':email' => $email,
        ':area' => $area,
        ':id_role' => $id_role,
        ':password_hash' => $hash,
      ]);

      $createdPassword = $plain_pass;

      // Evita reenvío del form con refresh
      header("Location: users.php?created=1");
      exit;

    } catch (PDOException $ex) {
      // Duplicado email (23000)
      if (($ex->errorInfo[0] ?? '') === '23000') {
        $createErrors[] = "Ese email ya existe.";
      } else {
        $createErrors[] = "Error al guardar: " . $ex->getMessage();
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
// LISTADO USERS
// ============================
$stmt = $pdo->query("
  SELECT
    u.id_user,
    u.full_name,
    u.area,
    u.email,
    r.name AS rol
  FROM users u
  JOIN roles r ON r.id_role = u.id_role
  ORDER BY u.id_user DESC
");
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
  <!-- Users -->
  <link rel="stylesheet" href="./assets/css/users.css?v=1">
</head>

<body>

  <div class="layout d-flex">

    <!-- SIDEBAR reutilizable -->
    <?php include __DIR__ . '/partials/menu.php'; ?>

    <!-- MAIN -->
    <main class="main flex-grow-1 d-flex justify-content-center align-items-start">
      <section class="panel card users-panel">

        <!-- Alerts (top) -->
        <?php if ($flashCreated): ?>
          <div class="alert alert-success mb-3">
            Usuario creado correctamente.
          </div>
        <?php endif; ?>

        <?php if ($flashPassUp): ?>
          <div class="alert alert-success mb-3">
            Contraseña actualizada correctamente.
          </div>
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

        <!-- Header panel -->
        <div class="users-head">
          <h1 class="panel__title m-0">Users</h1>

          <div class="users-actions">
            <button class="btn-pro" type="button" data-bs-toggle="modal" data-bs-target="#createUserModal">
              Create User
            </button>

            <button class="btn-pro btn-pro--sm" type="button" data-bs-toggle="modal" data-bs-target="#genPassModal">
              Generate Password
            </button>
          </div>
        </div>

        <!-- Tabla -->
        <div class="table-responsive users-table mt-3">
          <table class="table table-borderless align-middle mb-0">
            <thead>
              <tr>
                <th class="th-center">ID</th>
                <th>Name</th>
                <th>Area</th>
                <th>Email</th>
                <th>Rol</th>
                <th class="th-center">Modify Password</th>
              </tr>
            </thead>

            <tbody>
              <?php if (empty($users)): ?>
                <tr>
                  <td colspan="6" class="text-center py-4 text-muted">No hay usuarios registrados.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($users as $u): ?>
                  <tr>
                    <td class="th-center fw-bold"><?= e(str_pad($u['id_user'], 3, '0', STR_PAD_LEFT)) ?></td>
                    <td><?= e($u['full_name']) ?></td>
                    <td><?= e($u['area']) ?></td>
                    <td><?= e($u['email']) ?></td>

                    <td>
                      <span class="badge role-badge <?= e(roleClass($u['rol'])) ?>">
                        <?= e($u['rol']) ?>
                      </span>
                    </td>

                    <td class="th-center">
                      <button
                        class="icon-action"
                        type="button"
                        title="Cambiar contraseña"
                        data-bs-toggle="modal"
                        data-bs-target="#modPassModal"
                        data-user-id="<?= e($u['id_user']) ?>"
                        data-user-name="<?= e($u['full_name']) ?>"
                      >
                        <i class="fa-solid fa-key"></i>
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>

          </table>
        </div>

        <!-- Footer tabla -->
        <div class="table-foot d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 mt-3">
          <span class="foot-text">Usuarios cargados: <?= e(count($users)) ?></span>

          <nav aria-label="Paginación">
            <ul class="pagination pagination-sm mb-0">
              <li class="page-item disabled"><a class="page-link" href="#">Anterior</a></li>
              <li class="page-item active"><a class="page-link" href="#">1</a></li>
              <li class="page-item"><a class="page-link" href="#">2</a></li>
              <li class="page-item"><a class="page-link" href="#">Siguiente</a></li>
            </ul>
          </nav>
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

        <form method="POST" action="users.php">
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
              <div class="col-12">
                <label class="form-label">Nombre</label>
                <input class="form-control pro-input" name="full_name" type="text" placeholder="Nombre completo" required>
              </div>

              <div class="col-12">
                <label class="form-label">Email</label>
                <input class="form-control pro-input" name="email" type="email" placeholder="correo@dominio.com" required>
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

  <!-- MODAL: Generate Password (solo front por ahora) -->
  <div class="modal fade" id="genPassModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content modal-pro">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Generate Password</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted mb-2">Genera una contraseña segura para el usuario seleccionado.</p>
          <div class="d-flex gap-2">
            <input class="form-control pro-input" type="text" value="RHR-9xA!2kP@" readonly>
            <button class="btn-pro btn-pro--sm" type="button"><i class="fa-regular fa-copy me-2"></i>Copy</button>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cerrar</button>
          <button class="btn-pro" type="button">Regenerar</button>
        </div>
      </div>
    </div>
  </div>

  <!-- MODAL: Modify Password (YA FUNCIONAL) -->
  <div class="modal fade" id="modPassModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content modal-pro" method="POST" action="users.php">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Modify Password</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="action" value="update_password">
          <input type="hidden" id="userIdToUpdate" name="id_user">

          <label class="form-label">Nueva contraseña</label>
          <input
            class="form-control pro-input"
            id="newPassInput"
            name="new_password"
            type="password"
            placeholder="••••••••"
            required
          >
          <small class="text-muted">Mínimo 8 caracteres, incluye mayúscula, número y símbolo.</small>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancelar</button>
          <button class="btn-pro" type="submit">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    // Pasa user_id al modal Modify Password
    const modPassModal = document.getElementById('modPassModal');
    modPassModal?.addEventListener('show.bs.modal', (event) => {
      const btn = event.relatedTarget;
      const userId = btn?.getAttribute('data-user-id') || '';
      const hidden = document.getElementById('userIdToUpdate');
      const pass   = document.getElementById('newPassInput');

      if (hidden) hidden.value = userId;
      if (pass) pass.value = '';
    });
  </script>

</body>
</html>