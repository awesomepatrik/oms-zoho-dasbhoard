<?php
/**
 * List attachments for a Zoho Books contact.
 * GET ?contact_id=...
 *
 * Zoho Books does not expose GET /contacts/{id}/attachment for listing —
 * the documents array lives inside the contact detail response instead.
 * This endpoint fetches the contact detail fresh (no cache) so the list
 * reflects the current state immediately after an upload or delete.
 *
 * Returns: { "documents": [ { document_id, file_name, file_type, file_size, uploaded_time, ... } ] }
 */

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/ZohoOAuth.php';

header('Content-Type: application/json; charset=utf-8');

$contactId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['contact_id'] ?? '');
if ($contactId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing contact_id']);
    exit;
}

try {
    $oauth = new ZohoOAuth();
    $token = $oauth->getValidAccessToken();
} catch (ZohoAuthException $e) {
    http_response_code(401);
    echo json_encode(['error' => 'auth_required']);
    exit;
}

$cfg     = get_config();
$orgId   = $cfg['books_org_id'];
$baseUrl = rtrim($cfg['books_api_base'], '/');
$url     = "{$baseUrl}/contacts/" . rawurlencode($contactId)
         . '?' . http_build_query(['organization_id' => $orgId]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Zoho-oauthtoken ' . $token,
        'Accept: application/json',
    ],
]);

$body     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    error_log("Zoho contact detail (for attachments) cURL error: {$curlErr}");
    http_response_code(502);
    echo json_encode(['error' => 'Request failed']);
    exit;
}

$decoded = json_decode($body, true);

if ($httpCode !== 200 || !is_array($decoded) || ($decoded['code'] ?? -1) !== 0) {
    $zohoMsg = is_array($decoded) ? ($decoded['message'] ?? null) : null;
    $errMsg  = $zohoMsg ?: "Zoho API error (HTTP {$httpCode})";
    error_log("Zoho contact detail (for attachments) error (HTTP {$httpCode}): {$body}");
    http_response_code(502);
    echo json_encode(['error' => $errMsg]);
    exit;
}

$documents = $decoded['contact']['documents'] ?? [];
if (!empty($documents)) {
    error_log('Zoho contact document fields: ' . implode(', ', array_keys($documents[0])));
}
echo json_encode(['documents' => $documents]);
