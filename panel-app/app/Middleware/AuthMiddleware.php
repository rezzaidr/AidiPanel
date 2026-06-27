<?php
declare(strict_types=1);
namespace Middleware;

use Core\Auth;
use Core\Session;
use Core\Request;
use Core\DB;

class AuthMiddleware
{
    public static function handle(): void
    {
        if (!Auth::check()) {
            if (demo_mode()) {
                // Public read-only demo: sign in silently as the seeded viewer user.
                if (Auth::loginDemoUser()) {
                    return;
                }
                // Flag is on but the demo user isn't seeded — fail clearly instead of
                // redirecting to /login (which would loop with showLogin()).
                abort(503, 'Demo is temporarily unavailable.');
            }
            flash('error', 'Please login to continue.');
            redirect('/login');
        }

        // Re-validate the signed-in user against the DB on every request, so an
        // admin's edits take effect immediately: a deleted or deactivated account is
        // logged out on the next request, and a changed role is picked up without a
        // re-login. (Otherwise the role/identity is cached in the session from login.)
        $uid  = (int) Session::get('user_id');
        $user = DB::instance()->row('SELECT role, active FROM users WHERE id = ?', [$uid]);
        if (!$user || !$user['active']) {
            // Drop the login identity (clear both keys so a leftover role can't grant
            // admin), keep the session itself so the flash survives the redirect.
            Session::remove('user_id');
            Session::remove('role');
            flash('error', $user ? 'Your account has been deactivated.' : 'Your account no longer exists.');
            redirect('/login');
        }
        if ((string) $user['role'] !== (string) Session::get('role')) {
            Session::set('role', (string) $user['role']);
        }
    }
}
