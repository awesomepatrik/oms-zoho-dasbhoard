<?php
/**
 * update_msr.php — Save an updated MSR custom-field value back to Zoho Books.
 *
 * POST body (JSON):
 *   { "item_id": "...", "field_id": "...", "value": "<div>...</div>" }
 *
 * On success, invalidates the item-detail cache and returns {"success":true}.
 */

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/ZohoOAuth.php';
require_once __DIR__ . '/../lib/ApiCache.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$itemId  = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['item_id']  ?? '');
$fieldId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['field_id'] ?? '');
$value   = $input['value'] ?? '';

if ($itemId === '' || $fieldId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing_params']);
    exit;
}

try {
    $oauth = new ZohoOAuth();
    $token = $oauth->getValidAccessToken();
} catch (ZohoAuthException $e) {
    error_log('update_msr auth error: ' . $e->getMessage());
    http_response_code(401);
    echo json_encode(['error' => 'auth_required']);
    exit;
}

$cfg  = get_config();
$url  = rtrim($cfg['books_api_base'], '/') . '/items/' . rawurlencode($itemId)
      . '?' . http_build_query(['organization_id' => $cfg['books_org_id']]);

$payload = json_encode(
    ['custom_fields' => [['customfield_id' => $fieldId, 'value' => $value]]],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PUT',
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Zoho-oauthtoken ' . $token,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 30,
]);
$resp   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$decoded = json_decode($resp, true) ?? [];

if ($status !== 200 || ($decoded['code'] ?? -1) !== 0) {
    error_log("update_msr error [{$itemId}]: " . ($decoded['message'] ?? $resp));
    http_response_code(502);
    echo json_encode(['error' => 'update_failed', 'message' => $decoded['message'] ?? 'Unknown error']);
    exit;
}

// Invalidate the cached item detail so the next load fetches fresh data.
(new ApiCache('books_item_detail_' . $itemId))->invalidate();

echo json_encode(['success' => true]);
