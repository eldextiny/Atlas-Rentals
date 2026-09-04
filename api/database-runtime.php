<?php
declare(strict_types=1);

require_once __DIR__ . '/private-path.php';

function atlasRentalsDatabase(): PDO
{
    $loaderPath = atlasRentalsPrivatePath('ATLAS_RENTALS_DB_CONFIG', 'atlas-rentals-db.php', 'file');
    if (!is_readable($loaderPath)) throw new RuntimeException('Configuration unavailable.');
    $config = require $loaderPath;
    $required = ['host', 'port', 'database', 'username', 'password', 'charset'];
    if (!is_array($config) || array_diff($required, array_keys($config))) throw new RuntimeException('Configuration invalid.');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], $config['port'], $config['database'], $config['charset']);
    return new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
