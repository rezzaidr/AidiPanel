<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/panel-app/app/Core/Request.php';

function requestIpCheck(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "ok: {$label}\n";
}

/** @param array<string, string> $server */
function requestIpResolve(array $server): string
{
    $previous = $_SERVER;
    try {
        $_SERVER = $server;
        return (new \Core\Request())->ip();
    } finally {
        $_SERVER = $previous;
    }
}

requestIpCheck(
    requestIpResolve([
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.20, 192.0.2.30',
    ]) === '203.0.113.10',
    'a direct peer cannot spoof its address through X-Forwarded-For'
);

requestIpCheck(
    requestIpResolve([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
    ]) === '198.51.100.20',
    'an IPv4 loopback proxy can forward one valid client address'
);

requestIpCheck(
    requestIpResolve([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.20, 192.0.2.30',
    ]) === '192.0.2.30',
    'a loopback proxy uses the nearest rightmost IPv4 hop'
);

requestIpCheck(
    requestIpResolve([
        'REMOTE_ADDR' => '::1',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.20, 2001:db8::30',
    ]) === '2001:db8::30',
    'an IPv6 loopback proxy accepts a valid rightmost IPv6 hop'
);

requestIpCheck(
    requestIpResolve([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.20, not-an-ip',
    ]) === '127.0.0.1',
    'an invalid rightmost hop fails closed instead of trusting an earlier value'
);

requestIpCheck(
    requestIpResolve([
        'REMOTE_ADDR' => '::1',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.20,   ',
    ]) === '::1',
    'an empty rightmost hop fails closed to the immediate proxy'
);

requestIpCheck(
    requestIpResolve([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '',
    ]) === '127.0.0.1',
    'an empty forwarded header falls back to the immediate proxy'
);

echo "request IP proxy-boundary tests passed\n";
