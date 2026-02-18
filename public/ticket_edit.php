<?php
require __DIR__ . '/partials/auth.php';
require __DIR__ . '/config/db.php';

$active = 'tickets';

$ticketId = (int)($_GET['id'] ?? 0);
if ($ticketId <= 0) {
  header("Location: tickets.php");
  exit;
}

$errors = [];

// 1) Traer ticket
$stmt = $pdo->prepare("
  SELECT 
    id_ticket,
    id_user,
    category,
    type,
    area,
    comments,
    status,
    created_at,
    updated_at,
    priority,
    assigned_user_id,
    ticket_url,
    attachment_path
  FROM tickets
  WHERE id_ticket = :id
  LIMIT 1
");
$stmt->execute([':id' => $ticketId]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
  header("Location: tickets.php");
  exit;
}

// 2) Lista SOLO IT Support (activos)
$itStmt = $pdo->query("
  SELECT id_user, full_name, email
  FROM users
  WHERE AREA = 'IT Support' AND is_active = 1
  ORDER BY full_name ASC
");
$itUsers = $itStmt->fetchAll(PDO::FETCH_ASSOC);

// Helper: escapar HTML
function esc($s) {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Helper: validar si un usuario pertenece a IT Support (con la lista ya cargada)
function isItSupportUser(array $itUsers, ?int $id): bool {
  if ($id === null) return true; // null = sin asignar
  foreach ($itUsers as $u) {
    if ((int)$u['id_user'] === (int)$id) return true;
  }
  return false;
}

// Defaults si no existen (por si tu tabla aún no tiene priority)
$ticket['priority'] = $ticket['priority'] ?? 'Media';

// 3) Guardar cambios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $assigned = $_POST['assigned_user_id'] ?? '';
  $priority = trim($_POST['priority'] ?? 'Media');
  $status   = trim($_POST['status'] ?? 'Pendiente');

  // Normalizar assigned
  $assigned_user_id = ($assigned === '' || $assigned === '0') ? null : (int)$assigned;

  // Validación: solo IT Support
  if (!isItSupportUser($itUsers, $assigned_user_id)) {
    $errors[] = "Solo puedes asignar tickets a usuarios de IT Support.";
  }

  // Validación enums
  $validPriority = ['Baja', 'Media', 'Alta', 'Urgente'];
  if (!in_array($priority, $validPriority, true)) {
    $errors[] = "Prioridad inválida.";
  }

  $validStatus = ['Pendiente', 'En Proceso', 'Resuelto', 'Cerrado'];
  if (!in_array($status, $validStatus, true)) {
    $errors[] = "Estatus inválido.";
  }

  if (!$errors) {
    try {
      // OJO: requiere que tu tabla tickets tenga:
      // - assigned_user_id (INT NULL)
      // - priority (ENUM...)
      $up = $pdo->prepare("
        UPDATE tickets
        SET assigned_user_id = :assigned_user_id,
            priority = :priority,
            status = :status,
            updated_at = NOW()
        WHERE id_ticket = :id
        LIMIT 1
      ");
      $up->execute([
        ':assigned_user_id' => $assigned_user_id,
        ':priority' => $priority,
        ':status' => $status,
        ':id' => $ticketId
      ]);

      header("Location: tickets.php?updated=1");
      exit;

    } catch (PDOException $e) {
      $errors[] = "Error al guardar en BD: " . $e->getMessage();
    }
  }

  // Si hubo errores, re-pintar valores enviados
  $ticket['assigned_user_id'] = $assigned_user_id;
  $ticket['priority'] = $priority;
  $ticket['status'] = $status;
}

// helpers UI
$ticketCode = str_pad((string)$ticket['id_ticket'], 3, '0', STR_PAD_LEFT);
$assignedName = '—';
$ticketUrl = trim((string)($ticket['ticket_url'] ?? ''));
$ticketEvidence = trim((string)($ticket['attachment_path'] ?? ''));
// Base path para construir URLs locales (evita /uploads/... que apunta a la raíz)
$basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])); // ej: /ticketsystem/public
$basePath = rtrim($basePath, '/');
if ($basePath === '/') { $basePath = ''; }

// Helper: construir href local
function localHref(string $path, string $basePath): string {
  $path = trim($path);
  if ($path === '') return '';
  if (preg_match('~^https?://~i', $path)) return $path;     // externo
  if (strpos($path, '/') === 0) return $path;               // absoluto desde root (ya viene bien)
  return $basePath . '/' . ltrim($path, '/');               // relativo al proyecto
}

