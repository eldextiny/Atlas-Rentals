<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/http-request.php';
require_once __DIR__ . '/enquiry-service.php';
require_once __DIR__ . '/database-runtime.php';
require_once __DIR__ . '/integration-runtime.php';
require_once __DIR__ . '/journey-identifier.php';

function reviewRespond(int $status, array $body): never { http_response_code($status); echo json_encode($body); exit; }
try {
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    $input = parse_enquiry_request($_SERVER, $raw === false ? '' : $raw, 16384);
    $service = new EnquiryService(new PdoEnquiryStore(atlasRentalsDatabase()), journeyIdentifierCheck: atlasRentalsJourneyIdentifierCheck());
    $preview = $service->preview($input);
    $state = atlasRentalsSyncCrm($preview, null, atlasRentalsIntegrationConfig());
    $completed = ($state['crm']['status'] ?? '') === 'completed';
    reviewRespond($completed ? 200 : 202, ['ok' => true, 'crm' => $completed ? 'accepted' : 'pending']);
} catch (EnquiryHttpException $error) {
    reviewRespond($error->status, ['ok' => false, 'error' => $error->error]);
} catch (EnquiryValidationException $error) {
    reviewRespond(422, ['ok' => false, 'error' => 'validation_failed', 'fields' => $error->errors]);
} catch (JourneyIdentifierExpiredException) {
    reviewRespond(409, ['ok' => false, 'error' => 'journey_expired']);
} catch (Throwable) {
    reviewRespond(202, ['ok' => true, 'crm' => 'pending']);
}
