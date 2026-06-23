<?php
declare(strict_types=1);
namespace Controllers;

/**
 * Account settings (self-service) — reached from the top-bar profile menu.
 * Profile tab is functional (profile fields + change password + timezone).
 * The Security (2FA) tab remains a preview until a follow-up PR.
 */
class SettingsController extends BaseController
{
    public function index(array $params = []): void
    {
        $uid = (int) (\Core\Auth::user()['id'] ?? 0);
        $profile = $this->db->row(
            'SELECT id, username, email, first_name, last_name, timezone FROM users WHERE id = ?',
            [$uid]
        ) ?? [];

        $username      = (string) ($profile['username'] ?? '');
        $pendingSecret = (string) \Core\Session::get('twofa_pending_secret', '');
        $showCodes     = \Core\Session::get('twofa_show_codes');
        if ($showCodes !== null) {
            \Core\Session::remove('twofa_show_codes');   // one-shot display
        }
        $twofaOn = \Core\TwoFactor::isEnabled($uid);
        $twofa = [
            'enabled'    => $twofaOn,
            'pending'    => $pendingSecret !== ''
                ? ['secret' => $pendingSecret, 'uri' => \Core\Totp::uri($pendingSecret, $username)]
                : null,
            'show_codes' => is_array($showCodes) ? array_values($showCodes) : null,
            'remaining'  => $twofaOn ? \Core\TwoFactor::remainingRecoveryCodes($uid) : 0,
        ];

        $this->view('settings/index', [
            'pageTitle'  => t('settings.title'),
            'profile'    => $profile,
            'tzGroups'   => tz_grouped(),
            'tzSelected' => (string) ($profile['timezone'] ?? 'UTC'),
            'twofa'      => $twofa,
        ]);
    }

    public function saveProfile(array $params = []): void
    {
        $uid = (int) (\Core\Auth::user()['id'] ?? 0);
        if ($uid <= 0) $this->error('You are not signed in.', '/login');

        $email = trim((string) $this->request->post('email', ''));
        $first = trim((string) $this->request->post('first_name', ''));
        $last  = trim((string) $this->request->post('last_name', ''));
        $tz    = (string) $this->request->post('timezone', 'UTC');

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error(t('settings.err.email'), '/settings');
        }
        $nameRe = "/^[\\p{L} .'\\-]{0,60}$/u";
        if (!preg_match($nameRe, $first) || !preg_match($nameRe, $last)) {
            $this->error(t('settings.err.name'), '/settings');
        }
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $this->error(t('settings.err.timezone'), '/settings');
        }

        $this->db->run(
            'UPDATE users SET email = ?, first_name = ?, last_name = ?, timezone = ? WHERE id = ?',
            [$email, $first, $last, $tz, $uid]
        );
        \Core\DB::log('settings:profile', 'Updated own profile');
        $this->success(t('settings.flash.profile_saved'), '/settings');
    }

    public function changePassword(array $params = []): void
    {
        $uid = (int) (\Core\Auth::user()['id'] ?? 0);
        if ($uid <= 0) $this->error('You are not signed in.', '/login');

        $current = (string) $this->request->post('current_password', '');
        $new     = (string) $this->request->post('new_password', '');
        $confirm = (string) $this->request->post('confirm_password', '');

        $row = $this->db->row('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        if (!$row || !password_verify($current, (string) $row['password_hash'])) {
            $this->error(t('settings.err.current_password'), '/settings');
        }
        if ($new !== $confirm) {
            $this->error(t('settings.err.password_mismatch'), '/settings');
        }
        if (strlen($new) < 8) {
            $this->error(t('settings.err.password_short'), '/settings');
        }

        $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $uid]);
        \Core\DB::log('settings:password', 'Changed own password');
        $this->success(t('settings.flash.password_changed'), '/settings');
    }

    public function start2fa(array $params = []): void
    {
        $uid = (int) (\Core\Auth::user()['id'] ?? 0);
        if ($uid <= 0) $this->error('You are not signed in.', '/login');
        if (\Core\TwoFactor::isEnabled($uid)) {
            $this->error(t('settings.2fa.err.already'), '/settings');
        }
        \Core\Session::set('twofa_pending_secret', \Core\Totp::generateSecret());
        $this->redirect('/settings');
    }

    public function cancel2fa(array $params = []): void
    {
        \Core\Session::remove('twofa_pending_secret');
        $this->redirect('/settings');
    }

    public function enable2fa(array $params = []): void
    {
        $uid = (int) (\Core\Auth::user()['id'] ?? 0);
        if ($uid <= 0) $this->error('You are not signed in.', '/login');

        $secret = (string) \Core\Session::get('twofa_pending_secret', '');
        if ($secret === '') {
            $this->error(t('settings.2fa.err.no_pending'), '/settings');
        }
        $code = trim((string) $this->request->post('code', ''));
        if (\Core\Totp::verify($secret, $code) === false) {
            $this->error(t('settings.2fa.err.bad_code'), '/settings');
        }
        \Core\TwoFactor::enable($uid, $secret);
        \Core\Session::remove('twofa_pending_secret');
        \Core\Session::set('twofa_show_codes', \Core\TwoFactor::generateRecoveryCodes($uid));
        \Core\DB::log('2fa:enabled', 'Enabled two-factor authentication');
        $this->success(t('settings.2fa.flash.enabled'), '/settings');
    }

    public function disable2fa(array $params = []): void
    {
        $uid = (int) (\Core\Auth::user()['id'] ?? 0);
        if ($uid <= 0) $this->error('You are not signed in.', '/login');

        $password = (string) $this->request->post('current_password', '');
        $row = $this->db->row('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        if (!$row || !password_verify($password, (string) $row['password_hash'])) {
            $this->error(t('settings.2fa.err.password'), '/settings');
        }
        \Core\TwoFactor::disable($uid);
        \Core\DB::log('2fa:disabled', 'Disabled two-factor authentication');
        $this->success(t('settings.2fa.flash.disabled'), '/settings');
    }

    public function regenerateRecovery(array $params = []): void
    {
        $uid = (int) (\Core\Auth::user()['id'] ?? 0);
        if ($uid <= 0) $this->error('You are not signed in.', '/login');

        $password = (string) $this->request->post('current_password', '');
        $row = $this->db->row('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        if (!$row || !password_verify($password, (string) $row['password_hash'])) {
            $this->error(t('settings.2fa.err.password'), '/settings');
        }
        if (!\Core\TwoFactor::isEnabled($uid)) {
            $this->error(t('settings.2fa.err.not_enabled'), '/settings');
        }
        \Core\Session::set('twofa_show_codes', \Core\TwoFactor::generateRecoveryCodes($uid));
        \Core\DB::log('2fa:recovery_regenerated', 'Regenerated 2FA recovery codes');
        $this->success(t('settings.2fa.flash.recovery_regenerated'), '/settings');
    }
}
