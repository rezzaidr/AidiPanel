<?php

declare(strict_types=1);

namespace Core;

use Middleware\AuthMiddleware;
use Middleware\CsrfMiddleware;

class Router
{
    private array $routes = [];
    private Request $request;

    // Routes that do NOT require authentication
    private array $publicRoutes = ['/login', '/login/2fa'];

    // Read-only users may browse operational pages, but not sensitive admin views.
    private array $adminOnlyGetRoutes = [
        '/logs',
        '/users',
        '/admin/settings',
        '/sites/{domain}/nginx',
        '/sites/{domain}/files/list',
        '/sites/{domain}/files/read',
        '/sites/{domain}/files/download',
        '/sites/{domain}/files/download-many',
        '/sites/{domain}/sftp/status',
    ];

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function get(string $path, string $handler): void
    {
        $this->routes[] = ['GET', $path, $handler];
    }

    public function post(string $path, string $handler): void
    {
        $this->routes[] = ['POST', $path, $handler];
    }

    public function dispatch(): void
    {
        $method = $this->request->method();
        $uri    = $this->request->uri();

        foreach ($this->routes as [$routeMethod, $routePath, $handler]) {
            if ($routeMethod !== $method) {
                continue;
            }

            $params = $this->match($routePath, $uri);
            if ($params === null) {
                continue;
            }

            // Read-only demo: block every mutation. All writes are POST; only the
            // self-whitelisted read-only /api/cli is let through. Login is unneeded
            // (visitors are auto-signed-in as the viewer user), so /login* POST is
            // blocked too — no brute-force surface against the real admin account.
            if (demo_mode() && $method === 'POST'
                && !in_array($routePath, ['/api/cli'], true)) {
                // AJAX/stream callers gate on the JSON body's success flag, not the HTTP
                // status (opStream reads this message too), so return 200 + a clear message
                // rather than a 403 that would surface as a generic "couldn't start" error.
                if ($this->request->isAjax()) {
                    json(['success' => false, 'message' => 'This is a read-only demo — changes are disabled.']);
                }
                flash('warning', 'This is a read-only demo — changes are disabled.');
                redirect(safe_back_url());
            }

            // Auth check
            if (!in_array($uri, $this->publicRoutes, true)) {
                AuthMiddleware::handle();
            }

            // Authorization (roles + per-site access). Replaces the old binary requiresAdmin.
            $this->authorize($method, $routePath, $params);

            // CSRF check for POST requests
            if ($method === 'POST') {
                CsrfMiddleware::handle($this->request);
            }

            $this->call($handler, $params);
            return;
        }

        abort(404, 'Page not found.');
    }

    /**
     * Central authorization for the matched route. Called after auth, before CSRF.
     * One place classifies every route: admin-area (admin only), self-service
     * (any user), /sites/add + /sites/{domain}/delete (admin+manager), other
     * /sites/{domain}/* (canManageSite), /cache/* (per-site, domain in the body).
     * Manager/client cannot reach the admin area; clients cannot reach sites they
     * are not assigned to. The demo viewer keeps its admin-read browsability via
     * Access::canAccessAdminArea(); the demo POST guard (dispatch) still blocks writes.
     */
    private function authorize(string $method, string $routePath, array $params): void
    {
        // 1. Self-service — any authenticated user manages their OWN account.
        $selfService = [
            '/settings/profile', '/settings/password',
            '/settings/2fa/start', '/settings/2fa/cancel', '/settings/2fa/enable',
            '/settings/2fa/disable', '/settings/2fa/recovery',
        ];
        if (in_array($routePath, $selfService, true)) {
            return;
        }

        // 2. Admin area — admin only (Users, Logs, Settings, services, PHP, global cache).
        //    /cache/* is NOT here: it is per-site (domain in the body) — handled at #5.
        $adminArea = [
            '/users', '/users/add', '/users/edit', '/users/delete', '/users/passwd',
            '/logs',
            '/admin', '/admin/settings', '/admin/settings/domain', '/admin/settings/domain/clear',
            '/services', '/services/action',
            '/php/restart',
            // Global cache op: restarts a PHP version's OPcache system-wide (reads 'php', not a domain):
            '/cache/opcache-restart',
            // Raw server-level CLI bridge (site:list / db:list / system:info etc.) — admin only.
            // The demo viewer still reaches it: the demo POST guard exempts /api/cli, and
            // canAccessAdminArea() allows demo_mode + viewer. No panel UI calls this.
            '/api/cli',
        ];
        if (in_array($routePath, $adminArea, true)) {
            if (!\Core\Access::canAccessAdminArea()) {
                $this->deny(403, 'Administrator access required.');
            }
            return;
        }

        // 3. Site add (GET picker/forms + POST create) — admin or manager.
        if ($routePath === '/sites/add' || $routePath === '/sites/add/{type}') {
            if (!\Core\Access::canAddSite()) {
                $this->deny(403, 'You cannot add sites.');
            }
            return;
        }

        // 4. Site delete — admin or manager (never client).
        if ($routePath === '/sites/{domain}/delete') {
            if (!\Core\Access::canDeleteSite()) {
                $this->deny(403, 'You cannot delete sites.');
            }
            return;
        }

        // 5. Per-site cache/ssl ops — the domain travels in the request (POST body or
        // GET query), not the path. Covers the POST /cache/* actions AND the GET
        // /api/cache/* + /api/ssl/check reads (which carry ?domain=), so a client can
        // only read cache/SSL state for sites they are assigned to.
        if (str_starts_with($routePath, '/cache/')
            || str_starts_with($routePath, '/api/cache/')
            || $routePath === '/api/ssl/check') {
            $domain = (string) ($this->request->post('domain') ?? $this->request->get('domain') ?? '');
            if ($domain === '' || !\Core\Access::canManageSite($domain)) {
                $this->deny(404, 'Not found.');
            }
            return;
        }

        // 6. Any other /sites/{domain}/* — manage-site (admin/manager always; client iff assigned).
        if (str_starts_with($routePath, '/sites/{domain}')) {
            $domain = (string) ($params['domain'] ?? '');
            if ($domain === '' || !\Core\Access::canManageSite($domain)) {
                $this->deny(404, 'Not found.');   // 404: don't leak other clients' sites.
            }
            return;
        }

        // 7. Everything else (sites list /, /dashboard, read APIs, /settings GET, /logout):
        //    any authenticated user. The sites LIST is scoped in SiteController::index().
    }

    /** AJAX-aware denial: JSON for fetch callers, an error page for full navigations. */
    private function deny(int $code, string $message): never
    {
        if ($this->request->isAjax()) {
            json(['success' => false, 'message' => $message], $code);
        }
        abort($code, $message);
    }

    /**
     * Match a route pattern against the URI, returning params or null
     * Supports {param} placeholders
     */
    private function match(string $pattern, string $uri): ?array
    {
        // Convert {param} to named capture groups
        $regex = preg_replace('/\{([a-z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (!preg_match($regex, $uri, $matches)) {
            return null;
        }

        // Return only named params
        return array_filter($matches, fn($k) => is_string($k), ARRAY_FILTER_USE_KEY);
    }

    private function call(string $handler, array $params): void
    {
        [$controllerName, $method] = explode('@', $handler);
        $class = "Controllers\\{$controllerName}";

        if (!class_exists($class)) {
            abort(500, "Controller not found: {$class}");
        }

        $controller = new $class($this->request);

        if (!method_exists($controller, $method)) {
            abort(500, "Method not found: {$class}::{$method}");
        }

        $controller->{$method}($params);
    }
}
