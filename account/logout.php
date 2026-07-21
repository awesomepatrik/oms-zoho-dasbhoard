<?php
/**
 * Logout — destroys the session and returns to the login page.
 * A tiny same-origin POST form (submitted automatically) is used instead of
 * a plain GET link so logout can't be triggered by a third-party page.
 */
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';

Auth::boot();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::verifyCsrf($_POST['csrf_token'] ?? null)) {
        Auth::logout();
    }
    header('Location: /oms-zoho-dashboard/account/login.php');
    exit;
}

$csrfToken = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="en-AU">
<head><meta charset="UTF-8"><title>Signing out…</title></head>
<body>
    <form id="logout-form" method="POST" action="/oms-zoho-dashboard/account/logout.php">
        <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
    </form>
    <script>document.getElementById('logout-form').submit();</script>
</body>
</html>
