<?php
require __DIR__ . '/partials/auth.php';
$active = 'tickets';

require __DIR__ . '/config/db.php'; // Ajusta si tu db.php está en otra ruta


// ===============================
// Eliminar Ticket (desde tickets.php)
// ===============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_ticket') {
  $delId = (int)($_POST['id_ticket'] ?? 0);

  if ($delId > 0) {
    // Obtener evidencia para borrarla (si existe)
    $stmtEv = $pdo->prepare("SELECT attachment_path FROM tickets WHERE id_ticket = :id LIMIT 1");
    $stmtEv->execute([':id' => $delId]);
    $att = (string)($stmtEv->fetchColumn() ?: '');

    // Borrar ticket
    $stmtDel = $pdo->prepare("DELETE FROM tickets WHERE id_ticket = :id");
    $stmtDel->execute([':id' => $delId]);

    // Intentar borrar archivo físico (solo si está dentro de /uploads)
    if ($att !== '') {
      $rel = str_replace('\\', '/', $att);
      $rel = ltrim($rel, '/');
      if (strpos($rel, 'public/') === 0) $rel = substr($rel, 7); // por si guardaron "public/uploads/..."
      if (strpos($rel, '..') === false && (strpos($rel, 'uploads/') === 0)) {
        $baseUploads = realpath(__DIR__ . '/uploads');
        $full = realpath(__DIR__ . '/' . $rel);
        if ($baseUploads && $full && strpos($full, $baseUploads) === 0 && is_file($full)) {
          @unlink($full);
        }
      }
    }
  }

  header('Location: tickets.php?deleted=1');
  exit;
}

