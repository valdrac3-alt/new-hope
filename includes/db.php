<?php
// db.php — Database connection and shared helper functions.
// Credentials are loaded from the .env file (never hardcoded here).
// Uses PDO with PostgreSQL (Supabase) instead of mysqli.

// --- Load .env file -----------------------------------------------------------
function load_env($path) {
    if (!file_exists($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if (!array_key_exists($k, $_ENV)) { $_ENV[$k] = $v; putenv("$k=$v"); }
    }
}
load_env(__DIR__ . '/../.env');

// --- Connect to database (PDO + PostgreSQL / Supabase) ------------------------
try {
    $db_host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? 'localhost');
    $db_port = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? '5432');
    $db_name = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'postgres');
    $db_user = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? 'postgres');
    $db_pass = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? '');

    $dsn = "pgsql:host={$db_host};port={$db_port};dbname={$db_name};sslmode=prefer";

    $conn = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    
    ]);

} catch (PDOException $e) {
    error_log('[DB ERROR] ' . $e->getMessage());
    $isApi = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
    if ($isApi) {
        header('Content-Type: application/json');
        http_response_code(503);
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    } else {
        http_response_code(503);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
        <title>Database Error</title>
        <style>
            body{font-family:sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
            .box{background:#fff;border-radius:12px;padding:40px;max-width:500px;width:100%;box-shadow:0 4px 24px rgba(0,0,0,0.10);border-top:4px solid #dc2626;}
            h2{color:#dc2626;margin:0 0 12px;}
            p{color:#475569;line-height:1.7;margin:0 0 10px;}
            code{background:#f1f5f9;padding:2px 8px;border-radius:4px;font-size:0.9em;color:#0f172a;}
            .step{background:#fafafa;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;margin:10px 0;font-size:0.88em;color:#334155;}
            .step strong{display:block;margin-bottom:4px;color:#0f172a;}
        </style></head><body><div class="box">
        <h2>&#9888; Cannot connect to database</h2>
        <p>The system cannot reach PostgreSQL (Supabase). Check your environment variables:</p>
        <div class="step"><strong>Required .env variables for Supabase</strong>
            <code>DB_HOST=db.xxxx.supabase.co</code><br>
            <code>DB_PORT=5432</code><br>
            <code>DB_USER=postgres</code><br>
            <code>DB_PASS=your-supabase-password</code><br>
            <code>DB_NAME=postgres</code>
        </div>
        <div class="step"><strong>Where to find these values</strong>
            Go to your Supabase project → Settings → Database → Connection string (URI)
        </div>
        </div></body></html>';
    }
    exit();
}

// PDO compatibility shim: add a close() method so existing $stmt->close() calls work
// without errors (PDO statements don't have close(), but setting to null frees resources)
class CompatPDOStatement extends PDOStatement {
    public function close(): void { /* no-op — PDO frees on unset/reassign */ }
    public function get_result(): static { return $this; }
    public function num_rows(): int { return $this->rowCount(); }
    public function fetch_assoc(): ?array {
        $r = $this->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }
    public function fetch_all_assoc(): array { return $this->fetchAll(PDO::FETCH_ASSOC); }
}
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['CompatPDOStatement']);

// --- Helper functions ---------------------------------------------------------

// Return the real client IP, accounting for reverse proxies.
function get_client_ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $xff    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $is_proxy = (
        $remote === '127.0.0.1' ||
        str_starts_with($remote, '10.')    ||
        str_starts_with($remote, '172.')   ||
        str_starts_with($remote, '192.168.')
    );
    if ($is_proxy && !empty($xff)) {
        $ip = trim(explode(',', $xff)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return $remote;
}

// Cast a GET/POST value to a safe positive integer
function secure_int($value) {
    $v = intval($value);
    return $v > 0 ? $v : 0;
}

// Escape a string for use in LIKE queries (kept for backward compat)
function secure_str($conn, $value) {
    return trim($value);
}

// Safely echo user-supplied data in HTML
function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Check if an email address is valid
function valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Validate a phone number (E.164 or PH local format)
function valid_phone($phone) {
    $phone = trim($phone);
    if (preg_match('/^\+[1-9]\d{6,14}$/', $phone)) return true;
    if (preg_match('/^09\d{9}$/', $phone)) return true;
    return false;
}

// Write an entry to the audit_logs table
function log_action($conn, $user_id, $user_name, $action, $module, $record_id = null, $details = '') {
    $ip   = get_client_ip();
    $stmt = $conn->prepare(
        "INSERT INTO audit_logs (user_id, user_name, action, module, record_id, details, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    if ($stmt) {
        $stmt->execute([$user_id, $user_name, $action, $module, $record_id, $details, $ip]);
    }
}

// ============================================================
// SECURITY #1 — CSRF PROTECTION
// ============================================================
function generate_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    $token = generate_csrf_token();
    $safe  = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="_csrf" value="' . $safe . '">';
}

function validate_csrf(): void {
    $submitted = $_POST['_csrf'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';
    if (empty($submitted) || empty($expected) || !hash_equals($expected, $submitted)) {
        error_log('[CSRF] Token mismatch from IP: ' . get_client_ip());
        $isApi = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
        if ($isApi) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Invalid request token. Please refresh and try again.']);
        } else {
            http_response_code(403);
            include dirname(__DIR__) . '/error.php';
        }
        exit();
    }
}

// ============================================================
// NOTIFICATION TRIGGER FUNCTION
// ============================================================
function notify(PDO $conn, string $type, string $title, string $message, string $link = '', ?int $user_id = null): void {
    $allowed = ['appointment', 'payment', 'system', 'reminder'];
    if (!in_array($type, $allowed)) $type = 'system';
    $stmt = $conn->prepare(
        "INSERT INTO notifications (user_id, title, message, type, is_read, link)
         VALUES (?, ?, ?, ?, false, ?)"
    );
    if ($stmt) {
        $stmt->execute([$user_id, $title, $message, $type, $link]);
    }
}

// ============================================================
// OTP FUNCTIONS
// ============================================================
function generate_otp() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

// Send OTP via SMS using Semaphore (Philippine SMS API)
function send_otp_sms($phone, $otp) {
    $apikey = $_ENV['SEMAPHORE_API_KEY'] ?? '';
    if (empty($apikey) || empty($phone)) return false;
    $message = "Your DentalCare verification code is: $otp. It expires in 5 minutes. Do not share this code with anyone.";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.semaphore.co/api/v4/messages');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'apikey'     => $apikey,
        'number'     => $phone,
        'message'    => $message,
        'sendername' => 'DentalCare'
    ]));
    $result = curl_exec($ch);
    curl_close($ch);
    return $result !== false;
}

// Send OTP via email using Resend API
function send_otp_email($email, $otp, $name = '') {
    if (empty($email)) return false;
    $api_key   = $_ENV['RESEND_API_KEY'] ?? '';
    $from_addr = $_ENV['MAIL_FROM']      ?? 'no-reply@yourdomain.com';
    $from_name = $_ENV['MAIL_FROM_NAME'] ?? 'DentalCare';
    if (empty($api_key)) {
        error_log('[MAIL] RESEND_API_KEY is not set — OTP email to ' . $email . ' was skipped.');
        return false;
    }
    $greeting  = $name ?: 'User';
    $text_body =
        "Hello $greeting,\n\n" .
        "Your DentalCare verification code is:\n\n" .
        "  $otp\n\n" .
        "This code expires in 5 minutes.\n" .
        "Do not share this code with anyone.\n\n" .
        "- DentalCare System";
    $html_body =
        "<!DOCTYPE html><html><body style='font-family:sans-serif;max-width:480px;margin:auto;padding:32px;'>" .
        "<h2 style='color:#1e3a8a;'>DentalCare Verification</h2>" .
        "<p>Hello <strong>" . htmlspecialchars($greeting) . "</strong>,</p>" .
        "<p>Your verification code is:</p>" .
        "<div style='font-size:2rem;font-weight:700;letter-spacing:8px;color:#1e3a8a;" .
        "background:#f1f5f9;padding:16px 24px;border-radius:8px;display:inline-block;margin:8px 0;'>" .
        htmlspecialchars($otp) . "</div>" .
        "<p style='color:#64748b;font-size:0.9rem;'>This code expires in <strong>5 minutes</strong>." .
        " Do not share it with anyone.</p>" .
        "<hr style='border:none;border-top:1px solid #e2e8f0;margin:24px 0;'>" .
        "<p style='color:#94a3b8;font-size:0.8rem;'>DentalCare System</p>" .
        "</body></html>";
    $payload = json_encode([
        'from'    => $from_name . ' <' . $from_addr . '>',
        'to'      => [$email],
        'subject' => 'Your DentalCare Verification Code',
        'text'    => $text_body,
        'html'    => $html_body,
    ]);
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        error_log('[MAIL] Resend API returned HTTP ' . $code . ' for ' . $email . ': ' . $result);
        return false;
    }
    return true;
}

// Generate a padded code like PAT-0001 or APT-0042.
function make_code(string $prefix, int $id): string {
    return $prefix . '-' . str_pad($id, 4, '0', STR_PAD_LEFT);
}

// Legacy wrapper — kept for backward compatibility.
function generate_code($conn, $table, $prefix) {
    $res = $conn->query("SELECT MAX(id) as max_id FROM \"$table\"");
    $row = $res ? $res->fetch(PDO::FETCH_ASSOC) : null;
    $next = ($row['max_id'] ?? 0) + 1;
    return $prefix . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
}

// ============================================================
// SECURITY #2 — API RATE LIMITING
// ============================================================
function api_rate_limit($conn, string $endpoint, int $max_hits = 60, int $window_sec = 60): void {
    $ip  = get_client_ip();
    $now = date('Y-m-d H:i:s');

    try {
        $stmt = $conn->prepare(
            "SELECT id, hits, window_start FROM rate_limits WHERE ip_address = ? AND endpoint = ? LIMIT 1"
        );
        $stmt->execute([$ip, $endpoint]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $ins = $conn->prepare("INSERT INTO rate_limits (ip_address, endpoint, hits, window_start) VALUES (?,?,1,?)");
            $ins->execute([$ip, $endpoint, $now]);
            return;
        }

        $window_age = time() - strtotime($row['window_start']);

        if ($window_age > $window_sec) {
            $upd = $conn->prepare("UPDATE rate_limits SET hits = 1, window_start = ? WHERE id = ?");
            $upd->execute([$now, $row['id']]);
            return;
        }

        if ($row['hits'] >= $max_hits) {
            $retry_after = $window_sec - $window_age;
            http_response_code(429);
            header('Retry-After: ' . $retry_after);
            header('Content-Type: application/json');
            echo json_encode([
                'status'  => 'error',
                'message' => 'Too many requests. Please slow down.',
                'retry_after_seconds' => $retry_after,
            ]);
            exit();
        }

        $upd = $conn->prepare("UPDATE rate_limits SET hits = hits + 1 WHERE id = ?");
        $upd->execute([$row['id']]);
    } catch (PDOException $e) {
        // If rate-limit table is down, fail open rather than blocking all requests
        error_log('[RATE LIMIT] DB error: ' . $e->getMessage());
    }
}

// ============================================================
// SECURITY #3 — API TOKEN AUTHENTICATION
// ============================================================
function get_api_token_user($conn): ?array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($header, 'Bearer ')) return null;

    $raw_token = trim(substr($header, 7));
    if (empty($raw_token)) return null;

    $hash = hash('sha256', $raw_token);
    $now  = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("
        SELECT t.id as token_id, t.user_id, u.full_name, u.username, u.role, u.is_active,
               t.expires_at, t.is_active as token_active
        FROM   api_tokens t
        JOIN   users u ON u.id = t.user_id
        WHERE  t.token_hash = ?
        AND    t.is_active = TRUE
        AND    u.is_active = TRUE
        AND    (t.expires_at IS NULL OR t.expires_at > ?)
        LIMIT  1
    ");
    $stmt->execute([$hash, $now]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $upd = $conn->prepare("UPDATE api_tokens SET last_used = ? WHERE id = ?");
        $upd->execute([$now, $user['token_id']]);
    }

    return $user ?: null;
}

function require_api_auth($conn): array {
    if (isset($_SESSION['user_id'])) {
        return [
            'user_id'   => $_SESSION['user_id'],
            'full_name' => $_SESSION['full_name'],
            'role'      => $_SESSION['role'],
        ];
    }
    $user = get_api_token_user($conn);
    if ($user) {
        return [
            'user_id'   => $user['user_id'],
            'full_name' => $user['full_name'],
            'role'      => $user['role'],
        ];
    }
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Authentication required.']);
    exit();
}

// ============================================================
// SESSION CACHE HELPERS — reduce round trips for static data
// sc_get / sc_set cache DB results in $_SESSION for a given TTL.
// Usage:
//   $services = sc_get('services') ?? sc_set('services',
//       $conn->query("SELECT ...")->fetchAll(), 300);
// ============================================================
function sc_get(string $key): mixed {
    if (!isset($_SESSION['_sc'][$key])) return null;
    if (time() > $_SESSION['_sc'][$key]['exp']) {
        unset($_SESSION['_sc'][$key]);
        return null;
    }
    return $_SESSION['_sc'][$key]['d'];
}

function sc_set(string $key, mixed $data, int $ttl = 300): mixed {
    $_SESSION['_sc'][$key] = ['d' => $data, 'exp' => time() + $ttl];
    return $data;
}
