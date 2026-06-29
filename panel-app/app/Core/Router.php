<?php
declare(strict_types=1);

namespace Core;

use Middleware\AuthMiddleware;
use Middleware\CsrfMiddleware;

class Router
{
    private array $routes = [];
    private Request $request;

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

            // The public demo is read-only. In addition to POST mutations, the
            // policy blocks sensitive GET reads such as full backup downloads.
            if (demo_mode() && RoutePolicy::blockedInDemo($method, $routePath)) {
                if ($this->request->isAjax()) {
                    json(['success' => false, 'message' => 'This is a read-only demo — changes are disabled.']);
                }
                flash('warning', 'This is a read-only demo — changes are disabled.');
                redirect(safe_back_url());
            }

            if (RoutePolicy::scope($method, $routePath) !== RoutePolicy::PUBLIC) {
                AuthMiddleware::handle();
            }

            $this->authorize($method, $routePath, $params);

            if ($method === 'POST') {
                CsrfMiddleware::handle($this->request);
            }

            $this->call($handler, $params);
            return;
        }

        abort(404, 'Page not found.');
    }

    /** Enforce the explicit fail-closed policy for the matched route. */
    private function authorize(string $method, string $routePath, array $params): void
    {
        $scope = RoutePolicy::scope($method, $routePath);

        if ($scope === RoutePolicy::PUBLIC || $scope === RoutePolicy::AUTHENTICATED) {
            return;
        }

        if ($scope === RoutePolicy::ADMIN) {
            if (!Access::canAccessAdminArea()) {
                $this->deny(403, 'Administrator access required.');
            }
            return;
        }

        if ($scope === RoutePolicy::SITE_ADD) {
            if (!Access::canAddSite()) {
                $this->deny(403, 'You cannot add sites.');
            }
            return;
        }

        if ($scope === RoutePolicy::SITE_DELETE) {
            if (!Access::canDeleteSite()) {
                $this->deny(403, 'You cannot delete sites.');
            }
            return;
        }

        if ($scope === RoutePolicy::SITE_SETTINGS) {
            if (!Access::canEditSiteSettings()) {
                $this->deny(403, 'You cannot change PHP settings.');
            }
            return;
        }

        if ($scope === RoutePolicy::SITE_REQUEST_DOMAIN) {
            $domain = (string) ($this->request->post('domain') ?? $this->request->get('domain') ?? '');
            if ($domain === '' || !Access::canManageSite($domain)) {
                $this->deny(404, 'Not found.');
            }
            return;
        }

        if ($scope === RoutePolicy::SITE_PATH_DOMAIN || $scope === RoutePolicy::SITE_BACKUP_DOWNLOAD) {
            $domain = (string) ($params['domain'] ?? '');
            if ($domain === '' || !Access::canManageSite($domain)) {
                $this->deny(404, 'Not found.');
            }
            return;
        }

        // This indicates a developer forgot to classify a newly registered route.
        // Deny everyone, including administrators, until a policy is added.
        $this->deny(403, 'Access policy not configured.');
    }

    /** AJAX-aware denial: JSON for fetch callers, an error page otherwise. */
    private function deny(int $code, string $message): never
    {
        if ($this->request->isAjax()) {
            json(['success' => false, 'message' => $message], $code);
        }
        abort($code, $message);
    }

    /** Match a route pattern against the URI and return named parameters. */
    private function match(string $pattern, string $uri): ?array
    {
        $regex = preg_replace('/\{([a-z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (!preg_match($regex, $uri, $matches)) {
            return null;
        }

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
