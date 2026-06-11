<?php
/**
 * AidiPanel — Global helper functions
 */

declare(strict_types=1);

/**
 * Escape HTML output
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Active UI locale. Defaults to English; PANEL_LOCALE may be defined in the
 * bootstrap (later: read from panel settings / user preference).
 */
function current_locale(): string
{
    return defined('PANEL_LOCALE') ? PANEL_LOCALE : 'en';
}

/**
 * Load (and cache) a locale's string table from app/Lang/<locale>.php
 */
function lang_load(string $locale): array
{
    static $cache = [];
    if (isset($cache[$locale])) {
        return $cache[$locale];
    }
    $file = APP_ROOT . '/Lang/' . $locale . '.php';
    $cache[$locale] = file_exists($file) ? (array) require $file : [];
    return $cache[$locale];
}

/**
 * Translate a key for the current locale.
 * - Falls back to English, then to the key itself, so a missing string is
 *   visible but never fatal.
 * - {placeholder} tokens are replaced from $params; param values are HTML-escaped
 *   (they may carry user data such as a domain), the template itself is trusted.
 */
function t(string $key, array $params = []): string
{
    $locale  = current_locale();
    $strings = lang_load($locale);
    $text    = $strings[$key]
        ?? ($locale === 'en' ? null : (lang_load('en')[$key] ?? null))
        ?? $key;

    if ($params) {
        $repl = [];
        foreach ($params as $k => $v) {
            $repl['{' . $k . '}'] = e($v);
        }
        $text = strtr($text, $repl);
    }
    return $text;
}

/**
 * Render a view file with data
 */
function view(string $template, array $data = [], bool $return = false): string
{
    $file = APP_ROOT . '/Views/' . ltrim($template, '/') . '.php';
    if (!file_exists($file)) {
        throw new \RuntimeException("View not found: {$file}");
    }
    extract($data, EXTR_SKIP);
    if ($return) {
        ob_start();
        include $file;
        return ob_get_clean() ?: '';
    }
    include $file;
    return '';
}

/**
 * Render view inside base layout
 */
function layout(string $template, array $data = [], string $layoutFile = 'layout/base'): void
{
    $data['_content'] = view($template, $data, true);
    view($layoutFile, $data);
}

/**
 * Redirect to a URL
 */
function redirect(string $url, int $code = 302): never
{
    http_response_code($code);
    header("Location: {$url}");
    exit;
}

/**
 * Return JSON response and exit
 */
function json(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Get URL for a path
 */
function url(string $path = ''): string
{
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    return $base . '/' . ltrim($path, '/');
}

/**
 * Get/set flash message
 */
function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }
    $msg = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $msg;
}

/**
 * Abort with HTTP error
 */
function abort(int $code, string $message = ''): never
{
    http_response_code($code);
    $titles = [403 => 'Forbidden', 404 => 'Not Found', 500 => 'Server Error'];
    $title  = $titles[$code] ?? 'Error';
    if (empty($message)) {
        $message = $title;
    }
    echo "<!DOCTYPE html><html><head><title>{$code} {$title}</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#0f0f11;color:#e8e8e8;}
    .box{text-align:center}.code{font-size:72px;font-weight:700;color:#534AB7;}.msg{color:#9ca3af;}</style>
    </head><body><div class='box'><div class='code'>{$code}</div><div class='msg'>" . e($message) . "</div></div></body></html>";
    exit;
}

/**
 * Format bytes to human-readable
 */
function format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $units[$i];
}

/**
 * Check if string is a valid domain
 */
function is_valid_domain(string $domain): bool
{
    return (bool) preg_match(
        '/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/',
        $domain
    );
}

function is_valid_proxy_url(string $url): bool
{
    if (preg_match('/[\r\n;\s]/', $url)) {
        return false;
    }

    $parts = parse_url($url);
    if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
        return false;
    }

    return !empty($parts['host']);
}

function web_cli_allowed_commands(): array
{
    return [
        'site:add', 'site:delete', 'site:list',
        'vhost:save',
        'cache:status', 'cache:purge', 'cache:enable', 'cache:disable',
        'db:add', 'db:delete', 'db:list', 'db:backup',
        'php:list', 'php:version', 'php:restart', 'php:install',
        'ssl:install', 'ssl:renew', 'ssl:status', 'ssl:import',
        'service:status', 'service:start', 'service:stop', 'service:restart',
        'system:info',
    ];
}

function is_web_cli_command_allowed(string $command): bool
{
    return in_array($command, web_cli_allowed_commands(), true);
}

