<?php
declare(strict_types=1);
namespace Controllers;

class DashboardController extends BaseController
{
    public function index(array $params = []): void
    {
        $metrics   = $this->getMetrics();
        $vps       = $this->getVpsStatus($metrics);
        $services  = $this->getServicesStatus();
        $analytics = $this->getTrafficAnalytics();
        $history   = $this->getHistory((string) $this->request->get('range', '1h'));
        $sites     = $this->getTopSites();
        $alerts    = $this->getAlerts($metrics, $services);

        $this->view('dashboard/index', compact('metrics', 'vps', 'analytics', 'history', 'sites', 'alerts'));
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
        $services = ['nginx', db_service(), 'redis-server', 'aidipanel-fpm'];
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

    /**
     * Traffic & cache analytics from the Nginx access log.
     *
     * Nginx logs the FastCGI cache result on every line (`cache:$upstream_cache_status`,
     * see the `main` log_format in install.sh), so hit ratio, cached-vs-origin split,
     * bandwidth saved and a per-minute time series are all real — no separate metrics
     * collector needed (that one is for CPU/mem history, which /proc does not keep).
     *
     * Bounded to the tail of the log and cached for 60s to stay light on busy servers
     * (the performance-first brand: the panel must not become the bottleneck).
     */
    private function getTrafficAnalytics(): array
    {
        $empty = [
            'req_today'   => 0,
            'hit_ratio'   => 0.0,
            'cached'      => 0,
            'origin'      => 0,
            'uncacheable' => 0,
            'bw_saved'    => 0,
            'bw_total'    => 0,
            'cache_size'  => $this->fastcgiCacheSize(),
            'has_data'    => false,
            'series'      => ['labels' => [], 'cached' => [], 'origin' => []],
        ];

        $cacheFile = sys_get_temp_dir() . '/aidipanel_traffic.json';
        if (is_readable($cacheFile) && (time() - (int) filemtime($cacheFile)) < 60) {
            $hit = @json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($hit) && isset($hit['has_data'])) {
                return $hit;
            }
        }

        $log = '/var/log/nginx/access.log';
        if (!is_readable($log)) {
            @file_put_contents($cacheFile, json_encode($empty), LOCK_EX);
            return $empty;
        }

        $raw = @shell_exec('tail -n 50000 ' . escapeshellarg($log) . ' 2>/dev/null');
        if (!is_string($raw) || $raw === '') {
            @file_put_contents($cacheFile, json_encode($empty), LOCK_EX);
            return $empty;
        }

        $today       = date('d/M/Y');           // matches Nginx $time_local, e.g. 08/Jun/2026
        $cached      = 0;
        $origin      = 0;
        $uncacheable = 0;
        $bwSaved     = 0;
        $bwTotal     = 0;
        $buckets     = [];                       // 'HH:MM' => [cachedCount, originCount]

        // groups: 1=hour 2=minute 3=body_bytes_sent 4=cache status (date matched but not captured)
        $re = '#\[\d{2}/[A-Za-z]{3}/\d{4}:(\d{2}):(\d{2}):\d{2}[^\]]*\]\s"[^"]*"\s\d{3}\s(\d+|-)\s.*cache:(\S+)#';

        foreach (explode("\n", $raw) as $line) {
            if ($line === '' || strpos($line, $today) === false) continue;   // today only (fast pre-filter)
            if (!preg_match($re, $line, $m)) continue;

            $bytes   = $m[3] === '-' ? 0 : (int) $m[3];
            $status  = strtoupper($m[4]);
            $bwTotal += $bytes;

            $key = $m[1] . ':' . $m[2];
            if (!isset($buckets[$key])) $buckets[$key] = [0, 0];

            if (in_array($status, ['HIT', 'STALE', 'UPDATING', 'REVALIDATED'], true)) {
                $cached++; $bwSaved += $bytes; $buckets[$key][0]++;
            } elseif (in_array($status, ['MISS', 'EXPIRED', 'BYPASS'], true)) {
                $origin++; $buckets[$key][1]++;
            } else {
                $uncacheable++;   // '-' = static / non-FastCGI: not part of cache ratio or the chart
            }
        }

        $cacheable = $cached + $origin;
        $reqToday  = $cacheable + $uncacheable;

        ksort($buckets);                          // 'HH:MM' is zero-padded → chronological
        $slice = array_slice($buckets, -60, null, true);

        $result = [
            'req_today'   => $reqToday,
            'hit_ratio'   => $cacheable > 0 ? round($cached / $cacheable * 100, 1) : 0.0,
            'cached'      => $cached,
            'origin'      => $origin,
            'uncacheable' => $uncacheable,
            'bw_saved'    => $bwSaved,
            'bw_total'    => $bwTotal,
            'cache_size'  => $this->fastcgiCacheSize(),
            'has_data'    => $reqToday > 0,
            'series'      => [
                'labels' => array_values(array_keys($slice)),
                'cached' => array_values(array_map(static fn($b) => $b[0], $slice)),
                'origin' => array_values(array_map(static fn($b) => $b[1], $slice)),
            ],
        ];

        @file_put_contents($cacheFile, json_encode($result), LOCK_EX);
        return $result;
    }

    private function fastcgiCacheSize(): string
    {
        $dir = '/var/cache/nginx/fastcgi';
        if (!is_dir($dir)) return '—';
        $out = @shell_exec('du -sh ' . escapeshellarg($dir) . ' 2>/dev/null');
        if (!is_string($out) || $out === '') return '—';
        return trim(explode("\t", trim($out))[0] ?? '—');
    }

    private function getTopSites(): array
    {
        $sites = $this->db->rows('SELECT * FROM sites ORDER BY created_at DESC LIMIT 5');
        foreach ($sites as &$site) {
            $logFile = '/var/log/nginx/' . $site['domain'] . '-access.log';
            $site['req_today'] = 0;
            if (is_readable($logFile)) {
                $out = @shell_exec('wc -l < ' . escapeshellarg($logFile) . ' 2>/dev/null');
                $site['req_today'] = (int) trim((string) $out);
            }
        }
        unset($site);
        usort($sites, fn($a, $b) => $b['req_today'] <=> $a['req_today']);
        return $sites;
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

    private function cloudMetadata(): array
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return [];
        }
        $ctx = stream_context_create(['http' => ['timeout' => 0.4, 'ignore_errors' => true]]);
        $raw = @file_get_contents('http://169.254.169.254/metadata/v1.json', false, $ctx);
        if ($raw === false) return [];
        $d = json_decode($raw, true);
        if (!is_array($d)) return [];
        return [
            'provider'   => 'DigitalOcean',
            'droplet_id' => isset($d['droplet_id']) ? (string) $d['droplet_id'] : null,
            'region'     => $d['region'] ?? null,
            'ipv4'       => $d['interfaces']['public'][0]['ipv4']['ip_address'] ?? '',
        ];
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
