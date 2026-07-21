<?php
/**
 * Handle multi-file uploads for the Flow tab.
 * Files are stored in uploads/{item_id}/ with randomised filenames.
 * Metadata (original name, MIME, size, date) is kept in _meta.json.
 */

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';

header('Content-Type: application/json; charset=utf-8');

Auth::requireLoginApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$itemId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['item_id'] ?? '');
if ($itemId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing item_id']);
    exit;
}

$file = $_FILES['file'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    http_response_code(400);
    echo json_encode(['error' => 'Upload failed', 'upload_error_code' => $code]);
    exit;
}

// 10 MB size limit
if ($file['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'File too large (max 10 MB)']);
    exit;
}

// Validate MIME type from file contents (not browser-supplied type)
$allowedMimes = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain',
    'text/csv',
];

$finfo        = new finfo(FILEINFO_MIME_TYPE);
$detectedMime = $finfo->file($file['tmp_name']);

if (!in_array($detectedMime, $allowedMimes, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'File type not allowed']);
    exit;
}

// Derive extension from detected MIME (safer than trusting file name)
$mimeExtMap = [
    'image/jpeg'        => 'jpg',
    'image/png'         => 'png',
    'image/gif'         => 'gif',
    'image/webp'        => 'webp',
    'application/pdf'   => 'pdf',
    'application/msword'=> 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel'  => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'text/plain'        => 'txt',
    'text/csv'          => 'csv',
];
$ext      = $mimeExtMap[$detectedMime] ?? 'bin';
$storedId = bin2hex(random_bytes(12)) . '.' . $ext;

$uploadDir = __DIR__ . '/../uploads/' . $itemId . '/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not create upload directory']);
    exit;
}

if (!move_uploaded_file($file['tmp_name'], $uploadDir . $storedId)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file']);
    exit;
}

// Update metadata
$metaFile = $uploadDir . '_meta.json';
$meta     = [];
if (file_exists($metaFile)) {
    $meta = json_decode(file_get_contents($metaFile), true) ?? [];
}

$entry  = [
    'id'            => $storedId,
    'original_name' => $file['name'],
    'mime'          => $detectedMime,
    'size'          => (int) $file['size'],
    'uploaded_at'   => date('c'),
];
$meta[] = $entry;
file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode(['success' => true, 'file' => $entry]);
