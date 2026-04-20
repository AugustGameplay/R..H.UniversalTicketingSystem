<?php
require __DIR__ . '/partials/auth.php';
$active = 'history';

require __DIR__ . '/config/db.php';
require_once __DIR__ . '/partials/helpers.php';

// =====================================================
// Fechas (GET) - inicio y fin
// =====================================================
$start = trim($_GET['start'] ?? '');
$end   = trim($_GET['end'] ?? '');

$today = (new DateTime('today'))->format('Y-m-d');
if ($start === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = $today;
if ($end === ''   || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = $start;

if ($start > $end) { $tmp = $start; $start = $end; $end = $tmp; }

$dtStart   = new DateTime($start);
$dtEnd     = new DateTime($end);
$dtEndNext = (clone $dtEnd)->modify('+1 day');

$sqlRange = "t.created_at >= :start AND t.created_at < :endNext";
$paramsRange = [
  ':start'   => $dtStart->format('Y-m-d 00:00:00'),
  ':endNext' => $dtEndNext->format('Y-m-d 00:00:00'),
];

// =====================================================
// Campos de auditoría (estáticos)
// =====================================================
$creatorIdField = 'id_user';
$creatorJoinSQL = "LEFT JOIN users cu ON cu.id_user = t.id_user";
$creatorNameExpr = "COALESCE(cu.full_name, '')";
$closedField = 'closed_at';
$modsTable = 'ticket_modifications';

// =====================================================
// AJAX: historial de modificaciones (modal)
// =====================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'mods'){
  header('Content-Type: application/json; charset=utf-8');
  $ticketId = isset($_GET['ticket_id']) && ctype_digit((string)$_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
  if (!$modsTable || $ticketId <= 0){
    echo json_encode(['ok'=>true, 'items'=>[]]);
    exit;
  }
  try {
    $stmt = $pdo->prepare("
      SELECT
        m.id, m.ticket_id, m.modified_at, m.field_name,
        m.old_value, m.new_value, m.action,
        COALESCE(u.full_name, '—') AS modified_by_name
      FROM {$modsTable} m
      LEFT JOIN users u ON u.id_user = m.modified_by
      WHERE m.ticket_id = :id
      ORDER BY m.modified_at DESC, m.id DESC
      LIMIT 200
    ");
    $stmt->execute([':id'=>$ticketId]);
    echo json_encode(['ok'=>true, 'items'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'items'=>[]]);
  }
  exit;
}

// =====================================================
// Filtros: vista (cards) + creador
// =====================================================
$view = strtolower(trim($_GET['view'] ?? 'total'));
$allowedViews = ['total','assigned','unassigned','inprogress','done','closed'];
if (!in_array($view, $allowedViews, true)) $view = 'total';

$creatorId = trim($_GET['creator'] ?? '');
$creatorId = ($creatorIdField && $creatorId !== '' && ctype_digit($creatorId)) ? (int)$creatorId : 0;

$cat = trim($_GET['cat'] ?? '');
$creatorFilterSQL = "";
$params = $paramsRange;
if ($creatorIdField && $creatorId > 0){
  $creatorFilterSQL = " AND t.$creatorIdField = :creatorId";
  $params[':creatorId'] = $creatorId;
}

// =====================================================
// Download CSV
// =====================================================
if (isset($_GET['download']) && $_GET['download'] == '1') {
  $extraWhere = "";
  if ($cat !== '') {
    $extraWhere .= " AND COALESCE(NULLIF(TRIM(t.category), ''), 'Sin categoría') = :cat";
    $params[':cat'] = $cat;
  }
  if ($view === 'assigned')   $extraWhere .= " AND t.assigned_user_id IS NOT NULL";
  if ($view === 'unassigned') $extraWhere .= " AND t.assigned_user_id IS NULL";
  if ($view === 'inprogress') $extraWhere .= " AND t.status = 'En Proceso'";
  if ($view === 'done')       $extraWhere .= " AND t.status = 'Resuelto'";
  if ($view === 'closed')     $extraWhere .= " AND (t.status = 'Cerrado' OR t.status = 'Closed')";

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="tickets_history_'.$start.'_to_'.$end.'_'.$view.'.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID','Created','Closed','Area','Priority','Status','Assigned to','Created by']);
  $closedSelect = $closedField ? "t.$closedField AS closed_at" : "NULL AS closed_at";
  $stmt = $pdo->prepare("
    SELECT t.id_ticket, t.created_at, $closedSelect, t.area, t.priority, t.status,
           COALESCE(u.full_name, '') AS assigned_name, $creatorNameExpr AS created_by_name
    FROM tickets t
    LEFT JOIN users u ON u.id_user = t.assigned_user_id
    $creatorJoinSQL
    WHERE $sqlRange $creatorFilterSQL $extraWhere
    ORDER BY t.id_ticket DESC
  ");
  $stmt->execute($params);
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
      $row['id_ticket'],
      $row['created_at'],
      $row['closed_at']?:'',
      $row['area'],
      getPriorityEn($row['priority']),
      getStatusEn($row['status']),
      $row['assigned_name'],
      $row['created_by_name']
    ]);
  }
  fclose($out);
  exit;
}

// =====================================================
// Métricas
// =====================================================
function count_where(PDO $pdo, string $where, array $params): int {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t WHERE $where");
  $stmt->execute($params);
  return (int)($stmt->fetchColumn() ?: 0);
}

$whereBase  = $sqlRange . $creatorFilterSQL;
$total      = count_where($pdo, $whereBase, $params);
$assigned   = count_where($pdo, "$whereBase AND t.assigned_user_id IS NOT NULL", $params);
$unassigned = count_where($pdo, "$whereBase AND t.assigned_user_id IS NULL", $params);
$inprogress = count_where($pdo, "$whereBase AND t.status = 'En Proceso'", $params);
$done       = count_where($pdo, "$whereBase AND t.status = 'Resuelto'", $params);
$closed     = count_where($pdo, "$whereBase AND (t.status = 'Cerrado' OR t.status = 'Closed')", $params);

// =====================================================
// Vista activa
// =====================================================
$extraWhere = "";
$viewLabel  = "Total";
if ($view === 'assigned')   { $extraWhere = " AND t.assigned_user_id IS NOT NULL"; $viewLabel = "Assigned"; }
if ($view === 'unassigned') { $extraWhere = " AND t.assigned_user_id IS NULL";     $viewLabel = "Unassigned"; }
if ($view === 'inprogress') { $extraWhere = " AND t.status = 'En Proceso'";        $viewLabel = "In progress"; }
if ($view === 'done')       { $extraWhere = " AND t.status = 'Resuelto'";          $viewLabel = "Done"; }
if ($view === 'closed')     { $extraWhere = " AND (t.status = 'Cerrado' OR t.status = 'Closed')"; $viewLabel = "Closed"; }

// FIX: aplicar filtro de categoría a la query principal
if ($cat !== '') {
  $extraWhere .= " AND COALESCE(NULLIF(TRIM(t.category), ''), 'Uncategorized') = :cat";
  $params[':cat'] = $cat;
}

// =====================================================
// Categorías + Alerta "Posible falla general"
// =====================================================
$categoryCounts = [];
try {
  $stmtCat = $pdo->prepare("
    SELECT COALESCE(NULLIF(TRIM(t.category), ''), 'Uncategorized') AS category, COUNT(*) AS total
    FROM tickets t
    WHERE $whereBase $extraWhere
    GROUP BY COALESCE(NULLIF(TRIM(t.category), ''), 'Uncategorized')
    ORDER BY total DESC, category ASC
  ");
  $stmtCat->execute($params);
  $categoryCounts = $stmtCat->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $categoryCounts = []; }

$ALERT_THRESHOLD  = 5;
$ALERT_MIN_USERS  = 3;
$alertsByCategory = [];
try {
  $stmtAlert = $pdo->prepare("
    SELECT
      DATE(t.created_at) AS dia,
      COALESCE(NULLIF(TRIM(t.category), ''), 'Uncategorized') AS category,
      COALESCE(NULLIF(TRIM(t.type), ''), 'No type selected') AS type,
      COUNT(*) AS total,
      COUNT(DISTINCT t.id_user) AS reportantes
    FROM tickets t
    WHERE $whereBase
    GROUP BY DATE(t.created_at),
             COALESCE(NULLIF(TRIM(t.category), ''), 'Uncategorized'),
             COALESCE(NULLIF(TRIM(t.type), ''), 'No type selected')
    HAVING COUNT(*) >= :thr AND COUNT(DISTINCT t.id_user) >= :minu
    ORDER BY total DESC, reportantes DESC
  ");
  $stmtAlert->execute($params + [':thr'=>$ALERT_THRESHOLD, ':minu'=>$ALERT_MIN_USERS]);
  while ($r = $stmtAlert->fetch(PDO::FETCH_ASSOC)){
    $ck = $r['category'] ?? 'Uncategorized';
    if (!isset($alertsByCategory[$ck])) $alertsByCategory[$ck] = $r;
  }
} catch (Throwable $e) { $alertsByCategory = []; }

// =====================================================
// Filas de tabla
// =====================================================
$rows = [];
try {
  $closedSelect = $closedField ? "t.$closedField AS closed_at" : "NULL AS closed_at";
  $stmt = $pdo->prepare("
    SELECT t.id_ticket, t.created_at, $closedSelect, t.area, t.priority, t.status,
           COALESCE(u.full_name, '—') AS assigned_name,
           $creatorNameExpr AS created_by_name
    FROM tickets t
    LEFT JOIN users u ON u.id_user = t.assigned_user_id
    $creatorJoinSQL
    WHERE $sqlRange $creatorFilterSQL $extraWhere
    ORDER BY t.id_ticket DESC
    LIMIT 250
  ");
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $rows = []; }

// Usuarios para filtro
$usersList = [];
$selectedCreatorName = '';
if ($creatorIdField){
  try {
    $uStmt = $pdo->query("SELECT id_user, full_name FROM users ORDER BY full_name ASC");
    $usersList = $uStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($creatorId > 0){
      foreach ($usersList as $uu){
        if ((int)$uu['id_user'] === (int)$creatorId){ $selectedCreatorName = (string)$uu['full_name']; break; }
      }
    }
  } catch (Throwable $e) { $usersList = []; }
}

$startText = (new DateTime($start))->format('m/d/Y');
$endText   = (new DateTime($end))->format('m/d/Y');

function build_qs(array $pairs): string {
  $pairs = array_filter($pairs, fn($v) => $v !== null && $v !== '' && $v !== 0);
  return http_build_query($pairs);
}

$qsBase = build_qs(['start'=>$start, 'end'=>$end, 'creator'=>$creatorId, 'cat'=>$cat]);

$fieldLabels = [
  'status'=>'Status','priority'=>'Priority','area'=>'Area',
  'assigned_user_id'=>'Assigned to','assigned_to'=>'Assigned to',
  'ticket_url'=>'URL','url'=>'URL','evidence'=>'Evidence',
  'evidence_path'=>'Evidence','attachment'=>'Attachment','notes'=>'Notes',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>History | RH&R Ticketing</title>
  <link rel="icon" type="image/png" href="./assets/img/isotopo.png" />

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <link rel="stylesheet" href="./assets/css/generarTickets.css">
  <link rel="stylesheet" href="./assets/css/menu.css">
  <link rel="stylesheet" href="./assets/css/movil.css">
  <script defer src="./assets/js/sidebar.js"></script>

  <link rel="stylesheet" href="./assets/css/history.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
</head>

<body>
  <div class="layout d-flex">
    <?php include __DIR__ . '/partials/menu.php'; ?>

    <main class="main flex-grow-1 d-flex justify-content-center align-items-start">
      <section class="panel card history-panel">
        <div class="history-shell">

          <!-- ══════════════ IZQUIERDA ══════════════ -->
          <div class="history-left">

            <div class="history-left__header">
              <div class="history-h1">History</div>
              <div class="history-sub">View and download tickets by date/status.</div>
            </div>

            <!-- FECHAS -->
            <div class="history-dates">
              <div class="history-date" id="pillStart" role="button" aria-label="Fecha inicio">
                <i class="fa-solid fa-calendar-days"></i>
                <span class="pill-label">Start</span>
                <input id="startDate" class="date-pill-input" type="text" value="<?= esc($startText) ?>" readonly />
              </div>
              <div class="history-date" id="pillEnd" role="button" aria-label="Fecha fin">
                <i class="fa-solid fa-calendar-days"></i>
                <span class="pill-label">End</span>
                <input id="endDate" class="date-pill-input" type="text" value="<?= esc($endText) ?>" readonly />
              </div>
            </div>

            <!-- MÉTRICAS -->
            <div class="history-grid">
              <a class="stat-card stat-total <?= $view==='total'?'is-active':'' ?>"
                 href="history.php?<?= esc($qsBase) ?>&view=total">
                <div class="stat-num"><?= (int)$total ?></div>
                <div class="stat-label">Total</div>
              </a>
              <a class="stat-card stat-assigned <?= $view==='assigned'?'is-active':'' ?>"
                 href="history.php?<?= esc($qsBase) ?>&view=assigned">
                <div class="stat-num"><?= (int)$assigned ?></div>
                <div class="stat-label">Assigned</div>
              </a>
              <a class="stat-card stat-unassigned <?= $view==='unassigned'?'is-active':'' ?>"
                 href="history.php?<?= esc($qsBase) ?>&view=unassigned">
                <div class="stat-num"><?= (int)$unassigned ?></div>
                <div class="stat-label">Unassigned</div>
              </a>
              <a class="stat-card stat-inprogress <?= $view==='inprogress'?'is-active':'' ?>"
                 href="history.php?<?= esc($qsBase) ?>&view=inprogress">
                <div class="stat-num"><?= (int)$inprogress ?></div>
                <div class="stat-label">In progress</div>
              </a>
              <a class="stat-card stat-done <?= $view==='done'?'is-active':'' ?>"
                 href="history.php?<?= esc($qsBase) ?>&view=done">
                <div class="stat-num"><?= (int)$done ?></div>
                <div class="stat-label">Done</div>
              </a>
              <a class="stat-card stat-closed <?= $view==='closed'?'is-active':'' ?>"
                 href="history.php?<?= esc($qsBase) ?>&view=closed">
                <div class="stat-num"><?= (int)$closed ?></div>
                <div class="stat-label">Closed</div>
              </a>
            </div>
            <!-- ↑ FIX CLAVE: history-grid se cierra AQUÍ -->

            <!-- CATEGORÍAS — ahora fuera del history-grid -->
            <?php if (!empty($categoryCounts)): ?>
              <div class="history-cat-section">
                <div class="history-cat-head">
                  <div class="history-cat-title">Categories</div>
                  <div class="history-cat-sub">Count by category (same current filter)</div>
                </div>
                <div class="history-cat-grid">
                  <?php foreach ($categoryCounts as $cc):
                    $catName  = $cc['category'] ?? 'Uncategorized';
                    $catTotal = (int)($cc['total'] ?? 0);
                    $alert    = $alertsByCategory[$catName] ?? null;
                    $hasAlert = !empty($alert);
                    $isActive = ($cat === $catName);
                    // Link: si ya está activa, quita el filtro al picar; si no, actívala
                    $catQS = $isActive
                      ? build_qs(['start'=>$start,'end'=>$end,'creator'=>$creatorId,'view'=>$view])
                      : build_qs(['start'=>$start,'end'=>$end,'creator'=>$creatorId,'view'=>$view,'cat'=>$catName]);
                  ?>
                    <a href="history.php?<?= esc($catQS) ?>"
                       class="stat-card stat-total stat-cat <?= $hasAlert ? 'stat-cat--warn' : '' ?> <?= $isActive ? 'is-active' : '' ?>"
                       role="button" aria-label="<?= esc($catName) ?>" title="<?= $isActive ? 'Clear filter' : 'Filter by '.esc($catName) ?>">
                      <div class="stat-num"><?= $catTotal ?></div>
                      <div class="stat-label"><?= esc($catName) ?></div>
                      <?php if ($hasAlert): ?>
                        <div class="cat-warn">
                          <i class="fa-solid fa-triangle-exclamation"></i>
                          <span>
                            Possible general issue: <b><?= esc(getTypeEn($alert['type'] ?? '')) ?></b>
                            <span class="muted">(<?= (int)($alert['total'] ?? 0) ?>)</span>
                            <span class="muted"><?= esc($alert['dia'] ?? '') ?></span>
                          </span>
                        </div>
                      <?php endif; ?>
                    </a>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

          </div><!-- /history-left -->

          <!-- ══════════════ DERECHA ══════════════ -->
          <div class="history-right">
            <div class="history-right__top">
              <div class="history-right__titles">
                <div class="history-right__title">
                  Tickets <span class="chip-view"><?= esc($viewLabel) ?></span>
                  <?php if ($cat !== ''): ?>
                    <span class="chip-cat">
                      <i class="fa-solid fa-tag"></i> <?= esc($cat) ?>
                      <a class="chip-cat__clear" href="history.php?<?= esc(build_qs(['start'=>$start,'end'=>$end,'creator'=>$creatorId,'view'=>$view])) ?>" title="Clear category filter">×</a>
                    </span>
                  <?php endif; ?>
                </div>
                <div class="history-right__meta">
                  <?= esc("From $startText to $endText") ?>
                  <?php if ($creatorIdField && $creatorId > 0 && $selectedCreatorName): ?>
                    <span class="chip-creator">Created by: <?= esc($selectedCreatorName) ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="history-right__controls">
                <?php if ($creatorIdField): ?>
                  <form class="history-user-filter" method="get" action="history.php">
                    <input type="hidden" name="start"   value="<?= esc($start) ?>">
                    <input type="hidden" name="end"     value="<?= esc($end) ?>">
                    <input type="hidden" name="view"    value="<?= esc($view) ?>">
                    <select class="form-select" name="creator" onchange="this.form.submit()">
                      <option value="">All creators</option>
                      <?php foreach ($usersList as $uu): ?>
                        <option value="<?= (int)$uu['id_user'] ?>"
                          <?= ((int)$uu['id_user']===(int)$creatorId)?'selected':'' ?>>
                          <?= esc($uu['full_name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                <?php endif; ?>
              </div>
            </div>

            <div class="history-table-wrap">
              <table class="history-table">
                <thead>
                  <tr>
                    <th style="width:90px;">ID</th>
                    <th style="width:170px;">Create</th>
                    <th style="width:170px;">Closed</th>
                    <th>Area</th>
                    <th style="width:120px;">Priority</th>
                    <th style="width:140px;">Status</th>
                    <th style="width:190px;">Assigned to</th>
                    <th style="width:220px;">Created for</th>
                    <th style="width:120px; text-align:right;">Mods</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$rows): ?>
                    <tr><td colspan="9" class="empty-row">There are no tickets for this filter.</td></tr>
                  <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                      <?php
                        $createdTxt = '—'; $closedTxt = '—';
                        try { $createdTxt = (new DateTime($r['created_at']))->format('m/d/Y H:i'); } catch (Throwable $e) {}
                        if (!empty($r['closed_at'])){
                          try { $closedTxt = (new DateTime($r['closed_at']))->format('m/d/Y H:i'); }
                          catch (Throwable $e) { $closedTxt = esc((string)$r['closed_at']); }
                        }
                      ?>
                      <tr>
                        <td class="td-id"><span><?= (int)$r['id_ticket'] ?></span></td>
                        <td><?= esc($createdTxt) ?></td>
                        <td><?= esc($closedTxt) ?></td>
                        <td class="td-ellipsis" title="<?= esc($r['area']) ?>"><?= esc($r['area']) ?></td>
                        <td class="td-priority"><?php
                          $pri = esc($r['priority']);
                          $priEn = getPriorityEn($r['priority']);
                          $priClass = match($r['priority']) {
                            'Urgente' => 'badge-urgente',
                            'Alta'    => 'badge-alta',
                            'Media'   => 'badge-media',
                            'Baja'    => 'badge-baja',
                            default   => 'badge-media',
                          };
                        ?><span class="<?= $priClass ?>"><?= esc($priEn) ?></span></td>
                        <td class="td-status"><?php
                          $st = esc($r['status']);
                          $stEn = getStatusEn($r['status']);
                          $stClass = match(strtolower((string)$r['status'])) {
                            'cerrado', 'closed'  => 'badge-cerrado',
                            'resuelto', 'done'   => 'badge-resuelto',
                            'en proceso'         => 'badge-proceso',
                            'pendiente'          => 'badge-pendiente',
                            default              => 'badge-pendiente',
                          };
                        ?><span class="<?= $stClass ?>"><?= esc($stEn) ?></span></td>
                        <td class="td-ellipsis" title="<?= esc($r['assigned_name']) ?>"><?= esc($r['assigned_name']) ?></td>
                        <td class="td-ellipsis" title="<?= esc($r['created_by_name']?:'—') ?>"><?= esc($r['created_by_name']?:'—') ?></td>
                        <td class="td-actions">
                          <button type="button" class="btn-mods"
                                  data-ticket-id="<?= (int)$r['id_ticket'] ?>"
                                  <?= $modsTable ? '' : 'disabled' ?>
                                  title="View modification history">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                          </button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <div class="history-footer">
              <a class="btn-download d-inline-flex align-items-center justify-content-center text-decoration-none"
                 href="history.php?<?= esc($qsBase) ?>&view=<?= esc($view) ?>&download=1">
                <i class="fa-solid fa-download me-2"></i> Download
              </a>
            </div>
          </div><!-- /history-right -->

        </div><!-- /history-shell -->
      </section>
    </main>
  </div>

  <!-- Modal Mods -->
  <div class="modal fade" id="modsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content mods-modal">
        <div class="modal-header">
          <div>
            <h5 class="modal-title" id="modsTitle">Modification history</h5>
            <div class="mods-sub" id="modsSub">—</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="modsBody" class="mods-body">
            <div class="mods-loading">Loading…</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

  <script>
  (function(){
    // ── FECHAS ──
    const startEl   = document.getElementById('startDate');
    const endEl     = document.getElementById('endDate');
    const pillStart = document.getElementById('pillStart');
    const pillEnd   = document.getElementById('pillEnd');

    const view    = "<?= esc($view) ?>";
    const creator = "<?= esc((string)($creatorId ?: '')) ?>";
    let startISO  = "<?= esc($start) ?>";
    let endISO    = "<?= esc($end) ?>";

    const toISO = d => {
      const yyyy = d.getFullYear();
      const mm   = String(d.getMonth()+1).padStart(2,'0');
      const dd   = String(d.getDate()).padStart(2,'0');
      return `${yyyy}-${mm}-${dd}`;
    };

    const go = () => {
      if (!startISO || !endISO) return;
      const p = new URLSearchParams();
      p.set('start', startISO); p.set('end', endISO);
      if (view)    p.set('view', view);
      if (creator) p.set('creator', creator);
      window.location.href = `history.php?${p.toString()}`;
    };

    const fpStart = flatpickr(startEl, {
      dateFormat: "m/d/Y", defaultDate: startEl.value,
      allowInput: false, locale: { firstDayOfWeek: 1 },
      onChange: sel => {
        if (!sel?.[0]) return;
        startISO = toISO(sel[0]);
        if (startISO > endISO) [startISO, endISO] = [endISO, startISO];
        setTimeout(go, 150);
      }
    });

    const fpEnd = flatpickr(endEl, {
      dateFormat: "m/d/Y", defaultDate: endEl.value,
      allowInput: false, locale: { firstDayOfWeek: 1 },
      onChange: sel => {
        if (!sel?.[0]) return;
        endISO = toISO(sel[0]);
        if (startISO > endISO) [startISO, endISO] = [endISO, startISO];
        setTimeout(go, 150);
      }
    });

    pillStart.addEventListener('click', () => fpStart.open());
    pillEnd.addEventListener('click',   () => fpEnd.open());

    // ── MODAL MODS ──
    const modalEl = document.getElementById('modsModal');
    const modal   = modalEl ? new bootstrap.Modal(modalEl) : null;
    const titleEl = document.getElementById('modsTitle');
    const subEl   = document.getElementById('modsSub');
    const bodyEl  = document.getElementById('modsBody');
    const FL      = <?= json_encode($fieldLabels, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

    const fmtDT = s => {
      if (!s) return '—';
      const p = String(s).replace('T',' ').split(' ');
      const d = (p[0]||'').split('-');
      return d.length !== 3 ? s : `${d[1]}/${d[2]}/${d[0]} ${(p[1]||'').slice(0,5)}`;
    };

    const e = s => String(s??'').replace(/[&<>'"]/g, c =>
      ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]||c));

    const translateVal = (field, val) => {
      if(!val) return val;
      const f = (field||'').toLowerCase();
      const v = String(val).trim().toLowerCase();
      if(f === 'status') {
        if(v === 'cerrado' || v === 'closed') return 'Closed';
        if(v === 'resuelto' || v === 'done') return 'Resolved';
        if(v === 'en proceso') return 'In Progress';
        if(v === 'pendiente') return 'Pending';
      }
      if(f === 'priority') {
        if(v === 'urgente') return 'Urgent';
        if(v === 'alta') return 'High';
        if(v === 'media') return 'Medium';
        if(v === 'baja') return 'Low';
        if(v === 'alta/media') return 'High/Medium';
      }
      if(f === 'type') {
        if(v === 'falla') return 'Fault';
        if(v === 'solicitud') return 'Request';
      }
      return val;
    };

    const renderMods = items => {
      if (!items?.length){
        bodyEl.innerHTML = `<div class="mods-empty">There are no modifications recorded for this ticket yet.</div>`;
        return;
      }
      bodyEl.innerHTML = `<div class="mods-list">${items.map(it => `
        <div class="mod-item">
          <div class="mod-head">
            <div class="mod-when"><i class="fa-regular fa-clock"></i> ${fmtDT(it.modified_at)}</div>
            <div class="mod-who"><i class="fa-regular fa-user"></i> ${e(it.modified_by_name||'—')}</div>
          </div>
          <div class="mod-body">
            <div class="mod-field">${e(FL[(it.field_name||'').toLowerCase()]||it.field_name||'Field')}</div>
            <div class="mod-diff">
              <span class="mod-old">${e(translateVal(it.field_name, it.old_value??'—'))}</span>
              <span class="mod-arrow">→</span>
              <span class="mod-new">${e(translateVal(it.field_name, it.new_value??'—'))}</span>
            </div>
          </div>
        </div>`).join('')}</div>`;
    };

    document.querySelectorAll('.btn-mods').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!modal) return;
        const tid = btn.getAttribute('data-ticket-id');
        if (!tid) return;
        titleEl.textContent = 'Modification history';
        subEl.textContent   = `Ticket #${tid}`;
        bodyEl.innerHTML    = `<div class="mods-loading">Loading…</div>`;
        modal.show();
        try {
          const res  = await fetch(`history.php?ajax=mods&ticket_id=${encodeURIComponent(tid)}`);
          const data = await res.json();
          data?.ok ? renderMods(data.items)
                   : (bodyEl.innerHTML = `<div class="mods-empty">The history could not be loaded.</div>`);
        } catch {
          bodyEl.innerHTML = `<div class="mods-empty">The history could not be loaded.</div>`;
        }
      });
    });
  })();
  </script>
</body>
</html>
