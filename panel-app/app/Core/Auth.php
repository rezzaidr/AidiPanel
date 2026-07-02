<?php
declare(strict_types=1);
namespace Core;

class Auth
{
    /** A password-verified session awaiting its 2FA code expires after this long. */
    public const PENDING_TTL = 300;   // 5 minutes

    public static function check(): bool
    {
        if (!Session::has('user_id')) {
            return false;
        }

        $user = DB::instance()->row(
            'SELECT id, username, role, active, password_hash FROM users WHERE id = ? LIMIT 1',
            [(int) Session::get('user_id')]
        );
        $sessionHash = (string) Session::get('auth_hash', '');
        if (!$user
            || (int) $user['active'] !== 1
            || $sessionHash === ''
            || !hash_equals($sessionHash, self::authHash((string) $user['password_hash']))) {
            self::logout();
            return false;
        }

        // Apply account and role changes to an already-open session immediately.
        Session::set('username', $user['username']);
        Session::set('role',     $user['role']);
        return true;
    }

    public static function user(): ?array
    {
        if (!self::check()) return null;
        return [
            'id'       => Session::get('user_id'),
            'username' => Session::get('username'),
            'role'     => Session::get('role'),
        ];
    }

    public static function login(array $user): void
    {
        $wasFirstLogin = empty($user['last_login']);

        Session::regenerate();
        Session::set('user_id',  $user['id']);
        Session::set('username', $user['username']);
        Session::set('role',     $user['role']);
        Session::set('auth_hash', self::authHash((string) $user['password_hash']));
        Session::set('first_login', $wasFirstLogin);
        DB::instance()->run('UPDATE users SET last_login = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $user['id']]);
    }

    public static function wasFirstLogin(): bool
    {
        return (bool) Session::get('first_login', false);
    }

    public static function logout(): void
    {
        Session::destroy();
    }

    public static function isAdmin(): bool
    {
        return Session::get('role') === 'admin';
    }

    public static function role(): string
    {
        return (string) (Session::get('role') ?? '');
    }

    public static function isManager(): bool
    {
        return self::role() === 'manager';
    }

    public static function isClient(): bool
    {
        return self::role() === 'client';
    }

    /**
     * Validate username + password. Returns the user row on success (caller then
     * either logs in directly or starts a 2FA challenge), or null on failure.
     */
    public static function verifyCredentials(string $username, string $password): ?array
    {
        $user = DB::instance()->row(
            'SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1',
            [$username]
        );
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return null;
        }
        return $user;
    }

    public static function startPending(int $userId): void
    {
        Session::regenerate();
        Session::set('pending_2fa', ['uid' => $userId, 'at' => time()]);
    }

    /** The user id awaiting a 2FA code, or null if absent/expired. */
    public static function pendingUserId(): ?int
    {
        $p = Session::get('pending_2fa');
        if (!is_array($p) || empty($p['uid']) || (time() - (int) ($p['at'] ?? 0)) > self::PENDING_TTL) {
            return null;
        }
        return (int) $p['uid'];
    }

    public static function clearPending(): void
    {
        Session::remove('pending_2fa');
    }

    private static function authHash(string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }

    /**
     * Auto-login as the seeded read-only demo user (public demo only). Seed it
     * with the 'viewer' role: the existing role system then blocks every action
     * (all mutating POST requires admin) and hides sensitive admin-only pages,
     * with the Router demo guard + CLI gate as extra layers.
     */
    public static function loginDemoUser(): bool
    {
        $user = DB::instance()->row(
            'SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1',
            ['demo']
        );
        if (!$user) {
            return false;
        }
        self::login($user);
        return true;
    }
}
