<?php
$active = 'generarTickets'; // <-- para que "Generar Ticket" quede activo
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard | RH&R Ticketing</title>

  <!-- Bootstrap + FontAwesome -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <!-- Tu CSS -->
  <link rel="stylesheet" href="./assets/css/generarTickets.css">
  <link rel="stylesheet" href="./assets/css/menu.css">
  <link rel="stylesheet" href="./assets/css/movil.css">
  <script defer src="./assets/js/sidebar.js"></script>


  <script src="./assets/js/selects.js"></script>
</head>

<body>

  <!-- LAYOUT -->
  <div class="layout d-flex">

    <!-- SIDEBAR reutilizable -->
<?php include __DIR__ . '/partials/menu.php'; ?>

    <!-- MAIN -->
    <main class="main flex-grow-1 d-flex justify-content-center align-items-start">
      <section class="panel card">

        <h1 class="panel__title mb-4">Generate Ticket</h1>

        <form class="ticket-form mx-auto" id="ticketForm" novalidate>

          <!-- Category -->
          <div class="dropdown w-100">
            <button class="select-pro dropdown-toggle w-100" type="button" id="catBtn" data-bs-toggle="dropdown" aria-expanded="false">
              <span id="catText">Category</span>
              <span class="chev" aria-hidden="true"></span>
            </button>

            <ul class="dropdown-menu dropdown-pro w-100" aria-labelledby="catBtn">
              <li><button class="dropdown-item" type="button" data-value="Hardware">Hardware</button></li>
              <li><button class="dropdown-item" type="button" data-value="Software">Software</button></li>
              <li><button class="dropdown-item" type="button" data-value="Red">Network</button></li>
              <li><button class="dropdown-item" type="button" data-value="Correo">Email</button></li>
            </ul>

            <input type="hidden" name="category" id="category">
          </div>

          <!-- Type -->
          <div class="dropdown w-100">
            <button class="select-pro dropdown-toggle w-100" type="button" id="typeBtn" data-bs-toggle="dropdown" aria-expanded="false">
              <span id="typeText">Type</span>
              <span class="chev" aria-hidden="true"></span>
            </button>

            <ul class="dropdown-menu dropdown-pro w-100" aria-labelledby="typeBtn">
              <li><button class="dropdown-item" type="button" data-value="Equipo lento">Equipo lento</button></li>
              <li><button class="dropdown-item" type="button" data-value="No enciende">No enciende</button></li>
              <li><button class="dropdown-item" type="button" data-value="Sin internet">Sin internet</button></li>
              <li><button class="dropdown-item" type="button" data-value="Error de aplicación">Error de aplicación</button></li>
              <li><button class="dropdown-item" type="button" data-value="Acceso / credenciales">Acceso / credenciales</button></li>
              <li><button class="dropdown-item" type="button" data-value="Impresora">Impresora</button></li>
            </ul>

            <input type="hidden" name="problemType" id="problemType">
          </div>
        <!-- Area -->
          <div class="dropdown w-100">
            <button class="select-pro dropdown-toggle w-100" type="button" id="typeBtn" data-bs-toggle="dropdown" aria-expanded="false">
              <span id="typeText">Area</span>
              <span class="chev" aria-hidden="true"></span>
            </button>

            <ul class="dropdown-menu dropdown-pro w-100" aria-labelledby="typeBtn">
              <li><button class="dropdown-item" type="button" data-value="Equipo lento">Marketing e IT</button></li>
              <li><button class="dropdown-item" type="button" data-value="Corporate">Corporate</button></li>
              <li><button class="dropdown-item" type="button" data-value="Sin internet">Recruiters</button></li>
            </ul>

            <input type="hidden" name="problemType" id="problemType">
          </div>
          <!-- Descripción -->
          <div>
            <label class="label" for="desc">Comments</label>
            <textarea class="textarea" id="desc" rows="6" required placeholder="Describe your problem"></textarea>
          </div>

          <!-- Botón -->
          <button class="btn-send" type="submit">Send</button>

        </form>

      </section>
    </main>

  </div>

  <!-- Bootstrap Bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <!-- JS opcional (validación rápida) -->
  <script>
    const form = document.getElementById("ticketForm");
    form?.addEventListener("submit", (e) => {
      e.preventDefault();

      const category = document.getElementById("category");
      const problemType = document.getElementById("problemType");
      const desc = document.getElementById("desc");

      if (!category.value || !problemType.value || !desc.value.trim()) {
        alert("Completa todos los campos para enviar el ticket.");
        return;
      }

      alert("Ticket listo (front). Luego se conecta al backend.");
      form.reset();
    });
  </script>

  <script src="./assets/js/data/mock.js"></script>
  <script type="module">
    import { initStore } from "./assets/js/store.js";
    initStore();
  </script>

</body>
</html>