// ===============================
// Parámetros GET
// ===============================
$q        = trim($_GET['q'] ?? '');
$stateUI  = trim($_GET['state'] ?? '');    // lo que selecciona el usuario en el UI
$priority = trim($_GET['priority'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;
$offset = ($page - 1) * $perPage;

// ===============================
// Mapeo UI -> BD (status)
// BD: Pendiente, En Proceso, Resuelto, Cerrado
// UI: Abierto, En proceso, En espera, Resuelto, Cancelado
// ===============================
$mapStateUItoDB = [
  'Abierto'     => 'Pendiente',
  'En proceso'  => 'En Proceso',
  'En espera'   => 'Pendiente',  // si aún no tienes "En espera" como estado real
  'Resuelto'    => 'Resuelto',
  'Cancelado'   => 'Cerrado',
];

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Construir URL manteniendo querys
function build_url($overrides = []) {
  $base = $_GET;
  foreach ($overrides as $k => $v) {
    $base[$k] = $v;
  }
  return 'tickets.php?' . http_build_query($base);
}

// UI helpers
function ui_status_label($dbStatus){
  $map = [
    'Pendiente'   => 'Abierto',
    'En Proceso'  => 'En proceso',
    'Resuelto'    => 'Resuelto',
    'Cerrado'     => 'Cancelado',
  ];
  return $map[$dbStatus] ?? $dbStatus;
}

function ui_status_class($uiStatus){
  $map = [
    'Abierto'     => 'st-open',
    'En proceso'  => 'st-progress',
    'En espera'   => 'st-wait',
    'Resuelto'    => 'st-done',
    'Cancelado'   => 'st-cancel',
  ];
  return $map[$uiStatus] ?? 'badge-status';
}

function ui_prio_class($prio){
  $map = [
    'Baja'    => 'prio-low',
    'Media'   => 'prio-medium',
    'Alta'    => 'prio-high',
    'Urgente' => 'prio-urgent',
  ];
  return $map[$prio] ?? 'prio-medium';
}

// ===============================
// FROM base (lo reutilizamos para que el filtro por nombre funcione en TOTAL y DATA)
// ===============================
// Detectar cómo se guarda el creador del ticket (ID o texto)
// - Si existe un campo ID (INT) se hace JOIN a users para mostrar el nombre real.
// - Si existe un campo texto (VARCHAR/TEXT) se usa directo.
// - Si existen ambos, se prioriza el JOIN y se hace fallback al texto con COALESCE.
$creatorIdCol = null;
$creatorNameCol = null;

try {
  $colsInfo = $pdo->query("SHOW COLUMNS FROM tickets")->fetchAll(PDO::FETCH_ASSOC);
  $typeByField = [];
  foreach ($colsInfo as $c) {
    $typeByField[$c['Field']] = strtolower((string)$c['Type']);
  }

  $idCandidates = [
    'created_by_user_id',
    'created_user_id',
    'creator_user_id',
    'created_by_id',
    'created_by_id_user',
    'id_user_creator',
    'user_creator_id',
    'user_id_creator',
    'created_by',  // a veces lo guardan como INT
    'id_user',     // creator id (tu esquema actual)
    'user_id'      // fallback común
  ];

  $nameCandidates = [
    'created_by_name',
    'created_by_full_name',
    'creator_name',
    'created_by_user',
    'created_by_email',
    'created_by',       // a veces lo guardan como texto
    'created_user',
    'creator',
    'created_by_text'
  ];

  foreach ($idCandidates as $c) {
    if (isset($typeByField[$c]) && preg_match('/\b(int|bigint|smallint|mediumint|tinyint)\b/', $typeByField[$c])) {
      $creatorIdCol = $c;
      break;
    }
  }

  foreach ($nameCandidates as $c) {
    if (isset($typeByField[$c]) && !preg_match('/\b(int|bigint|smallint|mediumint|tinyint)\b/', $typeByField[$c])) {
      $creatorNameCol = $c;
      break;
    }
  }
} catch (Throwable $e) {
  $creatorIdCol = null;
  $creatorNameCol = null;
}

$fromSql = "FROM tickets t LEFT JOIN users u ON u.id_user = t.assigned_user_id";
if ($creatorIdCol) {
  $fromSql .= " LEFT JOIN users uc ON uc.id_user = t.$creatorIdCol";
}

if ($creatorIdCol && $creatorNameCol) {
  $selectCreator = ", COALESCE(NULLIF(uc.full_name,''), NULLIF(t.$creatorNameCol,'')) AS created_by_name";
} elseif ($creatorIdCol) {
  $selectCreator = ", NULLIF(uc.full_name,'') AS created_by_name";
} elseif ($creatorNameCol) {
  $selectCreator = ", NULLIF(t.$creatorNameCol,'') AS created_by_name";
} else {
  $selectCreator = ", NULL AS created_by_name";
}



// ===============================
// WHERE dinámico
// ===============================
$where = [];
$params = [];

// Search
if ($q !== '') {
  // Buscar por: ID (id_ticket), Área (area) y Nombre del asignado (users.full_name)
  // Nota: CAST para poder usar LIKE sobre un entero.
    // Buscar por: ID (id_ticket), Área (area), Nombre del asignado y Nombre del creador (si existe)
  // Nota: CAST para poder usar LIKE sobre un entero.
    $searchParts = [
    "CAST(t.id_ticket AS CHAR) LIKE :q",
    "t.area LIKE :q",
    "u.full_name LIKE :q",
  ];
  if ($creatorIdCol) {
    $searchParts[] = "uc.full_name LIKE :q";
  }
  if ($creatorNameCol) {
    $searchParts[] = "t.$creatorNameCol LIKE :q";
  }
  $where[] = "(" . implode(" OR ", $searchParts) . ")";
  $params[':q'] = "%{$q}%";

}

// State (UI)
if ($stateUI !== '' && $stateUI !== 'Filter by state') {
  $dbState = $mapStateUItoDB[$stateUI] ?? $stateUI;
  $where[] = "t.status = :status";
  $params[':status'] = $dbState;
}

// Priority
if ($priority !== '' && $priority !== 'Priority') {
  $where[] = "t.priority = :priority";
  $params[':priority'] = $priority;
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// ===============================
// TOTAL
// ===============================
$stmtTotal = $pdo->prepare("SELECT COUNT(*) $fromSql $whereSql");
$stmtTotal->execute($params);
$total = (int)$stmtTotal->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

// ===============================
// DATA
// ===============================
$stmt = $pdo->prepare("
  SELECT
    t.id_ticket,
    t.area,
    t.priority,
    t.status,
    t.created_at,
    u.full_name AS assigned_name
    $selectCreator,
    t.ticket_url,
    t.attachment_path
  $fromSql
  $whereSql
  ORDER BY t.id_ticket DESC
  LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Alert de actualización
$updated = isset($_GET['updated']) ? (int)$_GET['updated'] : 0;
$created = isset($_GET['created']) ? (int)$_GET['created'] : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tickets | RH&R Ticketing</title>

  <!-- Bootstrap + FontAwesome -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <!-- CSS compartido (NO lo tocamos) -->
  <link rel="stylesheet" href="./assets/css/generarTickets.css">
  <link rel="stylesheet" href="./assets/css/menu.css">
  <link rel="stylesheet" href="./assets/css/movil.css">
  <script defer src="./assets/js/sidebar.js"></script>

  <!-- Tickets original -->

  <link rel="stylesheet" href="./assets/css/tickets.css?v=<?= filemtime(__DIR__ . '/assets/css/tickets.css') ?>">

  <!-- NUEVO: estilo moderno (scopeado) -->
</head>

<body class="tickets-page">
  <div class="layout d-flex">

    <!-- SIDEBAR -->
    <?php include __DIR__ . '/partials/menu.php'; ?>

    <!-- MAIN -->
    <main class="main flex-grow-1 d-flex justify-content-center align-items-stretch">
      <div class="tickets-page tickets-wrap">

        <section class="panel card tickets-panel">

          <!-- Header -->
          <div class="tickets-head d-flex justify-content-between align-items-center gap-2">
            <h1 class="panel__title m-0">Tickets</h1>

            <div class="head-right d-flex align-items-center gap-2">

              <!-- Search -->
              <form class="search-wrap d-flex align-items-center gap-2 px-3" method="GET" action="tickets.php">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input class="search-input border-0 bg-transparent" name="q" type="search" placeholder="Search by ID, name or area..." value="<?= esc($q) ?>">
                <?php if($stateUI!==''): ?><input type="hidden" name="state" value="<?= esc($stateUI) ?>"><?php endif; ?>
                <?php if($priority!==''): ?><input type="hidden" name="priority" value="<?= esc($priority) ?>"><?php endif; ?>
              </form>

              <button class="avatar-btn" type="button" title="Perfil">
                <span class="avatar-dot"></span>
              </button>
            </div>
          </div>

          <!-- Alerts -->
          <?php if ($updated === 1): ?>
            <div class="alert alert-success mt-3 mb-0">
              ✅ Ticket actualizado correctamente.
            </div>
          <?php endif; ?>

          <?php if ($created === 1): ?>
            <div class="alert alert-success mt-3 mb-0">
              ✅ Ticket creado correctamente.
            </div>
          <?php endif; ?>

          <!-- Filters -->
          <form class="filters row g-2 mt-3" method="GET" action="tickets.php">
            <input type="hidden" name="q" value="<?= esc($q) ?>">

            <div class="col-12 col-md-4">
              <select class="form-select filter-select" name="state" onchange="this.form.submit()">
                <option <?= $stateUI==='' ? 'selected':''; ?>>Filter by status</option>
                <?php foreach (['Abierto','En proceso','En espera','Resuelto','Cancelado'] as $opt): ?>
                  <option value="<?= esc($opt) ?>" <?= $stateUI===$opt ? 'selected':''; ?>><?= esc($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <select class="form-select filter-select" name="priority" onchange="this.form.submit()">
                <option <?= $priority==='' ? 'selected':''; ?>>Priority</option>
                <?php foreach (['Baja','Media','Alta','Urgente'] as $opt): ?>
                  <option value="<?= esc($opt) ?>" <?= $priority===$opt ? 'selected':''; ?>><?= esc($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-4 d-flex justify-content-md-end">
              <a class="btn-pro d-inline-flex align-items-center justify-content-center text-decoration-none"
                 href="generarTickets.php">
                <i class="fa-solid fa-plus me-2"></i>New Ticket
              </a>
            </div>
          </form>

          <!-- Table -->
          <div class="table-responsive tickets-table mt-3">
            <table class="table table-borderless align-middle mb-0">
              <thead>
                <tr>
                  <th class="th-center">ID</th>
                  <th>Created</th>
                  <th>Created by</th>
                  <th>Area</th>
                  <th>Priority</th>
                  <th>Status</th>
                  <th>Assigned to</th>
                  <th class="th-center th-actions">Action</th>
                </tr>
              </thead>

              <tbody>
                <?php if (!$tickets): ?>
                  <tr>
                    <td colspan="8" class="text-center py-4" style="color: rgba(0,0,0,.55); font-weight:800;">
                      No hay tickets para mostrar.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($tickets as $t):
                    $idTxt = str_pad((string)$t['id_ticket'], 3, '0', STR_PAD_LEFT);

                    $prio = $t['priority'] ?: 'Media';
                    $prioClass = ui_prio_class($prio);

                    $uiStatus = ui_status_label($t['status']);
                    $stClass = ui_status_class($uiStatus);

                    $assigned = $t['assigned_name'] ?: '—';
                    $createdBy = $t['created_by_name'] ?: '—';
                  
                    $ticketUrl = trim((string)($t['ticket_url'] ?? ''));
                    $evidence = trim((string)($t['attachment_path'] ?? ''));
?>
                    <tr>
                      <td class="th-center fw-bold td-id"><?= esc($idTxt) ?></td>

                     <td><?= $t['created_at'] ? date('d/m/Y H:i', strtotime($t['created_at'])) : '—' ?></td>
                      <td class="td-createdby"><span class="cell-ellipsis" title="<?= esc($createdBy) ?>"><?= esc($createdBy) ?></span></td>
                      
                      <td><?= esc($t['area']) ?></td>
                      

                      <td>
                        <span class="badge badge-prio <?= esc($prioClass) ?>">
                          <?= esc($prio) ?>
                        </span>
                      </td>

                      <td>
                        <span class="badge badge-status <?= esc($stClass) ?>">
                          <?= esc($uiStatus) ?>
                        </span>
                      </td>

                      <td><?= esc($assigned) ?></td>

                      
                      <td class="th-center td-actions">
                        <div class="action-wrap">
                          <a class="icon-action text-decoration-none"
                             href="ticket_edit.php?id=<?= (int)$t['id_ticket'] ?>"
                             title="Asignar / Editar">
                            <i class="fa-regular fa-pen-to-square"></i>
                          </a>

                          <?php if ($ticketUrl !== ''): ?>
                            <a class="icon-action text-decoration-none"
                               href="<?= esc($ticketUrl) ?>"
                               target="_blank" rel="noopener"
                               title="Abrir URL">
                              <i class="fa-solid fa-link"></i>
                            </a>
                          <?php endif; ?>

                          <?php if ($evidence !== ''): ?>
                            <button type="button"
                                    class="icon-action icon-evidence evidence-btn"
                                    data-bs-toggle="modal"
                                    data-bs-target="#evidenceModal"
                                    data-file="<?= esc($evidence) ?>"
                                    data-ticket="<?= esc($idTxt) ?>"
                                    title="Ver evidencia">
                              <i class="fa-solid fa-paperclip"></i>
                            </button>
                          <?php endif; ?>
                          <button type="button"
                                    class="icon-action icon-delete delete-btn"
                                    data-bs-toggle="modal"
                                    data-bs-target="#deleteModal"
                                    data-id="<?= (int)$t['id_ticket'] ?>"
                                    data-ticket="<?= esc($idTxt) ?>"
                                    title="Eliminar ticket">
                              <i class="fa-regular fa-trash-can"></i>
                          </button>
                        </div>
                      </td>

                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <!-- Footer table -->
          <?php
            $from = $total ? ($offset + 1) : 0;
            $to = min($offset + $perPage, $total);
          ?>
          <div class="table-foot d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 mt-3">
            <span class="foot-text">Mostrando <?= (int)$from ?>–<?= (int)$to ?> de <?= (int)$total ?> tickets</span>

            <nav aria-label="Paginación">
              <ul class="pagination pagination-sm mb-0">

                <li class="page-item <?= $page<=1 ? 'disabled':''; ?>">
                  <a class="page-link" href="<?= esc(build_url(['page'=>max(1,$page-1)])) ?>">Back</a>
                </li>

                <?php
                  $start = max(1, $page - 2);
                  $end = min($totalPages, $page + 2);
                  for($p=$start; $p<=$end; $p++):
                ?>
                  <li class="page-item <?= $p===$page ? 'active':''; ?>">
                    <a class="page-link" href="<?= esc(build_url(['page'=>$p])) ?>"><?= $p ?></a>
                  </li>
                <?php endfor; ?>

                <li class="page-item <?= $page>=$totalPages ? 'disabled':''; ?>">
                  <a class="page-link" href="<?= esc(build_url(['page'=>min($totalPages,$page+1)])) ?>">Next</a>
                </li>

              </ul>
            </nav>
          </div>

        </section>
      </div>
    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <!-- ===== Modal Evidencia (PRO) ===== -->
  <div class="modal fade evidence-modal" id="evidenceModal" tabindex="-1" aria-labelledby="evidenceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
      <div class="modal-content">
        <div class="modal-header align-items-center">
          <div class="d-flex flex-column">
            <h5 class="modal-title mb-0" id="evidenceModalLabel">
              Evidencia: <span id="evFilename" class="fw-bold"></span>
            </h5>
            <div class="small text-muted">
              Ticket <span id="evTicketCode" class="fw-bold"></span>
            </div>
          </div>

          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div class="evidence-toolbar">
              <button type="button" class="btn btn-light ev-toolbtn" id="evZoomOut" title="Zoom -">
                <i class="fa-solid fa-magnifying-glass-minus"></i>
              </button>
              <button type="button" class="btn btn-light ev-toolbtn" id="evReset" title="Reiniciar zoom">
                <i class="fa-solid fa-rotate-left"></i>
              </button>
              <button type="button" class="btn btn-light ev-toolbtn" id="evZoomIn" title="Zoom +">
                <i class="fa-solid fa-magnifying-glass-plus"></i>
              </button>
            </div>

            <div class="d-flex gap-2">
              <a id="evOpenNew" class="btn btn-outline-primary ev-open" href="#" target="_blank" rel="noopener" title="Abrir en nueva pestaña">
                <i class="fa-solid fa-up-right-from-square me-2"></i>Abrir
              </a>
              <a id="evDownload" class="btn btn-outline-secondary ev-open" href="#" download title="Descargar">
                <i class="fa-solid fa-download me-2"></i>Descargar
              </a>
            </div>
          </div>

          <div class="evidence-canvas" id="evCanvas">
            <!-- IMG/IFRAME dinámico -->
          </div>
          <div class="evidence-tip mt-2">
            Tip: usa los botones para hacer zoom en imágenes. En PDF puedes usar el zoom del navegador.
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ===== Modal Eliminar Ticket ===== -->
  <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content delete-modal">
        <div class="modal-header">
          <h5 class="modal-title" id="deleteModalLabel">
            <i class="fa-regular fa-trash-can me-2"></i>Eliminar ticket <span id="delTicketCode" class="fw-bold"></span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          ¿Seguro que quieres eliminar este ticket? Esta acción no se puede deshacer.
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>

          <form method="POST" class="m-0">
            <input type="hidden" name="action" value="delete_ticket">
            <input type="hidden" name="id_ticket" id="delTicketId" value="">
            <button type="submit" class="btn btn-danger">
              <i class="fa-regular fa-trash-can me-2"></i>Eliminar
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function(){
      // ========= Evidencia Viewer (igual al de ticket_edit) =========
      const modalEl = document.getElementById('evidenceModal');
      const evCanvas = document.getElementById('evCanvas');
      const evTicketCode = document.getElementById('evTicketCode');
      const evFilename = document.getElementById('evFilename');
      const evOpenNew = document.getElementById('evOpenNew');
      const evDownload = document.getElementById('evDownload');

      const btnZoomOut = document.getElementById('evZoomOut');
      const btnZoomIn  = document.getElementById('evZoomIn');
      const btnReset   = document.getElementById('evReset');

      let imgEl = null;
      let iframeEl = null;
      let scale = 1;

      function safePath(p){
        if(!p) return '';
        if(p.includes('..')) return '';
        p = p.replaceAll('\\\\','/');
        p = p.replace(/^\/+/, '');          // quita "/" inicial para evitar /uploads (root)
        if(p.startsWith('public/')) p = p.slice(7);
        return p;
      }

      function filenameFrom(url){
        try { return decodeURIComponent(url.split('/').pop() || url); }
        catch(e){ return (url.split('/').pop() || url); }
      }

      function setZoomControls(enabled){
        [btnZoomOut, btnZoomIn, btnReset].forEach(b => b.disabled = !enabled);
      }

      function applyScale(){
        if(!imgEl) return;
        imgEl.style.transform = 'scale(' + scale + ')';
      }

      function resetZoom(){
        scale = 1;
        applyScale();
        if(evCanvas) evCanvas.classList.remove('is-zoomed');
      }

      function zoom(delta){
        if(!imgEl) return;
        scale = Math.max(1, Math.min(4, +(scale + delta).toFixed(2)));
        applyScale();
        if(scale > 1) evCanvas.classList.add('is-zoomed');
        else evCanvas.classList.remove('is-zoomed');
      }

      btnZoomOut?.addEventListener('click', () => zoom(-0.25));
      btnZoomIn?.addEventListener('click',  () => zoom(+0.25));
      btnReset?.addEventListener('click', resetZoom);

      modalEl?.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        const rawFile = btn?.getAttribute('data-file') || '';
        const ticket = btn?.getAttribute('data-ticket') || '';
        const file = safePath(rawFile);

        evTicketCode.textContent = ticket ? ('#' + ticket) : '';
        evCanvas.innerHTML = '';
        imgEl = null;
        iframeEl = null;
        resetZoom();

        if(!file){
          evFilename.textContent = '';
          evCanvas.innerHTML = '<div class="alert alert-warning mb-0">No se encontró la evidencia.</div>';
          evOpenNew.href = '#';
          evDownload.href = '#';
          setZoomControls(false);
          return;
        }

        const url = file;
        evFilename.textContent = filenameFrom(url);
        evOpenNew.href = url;
        evDownload.href = url;

        const ext = (url.split('.').pop() || '').toLowerCase();

        if(['png','jpg','jpeg','webp','gif'].includes(ext)){
          imgEl = document.createElement('img');
          imgEl.src = url;
          imgEl.alt = 'Evidencia';
          evCanvas.appendChild(imgEl);
          setZoomControls(true);

          imgEl.addEventListener('load', resetZoom);
        } else if(ext === 'pdf'){
          iframeEl = document.createElement('iframe');
          iframeEl.src = url;
          evCanvas.appendChild(iframeEl);
          setZoomControls(false);
        } else {
          setZoomControls(false);
          evCanvas.innerHTML = `
            <div class="alert alert-info mb-0">
              Vista previa no disponible para <b>.${ext || 'archivo'}</b>. Puedes abrirlo o descargarlo.
            </div>
          `;
        }
      });

      modalEl?.addEventListener('hidden.bs.modal', function(){
        evCanvas.innerHTML = '';
        evTicketCode.textContent = '';
        evFilename.textContent = '';
        imgEl = null;
        iframeEl = null;
        scale = 1;
      });

      // ========= Delete modal =========
      const deleteModal = document.getElementById('deleteModal');
      const delTicketId = document.getElementById('delTicketId');
      const delTicketCode = document.getElementById('delTicketCode');

      deleteModal?.addEventListener('show.bs.modal', function(event){
        const btn = event.relatedTarget;
        const id = btn?.getAttribute('data-id') || '';
        const code = btn?.getAttribute('data-ticket') || '';
        if(delTicketId) delTicketId.value = id;
        if(delTicketCode) delTicketCode.textContent = code ? ('#' + code) : '';
      });

      deleteModal?.addEventListener('hidden.bs.modal', function(){
        if(delTicketId) delTicketId.value = '';
        if(delTicketCode) delTicketCode.textContent = '';
      });

    })();
  </script>


</body>
</html>