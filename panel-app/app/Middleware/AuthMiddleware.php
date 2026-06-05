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
            flash('error', 'Please login to continue.');
            redirect('/login');
        }
    }
}
