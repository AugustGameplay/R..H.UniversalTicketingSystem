<?php
require __DIR__ . '/partials/auth.php';
$active = 'generarTickets';

// ✅ 1) Conexión BD (ajusta la ruta si tu db.php está en otro lado)
require __DIR__ . '/config/db.php'; // <-- AJUSTA si no existe aquí

$errors = [];
$success = null;

// Detectar rol y area del usuario desde la sesion
$sessionUser = $_SESSION['user'] ?? null;
$currentUserIdRole = null;
$currentUserArea   = null;

if (is_array($sessionUser)) {
  $currentUserIdRole = $sessionUser['id_role'] ?? $sessionUser['id_rol'] ?? null;
  $currentUserArea   = $sessionUser['AREA']     ?? $sessionUser['area']  ?? null;
}
$currentUserIdRole = $currentUserIdRole ?? ($_SESSION['id_role'] ?? $_SESSION['id_rol'] ?? null);
$currentUserArea   = $currentUserArea   ?? ($_SESSION['AREA']    ?? $_SESSION['area']   ?? null);

// Si faltan datos, buscar en BD por id_user de sesion
$sessionUserId = null;
if (is_array($sessionUser)) {
  $sessionUserId = $sessionUser['id_user'] ?? $sessionUser['user_id'] ?? $sessionUser['id'] ?? null;
}
$sessionUserId = $sessionUserId ?? ($_SESSION['id_user'] ?? $_SESSION['user_id'] ?? null);

if ((!$currentUserArea || !$currentUserIdRole) && $sessionUserId) {
  $stmtU0 = $pdo->prepare("SELECT AREA, id_role FROM users WHERE id_user = :id LIMIT 1");
  $stmtU0->execute([':id' => (int)$sessionUserId]);
  $rowU0 = $stmtU0->fetch(PDO::FETCH_ASSOC);
  if ($rowU0) {
    if (!$currentUserArea)   $currentUserArea   = $rowU0['AREA']    ?: null;
    if (!$currentUserIdRole) $currentUserIdRole = $rowU0['id_role'] ?? null;
  }
}

// id_role = 3 es Usuario General
$isGeneralUser = ((int)($currentUserIdRole ?? 0) === 3);

// ✅ 2) Procesar POST (guardar ticket)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // Campos del form
  $category = trim($_POST['category'] ?? '');
  $type     = trim($_POST['type'] ?? '');

  // Si es General User, su área se toma de la sesión (no del POST)
  if ($isGeneralUser && $currentUserArea) {
    $area = trim($currentUserArea);
  } else {
    $area = trim($_POST['area'] ?? '');
  }
  $comments = trim($_POST['comments'] ?? '');


  // Auto-categoría (backend) por si el front no la llena
  $TYPE_TO_CATEGORY = [
    'Printer' => 'Hardware',
    'Does not turn on' => 'Hardware',
    'Very slow / freezing' => 'Hardware',
    'No internet connection' => 'Network',
    'Error opening application' => 'Software',
    'Need access to new system' => 'Email',
  ];

  if ($category === '') {
    $category = $TYPE_TO_CATEGORY[$type] ?? '';
  }
  if ($category === '') {
    $category = 'General';
  }



  
  $ticket_url = trim($_POST['ticket_url'] ?? '');
  $attachment_path = null;
// Validación  if ($type === '')     $errors[] = "Select an issue type.";
  if ($area === '')     $errors[] = "Select an area.";
  if ($comments === '') $errors[] = "Please provide a description.";

  
  if ($ticket_url !== '' && !filter_var($ticket_url, FILTER_VALIDATE_URL)) {
    $errors[] = "The URL is invalid.";
  }
if (!$errors) {
    try {
      
        // ====== Upload de evidencia (opcional) ======
      if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
          throw new Exception('Error uploading file (code: ' . $_FILES['attachment']['error'] . ').');
        }

        // Tamaño máximo: 10MB
        if ($_FILES['attachment']['size'] > 10 * 1024 * 1024) {
          throw new Exception('The file exceeds the maximum size of 10MB.');
        }

        $allowed_ext = ['png','jpg','jpeg','pdf','doc','docx','xlsx','xls','txt'];
        $original_name = $_FILES['attachment']['name'];
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_ext, true)) {
          throw new Exception('File type not allowed.');
        }

        $upload_dir = __DIR__ . '/uploads/tickets';
        if (!is_dir($upload_dir)) {
          mkdir($upload_dir, 0777, true);
        }

        $safe_name = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
        $dest = $upload_dir . '/' . $safe_name;

        if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
          throw new Exception('Could not save the uploaded file.');
        }

        // Guardamos ruta relativa para BD / vistas
        $attachment_path = 'uploads/tickets/' . $safe_name;
      }

// ✅ 3) Inserta en tu tabla
      // ======= AJUSTA ESTO A TU ESQUEMA REAL =======
      // Ejemplo recomendado de columnas:
      // tickets(id, user_id, category, type, area, comments, status, created_at)

      // ====== Usuario creador (from auth.php session) ======
