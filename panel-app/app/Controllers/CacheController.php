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
        $this->success('Cache purged successfully.', '/cache');
    }

    public function toggle(array $params = []): void
    {
        $domain = (string) $this->request->post('domain', '');
        $action = (string) $this->request->post('action', 'enable'); // enable|disable
        $installNginxHelper = $this->request->post('install_nginx_helper', '') === '1';
        $installRedisPlugin = $this->request->post('install_redis_plugin', '') === '1';

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
            $installRedisPlugin = false;
        }

        if (($installNginxHelper || $installRedisPlugin) && ($site['type'] ?? '') !== 'wordpress') {
            $this->error('WordPress helper plugins can only be installed for WordPress sites.');
        }

        $args = ['--domain', $domain];
        if ($action === 'enable') {
            if ($installNginxHelper) {
                $args[] = '--install-nginx-helper';
            }
            if ($installRedisPlugin) {
                $args[] = '--install-redis-plugin';
            }
        }

        $result = run_cli("cache:{$action}", $args);
        if (!$result['success']) {
            $this->error("Cache {$action} failed: " . $result['output']);
        }

        $enabled = $action === 'enable' ? 1 : 0;
        $this->db->run('UPDATE sites SET cache_enabled = ? WHERE domain = ?', [$enabled, $domain]);
        \Core\DB::log("cache:{$action}", "Cache {$action}d for: {$domain}");
        $this->success("FastCGI cache {$action}d for {$domain}.", '/cache');
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
