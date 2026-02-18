<?php
require __DIR__ . '/partials/auth.php';
$active = 'history';

require __DIR__ . '/config/db.php'; // ajusta ruta si cambia

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ===============================
// Rango de fechas (GET)
// ===============================
$start = trim($_GET['start'] ?? '');
$end   = trim($_GET['end'] ?? '');

// Defaults: hoy
$today = (new DateTime('today'))->format('Y-m-d');
if ($start === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = $today;
if ($end === ''   || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = $start;

// Normaliza si vienen invertidas
if ($start > $end) { $tmp = $start; $start = $end; $end = $tmp; }

// Rangos para SQL (end inclusive -> endNext exclusive)
$dtStart = new DateTime($start);
$dtEnd   = new DateTime($end);
$dtEndNext = (clone $dtEnd)->modify('+1 day');

$sqlRange = "t.created_at >= :start AND t.created_at < :endNext";
$paramsRange = [
  ':start'   => $dtStart->format('Y-m-d 00:00:00'),
  ':endNext' => $dtEndNext->format('Y-m-d 00:00:00'),
];

// ===============================
// Download CSV
// ===============================
if (isset($_GET['download']) && $_GET['download'] == '1') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="tickets_history_'.$start.'_to_'.$end.'.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID', 'Fecha', 'Área', 'Prioridad', 'Estatus', 'Asignado a']);

  $stmt = $pdo->prepare("
    SELECT
      t.id_ticket,
      t.created_at,
      t.area,
      t.priority,
      t.status,
      COALESCE(u.full_name, '') AS assigned_name
    FROM tickets t
    LEFT JOIN users u ON u.id_user = t.assigned_user_id
    WHERE $sqlRange
    ORDER BY t.id_ticket DESC
  ");
  $stmt->execute($paramsRange);

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
      $row['id_ticket'],
      $row['created_at'],
      $row['area'],
      $row['priority'],
      $row['status'],
      $row['assigned_name'],
    ]);
  }
  fclose($out);
  exit;
}

// ===============================
// Métricas
// ===============================
function count_where(PDO $pdo, string $where, array $params): int {
  $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM tickets t WHERE $where");
  $stmt->execute($params);
  return (int)($stmt->fetchColumn() ?: 0);
}

$total      = count_where($pdo, $sqlRange, $paramsRange);
$assigned   = count_where($pdo, "$sqlRange AND t.assigned_user_id IS NOT NULL", $paramsRange);
$unassigned = count_where($pdo, "$sqlRange AND t.assigned_user_id IS NULL", $paramsRange);
$inprogress = count_where($pdo, "$sqlRange AND t.status = 'En Proceso'", $paramsRange);
$done       = count_where($pdo, "$sqlRange AND t.status = 'Resuelto'", $paramsRange);

