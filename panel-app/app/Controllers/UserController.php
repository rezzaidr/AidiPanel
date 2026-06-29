<?php declare(strict_types=1); namespace Controllers;

class UserController extends BaseController
{
    private const ROLES = ['admin', 'manager', 'client'];

    public function index(array $params = []): void
    {
        // Demo: never expose real admin accounts (the username is half a credential).
        $where = demo_mode() ? "WHERE role <> 'admin'" : '';
        $users = $this->db->rows(
            "SELECT id, username, email, first_name, last_name, role, active, timezone, created_at, last_login
             FROM users {$where} ORDER BY created_at DESC"
        );
        // Attach each client's assigned domains (for the "Site" column); admins/managers show "All".
        $siteMap = [];
        if ($users) {
            $clientIds = array_filter(array_map(static fn($u) => $u['role'] === 'client' ? (int) $u['id'] : 0, $users));
            if ($clientIds) {
                $place = implode(',', array_fill(0, count($clientIds), '?'));
                $rows  = $this->db->rows(
                    "SELECT us.user_id, s.domain FROM user_sites us
                     JOIN sites s ON s.id = us.site_id
                     WHERE us.user_id IN ({$place}) ORDER BY s.domain",
                    $clientIds
                );
                foreach ($rows as $r) { $siteMap[(int) $r['user_id']][] = $r['domain']; }
            }
        }
        $allSites = $this->db->rows('SELECT id, domain FROM sites ORDER BY domain');
        $tzGroups = tz_grouped();   // same continent-grouped list the /settings page uses
        $this->view('users/index', compact('users', 'siteMap', 'allSites', 'tzGroups'));
    }

    public function add(array $params = []): void
    {
        [$fields, $sites] = $this->readUserForm(true);
        $hash = password_hash($fields['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        try {
            $this->db->immediateTransaction(function (\Core\DB $db) use ($fields, $sites, $hash): void {
                if ($db->row('SELECT id FROM users WHERE username = ?', [$fields['username']])) {
                    throw new \DomainException("Username already exists: {$fields['username']}");
                }
                $db->run(
                    'INSERT INTO users (username, password_hash, email, first_name, last_name, role, active, timezone)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$fields['username'], $hash, $fields['email'], $fields['first_name'], $fields['last_name'],
                     $fields['role'], $fields['active'], $fields['timezone']]
                );
                \Core\Access::syncUserSiteAssignments((int) $db->lastInsertId(), $fields['role'], $sites);
            });
        } catch (\DomainException $e) {
            $this->error($e->getMessage());
        }

        \Core\DB::log('user:add', "Created panel user: {$fields['username']} ({$fields['role']})");
        $this->success("User '{$fields['username']}' created.", '/users');
    }

    public function edit(array $params = []): void
    {
        $id = (int) $this->request->post('id', 0);
        $existing = $this->db->row('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$existing) {
            $this->error('User not found.');
        }

        [$fields, $sites] = $this->readUserForm(false, $existing);

        $hash = $fields['password'] !== ''
            ? password_hash($fields['password'], PASSWORD_BCRYPT, ['cost' => 12])
            : null;
        try {
            $existing = $this->db->immediateTransaction(function (\Core\DB $db) use ($id, $fields, $sites, $hash): array {
                $current = $db->row('SELECT * FROM users WHERE id = ?', [$id]);
                if (!$current) {
                    throw new \DomainException('User not found.');
                }
                if ($current['role'] === 'admin'
                    && ($fields['role'] !== 'admin' || $fields['active'] !== 1)
                    && \Core\Access::isLastAdmin($id)) {
                    throw new \DomainException('Cannot change the last administrator — at least one admin must remain active.');
                }

                if ($hash !== null) {
                    $db->run(
                        'UPDATE users SET password_hash = ?, email = ?, first_name = ?, last_name = ?, role = ?, active = ?, timezone = ? WHERE id = ?',
                        [$hash, $fields['email'], $fields['first_name'], $fields['last_name'], $fields['role'], $fields['active'], $fields['timezone'], $id]
                    );
                } else {
                    $db->run(
                        'UPDATE users SET email = ?, first_name = ?, last_name = ?, role = ?, active = ?, timezone = ? WHERE id = ?',
                        [$fields['email'], $fields['first_name'], $fields['last_name'], $fields['role'], $fields['active'], $fields['timezone'], $id]
                    );
                }
                \Core\Access::syncUserSiteAssignments($id, $fields['role'], $sites);
                return $current;
            });
        } catch (\DomainException $e) {
            $this->error($e->getMessage());
        }

        \Core\DB::log('user:edit', "Edited panel user: {$existing['username']} ({$fields['role']})");
        $this->success("User '{$existing['username']}' updated.", '/users');
    }

