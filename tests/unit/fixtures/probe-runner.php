<?php
// Runs the real SystemProcessProbe against the real `ps`. It lives in its own process because the unit-test
// bootstrap overrides exec() for the Teknasyon\Crond namespace, which would answer in place of ps in-process.
// Prints "1" or "0".
require __DIR__ . '/../../../vendor/autoload.php';

$probe = new \Teknasyon\Crond\SystemProcessProbe();
$mode = $argv[1] ?? '';

switch ($mode) {
    case 'process':
        $result = $probe->isProcessRunning($argv[2] ?? '', $argv[3] ?? '');
        break;
    case 'command':
        $result = $probe->isCommandRunning($argv[2] ?? '');
        break;
    case 'usable':
        $result = $probe->isUsable();
        break;
    default:
        fwrite(STDERR, 'unknown mode: ' . $mode);
        exit(2);
}

echo $result ? '1' : '0';
