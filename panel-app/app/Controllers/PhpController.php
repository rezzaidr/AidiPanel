<?php declare(strict_types=1); namespace Controllers;

class PhpController extends BaseController
{
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
        // Quick action lives on the site page; return there (no server-wide PHP page).
        $this->success("PHP-FPM {$version} restarted.");
    }
}
