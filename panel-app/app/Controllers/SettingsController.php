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

        $this->view('settings/index', [
            'pageTitle'  => t('settings.title'),
            'profile'    => $profile,
            'tzGroups'   => tz_grouped(),
            'tzSelected' => (string) ($profile['timezone'] ?? 'UTC'),
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
}
