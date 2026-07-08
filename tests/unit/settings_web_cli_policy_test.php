<?php
declare(strict_types=1);

$demo = in_array('--demo', $argv, true);
$root = dirname(__DIR__, 2);
$storage = sys_get_temp_dir() . '/aidipanel-policy-' . getmypid() . ($demo ? '-demo' : '-normal');
@mkdir($storage, 0770, true);
if ($demo) {
    touch($storage . '/demo.flag');
}

define('PANEL_ROOT', $root . '/panel-app');
define('APP_ROOT', PANEL_ROOT . '/app');
define('STORAGE_ROOT', $storage);
define('PUBLIC_ROOT', PANEL_ROOT . '/public');
define('PANEL_VERSION', 'test');
define('PANEL_DIR', '/opt/aidipanel');

require APP_ROOT . '/Core/helpers.php';

$pass = 0;
$fail = function (string $msg): never {
    fwrite(STDERR, "POLICY_FAIL={$msg}\n");
    exit(1);
};
$ok = function (bool $cond, string $msg) use (&$pass, $fail): void {
    if (!$cond) {
        $fail($msg);
    }
    echo "ok: {$msg}\n";
    $pass++;
};

if ($demo) {
    $ok(is_web_cli_invocation_allowed('panel:ssl', ['--action', 'status']), 'demo allows panel ssl status');
    $ok(!is_web_cli_invocation_allowed('panel:ssl', ['--action', 'issue']), 'demo blocks panel ssl issue');
    $ok(!is_web_cli_invocation_allowed('panel:domain', ['--set', 'panel.example.com']), 'demo blocks panel domain set');
    $ok(!is_web_cli_invocation_allowed('panel:domain', ['--action', 'clear']), 'demo blocks panel domain clear');
    $ok(is_web_cli_invocation_allowed('db:server-info', []), 'demo allows db server info');
} else {
    $ok(is_web_cli_invocation_allowed('panel:domain', []), 'allows panel domain default status');
    $ok(is_web_cli_invocation_allowed('panel:domain', ['--action', 'status']), 'allows panel domain explicit status');
    $ok(is_web_cli_invocation_allowed('panel:domain', ['--set', 'panel.example.com']), 'allows panel domain set');
    $ok(is_web_cli_invocation_allowed('panel:domain', ['--action', 'clear']), 'allows panel domain clear');
    $ok(!is_web_cli_invocation_allowed('panel:domain', ['--set', 'bad;id']), 'rejects unsafe panel domain');
    $ok(is_web_cli_invocation_allowed('panel:ssl', ['--action', 'status']), 'allows panel ssl status');
    $ok(is_web_cli_invocation_allowed('panel:ssl', ['--action', 'issue']), 'allows panel ssl issue');
    $ok(is_web_cli_invocation_allowed('panel:ssl', ['--action', 'issue', '--email', 'admin@example.com']), 'allows panel ssl issue email');
    $ok(!is_web_cli_invocation_allowed('panel:ssl', ['--action', 'renew']), 'web blocks panel ssl renew');
    $ok(!is_web_cli_invocation_allowed('panel:ssl', ['--action', 'issue', '--staging']), 'web blocks panel ssl staging');
    $ok(is_web_cli_invocation_allowed('db:server-info', []), 'allows db server info');
    $ok(!is_web_cli_invocation_allowed('db:server-info', ['--json']), 'db server info takes no args');
}

echo 'POLICY_PASS=' . $pass . "\n";
