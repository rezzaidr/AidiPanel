<?php
declare(strict_types=1);
namespace Controllers;

class SiteController extends BaseController
{
    public function index(array $params = []): void
    {
        // Sync: add to the DB if present in Nginx but not yet in the DB
        $this->syncSitesFromFilesystem();

        $sites = $this->db->rows('SELECT * FROM sites ORDER BY created_at DESC');
        $this->view('sites/index', compact('sites'));
    }

    public function showAdd(array $params = []): void
    {
        $this->view('sites/add', ['cards' => $this->addTypeCards()]);
    }

    public function showAddForm(array $params = []): void
    {
        $slug = (string) ($params['type'] ?? '');
        $form = $this->addFormConfig($slug);
        if ($form === null) {
            $this->redirect('/sites/add');
        }
        $form['slug'] = $slug;
        $this->view('sites/add-form', ['form' => $form]);
    }

    public function add(array $params = []): void
    {
        // Streaming variant: the wizard posts stream=1 and reads live SSE progress.
        // addStream() sets up the event stream and exits; the CLI is the validator
        // there (its die() messages surface in the error frame).
        if ($this->request->post('stream') === '1') {
            $this->addStream();
            return;
        }

        $domain    = strtolower(trim((string) $this->request->post('domain', '')));
        $type      = (string) $this->request->post('type', 'php');
        $phpVer    = (string) $this->request->post('php_version', php_policy()['default']);
        $proxyPass = (string) $this->request->post('proxy_pass', 'http://127.0.0.1:3000');
        $siteUser  = strtolower(trim((string) $this->request->post('site_user', '')));

        // WordPress provisioning fields (only read/validated for --type wordpress).
        $wpTitle = trim((string) $this->request->post('site_title', ''));
        $wpUser  = trim((string) $this->request->post('admin_user', ''));
        $wpPass  = (string) $this->request->post('admin_pass', '');
        $wpEmail = trim((string) $this->request->post('admin_email', ''));
        $wpMulti = (string) $this->request->post('multisite', 'off');

        if (!is_valid_domain($domain)) {
            $this->error('Invalid domain name.');
        }
        if (!in_array($type, ['wordpress', 'php', 'laravel', 'static', 'proxy'], true)) {
            $this->error('Invalid site type.');
        }
        if (!in_array($phpVer, php_policy()['available'], true)) {
            $this->error('Invalid PHP version.');
        }
        // Patch C: a not-installed version is no longer blocked here — the CLI
        // (site:add) auto-installs it. The wizard confirms with the user first.
        if ($type === 'proxy' && !is_valid_proxy_url($proxyPass)) {
            $this->error('Invalid reverse proxy URL.');
        }
        if ($siteUser !== '' && !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $siteUser)) {
            $this->error('Invalid site user: lowercase, start with a letter, [a-z0-9_-], max 32 chars.');
        }

        // WordPress fields: a thin pre-check for friendly errors; the CLI validates
        // again and is the real gatekeeper before it installs anything.
        if ($type === 'wordpress') {
            if ($wpTitle === '' || mb_strlen($wpTitle) > 200) {
                $this->error('WordPress site title is required (max 200 characters).');
            }
            if ($wpUser === '' || strlen($wpUser) > 60 || preg_match('/[\r\n]/', $wpUser)) {
                $this->error('WordPress admin username is required (max 60 characters, no line breaks).');
            }
            if (strlen($wpPass) < 8 || preg_match('/[\r\n]/', $wpPass)) {
                $this->error('WordPress admin password must be at least 8 characters with no line breaks.');
            }
            if (!filter_var($wpEmail, FILTER_VALIDATE_EMAIL)) {
                $this->error('A valid WordPress admin email is required.');
            }
            if ($wpMulti === 'subdomain') {
                $this->error('Subdomain multisite needs wildcard SSL and is not available yet — choose Disabled or Subdirectory.');
            }
            if (!in_array($wpMulti, ['off', 'subdir'], true)) {
                $wpMulti = 'off';
            }
        }

