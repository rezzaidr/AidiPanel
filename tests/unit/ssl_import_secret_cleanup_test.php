<?php
declare(strict_types=1);

$controller = file_get_contents(dirname(__DIR__, 2) . '/panel-app/app/Controllers/SiteSslController.php');

function sslImportExpect(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "ok: {$label}\n";
}

sslImportExpect(str_contains($controller, 'bin2hex(random_bytes(16))'), 'SSL import uses a high-entropy private staging directory');
sslImportExpect(str_contains($controller, 'try {') && str_contains($controller, '} finally {'), 'SSL import cleanup runs on every exit path');
sslImportExpect(str_contains($controller, 'file_put_contents($path, $pem, LOCK_EX)'), 'every staged PEM write is checked');
sslImportExpect(str_contains($controller, "chmod(\$path, 0600)"), 'staged PEM permissions are enforced');

echo "SSL import secret cleanup contract passed\n";