/**
 * PHP version policy from /etc/aidipanel/php.conf (Patch A single source of truth).
 * Parsed directly (no sudo) for speed; falls back to a sane built-in if the file
 * is missing. Returns: ['default'=>'8.4', 'available'=>['8.2','8.3','8.4','8.5']].
 */
function php_policy(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $default   = '8.4';
    $available = ['8.2', '8.3', '8.4', '8.5'];

    $conf = '/etc/aidipanel/php.conf';
    if (is_readable($conf)) {
        $txt = (string) file_get_contents($conf);
        if (preg_match('/^\s*PHP_DEFAULT_VERSION\s*=\s*"?([0-9.]+)"?/m', $txt, $m)) {
            $default = $m[1];
        }
        if (preg_match('/^\s*PHP_AVAILABLE_VERSIONS\s*=\s*"?([0-9. ]+)"?/m', $txt, $m)) {
            $parsed = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
            if (!empty($parsed)) $available = $parsed;
        }
    }

    return $cache = ['default' => $default, 'available' => $available];
}

/**
 * True if PHP <ver> is genuinely installed.
 *
 * Probes the FPM *binary* (/usr/sbin/php-fpm<ver>, shipped by the phpX.Y-fpm
 * package) — NOT the /etc/php/<ver> directory. `apt purge` removes the binary
 * but can leave the config dir behind, and aidipanel itself writes files into
 * that dir (99-aidipanel.ini, pool.d/*.conf), so a dir check reads a purged
 * version as still installed. This mirrors the CLI's `_php_installed` exactly so
 * panel-state and CLI-state agree. www-data can stat the world-readable binary
 * without sudo.
 */
function php_is_installed(string $ver): bool
{
    return is_file("/usr/sbin/php-fpm{$ver}");
}

/**
 * Per-version status for the Admin > PHP page and the wizard/switcher.
 * installed = the php<ver>-fpm binary exists (see php_is_installed()).
 * running   = php<ver>-fpm service is active.
 * Returns an ordered map: ver => ['installed','running','default','label'].
 */
function php_versions_status(): array
{
    $policy = php_policy();
    $labels = [
        '8.5' => 'php.label.latest',
        '8.4' => 'php.label.default',
        '8.3' => 'php.label.compat',
        '8.2' => 'php.label.legacy',
    ];

    $out = [];
    foreach ($policy['available'] as $ver) {
        $installed = php_is_installed($ver);
        $running   = $installed
            && trim((string) shell_exec("systemctl is-active php{$ver}-fpm 2>/dev/null")) === 'active';
        $out[$ver] = [
            'installed' => $installed,
            'running'   => $running,
            'default'   => $ver === $policy['default'],
            'label'     => $labels[$ver] ?? 'php.label.compat',
        ];
    }
    return $out;
}

/**
 * Safe CLI runner - use sudo when invoked from the web (www-data)
 */
function run_cli(string $command, array $args = []): array
{
    $binary = '/usr/local/bin/aidipanel';
    if (!file_exists($binary)) {
        return ['success' => false, 'output' => 'AidiPanel CLI not found: ' . $binary, 'code' => 1];
    }

    if (!is_web_cli_command_allowed($command)) {
        return ['success' => false, 'output' => 'Command not allowed from web panel.', 'code' => 126];
    }

    // Make sure the log dir is writable
    $logDir = '/opt/aidipanel/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0770, true);
    }

    // Build safe command - set NO_COLOR so the output has no ANSI escape codes
    $safeArgs = array_map('escapeshellarg', $args);
    $cmdParts = [escapeshellcmd($binary), escapeshellarg($command), ...$safeArgs];
    $cmd      = 'NO_COLOR=1 ' . implode(' ', $cmdParts) . ' 2>&1';

    // Use sudo from the web panel. The wrapper escapes PHP-FPM's read-only mount namespace.
    $currentUser = trim((string)(shell_exec('whoami 2>/dev/null') ?: ''));
    if ($currentUser !== 'root' && file_exists('/usr/bin/sudo')) {
        $runner = file_exists('/usr/local/sbin/aidipanel-web-run')
            ? '/usr/local/sbin/aidipanel-web-run'
            : $binary;
        $cmd = '/usr/bin/sudo ' . escapeshellcmd($runner) . ' ' . escapeshellarg($command) . ' ' . implode(' ', $safeArgs) . ' 2>&1';
    }

    $output   = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    // Strip ANSI escape codes from the output (fallback if NO_COLOR is not honored)
    $cleanOutput = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', implode("\n", $output));

    return [
        'success' => $exitCode === 0,
        'output'  => $cleanOutput,
        'code'    => $exitCode,
    ];
}

