<?php
declare(strict_types=1);

/**
 * Regression contract for the cross-tenant cache authorization fix.
 *
 * Background: POST /cache/redis was classified SITE_REQUEST_DOMAIN (client-
 * scoped) but its controller ignored the domain and ran the GLOBAL
 * cache:redis-{enable,disable,flush} commands (→ Redis FLUSHALL / service stop
 * across the whole server) — a client with one site could disrupt every tenant
 * (BLOCKER). Separately, /cache/purge discarded the domain when a `url` was
 * sent and the CLI hashed the raw URL with no ownership check → one tenant
 * could purge another's cached page (MAJOR).
 *
 * This test pins the secure state so a regression — re-adding the route, the
 * controller method, the --url/--force branches, or the global redis verbs to
 * any web-allowlist copy — fails CI.
 */

require_once dirname(__DIR__, 2) . '/panel-app/app/Core/RoutePolicy.php';
require_once dirname(__DIR__, 2) . '/panel-app/app/Core/helpers.php';

use Core\RoutePolicy;

$repo = dirname(__DIR__, 2);

function fail(string $msg): never
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function mustNotContain(string $label, string $haystack, string $needle): void
{
    if ($needle !== '' && str_contains($haystack, $needle)) {
        fail("{$label} must not contain '{$needle}'");
    }
    echo "ok: {$label} excludes '{$needle}'\n";
}

function mustContain(string $label, string $haystack, string $needle): void
{
    if (!str_contains($haystack, $needle)) {
        fail("{$label} must contain '{$needle}'");
    }
    echo "ok: {$label} contains '{$needle}'\n";
}

/** Capture one class method's full source (signature → closing brace). */
function extractMethod(string $src, string $name): string
{
    $pattern = '/function ' . preg_quote($name, '/') . '\b.*?(?=^\s{4}(?:public|private|protected) function |\z)/ms';
    if (!preg_match($pattern, $src, $m)) {
        fail("could not locate method {$name}()");
    }
    return $m[0];
}

// --- A1: the global /cache/redis web path is fully closed ---------------------

$routes = (string) file_get_contents("{$repo}/panel-app/public/index.php");
mustNotContain('index.php routes', $routes, "CacheController@redis");
mustNotContain('index.php routes', $routes, "/cache/redis'");

$cache = (string) file_get_contents("{$repo}/panel-app/app/Controllers/CacheController.php");
mustNotContain('CacheController', $cache, 'function redis(');

foreach ([
    'helpers.php'  => "{$repo}/panel-app/app/Core/helpers.php",
    'install.sh'   => "{$repo}/install.sh",
    'deploy-panel' => "{$repo}/panel-app/deploy-panel.sh",
] as $label => $path) {
    $src = (string) file_get_contents($path);
    foreach (['cache:redis-enable', 'cache:redis-disable', 'cache:redis-flush'] as $verb) {
        mustNotContain($label, $src, $verb);
    }
}

// --- A2: purge() is domain-scoped only (no --url / --force branch) ------------

$purge = extractMethod($cache, 'purge');
mustNotContain('CacheController::purge', $purge, "post('url'");
mustNotContain('CacheController::purge', $purge, "'--url'");
mustNotContain('CacheController::purge', $purge, "'--force'");
mustContain('CacheController::purge', $purge, "run_cli('cache:purge', ['--domain', \$domain])");

// --- The surviving per-site cache routes stay client-gated --------------------

$scope = RoutePolicy::scope('POST', '/cache/purge');
if ($scope !== RoutePolicy::SITE_REQUEST_DOMAIN) {
    fail("/cache/purge must stay SITE_REQUEST_DOMAIN, got {$scope}");
}
echo "ok: /cache/purge remains SITE_REQUEST_DOMAIN\n";

// The per-site object-cache command (cache:redis --action ... --domain) stays
// allowed; only the global -enable/-disable/-flush verbs were removed.
$allowed = web_cli_allowed_commands();
foreach (['cache:redis', 'cache:purge', 'cache:page', 'cache:zone'] as $cmd) {
    if (!in_array($cmd, $allowed, true)) {
        fail("web allowlist must still permit per-site '{$cmd}'");
    }
}
foreach (['cache:redis-enable', 'cache:redis-disable', 'cache:redis-flush'] as $cmd) {
    if (in_array($cmd, $allowed, true)) {
        fail("web allowlist must not permit global '{$cmd}'");
    }
}
echo "ok: per-site cache verbs allowed; global redis verbs blocked\n";

echo "cache scope security contract passed\n";
