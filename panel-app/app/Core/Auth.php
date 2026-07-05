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
     * A fixed-cost bcrypt hash used only to equalize the wall-clock time of a
     * failed login when the username does not exist or is inactive — so an
     * attacker cannot enumerate valid/active usernames via response latency. Its
     * verification result is discarded; it is run purely for its bcrypt cost.
     */
    private const DUMMY_HASH = '$2y$12$RrAWIu1.PNM9enWS1q5DAeoeSIt3iCCZCX2juibt9jBuKGYZ1FPSq';

    /**
     * Validate username + password. Returns the user row on success (caller then
     * either logs in directly or starts a 2FA challenge), or null on failure.
     *
     * Timing is equalized across every failure branch (unknown user, inactive
     * user, wrong password) by always running exactly one bcrypt password_verify
     * — against the real hash when the row exists, or against DUMMY_HASH when it
     * does not — before returning null. The `active` flag is checked AFTER the
     * verify (not in the SQL) so an inactive account pays the same bcrypt cost as
     * an active one, removing the active/inactive timing signal too.
     */
    public static function verifyCredentials(string $username, string $password): ?array
    {
        $user = DB::instance()->row(
            'SELECT * FROM users WHERE username = ? LIMIT 1',
            [$username]
        );
        $hash = $user ? (string) $user['password_hash'] : self::DUMMY_HASH;
        if (!password_verify($password, $hash)) {
            return null;
        }
        if (!$user || (int) $user['active'] !== 1) {
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
