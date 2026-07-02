<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__, 2) . '/panel-app/app/Core/TwoFactor.php');
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: TwoFactor source unavailable.\n");
    exit(1);
}

function methodSlice(string $source, string $name, string $next): string
{
    $start = strpos($source, "public static function {$name}");
    $end = strpos($source, "public static function {$next}", $start === false ? 0 : $start);
    if ($start === false || $end === false) {
        fwrite(STDERR, "FAIL: could not locate {$name}.\n");
        exit(1);
    }
    return substr($source, $start, $end - $start);
}

$totp = methodSlice($source, 'verifyTotp', 'generateRecoveryCodes');
$recovery = methodSlice($source, 'verifyRecoveryCode', 'remainingRecoveryCodes');

if (!str_contains($totp, 'immediateTransaction')) {
    fwrite(STDERR, "FAIL: TOTP replay check and consumption are not atomic.\n");
    exit(1);
}
if (!str_contains($recovery, 'immediateTransaction')) {
    fwrite(STDERR, "FAIL: recovery-code lookup and consumption are not atomic.\n");
    exit(1);
}

echo "two-factor atomicity contract passed\n";
