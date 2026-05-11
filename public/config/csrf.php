<?php
// config/csrf.php
// Enterprise CSRF Protection functions

function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (\Exception $e) {
            // Fallback for extremely rare environments without random_bytes
            $_SESSION['csrf_token'] = hash('sha256', uniqid(mt_rand(), true));
        }
    }
    return $_SESSION['csrf_token'];
}

function csrf_validate(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}
