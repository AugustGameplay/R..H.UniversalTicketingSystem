<?php
// api/download.php
// Secure endpoint to stream ticket attachments only to recognized users.

require __DIR__ . '/../partials/auth.php';
require __DIR__ . '/../config/db.php';

// Auth checks
if (!isset($_AUTH_USER_ID) || !$_AUTH_USER_ID) {
    http_response_code(401);
    die('Unauthorized. Please log in.');
}

$file = $_GET['file'] ?? '';

// Prevent path traversal attacks (e.g. file=../../config/db.php)
if (empty($file) || basename($file) !== $file) {
    http_response_code(403);
    die('Invalid file request.');
}

$path = __DIR__ . '/../uploads/tickets/' . $file;

if (!file_exists($path) || !is_file($path)) {
    http_response_code(404);
    die('Requested file not found.');
}

// Security: Optionally we can verify if the user has access to this ticket
// But simply being logged into the Private corporate system is the minimum requirement
$stmt = $pdo->prepare("SELECT id_ticket, id_user FROM tickets WHERE attachment_path = :path LIMIT 1");
$stmt->execute([':path' => 'uploads/tickets/' . $file]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    http_response_code(404);
    die('File not linked to any active ticket.');
}

// Role 3 (General User): Ensure they own the ticket
if (($_AUTH_ROLE_ID ?? 3) == 3) {
    if ($ticket['id_user'] != $_AUTH_USER_ID) {
        http_response_code(403);
        die('HTTP 403 Forbidden: You do not have permission to view this attachment.');
    }
}

// Securely stream the file without altering the URL
$mime = mime_content_type($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('Cache-Control: private, max-age=31536000');

// Clean output buffer to prevent corrupted files
if (ob_get_level()) {
    ob_end_clean();
}
flush();
readfile($path);
exit;
