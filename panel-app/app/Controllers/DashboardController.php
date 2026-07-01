<?php
declare(strict_types=1);
namespace Controllers;

class DashboardController extends BaseController
{
    public function index(array $params = []): void
    {
        // Server identity, capacity, traffic and service health are admin-area data.
        // Managers/clients still get the dashboard shell plus their scoped site list,
        // but we do not even collect the server-wide values for their request.
        $showServerMetrics = \Core\Access::canAccessAdminArea();
        $metrics = $vps = $analytics = $history = $alerts = [];
        if ($showServerMetrics) {
            $metrics   = $this->getMetrics();
            $vps       = $this->getVpsStatus($metrics);
            $services  = $this->getServicesStatus();
            $analytics = $this->getTrafficAnalytics();
            $history   = $this->getHistory((string) $this->request->get('range', '1h'));
            $alerts    = $this->getAlerts($metrics, $services);
        }
        $sites = $this->getTopSites();

        $user    = \Core\Auth::user() ?? [];
        $profile = [];
        if (!empty($user['id'])) {
            $profile = $this->db->row(
                'SELECT username, first_name FROM users WHERE id = ? LIMIT 1',
                [(int) $user['id']]
            ) ?? [];
        }

        $firstName  = trim((string) ($profile['first_name'] ?? ''));
        $username   = trim((string) ($profile['username'] ?? $user['username'] ?? 'there'));
        $greetName  = $firstName !== '' ? $firstName : ($username !== '' ? $username : 'there');
        $welcomeKey = \Core\Auth::wasFirstLogin() ? 'dash.welcome_first' : 'dash.welcome_back';

        $this->view('dashboard/index', compact(
            'metrics', 'vps', 'analytics', 'history', 'sites', 'alerts',
            'greetName', 'welcomeKey', 'showServerMetrics'
        ));
    }

    public function apiMetrics(array $params = []): void
    {
        $this->json($this->getMetrics());
    }

    /**
     * Historical system metrics for the selected time range (CloudPanel-style),
     * recorded per-minute by bin/collect-metrics.php. Used both server-side (the
     * dashboard embeds it and reloads on range change) and as a JSON endpoint.
     */
    public function apiHistory(array $params = []): void
    {
        $this->json($this->getHistory((string) $this->request->get('range', '1h')));
    }

    private function getHistory(string $range): array
    {
        $ranges = ['30m' => 30, '1h' => 60, '3h' => 180, '6h' => 360, '12h' => 720];
        if (!isset($ranges[$range])) $range = '1h';
        $rangeMin = $ranges[$range];
        $now      = time();
        $since    = $now - $rangeMin * 60;

        try {
            $rows = $this->db->rows('SELECT * FROM metrics WHERE ts >= ? ORDER BY ts ASC', [$since]);
        } catch (\Throwable $e) {
            $rows = [];   // metrics table not created yet (collector hasn't run / old schema)
        }

        $out = ['range' => $range, 'labels' => [], 'cpu' => [], 'mem' => [], 'disk' => [], 'l1' => [], 'l5' => [], 'l15' => [], 'nin' => [], 'nout' => [], 'dr' => [], 'dw' => []];
        if (!$rows) return $out;

        // Downsample to 7 evenly-spaced points (6 gaps) anchored at "now" — a clean
        // CloudPanel-style x-axis (30m → every 5m, 1h → 10m, 12h → 2h) instead of one
        // dot per collected minute. Each point = the average of its interval bucket;
        // empty buckets are skipped so fresh/partial history still draws.
        $points = 7;
        $step    = (int) ($rangeMin / ($points - 1)) * 60;          // seconds between points
        $buckets = [];                                              // key 0 = oldest … 6 = now
        foreach ($rows as $r) {
            $age = (int) round(($now - (int) $r['ts']) / $step);   // 0 = now
            $key = ($points - 1) - max(0, min($points - 1, $age));
            $buckets[$key][] = $r;
        }
        ksort($buckets);

        $avg = static function (array $rws, string $col): float {
            $sum = 0.0;
            foreach ($rws as $r) $sum += (float) $r[$col];
            return $sum / count($rws);
        };
        foreach ($buckets as $key => $rws) {
            $ts = $now - (($points - 1) - $key) * $step;
            $out['labels'][] = date('H:i', $ts);
            $out['cpu'][]    = round($avg($rws, 'cpu'), 1);
            $out['mem'][]    = round($avg($rws, 'mem'), 1);
            $out['disk'][]   = round($avg($rws, 'disk'), 1);
            $out['l1'][]     = round($avg($rws, 'l1'), 2);
            $out['l5'][]     = round($avg($rws, 'l5'), 2);
            $out['l15'][]    = round($avg($rws, 'l15'), 2);
            $out['nin'][]    = round($avg($rws, 'net_rx') * 8 / 1e6, 2);   // bytes/s → Mbps
            $out['nout'][]   = round($avg($rws, 'net_tx') * 8 / 1e6, 2);
            $out['dr'][]     = round($avg($rws, 'dio_r') / 1048576, 2);    // bytes/s → MB/s
            $out['dw'][]     = round($avg($rws, 'dio_w') / 1048576, 2);
        }
        return $out;
    }

