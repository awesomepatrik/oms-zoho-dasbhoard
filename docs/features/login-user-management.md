# Feature prompt: Login & User Management

**Status: implemented (2026-07-21).** Decisions made on the open questions
below: MySQL for storage, admin-provisioned accounts (no public signup),
password reset emails via PHP's `mail()`, and the entire existing dashboard
now requires login. See `CLAUDE.md` → "Login & user management" for the
quick-reference (schema location, bootstrap command). This brief is kept as
the record of what was decided and why.

Use this as the implementation brief for adding authentication and role-based
user management to the OMS Zoho Dashboard. Paste it into a new session/task
when ready to build.

## Context

This is the Zoho Dashboard — an internal admin reporting tool for a
non-profit mission agency (see `CLAUDE.md`). Stack: plain PHP (no framework),
vanilla JS + jQuery, no database currently — everything is either pulled
live from Zoho APIs or cached to flat JSON files in
`zoho-dashboard-config/cache`. There is **no login system today**; anyone who
can reach the app can view and edit everything (e.g. `index.php`,
`employee.php`, MSR edits, attachment uploads/deletes).

Note: `auth/connect.php` and `auth/callback.php` already exist, but they are
the **Zoho OAuth2 handshake** (server obtaining its own Zoho API token) — not
end-user login. Don't reuse or collide with that flow; pick distinct naming
(e.g. a new `account/` folder, or `auth/login.php` etc. as long as it's
clearly separated from the Zoho OAuth callback route).

## Goal

Add email/password authentication with two roles (`admin`, `staff`) so the
dashboard is no longer open to anyone, plus an admin-only screen for managing
user accounts.

## Functional requirements

1. **Sign up & log in** with email + password.
2. **Forgot password / reset password** via emailed reset link.
3. **Two roles**: `admin` and `staff`.
4. **User management screen** (list/create/edit/deactivate users, change
   role) — accessible to `admin` only. `staff` must not be able to reach it
   even via direct URL/API call, not just a hidden nav item.

## Open decisions to confirm before building

These aren't in the codebase today and materially change the implementation
— confirm with the user first rather than assuming:

- **Storage**: no DB exists yet. XAMPP ships MySQL, which is the natural fit
  for a `users` table — but confirm before adding a DB dependency to a
  project that's currently DB-free. (A flat-JSON user store is possible but
  not recommended once password hashes, reset tokens, and roles are
  involved.)
- **Self-signup vs admin-provisioned accounts**: the ask says "signup", but
  this is an internal financial/donor-data dashboard for a non-profit. Open
  self-registration (anyone with an email can create an account) is a real
  risk here. Recommend: admin creates staff accounts (via the user
  management screen) rather than public signup; "signup" becomes
  "admin invites/creates a user." Confirm which behaviour is wanted.
- **Outbound email**: password reset requires sending mail. Confirm an SMTP
  provider/credentials to add to `zoho-dashboard-config/config.php` (e.g.
  PHPMailer + SMTP, or a transactional email API). `mail()` alone is
  unreliable on most hosts.
- **Should the whole existing dashboard require login**, or only new
  screens? Recommend: require login for the entire app once this ships,
  since none of it is currently gated.

## Data model (assuming MySQL)

```sql
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE password_resets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    token_hash      VARCHAR(255) NOT NULL,
    expires_at      DATETIME NOT NULL,
    used_at         DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

## Security requirements

- Hash passwords with `password_hash(..., PASSWORD_BCRYPT)` /
  `password_verify()`. Never store or log plaintext passwords.
- Sessions: PHP native sessions, `session.cookie_httponly=1`,
  `session.cookie_samesite=Strict`, `cookie_secure` when served over HTTPS.
  Regenerate session ID on login (`session_regenerate_id(true)`).
- CSRF token on every state-changing form/AJAX call (login excluded is fine,
  but signup/reset/user-management must be protected).
- Reset tokens: generate with `random_bytes`, store only a hash of the token
  (like `password_hash` does for passwords, or at minimum a SHA-256 hash),
  single-use, expire in ≤1 hour.
- Rate-limit login attempts per email/IP to blunt brute force.
- Generic error messages on login/forgot-password ("If that account exists,
  we've sent a reset link") — don't reveal whether an email is registered.
- Enforce a minimum password length (12+ recommended) on signup/reset.
- Every admin-only route/endpoint must check role **server-side** — the
  `api/*.php` proxy pattern already used in this repo (whitelist + explicit
  checks) is the right model to extend, not client-side hiding alone.

## Suggested structure (fitting existing conventions)

- `lib/Auth.php` — session helper: `Auth::login()`, `Auth::logout()`,
  `Auth::currentUser()`, `Auth::requireLogin()`, `Auth::requireRole('admin')`.
- `lib/Database.php` — thin PDO wrapper (mysql, prepared statements only).
- `account/login.php`, `account/forgot-password.php`,
  `account/reset-password.php` — page-level forms (distinct from Zoho's
  `auth/` folder).
- `api/auth.php` — POST endpoints: `login`, `logout`, `forgot_password`,
  `reset_password`, `create_user` (admin-invited signup) — same
  whitelist-and-validate pattern as `api/proxy.php`.
- `api/users.php` — admin-only CRUD for user management (`list`, `create`,
  `update_role`, `deactivate`), guarded by `Auth::requireRole('admin')`.
- `user-management.php` — admin-only page (nav item hidden for `staff`, and
  the page itself 403s server-side for non-admins).
- Add `Auth::requireLogin()` at the top of `index.php` / `employee.php` (or
  wherever the app currently boots) once the open-access question above is
  resolved.
- Extend `zoho-dashboard-config/config.example.php` with `db_*` and
  `smtp_*` keys (never commit real credentials, matching existing
  conventions).

## Acceptance criteria

- [ ] Unauthenticated users are redirected to login for every existing page.
- [ ] Login works with correct credentials; fails safely with incorrect ones
      (no user enumeration, no plaintext password ever logged).
- [ ] Forgot-password sends a working, single-use, expiring reset link.
- [ ] Staff account cannot load `user-management.php` or hit `api/users.php`
      (verify via direct URL, not just nav visibility) — should 403.
- [ ] Admin can create/deactivate users and change roles.
- [ ] Passwords are hashed at rest; verified via `password_verify`.
- [ ] CSRF protection present on all state-changing requests.
- [ ] `config.example.php` documents new required config keys without real
      secrets.
