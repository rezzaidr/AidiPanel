<?php declare(strict_types=1); namespace Controllers;

class PhpController extends BaseController
{
    public function index(array $params = []): void
    {
        $status   = php_versions_status();
        $versions = [];
        foreach ($status as $ver => $s) {
            $full = $s['installed']
                ? trim((string) shell_exec("/usr/bin/php{$ver} -r 'echo PHP_VERSION;' 2>/dev/null"))
                : null;
            $versions[$ver] = [
                'installed'  => $s['installed'],
                'fpm_active' => $s['running'],
                'default'    => $s['default'],
                'label'      => $s['label'],
                'full_ver'   => $full,
            ];
        }
        $sites = $this->db->rows('SELECT domain, php_version, type FROM sites ORDER BY domain');
        $this->view('php/index', compact('versions', 'sites'));
    }

    public function restart(array $params = []): void
    {
        $version = (string) $this->request->post('version', 'all');
        $allowed = array_merge(['all'], php_policy()['available']);
        if (!in_array($version, $allowed, true)) $this->error('Invalid PHP version.');

        $args = $version !== 'all' ? ['--version', $version] : [];
        $result = run_cli('php:restart', $args);
        if (!$result['success']) $this->error('PHP-FPM restart failed: ' . $result['output']);

        \Core\DB::log('php:restart', "Restarted PHP-FPM: {$version}");

        if ($this->request->isAjax()) {
            $this->json(['success' => true, 'message' => "PHP-FPM {$version} restarted."]);
        }
        $this->success("PHP-FPM {$version} restarted.", '/php');
    }

    public function install(array $params = []): void
    {
        $version = (string) $this->request->post('version', '');
        if (!in_array($version, php_policy()['available'], true)) {
            $this->error('Invalid PHP version.');
        }

        $result = run_cli('php:install', ['--version', $version]);
        if (!$result['success']) {
            $this->error('PHP ' . $version . ' install failed: ' . $result['output']);
        }

        \Core\DB::log('php:install', "Installed PHP {$version}");
        $this->success("PHP {$version} installed.", '/php');
    }
}
