<?php
declare(strict_types=1);

// Contract only. The default real loader is ../private_html/atlas-rentals-db.php
// relative to this application's public_html. Keep it outside public_html and Git.
return [
    'host' => 'database-host',
    'port' => 3306,
    'database' => 'database-name',
    'username' => 'database-user',
    'password' => 'database-password',
    'charset' => 'utf8mb4',
];
