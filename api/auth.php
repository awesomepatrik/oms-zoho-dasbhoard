<?php
/**
 * Auth API — login, logout, forgot-password, reset-password.
 *
 * POST api/auth.php?action=login            { email, password, csrf_token }
 * POST api/auth.php?action=logout            { csrf_token }
 * POST api/auth.php?action=forgot_password   { email, csrf_token }
 * POST api/auth.php?action=reset_password    { token, password, csrf_token }
 * GET  api/auth.php?action=csrf_token        (no body — fetch a token for a freshly loaded form)
 */

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';

header('Content-Type: application/json; charset=utf-8');

Auth::boot();

$action = $_GET['action'] ?? '';

if ($action === 'csrf_token') {
    json_response(['csrf_token' => Auth::csrfToken()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_out('Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (!Auth::verifyCsrf($input['csrf_token'] ?? null)) {
    error_out('Invalid or expired form. Refresh the page and try again.', 419);
}

switch ($action) {

    case 'login':
        $email    = (string)($input['email'] ?? '');
        $password = (string)($input['password'] ?? '');
        if ($email === '' || $password === '') {
            error_out('Email and password are required.', 400);
        }
        $result = Auth::attempt($email, $password);
        if (!$result['ok']) {
            error_out($result['error'], 401);
        }
        json_response(['success' => true, 'user' => $result['user']]);
        break;

    case 'logout':
        Auth::logout();
        json_response(['success' => true]);
        break;

    case 'forgot_password':
        $email = (string)($input['email'] ?? '');
        if ($email !== '') {
            Auth::requestPasswordReset($email);
        }
        // Always the same response — don't reveal whether the email exists.
        json_response(['success' => true, 'message' => 'If that email is registered, a reset link has been sent.']);
        break;

    case 'reset_password':
        $token    = (string)($input['token'] ?? '');
        $password = (string)($input['password'] ?? '');
        if ($token === '' || $password === '') {
            error_out('Missing token or password.', 400);
        }
        $result = Auth::resetPassword($token, $password);
        if (!$result['ok']) {
            error_out($result['error'], 400);
        }
        json_response(['success' => true]);
        break;

    default:
        error_out('Unknown action.', 400);
}
