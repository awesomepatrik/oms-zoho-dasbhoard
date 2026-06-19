<?php
/**
 * Step 1 of OAuth2 flow: redirect the browser to Zoho's consent screen.
 *
 * Visit this page to (re)authorise the dashboard.
 * After the admin grants permissions, Zoho redirects to auth/callback.php.
 */

require_once __DIR__ . '/../lib/ZohoOAuth.php';

$oauth   = new ZohoOAuth();
$authUrl = $oauth->getAuthUrl();

header('Location: ' . $authUrl);
exit;
