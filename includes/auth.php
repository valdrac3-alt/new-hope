<?php
// auth.php — Session guard for every protected page.
// Compatible with Laragon, XAMPP, and standard PHP setups.

// ── Step 1: ini_set BEFORE session_name and session_start ─────────────────
// These must come before any session function is called.
ini_set('session.cookie_httponly',  1);
ini_set('session.use_strict_mode',  1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime',   28800);
ini_set('session.cookie_lifetime',  0);
ini_set('session.cookie_path',     '/');
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 1 : 0);

// ── Step 2: Set session name — must be IDENTICAL on every page ────────────
session_name('dcms_session');

// ── Step 3: Start the session only if not already active ──────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Step 4: Timeout check (8-hour idle limit) ─────────────────────────────
$lifetime = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 28800;
if (isset($_SESSION['last_activity'])) {
    if ((time() - $_SESSION['last_activity']) > $lifetime) {
        session_unset();
        session_destroy();
        $base = defined('BASE_URL') ? BASE_URL : 'http://localhost/cap/';
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Location: ' . $base . 'index.php?timeout=1');
        exit();
    }
}

// ── Step 5: Authentication check ──────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    $base = defined('BASE_URL') ? BASE_URL : 'http://localhost/cap/';
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Location: ' . $base . 'index.php');
    exit();
}

// Stamp last_activity only after auth is confirmed — prevents unauthenticated
// sessions from self-renewing by refreshing the timestamp on every request.
$_SESSION['last_activity'] = time();

// ── Step 6: Expose session data ───────────────────────────────────────────
$current_user_id   = (int) $_SESSION['user_id'];
$current_user_name = $_SESSION['full_name'] ?? 'Unknown';
$current_user_role = $_SESSION['role']      ?? 'staff';

// ── Helpers ───────────────────────────────────────────────────────────────
function validate_password(string $pw): ?string {
    if (strlen($pw) < 8 || strlen($pw) > 18)
        return 'Password must be between 8 and 18 characters.';
    if (!preg_match('/[A-Z]/', $pw))
        return 'Password must contain at least one uppercase letter (A-Z).';
    if (!preg_match('/[a-z]/', $pw))
        return 'Password must contain at least one lowercase letter (a-z).';
    if (!preg_match('/[0-9]/', $pw))
        return 'Password must contain at least one number (0-9).';
    if (!preg_match('/[^A-Za-z0-9]/', $pw))
        return 'Password must contain at least one special character (e.g. @, #, $, !).';
    return null;
}

function is_admin(): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function require_admin(): void {
    if (!is_admin()) {
        $base = defined('BASE_URL') ? BASE_URL : 'http://localhost/cap/';
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Location: ' . $base . 'dashboard.php');
        exit();
    }
}
