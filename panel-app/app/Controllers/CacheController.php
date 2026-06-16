<?php
declare(strict_types=1);
namespace Controllers;

class CacheController extends BaseController
{
    public function index(array $params = []): void
    {
        $sites = $this->db->rows('SELECT domain, cache_enabled, type FROM sites ORDER BY domain');
        $stats = $this->getCacheStats();
        $this->view('cache/index', compact('sites', 'stats'));
    }

    public function apiStats(array $params = []): void
    {
        $this->json($this->getCacheStats());
    }

    public function purge(array $params = []): void
    {
        $domain = (string) $this->request->post('domain', '');
        $url    = (string) $this->request->post('url', '');

        $args = [];
        if ($url) {
            $args = ['--url', $url];
        } elseif ($domain) {
            if (!is_valid_domain($domain)) {
                $this->error('Invalid domain name.');
            }
            $args = ['--domain', $domain];
        } else {
            $args = ['--force'];
        }

        $result = run_cli('cache:purge', $args);

        if ($this->request->isAjax()) {
            $this->json(['success' => $result['success'], 'message' => $result['output']]);
        }

        if (!$result['success']) {
            $this->error('Cache purge failed: ' . $result['output']);
        }

        $label = $domain ?: ($url ?: 'all');
        \Core\DB::log('cache:purge', "Purged cache: {$label}");
        $this->success('Cache purged successfully.');
    }

    public function toggle(array $params = []): void
    {
        $domain = (string) $this->request->post('domain', '');
        $action = (string) $this->request->post('action', 'enable'); // enable|disable
        $installNginxHelper = $this->request->post('install_nginx_helper', '') === '1';

        if (!in_array($action, ['enable', 'disable'], true)) {
            $this->error('Invalid action.');
        }
        if (!is_valid_domain($domain)) {
            $this->error('Invalid domain name.');
        }

        $site = $this->db->row('SELECT type FROM sites WHERE domain = ?', [$domain]);
        if (!$site) {
            $this->error("Site not found: {$domain}");
        }

        if ($action === 'disable') {
            $installNginxHelper = false;
        }

        if ($installNginxHelper && ($site['type'] ?? '') !== 'wordpress') {
            $this->error('Nginx Helper can only be installed for WordPress sites.');
        }

        $args = ['--domain', $domain];
        if ($action === 'enable' && $installNginxHelper) {
            $args[] = '--install-nginx-helper';
        }

        // Installing Nginx Helper / enabling FastCGI can take a few seconds; release the
        // session lock first so concurrent same-session requests don't exhaust the pool.
        $this->unlockForLongOp();
        $result = run_cli("cache:{$action}", $args);
        if (!$result['success']) {
            $this->error("Cache {$action} failed: " . $result['output']);
        }

        $enabled = $action === 'enable' ? 1 : 0;
        $this->db->run('UPDATE sites SET cache_enabled = ? WHERE domain = ?', [$enabled, $domain]);
        \Core\DB::log("cache:{$action}", "Cache {$action}d for: {$domain}");

        $msg = "FastCGI cache {$action}d for {$domain}.";
        if ($action === 'enable' && $installNginxHelper) {
            $nh = parse_kv_output($result['output'])['nginx_helper'] ?? '';
            // Positive outcomes stay in the green message; problems become an amber
            // sub-line (flash 'success_warn') rendered inside the same success toast.
            $msg .= match ($nh) {
                'configured'               => ' Nginx Helper installed and configured.',
                'installed_not_configured' => ' Nginx Helper installed.',
                default                    => '',
            };
            $warn = match ($nh) {
                'installed_not_configured' => 'Finish Nginx Helper setup in WP Admin → Nginx Helper.',
                'install_failed'           => 'Nginx Helper could not be installed (check WP-CLI and network).',
                'skipped_no_wordpress'     => 'Nginx Helper skipped: WordPress is not installed at the web root yet.',
                'skipped_no_wpcli'         => 'Nginx Helper skipped: WP-CLI is not installed on the server.',
                default                    => '',
            };
            if ($warn !== '') {
                flash('success_warn', $warn);
            }
        }
        $this->success($msg);
    }

