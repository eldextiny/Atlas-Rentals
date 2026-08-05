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
$tests['CRM review is deduplicated and changed content updates'] = function () use ($preview, $config): void {
    $calls = 0; $poster = function () use (&$calls): array { $calls++; return atlasRentalsSafeResult(true, 'CRM_ACCEPTED'); };
    atlasRentalsSyncCrm($preview, null, $config, $poster); atlasRentalsSyncCrm($preview, null, $config, $poster);
    check($calls === 1, 'unchanged CRM review duplicated');
    $changed = $preview; $changed['canonical'] .= 'changed'; atlasRentalsSyncCrm($changed, null, $config, $poster);
    check($calls === 2, 'changed CRM review did not update');
};
$tests['PDF contains required quotation content'] = function () use ($record, $pdfPath): void {
    $pdf = atlasRentalsGeneratePdf($record, $pdfPath); $bytes = file_get_contents($pdf['path']);
    foreach (['ARQ-2026-000001', 'Ada User', 'VAT (7.5%)', 'ESTIMATED TOTAL', 'subject to availability', 'does not confirm availability'] as $text) check(str_contains($bytes, $text), "PDF missing {$text}");
};
$tests['partial email failure resumes without duplicating completed client'] = function () use ($record, $preview, $config, $pdfPath): void {
    $calls = ['client' => 0, 'admin' => 0]; $failAdmin = true;
    $adapters = ['crm' => fn() => [], 'pdf' => fn($record, $path) => atlasRentalsGeneratePdf($record, $path),
        'email' => function ($record, $pdf, $audience) use (&$calls, &$failAdmin): array { $calls[$audience]++; return $audience === 'admin' && $failAdmin ? atlasRentalsSafeResult(false, 'EMAIL_DELIVERY_FAILED', true) : atlasRentalsSafeResult(true, 'EMAIL_DELIVERED'); }];
    atlasRentalsDeliver($record, $preview, $config, $adapters); $failAdmin = false; $state = atlasRentalsDeliver($record, $preview, $config, $adapters);
    check($calls['client'] === 1 && $calls['admin'] === 2, 'recipient retry behavior incorrect');
    check($state['clientEmail']['status'] === 'completed' && $state['adminEmail']['status'] === 'completed', 'delivery did not recover');
};

$failed = 0; foreach ($tests as $name => $test) { try { $test(); echo "PASS {$name}\n"; } catch (Throwable $error) { $failed++; fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n"); } }
foreach (glob($statePath . DIRECTORY_SEPARATOR . '*') ?: [] as $file) unlink($file);
foreach (glob($pdfPath . DIRECTORY_SEPARATOR . '*') ?: [] as $file) unlink($file);
rmdir($statePath); rmdir($pdfPath); rmdir($root);
echo sprintf("%d passed, %d failed\n", count($tests) - $failed, $failed); exit($failed ? 1 : 0);
