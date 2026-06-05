<?php
declare(strict_types=1);
namespace Controllers;

class DashboardController extends BaseController
{
    public function index(array $params = []): void
    {
        $metrics  = $this->getMetrics();
        $sites    = $this->db->rows('SELECT * FROM sites ORDER BY created_at DESC LIMIT 5');
        $activity = $this->db->rows(
            'SELECT a.*, u.username FROM activity_log a
             LEFT JOIN users u ON a.user_id = u.id
             ORDER BY a.created_at DESC LIMIT 10'
        );
        $services = $this->getServicesStatus();

        $this->view('dashboard/index', compact('metrics', 'sites', 'activity', 'services'));
    }

    public function apiMetrics(array $params = []): void
    {
        $this->json($this->getMetrics());
    }

    private function getMetrics(): array
    {
        return [
            'cpu'    => sys_cpu_percent(),
            'memory' => sys_memory(),
            'disk'   => sys_disk('/'),
            'load'   => sys_load(),
            'uptime' => sys_uptime(),
        ];
    }

    private function getServicesStatus(): array
    {
        $services = ['nginx', 'mysql', 'mariadb', 'redis-server', 'php8.1-fpm', 'php8.2-fpm', 'php8.3-fpm'];
        $result   = [];

        foreach ($services as $svc) {
            $status = trim((string) shell_exec("systemctl is-active " . escapeshellarg($svc) . " 2>/dev/null"));
            if ($status === '') continue; // service not installed
            $result[$svc] = $status === 'active';
        }
        return $result;
    }
}