        // Check the DB AND the filesystem - neither may already exist
        $inDb   = $this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain]);
        $inFs   = file_exists("/etc/nginx/sites-available/{$domain}.conf");

        if ($inDb && $inFs) {
            $this->error("Site already exists: {$domain}");
        }

        // If present on the filesystem but not in the DB - sync first, then treat as existing
        if ($inFs && !$inDb) {
            $this->syncOneSite($domain, $type, $phpVer);
            $this->error("Site {$domain} already exists on the server but was not registered in the panel. It has now been synced - please refresh the Sites page.");
        }

        // Build CLI args
        $args = ['--domain', $domain, '--type', $type, '--php', $phpVer];
        if ($siteUser !== '') {           // blank => CLI derives it from the domain
            $args[] = '--user';
            $args[] = $siteUser;
        }
        if ($type === 'proxy') {
            $args[] = '--proxy-pass';
            $args[] = $proxyPass;
        }
        if ($type === 'wordpress') {
            // The CLI installs real WordPress when --wp-admin-email is present;
            // it re-validates these and feeds the passwords to WP-CLI over stdin.
            array_push(
                $args,
                '--wp-title',       $wpTitle,
                '--wp-admin-user',  $wpUser,
                '--wp-admin-pass',  $wpPass,
                '--wp-admin-email', $wpEmail,
                '--wp-multisite',   $wpMulti
            );
        }

        // A WordPress create downloads core + installs WP (~1 min), on top of any
        // PHP install below. Keep the request alive past a tab close so the
        // DB-write still runs (php_install_begin() does the same when it fires).
        if ($type === 'wordpress') {
            @ignore_user_abort(true);
            @set_time_limit(0);
        }

        // Patch C+: when the chosen version isn't installed, the CLI auto-installs
        // it (~1–2 min). Guard that single long op: keep the request alive past a
        // tab close so the DB-write below still runs, and take a per-version lock
        // so two creates on the same uninstalled version can't race apt.
        $lock = null;
        if (!php_is_installed($phpVer)) {
            $guard = php_install_begin($phpVer);
            if (!$guard['ok']) {
                $this->error("PHP {$phpVer} installation is already running. Please wait.");
            }
            $lock = $guard['handle'];
        }

        $result = run_cli('site:add', $args);
        php_install_end($lock);

        if (!$result['success']) {
            $this->error('Failed to create site: ' . $result['output']);
        }

        // Persist to panel DB
        $this->syncOneSite($domain, $type, $phpVer);
        \Core\DB::log('site:add', "Added site: {$domain} ({$type}, PHP {$phpVer})");

        if ($type === 'wordpress') {
            $kv       = parse_kv_output($result['output']);
            $adminUrl = $kv['admin_url'] ?? "https://{$domain}/wp-admin";
            $this->success("WordPress site {$domain} created. Admin login: {$adminUrl}", "/sites/{$domain}");
        }

        $this->success("Site {$domain} created successfully.", "/sites/{$domain}");
    }

    /**
     * Streaming site create. Mirrors add()'s arg-building (keep in sync) but reports
     * progress over SSE instead of blocking + redirecting. The CLI validates the
     * input and installs; its summary is parsed for the admin URL on success.
     */
    private function addStream(): void
    {
        stream_begin();

        $domain    = strtolower(trim((string) $this->request->post('domain', '')));
        $type      = (string) $this->request->post('type', 'php');
        $phpVer    = (string) $this->request->post('php_version', php_policy()['default']);
        $proxyPass = (string) $this->request->post('proxy_pass', 'http://127.0.0.1:3000');
        $siteUser  = strtolower(trim((string) $this->request->post('site_user', '')));

        $args = ['--domain', $domain, '--type', $type, '--php', $phpVer];
        if ($siteUser !== '') {
            $args[] = '--user';
            $args[] = $siteUser;
        }
        if ($type === 'proxy') {
            $args[] = '--proxy-pass';
            $args[] = $proxyPass;
        }
        if ($type === 'wordpress') {
            array_push(
                $args,
                '--wp-title',       trim((string) $this->request->post('site_title', '')),
                '--wp-admin-user',  trim((string) $this->request->post('admin_user', '')),
                '--wp-admin-pass',  (string) $this->request->post('admin_pass', ''),
                '--wp-admin-email', trim((string) $this->request->post('admin_email', '')),
                '--wp-multisite',   (string) $this->request->post('multisite', 'off')
            );
        }

        $result = run_cli_stream('site:add', $args, function (string $pct, string $key, string $msg): void {
            stream_send(['t' => 'p', 'pct' => (int) $pct, 'key' => $key, 'msg' => $msg]);
        });

        if (!$result['success']) {
            // The CLI's die() message is the last line; drop the [ERROR]/[INFO] tag.
            $lines = array_values(array_filter(array_map('trim', explode("\n", $result['output'])), fn($l) => $l !== ''));
            $tail  = $lines ? (string) end($lines) : '';
            $tail  = preg_replace('/^\[(ERROR|WARN|INFO|OK)\]\s*/', '', $tail);
            stream_send(['t' => 'done', 'ok' => false,
                         'message' => 'Failed to create site: ' . ($tail !== '' ? $tail : 'unknown error')]);
            exit;
        }

        // Persist to the panel DB, exactly like add().
        $this->syncOneSite($domain, $type, $phpVer);
        \Core\DB::log('site:add', "Added site: {$domain} ({$type}, PHP {$phpVer})");

        $kv      = parse_kv_output($result['output']);
        $message = $type === 'wordpress'
            ? "WordPress site {$domain} created. Admin login: " . ($kv['admin_url'] ?? "https://{$domain}/wp-admin")
            : "Site {$domain} created successfully.";

        stream_send(['t' => 'done', 'ok' => true, 'redirect' => "/sites/{$domain}", 'message' => $message]);
        exit;
    }

    public function detail(array $params = []): void
    {
        $domain = $params['domain'] ?? '';
        $site   = $this->db->row('SELECT * FROM sites WHERE domain = ?', [$domain]);
        if (!$site) {
            abort(404, "Site not found: {$domain}");
        }

        $activeTab = $this->sanitizeTab($_GET['tab'] ?? 'overview');

        $nginxConf = '';
        $confFile  = "/etc/nginx/sites-available/{$domain}.conf";
        if (file_exists($confFile) && is_readable($confFile)) {
            $nginxConf = file_get_contents($confFile);
        }

        $ssl         = $this->getSslInfo($domain, $nginxConf);
        $sslExpiry   = $ssl['expiry'];
        $sslDaysLeft = $ssl['daysLeft'];
        if ($ssl['state'] === 'letsencrypt' || $ssl['state'] === 'custom') {
            $site['ssl_type'] = $ssl['state'];
        }

        $logs = $this->db->rows(
            'SELECT * FROM activity_log WHERE detail LIKE ? ORDER BY created_at DESC LIMIT 15',
            ["%{$domain}%"]
        );

        $type          = (string) ($site['type'] ?? 'php');
        $phpVer        = (string) ($site['php_version'] ?? '');
        $diskSize      = $this->getSiteDiskUsage((string) ($site['webroot'] ?? ''));
        $pageCacheInfo = [];
        $objectCacheInfo = [];
        $opcacheInfo   = [];
        $protocolInfo  = [];
        $cacheConfig   = [];
        $lastPurge     = null;
        $cacheZoneSize = null;
        $httpsOptions  = [];
        $certs         = [];

        if ($activeTab === 'performance') {
            $pageCacheInfo   = $this->getPageCacheInfo($domain);
            $objectCacheInfo = $this->getObjectCacheInfo($domain, $type);
            $opcacheInfo     = $this->buildOpcacheInfo($this->getOpcacheStatus(), $phpVer);
            $protocolInfo    = $this->getProtocolInfo($domain);

            $snippetFile = "/etc/nginx/snippets/cache-{$domain}.conf";
            if (is_readable($snippetFile)) {
                $raw = (string) file_get_contents($snippetFile);
                foreach (['TTL', 'EXCLUDE_URLS', 'BYPASS_COOKIES', 'BYPASS_QUERY', 'STALE_REVALIDATE', 'DEBUG_HEADER'] as $key) {
                    if (preg_match('/# AIDIPANEL_CACHE_' . $key . '=(.*)/', $raw, $m)) {
                        $cacheConfig[strtolower(str_replace('_', '-', $key))] = trim($m[1]);
                    }
                }
            }
            $lastPurgeRow = $this->db->row(
                "SELECT created_at FROM activity_log WHERE action = 'cache:purge' AND detail LIKE ? ORDER BY created_at DESC LIMIT 1",
                ["%{$domain}%"]
            );
            $lastPurge = $lastPurgeRow ? $lastPurgeRow['created_at'] : null;

            $duOut = @shell_exec('du -sh /var/cache/nginx/fastcgi 2>/dev/null');
            if ($duOut !== null) {
                $duParts = preg_split('/\s+/', trim($duOut), 2);
                $cacheZoneSize = $duParts[0] ?? null;
            }
        }

        if ($activeTab === 'ssl') {
            $httpsOptions = $this->getHttpsOptions($domain, $nginxConf);
            $certs        = $this->getCertificates($domain, $ssl);
        }

        // Database tab data (scoped to this site by its name prefix).
        $databases = []; $dbUsers = []; $dbPrefix = ''; $pmaUrl = null; $wpDbInfo = null; $pmaInstalled = false;
        $dbHost = '127.0.0.1'; $dbPort = 3306;
        if ($activeTab === 'database') {
            $siteUser  = trim((string) ($site['site_user'] ?? ''));
            $dbPrefix  = $siteUser !== '' ? str_replace('-', '_', $siteUser) . '_' : '';
            $databases = $this->decodeCliJson(run_cli('db:list',  ['--site', $domain, '--json']));
            $dbUsers   = $this->decodeCliJson(run_cli('db:users', ['--site', $domain, '--json']));
            $pmaInstalled = is_file('/etc/aidipanel/phpmyadmin.conf') && is_file('/usr/share/phpmyadmin/index.php');
            $pmaUrl       = $pmaInstalled ? '/phpmyadmin/index.php' : null;
            if ($type === 'wordpress') {
                $wpDbInfo = $this->readWpConfigDb((string) ($site['webroot'] ?? ''));
            }
        }

        // hasCache is still needed for the Overview tab cache card (cheap DB read)
        $hasCache = (bool) ($site['cache_enabled'] ?? false);

        $this->view('sites/detail', compact(
            'site', 'nginxConf', 'ssl', 'sslExpiry', 'sslDaysLeft',
            'logs', 'activeTab', 'diskSize', 'hasCache',
            'pageCacheInfo', 'objectCacheInfo', 'opcacheInfo', 'protocolInfo',
            'cacheConfig', 'lastPurge', 'cacheZoneSize', 'httpsOptions', 'certs',
            'databases', 'dbUsers', 'dbHost', 'dbPort', 'dbPrefix', 'pmaInstalled', 'pmaUrl', 'wpDbInfo'
        ) + ['_full_bleed' => true]);
    }

    public function delete(array $params = []): void
    {
        $domain = $params['domain'] ?? '';
        $site   = $this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain]);
        if (!$site) {
            // Try to remove it from the filesystem too, even if not in the DB
            if (!file_exists("/etc/nginx/sites-available/{$domain}.conf")) {
                $this->error("Site not found: {$domain}");
            }
        }

        if (!is_valid_domain($domain)) {
            $this->error("Invalid domain name: {$domain}");
        }

        // Full purge (CloudPanel-style): the CLI's _site_purge_guard enforces the
        // .aidipanel-managed marker (root:root, domain+user match) before removing
        // the Linux user + /home, so a wrong/blank --user can only make it stricter.
        $row      = $this->db->row('SELECT site_user FROM sites WHERE domain = ?', [$domain]);
        $siteUser = is_array($row) ? trim((string)($row['site_user'] ?? '')) : '';

        $args = ['--domain', $domain, '--purge', '--yes'];
        if ($siteUser !== '') {
            $args[] = '--user';
            $args[] = $siteUser;
        }
        $result = run_cli('site:delete', $args);
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
        $phpVer = (string) $this->request->post('php_version', php_policy()['default']);

        if (!in_array($phpVer, php_policy()['available'], true)) {
            $this->error('Invalid PHP version.');
        }

        if ($this->request->post('stream') === '1') {
            $this->streamCli('php:version', ['--domain', $domain, '--set', $phpVer], function (array $r) use ($domain, $phpVer): array {
                $this->db->run('UPDATE sites SET php_version = ? WHERE domain = ?', [$phpVer, $domain]);
                \Core\DB::log('php:version', "Changed {$domain} to PHP {$phpVer}");
                return ['redirect' => "/sites/{$domain}", 'message' => "PHP version changed to {$phpVer} for {$domain}."];
            });
        }
        // Patch C: the CLI (php:version) auto-installs a missing version; the
        // per-site switcher confirms with the user before submitting.

        // Patch C+: same guard as add() — keep the request alive through the
        // DB-write past a tab close, and lock so two switches to the same
        // uninstalled version don't race apt. Only when an install is needed.
        $lock = null;
        if (!php_is_installed($phpVer)) {
            $guard = php_install_begin($phpVer);
            if (!$guard['ok']) {
                $this->error("PHP {$phpVer} installation is already running. Please wait.");
            }
            $lock = $guard['handle'];
        }

        $result = run_cli('php:version', ['--domain', $domain, '--set', $phpVer]);
        php_install_end($lock);

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

    /** Decode a --json CLI result into an array, isolating the JSON in case of stray output. */
    private function decodeCliJson(array $result): array
    {
        if (empty($result['success'])) { return []; }
        $out = trim((string) ($result['output'] ?? ''));
        if (preg_match('/\[.*\]/s', $out, $m)) { $out = $m[0]; }
        $data = json_decode($out, true);
        return is_array($data) ? $data : [];
    }

    /** Best-effort read of DB_NAME/DB_USER/$table_prefix from a WordPress wp-config.php. */
    private function readWpConfigDb(string $webroot): ?array
    {
        $webroot = rtrim($webroot, '/');
        if ($webroot === '') { return null; }
        $file = "{$webroot}/wp-config.php";
        if (!is_readable($file)) { return null; }
        $src = (string) @file_get_contents($file);
        if ($src === '') { return null; }
        $grab = static function (string $const) use ($src): ?string {
            if (preg_match('/define\(\s*[\'"]' . $const . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/', $src, $m)) {
                return $m[1];
            }
            return null;
        };
        $db   = $grab('DB_NAME');
        $user = $grab('DB_USER');
        if ($db === null && $user === null) { return null; }
        $prefix = 'wp_';
        if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $src, $m)) {
            $prefix = $m[1];
        }
        return ['db' => $db ?? '', 'user' => $user ?? '', 'prefix' => $prefix];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function sanitizeTab(string $raw): string
    {
        $valid = ['overview', 'performance', 'ssl', 'database', 'security', 'cron', 'files', 'settings'];
        return in_array($raw, $valid, true) ? $raw : 'overview';
    }

    /** Step-1 picker cards. `soon` = backend not wired (still navigable to a preview form). */
    private function addTypeCards(): array
    {
        return [
            ['slug' => 'wordpress',     'icon' => 'ti-brand-wordpress', 'title' => 'site.add.card.wp.title',     'desc' => 'site.add.card.wp.desc',     'soon' => false],
            ['slug' => 'php',           'icon' => 'ti-brand-php',       'title' => 'site.add.card.php.title',    'desc' => 'site.add.card.php.desc',    'soon' => false],
            ['slug' => 'nodejs',        'icon' => 'ti-brand-nodejs',    'title' => 'site.add.card.node.title',   'desc' => 'site.add.card.node.desc',   'soon' => true],
            ['slug' => 'static',        'icon' => 'ti-file-text',       'title' => 'site.add.card.static.title', 'desc' => 'site.add.card.static.desc', 'soon' => false],
            ['slug' => 'python',        'icon' => 'ti-brand-python',    'title' => 'site.add.card.python.title', 'desc' => 'site.add.card.python.desc', 'soon' => true],
            ['slug' => 'reverse-proxy', 'icon' => 'ti-arrow-guide',     'title' => 'site.add.card.proxy.title',  'desc' => 'site.add.card.proxy.desc',  'soon' => false],
        ];
    }

    /**
     * Per-type Add Site form definition ("honest version").
     * Each field carries `enabled`: true = backend ready, false = render disabled + "Soon".
     * `creatable` = whether this type can be provisioned today (node/python = false → preview).
     * `type` = the CLI --type submitted (null for non-creatable; for the PHP form the
     * Application <select name="type"> carries the type itself, so `type` here is unused).
     */
    private function addFormConfig(string $slug): ?array
    {
        // Site User: active, optional. Blank => the CLI derives it from the domain.
        $userField = ['key' => 'site_user', 'label' => 'site.add.f.site_user', 'input' => 'text',
                      'required' => false, 'enabled' => true, 'placeholder' => 'auto from domain',
                      'note' => 'site.add.f.site_user_note'];
        // Pre-fill the WordPress admin password with a fresh random value on every
        // render — never a hardcoded literal. The Generate button lets the user reroll.
        $adminPass = bin2hex(random_bytes(12));
        $phpStatus = php_versions_status();
        // Default first, then the rest descending, so the dropdown prefills the recommended version.
        $phpOpts = [];
        foreach ($phpStatus as $ver => $s) {
            $phpOpts[] = ['value' => $ver, 'disabled' => !$s['installed'], 'default' => $s['default']];
        }
        usort($phpOpts, function ($a, $b) {
            if ($a['default'] !== $b['default']) return $a['default'] ? -1 : 1;
            return version_compare($b['value'], $a['value']);
        });
        $phpField = ['key' => 'php_version', 'label' => 'site.add.f.php', 'input' => 'phpselect',
                     'required' => false, 'enabled' => true, 'options' => $phpOpts];

        $forms = [
            'wordpress' => [
                'type' => 'wordpress', 'icon' => 'ti-brand-wordpress',
                'title' => 'site.add.wp.title', 'desc' => 'site.add.wp.desc', 'creatable' => true,
                'fields' => [
                    ['key' => 'domain',      'label' => 'site.add.f.domain',      'input' => 'text',     'required' => true,  'enabled' => true, 'placeholder' => 'example.com'],
                    ['key' => 'site_title',  'label' => 'site.add.f.site_title',  'input' => 'text',     'required' => true,  'enabled' => true, 'placeholder' => 'My WordPress Site'],
                    $userField,
                    ['key' => 'admin_user',  'label' => 'site.add.f.admin_user',  'input' => 'text',     'required' => true,  'enabled' => true, 'placeholder' => 'admin'],
                    ['key' => 'admin_pass',  'label' => 'site.add.f.admin_pass',  'input' => 'password', 'required' => true,  'enabled' => true, 'value' => $adminPass, 'generate' => true],
                    ['key' => 'admin_email', 'label' => 'site.add.f.admin_email', 'input' => 'text',     'required' => true,  'enabled' => true, 'placeholder' => 'you@example.com'],
                    // CLI --wp-multisite takes off|subdir; subdomain needs wildcard SSL (DNS-01) → shown disabled.
                    ['key' => 'multisite',   'label' => 'site.add.f.multisite',   'input' => 'select',   'required' => false, 'enabled' => true,
                     'options' => [
                         ['value' => 'off',       'label' => 'Disabled'],
                         ['value' => 'subdir',    'label' => 'Subdirectory'],
                         ['value' => 'subdomain', 'label' => 'Subdomain (needs wildcard SSL)', 'disabled' => true],
                     ],
                     'note' => 'site.add.f.multisite_note'],
                    $phpField,
                ],
            ],
            'php' => [
                'type' => 'php', 'icon' => 'ti-brand-php',
                'title' => 'site.add.php.title', 'desc' => 'site.add.php.desc', 'creatable' => true,
                'fields' => [
                    ['key' => 'type', 'label' => 'site.add.f.application', 'input' => 'application', 'required' => true, 'enabled' => true,
                     'active' => [['php', 'Generic'], ['laravel', 'Laravel 12'], ['laravel', 'Laravel 11']],
                     'soon'   => ['WordPress', 'WooCommerce', 'Symfony 8', 'Drupal 11', 'Joomla 6', 'Magento 2', 'CakePHP 5', 'CodeIgniter 4', 'Moodle 5', 'Nextcloud 32', 'PrestaShop 1.7', 'Yii 2', '…and 13 more'],
                     'note'   => 'site.add.php.appnote'],
                    ['key' => 'domain', 'label' => 'site.add.f.domain', 'input' => 'text', 'required' => true, 'enabled' => true, 'placeholder' => 'example.com'],
                    $phpField,
                    $userField,
                ],
            ],
            'static' => [
                'type' => 'static', 'icon' => 'ti-file-text',
                'title' => 'site.add.static.title', 'desc' => 'site.add.static.desc', 'creatable' => true,
                'fields' => [
                    ['key' => 'domain', 'label' => 'site.add.f.domain', 'input' => 'text', 'required' => true, 'enabled' => true, 'placeholder' => 'example.com'],
                    $userField,
                ],
            ],
            'reverse-proxy' => [
                'type' => 'proxy', 'icon' => 'ti-arrow-guide',
                'title' => 'site.add.proxy.title', 'desc' => 'site.add.proxy.desc', 'creatable' => true,
                'fields' => [
                    ['key' => 'domain',     'label' => 'site.add.f.domain',    'input' => 'text', 'required' => true, 'enabled' => true, 'placeholder' => 'example.com'],
                    ['key' => 'proxy_pass', 'label' => 'site.add.f.proxy_url', 'input' => 'text', 'required' => true, 'enabled' => true, 'mono' => true, 'placeholder' => 'http://127.0.0.1:3000'],
                    $userField,
                ],
            ],
            'nodejs' => [
                'type' => null, 'icon' => 'ti-brand-nodejs',
                'title' => 'site.add.node.title', 'desc' => 'site.add.node.desc', 'creatable' => false,
                'banner' => 'site.add.node.banner',
                'fields' => [
                    ['key' => 'domain',       'label' => 'site.add.f.domain',       'input' => 'text',   'required' => true,  'enabled' => false, 'placeholder' => 'example.com'],
                    ['key' => 'node_version', 'label' => 'site.add.f.node_version', 'input' => 'select', 'required' => false, 'enabled' => false, 'options' => ['Node 22 LTS', 'Node 20 LTS', 'Node 18 LTS', 'Node 16 LTS']],
                    ['key' => 'app_port',     'label' => 'site.add.f.app_port',     'input' => 'text',   'required' => true,  'enabled' => false, 'placeholder' => '3000'],
                    $userField,
                ],
            ],
            'python' => [
                'type' => null, 'icon' => 'ti-brand-python',
                'title' => 'site.add.python.title', 'desc' => 'site.add.python.desc', 'creatable' => false,
                'banner' => 'site.add.python.banner',
                'fields' => [
                    ['key' => 'domain',         'label' => 'site.add.f.domain',         'input' => 'text',   'required' => true,  'enabled' => false, 'placeholder' => 'example.com'],
                    ['key' => 'python_version', 'label' => 'site.add.f.python_version', 'input' => 'select', 'required' => false, 'enabled' => false, 'options' => ['Python 3.12'], 'note' => 'site.add.python.docs'],
                    ['key' => 'app_port',       'label' => 'site.add.f.app_port',       'input' => 'text',   'required' => true,  'enabled' => false, 'value' => '8090'],
                    $userField,
                ],
            ],
        ];

        return $forms[$slug] ?? null;
    }

    /** Detect the active certificate for a site (files are the source of truth). */
    private function getSslInfo(string $domain, string $nginxConf = ''): array
    {
        $candidates = [
            'letsencrypt' => "/etc/letsencrypt/live/{$domain}/fullchain.pem",
            'custom'      => "/etc/ssl/aidipanel-custom/{$domain}/fullchain.pem",
            'self-signed' => "/etc/ssl/aidipanel-self/{$domain}.crt",
        ];
        $info = [
            'state' => 'none', 'expiry' => null, 'daysLeft' => null,
            'issuer' => null, 'selfSigned' => false, 'covers' => false,
            'domains' => [], 'trusted' => false,
        ];

        // The active cert is whichever one the vhost actually points at (its
        // ssl_certificate directive), so the panel follows "Use this cert"
        // switches instead of always preferring Let's Encrypt by file priority.
        $active = null;
        if ($nginxConf !== '' && preg_match('/^\s*ssl_certificate\s+([^;]+);/mi', $nginxConf, $m)) {
            $wired = trim($m[1]);
            foreach ($candidates as $state => $p) {
                if ($p === $wired) { $active = $state; break; }
            }
        }

        // is_readable (not file_exists): the panel runs as www-data, so a cert it
        // cannot read is a cert it cannot vouch for. The CLI now makes the public
        // cert + its directory readable, keeping only the private key root-only.
        $path = null;
        if ($active !== null) {
            $info['state'] = $active;                        // trust the vhost pointer
            if (is_readable($candidates[$active])) { $path = $candidates[$active]; }
        } else {
            foreach ($candidates as $state => $p) {
                if (is_readable($p)) { $info['state'] = $state; $path = $p; break; }
            }
        }
        if ($path === null) {
            return $info;
        }

        $cert = openssl_x509_parse((string) file_get_contents($path));
        if (!$cert) {
            return $info;
        }

        if (isset($cert['validTo_time_t'])) {
            $info['expiry']   = date('Y-m-d', $cert['validTo_time_t']);
            $info['daysLeft'] = (int) ceil(($cert['validTo_time_t'] - time()) / 86400);
        }
        $info['issuer']     = $cert['issuer']['CN'] ?? ($cert['issuer']['O'] ?? null);
        $info['selfSigned'] = !empty($cert['issuer']) && ($cert['issuer'] === $cert['subject']);

        // Names the cert is valid for (Subject CN + SAN DNS entries).
        $names = [];
        if (!empty($cert['subject']['CN'])) {
            $names[] = strtolower((string) $cert['subject']['CN']);
        }
        foreach (explode(',', (string) ($cert['extensions']['subjectAltName'] ?? '')) as $san) {
            $san = trim($san);
            if (stripos($san, 'DNS:') === 0) {
                $names[] = strtolower(substr($san, 4));
            }
        }
        $info['domains'] = array_values(array_unique($names));
        $info['covers']  = $this->certCoversDomain($domain, $info['domains']);

        $notExpired = $info['daysLeft'] === null || $info['daysLeft'] > 0;
        // Browser-trusted only when CA-signed, valid for this exact domain, and current.
        $info['trusted'] = $info['state'] !== 'none'
            && !$info['selfSigned'] && $info['covers'] && $notExpired;

        return $info;
    }

    /** Exact or wildcard (*.example.com) match of $domain against cert names. */
    private function certCoversDomain(string $domain, array $names): bool
    {
        $domain = strtolower($domain);
        foreach ($names as $name) {
            if ($name === $domain) {
                return true;
            }
            if (str_starts_with($name, '*.')) {
                $base = substr($name, 2);
                if (str_ends_with($domain, '.' . $base)) {
                    $sub = substr($domain, 0, -strlen('.' . $base));
                    if ($sub !== '' && !str_contains($sub, '.')) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Per-site HTTPS options for the SSL tab. force_https + hsts are detected
     * straight from the vhost; auto_renew comes from the CLI-managed marker
     * (the renewal dir is root-only). auto_renew is null when not applicable
     * (no Let's Encrypt cert to renew).
     */
    private function getHttpsOptions(string $domain, string $nginxConf): array
    {
        $forceHttps = (bool) preg_match('/return\s+301\s+https/i', $nginxConf);
        $hsts       = (bool) preg_match('/^\s*add_header\s+Strict-Transport-Security/mi', $nginxConf);

        $autoRenew = null;
        if (is_readable("/etc/letsencrypt/live/{$domain}/fullchain.pem")) {
            $autoRenew = preg_match('/^#\s*AIDIPANEL_AUTORENEW=(\w+)/mi', $nginxConf, $m)
                ? (strtolower($m[1]) === 'on')
                : true; // the global cron renews unless a marker says otherwise
        }
        return ['force_https' => $forceHttps, 'hsts' => $hsts, 'auto_renew' => $autoRenew];
    }

    /**
     * Rows for the certificates table: the active managed cert (if any) plus the
     * always-present self-signed fallback shown as a "Backup" row. `active` marks
     * the cert currently wired into the vhost (its row hides the ⋮ menu).
     */
    private function getCertificates(string $domain, array $ssl): array
    {
        $parse = function (string $path, string $type, string $state) use ($domain): ?array {
            if (!is_readable($path)) {
                return null;
            }
            $cert = openssl_x509_parse((string) file_get_contents($path));
            if (!$cert) {
                return null;
            }
            $names = [];
            if (!empty($cert['subject']['CN'])) {
                $names[] = strtolower((string) $cert['subject']['CN']);
            }
            foreach (explode(',', (string) ($cert['extensions']['subjectAltName'] ?? '')) as $san) {
                $san = trim($san);
                if (stripos($san, 'DNS:') === 0) {
                    $names[] = strtolower(substr($san, 4));
                }
            }
            $ts = $cert['validTo_time_t'] ?? null;
            return [
                'type'     => $type,
                'state'    => $state,
                'domains'  => $names ? array_values(array_unique($names)) : [$domain],
                'expiry'   => $ts ? date('Y-m-d', $ts) : null,
                'daysLeft' => $ts ? (int) ceil(($ts - time()) / 86400) : null,
            ];
        };

        $active = $ssl['state'] ?? 'none';
        $rows   = [];

        // List every certificate present on disk so the user can switch between
        // them; the one wired into the vhost is `active` (its row hides the ⋮),
        // every other present cert offers "Use this cert".
        $defs = [
            ["/etc/letsencrypt/live/{$domain}/fullchain.pem",     "Let's Encrypt", 'letsencrypt'],
            ["/etc/ssl/aidipanel-custom/{$domain}/fullchain.pem", 'Imported',      'custom'],
            ["/etc/ssl/aidipanel-self/{$domain}.crt",             'Self-signed',   'self-signed'],
        ];
        foreach ($defs as [$path, $label, $state]) {
            $row = $parse($path, $label, $state);
            if (!$row) { continue; }
            $row['active']    = ($state === $active);
            $row['installed'] = ($state === $active);
            $rows[] = $row;
        }

        return $rows;
    }

    private function getSiteDiskUsage(string $path): string
    {
        if ($path === '' || !is_dir($path)) return '—';
        $raw = trim((string) @shell_exec('du -sh ' . escapeshellarg($path) . ' 2>/dev/null'));
        return $raw !== '' ? (explode("\t", $raw)[0] ?? '—') : '—';
    }

    private function getOpcacheStatus(): array
    {
        if (!function_exists('opcache_get_status')) return [];
        $s = @opcache_get_status(false);
        return is_array($s) ? $s : [];
    }

    private function getPageCacheInfo(string $domain): array
    {
        // An empty array signals "CLI unavailable" to the view, which then falls
        // back to the DB cache flag and suppresses engine-level warnings/actions.
        $result = run_cli('cache:page', ['--action', 'status', '--domain', $domain]);
        if (!$result['success'] || trim($result['output']) === '') {
            return [];
        }
        $kv = parse_kv_output($result['output']);
        return [
            'engine'           => $kv['engine'] ?? 'fastcgi',
            'engine_ok'        => ($kv['engine_ok'] ?? '0') === '1',
            'site_cache_status'=> $kv['site_cache_status'] ?? 'unknown',
            'wp_helper_status' => $kv['wp_helper_status'] ?? 'unknown',
            'cache_header'     => $kv['cache_header'] ?? 'unknown',
            'hit_rate'         => $kv['hit_rate'] ?? 'unknown',
            'cache_path'       => $kv['cache_path'] ?? '/var/cache/nginx/fastcgi',
            'can_enable'       => ($kv['can_enable'] ?? '0') === '1',
            'can_disable'      => ($kv['can_disable'] ?? '0') === '1',
            'can_purge'        => ($kv['can_purge'] ?? '0') === '1',
            'can_check'        => ($kv['can_check'] ?? '0') === '1',
            'error'            => $kv['error'] ?? '',
        ];
    }

    private function getObjectCacheInfo(string $domain, string $type): array
    {
        // Skip CLI call for non-WordPress sites — report unsupported immediately
        if ($type !== 'wordpress') {
            return [
                'engine' => 'redis', 'service_ok' => false,
                'redis_host' => '127.0.0.1', 'redis_port' => '6379',
                'site_cache_status' => 'unsupported', 'plugin_status' => 'not_wordpress',
                'dropin_status' => 'unknown', 'prefix' => '', 'prefix_managed' => false,
                'site_keys' => 'unknown', 'site_memory' => 'unknown',
                'wp_cli_missing' => false,
                'can_enable' => false, 'can_disable' => false, 'can_flush' => false,
                'error' => '',
            ];
        }
        $result = run_cli('cache:redis', ['--action', 'status', '--domain', $domain]);
        if (!$result['success'] || trim($result['output']) === '') {
            return [];   // CLI unavailable — the view shows "status unavailable"
        }
        $kv = parse_kv_output($result['output']);
        return [
            'engine'           => 'redis',
            'service_ok'       => ($kv['service_ok'] ?? '0') === '1',
            'redis_host'       => $kv['redis_host'] ?? '127.0.0.1',
            'redis_port'       => $kv['redis_port'] ?? '6379',
            'site_cache_status'=> $kv['site_cache_status'] ?? 'unknown',
            'plugin_status'    => $kv['plugin_status'] ?? 'unknown',
            'dropin_status'    => $kv['dropin_status'] ?? 'unknown',
            'prefix'           => $kv['prefix'] ?? '',
            'prefix_managed'   => ($kv['prefix_managed'] ?? '0') === '1',
            'site_keys'        => $kv['site_keys'] ?? 'unknown',
            'site_memory'      => $kv['site_memory'] ?? 'unknown',
            'wp_cli_missing'   => ($kv['wp_cli_missing'] ?? '0') === '1',
            'can_enable'       => ($kv['can_enable'] ?? '0') === '1',
            'can_disable'      => ($kv['can_disable'] ?? '0') === '1',
            'can_flush'        => ($kv['can_flush'] ?? '0') === '1',
            'error'            => $kv['error'] ?? '',
        ];
    }

    private function buildOpcacheInfo(array $raw, string $phpVer): array
    {
        $enabled = !empty($raw['opcache_enabled']);
        $st = $raw['opcache_statistics'] ?? [];
        $mu = $raw['memory_usage'] ?? [];
        return [
            'enabled'        => $enabled,
            'scope'          => 'php_runtime',
            'php_version'    => $phpVer ?: '—',
            'hit_rate'       => $enabled ? round((float) ($st['opcache_hit_rate'] ?? 0), 1) . '%' : '—',
            'hits'           => $enabled ? number_format((int) ($st['hits'] ?? 0)) : '—',
            'memory_used'    => $enabled ? format_bytes((int) ($mu['used_memory'] ?? 0)) : '—',
            'memory_limit'   => $enabled ? format_bytes((int) (($mu['used_memory'] ?? 0) + ($mu['free_memory'] ?? 0))) : '—',
            'scripts_cached' => $enabled ? number_format((int) ($st['num_cached_scripts'] ?? 0)) : '—',
            'managed_at'     => 'Admin > PHP',
        ];
    }

    private function getProtocolInfo(string $domain): array
    {
        // 5-minute file cache to avoid repeated subprocess calls
        $cacheFile = '/opt/aidipanel/storage/cache/protocol_info.json';
        if (is_readable($cacheFile) && (time() - (int) @filemtime($cacheFile)) < 300) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $vhostConf  = "/etc/nginx/sites-available/{$domain}.conf";
        $vhost      = is_readable($vhostConf) ? (string) @file_get_contents($vhostConf) : '';
        $globalConf = (string) @file_get_contents('/etc/nginx/nginx.conf');
        $nginxV     = (string) @shell_exec('nginx -V 2>&1');

        $http2  = (bool) preg_match('/http2\s+on|listen\s[^;]+\s+http2/i', $vhost) ? 'on' : 'off';
        $http3  = (bool) preg_match('/quic|http3/i', $vhost) ? 'on' : 'not_configured';
        $brotli = str_contains($nginxV, 'brotli') ? 'on' : 'not_installed';
        $gzip   = (bool) preg_match('/gzip\s+on/i', $vhost . $globalConf) ? 'on' : 'off';
        $browserCache = (bool) preg_match('/expires\s+[^;]+;|add_header\s+Cache-Control/i', $vhost . $globalConf) ? 'on' : 'off';

        $info = [
            'http2'                 => $http2,
            'http3'                 => $http3,
            'brotli'                => $brotli,
            'gzip'                  => $gzip,
            'browser_cache_headers' => $browserCache,
            'scope'                 => 'server_level',
        ];

        // Write cache file (non-fatal)
        $cacheDir = dirname($cacheFile);
        if (is_dir($cacheDir) || @mkdir($cacheDir, 0770, true)) {
            @file_put_contents($cacheFile, json_encode($info));
        }

        return $info;
    }

    /**
     * Sync all sites from the Nginx filesystem into the SQLite panel DB
     * Called when the Sites page is opened
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

            // Only sync those that are actually active (present in sites-enabled)
            if (!file_exists("{$enabledDir}/{$domain}.conf")) continue;

            // Already in the DB, skip
            if ($this->db->row('SELECT id FROM sites WHERE domain = ?', [$domain])) continue;

            // Detect the type from the config contents
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

            // Detect the PHP version
            preg_match('/php(\d+\.\d+)-fpm\.sock/', $content, $m);
            $phpVer = $m[1] ?? php_policy()['default'];

            $this->syncOneSite($domain, $type, $phpVer);
        }
    }

    /**
     * Insert a single site into the DB (INSERT OR IGNORE)
     */
    private function syncOneSite(string $domain, string $type, string $phpVer): void
    {
        $confFile = "/etc/nginx/sites-available/{$domain}.conf";
        $conf     = is_file($confFile) ? (string) file_get_contents($confFile) : '';

        // Source of truth = the vhost the CLI wrote. Read the active web root, and
        // derive the site_user when it lives under /home/<user>/.
        $siteUser = preg_replace('/[^a-z0-9_-].*$/', '', strtolower($domain));
        $webroot  = "/home/{$siteUser}/htdocs/{$domain}";        // fallback if vhost has no root line
        if ($type === 'laravel') { $webroot .= '/public'; }
        if (preg_match('~^\s*root\s+([^;]+);~m', $conf, $m)) {
            $webroot = trim($m[1]);
            if (preg_match('~^/home/([a-z][a-z0-9_-]*)/~', $webroot, $mu)) {
                $siteUser = $mu[1];
            }
        }

        $hasLe = file_exists("/etc/letsencrypt/live/{$domain}/fullchain.pem");
        $ssl   = $hasLe ? 'letsencrypt' : 'self-signed';
        $cache = preg_match('/^\s*fastcgi_cache\s+aidipanel_fcgi\b/m', $conf) ? 1 : 0;

        $this->db->run(
            'INSERT OR IGNORE INTO sites (domain, type, php_version, webroot, site_user, ssl_type, cache_enabled)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$domain, $type, $phpVer, $webroot, $siteUser, $ssl, $cache]
        );
    }
}
