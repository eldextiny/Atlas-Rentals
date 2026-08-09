<?php
declare(strict_types=1);

function checkConfigure(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

function runConfigure(string $target, string $input): array
{
    $script = realpath(__DIR__ . '/../../tools/configure-whatsapp.php');
    if ($script === false) throw new RuntimeException('Configuration utility unavailable.');
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($target);
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Unable to start configuration utility.');
    fwrite($pipes[0], $input); fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atlas-rentals-configure-' . bin2hex(random_bytes(5));
mkdir($root, 0700, true);
$tests = [];

$tests['utility is CLI-only and uses the approved atomic private target contract'] = function (): void {
    $source = (string)file_get_contents(__DIR__ . '/../../tools/configure-whatsapp.php');
    checkConfigure(str_contains($source, "PHP_SAPI !== 'cli'"), 'CLI-only guard missing');
    checkConfigure(str_contains($source, '/home/548005.cloudwaysapps.com/ezgshksprf/private_html/atlas-rentals-integrations.php'), 'default private target changed');
    foreach (['is_readable($target)', 'is_writable($target)', 'tempnam($directory', 'rename($temporary, $target)', 'chmod($temporary, 0640)', 'WHATSAPP_CONFIG_OK'] as $contract) {
        checkConfigure(str_contains($source, $contract), "utility contract missing {$contract}");
    }
};

$tests['utility preserves configuration and updates only normalized WhatsApp number'] = function () use ($root): void {
    $target = $root . DIRECTORY_SEPARATOR . 'integrations.php';
    $original = ['resend_api_key' => 'unchanged-placeholder', 'nested' => ['enabled' => true, 'retries' => 3], 'whatsapp_number' => 'previous-placeholder'];
    file_put_contents($target, '<?php return ' . var_export($original, true) . ';'); chmod($target, 0640);
    $digits = implode('', array_fill(0, 12, '9')); $formatted = '+' . substr($digits, 0, 3) . ' (' . substr($digits, 3, 3) . ') ' . substr($digits, 6) . "\n";
    $result = runConfigure($target, $formatted); $updated = require $target;
    checkConfigure($result['exit'] === 0 && $result['stdout'] === "WHATSAPP_CONFIG_OK\n", 'success contract changed');
    checkConfigure(!str_contains($result['stdout'] . $result['stderr'], $digits), 'destination was printed');
    checkConfigure($updated['whatsapp_number'] === $digits, 'destination was not normalized');
    unset($updated['whatsapp_number'], $original['whatsapp_number']);
    checkConfigure($updated === $original, 'unrelated configuration changed');
    if (DIRECTORY_SEPARATOR === '/') checkConfigure((fileperms($target) & 0777) === 0640, 'secure mode was not preserved');
};

$tests['utility rejects invalid input without changing the target'] = function () use ($root): void {
    $target = $root . DIRECTORY_SEPARATOR . 'invalid-input.php'; file_put_contents($target, '<?php return ["keep" => "unchanged"];');
    foreach (["0123456789\n", "1234567\n", str_repeat('9', 16) . "\n"] as $input) {
        $before = hash_file('sha256', $target); $result = runConfigure($target, $input);
        checkConfigure($result['exit'] !== 0 && $result['stdout'] === '', 'invalid input did not fail safely');
        checkConfigure(hash_file('sha256', $target) === $before, 'invalid input changed target');
        checkConfigure(!str_contains($result['stderr'], trim($input)), 'invalid input was printed');
    }
};

$tests['utility rejects missing invalid and non-array targets'] = function () use ($root): void {
    $invalid = $root . DIRECTORY_SEPARATOR . 'invalid.php'; file_put_contents($invalid, '<?php return [;');
    $scalar = $root . DIRECTORY_SEPARATOR . 'scalar.php'; file_put_contents($scalar, '<?php return "not-an-array";');
    foreach ([$root . DIRECTORY_SEPARATOR . 'missing.php', $invalid, $scalar] as $target) {
        $result = runConfigure($target, str_repeat('9', 8) . "\n");
        checkConfigure($result['exit'] !== 0 && $result['stdout'] === '', 'unsafe target was accepted');
        checkConfigure(!str_contains($result['stderr'], 'not-an-array'), 'configuration contents were printed');
    }
};

$failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "PASS {$name}\n"; }
    catch (Throwable $error) { $failed++; fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n"); }
}
foreach (glob($root . DIRECTORY_SEPARATOR . '*') ?: [] as $file) unlink($file);
rmdir($root);
echo sprintf("%d passed, %d failed\n", count($tests) - $failed, $failed);
exit($failed ? 1 : 0);
