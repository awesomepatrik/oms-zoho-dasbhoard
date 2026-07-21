<?php
/**
 * One-off CLI bootstrap for the very first admin account.
 * (Every subsequent user — admin or staff — should be created via the
 * User Management screen, not this script.)
 *
 * Usage:
 *   php bin/create_admin.php "Full Name" admin@example.org "at-least-12-char-password"
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/../lib/Database.php';

[, $name, $email, $password] = $argv + [null, null, null, null];

if (!$name || !$email || !$password) {
    fwrite(STDERR, "Usage: php bin/create_admin.php \"Full Name\" email@example.org \"password\"\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Error: '{$email}' is not a valid email address.\n");
    exit(1);
}
if (strlen($password) < 12) {
    fwrite(STDERR, "Error: password must be at least 12 characters.\n");
    exit(1);
}

$email = trim(strtolower($email));

$stmt = db()->prepare('SELECT id FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
if ($stmt->fetch()) {
    fwrite(STDERR, "Error: a user with that email already exists.\n");
    exit(1);
}

$stmt = db()->prepare(
    'INSERT INTO users (name, email, password_hash, role, is_active)
     VALUES (:name, :email, :hash, \'admin\', 1)'
);
$stmt->execute([
    'name'  => trim($name),
    'email' => $email,
    'hash'  => password_hash($password, PASSWORD_BCRYPT),
]);

echo "Admin account created: {$email}\n";