    public function config(array $params = []): void
    {
        $domain      = (string) $this->request->post('domain', '');
        $ttl         = (string) $this->request->post('ttl', '');
        $excludeRaw  = (string) $this->request->post('exclude_urls', '');
        // Always enforce the non-removable baseline + the user's own additions. Sending
        // the full list (never empty) lets a user clear their additions while the
        // session/login baseline can never be dropped — the CLI re-enforces it too.
        $cookieExtras = array_filter(array_map('trim', explode(',', (string) $this->request->post('bypass_cookies', ''))));
        $cookies      = implode(',', array_values(array_unique(array_merge(cache_baseline_cookies(), $cookieExtras))));
        $bypassQ     = $this->request->post('bypass_query', '')     === '1' ? 'on' : 'off';
        $staleReval  = $this->request->post('stale_revalidate', '') === '1' ? 'on' : 'off';
        $debugHeader = $this->request->post('debug_header', '')     === '1' ? 'on' : 'off';

        if (!is_valid_domain($domain)) {
            $this->error('Invalid domain.');
        }

        // Textarea (newline-separated) → comma-separated for CLI
        $excludeUrls = implode(',', array_filter(array_map('trim', explode("\n", $excludeRaw))));

        $args = ['--domain', $domain];
        if ($ttl !== '')         { $args[] = '--ttl';           $args[] = $ttl; }
        if ($excludeUrls !== '') { $args[] = '--exclude-urls';  $args[] = $excludeUrls; }
        $args[] = '--bypass-cookies';   $args[] = $cookies;
        $args[] = '--bypass-query';     $args[] = $bypassQ;
        $args[] = '--stale-revalidate'; $args[] = $staleReval;
        $args[] = '--debug-header';     $args[] = $debugHeader;

        $result = run_cli('cache:config', $args);
        if (!$result['success']) {
            $this->error('Failed to save cache config: ' . $result['output']);
        }

        \Core\DB::log('cache:config', "Cache config updated for: {$domain}");
        $this->success('Cache config saved.', "/sites/{$domain}?tab=performance");
    }

    public function redis(array $params = []): void
    {
        $action = (string) $this->request->post('action', '');
        if (!in_array($action, ['enable', 'disable', 'flush'], true)) {
            $this->error('Invalid action.');
        }

        $cmd    = "cache:redis-{$action}";
        $result = run_cli($cmd, []);
        if (!$result['success']) {
            $this->error("Redis {$action} failed: " . $result['output']);
        }

        \Core\DB::log($cmd, "Redis {$action}");

        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $back    = $referer !== '' ? $referer : '/';
        $this->success("Redis {$action} done.", $back);
    }

