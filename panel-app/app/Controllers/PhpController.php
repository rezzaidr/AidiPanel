<?php declare(strict_types=1); namespace Controllers;

class PhpController extends BaseController
{
    public function index(array $params = []): void
    {
        $versions = [];
        foreach (['8.1', '8.2', '8.3'] as $v) {
            $installed  = is_executable("/usr/bin/php{$v}");
            $fpmActive  = $installed && trim((string) shell_exec("systemctl is-active php{$v}-fpm 2>/dev/null")) === 'active';
            $phpVersion = $installed ? trim((string) shell_exec("/usr/bin/php{$v} -r 'echo PHP_VERSION;' 2>/dev/null")) : null;
            $versions[$v] = [
                'installed'  => $installed,
                'fpm_active' => $fpmActive,
                'full_ver'   => $phpVersion,
            ];
        }
        $sites = $this->db->rows('SELECT domain, php_version, type FROM sites ORDER BY domain');
        $this->view('php/index', compact('versions', 'sites'));
    }

    public function restart(array $params = []): void
    {
        $version = (string) $this->request->post('version', 'all');
        $allowed = array_merge(['all'], ['8.1', '8.2', '8.3']);
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
}
