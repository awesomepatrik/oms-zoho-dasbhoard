<?php
/**
 * Session-based auth: login, logout, roles, CSRF, and password reset.
 *
 * Storage is the `users` / `password_resets` / `login_attempts` tables
 * (see zoho-dashboard-config/schema.sql), reached via lib/Database.php.
 * Separate from ZohoOAuth.php, which authenticates the *server* to Zoho —
 * this authenticates a *person* to the dashboard.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Mailer.php';

class Auth
{
    const RESET_TOKEN_TTL_MINUTES  = 60;
    const MAX_ATTEMPTS_PER_WINDOW  = 8;
    const ATTEMPT_WINDOW_MINUTES   = 15;
    const MIN_PASSWORD_LENGTH      = 12;

    private static bool $booted = false;

    /** Start the session with hardened cookie params. Safe to call repeatedly. */
    public static function boot(): void
    {
        if (self::$booted) return;
        self::$booted = true;

        if (session_status() === PHP_SESSION_ACTIVE) return;

        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    // -------------------------------------------------------------------
    // Current-user state
    // -------------------------------------------------------------------

    public static function check(): bool
    {
        self::boot();
        return !empty($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        self::boot();
        return $_SESSION['user_id'] ?? null;
    }

    public static function role(): ?string
    {
        self::boot();
        return $_SESSION['user_role'] ?? null;
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    public static function isViewer(): bool
    {
        return self::role() === 'viewer';
    }

    /** Full current-user row (id, name, email, role) or null. Re-reads DB. */
    public static function user(): ?array
    {
        $id = self::id();
        if ($id === null) return null;
        return self::findById($id);
    }

    // -------------------------------------------------------------------
    // Page/route guards
    // -------------------------------------------------------------------

    /** Call at the top of a page. Redirects to login if not authenticated. */
    public static function requireLogin(): void
    {
        self::boot();
        if (self::check()) return;

        $returnTo = $_SERVER['REQUEST_URI'] ?? '/oms-zoho-dashboard/index.php';
        header('Location: /oms-zoho-dashboard/account/login.php?return_to=' . urlencode($returnTo));
        exit;
    }

    /** Call at the top of a page after requireLogin(). 403s non-matching roles. */
    public static function requireRole(string $role): void
    {
        if (self::role() === $role) return;
        http_response_code(403);
        echo '<h1>403 Forbidden</h1><p>You do not have access to this page.</p>';
        exit;
    }

    /** Call at the top of an api/*.php endpoint. JSON 401 if not authenticated. */
    public static function requireLoginApi(): void
    {
        self::boot();
        if (self::check()) return;
        json_response(['error' => 'auth_required'], 401);
    }

    /** Call at the top of an admin-only api/*.php endpoint. */
    public static function requireRoleApi(string $role): void
    {
        self::requireLoginApi();
        if (self::role() === $role) return;
        json_response(['error' => 'forbidden'], 403);
    }

    /** Call at the top of a write (create/update/delete) api/*.php endpoint. JSON 403 for the read-only Viewer role. */
    public static function requireEditApi(): void
    {
        self::requireLoginApi();
        if (self::isViewer()) {
            json_response(['error' => 'forbidden'], 403);
        }
    }

    // -------------------------------------------------------------------
    // CSRF
    // -------------------------------------------------------------------

    public static function csrfToken(): string
    {
        self::boot();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::boot();
        return $token !== null
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    // -------------------------------------------------------------------
    // Login / logout
    // -------------------------------------------------------------------

    /**
     * @return array{ok:bool, error?:string, user?:array}
     */
    public static function attempt(string $email, string $password): array
    {
        self::boot();
        $email = trim(strtolower($email));
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (self::tooManyAttempts($email, $ip)) {
            return ['ok' => false, 'error' => 'Too many login attempts. Try again in a few minutes.'];
        }

        $row = self::findByEmail($email, true);

        $genericError = 'Incorrect email or password, or the account is not yet active.';

        if (!$row || $row['is_active'] != 1 || $row['password_hash'] === null
            || !password_verify($password, $row['password_hash'])) {
            self::recordFailedAttempt($email, $ip);
            return ['ok' => false, 'error' => $genericError];
        }

        unset($row['password_hash']);
        self::login($row);
        return ['ok' => true, 'user' => $row];
    }

    public static function login(array $userRow): void
    {
        self::boot();
        session_regenerate_id(true);
        $_SESSION['user_id']   = (int)$userRow['id'];
        $_SESSION['user_role'] = $userRow['role'];
    }

    public static function logout(): void
    {
        self::boot();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie('PHPSESSID', '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    private static function tooManyAttempts(string $email, string $ip): bool
    {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE (email = :email OR ip = :ip)
               AND attempted_at > (NOW() - INTERVAL :mins MINUTE)'
        );
        $stmt->execute(['email' => $email, 'ip' => $ip, 'mins' => self::ATTEMPT_WINDOW_MINUTES]);
        return (int)$stmt->fetchColumn() >= self::MAX_ATTEMPTS_PER_WINDOW;
    }

    private static function recordFailedAttempt(string $email, string $ip): void
    {
        $stmt = db()->prepare('INSERT INTO login_attempts (email, ip) VALUES (:email, :ip)');
        $stmt->execute(['email' => $email, 'ip' => $ip]);
    }

    // -------------------------------------------------------------------
    // Lookups
    // -------------------------------------------------------------------

    public static function findByEmail(string $email, bool $withPasswordHash = false): ?array
    {
        $cols = $withPasswordHash
            ? 'id, name, email, password_hash, role, is_active'
            : 'id, name, email, role, is_active';
        $stmt = db()->prepare("SELECT {$cols} FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => trim(strtolower($email))]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare('SELECT id, name, email, role, is_active, created_at FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function listUsers(): array
    {
        $stmt = db()->query('SELECT id, name, email, role, is_active, created_at FROM users ORDER BY name');
        return $stmt->fetchAll();
    }

    // -------------------------------------------------------------------
    // Admin-provisioned user creation
    // -------------------------------------------------------------------

    /**
     * Admin creates a user shell (no password yet) and emails them a
     * "set up your password" link that reuses the reset-password flow.
     *
     * @return array{ok:bool, error?:string, user?:array}
     */
    public static function createUser(string $name, string $email, string $role): array
    {
        $name  = trim($name);
        $email = trim(strtolower($email));

        if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'A valid name and email are required.'];
        }
        if (!in_array($role, ['admin', 'staff', 'viewer'], true)) {
            return ['ok' => false, 'error' => 'Invalid role.'];
        }
        if (self::findByEmail($email)) {
            return ['ok' => false, 'error' => 'A user with that email already exists.'];
        }

        $stmt = db()->prepare(
            'INSERT INTO users (name, email, password_hash, role, is_active) VALUES (:name, :email, NULL, :role, 1)'
        );
        $stmt->execute(['name' => $name, 'email' => $email, 'role' => $role]);
        $userId = (int)db()->lastInsertId();

        self::sendPasswordSetupEmail($userId, $email, $name, true);

        return ['ok' => true, 'user' => self::findById($userId)];
    }

    /**
     * @return array{ok:bool, error?:string}
     */
    public static function updateUser(int $id, string $name, string $email, string $role, bool $isActive): array
    {
        $name  = trim($name);
        $email = trim(strtolower($email));

        if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'A valid name and email are required.'];
        }
        if (!in_array($role, ['admin', 'staff', 'viewer'], true)) {
            return ['ok' => false, 'error' => 'Invalid role.'];
        }

        $existing = self::findByEmail($email);
        if ($existing && (int)$existing['id'] !== $id) {
            return ['ok' => false, 'error' => 'Another user already has that email.'];
        }

        $stmt = db()->prepare(
            'UPDATE users SET name = :name, email = :email, role = :role, is_active = :active WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name, 'email' => $email, 'role' => $role,
            'active' => $isActive ? 1 : 0, 'id' => $id,
        ]);
        return ['ok' => true];
    }

    /**
     * @return array{ok:bool, error?:string}
     */
    public static function deleteUser(int $id): array
    {
        if (!self::findById($id)) {
            return ['ok' => false, 'error' => 'User not found.'];
        }
        db()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
        return ['ok' => true];
    }

    // -------------------------------------------------------------------
    // Password reset (also used as the initial "set your password" step)
    // -------------------------------------------------------------------

    public static function sendPasswordSetupEmail(int $userId, string $email, string $name, bool $isNewAccount = false): void
    {
        $token = bin2hex(random_bytes(32));
        $stmt  = db()->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, NOW() + INTERVAL :mins MINUTE)'
        );
        $stmt->execute([
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'mins'       => self::RESET_TOKEN_TTL_MINUTES,
        ]);

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $link   = "{$scheme}://{$host}/oms-zoho-dashboard/account/reset-password.php?token={$token}";

        $subject = $isNewAccount ? 'Set up your Mission Agency Dashboard account' : 'Reset your Mission Agency Dashboard password';
        $body    = $isNewAccount
            ? "Hi {$name},\n\nAn administrator created a Mission Agency Dashboard account for you.\n"
            : "Hi {$name},\n\nWe received a request to reset your Mission Agency Dashboard password.\n";
        $body .= "Set your password using the link below (expires in " . self::RESET_TOKEN_TTL_MINUTES . " minutes):\n\n{$link}\n\n"
               . "If you didn't expect this, you can ignore this email.\n";

        mailer_send($email, $name, $subject, $body);
    }

    /** Always returns true — callers must show a generic message either way to avoid email enumeration. */
    public static function requestPasswordReset(string $email): bool
    {
        $user = self::findByEmail($email);
        if ($user && $user['is_active'] == 1) {
            self::sendPasswordSetupEmail((int)$user['id'], $user['email'], $user['name'], false);
        }
        return true;
    }

    /**
     * @return array{ok:bool, error?:string}
     */
    public static function resetPassword(string $token, string $newPassword): array
    {
        if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            return ['ok' => false, 'error' => 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.'];
        }

        $tokenHash = hash('sha256', $token);
        $stmt = db()->prepare(
            'SELECT id, user_id FROM password_resets
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $reset = $stmt->fetch();
        if (!$reset) {
            return ['ok' => false, 'error' => 'This link is invalid or has expired. Request a new one.'];
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
                ->execute(['hash' => $hash, 'id' => $reset['user_id']]);
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id')
                ->execute(['id' => $reset['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('resetPassword failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Something went wrong. Please try again.'];
        }

        return ['ok' => true];
    }
}
