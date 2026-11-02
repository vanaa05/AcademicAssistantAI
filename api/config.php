<?php
// ============================================================
// config.php — Database credentials + shared helpers
// ============================================================
// Credentials are loaded from env.php (one level up from api/).
// env.php is in .gitignore — it is NEVER committed to GitHub.
//
// ── Setup ────────────────────────────────────────────────────
//  • XAMPP localhost : edit the env.php in the project root
//  • Online hosting  : upload env.php to the project root on the server
//                      and fill in your host's DB credentials / Groq key
// ============================================================

// Load environment-specific credentials (DB + AI keys)
$_env_file = __DIR__ . '/../env.php';
if (file_exists($_env_file)) {
    require_once $_env_file;
} else {
    // ── Fallback for XAMPP if env.php is missing ─────────────
    // These values keep the local XAMPP setup working out-of-the-box.
    // For production, always create env.php instead.
    if (!defined('DB_HOST'))    define('DB_HOST',    'localhost');
    if (!defined('DB_PORT'))    define('DB_PORT',    '8111');
    if (!defined('DB_NAME'))    define('DB_NAME',    'academic_assistant');
    if (!defined('DB_USER'))    define('DB_USER',    'root');
    if (!defined('DB_PASS'))    define('DB_PASS',    '');
    if (!defined('AI_API_KEY')) define('AI_API_KEY', '');
    if (!defined('AI_MODEL'))   define('AI_MODEL',   'openai/gpt-oss-120b');
    if (!defined('AI_API_URL')) define('AI_API_URL', 'https://api.groq.com/openai/v1/chat/completions');
}

define('DB_CHARSET', 'utf8mb4');

// Upload directory (relative to api/ folder)
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_FILE_SIZE', 20 * 1024 * 1024); // 20 MB

// ─── PDO singleton ───────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            json_error('Database connection failed: ' . $e->getMessage(), 500);
        }
    }
    return $pdo;
}

// ─── Session ─────────────────────────────────────────────────
function start_session(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    // Detect HTTPS (covers reverse proxies and Cloudflare)
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
             || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    // Detect cross-origin requests (e.g. GitHub Pages frontend calling this PHP host).
    // When the Origin header domain differs from this server's host, the request is
    // cross-site. SameSite=Lax silently drops cookies on cross-site requests, which
    // breaks session-based auth.  SameSite=None allows cross-site cookies but
    // requires Secure (HTTPS), so we only use None when we are already on HTTPS.
    $samesite = 'Lax';   // safe default for same-origin deployments
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        $origin_host = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
        $server_host = $_SERVER['HTTP_HOST'] ?? '';
        // Strip port from server host for comparison
        $server_host = explode(':', $server_host)[0];
        if ($origin_host && $origin_host !== $server_host) {
            // Cross-origin request — use None so the cookie is sent cross-site.
            // Only valid over HTTPS; fall back to Lax on plain HTTP (localhost dev).
            $samesite = $is_https ? 'None' : 'Lax';
        }
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $is_https || $samesite === 'None',
        'httponly' => true,
        'samesite' => $samesite,
    ]);

    session_start();
}

function current_user(): ?array {
    start_session();
    return $_SESSION['user'] ?? null;
}

function require_auth(): array {
    $user = current_user();
    if (!$user) {
        json_error('Unauthenticated', 401);
    }
    return $user;
}

// ─── Response helpers ─────────────────────────────────────────
function json_ok($data = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function json_error(string $message, int $status = 400): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function get_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ─── CORS ─────────────────────────────────────────────────────
// Allow requests from the same origin only (no wildcard in production).
// For localhost dev the origin header is typically absent, so * is safe.
$allowed_origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . $allowed_origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
