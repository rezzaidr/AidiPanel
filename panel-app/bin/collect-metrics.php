<?php
/**
 * AidiPanel — system metrics collector.
 *
 * Run every minute by cron (as aidipanel, see /etc/cron.d/aidipanel). Samples the
 * server's CPU/memory/disk/load/network/disk-IO and appends one row to the
 * `metrics` SQLite table, so the dashboard can draw REAL historical charts for the
 * selected time range (CloudPanel-style) instead of only live data.
 *
 * /proc does not keep history, so we must record it ourselves. Rates (CPU, network,
 * disk-IO) are deltas against the previous sample → an average over the last minute,
 * which is exactly what a per-minute chart should show. Keep this lean (performance-first).
 *
 *   php /opt/aidipanel/bin/collect-metrics.php
 */
declare(strict_types=1);

define('PANEL_ROOT', dirname(__DIR__));        // /opt/aidipanel
define('APP_ROOT',   PANEL_ROOT . '/app');
define('PANEL_DIR',  '/opt/aidipanel');        // DB.php reads the SQLite path from here

spl_autoload_register(static function (string $class): void {
    $file = APP_ROOT . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) require_once $file;
});
require_once APP_ROOT . '/Core/helpers.php';

use Core\DB;
use Core\TrafficMetrics;

