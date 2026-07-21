<?php
/**
 * Delete an attachment from a Zoho Books contact.
 * POST JSON: { "contact_id": "...", "document_id": "..." }
 */

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/ZohoOAuth.php';

header('Content-Type: application/json; charset=utf-8');

Auth::requireLoginApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$contactId  = preg_replace('/[^a-zA-Z0-9_\-]/', '', $body['contact_id']  ?? '');
$documentId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $body['document_id'] ?? '');

if ($contactId === '' || $documentId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing contact_id or document_id']);
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
$base = "{$baseUrl}/contacts/" . rawurlencode($contactId);

// Zoho Books does not document a DELETE endpoint for contact attachments by document_id
// in the URL path. Try multiple patterns in order.
$urlsToTry = [
    $base . '/attachment/'  . rawurlencode($documentId) . '?' . http_build_query(['organization_id' => $orgId]),
    $base . '/attachments/' . rawurlencode($documentId) . '?' . http_build_query(['organization_id' => $orgId]),
    $base . '/attachment?'  . http_build_query(['organization_id' => $orgId, 'document_id' => $documentId]),
];

$curlOpts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'DELETE',
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Zoho-oauthtoken ' . $token,
        'Accept: application/json',
    ],
];

$body     = '';
$httpCode = 0;
$decoded  = null;

foreach ($urlsToTry as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, $curlOpts);
    $body    = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("Zoho contact delete attachment cURL error for {$url}: {$curlErr}");
        continue;
    }

    $decoded = json_decode($body, true);
    if ($httpCode === 200 && is_array($decoded) && ($decoded['code'] ?? -1) === 0) {
        // Remove local cache copy if present
        $uploadDir = realpath(__DIR__ . '/../uploads/' . $contactId);
        if ($uploadDir) {
            foreach (glob($uploadDir . DIRECTORY_SEPARATOR . $documentId . '.*') as $f) {
                @unlink($f);
            }
        }
        echo json_encode(['success' => true]);
        exit;
    }

    error_log("Zoho contact delete attachment error (HTTP {$httpCode}) for {$url}: {$body}");
}

http_response_code(502);
echo json_encode(['error' => (is_array($decoded) ? ($decoded['message'] ?? null) : null) ?: 'Zoho API error']);
