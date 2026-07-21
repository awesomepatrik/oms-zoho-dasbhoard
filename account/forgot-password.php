<?php
/**
 * Forgot-password page — emails a reset link if the address is registered.
 * Always shows the same confirmation, regardless of whether the email exists.
 */
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';

Auth::boot();

if (Auth::check()) {
    header('Location: /oms-zoho-dashboard/index.php');
    exit;
}

$csrfToken = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot password — Mission Agency Dashboard</title>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"
            integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
            crossorigin="anonymous"></script>
    <link rel="stylesheet" href="/oms-zoho-dashboard/assets/css/dashboard.css">
</head>
<body class="auth-body">

    <main class="auth-card">
        <h1 class="auth-title">Forgot your password?</h1>
        <p class="auth-subtitle">Enter your email and we'll send you a reset link.</p>

        <form id="forgot-form" class="auth-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">

            <label class="auth-label" for="email">Email</label>
            <input class="auth-input" type="email" id="email" name="email" autocomplete="username" required autofocus>

            <p class="auth-error" id="auth-error" hidden></p>
            <p class="auth-success" id="auth-success" hidden></p>

            <button class="auth-submit" type="submit">Send reset link</button>
        </form>

        <p class="auth-footnote">
            <a href="/oms-zoho-dashboard/account/login.php">Back to sign in</a>
        </p>
    </main>

    <script src="/oms-zoho-dashboard/assets/js/auth.js"></script>
</body>
</html>
