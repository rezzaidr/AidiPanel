<?php
declare(strict_types=1);
namespace Core;

/** Fail-closed classification for every route registered by the panel. */
final class RoutePolicy
{
    public const DENY                 = 'deny';
    public const PUBLIC               = 'public';
    public const AUTHENTICATED        = 'authenticated';
    public const ADMIN                = 'admin';
    public const SITE_ADD             = 'site_add';
    public const SITE_DELETE          = 'site_delete';
    public const SITE_SETTINGS        = 'site_settings';
    public const SITE_BACKUP_DOWNLOAD = 'site_backup_download';
    public const SITE_REQUEST_DOMAIN  = 'site_request_domain';
    public const SITE_PATH_DOMAIN     = 'site_path_domain';

    public static function scope(string $method, string $routePath): string
    {
        if (in_array($routePath, ['/login', '/login/2fa'], true)) {
            return self::PUBLIC;
        }

        $authenticated = [
            '/', '/dashboard', '/sites', '/settings', '/logout',
            '/settings/profile', '/settings/password',
            '/settings/2fa/start', '/settings/2fa/cancel', '/settings/2fa/enable',
            '/settings/2fa/disable', '/settings/2fa/recovery',
        ];
        if (in_array($routePath, $authenticated, true)) {
            return self::AUTHENTICATED;
        }

        $admin = [
            '/users', '/users/add', '/users/edit', '/users/delete', '/users/passwd',
            '/logs',
            '/admin', '/admin/web-delivery', '/admin/settings', '/admin/settings/domain', '/admin/settings/domain/clear',
            '/admin/backups', '/admin/backups/test', '/admin/backups/destination',
            '/admin/backups/policy', '/admin/backups/run',
            '/services', '/services/action', '/api/services',
            '/php/restart', '/cache/opcache-restart',
            '/api/metrics', '/api/metrics/history', '/api/cli',
        ];
        if (in_array($routePath, $admin, true)) {
            return self::ADMIN;
        }

        if ($routePath === '/sites/add' || $routePath === '/sites/add/{type}') {
            return self::SITE_ADD;
        }
        if ($routePath === '/sites/{domain}/delete') {
            return self::SITE_DELETE;
        }
        if ($routePath === '/sites/{domain}/backups/download') {
            return self::SITE_BACKUP_DOWNLOAD;
        }
        if ($routePath === '/sites/{domain}/php' || $routePath === '/sites/{domain}/php-settings') {
            return self::SITE_SETTINGS;
        }
        if (str_starts_with($routePath, '/cache/')
            || str_starts_with($routePath, '/api/cache/')
            || $routePath === '/api/ssl/check') {
            return self::SITE_REQUEST_DOMAIN;
        }
        if (str_starts_with($routePath, '/sites/{domain}')) {
            return self::SITE_PATH_DOMAIN;
        }

        return self::DENY;
    }

    /** Public demo is read-only. Backup download is a sensitive GET exception. */
    public static function blockedInDemo(string $method, string $routePath): bool
    {
        return ($method === 'POST' && $routePath !== '/api/cli')
            || $routePath === '/sites/{domain}/backups/download';
    }
}
