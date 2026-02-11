<?php
$active = 'history';
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

  <link rel="stylesheet" href="./assets/css/history-range.css">
  <!-- History -->
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
        <div class="history-date">
          <i class="fa-solid fa-calendar-days"></i>
          <input
            id="dateRange"
            class="date-range-input"
            type="text"
            placeholder="Selecciona un rango"
            readonly
          />
        </div>

        <!-- Métricas -->
        <div class="history-grid">

          <div class="stat-card stat-total">
            <div class="stat-num">0</div>
            <div class="stat-label">total tickets</div>
          </div>

          <div class="stat-card stat-assigned">
            <div class="stat-num">0</div>
            <div class="stat-label">Assigned</div>
          </div>

          <div class="stat-card stat-unassigned">
            <div class="stat-num">0</div>
            <div class="stat-label">Unassigned</div>
          </div>

          <div class="stat-card stat-inprogress">
            <div class="stat-num">0</div>
            <div class="stat-label">Inprogress</div>
          </div>

          <div class="stat-card stat-done">
            <div class="stat-num">0</div>
            <div class="stat-label">Done</div>
          </div>

        </div>

        <!-- Download -->
        <div class="history-footer">
          <button class="btn-download" type="button">
            Download
          </button>
        </div>

      </section>
    </main>

  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="./assets/js/history-range.js"></script>

</body>
</html>
