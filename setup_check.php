<?php
// ============================================================
// setup_check.php — Configuration Diagnostic Tool
// ============================================================
// Run this from your browser at:
//   http://localhost/AcademicAssistantAI/setup_check.php   (XAMPP)
//   https://yourdomain.com/setup_check.php                  (online)
//
// DELETE or RENAME this file after you have confirmed everything works.
// ============================================================
ini_set('display_errors', 0);
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/api/config.php';

$results  = [];
$fixSteps = [];

function check(string $label, bool $ok, string $detail = ''): array {
    return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
}

// ── 1. PHP Version ──────────────────────────────────────────
$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
$results[] = check('PHP Version', $phpOk,
    'PHP ' . PHP_VERSION . ($phpOk ? ' ✓' : ' — PHP 8.0+ required'));

// ── 2. Required extensions ──────────────────────────────────
foreach (['pdo', 'pdo_mysql', 'curl', 'json', 'mbstring', 'fileinfo'] as $ext) {
    $loaded = extension_loaded($ext);
    $results[] = check('PHP Extension: ' . $ext, $loaded,
        $loaded ? 'Loaded' : 'MISSING — enable in php.ini');
}

// ── 3. env.php presence ─────────────────────────────────────
$envFile  = __DIR__ . '/env.php';
$envFound = file_exists($envFile);
$results[] = check('env.php credentials file', $envFound,
    $envFound
        ? 'Found: ' . $envFile
        : 'NOT FOUND — copy env.example.php → env.php and fill in your credentials.');
if (!$envFound) {
    $fixSteps[] = [
        'title' => 'Create env.php with your credentials',
        'steps' => [
            'In the project root, find env.example.php.',
            'Make a copy of it and name the copy env.php.',
            'Open env.php and fill in: DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, AI_API_KEY.',
            'Save the file. Do NOT commit env.php to GitHub.',
        ],
    ];
}

// ── 4. API key presence and format ──────────────────────────
$key    = trim(AI_API_KEY);
$keySet = !empty($key);
$isGsk  = $keySet && str_starts_with($key, 'gsk_');

if (!$keySet) {
    $results[] = check('Groq API Key', false,
        'NOT SET — open env.php and paste your gsk_... key on the AI_API_KEY line.');
    $fixSteps[] = [
        'title' => 'Get and set your free Groq API key',
        'steps' => [
            'Go to https://console.groq.com and sign in (GitHub / Google login supported).',
            'Click "API Keys" in the left sidebar.',
            'Click "Create API Key", give it a name, click "Submit".',
            'Copy the key — it starts with "gsk_". (It is shown only once.)',
            'Open env.php in a text editor.',
            'Find the line: define(\'AI_API_KEY\', \'gsk_YOUR_GROQ_API_KEY_HERE\');',
            'Replace the placeholder with your real key.',
            'Save the file and reload this page.',
        ],
    ];
} else {
    $results[] = check('Groq API Key — format', $isGsk,
        $isGsk
            ? 'Key is present and starts with "gsk_" (correct format for Groq).'
            : 'WARNING: Key does not start with "gsk_". Groq keys always start with "gsk_". Check env.php.');
}

// ── 5. Database connection ──────────────────────────────────
$dbOk  = false;
$dbMsg = '';
try {
    $pdo   = db();
    $count = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $dbOk  = true;
    $dbMsg = 'Connected successfully. Users in database: ' . $count;
} catch (Throwable $e) {
    $dbMsg = '⚠ ' . $e->getMessage() . ' — Check DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS in env.php';
    $fixSteps[] = [
        'title' => 'Fix the database connection',
        'steps' => [
            'Open env.php.',
            'Set DB_HOST to the value given by your hosting control panel (usually "localhost").',
            'Set DB_PORT to 3306 (standard MySQL port on most hosts; XAMPP default is 3306 unless you changed it).',
            'Set DB_NAME to the database name you created.',
            'Set DB_USER and DB_PASS to your MySQL username and password.',
            'Import database.sql into your MySQL database via phpMyAdmin.',
            'Save env.php and reload this page.',
        ],
    ];
}
$results[] = check('Database Connection', $dbOk, $dbMsg);

