<?php
declare(strict_types=1);
namespace Controllers;

use Core\Auth;
use Core\Session;

class AuthController extends BaseController
{
    public function showLogin(array $params = []): void
    {
        if (Auth::check()) {
            redirect('/dashboard');
        }
        $csrf = Session::csrfToken();
        $error = flash('error');
        view('auth/login', compact('csrf', 'error'));
    }

    public function login(array $params = []): void
    {
        // CSRF already checked by middleware
        $username = trim((string) $this->request->post('username', ''));
        $password = (string) $this->request->post('password', '');

        if (empty($username) || empty($password)) {
            flash('error', 'Username and password are required.');
            redirect('/login');
        }

        // Brute-force throttle (simple: track failed attempts in session)
        $attempts = Session::get('_login_attempts', 0);
        $lastAt   = Session::get('_login_last_attempt', 0);

        if ($attempts >= 5 && (time() - $lastAt) < 300) {
            flash('error', 'Too many failed attempts. Please wait 5 minutes.');
            redirect('/login');
        }

        if (Auth::attempt($username, $password)) {
            Session::remove('_login_attempts');
            Session::remove('_login_last_attempt');
            \Core\DB::log('login', "User {$username} logged in");
            redirect('/dashboard');
        }

        Session::set('_login_attempts', $attempts + 1);
        Session::set('_login_last_attempt', time());
        flash('error', 'Invalid username or password.');
        redirect('/login');
    }

    public function logout(array $params = []): void
    {
        \Core\DB::log('logout', 'User logged out');
        Auth::logout();
        redirect('/login');
    }
}