    private function getMetrics(): array
    {
        return [
            'cpu'     => sys_cpu_percent(),
            'memory'  => sys_memory(),
            'disk'    => sys_disk('/'),
            'load'    => sys_load(),
            'uptime'  => sys_uptime(),
            'net'     => sys_net_rate(),
            'disk_io' => sys_disk_io(),
        ];
    }

    private function getServicesStatus(): array
    {
        $services = ['nginx', db_service(), 'redis-server'];
        foreach (php_versions_status() as $ver => $s) {
            if ($s['installed']) $services[] = "php{$ver}-fpm";
        }
        $result   = [];
        foreach ($services as $svc) {
            $status = trim((string) shell_exec("systemctl is-active " . escapeshellarg($svc) . " 2>/dev/null"));
            if ($status === '') continue;
            $result[$svc] = $status === 'active';
        }
        return $result;
    }

    /** Global origin analytics persisted by the existing per-minute collector. */
    private function getTrafficAnalytics(): array
    {
        $now = time();
        $dayStart = $this->trafficDayStart($now);
        $state = [];
        foreach ($this->db->rows('SELECT key, value FROM traffic_state') as $row) {
            $state[(string) $row['key']] = (string) $row['value'];
        }

        $lastCollected = isset($state['last_collected_at']) ? (int) $state['last_collected_at'] : null;
        $coverageStarted = max(0, (int) ($state['coverage_started_at'] ?? 0));
        $queryStart = max($dayStart, $coverageStarted);
        $status = \Core\TrafficMetrics::collectorStatus(
            $lastCollected,
            $now,
            (int) ($state['unreadable_logs'] ?? 0),
            (int) ($state['data_errors'] ?? 0)
        );
        $summary = \Core\TrafficMetrics::summarize([]);
        $series = ['labels' => [], 'cached' => [], 'origin' => []];

        if ($status === 'ready') {
            $totals = $this->db->row(
                'SELECT COALESCE(SUM(requests), 0) AS requests,
                        COALESCE(SUM(cache_hits), 0) AS cache_hits,
                        COALESCE(SUM(cache_misses), 0) AS cache_misses,
                        COALESCE(SUM(cache_bypass), 0) AS cache_bypass,
                        COALESCE(SUM(cache_bytes), 0) AS cache_bytes
                   FROM traffic_metrics
                  WHERE minute >= ? AND minute <= ?',
                [$queryStart, $now]
            ) ?? [];
            $summary = \Core\TrafficMetrics::summarize($totals);

            $endMinute = intdiv($now, 60) * 60;
            $startMinute = max($queryStart, $endMinute - 59 * 60);
            $rows = $this->db->rows(
                'SELECT minute,
                        SUM(requests) AS requests,
                        SUM(cache_hits) AS cache_hits,
                        SUM(cache_misses) AS cache_misses,
                        SUM(cache_bypass) AS cache_bypass,
                        SUM(cache_bytes) AS cache_bytes
                   FROM traffic_metrics
                  WHERE minute >= ? AND minute <= ?
                  GROUP BY minute ORDER BY minute ASC',
                [$startMinute, $endMinute]
            );
            $filled = \Core\TrafficMetrics::zeroFill($rows, $startMinute, $endMinute);
            $timezone = new \DateTimeZone(current_user_tz());
            foreach ($filled as $minute => $counts) {
                $series['labels'][] = (new \DateTimeImmutable('@' . $minute))
                    ->setTimezone($timezone)->format('H:i');
                $series['cached'][] = $counts['cache_hits'];
                $series['origin'][] = max(0, $counts['requests'] - $counts['cache_hits']);
            }
        }

        $cacheBytes = null;
        $cacheCheckedAt = isset($state['cache_checked_at']) ? (int) $state['cache_checked_at'] : 0;
        if ($cacheCheckedAt > 0 && $cacheCheckedAt <= $now + 300 && $now - $cacheCheckedAt <= 900
            && isset($state['cache_bytes']) && ctype_digit($state['cache_bytes'])) {
            $cacheBytes = (int) $state['cache_bytes'];
        }

        return [
            'status' => $status,
            'last_updated' => $lastCollected,
            'coverage_started_at' => $coverageStarted > 0 ? $coverageStarted : null,
            'req_today' => $status === 'ready' ? $summary['requests'] : null,
            'hit_ratio' => $status === 'ready' ? $summary['hit_ratio'] : null,
            'cached' => $status === 'ready' ? $summary['cache_hits'] : null,
            'origin' => $status === 'ready' ? $summary['cache_misses'] : null,
            'bypass' => $status === 'ready' ? $summary['cache_bypass'] : null,
            'served_bytes' => $status === 'ready' ? $summary['cache_bytes'] : null,
            'cache_bytes' => $cacheBytes,
            'has_data' => $status === 'ready' && $summary['requests'] > 0,
            'series' => $series,
        ];
    }

