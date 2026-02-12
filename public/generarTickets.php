<?php
require __DIR__ . '/partials/auth.php';
$active = 'generarTickets';

// ✅ 1) Conexión BD (ajusta la ruta si tu db.php está en otro lado)
require __DIR__ . '/config/db.php'; // <-- AJUSTA si no existe aquí

$errors = [];
$success = null;

// ✅ 2) Procesar POST (guardar ticket)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // Campos del form
  $category = trim($_POST['category'] ?? '');
  $type     = trim($_POST['type'] ?? '');
  $area     = trim($_POST['area'] ?? '');
  $comments = trim($_POST['comments'] ?? '');

  // Validación
  if ($category === '') $errors[] = "Selecciona una categoría.";
  if ($type === '')     $errors[] = "Selecciona un tipo.";
  if ($area === '')     $errors[] = "Selecciona un área.";
  if ($comments === '') $errors[] = "Escribe un comentario.";

  if (!$errors) {
    try {
      // ✅ 3) Inserta en tu tabla
      // ======= AJUSTA ESTO A TU ESQUEMA REAL =======
      // Ejemplo recomendado de columnas:
      // tickets(id, user_id, category, type, area, comments, status, created_at)

      $user_id = $_SESSION['id_user'] ?? $_SESSION['user_id'] ?? null; // por si tu sesión usa uno u otro

$stmt = $pdo->prepare("
  INSERT INTO tickets (id_user, category, type, area, comments, status, created_at)
  VALUES (:id_user, :category, :type, :area, :comments, 'Pendiente', NOW())
");

$stmt->execute([
  ':id_user'   => $user_id,
  ':category'  => $category,
  ':type'      => $type,
  ':area'      => $area,
  ':comments'  => $comments
]);

      $success = "✅ Ticket enviado correctamente.";
    } catch (PDOException $e) {
      $errors[] = "Error al guardar en BD: " . $e->getMessage();
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

  <!-- Bootstrap + FontAwesome -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <!-- Tu CSS (NO TOCADO) -->
  <link rel="stylesheet" href="./assets/css/generarTickets.css">
  <link rel="stylesheet" href="./assets/css/menu.css">
  <link rel="stylesheet" href="./assets/css/movil.css">
  <script defer src="./assets/js/sidebar.js"></script>

  <!-- Si ya tienes selects.js y lo usas en otras vistas, lo dejamos.
       Aquí igual implemento el binding en esta página para que sea seguro. -->
  <script src="./assets/js/selects.js"></script>
</head>

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
          <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
          <?php endif; ?>

          <?php if ($errors): ?>
            <div class="alert alert-danger py-2 mb-3">
              <ul class="mb-0">
                <?php foreach ($errors as $err): ?>
                  <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </div>

        <!-- ✅ FORM YA FUNCIONAL -->
        <form class="ticket-form mx-auto" id="ticketForm" method="POST" action="" novalidate>

          <!-- Category -->
          <div class="dropdown w-100">
            <button class="select-pro dropdown-toggle w-100" type="button" id="catBtn" data-bs-toggle="dropdown" aria-expanded="false">
              <span id="catText">Category</span>
              <span class="chev" aria-hidden="true"></span>
            </button>

            <ul class="dropdown-menu dropdown-pro w-100" aria-labelledby="catBtn" data-target-text="#catText" data-target-input="#category">
              <li><button class="dropdown-item" type="button" data-value="Hardware">Hardware</button></li>
              <li><button class="dropdown-item" type="button" data-value="Software">Software</button></li>
              <li><button class="dropdown-item" type="button" data-value="Network">Network</button></li>
              <li><button class="dropdown-item" type="button" data-value="Email">Email</button></li>
            </ul>

            <input type="hidden" name="category" id="category">
          </div>

          <!-- Type -->
          <div class="dropdown w-100">
            <button class="select-pro dropdown-toggle w-100" type="button" id="typeBtn" data-bs-toggle="dropdown" aria-expanded="false">
              <span id="typeText">Type</span>
              <span class="chev" aria-hidden="true"></span>
            </button>

            <ul class="dropdown-menu dropdown-pro w-100" aria-labelledby="typeBtn" data-target-text="#typeText" data-target-input="#type">
              <li><button class="dropdown-item" type="button" data-value="Equipo lento">Equipo lento</button></li>
              <li><button class="dropdown-item" type="button" data-value="No enciende">No enciende</button></li>
              <li><button class="dropdown-item" type="button" data-value="Sin internet">Sin internet</button></li>
              <li><button class="dropdown-item" type="button" data-value="Error de aplicación">Error de aplicación</button></li>
              <li><button class="dropdown-item" type="button" data-value="Acceso / credenciales">Acceso / credenciales</button></li>
              <li><button class="dropdown-item" type="button" data-value="Impresora">Impresora</button></li>
            </ul>

            <input type="hidden" name="type" id="type">
          </div>

          <!-- Area (✅ IDs únicos y name correcto) -->
          <div class="dropdown w-100">
            <button class="select-pro dropdown-toggle w-100" type="button" id="areaBtn" data-bs-toggle="dropdown" aria-expanded="false">
              <span id="areaText">Area</span>
              <span class="chev" aria-hidden="true"></span>
            </button>

            <ul class="dropdown-menu dropdown-pro w-100" aria-labelledby="areaBtn" data-target-text="#areaText" data-target-input="#area">
              <li><button class="dropdown-item" type="button" data-value="Marketing e IT">Marketing e IT</button></li>
              <li><button class="dropdown-item" type="button" data-value="Corporate">Corporate</button></li>
              <li><button class="dropdown-item" type="button" data-value="Recruiters">Recruiters</button></li>
            </ul>

            <input type="hidden" name="area" id="area">
          </div>

          <!-- Comments (✅ ya manda POST por tener name) -->
          <div>
            <label class="label" for="comments">Comments</label>
            <textarea class="textarea" id="comments" name="comments" rows="6" required placeholder="Describe your problem"></textarea>
          </div>

          <button class="btn-send" type="submit">Send</button>
        </form>

      </section>
    </main>

  </div>

  <!-- Bootstrap Bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <!-- ✅ Binding seguro para dropdowns (no interfiere con tu CSS) -->
  <script>
    // Convierte dropdown-items en selects: setea texto + hidden input
    document.querySelectorAll('.dropdown-menu[data-target-text][data-target-input]').forEach(menu => {
      const textSel = menu.getAttribute('data-target-text');
      const inputSel = menu.getAttribute('data-target-input');
      const textEl = document.querySelector(textSel);
      const inputEl = document.querySelector(inputSel);

      menu.querySelectorAll('.dropdown-item[data-value]').forEach(item => {
        item.addEventListener('click', () => {
          const val = item.getAttribute('data-value') || item.textContent.trim();
          if (textEl) textEl.textContent = val;
          if (inputEl) inputEl.value = val;
        });
      });
    });

    // Validación: si falta algo, no manda POST
    const form = document.getElementById("ticketForm");
    form?.addEventListener("submit", (e) => {
      const category = document.getElementById("category").value.trim();
      const type     = document.getElementById("type").value.trim();
      const area     = document.getElementById("area").value.trim();
      const comments = document.getElementById("comments").value.trim();

      if (!category || !type || !area || !comments) {
        e.preventDefault();
        alert("Completa todos los campos para enviar el ticket.");
      }
    });
  </script>

</body>
</html>