<?php
require __DIR__ . '/partials/auth.php';
$active = 'tickets';

require __DIR__ . '/config/db.php'; // Ajusta si tu db.php está en otra ruta
require_once __DIR__ . '/partials/helpers.php';
require_once __DIR__ . '/config/csrf.php';


require_once __DIR__ . '/config/TicketRepository.php';
$repo = new TicketRepository($pdo);

// ===============================
// Eliminar Ticket
// ===============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_ticket') {
  if (!csrf_validate()) {
    die("CSRF validation failed");
  }
  $delId = (int)($_POST['id_ticket'] ?? 0);
  $repo->deleteTicket($delId);
  header('Location: tickets.php?deleted=1');
  exit;
}

// ===============================
// Parámetros GET
// ===============================
$q          = trim($_GET['q'] ?? '');
$stateUI    = trim($_GET['state'] ?? '');
$priority   = trim($_GET['priority'] ?? '');
$assignedTo = trim($_GET['assigned'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = max(5, min(25, (int)($_GET['per_page'] ?? 5)));
$offset     = ($page - 1) * $perPage;

// Construir URL manteniendo querys
function build_url($overrides = []) {
  $base = $_GET;
  unset($base['created'], $base['updated'], $base['deleted']);
  foreach ($overrides as $k => $v) { $base[$k] = $v; }
  return 'tickets.php?' . http_build_query($base);
}

// ===============================
// SORT (Excel-like por encabezado)
// ===============================
$SORT_MAP = [
  'id'         => 't.id_ticket',
  'created'    => 't.created_at',
  'created_by' => 'created_by_name',
  'area'       => 't.area',
  'priority'   => 't.priority',
  'status'     => 't.status',
  'assigned'   => 'assigned_name',
];

$order = $_GET['order'] ?? '';
$order = is_string($order) ? $order : '';

$orderMap = [
  'id_desc'     => ['id', 'desc'],
  'id_asc'      => ['id', 'asc'],
  'date_desc'   => ['created', 'desc'],
  'date_asc'    => ['created', 'asc'],
  'area_asc'    => ['area', 'asc'],
  'area_desc'   => ['area', 'desc'],
  'creator_asc' => ['created_by', 'asc'],
  'creator_desc'=> ['created_by', 'desc'],
];

if ($order !== '' && isset($orderMap[$order])) {
  [$sort, $dirIn] = $orderMap[$order];
} else {
  $sort = $_GET['sort'] ?? 'id';
  $dirIn = strtolower($_GET['dir'] ?? 'desc');
}
if (!isset($SORT_MAP[$sort])) $sort = 'id';
$dir = ($dirIn === 'asc') ? 'ASC' : 'DESC';
$orderBySql = $SORT_MAP[$sort] . ' ' . $dir;

// Valores actuales para UI
$CURRENT_SORT = $sort;
$CURRENT_DIRIN = strtolower((string)$dirIn);

if ($order === '') {
  $key = $sort . '_' . strtolower($dirIn);
  $deduce = [
    'id_desc'         => 'id_desc',
    'id_asc'          => 'id_asc',
    'created_desc'    => 'date_desc',
    'created_asc'     => 'date_asc',
    'area_asc'        => 'area_asc',
    'area_desc'       => 'area_desc',
    'created_by_asc'  => 'creator_asc',
    'created_by_desc' => 'creator_desc',
  ];
  $order = $deduce[$key] ?? 'id_desc';
}

function sort_url(string $col): string {
  global $CURRENT_SORT, $CURRENT_DIRIN;
  $params = $_GET;
  unset($params['order'], $params['created'], $params['updated'], $params['deleted']);
  $currentSort = $CURRENT_SORT ?? ($params['sort'] ?? '');
  $currentDir  = strtolower($CURRENT_DIRIN ?? ($params['dir'] ?? 'desc'));
  $nextDir = ($currentSort === $col && $currentDir === 'asc') ? 'desc' : 'asc';
  $params['sort'] = $col;
  $params['dir']  = $nextDir;
  unset($params['page']);
  return '?' . http_build_query($params);
}

function sort_icon(string $col): string {
  global $CURRENT_SORT, $CURRENT_DIRIN;
  $currentSort = $CURRENT_SORT ?? ($_GET['sort'] ?? '');
  $currentDir  = strtolower($CURRENT_DIRIN ?? ($_GET['dir'] ?? 'desc'));

  if ($currentSort !== $col) return '<span class="sort-ico"><i class="fa-solid fa-sort text-muted" aria-hidden="true"></i></span>';
  if ($currentDir === 'asc') return '<span class="sort-ico"><i class="fa-solid fa-sort-up" aria-hidden="true"></i></span>';
  return '<span class="sort-ico"><i class="fa-solid fa-sort-down" aria-hidden="true"></i></span>';
}

// ===============================
// Filtros y Ejecución
// ===============================
$filters = [
  'q'        => $q,
  'status'   => $stateUI === 'Filter by status' ? '' : $stateUI,
  'priority' => $priority === 'Priority' ? '' : $priority,
  'assigned' => $assignedTo === 'Assigned To' ? '' : $assignedTo,
];

$total = $repo->countTickets($filters);
$totalPages = max(1, (int)ceil($total / $perPage));
$tickets = $repo->getTickets($filters, $perPage, $offset, $orderBySql);

// Traer usuarios asignables para el filtro (solo IT/Managers activos)
$itStmt = $pdo->query("
  SELECT id_user, full_name 
  FROM users 
  WHERE (AREA = 'IT Support' OR AREA = 'Managers') AND is_active = 1 
  ORDER BY full_name ASC
");
$itUsers = $itStmt->fetchAll(PDO::FETCH_ASSOC);

// Alert de actualización
$updated = isset($_GET['updated']) ? (int)$_GET['updated'] : 0;
$created = isset($_GET['created']) ? (int)$_GET['created'] : 0;
$deleted = isset($_GET['deleted']) ? (int)$_GET['deleted'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Tickets | RH&R Ticketing</title>
  <link rel="icon" type="image/png" href="./assets/img/isotopo.png" />

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

  <!-- Adaptive rows per page (runs before render to avoid flash) -->
  <script>
    (function () {
      // Altura del "chrome" del panel (header + filtros + thead + paginación + paddings)
      var PANEL_CHROME = 320;
      var ROW_H        = 54;
      // El panel tiene min/max definido en CSS: clamp(640px, 86vh, 900px)
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
    .th-sort{color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:6px;font-weight:800;}
    .th-sort:hover{text-decoration:underline;}
    th.th-center .th-sort{justify-content:center;width:100%;}
    .th-sort i{font-size:12px;opacity:.85;}
  </style>

  <link rel="stylesheet" href="./assets/css/rhr-toast.css">
  <script defer src="./assets/js/rhr-toast.js"></script>
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
              <?php if($order!==''): ?><input type="hidden" name="order" value="<?= esc($order) ?>"><?php endif; ?>
              <?php if($assignedTo!==''): ?><input type="hidden" name="assigned" value="<?= esc($assignedTo) ?>"><?php endif; ?>
</form>

              <button class="avatar-btn" type="button" title="Profile">
                <span class="avatar-dot"></span>
              </button>
            </div>
          </div>

          <!-- Alerts -->
          <?php if ($updated === 1): ?>
            <div data-rhr-toast="Ticket updated successfully." data-rhr-toast-type="success"></div>
          <?php endif; ?>

          <?php if ($created === 1): ?>
            <div data-rhr-toast="Ticket created successfully." data-rhr-toast-type="success"></div>
          <?php endif; ?>

          <?php if ($deleted === 1): ?>
            <div data-rhr-toast="Ticket deleted successfully." data-rhr-toast-type="error"></div>
          <?php endif; ?>

          <?php
            // ¿Hay algún filtro activo? (excluye "order" porque es orden, no filtro)
            $hasActiveFilters = ($q !== '' || $stateUI !== '' || $priority !== '' || $assignedTo !== '');
          ?>

          <!-- Filters -->
          <form class="filters row g-2 mt-3" method="GET" action="tickets.php">
            <input type="hidden" name="q" value="<?= esc($q) ?>">

            <!-- Status -->
            <div class="col-12 col-md">
              <select class="form-select filter-select" name="state" onchange="this.form.submit()">
                <option value="" <?= $stateUI==='' ? 'selected':''; ?> disabled hidden>Filter by status</option>
                <?php foreach (['Open','In progress','Resolved','Closed'] as $opt): ?>
                  <option value="<?= esc($opt) ?>" <?= $stateUI===$opt ? 'selected':''; ?>><?= esc($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Priority -->
            <div class="col-12 col-md">
              <select class="form-select filter-select" name="priority" onchange="this.form.submit()">
                <option value="" <?= $priority==='' ? 'selected':''; ?> disabled hidden>Priority</option>
                <?php foreach (['Baja','Media','Alta','Urgente'] as $opt): ?>
                  <option value="<?= esc($opt) ?>" <?= $priority===$opt ? 'selected':''; ?>><?= esc(ui_prio_label($opt)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Assigned To — solo admin (rol 1) y super admin (rol 2) -->
            <?php if (in_array($_AUTH_ROLE_ID, [1, 2])): ?>
            <div class="col-12 col-md">
              <select class="form-select filter-select" name="assigned" onchange="this.form.submit()">
                <option value="" <?= $assignedTo==='' ? 'selected':'' ?> disabled hidden>Assigned To</option>
                <?php foreach ($itUsers as $usr): ?>
                  <option value="<?= esc($usr['id_user']) ?>"
                    <?= ((string)$assignedTo === (string)$usr['id_user']) ? 'selected' : '' ?>>
                    <?= esc($usr['full_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>

            <!-- Sort order -->
            <div class="col-12 col-md">
              <select class="form-select filter-select" name="order" onchange="this.form.submit()">
                <option value="id_desc"     <?= $order==='id_desc'     ? 'selected':''; ?>>ID: highest to lowest</option>
                <option value="id_asc"      <?= $order==='id_asc'      ? 'selected':''; ?>>ID: lowest to highest</option>
                <option value="date_desc"   <?= $order==='date_desc'   ? 'selected':''; ?>>Date: newest first</option>
                <option value="date_asc"    <?= $order==='date_asc'    ? 'selected':''; ?>>Date: oldest first</option>
                <option value="area_asc"    <?= $order==='area_asc'    ? 'selected':''; ?>>Alphabetical (Area): A–Z</option>
                <option value="area_desc"   <?= $order==='area_desc'   ? 'selected':''; ?>>Alphabetical (Area): Z–A</option>
                <option value="creator_asc" <?= $order==='creator_asc' ? 'selected':''; ?>>Alphabetical (Creator): A–Z</option>
                <option value="creator_desc"<?= $order==='creator_desc'? 'selected':''; ?>>Alphabetical (Creator): Z–A</option>
              </select>
            </div>

            <!-- Acciones: Clear (condicional) + New Ticket -->
            <div class="col-12 col-md-auto d-flex justify-content-md-end align-items-center gap-2">
              <?php if ($hasActiveFilters): ?>
                <a class="btn-clear" href="tickets.php" title="Clear all filters">
                  <i class="fa-solid fa-xmark"></i>Clear filters
                </a>
              <?php endif; ?>
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
                  <th class="th-center"><a class="th-sort" href="<?= sort_url('id') ?>">ID <?= sort_icon('id') ?></a></th>
                  <th><a class="th-sort" href="<?= sort_url('created') ?>">Created <?= sort_icon('created') ?></a></th>
                  <th>Created By</th>
                  <th>Area</th>
                  <th><a class="th-sort" href="<?= sort_url('priority') ?>">Priority <?= sort_icon('priority') ?></a></th>
                  <th><a class="th-sort" href="<?= sort_url('status') ?>">Status <?= sort_icon('status') ?></a></th>
                  <th>Assigned To</th>
                  <th class="th-center th-actions">Action</th>
                </tr>
              </thead>

              <tbody>
                <?php if (!$tickets): ?>
                  <tr>
                    <td colspan="8" class="text-center py-4" style="color: rgba(0,0,0,.55); font-weight:800;">
                      No tickets to display.
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
                          <?= esc(ui_prio_label($prio)) ?>
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
                             title="Assign / Edit">
                            <i class="fa-regular fa-pen-to-square"></i>
                          </a>

                          <?php if ($ticketUrl !== ''): ?>
                            <a class="icon-action text-decoration-none"
                               href="<?= esc($ticketUrl) ?>"
                               target="_blank" rel="noopener"
                               title="Open URL">
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
                                    title="View Evidence">
                              <i class="fa-solid fa-paperclip"></i>
                            </button>
                          <?php endif; ?>
                          <button type="button"
                                    class="icon-action icon-delete delete-btn"
                                    data-bs-toggle="modal"
                                    data-bs-target="#deleteModal"
                                    data-id="<?= (int)$t['id_ticket'] ?>"
                                    data-ticket="<?= esc($idTxt) ?>"
                                    title="Delete Ticket">
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
            <span class="foot-text">Showing <?= (int)$from ?>–<?= (int)$to ?> of <?= (int)$total ?> tickets</span>

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
              Evidence: <span id="evFilename" class="fw-bold"></span>
            </h5>
            <div class="small text-muted">
              Ticket <span id="evTicketCode" class="fw-bold"></span>
            </div>
          </div>

          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div class="evidence-toolbar">
              <button type="button" class="btn btn-light ev-toolbtn" id="evZoomOut" title="Zoom -">
                <i class="fa-solid fa-magnifying-glass-minus"></i>
              </button>
              <button type="button" class="btn btn-light ev-toolbtn" id="evReset" title="Reset zoom">
                <i class="fa-solid fa-rotate-left"></i>
              </button>
              <button type="button" class="btn btn-light ev-toolbtn" id="evZoomIn" title="Zoom +">
                <i class="fa-solid fa-magnifying-glass-plus"></i>
              </button>
            </div>

            <div class="d-flex gap-2">
              <a id="evOpenNew" class="btn btn-outline-primary ev-open" href="#" target="_blank" rel="noopener" title="Open in new tab">
                <i class="fa-solid fa-up-right-from-square me-2"></i>Open
              </a>
              <a id="evDownload" class="btn btn-outline-secondary ev-open" href="#" download title="Download">
                <i class="fa-solid fa-download me-2"></i>Download
              </a>
            </div>
          </div>

          <div class="evidence-canvas" id="evCanvas">
            <!-- IMG/IFRAME dinámico -->
          </div>
          <div class="evidence-tip mt-2">
            Tip: Use the buttons to zoom in on images. For PDFs, you can use your browser's zoom function.
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
            <i class="fa-regular fa-trash-can me-2"></i>Delete ticket <span id="delTicketCode" class="fw-bold"></span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          Are you sure you want to delete this ticket? This action cannot be undone.
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>

          <form method="POST" class="m-0">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete_ticket">
            <input type="hidden" name="id_ticket" id="delTicketId" value="">
            <button type="submit" class="btn btn-danger">
              <i class="fa-regular fa-trash-can me-2"></i>Delete
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <script defer src="./assets/js/tickets-actions.js"></script>

<?php if ($updated || $created || $deleted): ?>
<script>
fetch('api/process_queue.php', {headers:{'X-Requested-With':'XMLHttpRequest'}}).catch(()=>{});
</script>
<?php endif; ?>

</body>
</html>