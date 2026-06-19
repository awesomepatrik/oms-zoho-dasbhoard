<?php
/**
 * Delete a single attachment and remove it from the metadata.
 * Expects JSON body: { "item_id": "...", "file_id": "..." }
 */

require_once __DIR__ . '/../lib/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$itemId = preg_replace('/[^a-zA-Z0-9_\-]/', '',    $body['item_id'] ?? '');
$fileId = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $body['file_id'] ?? '');

if ($itemId === '' || $fileId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing item_id or file_id']);
    exit;
}

$uploadDir = realpath(__DIR__ . '/../uploads/' . $itemId . '/');
if (!$uploadDir) {
    http_response_code(404);
    echo json_encode(['error' => 'Item not found']);
    exit;
}

$filePath    = $uploadDir . DIRECTORY_SEPARATOR . $fileId;
$realFilePath = realpath($filePath);

// Prevent path traversal: resolved path must start with the upload dir
if (!$realFilePath || strpos($realFilePath, $uploadDir) !== 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if (file_exists($realFilePath)) {
    unlink($realFilePath);
}

$metaFile = $uploadDir . DIRECTORY_SEPARATOR . '_meta.json';
if (file_exists($metaFile)) {
    $meta = json_decode(file_get_contents($metaFile), true) ?? [];
    $meta = array_values(array_filter($meta, fn($f) => $f['id'] !== $fileId));
    file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

echo json_encode(['success' => true]);
