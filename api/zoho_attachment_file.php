<?php
/**
 * Proxy a file download/view from a Zoho Books contact's attachments.
 * GET ?contact_id=...&document_id=...
 *
 * Zoho Books has no GET API for contact attachments. Files are cached
 * locally at uploads/{contact_id}/{document_id}.{ext} on upload.
 */

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/ZohoOAuth.php';

Auth::requireLoginApi();

$contactId  = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['contact_id']  ?? '');
$documentId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['document_id'] ?? '');

if ($contactId === '' || $documentId === '') {
    http_response_code(400);
    exit;
}

// Serve from local cache (populated on upload since Zoho Books has no download API for contacts).
$uploadDir = realpath(__DIR__ . '/../uploads/' . $contactId);
if ($uploadDir) {
    $matches = glob($uploadDir . DIRECTORY_SEPARATOR . $documentId . '.*');
    if (!empty($matches) && is_file($matches[0])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($matches[0]);
        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=3600');
        readfile($matches[0]);
        exit;
    }
}

// No local cache — this document was uploaded before caching was introduced.
http_response_code(404);
