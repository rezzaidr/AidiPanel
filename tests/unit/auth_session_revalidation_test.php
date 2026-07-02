<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$auth = file_get_contents($root . '/panel-app/app/Core/Auth.php');
$middleware = file_get_contents($root . '/panel-app/app/Middleware/AuthMiddleware.php');
$users = file_get_contents($root . '/panel-app/app/Controllers/UserController.php');

function authExpect(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "ok: {$label}\n";
}

authExpect(str_contains($auth, 'SELECT id, username, role, active, password_hash FROM users WHERE id = ?'), 'authenticated sessions re-read the current user');
authExpect(str_contains($auth, "(int) \$user['active'] !== 1"), 'disabled users lose existing sessions');
authExpect(str_contains($auth, '!hash_equals($sessionHash, self::authHash'), 'password changes invalidate existing sessions');
authExpect(str_contains($auth, "Session::set('role',     \$user['role']);"), 'role changes take effect on existing sessions');
authExpect(str_contains($auth, "Session::set('auth_hash', self::authHash((string) \$user['password_hash']));"), 'login records a non-reversible credential version');
authExpect(str_contains($users, "if (!\$target)"), 'password reset rejects a missing user');
authExpect(!str_contains($middleware, 'SELECT role, active FROM users'), 'authentication performs only one user revalidation query per request');

echo "auth session revalidation contract passed\n";