/**
 * Guard an on-demand PHP install (site:add / php:version onto a not-yet-installed
 * version). Two concerns, both scoped to THIS request only:
 *
 *  1. Make the request run to completion (incl. the panel DB-write that follows
 *     the CLI call) even if the user reloads or closes the tab mid-install —
 *     `ignore_user_abort(true)` + `set_time_limit(0)`. A PHP install is the only
 *     long, idempotent panel operation, so these relaxations are NOT applied to
 *     normal requests.
 *  2. Stop two concurrent installs of the same version racing apt. A per-version
 *     `flock(LOCK_EX|LOCK_NB)` lets the first request win; a second returns
 *     busy=true so the caller can say "installation is already running". flock
 *     auto-releases when the handle closes or the process dies, so a crash never
 *     leaves a stuck lock.
 *
 * Caller pattern:
 *   if (!php_is_installed($ver)) {                  // only when a real install is needed
 *       $g = php_install_begin($ver);
 *       if (!$g['ok']) { $this->error("PHP {$ver} installation is already running. Please wait."); }
 *       $lock = $g['handle'];
 *   }
 *   $result = run_cli(...);                          // triggers the CLI auto-install
 *   if (isset($lock)) php_install_end($lock);
 *
 * Returns ['ok'=>bool, 'handle'=>resource|null, 'busy'=>bool].
 * ok=false (busy=true) means another install of the same version holds the lock.
 */
function php_install_begin(string $ver): array
{
    // This request may run for ~1–2 minutes and must finish its DB-write.
    @ignore_user_abort(true);
    @set_time_limit(0);

    $dir = STORAGE_ROOT . '/tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $safe = preg_replace('/[^0-9.]/', '', $ver);     // lock name from digits/dot only
    $fp   = @fopen($dir . "/php-install-{$safe}.lock", 'c');

    // If the lock file can't be created, degrade gracefully (run without the lock)
    // rather than block a legitimate install — the abort guard above still applies.
    if ($fp === false) {
        return ['ok' => true, 'handle' => null, 'busy' => false];
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return ['ok' => false, 'handle' => null, 'busy' => true];
    }
    return ['ok' => true, 'handle' => $fp, 'busy' => false];
}

/**
 * Release a lock taken by php_install_begin(). Safe to call with null. The lock
 * also auto-releases when the request ends, so this is the tidy path, not a
 * correctness requirement.
 */
function php_install_end($handle): void
{
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

/**
 * Read system metric from /proc
 */
function sys_cpu_percent(): float
{
    $f    = sys_get_temp_dir() . '/aidipanel_cpu.json';
    $stat = @file('/proc/stat');
    if (!$stat) return 0.0;
    $parts = explode(' ', preg_replace('/\s+/', ' ', trim($stat[0])));
    $vals  = array_map('intval', array_slice($parts, 1));
    $idle  = ($vals[3] ?? 0) + ($vals[4] ?? 0);
    $total = array_sum($vals);
    $busy  = $total - $idle;
    $curr  = ['total' => $total, 'busy' => $busy];

    $prev = null;
    if (is_readable($f)) {
        $d = @json_decode((string) file_get_contents($f), true);
        if (is_array($d) && isset($d['total'])) $prev = $d;
    }
    @file_put_contents($f, json_encode($curr), LOCK_EX);

    if ($prev === null) {
        // No prior inter-request sample — measure over 200ms right now
        usleep(200000);
        $stat2  = @file('/proc/stat');
        if (!$stat2) return 0.0;
        $parts2 = explode(' ', preg_replace('/\s+/', ' ', trim($stat2[0])));
        $vals2  = array_map('intval', array_slice($parts2, 1));
        $idle2  = ($vals2[3] ?? 0) + ($vals2[4] ?? 0);
        $total2 = array_sum($vals2);
        $busy2  = $total2 - $idle2;
        @file_put_contents($f, json_encode(['total' => $total2, 'busy' => $busy2]), LOCK_EX);
        $dt = $total2 - $total;
        $db = $busy2  - $busy;
        return $dt > 0 ? round($db / $dt * 100, 1) : 0.0;
    }

    $dt = $curr['total'] - $prev['total'];
    $db = $curr['busy']  - $prev['busy'];
    return $dt > 0 ? round($db / $dt * 100, 1) : 0.0;
}

function sys_net_rate(): array
{
    $f     = sys_get_temp_dir() . '/aidipanel_net.json';
    $lines = @file('/proc/net/dev') ?: [];
    $rx = 0; $tx = 0; $iface = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if (!str_contains($line, ':')) continue;
        [$name, $data] = explode(':', $line, 2);
        $name = trim($name);
        if ($name === 'lo') continue;
        $cols = preg_split('/\s+/', trim($data));
        $rxB  = (int) ($cols[0] ?? 0);
        $txB  = (int) ($cols[8] ?? 0);
        if ($rxB + $txB > $rx + $tx) { $iface = $name; $rx = $rxB; $tx = $txB; }
    }
    $now  = microtime(true);
    $curr = ['rx' => $rx, 'tx' => $tx, 'ts' => $now];
    $prev = null;
    if (is_readable($f)) {
        $d = @json_decode((string) file_get_contents($f), true);
        if (is_array($d) && isset($d['ts'])) $prev = $d;
    }
    @file_put_contents($f, json_encode($curr), LOCK_EX);
    if ($prev === null || $now <= $prev['ts']) {
        return ['rx_rate' => 0, 'tx_rate' => 0, 'iface' => $iface];
    }
    $elapsed = $now - $prev['ts'];
    return [
        'rx_rate' => max(0, ($rx - $prev['rx']) / $elapsed),
        'tx_rate' => max(0, ($tx - $prev['tx']) / $elapsed),
        'iface'   => $iface,
    ];
}

