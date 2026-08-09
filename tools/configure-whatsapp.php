<?php
declare(strict_types=1);

const ATLAS_RENTALS_WHATSAPP_CONFIG = '/home/548005.cloudwaysapps.com/ezgshksprf/private_html/atlas-rentals-integrations.php';

function failWhatsAppConfiguration(): never
{
    fwrite(STDERR, "WHATSAPP_CONFIG_ERROR\n");
    exit(1);
}

function loadWhatsAppConfiguration(string $path): array
{
    ob_start();
    try { $configuration = @include $path; }
    catch (Throwable) { throw new RuntimeException('Invalid configuration.'); }
    finally { ob_end_clean(); }
    if (!is_array($configuration)) throw new RuntimeException('Invalid configuration.');
    return $configuration;
}

if (PHP_SAPI !== 'cli' || count($argv) > 2) failWhatsAppConfiguration();

$temporary = null; $handle = null;
try {
    $target = $argv[1] ?? ATLAS_RENTALS_WHATSAPP_CONFIG;
    if (!is_string($target) || $target === '' || !is_file($target) || is_link($target)
        || !is_readable($target) || !is_writable($target)) throw new RuntimeException('Unavailable target.');
    $directory = dirname($target);
    if (!is_dir($directory) || !is_readable($directory) || !is_writable($directory)) throw new RuntimeException('Unavailable directory.');
    $configuration = loadWhatsAppConfiguration($target);
    fwrite(STDERR, 'WhatsApp destination (international digits only): ');
    $input = fgets(STDIN);
    if ($input === false) throw new RuntimeException('Input unavailable.');
    $number = preg_replace('/\D+/', '', $input) ?? '';
    if (preg_match('/^[1-9]\d{7,14}$/D', $number) !== 1) throw new RuntimeException('Invalid destination.');
    $configuration['whatsapp_number'] = $number;
    $contents = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($configuration, true) . ";\n";
    $temporary = tempnam($directory, basename($target) . '.tmp.');
    if ($temporary === false) throw new RuntimeException('Temporary file unavailable.');
    $handle = @fopen($temporary, 'wb');
    if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('Temporary file unavailable.');
    $offset = 0; $length = strlen($contents);
    while ($offset < $length) {
        $bytes = fwrite($handle, substr($contents, $offset));
        if ($bytes === false || $bytes === 0) throw new RuntimeException('Write failed.');
        $offset += $bytes;
    }
    if (!fflush($handle)) throw new RuntimeException('Write failed.');
    if (function_exists('fsync') && !fsync($handle)) throw new RuntimeException('Write failed.');
    flock($handle, LOCK_UN); fclose($handle); $handle = null;
    @chmod($temporary, 0640);
    $verified = loadWhatsAppConfiguration($temporary);
    if ($verified !== $configuration || !@rename($temporary, $target)) throw new RuntimeException('Atomic replacement failed.');
    $temporary = null;
    @chmod($target, 0640);
} catch (Throwable) {
    if (isset($handle) && is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); }
    if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
    failWhatsAppConfiguration();
}

fwrite(STDOUT, "WHATSAPP_CONFIG_OK\n");
