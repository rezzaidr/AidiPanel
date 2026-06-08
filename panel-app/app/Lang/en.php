<?php
/**
 * AidiPanel — English strings (default locale).
 *
 * UI copy is multilingual from the start: never hardcode visible text in views,
 * always go through t('key'). Add other locales as app/Lang/<code>.php with the
 * same keys. Use {placeholder} tokens for dynamic values, e.g.
 *   t('sites.count', ['n' => 4])  with  'sites.count' => '{n} sites on this server'
 */

declare(strict_types=1);

return [
    // App
    'app.name'              => 'AidiPanel',

    // Top-bar navigation
    'nav.dashboard'         => 'Dashboard',
    'nav.sites'             => 'Sites',
    'nav.admin'             => 'Admin Area',

    // Top-bar actions
    'topbar.search'         => 'Search or command',
    'topbar.server_tooltip' => 'Server this panel runs on',
    'topbar.theme'          => 'Theme (coming soon)',
    'topbar.settings'       => 'Settings (coming soon)',
    'topbar.account'        => 'Account',
    'topbar.logout'         => 'Log out',

    // Generic actions / labels
    'action.manage'         => 'Manage',
    'action.open'           => 'Open',
    'common.online'         => 'Online',
    'common.soon'           => 'Soon',

    // Admin Area landing
    'admin.title'           => 'Admin Area',
    'admin.subtitle'        => 'Server-wide settings and services',
    'admin.services.title'  => 'Services',
    'admin.services.desc'   => 'Start, stop and restart Nginx, PHP-FPM, MariaDB, Redis',
    'admin.php.title'       => 'PHP Versions',
    'admin.php.desc'        => 'Installed PHP versions and pool status',
    'admin.cache.title'     => 'FastCGI Cache',
    'admin.cache.desc'      => 'Server-level page cache status and purge',
    'admin.ssl.title'       => 'SSL / TLS',
    'admin.ssl.desc'        => 'Certificates and auto-renewal across sites',
    'admin.users.title'     => 'Panel Users',
    'admin.users.desc'      => 'Accounts that can sign in to AidiPanel',
    'admin.logs.title'      => 'System Logs',
    'admin.logs.desc'       => 'Recent panel and server activity',
    'admin.security.title'  => 'Security',
    'admin.security.desc'   => 'Firewall, Fail2ban, SSH hardening',
    'admin.tuning.title'    => 'Server Tuning',
    'admin.tuning.desc'     => 'Auto-tune PHP-FPM, Nginx, MariaDB, delivery defaults',
    'admin.backups.title'   => 'Backups',
    'admin.backups.desc'    => 'Scheduled and on-demand server backups',

    // Dashboard
    'dash.subtitle'           => 'Real-time monitoring & performance',
    'dash.live'               => 'Live',
    'dash.attention'          => 'Needs Attention',
    'dash.allclear'           => 'All clear — nothing needs your attention right now.',
    'dash.alert.service_down' => '{svc} is stopped',
    'dash.alert.disk_high'    => 'Disk is {pct}% full',
    'dash.alert.mem_high'     => 'Memory is {pct}% used',

    // Dashboard — time range
    'dash.range.30m'          => 'Last 30 minutes',
    'dash.range.1h'           => 'Last 1 hour',
    'dash.range.3h'           => 'Last 3 hours',
    'dash.range.6h'           => 'Last 6 hours',
    'dash.range.12h'          => 'Last 12 hours',

    // Dashboard — KPI tiles
    'dash.kpi.requests'        => 'Requests',
    'dash.kpi.requests_hint'   => 'today',
    'dash.kpi.hit_ratio'       => 'Cache Hit Ratio',
    'dash.kpi.hit_ratio_hint'  => '{cached} of {total} cacheable from cache',
    'dash.kpi.hit_ratio_empty' => 'No cacheable requests yet',
    'dash.kpi.bw_saved'        => 'Bandwidth Saved',
    'dash.kpi.bw_saved_hint'   => 'served from cache today',
    'dash.kpi.data_cached'     => 'Data Cached',
    'dash.kpi.data_cached_hint'=> 'FastCGI cache on disk',

    // Dashboard — traffic analytics (cache effectiveness)
    'dash.traffic.title'      => 'Traffic',
    'dash.traffic.window'     => 'today · per minute',
    'dash.traffic.cached'     => 'Cached',
    'dash.traffic.origin'     => 'Origin / PHP',
    'dash.traffic.empty'      => 'No requests recorded yet today.',
    'dash.cacheperf.title'    => 'Cache Performance',
    'dash.cacheperf.hit'      => 'Cache hit',
    'dash.cacheperf.served'   => 'Served from cache',
    'dash.cacheperf.origin'   => 'Origin / PHP',
    'dash.cacheperf.saved'    => 'Bandwidth saved',
    'dash.cacheperf.empty'    => 'Cache metrics appear once your sites receive traffic.',

    // Dashboard — monitoring (historical charts)
    'dash.system.title'       => 'Monitoring',
    'dash.system.refresh'     => 'auto-refresh · 5s',

    // Dashboard — top sites
    'dash.top_sites'          => 'Top Sites',
    'dash.top_sites.hint'     => 'by requests today',

    // VPS status panel
    'vps.title'             => 'Server status',
    'vps.os'                => 'OS',
    'vps.hostname'          => 'Hostname',
    'vps.cpu'               => 'CPU',
    'vps.memory'            => 'Memory',
    'vps.cloud'             => 'Cloud',
    'vps.droplet'           => 'Droplet ID',
    'vps.region'            => 'Region',
    'vps.ipv4'              => 'IPv4 Public',
    'vps.cores'             => '{n} vCPU',
    'vps.uptime'            => 'Uptime',

    // System charts
    'chart.title'           => 'System metrics',
    'chart.cpu'             => 'CPU Usage',
    'chart.memory'          => 'Memory Usage',
    'chart.disk'            => 'Disk Usage',
    'chart.load'            => 'Load Average',
    'chart.network'         => 'Network I/O',
    'chart.diskio'          => 'Disk I/O',

    // Services panel
    'services.title'        => 'Services',
    'services.running'      => 'running',
    'services.stopped'      => 'stopped',
    'services.empty'        => 'No services detected',

    // Sites list page
    'sites.subtitle'        => '{n} sites on this server',
    'sites.new'             => 'New Site',
    'sites.search_ph'       => 'Search domain…',
    'sites.filter_all'      => 'All apps',
    'sites.created_on'      => 'Created {date}',
    'col.site_user'         => 'Site User',
    'app.wordpress'         => 'WordPress',
    'app.laravel'           => 'Laravel',
    'app.php'               => 'PHP',
    'app.static'            => 'Static',
    'app.proxy'             => 'Reverse Proxy',

    // Sites snapshot (dashboard)
    'dash.sites'            => 'Sites',
    'sites.view_all'        => 'View all',
    'sites.empty'           => 'No sites yet',
    'sites.add_first'       => 'Add your first site',
    'col.domain'            => 'Domain',
    'col.app'               => 'App',
    'col.cache'             => 'Cache',
    'col.ssl'               => 'SSL',
    'col.action'            => 'Action',
    'col.requests'          => 'Requests today',
    'cache.on'              => 'Cached',
    'cache.off'             => 'No cache',
    'ssl.le'                => "Let's Encrypt",
    'ssl.self'              => 'Self-signed',

    // Manage Site — common
    'site.back'             => 'Back to Sites',
    'site.visit'            => 'Visit site',
    'site.active'           => 'Active',

    // Manage Site — tabs
    'site.tab.overview'     => 'Overview',
    'site.tab.performance'  => 'Performance',
    'site.tab.ssl'          => 'SSL/TLS',
    'site.tab.database'     => 'Database',
    'site.tab.security'     => 'Security',
    'site.tab.cron'         => 'Cron',
    'site.tab.files'        => 'Files',
    'site.tab.settings'     => 'Settings',
    'site.tab.soon'         => 'This section is coming soon.',

    // Manage Site — Overview: status cards
    'site.ov.ssl_label'     => 'SSL',
    'site.ov.cache_label'   => 'Cache',
    'site.ov.php_label'     => 'PHP',
    'site.ov.disk_label'    => 'Disk (site)',
    'site.ov.ssl_active'    => 'Active',
    'site.ov.ssl_self'      => 'Self-signed',
    'site.ov.days_left'     => '{n} days left',
    'site.ov.cache_on'      => 'On',
    'site.ov.cache_off'     => 'Off',
    'site.ov.fpm_running'   => 'FPM · running',
    'site.ov.files_uploads' => 'files + uploads',

    // Manage Site — Overview: site details
    'site.detail.title'     => 'Site details',
    'site.detail.domain'    => 'Domain',
    'site.detail.app_type'  => 'App type',
    'site.detail.php'       => 'PHP version',
    'site.detail.webroot'   => 'Document root',
    'site.detail.created'   => 'Created',

    // Manage Site — Overview: activity log
    'site.activity.title'   => 'Recent activity',
    'site.activity.empty'   => 'No activity recorded yet.',
    'site.activity.view_all'=> 'View all logs',

    // Manage Site — Overview: quick actions
    'site.qa.title'         => 'Quick actions',
    'site.qa.visit'         => 'Visit site',
    'site.qa.clear_cache'   => 'Clear cache',
    'site.qa.restart_php'   => 'Restart PHP',

    // Manage Site — Performance tab
    'perf.page_cache'           => 'Page Cache',
    'perf.page_cache.tech'      => 'FastCGI',
    'perf.page_cache.desc'      => 'Serve ready HTML, skip PHP — the biggest speed win.',
    'perf.enable'               => 'Enable cache',
    'perf.disable'              => 'Disable cache',
    'perf.purge'                => 'Purge',
    'perf.object_cache'         => 'Object Cache',
    'perf.object_cache.tech'    => 'Redis',
    'perf.object_cache.desc'    => 'Cache DB queries — lightens the database for dynamic pages.',
    'perf.flush'                => 'Flush',
    'perf.opcache'              => 'OPcache',
    'perf.opcache.desc'         => 'Precompiled PHP bytecode — speeds up every request.',
    'perf.reset'                => 'Reset',
    'perf.delivery'             => 'Delivery & Compression',
    'perf.delivery.desc'        => 'Tuned once at the server level — on by default for all sites.',
    'perf.server_default'       => 'server default',
    'perf.server_tuning'        => 'Server Tuning',
    'perf.redis.ok'             => 'Connected · {keys} keys · {mem}',
    'perf.redis.fail'           => 'Redis not reachable',
    'perf.opcache.hit'          => 'Hit {rate} · {hits} hits',
    'perf.opcache.mem'          => '{used} used of {total}',
    'perf.opcache.disabled'     => 'OPcache not enabled',

    // Admin sub-pages — shared
    'svc.restart'           => 'Restart',
    'svc.start'             => 'Start',
    'svc.stop'              => 'Stop',
    'svc.stop_confirm'      => 'Stop {svc}?',
    'svc.autostart_on'      => 'auto-start on',
    'svc.autostart_off'     => 'auto-start off',

    // PHP page
    'php.restart_all'       => 'Restart all PHP-FPM',
    'php.per_site_title'    => 'PHP version per site',
    'php.not_installed'     => 'Not installed',
    'php.change'            => 'Change →',

    // Cache admin page
    'cache.title_size'      => 'Cache size',
    'cache.title_files'     => 'Cached files',
    'cache.purge_all'       => 'Purge all',
    'cache.purge_all_confirm'=> 'Purge ALL FastCGI cache?',
    'cache.purge_url_title' => 'Purge by URL',
    'cache.purge_url_ph'    => 'https://example.com/page',
    'cache.per_site_title'  => 'Cache per site',
    'cache.redis_title'     => 'Redis object cache',
    'cache.redis_no_stats'  => 'Redis not running or stats unavailable.',
    'cache.hit_rate'        => 'Hit rate',

    // SSL admin page
    'ssl.install_title'     => 'Install Let\'s Encrypt',
    'ssl.install_email'     => 'Email',
    'ssl.install_email_hint'=> 'Optional — used for expiry notifications from Let\'s Encrypt.',
    'ssl.install_dns_note'  => 'Ensure DNS points to this server and port 80 is accessible.',
    'ssl.install_btn'       => 'Install SSL Certificate',
    'ssl.renew_all'         => 'Renew all',
    'ssl.renew'             => 'Renew',
    'ssl.install_link'      => 'Install SSL',
    'ssl.cert_status_title' => 'Certificate status',
    'ssl.no_ssl'            => 'No SSL',
    'ssl.expired'           => 'Expired',
    'ssl.expiring_n'        => 'Expiring · {n}d',
    'ssl.valid_n'           => 'Valid · {n}d',
    'ssl.auto_renew_note'   => 'Auto-renewal is configured via cron.',
    'col.expiry'            => 'Expiry',
    'col.cert_type'         => 'Type',
    'col.status'            => 'Status',

    // Users page
    'users.add_title'       => 'Add panel user',
    'users.username'        => 'Username',
    'users.password'        => 'Password',
    'users.role'            => 'Role',
    'users.role_admin'      => 'Admin (full access)',
    'users.role_viewer'     => 'Viewer (read-only)',
    'users.create_btn'      => 'Create user',
    'users.list_title'      => 'Panel users',
    'users.active'          => 'Active',
    'users.inactive'        => 'Inactive',
    'users.delete_confirm'  => 'Delete user {username}?',
    'users.change_pass'     => 'Change password',
    'users.change_pass_title'=> 'Change Password',
    'users.new_pass_ph'     => 'New password (min 8 chars)',
    'users.never_login'     => 'Never',
    'col.user'              => 'User',
    'col.role'              => 'Role',
    'col.last_login'        => 'Last login',

    // Logs page
    'logs.viewer_title'     => 'Log viewer',
    'logs.domain_ph'        => '— Select domain —',
    'logs.log_type'         => 'Log type',
    'logs.lines'            => 'Lines',
    'logs.load_btn'         => 'Load log',
    'logs.select_hint'      => 'Select a domain and log type to view logs.',
    'logs.empty_log'        => 'Log file is empty or not found.',
    'logs.activity_title'   => 'Panel activity log',
    'logs.activity_empty'   => 'No activity yet.',

    // Add Site form
    'site.add.title'        => 'Add New Site',
    'site.add.domain_label' => 'Domain',
    'site.add.domain_hint'  => 'Without www — both www and non-www will be configured.',
    'site.add.type_label'   => 'Application type',
    'site.add.php_label'    => 'PHP version',
    'site.add.proxy_label'  => 'Proxy pass URL',
    'site.add.proxy_hint'   => 'Upstream URL for your Node.js / Python app (e.g. http://127.0.0.1:3000).',
    'site.add.submit'       => 'Create site',
    'common.cancel'         => 'Cancel',

    // Nginx editor
    'nginx.editor.title'    => 'Nginx Config Editor',
    'nginx.editor.caution'  => 'Edit with caution',
    'nginx.editor.save'     => 'Save & Reload Nginx',
    'nginx.editor.note'     => 'Config is tested before saving. A backup is created automatically.',

    // Manage Site — Settings tab
    'site.set.php_title'    => 'PHP Version',
    'site.set.php_hint'     => 'Restart PHP-FPM after changing version.',
    'site.set.php_apply'    => 'Apply',
    'site.set.nginx_title'  => 'Nginx Configuration',
    'site.set.nginx_hint'   => 'Edit the raw Nginx vhost config for this site.',
    'site.set.nginx_edit'   => 'Edit in editor',
    'site.set.danger_zone'  => 'Danger Zone',
    'site.set.delete_site'  => 'Delete Site',
    'site.set.delete_hint'  => 'Permanently removes the site files and Nginx configuration. This cannot be undone.',
    'site.set.delete_btn'   => 'Delete {domain}',
    'site.set.delete_confirm'=> 'Delete site {domain}? This will remove all files and cannot be undone.',
];
