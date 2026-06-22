<?php
/**
 * AidiPanel — Entry Point v1.2.0-rc1
 * All requests go through here (Nginx: try_files $uri $uri/ /index.php?$args)
 */

declare(strict_types=1);

define('PANEL_ROOT',    dirname(__DIR__));
define('APP_ROOT',      PANEL_ROOT . '/app');
define('STORAGE_ROOT',  PANEL_ROOT . '/storage');
define('PUBLIC_ROOT',   __DIR__);
define('PANEL_VERSION', '1.2.0-rc1');

// PANEL_DIR = /opt/aidipanel (used by DB.php to read credentials.conf)
define('PANEL_DIR', '/opt/aidipanel');

// Default UI locale (English). Built multilingual from the start; later this can
// come from panel settings or the signed-in user's preference.
define('PANEL_LOCALE', 'en');

// ── Autoloader (simple PSR-4 style, no Composer needed) ─────────────────────
spl_autoload_register(function (string $class): void {
    $file = APP_ROOT . '/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// ── Bootstrap ────────────────────────────────────────────────────────────────
require_once APP_ROOT . '/Core/helpers.php';

use Core\Router;
use Core\Request;
use Core\Session;

Session::start();

// The panel UI must never be cached anywhere (browser, proxy, or FastCGI). Applies to
// dynamic PHP responses only — static assets are served straight from Nginx. Streamed
// endpoints reset their own Cache-Control in stream_begin().
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$request = new Request();
$router  = new Router($request);

// ── Routes ───────────────────────────────────────────────────────────────────

// Auth
$router->get('/login',  'AuthController@showLogin');
$router->post('/login', 'AuthController@login');
$router->get('/logout', 'AuthController@logout');

// Dashboard
$router->get('/',          'DashboardController@index');
$router->get('/dashboard', 'DashboardController@index');

// Admin Area (server-wide hub — collects services, PHP, cache, SSL, users, logs)
$router->get('/admin', 'AdminController@index');

// Sites
$router->get('/sites',               'SiteController@index');
$router->get('/sites/add',           'SiteController@showAdd');
$router->get('/sites/add/{type}',    'SiteController@showAddForm');
$router->post('/sites/add',          'SiteController@add');
$router->get('/sites/{domain}',      'SiteController@detail');
$router->post('/sites/{domain}/delete', 'SiteController@delete');
$router->post('/sites/{domain}/php', 'SiteController@changePhp');
$router->get('/sites/{domain}/nginx','SiteController@nginxEditor');
$router->post('/sites/{domain}/nginx','SiteController@saveNginx');
$router->post('/sites/{domain}/ssl/install', 'SiteSslController@install');
$router->post('/sites/{domain}/ssl/renew',   'SiteSslController@renew');
$router->post('/sites/{domain}/ssl/import',  'SiteSslController@import');
$router->post('/sites/{domain}/ssl/force-https', 'SiteSslController@forceHttps');
$router->post('/sites/{domain}/ssl/hsts',    'SiteSslController@hsts');
$router->post('/sites/{domain}/ssl/autorenew', 'SiteSslController@autoRenew');
$router->post('/sites/{domain}/ssl/use',     'SiteSslController@useCert');
$router->get('/api/ssl/check',               'SiteSslController@check');
$router->post('/sites/{domain}/security/basic-auth', 'SiteSecurityController@basicAuth');
$router->post('/sites/{domain}/security/ip-block', 'SiteSecurityController@ipBlock');
$router->post('/sites/{domain}/security/cloudflare-only', 'SiteSecurityController@cloudflareOnly');

// Database tab (Manage Site → Database): site-scoped database + user actions.
$router->post('/sites/{domain}/database/add',         'SiteDatabaseController@addDatabase');
$router->post('/sites/{domain}/database/user/add',    'SiteDatabaseController@addUser');
$router->post('/sites/{domain}/database/user/edit',   'SiteDatabaseController@editUser');
$router->post('/sites/{domain}/database/phpmyadmin/install', 'SiteDatabaseController@installPhpMyAdmin');
$router->post('/sites/{domain}/database/phpmyadmin/open',    'SiteDatabaseController@openPhpMyAdmin');
$router->post('/sites/{domain}/database/delete',      'SiteDatabaseController@deleteDatabase');
$router->post('/sites/{domain}/database/user/delete', 'SiteDatabaseController@deleteUser');

// Per-site cron jobs
$router->post('/sites/{domain}/cron/add',    'SiteCronController@add');
$router->post('/sites/{domain}/cron/update', 'SiteCronController@update');
$router->post('/sites/{domain}/cron/delete', 'SiteCronController@delete');
$router->post('/sites/{domain}/cron/toggle', 'SiteCronController@toggle');
$router->post('/sites/{domain}/cron/wp',     'SiteCronController@wpCron');

// Per-site File Manager
$router->get('/sites/{domain}/files/list',     'SiteFileController@list');
$router->get('/sites/{domain}/files/read',     'SiteFileController@read');
$router->get('/sites/{domain}/files/download', 'SiteFileController@download');
$router->post('/sites/{domain}/files/save',    'SiteFileController@save');
$router->post('/sites/{domain}/files/mkdir',   'SiteFileController@mkdir');
$router->post('/sites/{domain}/files/delete',  'SiteFileController@delete');
$router->post('/sites/{domain}/files/rename',  'SiteFileController@rename');
$router->post('/sites/{domain}/files/copy',    'SiteFileController@copy');
$router->post('/sites/{domain}/files/move',    'SiteFileController@move');
$router->post('/sites/{domain}/files/chmod',   'SiteFileController@chmod');
$router->post('/sites/{domain}/files/zip',     'SiteFileController@zip');
$router->post('/sites/{domain}/files/unzip',   'SiteFileController@unzip');
$router->get('/sites/{domain}/files/download-many', 'SiteFileController@downloadMany');
$router->post('/sites/{domain}/files/upload-chunk', 'SiteFileController@uploadChunk');
$router->post('/sites/{domain}/files/upload-cancel', 'SiteFileController@uploadCancel');

// Per-site SFTP access
$router->get('/sites/{domain}/sftp/status',         'SiteSftpController@status');
$router->post('/sites/{domain}/sftp/enable',         'SiteSftpController@enable');
$router->post('/sites/{domain}/sftp/disable',        'SiteSftpController@disable');
$router->post('/sites/{domain}/sftp/password',       'SiteSftpController@setPassword');
$router->post('/sites/{domain}/sftp/password-clear', 'SiteSftpController@clearPassword');
$router->post('/sites/{domain}/sftp/key-add',        'SiteSftpController@addKey');
$router->post('/sites/{domain}/sftp/key-delete',     'SiteSftpController@deleteKey');

// Cache
$router->get('/cache',         'CacheController@index');
$router->post('/cache/purge',  'CacheController@purge');
$router->post('/cache/toggle',          'CacheController@toggle');
$router->post('/cache/config',          'CacheController@config');
$router->post('/cache/redis',           'CacheController@redis');
$router->post('/cache/object',          'CacheController@objectCache');
$router->post('/cache/purge-urls',      'CacheController@purgeUrls');
$router->post('/cache/opcache-restart', 'CacheController@opcacheRestart');
$router->post('/cache/zone',            'CacheController@zone');

// PHP
$router->get('/php',           'PhpController@index');
$router->post('/php/restart',  'PhpController@restart');
$router->post('/php/install',  'PhpController@install');

// SSL
$router->get('/ssl',           'SslController@index');
$router->post('/ssl/install',  'SslController@install');
$router->post('/ssl/renew',    'SslController@renew');

// Services
$router->get('/services',          'ServiceController@index');
$router->post('/services/action',  'ServiceController@action');

// Users
$router->get('/users',             'UserController@index');
$router->post('/users/add',        'UserController@add');
$router->post('/users/delete',     'UserController@delete');
$router->post('/users/passwd',     'UserController@changePassword');

// Logs
$router->get('/logs', 'SystemController@logs');

// Account settings (self-service profile + security; reached from the profile menu)
$router->get('/settings', 'SettingsController@index');
$router->post('/settings/profile',  'SettingsController@saveProfile');
$router->post('/settings/password', 'SettingsController@changePassword');

// API (JSON responses for Alpine.js fetch calls)
$router->get('/api/metrics',         'DashboardController@apiMetrics');
$router->get('/api/metrics/history', 'DashboardController@apiHistory');
$router->get('/api/services',     'ServiceController@apiStatus');
$router->get('/api/cache/stats',  'CacheController@apiStats');
$router->get('/api/cache/object-metrics', 'CacheController@objectCacheMetrics');
$router->get('/api/cache/check',   'CacheController@checkUrl');
$router->get('/api/cache/zone-status', 'CacheController@zoneStatus');
$router->post('/api/cli',         'SystemController@apiCli');

// ── Dispatch ─────────────────────────────────────────────────────────────────
$router->dispatch();
