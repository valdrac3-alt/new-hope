<?php
// logout.php — Destroys the admin session and redirects to the login page.
// IMPORTANT: session_name() must be called before ANY require/include
// that might trigger session_start() internally.

session_name('dcms_session');          // ← must match auth.php exactly
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode',  1);
ini_set('session.cookie_samesite', 'Strict');
session_start();

// Now safe to load config and db
require_once 'includes/config.php';
require_once 'includes/db.php';

// Log the logout action only if the user was actually logged in
if (isset($_SESSION['user_id'], $_SESSION['full_name'])) {
    log_action(
        $conn,
        $_SESSION['user_id'],
        $_SESSION['full_name'],
        'Logged Out',
        'auth',
        $_SESSION['user_id'],
        'Session ended from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
    );
}

// Destroy session cleanly
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $p['path'], $p['domain'],
        $p['secure'], $p['httponly']
    );
}
session_destroy();

header('Location: ' . BASE_URL . 'index.php?logout=1');
exit();
