<?php
/**
 * Return the list of uploaded attachments for a given item.
 */

require_once __DIR__ . '/../lib/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$itemId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['item_id'] ?? '');
if ($itemId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing item_id']);
    exit;
}

$metaFile = __DIR__ . '/../uploads/' . $itemId . '/_meta.json';
$meta     = [];
if (file_exists($metaFile)) {
    $meta = json_decode(file_get_contents($metaFile), true) ?? [];
}

// Newest first
usort($meta, fn($a, $b) => strcmp($b['uploaded_at'], $a['uploaded_at']));

echo json_encode(['attachments' => $meta]);
