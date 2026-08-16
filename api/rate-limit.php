<?php
declare(strict_types=1);

const ATLAS_RENTALS_RATE_LIMIT_STATE = '/home/548005.cloudwaysapps.com/ezgshksprf/private_html/atlas-rentals/rate-limits';

function atlasRentalsEnforceRateLimit(
    string $clientAddress,
    ?string $directory = null,
    int $limit = 10,
    int $windowSeconds = 600,
    ?Closure $clock = null,
): int {
    if ($limit < 1 || $windowSeconds < 1) throw new InvalidArgumentException('Rate limit configuration invalid.');
    if (filter_var($clientAddress, FILTER_VALIDATE_IP) === false) $clientAddress = 'unknown';
    $directory ??= atlasRentalsPrivateStateDirectory('ATLAS_RENTALS_RATE_LIMIT_STATE_PATH', ATLAS_RENTALS_RATE_LIMIT_STATE);
    if (!is_dir($directory) || !is_readable($directory) || !is_writable($directory)) throw new RuntimeException('Rate limit state unavailable.');
    $time = $clock ? $clock() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if (!$time instanceof DateTimeInterface) throw new RuntimeException('Rate limit clock invalid.');
    $now = $time->getTimestamp();
    $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $clientAddress) . '.json';
    $lock = @fopen($path . '.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Rate limit state unavailable.');
    try {
        $state = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        if (!is_array($state) || !is_int($state['windowStartedAt'] ?? null) || !is_int($state['count'] ?? null) || $now >= $state['windowStartedAt'] + $windowSeconds) {
            $state = ['version' => 1, 'windowStartedAt' => $now, 'count' => 0];
        }
        if ($state['count'] >= $limit) return max(1, $state['windowStartedAt'] + $windowSeconds - $now);
        $state['count']++;
        atlasRentalsWritePrivateJson($directory, $path, $state);
        return 0;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
