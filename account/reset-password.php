<?php
/**
 * Reset/set-password page. Reached via the link emailed by
 * Auth::sendPasswordSetupEmail() — used both for admin-created accounts
 * (first-time setup) and for "forgot password" resets.
 */
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';

Auth::boot();

$token = (string)($_GET['token'] ?? '');
if ($token === '') {
    header('Location: /oms-zoho-dashboard/account/login.php');
    exit;
}

$csrfToken = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set your password — Mission Agency Dashboard</title>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"
            integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
            crossorigin="anonymous"></script>
    <link rel="stylesheet" href="/oms-zoho-dashboard/assets/css/dashboard.css">
</head>
<body class="auth-body">

    <main class="auth-card">
        <h1 class="auth-title">Set your password</h1>
        <p class="auth-subtitle">Choose a password with at least 12 characters.</p>

        <form id="reset-form" class="auth-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
            <input type="hidden" name="token" value="<?= esc($token) ?>">

            <label class="auth-label" for="password">New password</label>
            <input class="auth-input" type="password" id="password" name="password" autocomplete="new-password" minlength="12" required autofocus>

            <label class="auth-label" for="password_confirm">Confirm password</label>
            <input class="auth-input" type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" minlength="12" required>

            <p class="auth-error" id="auth-error" hidden></p>
            <p class="auth-success" id="auth-success" hidden></p>

            <button class="auth-submit" type="submit">Set password</button>
        </form>
    </main>

    <script src="/oms-zoho-dashboard/assets/js/auth.js"></script>
</body>
</html>
