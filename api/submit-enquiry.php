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
    require_once __DIR__ . '/database-runtime.php';
    require_once __DIR__ . '/integration-runtime.php';
    $store = new PdoEnquiryStore(atlasRentalsDatabase());
    $service = new EnquiryService($store);
    $preview = $service->preview($input);
    $result = $service->submit($input);
    $record = $store->findByReference($result['reference']);
    if ($record === null) throw new RuntimeException('Persisted enquiry unavailable.');
    try {
        $delivery = atlasRentalsDeliver($record, $preview, atlasRentalsIntegrationConfig());
        $complete = ($delivery['pdf']['status'] ?? '') === 'completed'
            && ($delivery['clientEmail']['status'] ?? '') === 'completed'
            && ($delivery['adminEmail']['status'] ?? '') === 'completed';
    } catch (Throwable) { $complete = false; }
    $result['deliveryComplete'] = $complete;
    $result['deliveryStatus'] = $complete ? 'complete' : 'pending';
    respond(200, ['ok' => true, 'enquiry' => $result]);
} catch (EnquiryValidationException $error) {
    respond(422, ['ok' => false, 'error' => 'validation_failed', 'fields' => $error->errors]);
} catch (Throwable) {
    respond(503, ['ok' => false, 'error' => 'submission_unavailable']);
}
