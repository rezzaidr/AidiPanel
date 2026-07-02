<?php
declare(strict_types=1);

$db = file_get_contents(dirname(__DIR__, 2) . '/panel-app/app/Core/DB.php');

function seedExpect(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "ok: {$label}\n";
}

seedExpect(str_contains($db, "throw new \\RuntimeException('Initial admin hash is required.')"), 'database initialization fails closed without an installer-provided admin hash');
seedExpect(!str_contains($db, '/tmp/aidipanel-fallback-pass.txt'), 'admin credentials are never written to a shared temporary directory');
seedExpect(!str_contains($db, 'AidiPanel FALLBACK'), 'admin credentials are never mentioned in the PHP error log');

echo "admin seed fail-closed contract passed\n";
