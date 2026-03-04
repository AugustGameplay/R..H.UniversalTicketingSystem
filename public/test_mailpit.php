<?php
require_once __DIR__ . '/config/mailer_mailpit.php';

$ticket = [
  'id' => 999,
  'titulo' => 'Prueba Mailpit',
  'descripcion' => 'Si ves esto en Mailpit, ya quedó.',
  'area' => 'IT',
  'prioridad' => 'Alta',
  'creado_por' => 'Jared',
  'url' => 'http://localhost/ticketsystem/R..H.UniversalTicketingSystem/public/'
];

$ok = mailpit_send_ticket_created($ticket, 'test@local.test', 'Jared');

echo $ok ? "OK enviado (revisa Mailpit en http://localhost:8025)" : "FALLÓ (revisa C:\\xampp\\apache\\logs\\error.log)";