$user_id = $_AUTH_USER_ID ?? null;
$creator_name = $_AUTH_FULL_NAME ?? null;
$creator_email = $_SESSION['email'] ?? null;

// Fallback: if auth gave us an ID but no email, fetch it
if ($user_id && !$creator_email) {
  $stmtU = $pdo->prepare("SELECT full_name, email FROM users WHERE id_user = :id LIMIT 1");
  $stmtU->execute([':id' => $user_id]);
  $urow = $stmtU->fetch(PDO::FETCH_ASSOC);
  if ($urow) {
    if (!$creator_name) $creator_name = $urow['full_name'] ?? null;
    $creator_email = $urow['email'] ?? null;
  }
}

if (!$user_id) {
  throw new Exception("Could not identify the user creating the ticket. Please log out and log in again.");
}


$stmt = $pdo->prepare("
  INSERT INTO tickets (id_user, category, type, area, comments, ticket_url, attachment_path, status, created_at)
  VALUES (:id_user, :category, :type, :area, :comments, :ticket_url, :attachment_path, 'Pendiente', NOW())
");

$stmt->execute([
  ':id_user'          => $user_id,
  ':category'         => $category,
  ':type'             => $type,
  ':area'             => $area,
  ':comments'         => $comments,
  ':ticket_url'       => ($ticket_url !== '' ? $ticket_url : null),
  ':attachment_path'  => $attachment_path
]);

      // ====== Notificación por correo (DEV: Mailpit / PROD: Resend) ======
      // Nota: si el envío falla, NO debe impedir que el ticket se guarde.
      $ticketId = (int)$pdo->lastInsertId();

      try {
        require_once __DIR__ . '/config/mailer.php';

        $tituloEmail = trim(($type !== '' ? $type : 'Ticket') . ' | ' . ($area !== '' ? $area : 'Área N/A'));

        $ticketMail = [
          'id' => $ticketId,
          'titulo' => $tituloEmail,
          'descripcion' => $comments,
          'area' => $area,
          'prioridad' => 'N/A',
          'creado_por' => ($creator_name ?: ('User #' . $user_id)),
          'url' => $ticket_url ?: '',
          'category' => $category,
          'type' => $type,
          'status' => 'Pendiente',
          'attachment_path' => $attachment_path ?: '',
        ];

        // 1) Notify all Admins and Super Admins (batched via BCC)
        try {
          $stAdmins = $pdo->query("
            SELECT u.email, u.full_name 
            FROM users u 
            JOIN roles r ON r.id_role = u.id_role 
            WHERE r.name IN ('Admin', 'Superadmin', 'Super Admin') 
              AND u.email IS NOT NULL AND u.email != ''
          ");
          $adminUsers = $stAdmins->fetchAll(PDO::FETCH_ASSOC);
          $adminEmailsArray = [];
          if (!empty($adminUsers)) {
            foreach ($adminUsers as $adm) {
              $admEmail = trim($adm['email']);
              if (filter_var($admEmail, FILTER_VALIDATE_EMAIL)) {
                $adminEmailsArray[] = ['email' => $admEmail, 'name' => $adm['full_name']];
              }
            }
          }
          
          notify_ticket_created_admin($ticketMail, $adminEmailsArray);
          
        } catch (\Throwable $admErr) {
          error_log('[MAIL] Could not query and batch admins: ' . $admErr->getMessage());
          // Fallback a correo por defecto
          notify_ticket_created_admin($ticketMail, []);
        }

        // 2) Send confirmation to the actual user who created the ticket
        if (!empty($creator_email) && filter_var($creator_email, FILTER_VALIDATE_EMAIL)) {
          $creatorMailName = $creator_name ?: ('User #' . $user_id);
          notify_ticket_created($ticketMail, $creator_email, $creatorMailName);
        }

      } catch (\Throwable $mailErr) {
        error_log('[MAIL] Could not send ticket notification: ' . $mailErr->getMessage());
      }


      $success = "✅ Ticket submitted successfully.";
    } catch (PDOException $e) {
      $errors[] = "Error saving to database: " . $e->getMessage();
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard | RH&R Ticketing</title>
  <link rel="icon" type="image/png" href="./assets/img/isotopo.png" />

  <!-- Bootstrap + FontAwesome -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <!-- Tu CSS (NO TOCADO) -->
  <link rel="stylesheet" href="./assets/css/generarTickets.css?v=<?= filemtime(__DIR__ . '/assets/css/generarTickets.css') ?>">
  <link rel="stylesheet" href="./assets/css/menu.css">
  <link rel="stylesheet" href="./assets/css/rhr-toast.css">
  <script defer src="./assets/js/rhr-toast.js"></script>
  <link rel="stylesheet" href="./assets/css/movil.css">

  <!-- Si ya tienes selects.js y lo usas en otras vistas, lo dejamos.
       Aquí igual implemento el binding en esta página para que sea seguro. -->
  <script src="./assets/js/selects.js"></script>
</head>

<style>
:root {
  --brand: #083B5C;
  --brand-hover: #D14B16;
  --brand-rgb: 8, 59, 92;
  --slate-50:  #f8fafc;
  --slate-100: #f1f5f9;
  --slate-200: #e2e8f0;
  --slate-300: #cbd5e1;
  --slate-400: #94a3b8;
  --slate-500: #64748b;
  --slate-700: #334155;
  --slate-800: #1e293b;
  --slate-900: #0f172a;
}

/* ── Tipo Picker Trigger ──────────────────────────────── */
.tipo-trigger {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  padding: 14px 18px;
  border-radius: 12px;
  border: 1px solid var(--slate-200);
  background: #fcfcfd;
  font-family: inherit;
  font-size: 0.95rem;
  font-weight: 500;
  color: var(--slate-800);
  cursor: pointer;
  text-align: left;
  box-shadow: 0 1px 2px rgba(0,0,0,0.01) inset;
  transition: all 0.2s ease;
}
.tipo-trigger:hover { background: #fff; border-color: var(--slate-300); }
.tipo-trigger.has-value { color: var(--slate-900); font-weight: 600; }
.tipo-trigger .tipo-trigger__icon {
  width: 34px; height: 34px; border-radius: 8px;
  background: var(--slate-100); border: 1px solid var(--slate-200);
  display: grid; place-items: center;
  font-size: 1rem; color: var(--brand); flex-shrink: 0;
  transition: background 0.2s ease;
}
.tipo-trigger .tipo-trigger__right {
  display: flex; align-items: center; gap: 10px;
  color: var(--slate-400); font-size: 0.8rem;
}
.tipo-trigger .chev {
  width: 10px; height: 10px;
  border-right: 2px solid var(--slate-400);
  border-bottom: 2px solid var(--slate-400);
  transform: rotate(45deg); flex-shrink: 0;
}

/* ── Modal Fallas ─────────────────────────────────────── */
#modalFallas .modal-content {
  border-radius: 20px;
  border: 1px solid var(--slate-200);
  box-shadow: 0 25px 50px -12px rgba(0,0,0,0.15);
  overflow: hidden;
}
#modalFallas .modal-header {
  background: #fff;
  border-bottom: 1px solid var(--slate-100);
  padding: 20px 24px 16px;
}
#modalFallas .modal-title {
  font-size: 1.1rem; font-weight: 800; color: var(--slate-900);
}
.modal-back-btn {
  background: var(--brand); border: 1px solid var(--brand-hover);
  color: #fff; border-radius: 8px;
  padding: 6px 14px; font-size: 0.8rem; font-weight: 700;
  display: none; cursor: pointer; transition: all 0.15s ease;
  align-items: center; gap: 6px;
  box-shadow: 0 1px 3px rgba(var(--brand-rgb), 0.3);
}
.modal-back-btn:hover { background: var(--brand-hover); }
.modal-back-btn.visible { display: flex !important; }

