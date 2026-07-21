<?php
/**
 * User management — admin only. Create staff/admin accounts, edit role
 * and active status. New accounts get an emailed "set your password" link
 * (see Auth::createUser()) rather than a password typed in by the admin.
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/Auth.php';

Auth::requireLogin();
Auth::requireRole('admin');

$csrfToken = Auth::csrfToken();
$me        = Auth::user();
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management — Mission Agency Dashboard</title>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"
            integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
            crossorigin="anonymous"></script>
    <link rel="stylesheet" href="/oms-zoho-dashboard/assets/css/dashboard.css">
</head>
<body>

    <header class="site-header">
        <a href="/oms-zoho-dashboard/index.php" class="btn-back">← Dashboard</a>
        <h1>User Management</h1>
        <nav>
            <span class="site-header-user"><?= esc($me['name'] ?? '') ?></span>
            <a href="/oms-zoho-dashboard/account/logout.php" class="btn-reauth">Log out</a>
        </nav>
    </header>

    <main class="page-user-mgmt">
        <input type="hidden" id="csrf-token" value="<?= esc($csrfToken) ?>">
        <input type="hidden" id="current-user-id" value="<?= (int)($me['id'] ?? 0) ?>">

        <section class="detail-card">
            <div class="um-header-row">
                <h2>Users</h2>
                <button id="btn-new-user" class="auth-submit um-new-btn" type="button">+ New user</button>
            </div>
            <div id="um-error" class="auth-error" hidden></div>
            <div id="um-table-wrap"><p class="loading">Loading…</p></div>
        </section>
    </main>

    <!-- Create/edit user modal -->
    <div id="um-modal" class="um-modal" hidden>
        <div class="um-modal-inner">
            <h3 id="um-modal-title">New user</h3>
            <form id="um-form" class="auth-form" novalidate>
                <input type="hidden" id="um-form-id" name="id">

                <label class="auth-label" for="um-form-name">Name</label>
                <input class="auth-input" type="text" id="um-form-name" name="name" required>

                <label class="auth-label" for="um-form-email">Email</label>
                <input class="auth-input" type="email" id="um-form-email" name="email" required>

                <label class="auth-label" for="um-form-role">Role</label>
                <select class="auth-input" id="um-form-role" name="role">
                    <option value="staff">Staff</option>
                    <option value="admin">Admin</option>
                </select>

                <label class="auth-label um-checkbox-label" id="um-active-row">
                    <input type="checkbox" id="um-form-active" name="is_active" checked>
                    Active
                </label>

                <p class="auth-error" id="um-form-error" hidden></p>

                <div class="um-modal-actions">
                    <button type="button" id="um-cancel-btn" class="btn-msr-action btn-msr-cancel">Cancel</button>
                    <button type="submit" class="auth-submit">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script src="/oms-zoho-dashboard/assets/js/user-management.js"></script>
</body>
</html>