    private function getTopSites(): array
    {
        // Scope to the signed-in user (admin/manager/viewer see all; a client sees
        // only assigned sites; a client with none sees nothing) — same rule as the
        // /sites list, so the dashboard never leaks other tenants' sites.
        $ids = \Core\Access::visibleSiteIds();
        if ($ids === []) {
            return [];
        }
        $collectorState = [];
        foreach ($this->db->rows('SELECT key, value FROM traffic_state') as $row) {
            $collectorState[(string) $row['key']] = (string) $row['value'];
        }
        $trafficStatus = \Core\TrafficMetrics::collectorStatus(
            isset($collectorState['last_collected_at']) ? (int) $collectorState['last_collected_at'] : null,
            time(),
            (int) ($collectorState['unreadable_logs'] ?? 0),
            (int) ($collectorState['data_errors'] ?? 0)
        );
        if ($trafficStatus !== 'ready') {
            if ($ids === null) {
                $sites = $this->db->rows('SELECT * FROM sites ORDER BY created_at DESC LIMIT 5');
            } else {
                $place = implode(',', array_fill(0, count($ids), '?'));
                $sites = $this->db->rows(
                    "SELECT * FROM sites WHERE id IN ({$place}) ORDER BY created_at DESC LIMIT 5",
                    $ids
                );
            }
            foreach ($sites as &$site) $site['req_today'] = null;
            unset($site);
            return $sites;
        }
        $now = time();
        $coverageStarted = max(0, (int) ($collectorState['coverage_started_at'] ?? 0));
        $params = [max($this->trafficDayStart($now), $coverageStarted), $now];
        $where = '';
        if ($ids !== null) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $where = "WHERE s.id IN ({$place})";
            array_push($params, ...$ids);
        }
        $sites = $this->db->rows(
            "SELECT s.*, COALESCE(SUM(tm.requests), 0) AS req_today
               FROM sites s
               LEFT JOIN traffic_metrics tm
                 ON tm.domain = s.domain AND tm.minute >= ? AND tm.minute <= ?
               {$where}
              GROUP BY s.id
              ORDER BY req_today DESC, s.created_at DESC
              LIMIT 5",
            $params
        );
        foreach ($sites as &$site) $site['req_today'] = (int) $site['req_today'];
        unset($site);
        return $sites;
    }

    private function trafficDayStart(int $now): int
    {
        $timezone = new \DateTimeZone(current_user_tz());
        return (new \DateTimeImmutable('@' . $now))
            ->setTimezone($timezone)
            ->setTime(0, 0)
            ->getTimestamp();
    }

    private function getVpsStatus(array $metrics): array
    {
        $os = PHP_OS;
        if (is_readable('/etc/os-release')) {
            $rel = @parse_ini_file('/etc/os-release');
            $os  = $rel['PRETTY_NAME'] ?? ($rel['NAME'] ?? $os);
        }

        $cores = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
        $cloud = $this->cloudMetadata();

        // Public IP remains provider-independent and also handles NAT clouds.
        $ipv4 = server_public_ip();

        return [
            'os'         => $os,
            'hostname'   => gethostname() ?: 'server',
            'cores'      => $cores > 0 ? $cores : null,
            'mem_total'  => $metrics['memory']['total'] ?? 0,
            'cloud_status' => $cloud['status'] ?? 'unknown',
            'provider'   => $cloud['provider'] ?? null,
            'instance_id' => $cloud['instance_id'] ?? null,
            'region'     => $cloud['region'] ?? null,
            'ipv4'       => $ipv4 !== '' ? $ipv4 : null,
            'uptime'     => $metrics['uptime'] ?? '',
        ];
    }

    private function cloudMetadata(): array
    {
        if (demo_mode()) {
            return \Core\CloudInstanceMetadata::unknown();
        }
        $result = run_cli('system:cloud-metadata');
        return $result['success']
            ? \Core\CloudInstanceMetadata::parse((string) $result['output'])
            : \Core\CloudInstanceMetadata::unknown();
    }

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
