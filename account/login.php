<?php
/**
 * Login page — email/password authentication.
 * No public signup: accounts are created by an admin via user-management.php.
 */
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';

Auth::boot();

if (Auth::check()) {
    header('Location: /oms-zoho-dashboard/index.php');
    exit;
}

$returnTo = $_GET['return_to'] ?? '/oms-zoho-dashboard/index.php';
if (strpos($returnTo, '/oms-zoho-dashboard/') !== 0) {
    $returnTo = '/oms-zoho-dashboard/index.php'; // guard against open redirect
}

$csrfToken = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in — Mission Agency Dashboard</title>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"
            integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
            crossorigin="anonymous"></script>
    <link rel="stylesheet" href="/oms-zoho-dashboard/assets/css/dashboard.css">
</head>
<body class="auth-body">

    <main class="auth-card">
        <h1 class="auth-title">One Mission Society</h1>
        <p class="auth-subtitle">Sign in to the dashboard</p>

        <form id="login-form" class="auth-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
            <input type="hidden" name="return_to" value="<?= esc($returnTo) ?>">

            <label class="auth-label" for="email">Email</label>
            <input class="auth-input" type="email" id="email" name="email" autocomplete="username" required autofocus>

            <label class="auth-label" for="password">Password</label>
            <input class="auth-input" type="password" id="password" name="password" autocomplete="current-password" required>

            <p class="auth-error" id="auth-error" hidden></p>

            <button class="auth-submit" type="submit">Sign in</button>
        </form>

        <p class="auth-footnote">
            <a href="/oms-zoho-dashboard/account/forgot-password.php">Forgot your password?</a>
        </p>
        <p class="auth-footnote">Don't have an account? Contact your administrator.</p>
    </main>

    <script src="/oms-zoho-dashboard/assets/js/auth.js"></script>
</body>
</html>
