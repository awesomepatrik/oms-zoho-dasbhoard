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
    'redirect_uri'  => 'http://localhost:8080/oms-zoho-dashboard/auth/callback.php',

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

    // -------------------------------------------------------------------------
    // User login database (email/password auth, roles, password resets)
    // -------------------------------------------------------------------------
    'db_host'    => 'localhost',
    'db_name'    => 'oms_zoho_dashboard',
    'db_user'    => 'root',
    'db_pass'    => '',
    'db_charset' => 'utf8mb4',

    // -------------------------------------------------------------------------
    // Outbound mail (password reset / account setup emails) — sent via SMTP
    // (see lib/Mailer.php, PHPMailer). For Gmail/Google Workspace:
    //   - smtp_host: smtp.gmail.com, smtp_port: 587, smtp_encryption: 'tls'
    //   - smtp_username: your full Gmail address
    //   - smtp_password: an App Password, NOT your normal Google password —
    //     generate one at https://myaccount.google.com/apppasswords
    //     (requires 2-Step Verification to be enabled on the account)
    //   - mail_from: Gmail silently rewrites the From header to the
    //     authenticated account unless it's a verified "Send As" alias, so
    //     keep this the same as smtp_username unless you've set one up.
    // -------------------------------------------------------------------------
    'smtp_host'       => 'smtp.gmail.com',
    'smtp_port'       => 587,
    'smtp_encryption' => 'tls', // 'tls' (port 587) or 'ssl' (port 465)
    'smtp_username'   => 'YOUR_GMAIL_ADDRESS',
    'smtp_password'   => 'YOUR_APP_PASSWORD',
    'mail_from'       => 'YOUR_GMAIL_ADDRESS',
    'mail_from_name'  => 'Mission Agency Dashboard',

];
