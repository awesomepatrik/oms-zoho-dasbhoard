<?php
/**
 * Zoho Dashboard — Master Configuration Template
 *
 * Copy this file to config.php and fill in your real values.
 * NEVER commit config.php — it contains secrets.
 */

return [

    // -------------------------------------------------------------------------
    // Zoho OAuth2 Credentials
    // Register your app at: https://api-console.zoho.com.au
    // -------------------------------------------------------------------------
    'client_id'     => 'YOUR_CLIENT_ID',
    'client_secret' => 'YOUR_CLIENT_SECRET',
    'redirect_uri'  => 'http://localhost:8080/oms-zoho-dashboard/zoho-dashboard/auth/callback.php',

    // Scopes required by this dashboard
    'scope' => 'ZohoBooks.fullaccess.all,ZohoCRM.modules.ALL,ZohoCRM.users.READ',

    // -------------------------------------------------------------------------
    // Zoho Organisation IDs
    // -------------------------------------------------------------------------
    'books_org_id' => 'YOUR_BOOKS_ORG_ID',

    // -------------------------------------------------------------------------
    // Zoho API Base URLs (Australian data centre)
    // -------------------------------------------------------------------------
    'books_api_base' => 'https://www.zohoapis.com.au/books/v3',
    'crm_api_base'   => 'https://www.zohoapis.com.au/crm/v3',
    'auth_base'      => 'https://accounts.zoho.com.au/oauth/v2',

    // -------------------------------------------------------------------------
    // File Paths (absolute — adjust if you move this config directory)
    // -------------------------------------------------------------------------
    'token_file' => __DIR__ . '/tokens.json',
    'cache_dir'  => __DIR__ . '/cache',

    // -------------------------------------------------------------------------
    // Cache TTL (seconds). Minimum 3600 (1 hour) per project requirements.
    // -------------------------------------------------------------------------
    'cache_ttl' => 3600,

];
