<?php
declare(strict_types=1);
namespace Middleware;

use Core\Auth;
use Core\Session;
use Core\Request;

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
    }
}
