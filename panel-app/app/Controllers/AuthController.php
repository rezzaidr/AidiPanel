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

        // Brute-force throttle (persistent: per-IP and per-username, in SQLite)
        $ip = $this->request->ip();
        $counts = \Core\DB::failedLoginCounts($username, $ip);
        if ($counts['ip'] >= 5) {
            flash('error', 'Too many failed attempts from your address. Please wait 5 minutes.');
            redirect('/login');
        }
        if ($counts['user'] >= 10) {
            flash('error', 'Too many failed attempts for this account. Please wait 15 minutes.');
            redirect('/login');
        }

        if (Auth::attempt($username, $password)) {
            \Core\DB::clearFailedLogins($ip, $username);
            \Core\DB::log('login', "User {$username} logged in");
            redirect('/dashboard');
        }

        \Core\DB::recordFailedLogin($username, $ip);
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
