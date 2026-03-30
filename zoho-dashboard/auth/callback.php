<?php
/**
 * Step 2 of OAuth2 flow: receive the authorisation code from Zoho and
 * exchange it for access + refresh tokens.
 *
 * Zoho redirects here after the user grants (or denies) permissions:
 *   ?code=1000.XXXX&location=au&accounts-server=https://accounts.zoho.com.au
 * or on denial/error:
 *   ?error=access_denied
 */

require_once __DIR__ . '/../lib/ZohoOAuth.php';

// Handle Zoho error responses.
if (!empty($_GET['error'])) {
    $error = htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8');
    http_response_code(400);
    echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'>"
        . "<title>Authorisation Error</title></head><body>"
        . "<h1>Authorisation Error</h1>"
        . "<p>Zoho returned an error: <strong>{$error}</strong></p>"
        . "<p><a href='/oms-zoho-dashboard/zoho-dashboard/auth/connect.php'>Try again</a></p>"
        . "</body></html>";
    exit;
}

// Validate that a code was provided.
if (empty($_GET['code'])) {
    http_response_code(400);
    echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'>"
        . "<title>Missing Code</title></head><body>"
        . "<h1>Missing Authorisation Code</h1>"
        . "<p>No code was returned by Zoho. "
        . "<a href='/oms-zoho-dashboard/zoho-dashboard/auth/connect.php'>Restart authorisation</a>.</p>"
        . "</body></html>";
    exit;
}

$code = $_GET['code'];

try {
    $oauth = new ZohoOAuth();
    $oauth->exchangeCode($code);
} catch (ZohoAuthException $e) {
    error_log('ZohoOAuth callback error: ' . $e->getMessage());
    http_response_code(500);
    echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'>"
        . "<title>Token Exchange Failed</title></head><body>"
        . "<h1>Token Exchange Failed</h1>"
        . "<p>Could not obtain tokens from Zoho. Check the server error log.</p>"
        . "<p><a href='/oms-zoho-dashboard/zoho-dashboard/auth/connect.php'>Try again</a></p>"
        . "</body></html>";
    exit;
}

// Success — tokens stored. Redirect to the dashboard.
header('Location: /oms-zoho-dashboard/zoho-dashboard/');
exit;
