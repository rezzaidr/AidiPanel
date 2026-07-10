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

expectScope('/sites/{domain}/nginx', RoutePolicy::ADMIN);
expectScope('/sites/{domain}', RoutePolicy::SITE_PATH_DOMAIN);

if (!RoutePolicy::blockedInDemo('GET', '/sites/{domain}/nginx')) {
    fwrite(STDERR, "FAIL: demo must not expose raw Nginx configuration.\n");
    exit(1);
}
echo "ok: demo blocks raw Nginx configuration\n";

$detailView = file_get_contents(dirname(__DIR__, 2) . '/panel-app/app/Views/sites/detail.php');
if (!is_string($detailView) || !preg_match(
    '/<\?php if \(\\\\Core\\\\Auth::isAdmin\(\)\): \?>\s*<!-- Nginx config -->.*?<\?php endif; \?>\s*<\?php if \(\\\\Core\\\\Access::canDeleteSite\(\)\): \?>/s',
    $detailView
)) {
    fwrite(STDERR, "FAIL: the complete raw Nginx card must be admin-only.\n");
    exit(1);
}
echo "ok: raw Nginx card is admin-only\n";

echo "route policy security contract passed\n";