$collectorLock = @fopen(PANEL_DIR . '/storage/tmp/metrics-collector.lock', 'c');
if ($collectorLock === false || !flock($collectorLock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

function tc_state(DB $db, string $key): ?string
{
    $value = $db->value('SELECT value FROM traffic_state WHERE key = ?', [$key]);
    return $value === null ? null : (string) $value;
}

function tc_set_state(DB $db, string $key, string|int $value): void
{
    $db->run(
        'INSERT INTO traffic_state (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value',
        [$key, (string) $value]
    );
}

function tc_measure_cache_bytes(): ?int
{
    $paths = [];
    foreach (['/var/cache/nginx/fastcgi'] as $path) {
        if (is_dir($path)) $paths[] = $path;
    }
    foreach (glob('/var/cache/nginx/aidipanel/*/fastcgi', GLOB_ONLYDIR) ?: [] as $path) {
        $paths[] = $path;
    }
    if ($paths === []) return 0;

    $command = 'timeout 5s ionice -c3 nice -n 10 du -sb -- '
        . implode(' ', array_map('escapeshellarg', $paths))
        . " 2>/dev/null && printf '\\n__AIDIPANEL_DU_OK__\\n'";
    $output = @shell_exec($command);
    if (!is_string($output) || trim($output) === '') return null;

    $lines = preg_split('/\r?\n/', trim($output)) ?: [];
    if (array_pop($lines) !== '__AIDIPANEL_DU_OK__' || $lines === []) return null;
    $total = 0;
    foreach ($lines as $line) {
        if (preg_match('/^(\d+)\s/', $line, $match) !== 1) return null;
        $bytes = (int) $match[1];
        if ($bytes < 0 || $total > PHP_INT_MAX - $bytes) return null;
        $total += $bytes;
    }
    return $total;
}

function tc_collect(DB $db, int $now, ?int $cacheBytes, bool $cacheAttempted): void
{
    $domains = array_column($db->rows('SELECT domain FROM sites ORDER BY domain'), 'domain');
    $unreadableLogs = 0;
    $dataErrors = 0;
    $updates = [];
    foreach ($domains as $rawDomain) {
        $domain = (string) $rawDomain;
        if (!TrafficMetrics::validDomain($domain)) continue;
        $logPath = '/var/log/nginx/' . $domain . '-access.log';
        $stored = $db->row(
            'SELECT inode, byte_offset FROM traffic_cursors WHERE log_path = ?',
            [$logPath]
        );
        if (!is_file($logPath)) {
            if ($stored !== null) $dataErrors++;
            continue;
        }
        if (!is_readable($logPath)) {
            $unreadableLogs++;
            continue;
        }

        $cursor = $stored === null ? null : [
            'inode' => (int) $stored['inode'],
            'offset' => (int) $stored['byte_offset'],
        ];
        $increment = TrafficMetrics::readIncrement($logPath, $cursor);
        if (!empty($increment['gap']) || !empty($increment['error'])) $dataErrors++;
        $next = $increment['cursor'] ?? null;
        if (!is_array($next)) continue;

        $minutes = [];
        foreach ($increment['records'] as $line) {
            $parsed = TrafficMetrics::parseLine((string) $line);
            if ($parsed === null
                || $parsed['timestamp'] < $now - 8 * 86400
                || $parsed['timestamp'] > $now + 300) {
                $dataErrors++;
                continue;
            }
            $minute = (int) $parsed['minute'];
            if (!isset($minutes[$minute])) {
                $minutes[$minute] = [
                    'requests' => 0, 'cache_hits' => 0, 'cache_misses' => 0,
                    'cache_bypass' => 0, 'cache_bytes' => 0,
                ];
            }
            foreach (array_keys($minutes[$minute]) as $key) {
                $minutes[$minute][$key] += (int) $parsed[$key];
            }
        }
        $updates[] = compact('domain', 'logPath', 'next', 'minutes');
    }

    $db->immediateTransaction(static function (DB $db) use ($updates, $unreadableLogs, $dataErrors, $now, $cacheBytes, $cacheAttempted): void {
        if (tc_state($db, 'coverage_started_at') === null || $unreadableLogs > 0 || $dataErrors > 0) {
            tc_set_state($db, 'coverage_started_at', $now);
        }

        foreach ($updates as $update) {
            $domain = $update['domain'];
            $logPath = $update['logPath'];
            $next = $update['next'];
            $minutes = $update['minutes'];
            foreach ($minutes as $minute => $counts) {
                $db->run(
                    'INSERT INTO traffic_metrics
                        (minute, domain, requests, cache_hits, cache_misses, cache_bypass, cache_bytes)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT(minute, domain) DO UPDATE SET
                        requests = requests + excluded.requests,
                        cache_hits = cache_hits + excluded.cache_hits,
                        cache_misses = cache_misses + excluded.cache_misses,
                        cache_bypass = cache_bypass + excluded.cache_bypass,
                        cache_bytes = cache_bytes + excluded.cache_bytes',
                    [
                        $minute, $domain, $counts['requests'], $counts['cache_hits'],
                        $counts['cache_misses'], $counts['cache_bypass'], $counts['cache_bytes'],
                    ]
                );
            }

            $db->run(
                'INSERT INTO traffic_cursors (log_path, inode, byte_offset, updated_at)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT(log_path) DO UPDATE SET
                    inode = excluded.inode,
                    byte_offset = excluded.byte_offset,
                    updated_at = excluded.updated_at',
                [$logPath, (int) $next['inode'], (int) $next['offset'], $now]
            );
        }

        $db->run('DELETE FROM traffic_metrics WHERE minute < ?', [$now - 8 * 86400]);
        tc_set_state($db, 'last_collected_at', $now);
        tc_set_state($db, 'unreadable_logs', $unreadableLogs);
        tc_set_state($db, 'data_errors', $dataErrors);
        if ($cacheAttempted) tc_set_state($db, 'cache_last_attempt_at', $now);
        if ($cacheBytes !== null) {
            tc_set_state($db, 'cache_bytes', $cacheBytes);
            tc_set_state($db, 'cache_checked_at', $now);
        }
    });
}

/** Raw kernel counters (cpu jiffies, network bytes, disk bytes). */
function mc_read(): array
{
    $cpuTotal = 0; $cpuBusy = 0;
    $stat = @file('/proc/stat');
    if ($stat) {
        $p    = explode(' ', preg_replace('/\s+/', ' ', trim($stat[0])));
        $v    = array_map('intval', array_slice($p, 1));
        $idle = ($v[3] ?? 0) + ($v[4] ?? 0);     // idle + iowait
        $cpuTotal = array_sum($v);
        $cpuBusy  = $cpuTotal - $idle;
    }

    $rx = 0; $tx = 0;
    foreach (@file('/proc/net/dev') ?: [] as $line) {
        if (!str_contains($line, ':')) continue;
        [$name, $data] = explode(':', $line, 2);
        if (trim($name) === 'lo') continue;
        $c   = preg_split('/\s+/', trim($data));
        $rx += (int) ($c[0] ?? 0);
        $tx += (int) ($c[8] ?? 0);
    }

    $drs = 0; $dws = 0;
    foreach (@file('/proc/diskstats') ?: [] as $line) {
        $c    = preg_split('/\s+/', trim($line));
        $name = $c[2] ?? '';
        if (!preg_match('/^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme\d+n\d+|mmcblk\d+)$/', $name)) continue;
        $drs += (int) ($c[5] ?? 0);   // sectors read
        $dws += (int) ($c[9] ?? 0);   // sectors written
    }

    return [
        'cpu_total' => $cpuTotal, 'cpu_busy' => $cpuBusy,
        'rx' => $rx, 'tx' => $tx,
        'dr' => $drs * 512, 'dw' => $dws * 512,
        'ts' => microtime(true),
    ];
}

/** Compute rates between two counter snapshots. */
function mc_rates(array $a, array $b): array
{
    $dt = $b['ts'] - $a['ts'];
    if ($dt <= 0) $dt = 1;
    $dTotal = $b['cpu_total'] - $a['cpu_total'];
    $dBusy  = $b['cpu_busy']  - $a['cpu_busy'];
    $cpu    = $dTotal > 0 ? max(0.0, min(100.0, $dBusy / $dTotal * 100)) : 0.0;
    return [
        'cpu'    => round($cpu, 1),
        'net_rx' => $b['rx'] >= $a['rx'] ? max(0, ($b['rx'] - $a['rx']) / $dt) : 0,
        'net_tx' => $b['tx'] >= $a['tx'] ? max(0, ($b['tx'] - $a['tx']) / $dt) : 0,
        'dio_r'  => $b['dr'] >= $a['dr'] ? max(0, ($b['dr'] - $a['dr']) / $dt) : 0,
        'dio_w'  => $b['dw'] >= $a['dw'] ? max(0, ($b['dw'] - $a['dw']) / $dt) : 0,
    ];
}

$prevFile = PANEL_DIR . '/storage/tmp/system-metrics.json';
$cur  = mc_read();

$prev = null;
if (is_readable($prevFile)) {
    $d = @json_decode((string) file_get_contents($prevFile), true);
    if (is_array($d) && isset($d['ts'])) $prev = $d;
}

if ($prev === null || ($cur['ts'] - $prev['ts']) > 600) {
    // first run, or the previous sample is stale: take a fresh 1s delta so the
    // first stored point isn't zero.
    usleep(1_000_000);
    $cur2  = mc_read();
    $rates = mc_rates($cur, $cur2);
    @file_put_contents($prevFile, json_encode($cur2), LOCK_EX);
} else {
    $rates = mc_rates($prev, $cur);
    @file_put_contents($prevFile, json_encode($cur), LOCK_EX);
}

$mem  = sys_memory();
$disk = sys_disk('/');
$load = sys_load();

try {
    $db = DB::instance();
    $db->run(
        'INSERT OR REPLACE INTO metrics (ts, cpu, mem, disk, l1, l5, l15, net_rx, net_tx, dio_r, dio_w)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            time(),
            $rates['cpu'],
            (float) ($mem['percent'] ?? 0),
            (float) ($disk['percent'] ?? 0),
            (float) ($load['1m'] ?? 0),
            (float) ($load['5m'] ?? 0),
            (float) ($load['15m'] ?? 0),
            round($rates['net_rx']), round($rates['net_tx']),
            round($rates['dio_r']),  round($rates['dio_w']),
        ]
    );
    // retention: keep ~8 days of per-minute samples
    $db->run('DELETE FROM metrics WHERE ts < ?', [time() - 8 * 86400]);

    $now = time();
    $lastCacheAttempt = (int) (tc_state($db, 'cache_last_attempt_at') ?? 0);
    $cacheAttempted = $now - $lastCacheAttempt >= 300;
    $cacheBytes = $cacheAttempted ? tc_measure_cache_bytes() : null;
    tc_collect($db, $now, $cacheBytes, $cacheAttempted);
} catch (\Throwable $e) {
    fwrite(STDERR, 'collect-metrics: ' . $e->getMessage() . "\n");
    exit(1);
}
