<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/panel-app/app/Core/RoutePolicy.php';

use Core\RoutePolicy;

$index = file_get_contents($root . '/panel-app/public/index.php');
if (!is_string($index)) {
    fwrite(STDERR, "FAIL: could not read route registry.\n");
    exit(1);
}

preg_match_all('/\$router->(get|post)\s*\(\s*[\'\"]([^\'\"]+)/i', $index, $matches, PREG_SET_ORDER);
if (count($matches) < 90) {
    fwrite(STDERR, 'FAIL: route parser found only ' . count($matches) . " routes.\n");
    exit(1);
}

foreach ($matches as $match) {
    $method = strtoupper($match[1]);
    $path = $match[2];
    if (RoutePolicy::scope($method, $path) === RoutePolicy::DENY) {
        fwrite(STDERR, "FAIL: registered route has no policy: {$method} {$path}\n");
        exit(1);
    }
}

if (RoutePolicy::scope('GET', '/future/unclassified') !== RoutePolicy::DENY) {
    fwrite(STDERR, "FAIL: unknown routes must fail closed.\n");
    exit(1);
}
if (!RoutePolicy::blockedInDemo('GET', '/sites/{domain}/backups/download')) {
    fwrite(STDERR, "FAIL: demo must block backup downloads.\n");
    exit(1);
}

echo 'route policy coverage contract passed: ' . count($matches) . " routes\n";
