<?php
// ============================================================
// auth.php — register / login / logout / me
// ============================================================
require_once __DIR__ . '/config.php';
start_session();

$action = $_GET['action'] ?? '';

match ($action) {
    'register' => handle_register(),
    'login'    => handle_login(),
    'logout'   => handle_logout(),
    'me'       => handle_me(),
    default    => json_error('Unknown action', 404),
};

// ─── Register ────────────────────────────────────────────────
function handle_register(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed', 405);
    }
    $body = get_json_body();

    $full_name = trim($body['full_name'] ?? '');
    $email     = strtolower(trim($body['email'] ?? ''));
    $password  = $body['password'] ?? '';

    // Validation
    if (strlen($full_name) < 2) {
        json_error('Full name must be at least 2 characters.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Invalid email address.');
    }
    if (strlen($password) < 8) {
        json_error('Password must be at least 8 characters.');
    }

    $pdo = db();

    // Duplicate check
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        json_error('An account with this email already exists.');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare(
        'INSERT INTO users (full_name, email, password) VALUES (?, ?, ?)'
    );
    $stmt->execute([$full_name, $email, $hash]);
    $user_id = (int) $pdo->lastInsertId();

    $user = ['id' => $user_id, 'full_name' => $full_name, 'email' => $email];
    $_SESSION['user'] = $user;

    json_ok($user);
}

// ─── Login ───────────────────────────────────────────────────
function handle_login(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed', 405);
    }
    $body  = get_json_body();
    $email = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';

    if (!$email || !$password) {
        json_error('Email and password are required.');
    }

    $pdo  = db();
    $stmt = $pdo->prepare('SELECT id, full_name, email, password FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['password'])) {
        json_error('Invalid email or password.', 401);
    }

    // Update last_login
    $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$row['id']]);

    $user = ['id' => $row['id'], 'full_name' => $row['full_name'], 'email' => $row['email']];
    $_SESSION['user'] = $user;

    json_ok($user);
}

// ─── Logout ──────────────────────────────────────────────────
function handle_logout(): void {
    $_SESSION = [];
    session_destroy();
    json_ok(['message' => 'Logged out']);
}

// ─── Me ──────────────────────────────────────────────────────
function handle_me(): void {
    $user = current_user();
    if (!$user) {
        json_error('Not authenticated', 401);
    }
    json_ok($user);
}