/* ── Step 1: Categoría cards grid ─────────────────────── */
.cat-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  padding: 20px 24px;
}
@media (max-width: 480px) { .cat-grid { grid-template-columns: repeat(2, 1fr); } }

.cat-card {
  display: flex; flex-direction: column; align-items: center;
  gap: 10px; padding: 18px 10px;
  border-radius: 14px; border: 1.5px solid var(--slate-200);
  background: #fff; cursor: pointer;
  transition: all 0.18s ease; text-align: center;
}
.cat-card:hover {
  border-color: rgba(var(--brand-rgb), 0.4);
  background: rgba(var(--brand-rgb), 0.03);
  transform: translateY(-2px);
  box-shadow: 0 4px 10px rgba(0,0,0,0.05);
}
.cat-card.is-selected {
  border-color: var(--brand);
  background: rgba(var(--brand-rgb), 0.04);
  box-shadow: 0 0 0 3px rgba(var(--brand-rgb), 0.1);
}
.cat-card__ico {
  width: 48px; height: 48px; border-radius: 14px;
  background: var(--slate-50); border: 1px solid var(--slate-200);
  display: grid; place-items: center;
  font-size: 1.4rem; color: var(--brand);
  transition: all 0.18s ease;
}
.cat-card:hover .cat-card__ico { background: rgba(var(--brand-rgb), 0.08); }
.cat-card__label { font-size: 0.82rem; font-weight: 700; color: var(--slate-700); }

/* ── Step 2: Sub-fallas list ──────────────────────────── */
.subfalla-list {
  display: flex; flex-direction: column;
  gap: 6px; padding: 16px 24px 24px;
}
.subfalla-item {
  display: flex; align-items: center; gap: 14px;
  padding: 12px 16px; border-radius: 12px;
  border: 1px solid var(--slate-200); background: #fff;
  cursor: pointer; font-size: 0.9rem; font-weight: 600;
  color: var(--slate-700); transition: all 0.15s ease;
}
.subfalla-item:hover {
  border-color: rgba(var(--brand-rgb), 0.4);
  background: rgba(var(--brand-rgb), 0.03);
  color: var(--brand);
}
.subfalla-item__ico { width: 18px; text-align: center; color: var(--slate-400); font-size: 0.85rem; }
.subfalla-item:hover .subfalla-item__ico { color: var(--brand); }
.subfalla-item--other { border-style: dashed; color: var(--slate-500); }

