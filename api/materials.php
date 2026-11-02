<?php
// ============================================================
// materials.php — upload, list, delete, select material
// ============================================================
require_once __DIR__ . '/config.php';
start_session();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

match ($action) {
    'upload'     => handle_upload(),
    'list'       => handle_list(),
    'delete'     => handle_delete(),
    'save_text'  => handle_save_text(),
    'get_text'   => handle_get_text(),
    default      => json_error('Unknown action', 404),
};

// ─── Upload ──────────────────────────────────────────────────
function handle_upload(): void {
    $user = require_auth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed', 405);
    }

    if (empty($_FILES['file'])) {
        json_error('No file uploaded.');
    }

    $file = $_FILES['file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_error('Upload error code: ' . $file['error']);
    }

    // Size check
    if ($file['size'] > MAX_FILE_SIZE) {
        json_error('File exceeds the 20 MB limit.');
    }

    // Type check
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if ($mimeType !== 'application/pdf') {
        json_error('Only PDF files are accepted.');
    }

    // Save to uploads/ with a unique name
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $ext      = 'pdf';
    $saveName = uniqid('mat_', true) . '.' . $ext;
    $savePath = UPLOAD_DIR . $saveName;

    if (!move_uploaded_file($file['tmp_name'], $savePath)) {
        json_error('Failed to save file.', 500);
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO materials (user_id, filename, original_name, file_size, status)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $user['id'],
        $saveName,
        htmlspecialchars($file['name'], ENT_QUOTES),
        $file['size'],
        'processing',
    ]);
    $material_id = (int) $pdo->lastInsertId();

    json_ok([
        'id'            => $material_id,
        'filename'      => $saveName,
        'original_name' => $file['name'],
        'file_size'     => $file['size'],
        'status'        => 'processing',
        'uploaded_at'   => date('Y-m-d H:i:s'),
    ]);
}

// ─── List ─────────────────────────────────────────────────────
function handle_list(): void {
    $user = require_auth();
    $pdo  = db();
    $stmt = $pdo->prepare(
        'SELECT id, original_name, file_size, page_count, status, uploaded_at
         FROM materials
         WHERE user_id = ?
         ORDER BY uploaded_at DESC'
    );
    $stmt->execute([$user['id']]);
    json_ok($stmt->fetchAll());
}

// ─── Delete ──────────────────────────────────────────────────
function handle_delete(): void {
    $user = require_auth();
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        json_error('Method not allowed', 405);
    }
    $body = get_json_body();
    $id   = (int)($body['id'] ?? 0);
    if ($id <= 0) {
        json_error('Invalid material id.');
    }

    $pdo  = db();
    // Verify ownership
    $stmt = $pdo->prepare('SELECT id, filename FROM materials WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Material not found.', 404);
    }

    // Remove file from disk
    $path = UPLOAD_DIR . $row['filename'];
    if (file_exists($path)) {
        @unlink($path);
    }

    $pdo->prepare('DELETE FROM materials WHERE id = ?')->execute([$id]);
    json_ok(['deleted' => $id]);
}

// ─── Save extracted text (called from JS after PDF.js parsing) ──
function handle_save_text(): void {
    $user = require_auth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed', 405);
    }
    $body       = get_json_body();
    $id         = (int)($body['id'] ?? 0);
    $text       = $body['text'] ?? '';
    $page_count = (int)($body['page_count'] ?? 0);

    if ($id <= 0) {
        json_error('Invalid material id.');
    }

    $pdo  = db();
    $stmt = $pdo->prepare('SELECT id FROM materials WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['id']]);
    if (!$stmt->fetch()) {
        json_error('Material not found.', 404);
    }

    $pdo->prepare(
        'UPDATE materials SET extracted_text = ?, page_count = ?, status = ? WHERE id = ?'
    )->execute([
        $text,
        $page_count > 0 ? $page_count : null,
        'ready',
        $id,
    ]);

    json_ok(['id' => $id, 'status' => 'ready']);
}

// ─── Get extracted text ─────────────────────────────────────
function handle_get_text(): void {
    $user = require_auth();
    $id   = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_error('Invalid material id.');
    }
    $pdo  = db();
    $stmt = $pdo->prepare(
        'SELECT extracted_text, status FROM materials WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$id, $user['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Material not found.', 404);
    }
    json_ok($row);
}
