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
        'php:list', 'php:version', 'php:restart',
        'ssl:install', 'ssl:renew', 'ssl:status',
        'service:status', 'service:start', 'service:stop', 'service:restart',
        'system:info',
    ];
}

function is_web_cli_command_allowed(string $command): bool
{
    return in_array($command, web_cli_allowed_commands(), true);
}
/**
 * Safe CLI runner — pakai sudo jika dijalankan dari web (www-data)
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

    // Pastikan log dir bisa ditulis
    $logDir = '/opt/aidipanel/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0770, true);
    }

    // Build safe command — set NO_COLOR agar output tidak ada ANSI escape codes
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

    // Strip ANSI escape codes dari output (fallback jika NO_COLOR tidak diterapkan)
    $cleanOutput = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', implode("\n", $output));

    return [
        'success' => $exitCode === 0,
        'output'  => $cleanOutput,
        'code'    => $exitCode,
    ];
}

/**
 * Read system metric from /proc
 */
function sys_cpu_percent(): float
{
    static $prev = null;
    $stat = file('/proc/stat');
    $line = explode(' ', preg_replace('/\s+/', ' ', trim($stat[0])));
    [, $user, $nice, $system, $idle, $iowait] = $line;
    $total   = $user + $nice + $system + $idle + $iowait;
    $busyNow = $user + $nice + $system;

    if ($prev === null) {
        $prev = ['total' => $total, 'busy' => $busyNow];
        usleep(100000); // 100ms sample
        return sys_cpu_percent();
    }

    $diffTotal = $total - $prev['total'];
    $diffBusy  = $busyNow - $prev['busy'];
    $prev = ['total' => $total, 'busy' => $busyNow];

    return $diffTotal > 0 ? round(($diffBusy / $diffTotal) * 100, 1) : 0.0;
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