if (!empty($ticket['assigned_user_id'])) {
  foreach ($itUsers as $u) {
    if ((int)$u['id_user'] === (int)$ticket['assigned_user_id']) {
      $assignedName = $u['full_name'];
      break;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Asignar Ticket | RH&R Ticketing</title>

  <!-- Bootstrap + FontAwesome -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <!-- CSS compartido (NO lo tocamos) -->
  <link rel="stylesheet" href="./assets/css/generarTickets.css">
  <link rel="stylesheet" href="./assets/css/menu.css">
  <link rel="stylesheet" href="./assets/css/movil.css">

  <!-- CSS SOLO para esta vista -->
  <link rel="stylesheet" href="assets/css/ticket_edit.css?v=<?= filemtime(__DIR__ . '/assets/css/ticket_edit.css') ?>">

  <script defer src="./assets/js/sidebar.js"></script>
</head>

<body class="ticket-edit-page">
  <div class="layout d-flex">

    <!-- SIDEBAR -->
    <?php include __DIR__ . '/partials/menu.php'; ?>

    <!-- MAIN -->
    <main class="main flex-grow-1 d-flex justify-content-center align-items-start">
      <div class="ticket-edit-page" style="width: 100%; max-width: 980px;">

        <section class="panel card" style="width:100%;">

          <!-- Header -->
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="panel__title m-0">Asignar Ticket</h1>
            <a href="tickets.php" class="btn btn-outline-secondary btn-sm">
              <i class="fa-solid fa-arrow-left me-2"></i>Volver
            </a>
          </div>

          <?php if ($errors): ?>
            <div class="alert alert-danger">
              <b>Revisa esto:</b>
              <ul class="mb-0">
                <?php foreach ($errors as $e): ?>
                  <li><?= esc($e) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <!-- Resumen -->
          <div class="card p-3 mb-3 ticket-summary">
            <div class="row g-3">
              <div class="col-md-3"><b>ID:</b> <?= esc($ticketCode) ?></div>
              <div class="col-md-3"><b>Area:</b> <?= esc($ticket['area']) ?></div>
              <div class="col-md-3"><b>Categoría:</b> <?= esc($ticket['category']) ?></div>
              <div class="col-md-3"><b>Tipo:</b> <?= esc($ticket['type']) ?></div>

              

              <div class="col-md-6">
                <b>URL:</b>
                <?php if (!empty($ticketUrl)): ?>
                  <?php
                    $urlHref = $ticketUrl;
                    if (!preg_match('~^https?://~i', $urlHref)) {
                      $urlHref = 'https://' . $urlHref;
                    }
                  ?>
                  <a href="<?= esc($urlHref) ?>" target="_blank" rel="noopener noreferrer">
                    <?= esc($ticketUrl) ?>
                  </a>
                <?php else: ?>
                  <span class="text-muted">Sin URL</span>
                <?php endif; ?>
              </div>

              <div class="col-md-6">
                <b>Evidencia:</b>
                <?php if (!empty($ticketEvidence)): ?>
                  <?php
                    $evHref = localHref($ticketEvidence, $basePath);
                    $ext = strtolower(pathinfo($ticketEvidence, PATHINFO_EXTENSION));
                    $evType = ($ext === 'pdf') ? 'pdf' : 'img';
                    $evName = basename($ticketEvidence);
                  ?>
                  <a href="#" 
                     class="evidence-link"
                     data-bs-toggle="modal"
                     data-bs-target="#evidenceModal"
                     data-ev-src="<?= esc($evHref) ?>"
                     data-ev-type="<?= esc($evType) ?>"
                     data-ev-name="<?= esc($evName) ?>">
                    <i class="fa-solid fa-paperclip"></i> Ver evidencia
                  </a>
                <?php else: ?>
                  <span class="text-muted">Sin evidencia</span>
                <?php endif; ?>
              </div>
<div class="col-md-4">
                <span class="chip brand">
                  <i class="fa-solid fa-user-check"></i>
                  <span><b>Asignado:</b> <?= esc($assignedName) ?></span>
                </span>
              </div>

              <div class="col-md-4">
                <span class="chip warn">
                  <i class="fa-solid fa-triangle-exclamation"></i>
                  <span><b>Prioridad:</b> <?= esc($ticket['priority']) ?></span>
                </span>
              </div>

              <div class="col-md-4">
                <span class="chip ok">
                  <i class="fa-solid fa-circle-info"></i>
                  <span><b>Status:</b> <?= esc($ticket['status']) ?></span>
                </span>
              </div>

              <div class="col-12">
                <b>Comentarios:</b><br>
                <?= nl2br(esc($ticket['comments'])) ?>
              </div>
            </div>
          </div>

          <!-- Form Asignación -->
          <form method="POST" class="card p-3">
            <div class="row g-3">

              <div class="col-md-6">
                <label class="form-label">Assigned to (solo IT Support)</label>
                <select name="assigned_user_id" class="form-select">
                  <option value="0">— Sin asignar —</option>
                  <?php foreach ($itUsers as $u): ?>
                    <option value="<?= (int)$u['id_user'] ?>"
                      <?= ((int)$ticket['assigned_user_id'] === (int)$u['id_user']) ? 'selected' : '' ?>>
                      <?= esc($u['full_name']) ?> (<?= esc($u['email']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <small class="text-muted">Solo aparecen usuarios activos con AREA = IT Support.</small>
              </div>

              <div class="col-md-3">
                <label class="form-label">Priority</label>
                <select name="priority" class="form-select">
                  <?php foreach (['Baja','Media','Alta','Urgente'] as $p): ?>
                    <option value="<?= esc($p) ?>" <?= ($ticket['priority'] === $p) ? 'selected' : '' ?>>
                      <?= esc($p) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                  <?php foreach (['Pendiente','En Proceso','Resuelto','Cerrado'] as $s): ?>
                    <option value="<?= esc($s) ?>" <?= ($ticket['status'] === $s) ? 'selected' : '' ?>>
                      <?= esc($s) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-12 d-flex justify-content-end gap-2">
                <a href="tickets.php" class="btn btn-outline-secondary">
                  Cancelar
                </a>
                <button class="btn btn-primary" type="submit">
                  <i class="fa-solid fa-floppy-disk me-2"></i>Guardar
                </button>
              </div>

            </div>
          </form>

        </section>
      </div>
    </main>

  </div>

  
  <!-- ====== MODAL: Evidencia ====== -->
  <div class="modal fade evidence-modal" id="evidenceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="evidenceTitle">Evidencia</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body">
          <div class="evidence-toolbar">
            <div class="btn-group" role="group" aria-label="Zoom">
              <button type="button" class="btn btn-outline-secondary btn-sm" id="evZoomOut" title="Alejar">
                <i class="fa-solid fa-magnifying-glass-minus"></i>
              </button>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="evZoomReset" title="Restablecer">
                <i class="fa-solid fa-rotate-left"></i>
              </button>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="evZoomIn" title="Acercar">
                <i class="fa-solid fa-magnifying-glass-plus"></i>
              </button>
            </div>

            <a class="btn btn-outline-primary btn-sm ms-auto" id="evOpenNewTab" href="#" target="_blank" rel="noopener noreferrer">
              <i class="fa-solid fa-up-right-from-square me-1"></i>Abrir
            </a>
          </div>

          <div class="evidence-canvas mt-3" id="evCanvas">
            <img id="evImg" alt="Evidencia" />
            <iframe id="evPdf" title="Evidencia PDF"></iframe>
          </div>

          <div class="evidence-hint mt-2 text-muted small">
            Tip: usa los botones para zoom (imágenes). En PDF puedes usar el zoom del navegador.
          </div>
        </div>
      </div>
    </div>
  </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    (function(){
      const modalEl = document.getElementById('evidenceModal');
      if(!modalEl) return;

      const titleEl = document.getElementById('evidenceTitle');
      const imgEl   = document.getElementById('evImg');
      const pdfEl   = document.getElementById('evPdf');
      const openEl  = document.getElementById('evOpenNewTab');

      const zoomInBtn    = document.getElementById('evZoomIn');
      const zoomOutBtn   = document.getElementById('evZoomOut');
      const zoomResetBtn = document.getElementById('evZoomReset');

      let scale = 1;

      function applyScale(){
        imgEl.style.transform = 'scale(' + scale.toFixed(2) + ')';
      }

      function reset(){
        scale = 1;
        applyScale();
      }

      modalEl.addEventListener('show.bs.modal', function (event) {
        const trigger = event.relatedTarget;
        if(!trigger) return;

        const src  = trigger.getAttribute('data-ev-src') || '';
        const type = trigger.getAttribute('data-ev-type') || 'img';
        const name = trigger.getAttribute('data-ev-name') || 'Evidencia';

        titleEl.textContent = 'Evidencia: ' + name;
        openEl.href = src;

        // reset zoom
        reset();

        if(type === 'pdf'){
          imgEl.style.display = 'none';
          pdfEl.style.display = 'block';
          pdfEl.src = src;
          // deshabilitar zoom botones (no aplica al iframe)
          zoomInBtn.disabled = true;
          zoomOutBtn.disabled = true;
          zoomResetBtn.disabled = true;
        } else {
          pdfEl.style.display = 'none';
          pdfEl.src = '';
          imgEl.style.display = 'block';
          imgEl.src = src;
          zoomInBtn.disabled = false;
          zoomOutBtn.disabled = false;
          zoomResetBtn.disabled = false;
        }
      });

      modalEl.addEventListener('hidden.bs.modal', function(){
        // limpiar fuentes
        imgEl.src = '';
        pdfEl.src = '';
        reset();
      });

      zoomInBtn.addEventListener('click', function(){
        scale = Math.min(4, scale + 0.15);
        applyScale();
      });

      zoomOutBtn.addEventListener('click', function(){
        scale = Math.max(0.4, scale - 0.15);
        applyScale();
      });

      zoomResetBtn.addEventListener('click', reset);

      // zoom con rueda (solo imagen)
      const canvas = document.getElementById('evCanvas');
      canvas.addEventListener('wheel', function(e){
        if(imgEl.style.display === 'none') return;
        e.preventDefault();
        const delta = Math.sign(e.deltaY);
        scale = delta > 0 ? Math.max(0.4, scale - 0.10) : Math.min(4, scale + 0.10);
        applyScale();
      }, { passive:false });
    })();
  </script>

</body>
</html>