    /**
     * Per-site WordPress Object Cache (Redis) enable/disable/flush.
     * Calls the new per-site `cache:redis --action enable|disable|flush --domain` (NOT
     * the legacy global `cache:redis-*`). Streamed (progress bar) when stream=1.
     */
    public function objectCache(array $params = []): void
    {
        $domain = strtolower(trim((string) $this->request->post('domain', '')));
        $action = (string) $this->request->post('action', '');

        if (!in_array($action, ['enable', 'disable', 'flush'], true)) {
            $this->error('Invalid action.');
        }
        if (!is_valid_domain($domain)) {
            $this->error('Invalid domain name.');
        }
        if (!$this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain])) {
            $this->error("Site not found: {$domain}");
        }

        $args = ['--action', $action, '--domain', $domain];
        $tab  = "/sites/{$domain}?tab=performance";
        $okMsg = match ($action) {
            'enable'  => "Object cache enabled for {$domain}.",
            'disable' => "Object cache disabled for {$domain}.",
            'flush'   => "Object cache flushed for {$domain}.",
        };

        if ($this->request->post('stream') === '1') {
            $this->streamCli(
                'cache:redis',
                $args,
                function (array $r) use ($domain, $action, $tab, $okMsg): array {
                    \Core\DB::log("cache:redis:{$action}", "Object cache {$action} for: {$domain}");
                    return ['redirect' => $tab, 'message' => $okMsg];
                },
                fn(string $out): string => $this->objectCacheError($out)
            );
        }

        // Non-streamed (flush/disable via plain POST): release the session lock first so
        // this multi-second wp-cli op doesn't block other same-session requests (→ 502).
        $this->unlockForLongOp();
        $result = run_cli('cache:redis', $args);
        if (!$result['success']) {
            $this->error($this->objectCacheError($result['output']), $tab);
        }
        \Core\DB::log("cache:redis:{$action}", "Object cache {$action} for: {$domain}");
        $this->success($okMsg, $tab);
    }

    /** Map a `cache:redis` enable/disable `error=<code>` line to a friendly message. */
    private function objectCacheError(string $output): string
    {
        $code = preg_match('/^error=(\S+)/m', $output, $m) ? $m[1] : '';
        return match ($code) {
            'object_cache_unmanaged' => "This site's object cache was set up outside AidiPanel (different Redis prefix), so AidiPanel won't change it. Turn it off in WordPress first if you want AidiPanel to manage it.",
            'redis_service_down'     => "Redis isn't running. Start it in Admin Area → Services, then try again.",
            'wp_cli_missing'         => "WP-CLI isn't installed on this server, so object cache can't be managed.",
            'not_wordpress'          => "Object cache is a WordPress feature; this site isn't WordPress.",
            'wp_config_not_safe'     => "wp-config.php can't be edited safely (it's a symlink or sits outside the site). No changes were made.",
            'wp_config_not_writable' => "wp-config.php isn't writable. No changes were made.",
            'plugin_install_failed'  => "Couldn't install the Redis Object Cache plugin — check the server's network connection.",
            'plugin_activate_failed' => "Couldn't activate the Redis Object Cache plugin.",
            'dropin_enable_failed'   => "Couldn't enable the object cache; the changes were rolled back.",
            'object_cache_not_connected' => "Object cache isn't set up for this site yet, so there's nothing to flush.",
            'flush_scope_unsafe'     => "AidiPanel couldn't guarantee a per-site flush, so it stopped to avoid clearing other sites' cache. Nothing was flushed.",
            'flush_failed'           => "The object cache couldn't be flushed. Please try again.",
            'domain_not_found'       => "Site not found.",
            default                  => "Object cache operation failed. Please try again.",
        };
    }

    /**
     * Read-only per-site object cache metrics (keys + approx memory) as JSON.
     * Drives the Performance tab's live tiles without blocking page load.
     */
    public function objectCacheMetrics(array $params = []): void
    {
        $domain = strtolower(trim((string) $this->request->get('domain', '')));
        if (!is_valid_domain($domain)) {
            $this->json(['ok' => false, 'error' => 'invalid_domain'], 400);
        }
        if (!$this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain])) {
            $this->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $result = run_cli('cache:redis', ['--action', 'metrics', '--domain', $domain]);
        if (!$result['success']) {
            $this->json(['ok' => false, 'error' => 'cli_failed']);
        }

        $kv = parse_kv_output($result['output']);
        $this->json([
            'ok'      => true,
            'keys'    => $kv['site_keys']   ?? 'unknown',
            'memory'  => $kv['site_memory'] ?? 'unknown',
            'limited' => ($kv['metrics_limited'] ?? '0') === '1',
        ]);
    }

    /**
     * Read-only cache status for one URL: HIT/MISS/BYPASS + TTFB, as JSON.
     * Drives the "Check a URL" tool in the Page Cache card.
     */
    public function checkUrl(array $params = []): void
    {
        $domain = strtolower(trim((string) $this->request->get('domain', '')));
        $url    = trim((string) $this->request->get('url', ''));
        if (!is_valid_domain($domain)) {
            $this->json(['ok' => false, 'error' => 'invalid_domain'], 400);
        }
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            $this->json(['ok' => false, 'error' => 'invalid_url'], 400);
        }
        if (!$this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain])) {
            $this->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $result = run_cli('cache:page', ['--action', 'check', '--domain', $domain, '--url', $url]);
        $kv = parse_kv_output($result['output']);
        if (!$result['success']) {
            $this->json(['ok' => false, 'error' => $kv['error'] ?? 'cli_failed']);
        }
        $this->json([
            'ok'        => true,
            'status'    => $kv['status']    ?? 'unknown',
            'ttfb_ms'   => (int) ($kv['ttfb_ms']   ?? 0),
            'http_code' => (int) ($kv['http_code'] ?? 0),
        ]);
    }

    /**
     * Purge specific URLs from this site's FastCGI cache (textarea, one per line).
     * Plain POST + toast. Releases the session lock first (the grep can take a
     * moment) to avoid blocking the on-demand panel pool.
     */
    public function purgeUrls(array $params = []): void
    {
        $domain = strtolower(trim((string) $this->request->post('domain', '')));
        $raw    = (string) $this->request->post('urls', '');
        $tab    = "/sites/{$domain}?tab=performance";

        if (!is_valid_domain($domain)) {
            $this->error('Invalid domain name.');
        }
        if (!$this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain])) {
            $this->error("Site not found: {$domain}", $tab);
        }
        $urls = array_values(array_filter(array_map('trim', explode("\n", $raw))));
        if (empty($urls)) {
            $this->error('Enter at least one URL to purge.', $tab);
        }

        $this->unlockForLongOp();
        $result = run_cli('cache:page', ['--action', 'purge-url', '--domain', $domain, '--urls', implode(',', $urls)]);
        $kv = parse_kv_output($result['output']);
        if (!$result['success']) {
            $msg = ($kv['error'] ?? '') === 'url_not_in_domain'
                ? 'Every URL must belong to this site.'
                : 'Could not purge the URLs. Please try again.';
            $this->error($msg, $tab);
        }

        $purged   = (int) ($kv['purged']   ?? 0);
        $notfound = (int) ($kv['notfound'] ?? 0);
        \Core\DB::log('cache:page:purge-url', "Purged {$purged} URL(s) for: {$domain}");
        $this->success("Purged {$purged} URL(s)" . ($notfound ? ", {$notfound} not cached" : '') . '.', $tab);
    }

    public function opcacheRestart(array $params = []): void
    {
        $php    = (string) $this->request->post('php', '');
        $args   = ($php !== '') ? ['--php', $php] : [];
        $result = run_cli('cache:opcache-restart', $args);
        if (!$result['success']) {
            $this->error('OPcache restart failed: ' . $result['output']);
        }

        \Core\DB::log('cache:opcache-restart', $php !== '' ? "PHP {$php}" : 'all versions');

        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $back    = $referer !== '' ? $referer : '/';
        $this->success('OPcache restarted.', $back);
    }

    private function getCacheStats(): array
    {
        $cacheDir = '/var/cache/nginx/fastcgi';
        $size     = 0;
        $files    = 0;

        if (is_dir($cacheDir)) {
            $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cacheDir,
                \FilesystemIterator::SKIP_DOTS));
            foreach ($iter as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                    $files++;
                }
            }
        }

        // Redis stats
        $redisStats = [];
        $redisInfo  = @shell_exec('redis-cli info stats 2>/dev/null');
        if ($redisInfo) {
            preg_match('/keyspace_hits:(\d+)/', $redisInfo, $h);
            preg_match('/keyspace_misses:(\d+)/', $redisInfo, $m);
            $hits   = (int) ($h[1] ?? 0);
            $misses = (int) ($m[1] ?? 0);
            $total  = $hits + $misses;
            $redisStats = [
                'hits'     => $hits,
                'misses'   => $misses,
                'hit_rate' => $total > 0 ? round(($hits / $total) * 100, 1) : 0,
            ];
        }

        return [
            'fcgi_size'  => format_bytes($size),
            'fcgi_files' => $files,
            'redis'      => $redisStats,
        ];
    }
}
