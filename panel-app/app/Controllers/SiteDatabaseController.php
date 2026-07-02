<?php
declare(strict_types=1);
namespace Controllers;

/**
 * Site-scoped database actions (Manage Site → Database tab).
 * Mirrors SiteSslController: validate → run_cli → flash → redirect ?tab=database.
 *
 * Isolation is enforced in the CLI: every db:* call passes --site <domain> and the
 * CLI derives the site's name prefix from the root-owned vhost and refuses any name
 * outside it. The checks here are a fast first pass, not the security boundary.
 */
class SiteDatabaseController extends BaseController
{
    private const PMA_SIGNON_SESSION = 'AIDIPANEL_PMA_SIGNON';

    /** Create a database, optionally with a matching user (Add Database modal). */
    public function addDatabase(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);

        $name = $this->cleanName((string) $this->request->post('name', ''));
        if ($name === '') {
            $this->error('Database name is required.', $this->tab($domain));
        }

        $createUser = $this->checked('create_user');
        $perm = $this->perm((string) $this->request->post('permission', 'rw'), $domain);

        $args = ['--site', $domain, '--name', $name, '--permission', $perm];
        $user = '';
        $secret = '';

        if ($createUser) {
            $user = $this->cleanName((string) $this->request->post('user', $name));
            if ($user === '') {
                $this->error('Database user name is required.', $this->tab($domain));
            }
            $args[] = '--user'; $args[] = $user;
            $pass = (string) $this->request->post('pass', '');
            if ($pass !== '') {
                $this->checkPass($pass, $domain);
                $args[] = '--password-stdin';
                $secret = $pass;
            }
        } else {
            $args[] = '--no-user';
        }

        $result = $secret !== '' ? run_cli_stdin('db:add', $args, $secret) : run_cli('db:add', $args);
        if (!$result['success']) {
            $this->error('Could not create database: ' . $result['output'], $this->tab($domain));
        }

