<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/http-request.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/enquiry-service.php';
require_once __DIR__ . '/database-runtime.php';
require_once __DIR__ . '/integration-runtime.php';
require_once __DIR__ . '/journey-identifier.php';

function reviewRespond(int $status, array $body): never { http_response_code($status); echo json_encode($body); exit; }
try {
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    $input = parse_enquiry_request($_SERVER, $raw === false ? '' : $raw, 16384);
} catch (EnquiryHttpException $error) {
    reviewRespond($error->status, ['ok' => false, 'error' => $error->error]);
}
try {
    $retryAfter = atlasRentalsEnforceRateLimit(
        clientAddress: (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        limit: 30,
        windowSeconds: 600,
        namespace: 'review',
    );
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
        reviewRespond(429, ['ok' => false, 'error' => 'rate_limited']);
    }
} catch (Throwable) {
    reviewRespond(503, ['ok' => false, 'error' => 'submission_unavailable']);
}
try {
    $service = new EnquiryService(new PdoEnquiryStore(atlasRentalsDatabase()), journeyIdentifierCheck: atlasRentalsJourneyIdentifierCheck());
    $service->preview($input);
    reviewRespond(202, ['ok' => true, 'crm' => 'pending']);
} catch (EnquiryValidationException $error) {
    reviewRespond(422, ['ok' => false, 'error' => 'validation_failed', 'fields' => $error->errors]);
} catch (JourneyIdentifierExpiredException) {
    reviewRespond(409, ['ok' => false, 'error' => 'journey_expired']);
} catch (Throwable) {
    reviewRespond(202, ['ok' => true, 'crm' => 'pending']);
}
