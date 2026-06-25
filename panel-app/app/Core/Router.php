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

            if ($this->requiresAdmin($method, $routePath)) {
                $this->enforceAdmin();
            }

            // CSRF check for POST requests
            if ($method === 'POST') {
                CsrfMiddleware::handle($this->request);
            }

            $this->call($handler, $params);
            return;
        }

        abort(404, 'Page not found.');
    }

    private function requiresAdmin(string $method, string $routePath): bool
    {
        if ($method === 'POST' && !in_array($routePath, $this->publicRoutes, true)) {
            return true;
        }

        // Read-only demo: let the viewer browse the admin-only READ pages too (file
        // manager, panel users, nginx view, sftp status) — every write is blocked by the
        // demo POST guard above. EXCEPT /logs, which can carry login IPs/paths, so it
        // stays admin-only (hidden in the demo).
        if (demo_mode()) {
            return $routePath === '/logs';
        }

        return $method === 'GET'
            && in_array($routePath, $this->adminOnlyGetRoutes, true);
    }

    private function enforceAdmin(): void
    {
        if (Auth::isAdmin()) {
            return;
        }

        if ($this->request->isAjax()) {
            json(['success' => false, 'message' => 'Administrator privileges required.'], 403);
        }

        abort(403, 'Administrator privileges required.');
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