// ── 6. Upload directory ─────────────────────────────────────
if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
$uploadOk = is_dir(UPLOAD_DIR) && is_writable(UPLOAD_DIR);
$results[] = check('Upload Directory (api/uploads/)', $uploadOk,
    $uploadOk
        ? 'Directory exists and is writable: ' . UPLOAD_DIR
        : '⚠ Not writable: ' . UPLOAD_DIR . ' — on Linux hosts run: chmod 755 api/uploads/');

// ── 7. Live Groq API test ───────────────────────────────────
$apiOk     = false;
$apiDetail = '';
$apiHint   = '';

if (!$keySet) {
    $apiDetail = 'Skipped — API key not set in env.php.';
} else {
    $testPayload = json_encode([
        'model'       => AI_MODEL,
        'messages'    => [['role' => 'user', 'content' => 'Reply with exactly one word: CONNECTED']],
        'max_tokens'  => 10,
        'temperature' => 0,
    ]);
    $ch = curl_init(AI_API_URL);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_POSTFIELDS     => $testPayload,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    // Try to find a CA bundle for SSL verification
    $caCandidates = [
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/usr/local/etc/openssl/cert.pem',
        'D:/xampp/apache/bin/curl-ca-bundle.crt',
        'D:/xampp/php/extras/ssl/cacert.pem',
        'C:/xampp/apache/bin/curl-ca-bundle.crt',
        'C:/xampp/php/extras/ssl/cacert.pem',
        __DIR__ . '/cacert.pem',
    ];
    foreach ($caCandidates as $ca) {
        if (file_exists($ca) && is_readable($ca)) {
            $opts[CURLOPT_CAINFO] = $ca;
            break;
        }
    }
    $phpCa = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
    if (!isset($opts[CURLOPT_CAINFO]) && $phpCa && file_exists($phpCa)) {
        $opts[CURLOPT_CAINFO] = $phpCa;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cErr = curl_error($ch);
    curl_close($ch);

    if ($cErr) {
        $apiDetail = 'cURL error: ' . $cErr;
        $apiHint   = 'Check your internet connection and SSL certificate bundle. On XAMPP, download cacert.pem from https://curl.se/ca/cacert.pem and place it in the project root.';
    } elseif ($code === 200) {
        $data      = json_decode($resp, true);
        $text      = $data['choices'][0]['message']['content'] ?? '';
        $apiOk     = true;
        $apiDetail = 'HTTP 200 — Groq model "' . AI_MODEL . '" responded: "' . trim($text) . '"';
    } elseif ($code === 401) {
        $apiDetail = 'HTTP 401 — API key invalid or revoked.';
        $apiHint   = 'Generate a new key at https://console.groq.com/keys and update env.php.';
    } elseif ($code === 404) {
        $errBody   = json_decode($resp, true);
        $errMsg    = $errBody['error']['message'] ?? '';
        $apiDetail = 'HTTP 404 — Model "' . AI_MODEL . '" not found. ' . substr($errMsg, 0, 120);
        $apiHint   = 'Check available models at https://console.groq.com/docs/models and update AI_MODEL in env.php.';
    } elseif ($code === 429) {
        $apiOk     = true; // key is valid, just throttled
        $apiDetail = 'HTTP 429 — Rate limit hit. Key is valid but quota is exceeded.';
        $apiHint   = 'Wait 30 seconds and reload this page.';
    } else {
        $errBody   = json_decode($resp, true);
        $apiDetail = 'HTTP ' . $code . ' — ' . substr(($errBody['error']['message'] ?? 'Unknown error'), 0, 150);
        $apiHint   = 'Check your Groq key and model name in env.php.';
    }
}
$results[] = check('Live Groq API Test (' . AI_MODEL . ')', $apiOk,
    $apiDetail . ($apiHint ? ' | Hint: ' . $apiHint : ''));

// ── Count pass/fail ─────────────────────────────────────────
$pass = count(array_filter($results, fn($r) => $r['ok']));
$fail = count($results) - $pass;

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Academic Assistant AI — Setup Check</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, 'Segoe UI', system-ui, sans-serif; background: #f0f4f8; color: #1f2328; padding: 2rem; }
  .container { max-width: 800px; margin: 0 auto; }
  h1 { font-size: 1.5rem; margin-bottom: .25rem; }
  .subtitle { color: #57606a; font-size: .9rem; margin-bottom: 1.5rem; }
  .summary { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
  .badge { padding: .5rem 1.25rem; border-radius: 6px; font-weight: 600; font-size: 1rem; }
  .badge.pass { background: #dcfce7; color: #166534; }
  .badge.fail { background: #fee2e2; color: #991b1b; }
  .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; margin-bottom: 1.5rem; }
  .check-row { display: flex; align-items: flex-start; gap: .75rem; padding: .75rem 1rem; border-bottom: 1px solid #f3f4f6; }
  .check-row:last-child { border-bottom: none; }
  .icon { font-size: 1.1rem; flex-shrink: 0; margin-top: .05rem; }
  .ok { color: #16a34a; }
  .fail-icon { color: #dc2626; }
  .check-label { font-weight: 600; font-size: .9rem; }
  .check-detail { font-size: .82rem; color: #57606a; margin-top: .15rem; line-height: 1.5; }
  .fix-card { background: #fff8dc; border: 1px solid #f0c040; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem; }
  .fix-card h2 { font-size: 1rem; margin-bottom: .75rem; color: #92400e; }
  .fix-card ol { margin-left: 1.25rem; font-size: .9rem; line-height: 2; }
  .fix-card code { background: #f1f5f9; padding: .1rem .35rem; border-radius: 3px; font-family: monospace; font-size: .87rem; }
  .fix-card a { color: #1d4ed8; }
  .warn { background: #fef9c3; padding: .75rem 1rem; border-radius: 6px; font-size: .85rem; color: #854d0e; margin-top: 1rem; }
  .success-banner { background: #dcfce7; border: 1px solid #86efac; border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; color: #14532d; font-weight: 600; }
</style>
</head>
<body>
<div class="container">
  <h1>🔧 Academic Assistant AI — Setup Check</h1>
  <p class="subtitle">Run this page to diagnose your configuration. Delete this file once everything is working.</p>

  <div class="summary">
    <div class="badge pass"><?= $pass ?> Passed</div>
    <?php if ($fail > 0): ?>
    <div class="badge fail"><?= $fail ?> Failed</div>
    <?php endif; ?>
  </div>

  <?php if ($fail === 0): ?>
  <div class="success-banner">✅ All checks passed! Your application is ready. Delete setup_check.php before sharing the URL publicly.</div>
  <?php endif; ?>

  <?php foreach ($fixSteps as $fix): ?>
  <div class="fix-card">
    <h2>📋 <?= htmlspecialchars($fix['title']) ?></h2>
    <ol>
      <?php foreach ($fix['steps'] as $step): ?>
      <li><?= htmlspecialchars($step) ?></li>
      <?php endforeach; ?>
    </ol>
  </div>
  <?php endforeach; ?>

  <div class="card">
    <?php foreach ($results as $r): ?>
    <div class="check-row">
      <span class="icon <?= $r['ok'] ? 'ok' : 'fail-icon' ?>"><?= $r['ok'] ? '✅' : '❌' ?></span>
      <div>
        <div class="check-label"><?= htmlspecialchars($r['label']) ?></div>
        <?php if ($r['detail']): ?>
        <div class="check-detail"><?= nl2br(htmlspecialchars($r['detail'])) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="warn">
    ⚠ Security notice: Delete or rename <code>setup_check.php</code> from your server after confirming all checks pass.
    This page reveals configuration details that should not be publicly accessible.
  </div>
</div>
</body>
</html>
