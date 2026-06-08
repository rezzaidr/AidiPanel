<?php
/**
 * AidiPanel — system metrics collector.
 *
 * Run every minute by cron (as www-data, see /etc/cron.d/aidipanel). Samples the
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

$prevFile = sys_get_temp_dir() . '/aidipanel_collector.json';
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
} catch (\Throwable $e) {
    fwrite(STDERR, 'collect-metrics: ' . $e->getMessage() . "\n");
    exit(1);
}
