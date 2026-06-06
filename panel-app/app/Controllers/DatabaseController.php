<?php
declare(strict_types=1);
namespace Controllers;

class DatabaseController extends BaseController
{
    public function index(array $params = []): void
    {
        $result = run_cli('db:list', ['--plain']);
        $databases = array_values(array_filter(array_map('trim', explode("\n", $result['output']))));
        $this->view('databases/index', ['databases' => $databases, 'output' => $result['output']]);
    }

    public function add(array $params = []): void
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $this->request->post('name', ''));
        $user = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $this->request->post('user', $name));
        $pass = (string) $this->request->post('pass', '');

        if (empty($name)) $this->error('Database name is required.');
        if ($pass !== '' && !preg_match('/^[A-Za-z0-9_!@#%+=.,:-]{8,128}$/', $pass)) {
            $this->error('Password must be 8-128 characters and contain only safe symbols.');
        }

        $args = ['--name', $name, '--user', $user];
        if ($pass) $args = array_merge($args, ['--pass', $pass]);

        $result = run_cli('db:add', $args);
        if (!$result['success']) $this->error('Failed to create database: ' . $result['output']);

        \Core\DB::log('db:add', "Created database: {$name}");

        // Extract generated password from CLI output to show in flash
        preg_match('/Password:\s*(\S+)/', $result['output'], $m);
        $generatedPass = $m[1] ?? '(see CLI output)';

        flash('db_credentials', json_encode(['name' => $name, 'user' => $user, 'pass' => $generatedPass]));
        $this->success("Database '{$name}' created.", '/databases');
    }

    public function delete(array $params = []): void
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $this->request->post('name', ''));
        if (empty($name)) $this->error('Database name is required.');

        $result = run_cli('db:delete', ['--name', $name, '--force']);
        if (!$result['success']) $this->error('Failed to delete database: ' . $result['output']);

        \Core\DB::log('db:delete', "Deleted database: {$name}");
        $this->success("Database '{$name}' deleted.", '/databases');
    }

    public function backup(array $params = []): void
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $this->request->post('name', ''));
        if (empty($name)) $this->error('Database name is required.');

        $result = run_cli('db:backup', ['--name', $name]);
        if (!$result['success']) $this->error('Backup failed: ' . $result['output']);

        preg_match('/Backup created:\s*(\S+)/', $result['output'], $m);
        $file = $m[1] ?? 'backup file';

        \Core\DB::log('db:backup', "Backed up database: {$name} → {$file}");
        $this->success("Backup created: {$file}", '/databases');
    }
}
