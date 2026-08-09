<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/integration-runtime.php';

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atlas-rentals-' . bin2hex(random_bytes(5));
$statePath = $root . DIRECTORY_SEPARATOR . 'state'; $pdfPath = $root . DIRECTORY_SEPARATOR . 'pdf';
mkdir($statePath, 0700, true); mkdir($pdfPath, 0700, true);
$config = ['state_path' => $statePath, 'pdf_path' => $pdfPath, 'crm_source' => 'Atlas Rentals', 'crm_service' => 'Laptop Rental'];
$normalized = ['location' => 'Lagos', 'startDate' => '2026-08-05', 'endDate' => '2026-08-07', 'standardQuantity' => 3, 'performanceQuantity' => 2, 'technicianRequired' => true, 'technicianDays' => 2, 'fullName' => 'Ada User', 'organization' => 'Example Ltd', 'email' => 'ada@example.com', 'phone' => '+2348028557479'];
$preview = ['journeyId' => '0123456789abcdef0123456789abcdef', 'normalized' => $normalized, 'canonical' => json_encode($normalized), 'pricing' => ['rentalDays' => 3, 'estimatedTotal' => 311750]];
$pricing = ['standardDailyRate' => 10000, 'performanceDailyRate' => 15000, 'deliveryFee' => 40000, 'technicianDailyRate' => 35000, 'vatRate' => .075, 'subtotal' => 290000, 'vatAmount' => 21750, 'estimatedTotal' => 311750];
$record = ['enquiry_reference' => 'ARQ-2026-000001', 'normalized_payload' => json_encode($normalized), 'pricing_snapshot' => json_encode($pricing), 'created_at' => '2026-08-05 12:00:00', 'rental_days' => 3, 'standard_quantity' => 3, 'performance_quantity' => 2, 'technician_required' => 1, 'technician_days' => 2, 'standard_daily_rate' => 10000, 'performance_daily_rate' => 15000, 'delivery_fee' => 40000, 'technician_daily_rate' => 35000, 'subtotal' => 290000, 'vat_amount' => 21750, 'estimated_total' => 311750, 'email' => 'ada@example.com', 'full_name' => 'Ada User', 'organization' => 'Example Ltd', 'location' => 'Lagos', 'start_date' => '2026-08-05', 'end_date' => '2026-08-07'];