/* ── Category selected badge ──────────────────────────── */
.cat-breadcrumb {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 24px 4px;
  font-size: 0.8rem; font-weight: 700; color: var(--slate-500);
}
.cat-breadcrumb i { color: var(--brand); }
</style>

<body>

  <div class="layout d-flex">

    <!-- SIDEBAR -->
    <?php include __DIR__ . '/partials/menu.php'; ?>

    <!-- MAIN -->
    <main class="main flex-grow-1 d-flex justify-content-center align-items-start">
      <section class="panel card">

        <h1 class="panel__title mb-4">Generate Ticket</h1>

        <!-- Mensajes -->
        <div class="mx-auto" style="width:min(520px, 100%);">
          <?php if (!empty($_SESSION['flash_login'])): ?>
            <div data-rhr-toast="<?= htmlspecialchars($_SESSION['flash_login'], ENT_QUOTES, 'UTF-8') ?>" data-rhr-toast-type="success"></div>
            <?php unset($_SESSION['flash_login']); ?>
          <?php endif; ?>

          <?php if ($success): ?>
            <div data-rhr-toast="<?= htmlspecialchars($success) ?>" data-rhr-toast-type="success"></div>
          <?php endif; ?>

          <?php if ($errors): ?>
            <div data-rhr-toast data-rhr-toast-type="error">
              <ul>
                <?php foreach ($errors as $err): ?>
                  <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </div>

        <!-- ✅ FORM YA FUNCIONAL -->
        <form class="ticket-form mx-auto" id="ticketForm" method="POST" action="" novalidate enctype="multipart/form-data">

          <!-- Hidden inputs -->
          <input type="hidden" name="category" id="category">
          <input type="hidden" name="type"     id="type">

          <!-- Type (modal picker) -->
          <button type="button" class="select-pro w-100" id="tipoTrigger" data-bs-toggle="modal" data-bs-target="#modalFallas">
            <span id="tipoTriggerText">Type</span>
            <span class="chev" aria-hidden="true"></span>
          </button>

          <!-- Area -->
          <?php if ($isGeneralUser && $currentUserArea): ?>
            <?php /* General User: área fija, no editable */ ?>
            <div class="select-pro w-100 d-flex align-items-center justify-content-between" style="cursor:default; opacity:0.85;">
              <span><?= htmlspecialchars($currentUserArea) ?></span>
              <i class="fa-solid fa-lock" style="font-size:0.75rem; color:var(--slate-400);"></i>
            </div>
            <input type="hidden" name="area" id="area" value="<?= htmlspecialchars($currentUserArea) ?>">
          <?php else: ?>
            <div class="dropdown w-100">
              <button class="select-pro dropdown-toggle w-100" type="button" id="areaBtn" data-bs-toggle="dropdown" aria-expanded="false">
                <span id="areaText">Area</span>
                <span class="chev" aria-hidden="true"></span>
              </button>
              <ul class="dropdown-menu dropdown-pro w-100" aria-labelledby="areaBtn" data-target-text="#areaText" data-target-input="#area">
                <li><button class="dropdown-item" type="button" data-value="Accounting">Accounting</button></li>
                <li><button class="dropdown-item" type="button" data-value="Corporate">Corporate</button></li>
                <li><button class="dropdown-item" type="button" data-value="HR">HR</button></li>
                <li><button class="dropdown-item" type="button" data-value="Managers">Managers</button></li>
                <li><button class="dropdown-item" type="button" data-value="Marketing and IT">Marketing and IT</button></li>
                <li><button class="dropdown-item" type="button" data-value="Recruiters">Recruiters</button></li>
                <li><button class="dropdown-item" type="button" data-value="Workers Comp">Workers Comp</button></li>
              </ul>
              <input type="hidden" name="area" id="area">
            </div>
          <?php endif; ?>

          <!-- URL (opcional) -->
          <div class="field">
            <div class="field__row">
              <label class="field__label" for="ticket_url">URL (optional)</label>
              <span class="field__counter">Optional</span>
            </div>
            <div class="tc-urlwrap">
              <i class="fa-solid fa-link" aria-hidden="true"></i>
              <input class="tc-urlinput" type="url" id="ticket_url" name="ticket_url"
                value="<?= htmlspecialchars($_POST['ticket_url'] ?? '') ?>"
                placeholder="Paste a link (Drive, SharePoint, etc.)">
            </div>
            <div class="field__hint">Tip: if you paste without http/https, we normalize it automatically.</div>
            <div id="urlError" class="tc-inline-error" hidden>
              <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
              URL is invalid.
            </div>
          </div>

          <!-- Adjunto / Evidencia (opcional) -->
          <div class="field">
            <div class="field__row">
              <label class="field__label" for="attachment">Attachment/Evidence (optional)</label>
              <span class="field__counter">Max. 10MB</span>
            </div>
            <input type="file" id="attachment" name="attachment"
              accept=".png,.jpg,.jpeg,.pdf,.doc,.docx,.xlsx,.xls,.txt" hidden>

            <label for="attachment" class="tc-dropzone" id="dropzone">
              <div class="tc-dropzone__icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
              <div class="tc-dropzone__text">
                <strong>Drag your file here</strong>
                <span>or click to select</span>
              </div>
              <div class="tc-dropzone__meta">Allowed: PNG/JPG/PDF/DOC/XLS/TXT</div>
            </label>

            <div class="tc-fileinfo" id="fileInfo" hidden>
              <div class="tc-fileinfo__left">
                <img id="filePreview" class="tc-fileinfo__preview" alt="Preview" hidden>
                <div id="fileIcon" class="tc-fileinfo__icon" aria-hidden="true">
                  <i class="fa-regular fa-file"></i>
                </div>
                <div class="tc-fileinfo__txt">
                  <div class="tc-fileinfo__name" id="fileName">file</div>
                  <div class="tc-fileinfo__size" id="fileSize">0 KB</div>
                </div>
              </div>
              <button type="button" class="tc-fileinfo__remove" id="fileRemove" title="Remove file">
                <i class="fa-solid fa-xmark"></i>
              </button>
            </div>

            <div class="field__hint">Tip: If it is an image, you will see a small preview.</div>
          </div>

          <!-- Comments -->
          <div>
            <label class="label" for="comments">Comments</label>
            <textarea class="textarea" id="comments" name="comments" rows="6" required placeholder="Describe your problem"></textarea>
          </div>

          <button class="btn-send" type="submit">Send ticket</button>
        </form>

      </section>
    </main>

  </div>

  <!-- ═══════════════════════════════════════════════════
       MODAL: Selector de Tipo de Falla (2 pasos)
  ════════════════════════════════════════════════════ -->
  <div class="modal fade" id="modalFallas" tabindex="-1" aria-labelledby="modalFallasLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">

        <!-- Header -->
        <div class="modal-header">
          <div style="display:flex;align-items:center;gap:12px;flex:1;">
            <button type="button" class="modal-back-btn" id="modalBackBtn">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
              </svg>
              Back
            </button>
            <h5 class="modal-title" id="modalFallasLabel">What is the issue?</h5>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <!-- Body -->
        <div class="modal-body p-0" id="modalBody">

          <!-- STEP 1: Categoría grid -->
          <div id="step1">
            <div class="cat-grid">

              <button type="button" class="cat-card" data-cat="Hardware" data-cat-label="Computer / PC" data-cat-ico="fa-solid fa-desktop">
                <div class="cat-card__ico"><i class="fa-solid fa-desktop"></i></div>
                <div class="cat-card__label">Computer / PC</div>
              </button>

              <button type="button" class="cat-card" data-cat="Hardware" data-cat-label="Monitor" data-cat-ico="fa-solid fa-tv">
                <div class="cat-card__ico"><i class="fa-solid fa-tv"></i></div>
                <div class="cat-card__label">Monitor</div>
              </button>

              <button type="button" class="cat-card" data-cat="Hardware" data-cat-label="Printer" data-cat-ico="fa-solid fa-print">
                <div class="cat-card__ico"><i class="fa-solid fa-print"></i></div>
                <div class="cat-card__label">Printer</div>
              </button>

              <button type="button" class="cat-card" data-cat="Network" data-cat-label="Network / Internet" data-cat-ico="fa-solid fa-wifi">
                <div class="cat-card__ico"><i class="fa-solid fa-wifi"></i></div>
                <div class="cat-card__label">Network / Internet</div>
              </button>

              <button type="button" class="cat-card" data-cat="Software" data-cat-label="App / Software" data-cat-ico="fa-solid fa-cubes">
                <div class="cat-card__ico"><i class="fa-solid fa-cubes"></i></div>
                <div class="cat-card__label">App / Software</div>
              </button>

              <button type="button" class="cat-card" data-cat="Email" data-cat-label="Email / Access" data-cat-ico="fa-solid fa-envelope-circle-check">
                <div class="cat-card__ico"><i class="fa-solid fa-envelope-circle-check"></i></div>
                <div class="cat-card__label">Email / Access</div>
              </button>

              <button type="button" class="cat-card" data-cat="Hardware" data-cat-label="Keyboard / Mouse" data-cat-ico="fa-solid fa-keyboard">
                <div class="cat-card__ico"><i class="fa-solid fa-keyboard"></i></div>
                <div class="cat-card__label">Keyboard / Mouse</div>
              </button>

              <button type="button" class="cat-card" data-cat="Hardware" data-cat-label="Phone / VoIP" data-cat-ico="fa-solid fa-phone-office">
                <div class="cat-card__ico"><i class="fa-solid fa-phone"></i></div>
                <div class="cat-card__label">Phone / RingCentral</div>
              </button>

              <button type="button" class="cat-card" data-cat="General" data-cat-label="Other" data-cat-ico="fa-solid fa-circle-question">
                <div class="cat-card__ico"><i class="fa-solid fa-circle-question"></i></div>
                <div class="cat-card__label">Other</div>
              </button>

            </div>
          </div>

          <!-- STEP 2: Sub-fallas (se llena dinámicamente) -->
          <div id="step2" hidden>
            <div class="cat-breadcrumb" id="catBreadcrumb">
              <i class="fa-solid fa-folder-open"></i>
              <span id="catBreadcrumbLabel">Category</span>
              <i class="fa-solid fa-chevron-right" style="font-size:0.6rem;opacity:0.5;"></i>
              <span>Select specific issue</span>
            </div>
            <div class="subfalla-list" id="subfallaList">
              <!-- Se genera dinámicamente -->
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>

  <!-- Bootstrap Bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
  'use strict';

  /* ─────────────────────────────────────────────────────────
     CATÁLOGO DE FALLAS (categoría → subfallas)
  ───────────────────────────────────────────────────────── */
  const FALLAS = {
    'Computer / PC': [
      { ico: 'fa-solid fa-power-off',          label: 'Does not turn on' },
      { ico: 'fa-solid fa-gauge',              label: 'Very slow / freezing' },
      { ico: 'fa-solid fa-fire',               label: 'Overheating / turns off randomly' },
      { ico: 'fa-solid fa-volume-high',        label: 'Making strange noises' },
      { ico: 'fa-solid fa-skull-crossbones',   label: 'Blue screen / crashing' },
      { ico: 'fa-solid fa-rotate-right',       label: 'Keeps restarting' },
      { ico: 'fa-solid fa-circle-question',    label: 'Other issue', other: true },
    ],
    'Monitor': [
      { ico: 'fa-solid fa-power-off',          label: 'Does not turn on' },
      { ico: 'fa-solid fa-bolt',               label: 'Flickering / flashing' },
      { ico: 'fa-solid fa-plug',               label: 'HDMI / VGA / cable issue' },
      { ico: 'fa-solid fa-expand',             label: 'Incorrect resolution' },
      { ico: 'fa-solid fa-eye-slash',          label: 'No display / black screen' },
      { ico: 'fa-solid fa-bars',               label: 'Lines / spots on screen' },
      { ico: 'fa-solid fa-circle-question',    label: 'Other issue', other: true },
    ],
    'Printer': [
      { ico: 'fa-solid fa-fill-drip',          label: 'Out of ink / toner' },
      { ico: 'fa-solid fa-file-circle-xmark',  label: 'Out of paper / paper jam' },
      { ico: 'fa-solid fa-link-slash',         label: 'Not connecting (USB/Network/WiFi)' },
      { ico: 'fa-solid fa-ban',                label: 'Not printing / stuck in queue' },
      { ico: 'fa-solid fa-file-circle-exclamation', label: 'Printing cut off / bad format' },
      { ico: 'fa-solid fa-circle-question',    label: 'Other issue', other: true },
    ],
    'Network / Internet': [
      { ico: 'fa-solid fa-wifi',               label: 'No internet connection' },
      { ico: 'fa-solid fa-gauge',              label: 'Very slow connection' },
      { ico: 'fa-solid fa-ethernet',           label: 'Network cable unplugged/damaged' },
      { ico: 'fa-solid fa-server',             label: 'Cannot access server / VPN' },
      { ico: 'fa-solid fa-globe',              label: 'Certain websites not loading' },
      { ico: 'fa-solid fa-circle-question',    label: 'Other issue', other: true },
    ],
    'App / Software': [
      { ico: 'fa-solid fa-triangle-exclamation', label: 'Error opening application' },
      { ico: 'fa-solid fa-bug',                label: 'App crashing / closing unexpectedly' },
      { ico: 'fa-solid fa-lock',               label: 'No access / permission denied' },
      { ico: 'fa-solid fa-download',           label: 'Need software installed' },
      { ico: 'fa-solid fa-rotate',             label: 'Pending / forced update' },
      { ico: 'fa-solid fa-circle-question',    label: 'Other issue', other: true },
    ],
    'Email / Access': [
      { ico: 'fa-solid fa-key',                label: 'Forgot password' },
      { ico: 'fa-solid fa-user-lock',          label: 'Account locked' },
      { ico: 'fa-solid fa-paper-plane',        label: 'Cannot send or receive emails' },
      { ico: 'fa-solid fa-id-badge',           label: 'Need access to new system' },
      { ico: 'fa-solid fa-shield-halved',      label: 'Suspected compromised account' },
      { ico: 'fa-solid fa-circle-question',    label: 'Other issue', other: true },
    ],
    'Keyboard / Mouse': [
      { ico: 'fa-solid fa-keyboard',           label: 'Keys not responding' },
      { ico: 'fa-solid fa-computer-mouse',     label: 'Mouse not moving / clicking' },
      { ico: 'fa-solid fa-battery-quarter',    label: 'Battery dead (wireless)' },
      { ico: 'fa-solid fa-circle-exclamation', label: 'Device not recognized' },
      { ico: 'fa-solid fa-circle-question',    label: 'Other issue', other: true },
    ],
    'Phone / VoIP': [
      { ico: 'fa-solid fa-phone-slash',        label: 'No dial tone / cannot call' },
      { ico: 'fa-solid fa-microphone-slash',   label: 'No audio during calls' },
      { ico: 'fa-solid fa-signal',             label: 'Disconnected from VoIP network' },
      { ico: 'fa-solid fa-power-off',          label: 'Will not turn on / frozen' },
      { ico: 'fa-solid fa-circle-question',    label: 'Other issue', other: true },
    ],
    'Other': [
      { ico: 'fa-solid fa-wrench',             label: 'Unlisted hardware failure' },
      { ico: 'fa-solid fa-comment-dots',       label: 'General request / inquiry', other: true },
    ],
  };

  /* ─────────────────────────────────────────────────────────
     LÓGICA DEL MODAL (2 Pasos)
  ───────────────────────────────────────────────────────── */
  const modalEl      = document.getElementById('modalFallas');
  const step1        = document.getElementById('step1');
  const step2        = document.getElementById('step2');
  const backBtn      = document.getElementById('modalBackBtn');
  const modalTitle   = document.getElementById('modalFallasLabel');
  const subfallaList = document.getElementById('subfallaList');
  const catBLabel    = document.getElementById('catBreadcrumbLabel');
  const triggerText  = document.getElementById('tipoTriggerText');
  const inputType    = document.getElementById('type');
  const inputCat     = document.getElementById('category');
  const tipoTrigger  = document.getElementById('tipoTrigger');

  let bsModal = null;
  modalEl && (bsModal = new bootstrap.Modal(modalEl));

  // Reset to step 1 whenever modal opens
  modalEl.addEventListener('show.bs.modal', () => showStep1());
  
  // Prevent focus issues when modal closes (fixes aria-hidden warning)
  modalEl.addEventListener('hide.bs.modal', () => document.activeElement?.blur());

  function showStep1() {
    step1.hidden = false;
    step2.hidden = true;
    backBtn.classList.remove('visible');
    modalTitle.textContent = 'What is the issue?';
  }

  function showStep2(catLabel, catIco) {
    step1.hidden = true;
    step2.hidden = false;
    backBtn.classList.add('visible');
    modalTitle.textContent = 'Select the specific issue';
    catBLabel.textContent = catLabel;

    // Build subfalla items
    const fallas = FALLAS[catLabel] || [{ ico: 'fa-solid fa-circle-question', label: 'Other', other: true }];
    subfallaList.innerHTML = '';
    fallas.forEach(f => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'subfalla-item' + (f.other ? ' subfalla-item--other' : '');
      btn.innerHTML = `<i class="subfalla-item__ico ${f.ico}"></i> ${f.label}`;
      btn.addEventListener('click', () => selectFalla(catLabel, f.label, catIco));
      subfallaList.appendChild(btn);
    });
  }

  function selectFalla(catLabel, fallaLabel, catIco) {
    // Set hidden inputs
    const catData = document.querySelector(`.cat-card[data-cat-label="${catLabel}"]`)?.dataset?.cat || 'General';
    inputType.value = fallaLabel;
    inputCat.value  = catData;

    // Update trigger button text
    triggerText.textContent = `${catLabel} — ${fallaLabel}`;

    // Close modal
    bsModal && bsModal.hide();
  }

  // Cat card clicks → go to step 2
  document.querySelectorAll('.cat-card').forEach(card => {
    card.addEventListener('click', () => {
      const label = card.dataset.catLabel;
      const ico   = card.dataset.catIco;
      showStep2(label, ico);
    });
  });

  // Back button
  backBtn.addEventListener('click', () => showStep1());

  /* ─────────────────────────────────────────────────────────
     ÁREA: Dropdown binding
  ───────────────────────────────────────────────────────── */
  document.querySelectorAll('.dropdown-menu[data-target-text][data-target-input]').forEach(menu => {
    const textEl  = document.querySelector(menu.getAttribute('data-target-text'));
    const inputEl = document.querySelector(menu.getAttribute('data-target-input'));
    menu.querySelectorAll('.dropdown-item[data-value]').forEach(item => {
      item.addEventListener('click', () => {
        if (textEl)  textEl.textContent = item.getAttribute('data-value');
        if (inputEl) inputEl.value      = item.getAttribute('data-value');
      });
    });
  });

  /* ─────────────────────────────────────────────────────────
     AUTO-CATEGORÍA en JS (fallback igual que en PHP)
  ───────────────────────────────────────────────────────── */
  const TYPE_TO_CATEGORY = {
    'Computer / PC': 'Hardware',
    'Monitor': 'Hardware', 'Printer': 'Hardware',
    'Keyboard / Mouse': 'Hardware', 'Phone / VoIP': 'Hardware',
    'Network / Internet': 'Network', 'App / Software': 'Software',
    'Email / Access': 'Email', 'Other': 'General',
  };

  /* ─────────────────────────────────────────────────────────
     VALIDACIÓN antes de enviar
  ───────────────────────────────────────────────────────── */
  const form = document.getElementById('ticketForm');
  const isGeneralUser = <?= $isGeneralUser ? 'true' : 'false' ?>;
  form?.addEventListener('submit', e => {
    const typeVal = document.getElementById('type').value.trim();
    const areaVal = document.getElementById('area').value.trim();
    const commVal = document.getElementById('comments').value.trim();
    if (!typeVal || (!isGeneralUser && !areaVal) || !commVal) {
      e.preventDefault();
      rhrToast('Please fill out all required fields: Issue Type, Area, and Comments.', 'error');
    }
  });

  /* ─────────────────────────────────────────────────────────
     URL: normalizar + validación UX
  ───────────────────────────────────────────────────────── */
  const urlInput = document.getElementById('ticket_url');
  const urlError = document.getElementById('urlError');

  function normalizeUrl(val) {
    const v = (val || '').trim();
    if (!v) return '';
    if (/^https?:\/\//i.test(v)) return v;
    return 'https://' + v;
  }
  function isValidUrl(val) {
    try { new URL(normalizeUrl(val)); return true; } catch(e) { return false; }
  }
  urlInput?.addEventListener('blur', () => {
    if (!urlInput.value.trim()) { if (urlError) urlError.hidden = true; return; }
    urlInput.value = normalizeUrl(urlInput.value);
    if (urlError) urlError.hidden = isValidUrl(urlInput.value);
  });
  urlInput?.addEventListener('input', () => { if (urlError) urlError.hidden = true; });

  /* ─────────────────────────────────────────────────────────
     EVIDENCIA: nombre, tamaño, preview y quitar
  ───────────────────────────────────────────────────────── */
  const fileInput  = document.getElementById('attachment');
  const dropzone   = document.getElementById('dropzone');
  const fileInfo   = document.getElementById('fileInfo');
  const fileNameEl = document.getElementById('fileName');
  const fileSizeEl = document.getElementById('fileSize');
  const filePrev   = document.getElementById('filePreview');
  const fileIcon   = document.getElementById('fileIcon');
  const fileRemove = document.getElementById('fileRemove');

  function humanSize(bytes) {
    const u = ['B','KB','MB','GB']; let n = bytes || 0, i = 0;
    while (n >= 1024 && i < u.length-1) { n /= 1024; i++; }
    return (i === 0 ? Math.round(n) : n.toFixed(1)) + ' ' + u[i];
  }

  function setFileUi(file) {
    if (!file) return;
    if (fileInfo) fileInfo.hidden = false;
    if (fileNameEl) fileNameEl.textContent = file.name;
    if (fileSizeEl) fileSizeEl.textContent = humanSize(file.size);
    const name  = (file.name || '').toLowerCase();
    const isImg = /^image\//.test(file.type) || /\.(png|jpe?g|gif|webp)$/i.test(name);
    if (filePrev) { filePrev.hidden = true; filePrev.src = ''; }
    if (fileIcon) { fileIcon.style.display = 'grid'; fileIcon.innerHTML = '<i class="fa-regular fa-file"></i>'; }
    if (isImg && filePrev) {
      filePrev.src = URL.createObjectURL(file); filePrev.hidden = false;
      if (fileIcon) fileIcon.style.display = 'none';
    } else {
      let ico = 'fa-regular fa-file';
      if (/\.pdf$/i.test(name))          ico = 'fa-regular fa-file-pdf';
      else if (/\.(doc|docx)$/i.test(name)) ico = 'fa-regular fa-file-word';
      else if (/\.(xls|xlsx)$/i.test(name)) ico = 'fa-regular fa-file-excel';
      else if (/\.txt$/i.test(name))     ico = 'fa-regular fa-file-lines';
      if (fileIcon) fileIcon.innerHTML = `<i class="${ico}"></i>`;
    }
  }

  function clearFile() {
    if (!fileInput) return;
    fileInput.value = '';
    if (fileInfo) fileInfo.hidden = true;
    if (filePrev) { filePrev.hidden = true; filePrev.src = ''; }
    if (fileIcon) { fileIcon.style.display = 'grid'; fileIcon.innerHTML = '<i class="fa-regular fa-file"></i>'; }
  }

  fileInput?.addEventListener('change', () => {
    const f = fileInput.files?.[0];
    f ? setFileUi(f) : clearFile();
  });
  fileRemove?.addEventListener('click', () => clearFile());

  ['dragenter','dragover'].forEach(ev => {
    dropzone?.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.add('is-over'); });
  });
  ['dragleave','drop'].forEach(ev => {
    dropzone?.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.remove('is-over'); });
  });
  dropzone?.addEventListener('drop', e => {
    const dt = e.dataTransfer;
    if (!dt?.files?.length || !fileInput) return;
    try { const dt2 = new DataTransfer(); dt2.items.add(dt.files[0]); fileInput.files = dt2.files; } catch(_) {}
    setFileUi(dt.files[0]);
  });

  </script>

  <?php if ($success): ?>
  <script>
  // Process queued emails in background (non-blocking)
  fetch('api/process_queue.php', {headers:{'X-Requested-With':'XMLHttpRequest'}}).catch(()=>{});
  </script>
  <?php endif; ?>

</body>
</html>