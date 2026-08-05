<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/http-request.php';

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$maxBytes = 16384;
$raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
try {
    $input = parse_enquiry_request($_SERVER, $raw === false ? '' : $raw, $maxBytes);
} catch (EnquiryHttpException $error) {
    if ($error->status === 405) header('Allow: POST');
    respond($error->status, ['ok' => false, 'error' => $error->error]);
}

try {
    require_once __DIR__ . '/enquiry-service.php';
    $loaderPath = getenv('ATLAS_RENTALS_DB_CONFIG') ?: '/home/548005.cloudwaysapps.com/ezgshksprf/private_html/atlas-rentals-db.php';
    if (!is_file($loaderPath) || !is_readable($loaderPath)) throw new RuntimeException('Configuration unavailable.');
    $config = require $loaderPath;
    $required = ['host', 'port', 'database', 'username', 'password', 'charset'];
    if (!is_array($config) || array_diff($required, array_keys($config))) throw new RuntimeException('Configuration invalid.');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], $config['port'], $config['database'], $config['charset']);
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $result = (new EnquiryService(new PdoEnquiryStore($pdo)))->submit($input);
    respond(200, ['ok' => true, 'enquiry' => $result]);
} catch (EnquiryValidationException $error) {
    respond(422, ['ok' => false, 'error' => 'validation_failed', 'fields' => $error->errors]);
} catch (Throwable) {
    respond(503, ['ok' => false, 'error' => 'submission_unavailable']);
}