// Texto para input (DD/MM/YYYY)
$inputText = (new DateTime($start))->format('d/m/Y') . ' - ' . (new DateTime($end))->format('d/m/Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>History | RH&R Ticketing</title>

  <!-- Bootstrap + FontAwesome -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <!-- Base -->
  <link rel="stylesheet" href="./assets/css/generarTickets.css">
  <link rel="stylesheet" href="./assets/css/menu.css">
  <link rel="stylesheet" href="./assets/css/movil.css">
  <script defer src="./assets/js/sidebar.js"></script>

  <!-- History -->
  <link rel="stylesheet" href="./assets/css/history-range.css">
  <link rel="stylesheet" href="./assets/css/history.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
</head>

<body>

  <div class="layout d-flex">

    <!-- SIDEBAR reutilizable -->
    <?php include __DIR__ . '/partials/menu.php'; ?>

    <!-- MAIN -->
    <main class="main flex-grow-1 d-flex justify-content-center align-items-start">
      <section class="panel card history-panel">

        <!-- Rango de fechas -->
        <div class="history-date" id="datePill" role="button" aria-label="Seleccionar rango de fechas">
          <i class="fa-solid fa-calendar-days"></i>
          <input
            id="dateRange"
            class="date-range-input"
            type="text"
            value="<?= esc($inputText) ?>"
            placeholder="Selecciona un rango"
            readonly
            readonly
          />
          <span class="range-caption" id="rangeCaption">Del <?= esc((new DateTime($start))->format('d/m/Y')) ?> al <?= esc((new DateTime($end))->format('d/m/Y')) ?></span>

        </div>

        <!-- Métricas -->
        <div class="history-grid">

          <div class="stat-card stat-total">
            <div class="stat-num" id="mTotal"><?= (int)$total ?></div>
            <div class="stat-label">Total</div>
          </div>

          <div class="stat-card stat-assigned">
            <div class="stat-num" id="mAssigned"><?= (int)$assigned ?></div>
            <div class="stat-label">Assigned</div>
          </div>

          <div class="stat-card stat-unassigned">
            <div class="stat-num" id="mUnassigned"><?= (int)$unassigned ?></div>
            <div class="stat-label">Unassigned</div>
          </div>

          <div class="stat-card stat-inprogress">
            <div class="stat-num" id="mInprogress"><?= (int)$inprogress ?></div>
            <div class="stat-label">In progress</div>
          </div>

          <div class="stat-card stat-done">
            <div class="stat-num" id="mDone"><?= (int)$done ?></div>
            <div class="stat-label">Done</div>
          </div>

        </div>

        <!-- Download -->
        <div class="history-footer">
          <a class="btn-download d-inline-flex align-items-center justify-content-center text-decoration-none"
             href="history.php?start=<?= esc($start) ?>&end=<?= esc($end) ?>&download=1">
            <i class="fa-solid fa-download me-2"></i> Download
          </a>
        </div>

      </section>
    </main>

  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

  <!-- History range (funcional) -->
  <script>
    (function(){
      const input = document.getElementById('dateRange');
      const pill  = document.getElementById('datePill');

      // valores iniciales desde PHP
      const startISO = "<?= esc($start) ?>";
      const endISO   = "<?= esc($end) ?>";

      const fp = flatpickr(input, {
        mode: "range",
        dateFormat: "Y-m-d",      // valor real
        altInput: true,
        altFormat: "d/m/Y",       // lo que ve el usuario
        defaultDate: [startISO, endISO],
        allowInput: false,
        clickOpens: true,
        locale: { firstDayOfWeek: 1 }, // lunes
        onChange: function(selectedDates, dateStr, instance){
          // Actualiza texto "Del ... al ..." mientras selecciona
          const cap = document.getElementById('rangeCaption');
          if (!cap) return;

          const fmt = (d) => {
            const dd = String(d.getDate()).padStart(2,'0');
            const mm = String(d.getMonth()+1).padStart(2,'0');
            const yy = d.getFullYear();
            return `${dd}/${mm}/${yy}`;
          };

          if (!selectedDates || selectedDates.length === 0){
            cap.textContent = 'Selecciona un rango';
            return;
          }

          const s = selectedDates[0];
          const e = selectedDates[1] || selectedDates[0];
          cap.textContent = `Del ${fmt(s)} al ${fmt(e)}`;

          // Solo recarga cuando ya hay 2 fechas (rango completo)
          if (selectedDates.length >= 2){
            const toISO = (d) => {
              const yyyy = d.getFullYear();
              const mm = String(d.getMonth()+1).padStart(2,'0');
              const dd = String(d.getDate()).padStart(2,'0');
              return `${yyyy}-${mm}-${dd}`;
            };
            const newStart = toISO(s);
            const newEnd   = toISO(e);

            // pequeño delay para que el usuario alcance a ver el rango
            setTimeout(() => {
              window.location.href = `history.php?start=${encodeURIComponent(newStart)}&end=${encodeURIComponent(newEnd)}`;
            }, 150);
          }
        }
      });

      // Toda la pastilla abre el calendario
      pill.addEventListener('click', () => fp.open());
    })();
  </script>

</body>
</html>