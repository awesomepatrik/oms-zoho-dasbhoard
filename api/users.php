<?php
/**
 * User management API — admin only.
 *
 * GET  api/users.php?action=list
 * POST api/users.php?action=create  { name, email, role, csrf_token }
 * POST api/users.php?action=update  { id, name, email, role, is_active, csrf_token }
 * POST api/users.php?action=delete  { id, csrf_token }
 */

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';

header('Content-Type: application/json; charset=utf-8');

Auth::requireRoleApi('admin');

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    json_response(['data' => Auth::listUsers()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_out('Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (!Auth::verifyCsrf($input['csrf_token'] ?? null)) {
    error_out('Invalid or expired form. Refresh the page and try again.', 419);
}

switch ($action) {

    case 'create':
        $name  = (string)($input['name']  ?? '');
        $email = (string)($input['email'] ?? '');
        $role  = (string)($input['role']  ?? 'staff');

        $result = Auth::createUser($name, $email, $role);
        if (!$result['ok']) {
            error_out($result['error'], 400);
        }
        json_response(['success' => true, 'user' => $result['user']]);
        break;

    case 'update':
        $id       = (int)($input['id'] ?? 0);
        $name     = (string)($input['name']  ?? '');
        $email    = (string)($input['email'] ?? '');
        $role     = (string)($input['role']  ?? 'staff');
        $isActive = !empty($input['is_active']);

        if ($id <= 0) {
            error_out('Missing user id.', 400);
        }
        if ($id === Auth::id() && ($role !== 'admin' || !$isActive)) {
            error_out('You cannot remove your own admin access or deactivate your own account.', 400);
        }

        $result = Auth::updateUser($id, $name, $email, $role, $isActive);
        if (!$result['ok']) {
            error_out($result['error'], 400);
        }
        json_response(['success' => true, 'user' => Auth::findById($id)]);
        break;

    case 'delete':
        $id = (int)($input['id'] ?? 0);

        if ($id <= 0) {
            error_out('Missing user id.', 400);
        }
        if ($id === Auth::id()) {
            error_out('You cannot remove your own account.', 400);
        }

        $result = Auth::deleteUser($id);
        if (!$result['ok']) {
            error_out($result['error'], 400);
        }
        json_response(['success' => true]);
        break;

    default:
        error_out('Unknown action.', 400);
}
