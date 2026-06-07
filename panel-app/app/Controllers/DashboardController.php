<?php
declare(strict_types=1);
namespace Controllers;

class DashboardController extends BaseController
{
    public function index(array $params = []): void
    {
        $metrics  = $this->getMetrics();
        $vps      = $this->getVpsStatus($metrics);
        $services = $this->getServicesStatus();
        $sites    = $this->db->rows('SELECT * FROM sites ORDER BY created_at DESC LIMIT 6');
        $alerts   = $this->getAlerts($metrics, $services);

        $this->view('dashboard/index', compact('metrics', 'vps', 'services', 'sites', 'alerts'));
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

    /**
     * Server facts for the VPS status panel. Everything is best-effort with safe
     * fallbacks so the dashboard renders even when a source is unavailable.
     */
    private function getVpsStatus(array $metrics): array
    {
        $os = PHP_OS;
        if (is_readable('/etc/os-release')) {
            $rel = @parse_ini_file('/etc/os-release');
            $os  = $rel['PRETTY_NAME'] ?? ($rel['NAME'] ?? $os);
        }

        $cores = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
        $cloud = $this->cloudMetadata();

        $ipv4 = $cloud['ipv4'] ?? '';
        if ($ipv4 === '') {
            $hostIp = trim((string) @shell_exec('hostname -I 2>/dev/null'));
            $ipv4   = $hostIp !== '' ? (explode(' ', $hostIp)[0] ?? '') : '';
        }

        return [
            'os'         => $os,
            'hostname'   => gethostname() ?: 'server',
            'cores'      => $cores > 0 ? $cores : null,
            'mem_total'  => $metrics['memory']['total'] ?? 0,
            'provider'   => $cloud['provider'] ?? null,
            'droplet_id' => $cloud['droplet_id'] ?? null,
            'region'     => $cloud['region'] ?? null,
            'ipv4'       => $ipv4 !== '' ? $ipv4 : null,
            'uptime'     => $metrics['uptime'] ?? '',
        ];
    }

    /**
     * DigitalOcean metadata service (link-local, only reachable from the droplet).
     * Returns [] when not on DO or the request times out, so callers degrade safely.
     */
    private function cloudMetadata(): array
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return [];
        }
        $ctx = stream_context_create(['http' => ['timeout' => 0.4, 'ignore_errors' => true]]);
        $raw = @file_get_contents('http://169.254.169.254/metadata/v1.json', false, $ctx);
        if ($raw === false) {
            return [];
        }
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            return [];
        }
        return [
            'provider'   => 'DigitalOcean',
            'droplet_id' => isset($d['droplet_id']) ? (string) $d['droplet_id'] : null,
            'region'     => $d['region'] ?? null,
            'ipv4'       => $d['interfaces']['public'][0]['ipv4']['ip_address'] ?? '',
        ];
    }

    /**
     * "Needs Attention" advisor — computed only from data we actually have, so
     * every item is real. SSL-expiry / OS-updates / disk-full forecast land in a
     * later slice once their data sources exist.
     */
    private function getAlerts(array $metrics, array $services): array
    {
        $alerts = [];

        foreach ($services as $name => $active) {
            if (!$active) {
                $alerts[] = ['level' => 'danger', 'icon' => 'ti-player-stop', 'text' => t('dash.alert.service_down', ['svc' => $name])];
            }
        }

        $disk = (float) ($metrics['disk']['percent'] ?? 0);
        if ($disk >= 80) {
            $alerts[] = ['level' => 'warn', 'icon' => 'ti-device-floppy', 'text' => t('dash.alert.disk_high', ['pct' => $disk])];
        }

        $mem = (float) ($metrics['memory']['percent'] ?? 0);
        if ($mem >= 90) {
            $alerts[] = ['level' => 'warn', 'icon' => 'ti-cpu-2', 'text' => t('dash.alert.mem_high', ['pct' => $mem])];
        }

        return $alerts;
    }
}
