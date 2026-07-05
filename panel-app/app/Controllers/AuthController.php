<?php
declare(strict_types=1);
namespace Controllers;

use Core\Auth;
use Core\Session;

class AuthController extends BaseController
{
    public function showLogin(array $params = []): void
    {
        // Public read-only demo never shows a login screen — sign in and go.
        if (demo_mode()) {
            if (!Auth::check()) {
                Auth::loginDemoUser();
            }
            redirect('/dashboard');
        }
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

        $user = Auth::verifyCredentials($username, $password);
        if ($user !== null) {
            if ((int) $user['totp_enabled'] === 1) {
                // Do NOT clear failed_logins here: the user has only proven their
                // password, not completed 2FA. Clearing at this stage lets an
                // attacker who possesses the password reset the 2FA attempt budget
                // by re-submitting /login between guesses. The counter persists and
                // is cleared only after the full 2FA challenge succeeds (verify2fa).
                Auth::startPending((int) $user['id']);
                redirect('/login/2fa');
            }
            \Core\DB::clearFailedLogins($ip, $username);
            Auth::login($user);
            \Core\DB::log('login', "User {$username} logged in");
            redirect('/dashboard');
        }

        \Core\DB::recordFailedLogin($username, $ip);
        flash('error', 'Invalid username or password.');
        redirect('/login');
    }

    public function logout(array $params = []): void
    {
        // No real session to end in the demo — keep the visitor on the dashboard.
        if (demo_mode()) {
            redirect('/dashboard');
        }
        \Core\DB::log('logout', 'User logged out');
        Auth::logout();
        redirect('/login');
    }

    public function show2fa(array $params = []): void
    {
        if (Auth::check()) {
            redirect('/dashboard');
        }
        if (Auth::pendingUserId() === null) {
            redirect('/login');
        }
        $csrf  = Session::csrfToken();
        $error = flash('error');
        view('auth/2fa-challenge', compact('csrf', 'error'));
    }

    public function verify2fa(array $params = []): void
    {
        // CSRF already checked by middleware.
        $uid = Auth::pendingUserId();
        if ($uid === null) {
            flash('error', 'Your sign-in session expired. Please sign in again.');
            redirect('/login');
        }

        $user = $this->db->row('SELECT * FROM users WHERE id = ? AND active = 1 LIMIT 1', [$uid]);
        if (!$user) {
            Auth::clearPending();
            redirect('/login');
        }
        $username = (string) $user['username'];
        $ip       = $this->request->ip();

        // Brute-force throttle (reuse the password-stage counters).
        $counts = \Core\DB::failedLoginCounts($username, $ip);
        if ($counts['ip'] >= 5 || $counts['user'] >= 10) {
            flash('error', 'Too many attempts. Please wait a few minutes and sign in again.');
            redirect('/login');
        }

        $code = trim((string) $this->request->post('code', ''));

        $usedRecovery = false;
        if (\Core\TwoFactor::verifyTotp($uid, $code)) {
            $ok = true;
        } elseif (\Core\TwoFactor::verifyRecoveryCode($uid, $code)) {
            $ok = true;
            $usedRecovery = true;
        } else {
            $ok = false;
        }

        if (!$ok) {
            \Core\DB::recordFailedLogin($username, $ip);
            flash('error', 'Invalid code. Enter the 6-digit code from your authenticator app, or a recovery code.');
            redirect('/login/2fa');
        }

        \Core\DB::clearFailedLogins($ip, $username);
        Auth::clearPending();
        Auth::login($user);
        \Core\DB::log('login', "User {$username} logged in (2FA)");

        if ($usedRecovery) {
            $left = \Core\TwoFactor::remainingRecoveryCodes($uid);
            flash('warning', "Signed in with a recovery code. You have {$left} recovery code(s) left.");
        }
        redirect('/dashboard');
    }
}
