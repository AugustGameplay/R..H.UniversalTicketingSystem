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


  // Auto-categoría (backend) por si el front no la llena
  $TYPE_TO_CATEGORY = [
    'Impresora' => 'Hardware',
    'No enciende' => 'Hardware',
    'Equipo lento' => 'Hardware',
    'Sin internet' => 'Network',
    'Error de aplicación' => 'Software',
    'Acceso / credenciales' => 'Email',
  ];

  if ($category === '') {
    $category = $TYPE_TO_CATEGORY[$type] ?? '';
  }
  if ($category === '') {
    $category = 'General';
  }



  
  $ticket_url = trim($_POST['ticket_url'] ?? '');
  $attachment_path = null;
// Validación  if ($type === '')     $errors[] = "Selecciona un tipo.";
  if ($area === '')     $errors[] = "Selecciona un área.";
  if ($comments === '') $errors[] = "Escribe un comentario.";

  
  if ($ticket_url !== '' && !filter_var($ticket_url, FILTER_VALIDATE_URL)) {
    $errors[] = "La URL no es válida.";
  }
if (!$errors) {
    try {
      
      // ====== Upload de evidencia (opcional) ======
      if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
          throw new Exception('Error al subir el archivo (código: ' . $_FILES['attachment']['error'] . ').');
        }

        // Tamaño máximo: 10MB
        if ($_FILES['attachment']['size'] > 10 * 1024 * 1024) {
          throw new Exception('El archivo excede el tamaño máximo de 10MB.');
        }

        $allowed_ext = ['png','jpg','jpeg','pdf','doc','docx','xlsx','xls','txt'];
        $original_name = $_FILES['attachment']['name'];
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_ext, true)) {
          throw new Exception('Tipo de archivo no permitido.');
        }

        $upload_dir = __DIR__ . '/uploads/tickets';
        if (!is_dir($upload_dir)) {
          mkdir($upload_dir, 0777, true);
        }

        $safe_name = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
        $dest = $upload_dir . '/' . $safe_name;

        if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
          throw new Exception('No se pudo guardar el archivo subido.');
        }

        // Guardamos ruta relativa para BD / vistas
        $attachment_path = 'uploads/tickets/' . $safe_name;
      }

// ✅ 3) Inserta en tu tabla
      // ======= AJUSTA ESTO A TU ESQUEMA REAL =======
      // Ejemplo recomendado de columnas:
      // tickets(id, user_id, category, type, area, comments, status, created_at)

      $user_id = $_SESSION['id_user'] ?? $_SESSION['user_id'] ?? null; // por si tu sesión usa uno u otro

$stmt = $pdo->prepare("
  INSERT INTO tickets (id_user, category, type, area, comments, ticket_url, attachment_path, status, created_at)
  VALUES (:id_user, :category, :type, :area, :comments, :ticket_url, :attachment_path, 'Pendiente', NOW())
");

$stmt->execute([
  ':id_user'          => $user_id,
  ':category'         => $category,
  ':type'             => $type,
  ':area'             => $area,
  ':comments'         => $comments,
  ':ticket_url'       => ($ticket_url !== '' ? $ticket_url : null),
  ':attachment_path'  => $attachment_path
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
        <form class="ticket-form mx-auto" id="ticketForm" method="POST" action="" novalidate enctype="multipart/form-data">

          <!-- Category (auto, oculto) -->
          <input type="hidden" name="category" id="category">

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
              <li><button class="dropdown-item" type="button" data-value="Managers">Managers</button></li>
              <li><button class="dropdown-item" type="button" data-value="Corporate">Corporate</button></li>
              <li><button class="dropdown-item" type="button" data-value="Recruiters">Recruiters</button></li>
              <li><button class="dropdown-item" type="button" data-value="RH">RH</button></li>
              <li><button class="dropdown-item" type="button" data-value="Accounting">Accounting</button></li>
              <li><button class="dropdown-item" type="button" data-value="Workers Comp">Workers Comp</button></li>
            </ul>

            <input type="hidden" name="area" id="area">
          </div>
          <!-- URL (opcional) -->
          <div>
            <label class="label" for="ticket_url">URL (optional)</label>
            <input class="input" type="url" id="ticket_url" name="ticket_url" value="<?= htmlspecialchars($_POST['ticket_url'] ?? '') ?>" placeholder="Paste a link (Drive, SharePoint, etc.)">
          </div>

          <!-- Attachment / Evidence (opcional) -->
          <div>
            <label class="label" for="attachment">Attachment / Evidence (optional)</label>
            <input class="input" type="file" id="attachment" name="attachment" accept=".png,.jpg,.jpeg,.pdf,.doc,.docx,.xlsx,.xls,.txt">
            <small class="hint">Max 10MB. Allowed: images, PDF, Office docs, txt.</small>
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

    
    // Auto-categoría según el "Type"
    // Puedes ajustar/expandir este mapeo cuando agreguen más tipos.
    const TYPE_TO_CATEGORY = {
      "Impresora": "Hardware",
      "No enciende": "Hardware",
      "Equipo lento": "Hardware",
      "Sin internet": "Network",
      "Error de aplicación": "Software",
      "Acceso / credenciales": "Email",
    };

    const typeMenu = document.querySelector('ul[aria-labelledby="typeBtn"]');
    const catTextEl = document.querySelector("#catText");
    const catInputEl = document.querySelector("#category");

    // Cuando el usuario elige un Type, se asigna la Category automáticamente
    typeMenu?.querySelectorAll('.dropdown-item[data-value]').forEach(item => {
      item.addEventListener('click', () => {
        const typeVal = item.getAttribute('data-value') || item.textContent.trim();
        const autoCat = TYPE_TO_CATEGORY[typeVal];

        if (autoCat && catInputEl) {
          catInputEl.value = autoCat;
          if (catTextEl) catTextEl.textContent = autoCat;
        }
      });
    });


// Validación: si falta algo, no manda POST
    const form = document.getElementById("ticketForm");
    form?.addEventListener("submit", (e) => {
      const type     = document.getElementById("type").value.trim();
      const area     = document.getElementById("area").value.trim();
      const comments = document.getElementById("comments").value.trim();

      // Si por alguna razón category viene vacía, la calculamos antes de validar/enviar
      const catInput = document.getElementById("category");
      if (catInput && !catInput.value.trim()) {
        const autoCat = TYPE_TO_CATEGORY[type] || "General";
        catInput.value = autoCat;
      }

      if (!type || !area || !comments) {
        e.preventDefault();
        alert("Completa todos los campos para enviar el ticket.");
      }
    });
  </script>

</body>
</html>