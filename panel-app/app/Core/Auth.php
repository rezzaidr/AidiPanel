<?php
declare(strict_types=1);
namespace Core;

class Auth
{
    public static function check(): bool
    {
        return Session::has('user_id');
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
        Session::regenerate();
        Session::set('user_id',  $user['id']);
        Session::set('username', $user['username']);
        Session::set('role',     $user['role']);
    }

    public static function logout(): void
    {
        Session::destroy();
    }

    public static function isAdmin(): bool
    {
        return Session::get('role') === 'admin';
    }

    /**
     * Attempt to authenticate user from DB
     */
    public static function attempt(string $username, string $password): bool
    {
        $db   = DB::instance();
        $user = $db->row(
            'SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1',
            [$username]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Update last login
        $db->run('UPDATE users SET last_login = ? WHERE id = ?', [date('Y-m-d H:i:s'), $user['id']]);

        self::login($user);
        return true;
    }
}
