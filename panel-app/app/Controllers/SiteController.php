<?php
declare(strict_types=1);
namespace Controllers;

class SiteController extends BaseController
{
    public function index(array $params = []): void
    {
        // Sync: tambah ke DB jika ada di Nginx tapi belum di DB
        $this->syncSitesFromFilesystem();

        $sites = $this->db->rows('SELECT * FROM sites ORDER BY created_at DESC');
        $this->view('sites/index', compact('sites'));
    }

    public function showAdd(array $params = []): void
    {
        $this->view('sites/add', [
            'phpVersions' => ['8.1', '8.2', '8.3'],
            'siteTypes'   => [
                'wordpress' => 'WordPress',
                'php'       => 'PHP / Generic',
                'laravel'   => 'Laravel',
                'static'    => 'Static HTML',
                'proxy'     => 'Reverse Proxy',
            ],
        ]);
    }

    public function add(array $params = []): void
    {
        $domain    = strtolower(trim((string) $this->request->post('domain', '')));
        $type      = (string) $this->request->post('type', 'php');
        $phpVer    = (string) $this->request->post('php_version', '8.3');
        $proxyPass = (string) $this->request->post('proxy_pass', 'http://127.0.0.1:3000');

        if (!is_valid_domain($domain)) {
            $this->error('Invalid domain name.');
        }
        if (!in_array($type, ['wordpress', 'php', 'laravel', 'static', 'proxy'], true)) {
            $this->error('Invalid site type.');
        }
        if (!in_array($phpVer, ['8.1', '8.2', '8.3'], true)) {
            $this->error('Invalid PHP version.');
        }
        if ($type === 'proxy' && !is_valid_proxy_url($proxyPass)) {
            $this->error('Invalid reverse proxy URL.');
        }

        // Cek di DB DAN filesystem — keduanya harus tidak ada
        $inDb   = $this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain]);
        $inFs   = file_exists("/etc/nginx/sites-available/{$domain}.conf");

        if ($inDb && $inFs) {
            $this->error("Site already exists: {$domain}");
        }

        // Jika ada di filesystem tapi tidak di DB — sync dulu, anggap sudah ada
        if ($inFs && !$inDb) {
            $this->syncOneSite($domain, $type, $phpVer);
            $this->error("Site {$domain} sudah ada di server tapi belum terdaftar di panel. Sudah disinkronkan — silakan refresh halaman Sites.");
        }

        // Build CLI args
        $args = ['--domain', $domain, '--type', $type, '--php', $phpVer];
        if ($type === 'proxy') {
            $args[] = '--proxy-pass';
            $args[] = $proxyPass;
        }

        $result = run_cli('site:add', $args);

        if (!$result['success']) {
            $this->error('Failed to create site: ' . $result['output']);
        }

        // Persist to panel DB
        $this->syncOneSite($domain, $type, $phpVer);

        \Core\DB::log('site:add', "Added site: {$domain} ({$type}, PHP {$phpVer})");
        $this->success("Site {$domain} created successfully.", "/sites/{$domain}");
    }

    public function detail(array $params = []): void
    {
        $domain = $params['domain'] ?? '';
        $site   = $this->db->row('SELECT * FROM sites WHERE domain = ?', [$domain]);
        if (!$site) {
            abort(404, "Site not found: {$domain}");
        }

        $nginxConf = '';
        $confFile  = "/etc/nginx/sites-available/{$domain}.conf";
        if (file_exists($confFile) && is_readable($confFile)) {
            $nginxConf = file_get_contents($confFile);
        }

        $sslExpiry = null;
        $lePath    = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
        if (file_exists($lePath)) {
            $certInfo  = openssl_x509_parse((string) file_get_contents($lePath));
            $sslExpiry = $certInfo ? date('Y-m-d', $certInfo['validTo_time_t']) : null;
            $site['ssl_type'] = "Let's Encrypt";
        }

        $logs = $this->db->rows(
            'SELECT * FROM activity_log WHERE detail LIKE ? ORDER BY created_at DESC LIMIT 20',
            ["%{$domain}%"]
        );

        $this->view('sites/detail', compact('site', 'nginxConf', 'sslExpiry', 'logs'));
    }

    public function delete(array $params = []): void
    {
        $domain = $params['domain'] ?? '';
        $site   = $this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain]);
        if (!$site) {
            // Coba hapus dari filesystem juga walau tidak di DB
            if (!file_exists("/etc/nginx/sites-available/{$domain}.conf")) {
                $this->error("Site not found: {$domain}");
            }
        }

        if (!is_valid_domain($domain)) {
            $this->error("Invalid domain name: {$domain}");
        }

        $result = run_cli('site:delete', ['--domain', $domain, '--force']);
        if (!$result['success']) {
            $this->error('Failed to delete site: ' . $result['output']);
        }

        $this->db->run('DELETE FROM sites WHERE domain = ?', [$domain]);
        \Core\DB::log('site:delete', "Deleted site: {$domain}");
        $this->success("Site {$domain} deleted.", '/sites');
    }

    public function changePhp(array $params = []): void
    {
        $domain = $params['domain'] ?? '';
        $phpVer = (string) $this->request->post('php_version', '8.3');

        if (!in_array($phpVer, ['8.1', '8.2', '8.3'], true)) {
            $this->error('Invalid PHP version.');
        }

        $result = run_cli('php:version', ['--domain', $domain, '--set', $phpVer]);
        if (!$result['success']) {
            $this->error('Failed to change PHP version: ' . $result['output']);
        }

        $this->db->run('UPDATE sites SET php_version = ? WHERE domain = ?', [$phpVer, $domain]);
        \Core\DB::log('php:version', "Changed {$domain} to PHP {$phpVer}");
        $this->success("PHP version changed to {$phpVer} for {$domain}.", "/sites/{$domain}");
    }

    public function nginxEditor(array $params = []): void
    {
        $domain   = $params['domain'] ?? '';
        $site     = $this->db->row('SELECT * FROM sites WHERE domain = ?', [$domain]);
        if (!$site) abort(404);

        $confFile  = "/etc/nginx/sites-available/{$domain}.conf";
        $nginxConf = file_exists($confFile) ? (string) file_get_contents($confFile) : '';

        $this->view('sites/nginx-editor', compact('site', 'nginxConf'));
    }

    public function saveNginx(array $params = []): void
    {
        $domain  = $params['domain'] ?? '';
        $content = (string) $this->request->post('nginx_conf', '');
        $site    = $this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain]);

        if (!$site || !is_valid_domain($domain)) {
            $this->error("Site not found: {$domain}");
        }

        if (empty($content)) {
            $this->error('Nginx config cannot be empty.');
        }

        if (str_contains($content, "\0")) {
            $this->error('Nginx config contains invalid bytes.');
        }

        $confFile = "/etc/nginx/sites-available/{$domain}.conf";
        if (!file_exists($confFile)) {
            $this->error("Nginx config not found for: {$domain}");
        }

        $tmpDir = STORAGE_ROOT . '/tmp/vhost';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0770, true);
        }

        $tmpFile = tempnam($tmpDir, $domain . '.');
        if ($tmpFile === false || file_put_contents($tmpFile, $content) === false) {
            $this->error('Could not write temporary Nginx config.');
        }
        @chmod($tmpFile, 0640);

        $result = run_cli('vhost:save', ['--domain', $domain, '--file', $tmpFile]);
        @unlink($tmpFile);

        if (!$result['success']) {
            $this->error('Nginx config test failed: ' . $result['output']);
        }

        \Core\DB::log('nginx:save', "Saved Nginx config for {$domain}");
        $this->success('Nginx configuration saved and reloaded.', "/sites/{$domain}");
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Sync semua site dari Nginx filesystem ke SQLite panel DB
     * Dipanggil saat halaman Sites dibuka
     */
    private function syncSitesFromFilesystem(): void
    {
        $confDir = '/etc/nginx/sites-available';
        if (!is_dir($confDir)) return;

        $enabledDir = '/etc/nginx/sites-enabled';
        foreach (glob("{$confDir}/*.conf") as $confFile) {
            $basename = basename($confFile, '.conf');

            // Skip panel vhost
            if ($basename === 'aidipanel-ui') continue;

            $domain = $basename;
            if (!is_valid_domain($domain)) continue;

            // Hanya sync yang benar-benar aktif (ada di sites-enabled)
            if (!file_exists("{$enabledDir}/{$domain}.conf")) continue;

            // Sudah ada di DB, skip
            if ($this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain])) continue;

            // Deteksi type dari isi config
            $content  = (string) file_get_contents($confFile);
            $type     = 'php';
            if (preg_match('/^\s*#\s*Type:\s*(wordpress|laravel|php|static|proxy)\b/m', $content, $typeMatch)) {
                $type = $typeMatch[1];
            } elseif (str_contains($content, 'wp-admin') || str_contains($content, 'wp-login')) {
                $type = 'wordpress';
            } elseif (str_contains($content, '/public;') || str_contains($content, 'laravel')) {
                $type = 'laravel';
            } elseif (!str_contains($content, 'fastcgi_pass') && !str_contains($content, 'proxy_pass')) {
                $type = 'static';
            } elseif (str_contains($content, 'proxy_pass')) {
                $type = 'proxy';
            }

            // Deteksi PHP version
            preg_match('/php(\d+\.\d+)-fpm\.sock/', $content, $m);
            $phpVer = $m[1] ?? '8.3';

            $this->syncOneSite($domain, $type, $phpVer);
        }
    }

    /**
     * Insert satu site ke DB (INSERT OR IGNORE)
     */
    private function syncOneSite(string $domain, string $type, string $phpVer): void
    {
        $webroot = "/var/www/{$domain}/htdocs";
        if ($type === 'laravel') {
            $webroot .= '/public';
        }

        $hasLe  = file_exists("/etc/letsencrypt/live/{$domain}/fullchain.pem");
        $ssl    = $hasLe ? 'letsencrypt' : 'self-signed';
        $confFile = "/etc/nginx/sites-available/{$domain}.conf";
        $conf     = is_file($confFile) ? (string) file_get_contents($confFile) : '';
        $cache    = preg_match('/^\s*fastcgi_cache\s+aidipanel_fcgi\b/m', $conf) ? 1 : 0;

        $this->db->run(
            'INSERT OR IGNORE INTO sites (domain, type, php_version, webroot, ssl_type, cache_enabled) VALUES (?, ?, ?, ?, ?, ?)',
            [$domain, $type, $phpVer, $webroot, $ssl, $cache]
        );
    }
}
