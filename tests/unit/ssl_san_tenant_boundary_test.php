<?php
declare(strict_types=1);

namespace Controllers {
    class BaseController {}
}

namespace {
    $root = dirname(__DIR__, 2);
    $path = $root . '/panel-app/app/Controllers/SiteSslController.php';
    $source = (string) file_get_contents($path);
    require_once $path;

    function sslSanFail(string $message): never
    {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    function sslSanOk(bool $condition, string $label): void
    {
        if (!$condition) sslSanFail($label);
        echo "ok: {$label}\n";
    }

    function sslSanMethod(string $source, string $name): string
    {
        $pattern = '/(?:public|private|protected) (?:static )?function '
            . preg_quote($name, '/')
            . '\b.*?(?=^\s{4}(?:public|private|protected) (?:static )?function |\z)/ms';
        if (!preg_match($pattern, $source, $match)) {
            sslSanFail("could not locate {$name}()");
        }
        return $match[0];
    }

    $class = \Controllers\SiteSslController::class;
    sslSanOk(method_exists($class, 'certificateDomainWithinSite'),
        'controller defines the certificate-domain relationship predicate');
    $predicate = new \ReflectionMethod($class, 'certificateDomainWithinSite');
    $predicate->setAccessible(true);
    $within = static fn(string $candidate, string $primary): bool =>
        (bool) $predicate->invoke(null, $candidate, $primary);

    sslSanOk($within('example.com', 'example.com'), 'primary domain is accepted');
    sslSanOk($within('www.example.com', 'example.com'), 'www subdomain is accepted');
    sslSanOk($within('api.shop.example.com', 'example.com'), 'nested subdomain is accepted');
    sslSanOk(!$within('evilexample.com', 'example.com'), 'suffix confusion is rejected');
    sslSanOk(!$within('example.com.evil.test', 'example.com'), 'parent-prefix confusion is rejected');
    sslSanOk(!$within('example.net', 'example.com'), 'unrelated domains are rejected');

    $install = sslSanMethod($source, 'install');
    $guard = '$this->assertCertificateDomainsBelongToSite($domain, $domains);';
    $guardPos = strpos($install, $guard);
    $argsPos = strpos($install, "\$args = ['--domains'");
    sslSanOk($guardPos !== false, 'install invokes the SAN tenant guard');
    sslSanOk($argsPos !== false && $guardPos < $argsPos,
        'controller guard runs before CLI arguments are built');

    $assertion = sslSanMethod($source, 'assertCertificateDomainsBelongToSite');
    sslSanOk(str_contains($assertion, 'SELECT id FROM sites WHERE domain = ?'),
        'controller rejects a SAN registered as another managed site');
    sslSanOk(str_contains($assertion, 'self::certificateDomainWithinSite($candidate, $primary)'),
        'controller uses the tested relationship predicate');

    echo "SSL SAN controller tenant-boundary contract passed\n";
}
