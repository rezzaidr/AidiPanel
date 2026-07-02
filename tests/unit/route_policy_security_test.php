<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/panel-app/app/Core/RoutePolicy.php';

use Core\RoutePolicy;

function expectScope(string $route, string $expected): void
{
    $actual = RoutePolicy::scope('GET', $route);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$route} expected {$expected}, got {$actual}\n");
        exit(1);
    }
    echo "ok: {$route} is {$expected}\n";
}

expectScope('/sites/{domain}/nginx', RoutePolicy::SITE_SETTINGS);
expectScope('/sites/{domain}', RoutePolicy::SITE_PATH_DOMAIN);

echo "route policy security contract passed\n";
