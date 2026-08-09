<?php
declare(strict_types=1);

require_once __DIR__ . '/integration-runtime.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$reference = is_string($_GET['reference'] ?? null) ? $_GET['reference'] : '';
$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$config = atlasRentalsIntegrationConfig();
$state = atlasRentalsReadDeliveryState((string)$config['state_path'], $reference);
$path = atlasRentalsResolveDownloadPdf($state, $reference, $token, (string)$config['pdf_path']);
if ($path === null) {
    http_response_code(404);
    exit;
}

$length = filesize($path);
if ($length === false) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $reference . '-quotation.pdf"');
header('Content-Length: ' . $length);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
