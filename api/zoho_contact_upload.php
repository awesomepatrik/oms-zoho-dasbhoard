<?php
/**
 * Upload a file to a Zoho Books contact's attachments.
 * POST multipart/form-data: contact_id + file
 * Zoho Books field name for the file is 'attachment'.
 */

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/ZohoOAuth.php';

header('Content-Type: application/json; charset=utf-8');

Auth::requireEditApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$contactId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['contact_id'] ?? '');
if ($contactId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing contact_id']);
    exit;
}

$file = $_FILES['file'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload failed', 'upload_error_code' => $file['error'] ?? UPLOAD_ERR_NO_FILE]);
    exit;
}

if ($file['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'File too large (max 10 MB)']);
    exit;
}

$allowedMimes = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain', 'text/csv',
];

$finfo        = new finfo(FILEINFO_MIME_TYPE);
$detectedMime = $finfo->file($file['tmp_name']);

if (!in_array($detectedMime, $allowedMimes, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'File type not allowed']);
    exit;
}

try {
    $oauth = new ZohoOAuth();
    $token = $oauth->getValidAccessToken();
} catch (ZohoAuthException $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication error']);
    exit;
}

$cfg     = get_config();
$orgId   = $cfg['books_org_id'];
$baseUrl = rtrim($cfg['books_api_base'], '/');
$url     = "{$baseUrl}/contacts/" . rawurlencode($contactId) . '/attachment'
         . '?' . http_build_query(['organization_id' => $orgId]);

$cFile = new CURLFile($file['tmp_name'], $detectedMime, basename($file['name']));

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => ['attachment' => $cFile],
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Zoho-oauthtoken ' . $token],
]);

$body     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    error_log("Zoho contact upload cURL error: {$curlErr}");
    http_response_code(502);
    echo json_encode(['error' => 'Upload failed']);
    exit;
}

$decoded = json_decode($body, true);

// Zoho returns 201 Created for successful uploads (not 200)
if (!in_array($httpCode, [200, 201]) || !is_array($decoded) || ($decoded['code'] ?? -1) !== 0) {
    $zohoMsg = is_array($decoded) ? ($decoded['message'] ?? null) : null;
    $errMsg  = $zohoMsg ?: "Zoho API error (HTTP {$httpCode})";
    error_log("Zoho contact upload error (HTTP {$httpCode}): {$body}");
    http_response_code(502);
    echo json_encode(['error' => $errMsg]);
    exit;
}

// Zoho returns all documents for the contact; the newly uploaded one is last.
$allDocs = $decoded['documents'] ?? (isset($decoded['document']) ? [$decoded['document']] : []);
$doc     = !empty($allDocs) ? end($allDocs) : [];
$docId   = $doc['document_id'] ?? '';

// Zoho Books has no GET API for contact attachments, so cache a local copy
// at uploads/{contact_id}/{document_id}.{ext} for serving thumbnails/downloads.
if ($docId !== '') {
    $ext      = $mimeExtMap[$detectedMime] ?? 'bin';
    $cacheDir = __DIR__ . '/../uploads/' . $contactId . '/';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
    copy($file['tmp_name'], $cacheDir . $docId . '.' . $ext);
}

echo json_encode(['success' => true, 'document' => $doc]);