        \Core\DB::log('db:add', "Created database {$name}" . ($createUser ? " (user {$user})" : '') . " for {$domain}");
        if ($createUser) {
            $this->flashCredentials($name, $user, $result['output']);
        }
        $this->success("Database '{$name}' created.", $this->tab($domain));
    }

    /** Create a database user on an existing database (Add Database User modal). */
    public function addUser(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);

        $user = $this->cleanName((string) $this->request->post('name', ''));
        $db   = $this->cleanName((string) $this->request->post('database', ''));
        if ($user === '' || $db === '') {
            $this->error('Database user name and database are required.', $this->tab($domain));
        }
        $perm = $this->perm((string) $this->request->post('permission', 'rw'), $domain);

        $args = ['--site', $domain, '--name', $user, '--db', $db, '--permission', $perm];
        $secret = '';
        $pass = (string) $this->request->post('pass', '');
        if ($pass !== '') {
            $this->checkPass($pass, $domain);
            $args[] = '--password-stdin';
            $secret = $pass;
        }

        $result = $secret !== '' ? run_cli_stdin('db:user-add', $args, $secret) : run_cli('db:user-add', $args);
        if (!$result['success']) {
            $this->error('Could not create user: ' . $result['output'], $this->tab($domain));
        }
        \Core\DB::log('db:user-add', "Created db user {$user} on {$db} for {$domain}");
        $this->flashCredentials($db, $user, $result['output']);
        $this->success("Database user '{$user}' created.", $this->tab($domain));
    }

    /** Change a user's permission and/or reset its password (Edit Database User modal). */
    public function editUser(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);

        $user = $this->cleanName((string) $this->request->post('name', ''));
        if ($user === '') {
            $this->error('Database user is required.', $this->tab($domain));
        }

        $args = ['--site', $domain, '--name', $user];
        $changed = false;
        $passChanged = false;
        $secret = '';

        $permRaw = trim((string) $this->request->post('permission', ''));
        if ($permRaw !== '') {
            $args[] = '--permission'; $args[] = $this->perm($permRaw, $domain);
            $changed = true;
        }

        $pass = (string) $this->request->post('pass', '');
        if ($this->checked('regenerate')) {
            $args[] = '--generate';
            $changed = true; $passChanged = true;
        } elseif ($pass !== '') {
            $this->checkPass($pass, $domain);
            $args[] = '--password-stdin';
            $secret = $pass;
            $changed = true; $passChanged = true;
        }

        if (!$changed) {
            $this->error('Nothing to change.', $this->tab($domain));
        }

        // On a WordPress site, let the CLI keep wp-config.php in sync with the new password.
        if ($passChanged) {
            $site = $this->db->row('SELECT type, webroot FROM sites WHERE domain = ?', [$domain]);
            if ($site && ($site['type'] ?? '') === 'wordpress' && !empty($site['webroot'])) {
                $args[] = '--wp-config';
                $args[] = rtrim((string) $site['webroot'], '/') . '/wp-config.php';
            }
        }

        $result = $secret !== '' ? run_cli_stdin('db:user-edit', $args, $secret) : run_cli('db:user-edit', $args);
        if (!$result['success']) {
            $this->error('Could not update user: ' . $result['output'], $this->tab($domain));
        }
        \Core\DB::log('db:user-edit', "Edited db user {$user} for {$domain}");
        if ($passChanged) {
            $this->flashCredentials('', $user, $result['output']);
        }
        $this->success("Database user '{$user}' updated.", $this->tab($domain));
    }

    /** Install phpMyAdmin globally, then expose it in this site's Database tab. */
    public function installPhpMyAdmin(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);

        if ($this->request->post('stream') === '1') {
            $this->streamCli('db:pma-install', [], function (array $result) use ($domain): array {
                \Core\DB::log('db:pma-install', "Installed phpMyAdmin from {$domain}");
                return [
                    'redirect' => $this->tab($domain),
                    'message'  => 'phpMyAdmin installed.',
                ];
            });
        }

        $this->unlockForLongOp();
        $result = run_cli('db:pma-install', []);
        if (!$result['success']) {
            $this->error('Could not install phpMyAdmin: ' . $result['output'], $this->tab($domain));
        }

        \Core\DB::log('db:pma-install', "Installed phpMyAdmin from {$domain}");
        $this->success('phpMyAdmin installed.', $this->tab($domain));
    }

    /** Create a phpMyAdmin signon session for one site-scoped DB user, then redirect. */
    public function openPhpMyAdmin(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);

        $user = $this->cleanName((string) $this->request->post('user', ''));
        $db   = $this->cleanName((string) $this->request->post('database', ''));
        if ($user === '' || $db === '') {
            $this->error('Database user and database are required.', $this->tab($domain));
        }

        $result = run_cli('db:pma-credentials', ['--site', $domain, '--user', $user, '--db', $db]);
        if (!$result['success']) {
            $this->error('Could not open phpMyAdmin: ' . $result['output'], $this->tab($domain));
        }

        $cred = $this->parseKeyValues($result['output']);
        foreach (['pma_session_id', 'pma_user', 'pma_db'] as $key) {
            if (($cred[$key] ?? '') === '') {
                $this->error('Could not open phpMyAdmin: incomplete signon credential.', $this->tab($domain));
            }
        }
        if (!preg_match('/^[a-f0-9]{32}$/', (string) $cred['pma_session_id'])) {
            $this->error('Could not open phpMyAdmin: malformed signon session.', $this->tab($domain));
        }

        $this->sendPhpMyAdminSignonCookie((string) $cred['pma_session_id']);
        \Core\DB::log('db:pma-open', "Opened phpMyAdmin for {$cred['pma_user']} on {$cred['pma_db']} from {$domain}");
        $this->redirect('/phpmyadmin/index.php?db=' . rawurlencode($cred['pma_db']));
    }

    /** Delete a database (type-to-confirm). */
    public function deleteDatabase(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);

        $name    = $this->cleanName((string) $this->request->post('name', ''));
        $confirm = $this->cleanName((string) $this->request->post('confirm', ''));
        if ($name === '' || $confirm !== $name) {
            $this->error('Type the database name exactly to confirm deletion.', $this->tab($domain));
        }

        $result = run_cli('db:delete', ['--site', $domain, '--name', $name, '--force']);
        if (!$result['success']) {
            $this->error('Could not delete database: ' . $result['output'], $this->tab($domain));
        }
        \Core\DB::log('db:delete', "Deleted database {$name} for {$domain}");
        $this->success("Database '{$name}' deleted.", $this->tab($domain));
    }

    /** Delete a database user (type-to-confirm). */
    public function deleteUser(array $params = []): void
    {
        $domain = (string) ($params['domain'] ?? '');
        $this->requireSite($domain);

        $name    = $this->cleanName((string) $this->request->post('name', ''));
        $confirm = $this->cleanName((string) $this->request->post('confirm', ''));
        if ($name === '' || $confirm !== $name) {
            $this->error('Type the user name exactly to confirm deletion.', $this->tab($domain));
        }

        $result = run_cli('db:user-delete', ['--site', $domain, '--name', $name, '--force']);
        if (!$result['success']) {
            $this->error('Could not delete user: ' . $result['output'], $this->tab($domain));
        }
        \Core\DB::log('db:user-delete', "Deleted db user {$name} for {$domain}");
        $this->success("Database user '{$name}' deleted.", $this->tab($domain));
    }

    // ---- helpers ----

    /** Strip a name to the CLI-safe charset; the CLI re-validates + prefix-checks. */
    private function cleanName(string $raw): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $raw) ?? '';
    }

    private function checked(string $field): bool
    {
        $v = $this->request->post($field);
        return $v !== null && $v !== '' && $v !== '0';
    }

    private function perm(string $raw, string $domain): string
    {
        $p = strtolower(trim($raw));
        if (in_array($p, ['read-write', 'readwrite', 'read_write'], true)) { $p = 'rw'; }
        if (in_array($p, ['read-only', 'readonly', 'read_only'], true))     { $p = 'ro'; }
        if ($p !== 'rw' && $p !== 'ro') {
            $this->error('Invalid permission.', $this->tab($domain));
        }
        return $p;
    }

    private function checkPass(string $pass, string $domain): void
    {
        if (!preg_match('/^[A-Za-z0-9_!@#%+=.,:-]{8,128}$/', $pass)) {
            $this->error('Password must be 8-128 characters and use only safe symbols.', $this->tab($domain));
        }
    }

    /** Pull the once-shown password out of the CLI output and flash it for the view. */
    private function flashCredentials(string $db, string $user, string $output): void
    {
        if (preg_match('/Password:\s*(\S+)/', $output, $m)) {
            flash('db_credentials', json_encode([
                'database' => $db,
                'user'     => $user,
                'pass'     => $m[1],
            ]));
        }
    }

    private function parseKeyValues(string $output): array
    {
        $out = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (preg_match('/^([a-z_]+)=(.*)$/', trim($line), $m)) {
                $out[$m[1]] = $m[2];
            }
        }
        return $out;
    }

    /**
     * Hand the browser the signon session id minted by `db:pma-credentials`.
     * The session file itself is written root-owned by the CLI — PHP's files
     * session handler only trusts files owned by the reader's uid or by root,
     * so a panel-written file would be rejected by phpMyAdmin's isolated
     * runtime (aidipanel-pma). The panel never sees the DB password.
     */
    private function sendPhpMyAdminSignonCookie(string $sessionId): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') === '8443');

        setcookie(self::PMA_SIGNON_SESSION, $sessionId, [
            'expires'  => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function requireSite(string $domain): void
    {
        if (!is_valid_domain($domain) || !$this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain])) {
            abort(404, "Site not found: {$domain}");
        }
    }

    private function tab(string $domain): string
    {
        return "/sites/{$domain}?tab=database";
    }
}
