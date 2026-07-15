<?php
declare(strict_types=1);

$panelRoot = dirname(__DIR__);
define('PANEL_DIR', $panelRoot);
define('APP_ROOT', $panelRoot . '/app');

spl_autoload_register(static function (string $class): void {
    $file = APP_ROOT . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

try {
    $migrated = \Core\TwoFactor::migrateLegacySecrets();
    echo "TOTP secrets migrated: {$migrated}\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'TOTP secret migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
