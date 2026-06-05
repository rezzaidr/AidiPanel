<?php declare(strict_types=1); namespace Controllers;

class UserController extends BaseController
{
    public function index(array $params = []): void
    {
        $users = $this->db->rows('SELECT id, username, role, active, created_at, last_login FROM users ORDER BY created_at DESC');
        $this->view('users/index', compact('users'));
    }

    public function add(array $params = []): void
    {
        $username = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $this->request->post('username', ''));
        $password = (string) $this->request->post('password', '');
        $role     = (string) $this->request->post('role', 'admin');

        if (empty($username)) $this->error('Username is required.');
        if (strlen($password) < 8) $this->error('Password must be at least 8 characters.');
        if (!in_array($role, ['admin', 'viewer'], true)) $this->error('Invalid role.');

        if ($this->db->row('SELECT id FROM users WHERE username = ?', [$username])) {
            $this->error("Username already exists: {$username}");
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->db->run('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)', [$username, $hash, $role]);
        \Core\DB::log('user:add', "Created panel user: {$username} ({$role})");
        $this->success("User '{$username}' created.", '/users');
    }

    public function delete(array $params = []): void
    {
        $id = (int) $this->request->post('id', 0);
        $currentUserId = (int) \Core\Session::get('user_id');
        if ($id === $currentUserId) $this->error('Cannot delete your own account.');
        if ($id <= 0) $this->error('Invalid user.');

        $user = $this->db->row('SELECT username FROM users WHERE id = ?', [$id]);
        if (!$user) $this->error('User not found.');

        $this->db->run('DELETE FROM users WHERE id = ?', [$id]);
        \Core\DB::log('user:delete', "Deleted panel user: " . $user['username']);
        $this->success('User deleted.', '/users');
    }

    public function changePassword(array $params = []): void
    {
        $id      = (int) $this->request->post('id', 0);
        $newPass = (string) $this->request->post('password', '');

        if (strlen($newPass) < 8) $this->error('Password must be at least 8 characters.');

        $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $id]);
        \Core\DB::log('user:passwd', "Changed password for user ID: {$id}");
        $this->success('Password updated.', '/users');
    }
}
