<?php
declare(strict_types=1);
namespace Core;

class Request
{
    private ?array $jsonBody = null;

    public function method(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // RFC 7231: HEAD is identical to GET minus the body. Map HEAD→GET so the router
        // matches GET routes; otherwise HEAD 404s, which makes uptime/health checks
        // falsely report the panel (and the public demo) as down. POST-only routes still
        // 404 for HEAD (HEAD is not POST), and CSRF/demo-POST guards key on 'POST'.
        return $method === 'HEAD' ? 'GET' : $method;
    }

    public function uri(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return '/' . trim((string) $uri, '/') ?: '/';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $this->jsonBody()[$key] ?? $default;
    }

    public function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    public function ip(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Only trust X-Forwarded-For when the immediate peer is a trusted proxy
        // (the panel sits behind the local Nginx on the same host).
        $trustedProxies = ['127.0.0.1', '::1'];
        if (in_array($remote, $trustedProxies, true)) {
            $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($forwardedFor !== '') {
                $firstIp = trim(explode(',', $forwardedFor)[0]);
                if (filter_var($firstIp, FILTER_VALIDATE_IP)) {
                    return $firstIp;
                }
            }
        }

        return $remote;
    }

    private function jsonBody(): array
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_contains($contentType, 'application/json')) {
            return $this->jsonBody = [];
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return $this->jsonBody = [];
        }

        $decoded = json_decode($raw, true);
        return $this->jsonBody = is_array($decoded) ? $decoded : [];
    }
}
