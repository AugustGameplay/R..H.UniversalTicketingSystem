<?php
$active = 'tickets';
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

  <!-- Base -->
  <link rel="stylesheet" href="./assets/css/generarTickets.css">
  <link rel="stylesheet" href="./assets/css/menu.css">
  <link rel="stylesheet" href="./assets/css/movil.css">
  <script defer src="./assets/js/sidebar.js"></script>
  <!-- Tickets -->
  <link rel="stylesheet" href="./assets/css/tickets.css">
</head>

<body>

  <div class="layout d-flex">

    <!-- SIDEBAR reutilizable -->
    <?php include __DIR__ . '/partials/menu.php'; ?>

    <!-- MAIN -->
    <main class="main flex-grow-1 d-flex justify-content-center align-items-start">
      <section class="panel card tickets-panel">

        <!-- Header del panel -->
        <div class="tickets-head">
          <h1 class="panel__title m-0">Tickets</h1>

          <div class="head-right d-flex align-items-center gap-2">
            <!-- búsqueda -->
            <div class="search-wrap">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input class="search-input" type="search" placeholder="Search ticket...">
            </div>

            <!-- avatar -->
            <button class="avatar-btn" type="button" title="Perfil">
              <span class="avatar-dot"></span>
            </button>
          </div>
        </div>

        <!-- Filtros -->
        <div class="filters row g-2 mt-3">
          <div class="col-12 col-md-4">
            <select class="form-select filter-select">
              <option selected>Filter by state</option>
              <option>Abierto</option>
              <option>En proceso</option>
              <option>En espera</option>
              <option>Resuelto</option>
              <option>Cancelado</option>
            </select>
          </div>
          <div class="col-12 col-md-4">
            <select class="form-select filter-select">
              <option selected>Priority</option>
              <option>Baja</option>
              <option>Media</option>
              <option>Alta</option>
              <option>Urgente</option>
            </select>
          </div>
          <div class="col-12 col-md-4 d-flex justify-content-md-end">
            <button class="btn-pro" type="button">
              <i class="fa-solid fa-plus me-2"></i>New Ticket
            </button>
          </div>
        </div>

        <!-- Tabla -->
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
              <tr>
                <td class="th-center fw-bold">001</td>
                <td>IT Support</td>
                <td><span class="badge badge-prio prio-medium">Media</span></td>
                <td><span class="badge badge-status st-open">Abierto</span></td>
                <td>—</td>
                <td class="th-center">
                  <button class="icon-action" type="button" title="Editar">
                    <i class="fa-regular fa-pen-to-square"></i>
                  </button>
                </td>
              </tr>

              <tr>
                <td class="th-center fw-bold">002</td>
                <td>Infraestructura</td>
                <td><span class="badge badge-prio prio-high">Alta</span></td>
                <td><span class="badge badge-status st-progress">En proceso</span></td>
                <td>Juan Pérez</td>
                <td class="th-center">
                  <button class="icon-action" type="button" title="Editar">
                    <i class="fa-regular fa-pen-to-square"></i>
                  </button>
                </td>
              </tr>

              <tr>
                <td class="th-center fw-bold">003</td>
                <td>Software</td>
                <td><span class="badge badge-prio prio-low">Baja</span></td>
                <td><span class="badge badge-status st-wait">En espera</span></td>
                <td>—</td>
                <td class="th-center">
                  <button class="icon-action" type="button" title="Editar">
                    <i class="fa-regular fa-pen-to-square"></i>
                  </button>
                </td>
              </tr>

              <tr>
                <td class="th-center fw-bold">004</td>
                <td>Red</td>
                <td><span class="badge badge-prio prio-urgent">Urgente</span></td>
                <td><span class="badge badge-status st-done">Resuelto</span></td>
                <td>María López</td>
                <td class="th-center">
                  <button class="icon-action" type="button" title="Editar">
                    <i class="fa-regular fa-pen-to-square"></i>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Footer tabla -->
        <div class="table-foot d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 mt-3">
          <span class="foot-text">Mostrando 1–4 de 4 tickets</span>

          <nav aria-label="Paginación">
            <ul class="pagination pagination-sm mb-0">
              <li class="page-item disabled"><a class="page-link" href="#">Back</a></li>
              <li class="page-item active"><a class="page-link" href="#">1</a></li>
              <li class="page-item"><a class="page-link" href="#">2</a></li>
              <li class="page-item"><a class="page-link" href="#">Next</a></li>
            </ul>
          </nav>
        </div>

      </section>
    </main>

  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>