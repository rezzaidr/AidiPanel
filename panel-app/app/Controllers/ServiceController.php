<?php declare(strict_types=1); namespace Controllers;

class ServiceController extends BaseController
{
    /** php<ver>-fpm names for every installed PHP version. */
    private function phpFpmServices(): array
    {
        $out = [];
        foreach (php_versions_status() as $ver => $s) {
            if ($s['installed']) $out[] = "php{$ver}-fpm";
        }
        return $out;
    }

    /** Base services + the panel runtime + every installed PHP-FPM service. */
    private function services(): array
    {
        return array_merge(['nginx', db_service(), 'redis-server', 'aidipanel-fpm'], $this->phpFpmServices());
    }

    public function index(array $params = []): void
    {
        $services = $this->fetchStatus();
        $this->view('services/index', compact('services'));
    }

    public function apiStatus(array $params = []): void
    {
        $this->json($this->fetchStatus());
    }

    public function action(array $params = []): void
    {
        $service = (string) $this->request->post('service', '');
        $action  = (string) $this->request->post('action', '');

        $allowed = array_merge(['nginx', db_service(), 'redis', 'redis-server', 'aidipanel-fpm'], $this->phpFpmServices());
        if (!in_array($service, $allowed, true)) $this->error('Invalid service.');
        if (!in_array($action, ['start', 'stop', 'restart'], true)) $this->error('Invalid action.');

        $result = run_cli("service:{$action}", [$service]);

        if ($this->request->isAjax()) {
            $this->json(['success' => $result['success'], 'message' => $result['output']]);
        }

        if (!$result['success']) $this->error("Service {$action} failed: " . $result['output']);

        \Core\DB::log("service:{$action}", "{$action} {$service}");
        $this->success("{$service} {$action}ed successfully.", '/services');
    }

    private function fetchStatus(): array
    {
        $result = [];
        foreach ($this->services() as $svc) {
            $status   = trim((string) shell_exec("systemctl is-active " . escapeshellarg($svc) . " 2>/dev/null"));
            if ($status === '') continue;
            $enabled  = trim((string) shell_exec("systemctl is-enabled " . escapeshellarg($svc) . " 2>/dev/null"));
            $result[$svc] = [
                'name'    => $svc,
                'active'  => $status === 'active',
                'enabled' => $enabled === 'enabled',
                'status'  => $status,
            ];
        }
        return $result;
    }
}