function sys_memory(): array
{
    $data = [];
    foreach (file('/proc/meminfo') as $line) {
        [$key, $val] = explode(':', $line);
        $data[trim($key)] = (int) trim(str_replace(' kB', '', $val));
    }
    $total     = $data['MemTotal'] ?? 0;
    $available = $data['MemAvailable'] ?? 0;
    $used      = $total - $available;
    return [
        'total'   => $total * 1024,
        'used'    => $used * 1024,
        'free'    => $available * 1024,
        'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
    ];
}

function sys_disk(string $path = '/'): array
{
    $total = disk_total_space($path);
    $free  = disk_free_space($path);
    $used  = $total - $free;
    return [
        'total'   => $total,
        'used'    => $used,
        'free'    => $free,
        'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
    ];
}

function sys_load(): array
{
    $load = sys_getloadavg();
    return ['1m' => $load[0], '5m' => $load[1], '15m' => $load[2]];
}

function sys_uptime(): string
{
    $uptime = (int) file_get_contents('/proc/uptime');
    $days   = intdiv($uptime, 86400);
    $hours  = intdiv($uptime % 86400, 3600);
    $mins   = intdiv($uptime % 3600, 60);
    return "{$days}d {$hours}h {$mins}m";
}

/**
 * Disk I/O throughput (bytes/sec) across physical block devices.
 * Reads /proc/diskstats and computes the delta against the previous sample,
 * mirroring sys_net_rate() so a single request yields a real rate.
 */
function sys_disk_io(): array
{
    $f     = sys_get_temp_dir() . '/aidipanel_diskio.json';
    $lines = @file('/proc/diskstats') ?: [];
    $readSectors = 0; $writeSectors = 0;
    foreach ($lines as $line) {
        $c    = preg_split('/\s+/', trim($line));
        $name = $c[2] ?? '';
        // physical disks only — skip partitions, loop, ram, dm-*
        if (!preg_match('/^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme\d+n\d+|mmcblk\d+)$/', $name)) continue;
        $readSectors  += (int) ($c[5] ?? 0);   // sectors read
        $writeSectors += (int) ($c[9] ?? 0);   // sectors written
    }
    $read  = $readSectors  * 512;
    $write = $writeSectors * 512;
    $now   = microtime(true);
    $curr  = ['r' => $read, 'w' => $write, 'ts' => $now];

    $prev = null;
    if (is_readable($f)) {
        $d = @json_decode((string) file_get_contents($f), true);
        if (is_array($d) && isset($d['ts'])) $prev = $d;
    }
    @file_put_contents($f, json_encode($curr), LOCK_EX);

    // first sample, clock skew, or counter reset (reboot) → no rate yet
    if ($prev === null || $now <= $prev['ts'] || $read < $prev['r']) {
        return ['read_rate' => 0, 'write_rate' => 0];
    }
    $elapsed = $now - $prev['ts'];
    return [
        'read_rate'  => max(0, ($read  - $prev['r']) / $elapsed),
        'write_rate' => max(0, ($write - $prev['w']) / $elapsed),
    ];
}