$tests = [];
$tests['client and administrator emails are branded, distinct, escaped, and retain text alternatives'] = function () use ($record): void {
    $unsafe = $record;
    $payload = json_decode($unsafe['normalized_payload'], true, 32, JSON_THROW_ON_ERROR);
    $payload['fullName'] = 'Ada <script>alert("x")</script> & Co';
    $unsafe['normalized_payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
    $client = atlasRentalsBuildEmail($unsafe, 'client'); $admin = atlasRentalsBuildEmail($unsafe, 'admin');
    foreach ([$client, $admin] as $message) {
        check(str_contains($message['html'], 'DY-PLUS') && str_contains($message['html'], 'ATLAS Rentals'), 'email branding missing');
        check(str_contains($message['html'], 'https://laptops.dyplus.com.ng/assets/dyplus-logo.png') && str_contains($message['html'], 'alt="DY-PLUS company logo"'), 'approved email logo missing');
        check(str_contains($message['html'], 'ARQ-2026-000001') && str_contains($message['html'], '₦311,750.00'), 'required email fields missing');
        check(str_contains($message['html'], 'valid for 30 days') && str_contains($message['text'], 'valid for 30 days'), '30-day validity missing from email');
        check(!str_contains($message['html'], '<script>') && str_contains($message['html'], '&lt;script&gt;'), 'user HTML was not escaped');
        check(str_contains($message['text'], 'ESTIMATED TOTAL') || str_contains($message['text'], 'Estimated total'), 'plain-text alternative missing totals');
    }
    check(str_contains($client['html'], 'Dear Ada') && str_contains($client['html'], 'Thank you'), 'client acknowledgement purpose missing');
    check(str_contains($admin['html'], 'operational and commercial review') && str_contains($admin['html'], 'Customer and contact'), 'admin review purpose missing');
    check($client['html'] !== $admin['html'] && $client['text'] !== $admin['text'], 'recipient emails are not distinct');
};
$tests['optional technician presentation and approved rate are preserved'] = function () use ($record): void {
    $with = atlasRentalsBuildEmail($record, 'client');
    check(str_contains($with['html'], 'Technician') && str_contains($with['html'], '₦35,000.00'), 'approved technician presentation missing');
    $without = $record; $without['technician_required'] = 0; $without['technician_days'] = 0;
    $payload = json_decode($without['normalized_payload'], true, 32, JSON_THROW_ON_ERROR); $payload['technicianRequired'] = false; $payload['technicianDays'] = 0;
    $without['normalized_payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
    $message = atlasRentalsBuildEmail($without, 'client');
    check(!str_contains($message['html'], '>Technician<') && !str_contains($message['text'], 'Technician:'), 'unselected technician was presented');
};
$tests['CRM review is deduplicated and changed content updates'] = function () use ($preview, $config): void {
    $calls = 0; $poster = function () use (&$calls): array { $calls++; return atlasRentalsSafeResult(true, 'CRM_ACCEPTED'); };
    atlasRentalsSyncCrm($preview, null, $config, $poster); atlasRentalsSyncCrm($preview, null, $config, $poster);
    check($calls === 1, 'unchanged CRM review duplicated');
    $changed = $preview; $changed['canonical'] .= 'changed'; atlasRentalsSyncCrm($changed, null, $config, $poster);
    check($calls === 2, 'changed CRM review did not update');
};
$tests['PDF contains required quotation content'] = function () use ($record, $pdfPath): void {
    check(is_file(ATLAS_RENTALS_PDF_LOGO_PATH), 'approved DY-PLUS logo asset is missing');
    $logo = atlasRentalsPdfLoadRgbaPng(ATLAS_RENTALS_PDF_LOGO_PATH);
    check($logo['width'] === 200 && $logo['height'] === 129, 'approved logo dimensions changed');
    $pdf = atlasRentalsGeneratePdf($record, $pdfPath); $bytes = file_get_contents($pdf['path']);
    check(str_starts_with($bytes, '%PDF-1.4') && str_ends_with($bytes, '%%EOF'), 'PDF structure invalid');
    check(str_contains($bytes, '/Subtype /Image') && str_contains($bytes, '/Width 200 /Height 129') && str_contains($bytes, '/SMask'), 'approved logo was not embedded with transparency');
    foreach (['DY-PLUS', 'ATLAS Rentals', 'Laptop Rental Quotation', 'ARQ-2026-000001', '04 September 2026', 'Ada User', 'Standard laptops', 'High-performance laptops', 'Technician', 'NGN 35,000.00', 'Delivery & retrieval', 'ESTIMATED TOTAL', 'NGN 311,750.00', 'valid for 30 days', 'subject to equipment availability', 'does not confirm availability', 'Page 1'] as $text) check(str_contains($bytes, $text), "PDF missing {$text}");
    check(str_contains($bytes, 'VAT \\(7.5%\\)'), 'PDF missing VAT (7.5%)');
    $long = $record; $long['enquiry_reference'] = 'ARQ-2026-000099';
    $payload = json_decode($long['normalized_payload'], true, 32, JSON_THROW_ON_ERROR);
    $payload['organization'] = str_repeat('International Equipment and Conference Operations Group ', 90);
    $long['normalized_payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
    $longPdf = atlasRentalsGeneratePdf($long, $pdfPath); $longBytes = file_get_contents($longPdf['path']);
    check(preg_match_all('/\/Type \/Page\b/', $longBytes) >= 2, 'long quotation did not flow safely across pages');
};
$tests['PDF renderer version rotates the cached document fingerprint'] = function () use ($record, $pdfPath): void {
    $old = substr(hash('sha256', $record['normalized_payload'] . '|' . $record['pricing_snapshot']), 0, 16);
    $previousPresentation = substr(hash('sha256', $record['normalized_payload'] . '|' . $record['pricing_snapshot'] . '|rentals-quotation-v2'), 0, 16);
    $logoPresentation = substr(hash('sha256', $record['normalized_payload'] . '|' . $record['pricing_snapshot'] . '|rentals-quotation-v3-logo'), 0, 16);
    $pdf = atlasRentalsGeneratePdf($record, $pdfPath);
    check($pdf['fingerprint'] !== $old, 'presentation version did not rotate PDF fingerprint');
    check($pdf['fingerprint'] !== $previousPresentation, 'logo renderer reused the previous presentation fingerprint');
    check($pdf['fingerprint'] !== $logoPresentation, '30-day renderer reused the previous presentation fingerprint');
    check($pdf['fingerprint'] === substr(hash('sha256', $record['normalized_payload'] . '|' . $record['pricing_snapshot'] . '|' . ATLAS_RENTALS_PDF_PRESENTATION_VERSION), 0, 16), 'PDF fingerprint is not presentation-version bound');
};
$tests['partial email failure resumes without duplicating completed client'] = function () use ($record, $preview, $config, $pdfPath): void {
    $calls = ['client' => 0, 'admin' => 0]; $failAdmin = true;
    $adapters = ['crm' => fn() => [], 'pdf' => fn($record, $path) => atlasRentalsGeneratePdf($record, $path),
        'email' => function ($record, $pdf, $audience) use (&$calls, &$failAdmin): array { $calls[$audience]++; return $audience === 'admin' && $failAdmin ? atlasRentalsSafeResult(false, 'EMAIL_DELIVERY_FAILED', true) : atlasRentalsSafeResult(true, 'EMAIL_DELIVERED'); }];
    atlasRentalsDeliver($record, $preview, $config, $adapters); $failAdmin = false; $state = atlasRentalsDeliver($record, $preview, $config, $adapters);
    check($calls['client'] === 1 && $calls['admin'] === 2, 'recipient retry behavior incorrect');
    check($state['clientEmail']['status'] === 'completed' && $state['adminEmail']['status'] === 'completed', 'delivery did not recover');
};
$tests['completed recipients remain skipped and idempotency keys stay stable'] = function () use ($record, $preview, $config, $pdfPath): void {
    $record['enquiry_reference'] = 'ARQ-2026-000002';
    $calls = ['client' => 0, 'admin' => 0];
    $adapters = ['crm' => fn() => [], 'pdf' => fn($record, $path) => atlasRentalsGeneratePdf($record, $path),
        'email' => function ($record, $pdf, $audience) use (&$calls): array { $calls[$audience]++; return atlasRentalsSafeResult(true, 'EMAIL_DELIVERED'); }];
    atlasRentalsDeliver($record, $preview, $config, $adapters); atlasRentalsDeliver($record, $preview, $config, $adapters);
    check($calls === ['client' => 1, 'admin' => 1], 'completed historical recipients were reopened');
    check(atlasRentalsEmailIdempotencyKey('ARQ-2026-000001', 'client') === 'atlas-rentals-arq-2026-000001-client', 'client idempotency key changed');
    check(atlasRentalsEmailIdempotencyKey('ARQ-2026-000001', 'admin') === 'atlas-rentals-arq-2026-000001-admin', 'admin idempotency key changed');
};

$failed = 0; foreach ($tests as $name => $test) { try { $test(); echo "PASS {$name}\n"; } catch (Throwable $error) { $failed++; fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n"); } }
foreach (glob($statePath . DIRECTORY_SEPARATOR . '*') ?: [] as $file) unlink($file);
foreach (glob($pdfPath . DIRECTORY_SEPARATOR . '*') ?: [] as $file) unlink($file);
rmdir($statePath); rmdir($pdfPath); rmdir($root);
echo sprintf("%d passed, %d failed\n", count($tests) - $failed, $failed); exit($failed ? 1 : 0);