    public function delete(array $params = []): void
    {
        $id = (int) $this->request->post('id', 0);
        if ($id === (int) \Core\Session::get('user_id')) {
            $this->error('Cannot delete your own account.');
        }
        if ($id <= 0) {
            $this->error('Invalid user.');
        }
        try {
            $user = $this->db->immediateTransaction(function (\Core\DB $db) use ($id): array {
                $current = $db->row('SELECT username FROM users WHERE id = ?', [$id]);
                if (!$current) {
                    throw new \DomainException('User not found.');
                }
                if (\Core\Access::isLastAdmin($id)) {
                    throw new \DomainException('Cannot delete the last administrator.');
                }
                $db->run('DELETE FROM users WHERE id = ?', [$id]);
                return $current;
            });
        } catch (\DomainException $e) {
            $this->error($e->getMessage());
        }
        \Core\DB::log('user:delete', "Deleted panel user: " . $user['username']);
        $this->success('User deleted.', '/users');
    }

    public function changePassword(array $params = []): void
    {
        $id      = (int) $this->request->post('id', 0);
        $newPass = (string) $this->request->post('password', '');

        if (strlen($newPass) < 8) {
            $this->error('Password must be at least 8 characters.');
        }

        $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $id]);
        \Core\DB::log('user:passwd', "Changed password for user ID: {$id}");
        $this->success('Password updated.', '/users');
    }

    /**
     * Read + validate the shared Add/Edit form. $requirePassword = true on add.
     * Returns [fields, sites[]]. error() (never) on validation failure.
     */
    private function readUserForm(bool $requirePassword, ?array $existing = null): array
    {
        $username = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $this->request->post('username', ''));
        // On edit the username input is disabled (read-only) so it is NOT submitted —
        // use the existing one. This must happen BEFORE the empty check below.
        if ($existing !== null) {
            $username = $existing['username'];
        }

        $email     = trim((string) $this->request->post('email', ''));
        $firstName = trim((string) $this->request->post('first_name', ''));
        $lastName  = trim((string) $this->request->post('last_name', ''));   // optional
        $password  = (string) $this->request->post('password', '');
        $active    = $this->request->post('active', '1') === '0' ? 0 : 1;
        $role      = (string) $this->request->post('role', 'client');
        $timezone  = (string) $this->request->post('timezone', 'UTC');
        $sites     = (array) ($this->request->post('sites') ?? []);

        if ($username === '') {
            $this->error('Username is required.');
        }
        if ($requirePassword && strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');
        }
        if (!$requirePassword && $password !== '' && strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid email is required.');
        }
        if ($firstName === '') {
            $this->error('First name is required.');
        }
        if (!in_array($role, self::ROLES, true)) {
            $this->error('Invalid role.');
        }
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $timezone = 'UTC';
        }
        // Sites are only meaningful for clients; ignore otherwise.
        if ($role !== 'client') {
            $sites = [];
        }

        return [
            [
                'username'   => $username,
                'email'      => $email,
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'password'   => $password,
                'active'     => $active,
                'role'       => $role,
                'timezone'   => $timezone,
            ],
            $sites,
        ];
    }
}
