<?php
require __DIR__ . '/partials/auth.php';
$active = 'tickets';

require __DIR__ . '/config/db.php'; // Ajusta si tu db.php está en otra ruta

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
$fromSql = "FROM tickets t LEFT JOIN users u ON u.id_user = t.assigned_user_id";

// ===============================
// WHERE dinámico
// ===============================
$where = [];
$params = [];

// Search
if ($q !== '') {
  // Buscar por: ID (id_ticket), Área (area) y Nombre del asignado (users.full_name)
  // Nota: CAST para poder usar LIKE sobre un entero.
  $where[] = "(CAST(t.id_ticket AS CHAR) LIKE :q OR t.area LIKE :q OR u.full_name LIKE :q)";
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
    u.full_name AS assigned_name,
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
  <link rel="stylesheet" href="./assets/css/tickets.css">
  <link rel="stylesheet" href="./assets/css/tickets.css?v=20260212_1">

  <!-- NUEVO: estilo moderno (scopeado) -->
</head>

<body>
  <div class="layout d-flex">

    <!-- SIDEBAR -->
    <?php include __DIR__ . '/partials/menu.php'; ?>

    <!-- MAIN -->
    <main class="main flex-grow-1 d-flex justify-content-center align-items-start">
      <div class="tickets-page" style="width:100%; max-width: 1100px;">

        <section class="panel card tickets-panel">

          <!-- Header -->
          <div class="tickets-head d-flex justify-content-between align-items-center gap-2">
            <h1 class="panel__title m-0">Tickets</h1>

            <div class="head-right d-flex align-items-center gap-2">

              <!-- Search -->
              <form class="search-wrap d-flex align-items-center gap-2 px-3" method="GET" action="tickets.php">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input class="search-input border-0 bg-transparent" name="q" type="search" placeholder="Buscar por ID, nombre o área..." value="<?= esc($q) ?>">
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
                <option <?= $stateUI==='' ? 'selected':''; ?>>Filter by state</option>
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
                  <th>Area</th>
                  <th>Priority</th>
                  <th>Status</th>
                  <th>Assigned to</th>
                  <th class="th-center">Action</th>
                </tr>
              </thead>

              <tbody>
                <?php if (!$tickets): ?>
                  <tr>
                    <td colspan="6" class="text-center py-4" style="color: rgba(0,0,0,.55); font-weight:800;">
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
                  
                    $ticketUrl = trim((string)($t['ticket_url'] ?? ''));
                    $evidence = trim((string)($t['attachment_path'] ?? ''));
?>
                    <tr>
                      <td class="th-center fw-bold"><?= esc($idTxt) ?></td>
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

                      
                      <td class="th-center">
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
                                    class="icon-action evidence-btn"
                                    data-bs-toggle="modal"
                                    data-bs-target="#evidenceModal"
                                    data-file="<?= esc($evidence) ?>"
                                    data-ticket="<?= esc($idTxt) ?>"
                                    title="Ver evidencia">
                              <i class="fa-solid fa-paperclip"></i>
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

  <!-- ===== Modal Evidencia ===== -->
  <div class="modal fade" id="evidenceModal" tabindex="-1" aria-labelledby="evidenceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="evidenceModalLabel">
            Evidencia del Ticket <span id="evTicketCode" class="fw-bold"></span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div id="evBody" class="text-center" style="min-height: 220px;"></div>
        </div>
        <div class="modal-footer">
          <a id="evOpenNew" class="btn btn-outline-secondary" href="#" target="_blank" rel="noopener">
            Abrir en nueva pestaña
          </a>
          <a id="evDownload" class="btn btn-primary" href="#" download>
            Descargar
          </a>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function(){
      const modalEl = document.getElementById('evidenceModal');
      const evBody = document.getElementById('evBody');
      const evTicketCode = document.getElementById('evTicketCode');
      const evOpenNew = document.getElementById('evOpenNew');
      const evDownload = document.getElementById('evDownload');

      function safePath(p){
        if(!p) return '';
        if(p.includes('..')) return '';
        return p.replaceAll('\\\\','/');
      }

      modalEl.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        const rawFile = btn?.getAttribute('data-file') || '';
        const ticket = btn?.getAttribute('data-ticket') || '';
        const file = safePath(rawFile);

        evTicketCode.textContent = ticket ? ('#' + ticket) : '';
        evBody.innerHTML = '';

        if(!file){
          evBody.innerHTML = '<div class="alert alert-warning mb-0">No se encontró la evidencia.</div>';
          evOpenNew.href = '#';
          evDownload.href = '#';
          return;
        }

        const url = file;
        evOpenNew.href = url;
        evDownload.href = url;

        const ext = (url.split('.').pop() || '').toLowerCase();

        if(['png','jpg','jpeg','webp','gif'].includes(ext)){
          const img = document.createElement('img');
          img.src = url;
          img.alt = 'Evidencia';
          img.style.maxWidth = '100%';
          img.style.height = 'auto';
          img.style.borderRadius = '12px';
          evBody.appendChild(img);
        } else if(ext === 'pdf'){
          const iframe = document.createElement('iframe');
          iframe.src = url;
          iframe.style.width = '100%';
          iframe.style.height = '70vh';
          iframe.style.border = '0';
          iframe.style.borderRadius = '12px';
          evBody.appendChild(iframe);
        } else {
          evBody.innerHTML = `
            <div class="alert alert-info">
              Vista previa no disponible para <b>.${ext || 'archivo'}</b>. Puedes abrirlo o descargarlo.
            </div>
          `;
        }
      });

      modalEl.addEventListener('hidden.bs.modal', function(){
        evBody.innerHTML = '';
        evTicketCode.textContent = '';
      });
    })();
  </script>


</body>
</html>