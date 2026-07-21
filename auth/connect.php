<?php
/**
 * Step 1 of OAuth2 flow: redirect the browser to Zoho's consent screen.
 *
 * Visit this page to (re)authorise the dashboard.
 * After the admin grants permissions, Zoho redirects to auth/callback.php.
 */

require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/ZohoOAuth.php';

Auth::requireLogin();
Auth::requireRole('admin'); // (re)connecting Zoho affects the whole app's shared connection

$oauth   = new ZohoOAuth();
$authUrl = $oauth->getAuthUrl();

header('Location: ' . $authUrl);
exit;
