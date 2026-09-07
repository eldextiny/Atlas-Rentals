<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/integration-runtime.php';

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function receiver_accepts_rentals_fixture(array $payload): bool
{
    return ($payload['sourceModule'] ?? null) === 'Atlas Rental'
        && ($payload['documentType'] ?? null) === 'Laptop Rental Quotation'
        && preg_match('/^ARQ-\d{4}-\d{6}$/D', (string)($payload['documentReference'] ?? '')) === 1
        && is_array($payload['client'] ?? null) && trim((string)($payload['client']['organisation'] ?? '')) !== ''
        && trim((string)($payload['client']['contactPerson'] ?? '')) !== ''
        && filter_var($payload['client']['email'] ?? '', FILTER_VALIDATE_EMAIL) !== false
        && is_array($payload['commercial'] ?? null)
        && is_int($payload['commercial']['subtotalNgn'] ?? null)
        && ($payload['commercial']['currency'] ?? null) === 'NGN'
        && is_array($payload['documentContext'] ?? null);
}
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atlas-rentals-' . bin2hex(random_bytes(5));
$statePath = $root . DIRECTORY_SEPARATOR . 'state'; $pdfPath = $root . DIRECTORY_SEPARATOR . 'pdf';
mkdir($statePath, 0700, true); mkdir($pdfPath, 0700, true);
$config = ['state_path' => $statePath, 'pdf_path' => $pdfPath, 'crm_source' => 'Atlas Rentals', 'crm_service' => 'Laptop Rental'];
$normalized = ['location' => 'Lagos', 'startDate' => '2026-08-05', 'endDate' => '2026-08-07', 'ratePlan' => 'daily', 'standardQuantity' => 3, 'performanceQuantity' => 2, 'technicianRequired' => true, 'technicianQuantity' => 1, 'technicianDays' => 2, 'fullName' => 'Ada User', 'organization' => 'Example Ltd', 'email' => 'ada@example.com', 'phone' => '+2348028557479'];
$pricing = atlasRentalsCalculatePricing($normalized, 3);
$crmNormalized = $normalized; $crmNormalized['standardQuantity'] = 5; $crmNormalized['performanceQuantity'] = 0; $crmNormalized['technicianDays'] = 3;
$crmPricing = atlasRentalsCalculatePricing($crmNormalized, 3);
$preview = ['journeyId' => '0123456789abcdef0123456789abcdef', 'normalized' => $crmNormalized, 'canonical' => json_encode($crmNormalized), 'pricing' => $crmPricing];
$record = ['enquiry_reference' => 'ARQ-2026-000001', 'normalized_payload' => json_encode($normalized), 'pricing_snapshot' => json_encode($pricing), 'created_at' => '2026-08-05 12:00:00', 'rental_days' => 3, 'standard_quantity' => 3, 'performance_quantity' => 2, 'technician_required' => 1, 'technician_quantity' => 1, 'technician_days' => 2, 'standard_daily_rate' => 10000, 'performance_daily_rate' => 15000, 'delivery_fee' => 40000, 'technician_daily_rate' => 35000, 'subtotal' => 290000, 'vat_amount' => 21750, 'estimated_total' => 311750, 'email' => 'ada@example.com', 'full_name' => 'Ada User', 'organization' => 'Example Ltd', 'location' => 'Lagos', 'start_date' => '2026-08-05', 'end_date' => '2026-08-07'];

$tests = [];
$tests['CRM payload exactly matches the deployed receiver contract'] = function () use ($preview, $config): void {
    $payload = atlasRentalsCrmPayload($preview, 'ARQ-2026-000001', $config);
    check(receiver_accepts_rentals_fixture($payload), 'receiver-compatible fixture rejected emitted payload');
    check($payload === [
        'sourceModule' => 'Atlas Rental', 'documentType' => 'Laptop Rental Quotation',
        'documentReference' => 'ARQ-2026-000001',
        'client' => ['organisation' => 'Example Ltd', 'contactPerson' => 'Ada User', 'email' => 'ada@example.com', 'phone' => '+2348028557479'],
        'title' => 'Laptop Rental Quotation', 'category' => 'Standard Business Laptop',
        'serviceMode' => 'Daily Rate', 'venue' => 'Lagos', 'participants' => 5, 'durationValue' => 3, 'durationUnit' => 'days',
        'workingLanguages' => [],
        'commercial' => ['subtotalNgn' => 295000, 'vatNgn' => 22125, 'grandTotalNgn' => 317125, 'currency' => 'NGN'],
        'pricingStatus' => 'Estimated', 'documentStatus' => 'Enquiry Received',
        'documentContext' => ['contractVersion' => '1', 'startDate' => '2026-08-05', 'endDate' => '2026-08-07', 'rentalDays' => 3, 'laptopCategory' => 'Standard Business Laptop', 'laptopQuantity' => 5, 'ratePlan' => 'daily', 'dailyLaptopRate' => 10000, 'laptopAmount' => 150000, 'technicianRequired' => true, 'technicianQuantity' => 1, 'technicianDays' => 3, 'technicianDailyRate' => 35000, 'technicianAmount' => 105000, 'deliveryFee' => 40000],
    ], 'CRM payload mapping changed');
    foreach (['crm', 'document', 'usdEquivalent', 'journeyId', 'enquiryReference', 'eventTitle', 'eventType'] as $obsolete) check(!array_key_exists($obsolete, $payload), "obsolete CRM field {$obsolete} returned");
    foreach (['subtotalNgn', 'vatNgn', 'grandTotalNgn', 'currency'] as $field) check(!array_key_exists($field, $payload), "commercial field {$field} escaped its root commercial object");
};
$tests['CRM payload maps the performance category and optional technician state exactly'] = function () use ($normalized, $config): void {
    $candidate = $normalized;
    $candidate['standardQuantity'] = 0; $candidate['performanceQuantity'] = 5;
    $candidate['technicianRequired'] = false; $candidate['technicianQuantity'] = 0; $candidate['technicianDays'] = 0;
    $pricing = atlasRentalsCalculatePricing($candidate, 3);
    $payload = atlasRentalsCrmPayload(['normalized' => $candidate, 'pricing' => $pricing], 'ARQ-2026-000052', $config);
    check($payload['documentReference'] === 'ARQ-2026-000052', 'allocated reference was normalized or regenerated');
    check($payload['category'] === 'High Performance Laptop' && $payload['documentContext']['laptopCategory'] === 'High Performance Laptop', 'performance category mapping changed');
    check($payload['documentContext']['dailyLaptopRate'] === 15000 && $payload['documentContext']['laptopAmount'] === 225000, 'performance pricing mapping changed');
    check($payload['documentContext']['technicianRequired'] === false && $payload['documentContext']['technicianQuantity'] === 0
        && $payload['documentContext']['technicianDays'] === 0 && $payload['documentContext']['technicianDailyRate'] === 35000
        && $payload['documentContext']['technicianAmount'] === 0, 'optional technician mapping changed');
    check($payload['commercial'] === ['subtotalNgn' => 265000, 'vatNgn' => 19875, 'grandTotalNgn' => 284875, 'currency' => 'NGN'], 'performance commercial mapping changed');
    check(!array_key_exists('usdEquivalent', $payload['commercial']), 'non-null USD equivalent entered the NGN-only contract');
};
$tests['CRM payload fails closed for missing malformed or inconsistent authoritative values'] = function () use ($preview, $config): void {
    $cases = [];
    $missingReference = $preview; $cases[] = [$missingReference, null];
    $lowercaseReference = $preview; $cases[] = [$lowercaseReference, 'arq-2026-000001'];
    $badEmail = $preview; $badEmail['normalized']['email'] = 'invalid'; $cases[] = [$badEmail, 'ARQ-2026-000001'];
    $mixed = $preview; $mixed['normalized']['performanceQuantity'] = 2; $cases[] = [$mixed, 'ARQ-2026-000001'];
    $mismatch = $preview; $mismatch['pricing']['estimatedTotal']++; $cases[] = [$mismatch, 'ARQ-2026-000001'];
    $floatMoney = $preview; $floatMoney['pricing']['subtotal'] = (float)$floatMoney['pricing']['subtotal']; $cases[] = [$floatMoney, 'ARQ-2026-000001'];
    $stringMoney = $preview; $stringMoney['pricing']['vatAmount'] = (string)$stringMoney['pricing']['vatAmount']; $cases[] = [$stringMoney, 'ARQ-2026-000001'];
    $badPlan = $preview; $badPlan['normalized']['ratePlan'] = 'hourly'; $cases[] = [$badPlan, 'ARQ-2026-000001'];
    foreach ($cases as [$candidate, $reference]) {
        try { atlasRentalsCrmPayload($candidate, $reference, $config); }
        catch (UnexpectedValueException) { continue; }
        throw new RuntimeException('Malformed authoritative CRM input was accepted.');
    }
};
$tests['CRM transport reports only application-owned diagnostics and an explicit execution boundary'] = function () use ($preview, $config): void {
    $payload = atlasRentalsCrmPayload($preview, 'ARQ-2026-000010', $config);
    $transportConfig = $config + ['crm_endpoint' => 'https://crm.example.test/review', 'crm_token' => 'test-token'];
    $calls = 0;
    $clientFailure = atlasRentalsPostCrm($payload, $transportConfig, function () use (&$calls): array {
        $calls++;
        return ['body' => json_encode(['message' => 'Ada User ada@example.com token=top-secret'], JSON_THROW_ON_ERROR), 'status' => 422, 'curlErrorNumber' => 0];
    }, true);
    check($clientFailure['ok'] === false && $clientFailure['code'] === 'CRM_REQUEST_FAILED', 'CRM 4xx result changed');
    check($calls === 1 && $clientFailure['attempted'] === true && $clientFailure['httpStatus'] === 422 && $clientFailure['curlErrorNumber'] === 0 && $clientFailure['errorCategory'] === 'http_4xx', 'CRM 4xx execution diagnostics missing');
    check(array_keys($clientFailure) === ['ok', 'code', 'retryable', 'attempted', 'httpStatus', 'curlErrorNumber', 'errorCategory'], 'CRM failure returned non-application-owned diagnostics');
    $serverFailure = atlasRentalsPostCrm($payload, $transportConfig, static fn(): array => ['body' => '<html>private receiver content</html>', 'status' => 503, 'curlErrorNumber' => 0], true);
    check($serverFailure['attempted'] === true && $serverFailure['httpStatus'] === 503 && $serverFailure['errorCategory'] === 'http_5xx', 'CRM 5xx diagnostics missing');
    $transportFailure = atlasRentalsPostCrm($payload, $transportConfig, static fn(): array => ['body' => false, 'status' => 0, 'curlErrorNumber' => 28], true);
    check($transportFailure['attempted'] === true && $transportFailure['httpStatus'] === 0 && $transportFailure['curlErrorNumber'] === 28 && $transportFailure['errorCategory'] === 'transport', 'CRM transport diagnostics missing');
    $malformed = atlasRentalsPostCrm($payload, $transportConfig, static fn(): array => ['body' => '{malformed', 'status' => 200, 'curlErrorNumber' => 0], true);
    check($malformed['attempted'] === true && $malformed['errorCategory'] === 'invalid_response', 'malformed successful CRM response was accepted');
    $empty = atlasRentalsPostCrm($payload, $transportConfig, static fn(): array => ['body' => '', 'status' => 500, 'curlErrorNumber' => 0], true);
    check($empty['attempted'] === true && $empty['httpStatus'] === 500 && $empty['errorCategory'] === 'http_5xx', 'empty CRM failure response was misclassified');
    $thrown = atlasRentalsPostCrm($payload, $transportConfig, static function (): never { throw new RuntimeException('post-execution failure with private data'); }, true);
    check($thrown['attempted'] === true && $thrown['errorCategory'] === 'transport' && !str_contains(json_encode($thrown, JSON_THROW_ON_ERROR), 'private data'), 'post-boundary exception lost safe attempt evidence');
    $notConfigured = atlasRentalsPostCrm($payload, $config, static function () use (&$calls): array { $calls++; return []; }, true);
    check($notConfigured['attempted'] === false && $notConfigured['errorCategory'] === 'configuration' && $calls === 1, 'configuration failure crossed the HTTP boundary');
    $runtimeUnavailable = atlasRentalsPostCrm($payload, $transportConfig, static function () use (&$calls): array { $calls++; return []; }, false);
    check($runtimeUnavailable['attempted'] === false && $runtimeUnavailable['errorCategory'] === 'runtime' && $calls === 1, 'missing cURL crossed the HTTP boundary');
    $success = atlasRentalsPostCrm($payload, $transportConfig, static fn(): array => ['body' => '{"ok":true}', 'status' => 200, 'curlErrorNumber' => 0], true);
    check($success === atlasRentalsSafeResult(true, 'CRM_ACCEPTED', false, ['attempted' => true]), 'successful CRM result changed');
};
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
        check(str_contains($message['html'], 'Billable working days') && str_contains($message['text'], 'Billable working days:'), 'working-days label missing from email');
        check(!str_contains($message['html'], 'Inclusive duration') && !str_contains($message['text'], 'inclusive day(s)'), 'obsolete inclusive-days label remains in email');
        check(str_contains($message['html'], 'valid for 30 days') && str_contains($message['text'], 'valid for 30 days'), '30-day validity missing from email');
        check(!str_contains($message['html'], '<script>') && str_contains($message['html'], '&lt;script&gt;'), 'user HTML was not escaped');
        check(str_contains($message['text'], 'ESTIMATED TOTAL') || str_contains($message['text'], 'Estimated total'), 'plain-text alternative missing totals');
    }
    check(str_contains($client['html'], 'Dear Ada') && str_contains($client['html'], 'Thank you'), 'client acknowledgement purpose missing');
    check(str_contains($admin['html'], 'operational and commercial review') && str_contains($admin['html'], 'Customer and contact'), 'admin review purpose missing');
    check($client['html'] !== $admin['html'] && $client['text'] !== $admin['text'], 'recipient emails are not distinct');
};
$tests['client WhatsApp CTA uses private configuration and omits safely'] = function () use ($record): void {
    $digits = implode('', array_fill(0, 12, '9'));
    $configured = '+' . substr($digits, 0, 3) . ' (' . substr($digits, 3, 3) . ') ' . substr($digits, 6);
    $client = atlasRentalsBuildEmail($record, 'client', ['whatsapp_number' => $configured]);
    $admin = atlasRentalsBuildEmail($record, 'admin', ['whatsapp_number' => $configured]);
    $message = 'Hello DY-PLUS, I’m following up on laptop rental enquiry ARQ-2026-000001.';
    $url = 'https://wa.me/' . $digits . '?text=' . rawurlencode($message);
    check(str_contains($client['html'], 'Chat with us on WhatsApp') && str_contains($client['html'], atlasRentalsHtml($url)), 'configured client WhatsApp CTA missing');
    check(str_contains($client['text'], $url), 'plain-text WhatsApp fallback missing');
    check(preg_match('#https://wa\.me/(\d+)\?text=#', $client['text'], $match) === 1 && $match[1] === $digits, 'WhatsApp destination was not normalized to digits');
    check(str_contains($client['text'], rawurlencode('ARQ-2026-000001')), 'authoritative enquiry reference was not encoded');
    check(!str_contains($admin['html'], 'Chat with us on WhatsApp') && !str_contains($admin['text'], 'wa.me/'), 'administrator received customer WhatsApp CTA');
    foreach ([[], ['whatsapp_number' => 'invalid']] as $missing) {
        $without = atlasRentalsBuildEmail($record, 'client', $missing);
        check(!str_contains($without['html'], 'Chat with us on WhatsApp') && !str_contains($without['text'], 'wa.me/'), 'missing WhatsApp configuration did not omit CTA');
        check(str_contains($without['html'], 'Laptop Rental Quotation') && str_contains($without['text'], 'Estimated total'), 'missing WhatsApp configuration broke email rendering');
    }
};
$tests['WhatsApp environment configuration takes precedence over private configuration'] = function () use ($root): void {
    $privateDigits = implode('', array_fill(0, 12, '8')); $environmentDigits = implode('', array_fill(0, 12, '9'));
    $privatePath = $root . DIRECTORY_SEPARATOR . 'integrations.php';
    file_put_contents($privatePath, '<?php return ' . var_export(['whatsapp_number' => $privateDigits], true) . ';');
    putenv('ATLAS_RENTALS_INTEGRATIONS_CONFIG=' . $privatePath); putenv('ATLAS_RENTALS_DELIVERY_STATE_PATH=' . $root); putenv('ATLAS_RENTALS_PDF_PATH=' . $root); putenv('ATLAS_RENTALS_WHATSAPP_NUMBER');
    check(atlasRentalsIntegrationConfig()['whatsapp_number'] === $privateDigits, 'private WhatsApp fallback was not loaded');
    putenv('ATLAS_RENTALS_WHATSAPP_NUMBER=' . $environmentDigits);
    check(atlasRentalsIntegrationConfig()['whatsapp_number'] === $environmentDigits, 'WhatsApp environment variable did not take precedence');
    putenv('ATLAS_RENTALS_WHATSAPP_NUMBER'); putenv('ATLAS_RENTALS_INTEGRATIONS_CONFIG'); putenv('ATLAS_RENTALS_DELIVERY_STATE_PATH'); putenv('ATLAS_RENTALS_PDF_PATH'); unlink($privatePath);
};
$tests['standard rental service wording replaces compulsory service'] = function () use ($record): void {
    $client = atlasRentalsBuildEmail($record, 'client'); $admin = atlasRentalsBuildEmail($record, 'admin'); $pdf = atlasRentalsRenderQuotationPdf($record);
    foreach ([$client['html'], $client['text'], $admin['html'], $admin['text'], $pdf] as $presentation) {
        check(str_contains($presentation, 'Standard rental service'), 'standard rental service wording missing');
        check(!str_contains($presentation, 'Compulsory service'), 'obsolete compulsory service wording remains');
    }
};
$tests['historical best snapshot remains authoritative for email and PDF and fails closed at the v1 CRM boundary'] = function () use ($record, $config): void {
    $tiered = $record; $payload = json_decode($tiered['normalized_payload'], true, 32, JSON_THROW_ON_ERROR);
    $payload['ratePlan'] = 'best'; $payload['standardQuantity'] = 6; $payload['performanceQuantity'] = 0; $payload['startDate'] = '2026-08-01'; $payload['endDate'] = '2026-09-09'; $payload['technicianDays'] = 40;
    $standard = atlasRentalsHistoricalTieredUnitPrice(40, ['dailyRate' => 10000, 'weeklyRate' => 59500, 'monthlyRate' => 185000]) + ['quantity' => 6];
    $performance = atlasRentalsHistoricalTieredUnitPrice(40, ['dailyRate' => 15000, 'weeklyRate' => 89500, 'monthlyRate' => 225500]) + ['quantity' => 0];
    $standard['equipmentAmount'] = 6 * $standard['perUnitRental']; $performance['equipmentAmount'] = 0;
    $subtotal = $standard['equipmentAmount'] + 40000 + (40 * 35000); $vat = (int)round($subtotal * .075);
    $pricing = ['currency' => 'NGN', 'ratePlan' => 'best', 'ratePlanLabel' => 'Best Available Rate', 'rentalDays' => 40,
        'duration' => ['totalDays' => 40, 'months' => 1, 'weeks' => 1, 'days' => 3], 'durationLabel' => '1 month + 1 week + 3 days',
        'standard' => $standard, 'performance' => $performance, 'equipmentAmount' => $standard['equipmentAmount'],
        'deliveryFee' => 40000, 'technicianQuantity' => 1, 'technicianDailyRate' => 35000, 'technicianAmount' => 1400000,
        'vatRate' => .075, 'subtotal' => $subtotal, 'vatAmount' => $vat, 'estimatedTotal' => $subtotal + $vat];
    $tiered['normalized_payload'] = json_encode($payload, JSON_THROW_ON_ERROR); $tiered['pricing_snapshot'] = json_encode($pricing, JSON_THROW_ON_ERROR);
    $tiered['rental_days'] = 40; $tiered['standard_quantity'] = 6; $tiered['performance_quantity'] = 0; $tiered['technician_days'] = 40;
    $tiered['subtotal'] = $pricing['subtotal']; $tiered['vat_amount'] = $pricing['vatAmount']; $tiered['estimated_total'] = $pricing['estimatedTotal'];
    $email = atlasRentalsBuildEmail($tiered, 'client'); $pdf = atlasRentalsRenderQuotationPdf($tiered);
    check(str_contains($email['html'], '1 month + 1 week + 3 days') && str_contains($email['text'], '185,000.00') && str_contains($email['text'], '59,500.00'), 'email tier presentation mismatch');
    check(str_contains($email['html'], 'Best Available Rate') && str_contains($email['text'], 'Rental rate plan: Best Available Rate'), 'email rate plan missing');
    check(str_contains($pdf, 'Best Available Rate') && str_contains($pdf, '1 month + 1 week + 3 days') && str_contains($pdf, 'month at NGN 185,000.00') && str_contains($pdf, 'week at NGN 59,500.00') && str_contains($pdf, 'unit NGN 274,500.00'), 'PDF tier presentation mismatch');
    $preview = ['journeyId' => '0123456789abcdef0123456789abcdef', 'normalized' => $payload, 'pricing' => $pricing];
    try { atlasRentalsCrmPayload($preview, 'ARQ-2026-000001', $config); }
    catch (UnexpectedValueException) { return; }
    throw new RuntimeException('historical best pricing entered the daily-only CRM v1 contract');
};
$tests['historical tier helper fails cleanly when persisted rate keys are missing'] = function (): void {
    set_error_handler(static function (int $severity, string $message): never { throw new ErrorException($message, 0, $severity); });
    try {
        atlasRentalsHistoricalTieredUnitPrice(40, ATLAS_RENTALS_PRICING['standard']);
    } catch (InvalidArgumentException $error) {
        check($error->getMessage() === 'Historical tier pricing requires complete persisted rate values.', 'missing historical rates returned the wrong failure');
        restore_error_handler();
        return;
    } catch (Throwable $error) {
        restore_error_handler();
        throw $error;
    }
    restore_error_handler();
    throw new RuntimeException('incomplete historical rates were accepted');
};
$tests['historical weekly and monthly snapshots retain saved presentation and fail closed at the CRM v1 boundary'] = function () use ($record, $config): void {
    foreach ([
        ['weekly', 'Weekly Rate - 7 days', 14, ['totalDays' => 14, 'months' => 0, 'weeks' => 2, 'days' => 0]],
        ['monthly', 'Monthly Rate - 30 days', 60, ['totalDays' => 60, 'months' => 2, 'weeks' => 0, 'days' => 0]],
    ] as [$plan, $label, $days, $duration]) {
        $item = $record;
        $payload = json_decode($item['normalized_payload'], true, 32, JSON_THROW_ON_ERROR);
        $payload['ratePlan'] = $plan; $payload['standardQuantity'] = 5; $payload['performanceQuantity'] = 0;
        $standard = atlasRentalsHistoricalTieredUnitPrice($days, ['dailyRate' => 10000, 'weeklyRate' => 59500, 'monthlyRate' => 185000]) + ['quantity' => 5];
        $performance = atlasRentalsHistoricalTieredUnitPrice($days, ['dailyRate' => 15000, 'weeklyRate' => 89500, 'monthlyRate' => 225500]) + ['quantity' => 0];
        $standard['equipmentAmount'] = 5 * $standard['perUnitRental']; $performance['equipmentAmount'] = 0;
        $subtotal = $standard['equipmentAmount'] + 40000 + 70000; $vat = (int)round($subtotal * .075);
        $pricing = ['currency' => 'NGN', 'ratePlan' => $plan, 'ratePlanLabel' => $label, 'rentalDays' => $days,
            'duration' => $duration, 'durationLabel' => atlasRentalsDurationLabel($duration), 'standard' => $standard, 'performance' => $performance,
            'equipmentAmount' => $standard['equipmentAmount'], 'deliveryFee' => 40000, 'technicianQuantity' => 1, 'technicianDailyRate' => 35000, 'technicianAmount' => 70000,
            'vatRate' => .075, 'subtotal' => $subtotal, 'vatAmount' => $vat, 'estimatedTotal' => $subtotal + $vat];
        $item['normalized_payload'] = json_encode($payload, JSON_THROW_ON_ERROR); $item['pricing_snapshot'] = json_encode($pricing, JSON_THROW_ON_ERROR);
        $item['rental_days'] = $days; $item['standard_quantity'] = 5; $item['performance_quantity'] = 0;
        $item['subtotal'] = $subtotal; $item['vat_amount'] = $vat; $item['estimated_total'] = $subtotal + $vat;
        $email = atlasRentalsBuildEmail($item, 'client'); $pdf = atlasRentalsRenderQuotationPdf($item);
        check(str_contains($email['html'], $label) && str_contains($email['text'], number_format($standard['perUnitRental'], 2)), "historical {$plan} email changed");
        check(str_contains($pdf, $label) && str_contains($pdf, 'unit NGN ' . number_format($standard['perUnitRental'], 2)), "historical {$plan} PDF changed");
        $preview = ['journeyId' => '0123456789abcdef0123456789abcdef', 'normalized' => $payload, 'pricing' => $pricing];
        $rejected = false;
        try { atlasRentalsCrmPayload($preview, 'ARQ-2026-000001', $config); }
        catch (UnexpectedValueException) { $rejected = true; }
        check($rejected, "CRM accepted historical {$plan} pricing into the daily-only v1 contract");
    }
};
$tests['daily rate propagates through snapshot CRM email and PDF'] = function () use ($record, $config): void {
    foreach ([['daily', 6, 'Daily Rate']] as [$plan, $days, $label]) {
        $item = $record; $normalized = json_decode($item['normalized_payload'], true, 32, JSON_THROW_ON_ERROR);
        $normalized['ratePlan'] = $plan; $normalized['standardQuantity'] = 5; $normalized['performanceQuantity'] = 0; $normalized['technicianDays'] = $days; $normalized['startDate'] = '2026-01-01'; $normalized['endDate'] = (new DateTimeImmutable('2026-01-01'))->modify('+' . ($days - 1) . ' days')->format('Y-m-d');
        $pricing = atlasRentalsCalculatePricing($normalized, $days); $item['normalized_payload'] = json_encode($normalized, JSON_THROW_ON_ERROR); $item['pricing_snapshot'] = json_encode($pricing, JSON_THROW_ON_ERROR); $item['rental_days'] = $days;
        $item['subtotal'] = $pricing['subtotal']; $item['vat_amount'] = $pricing['vatAmount']; $item['estimated_total'] = $pricing['estimatedTotal'];
        $preview = ['journeyId' => '0123456789abcdef0123456789abcdef', 'normalized' => $normalized, 'pricing' => $pricing];
        $crm = atlasRentalsCrmPayload($preview, 'ARQ-2026-000001', $config);
        check($crm['documentContext']['ratePlan'] === 'daily' && $crm['commercial']['grandTotalNgn'] === $pricing['estimatedTotal'], 'daily CRM contract changed');
        check(str_contains(atlasRentalsBuildEmail($item, 'client')['html'], $label), "email missing {$plan}");
        check(str_contains(atlasRentalsRenderQuotationPdf($item), $label), "PDF missing {$plan}");
    }
};
$tests['legacy stored snapshots retain their original daily calculation'] = function () use ($record): void {
    $legacy = $record;
    unset($legacy['technician_quantity']);
    $normalized = json_decode($legacy['normalized_payload'], true, 32, JSON_THROW_ON_ERROR); unset($normalized['ratePlan'], $normalized['technicianQuantity']); $legacy['normalized_payload'] = json_encode($normalized, JSON_THROW_ON_ERROR);
    $legacy['pricing_snapshot'] = json_encode(['standardDailyRate' => 10000, 'performanceDailyRate' => 15000, 'deliveryFee' => 40000, 'technicianDailyRate' => 35000, 'vatRate' => .075, 'subtotal' => 290000, 'vatAmount' => 21750, 'estimatedTotal' => 311750], JSON_THROW_ON_ERROR);
    $model = atlasRentalsEmailModel($legacy);
    check($model['legacyPricing'] === true && $model['standardPerUnit'] === '₦30,000.00' && $model['total'] === '₦311,750.00', 'historical pricing was recalculated');
    check($model['technicianQuantity'] === 1, 'historical technician support did not retain one-technician meaning');
    $message = atlasRentalsBuildEmail($legacy, 'client');
    check(!str_contains($message['html'], 'Weekly') && str_contains($message['html'], 'Daily'), 'legacy quotation was presented as tiered pricing');
    check(str_contains($message['html'], 'Historical stored pricing'), 'historical rate-plan compatibility label missing');
};
$tests['historical tiered snapshots without a rate plan remain authoritative'] = function () use ($record): void {
    $historical = $record; $snapshot = json_decode($historical['pricing_snapshot'], true, 32, JSON_THROW_ON_ERROR);
    unset($snapshot['ratePlan'], $snapshot['ratePlanLabel']); $snapshot['standard']['perUnitRental'] = 123456; $snapshot['standard']['equipmentAmount'] = 370368;
    $historical['pricing_snapshot'] = json_encode($snapshot, JSON_THROW_ON_ERROR);
    $normalized = json_decode($historical['normalized_payload'], true, 32, JSON_THROW_ON_ERROR); unset($normalized['ratePlan']); $historical['normalized_payload'] = json_encode($normalized, JSON_THROW_ON_ERROR);
    $model = atlasRentalsEmailModel($historical);
    check($model['ratePlanLabel'] === 'Historical stored pricing' && $model['standardPerUnit'] === '₦123,456.00', 'historical tiered snapshot was repriced');
};
$tests['optional technician presentation and approved rate are preserved'] = function () use ($record): void {
    $wrapped = atlasRentalsPdfWrap("Unit rate: NGN 35,000.00\nPer technician\nPer working day", 29);
    check($wrapped === ['Unit rate: NGN 35,000.00', 'Per technician', 'Per working day'], 'explicit PDF calculation lines were not preserved');
    $with = atlasRentalsBuildEmail($record, 'client');
    check(str_contains($with['html'], '1 technician') && str_contains($with['html'], '₦35,000.00'), 'singular technician presentation missing');
    $singlePdf = atlasRentalsRenderQuotationPdf($record);
    foreach (['Technician - 1 technician', 'Unit rate: NGN 35,000.00', 'Per technician', 'Per working day', 'Quantity: 1 technician', 'Billable days: 2 working days', 'NGN 70,000.00'] as $text) {
        check(str_contains($singlePdf, $text), "single-technician PDF missing {$text}");
    }
    $multiple = $record; $multiple['technician_quantity'] = 3;
    $payload = json_decode($multiple['normalized_payload'], true, 32, JSON_THROW_ON_ERROR); $payload['technicianQuantity'] = 3; $multiple['normalized_payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
    $snapshot = json_decode($multiple['pricing_snapshot'], true, 32, JSON_THROW_ON_ERROR);
    $snapshot['technicianQuantity'] = 3; $snapshot['technicianAmount'] = 210000; $snapshot['subtotal'] = 430000; $snapshot['vatAmount'] = 32250; $snapshot['estimatedTotal'] = 462250;
    $multiple['pricing_snapshot'] = json_encode($snapshot, JSON_THROW_ON_ERROR);
    $multiple['subtotal'] = 430000; $multiple['vat_amount'] = 32250; $multiple['estimated_total'] = 462250;
    check(str_contains(atlasRentalsBuildEmail($multiple, 'admin')['text'], '3 technicians × 2 working days'), 'plural technician email presentation missing');
    $multiplePdf = atlasRentalsRenderQuotationPdf($multiple);
    foreach (['Technician - 3 technicians', 'Unit rate: NGN 35,000.00', 'Per technician', 'Per working day', 'Quantity: 3 technicians', 'Billable days: 2 working days', 'NGN 210,000.00'] as $text) {
        check(str_contains($multiplePdf, $text), "multiple-technician PDF missing {$text}");
    }
    $without = $record; $without['technician_required'] = 0; $without['technician_quantity'] = 0; $without['technician_days'] = 0;
    $payload = json_decode($without['normalized_payload'], true, 32, JSON_THROW_ON_ERROR); $payload['technicianRequired'] = false; $payload['technicianQuantity'] = 0; $payload['technicianDays'] = 0;
    $without['normalized_payload'] = json_encode($payload, JSON_THROW_ON_ERROR);
    $message = atlasRentalsBuildEmail($without, 'client');
    check(!str_contains($message['html'], '>Technician<') && !str_contains($message['text'], 'Technician:'), 'unselected technician was presented');
};
$tests['CRM document synchronization is deduplicated and changed content updates'] = function () use ($preview, $config): void {
    $calls = 0; $captured = null; $poster = function ($payload) use (&$calls, &$captured): array { $calls++; $captured = $payload; return atlasRentalsSafeResult(true, 'CRM_ACCEPTED'); };
    atlasRentalsSyncCrm($preview, 'ARQ-2026-000001', $config, $poster); atlasRentalsSyncCrm($preview, 'ARQ-2026-000001', $config, $poster);
    check($calls === 1, 'unchanged CRM delivery duplicated');
    check($captured['sourceModule'] === 'Atlas Rental' && $captured['documentType'] === 'Laptop Rental Quotation'
        && $captured['documentReference'] === 'ARQ-2026-000001', 'CRM did not receive the allocated document identity');
    $sameEnquiryNewJourney = $preview; $sameEnquiryNewJourney['journeyId'] = 'fedcba9876543210fedcba9876543210';
    atlasRentalsSyncCrm($sameEnquiryNewJourney, 'ARQ-2026-000001', $config, $poster);
    check($calls === 1, 'completed reference was duplicated by a new browser journey');
    $changed = $preview; $changed['canonical'] .= 'changed'; atlasRentalsSyncCrm($changed, 'ARQ-2026-000001', $config, $poster);
    check($calls === 2, 'changed CRM document did not update');
};
$tests['CRM requires an allocated reference and failed delivery remains pending until one safe retry succeeds'] = function () use ($preview, $config): void {
    try { atlasRentalsSyncCrm($preview, null, $config, static fn(): array => atlasRentalsSafeResult(true, 'CRM_ACCEPTED')); }
    catch (UnexpectedValueException) { $missingRejected = true; }
    check($missingRejected ?? false, 'CRM accepted a review without an allocated reference');
    $calls = 0; $poster = function (array $payload) use (&$calls): array {
        $calls++;
        check($payload['documentReference'] === 'ARQ-2026-000004', 'CRM retry changed the allocated reference');
        check(!isset($payload['crm'], $payload['document']), 'CRM retry restored an obsolete payload envelope');
        return $calls === 1
            ? atlasRentalsSafeResult(false, 'CRM_REQUEST_FAILED', true, ['attempted' => true, 'httpStatus' => 503, 'curlErrorNumber' => 0, 'errorCategory' => 'http_5xx'])
            : atlasRentalsSafeResult(true, 'CRM_ACCEPTED', false, ['attempted' => true]);
    };
    $failed = atlasRentalsSyncCrm($preview, 'ARQ-2026-000004', $config, $poster);
    check($failed['crm']['status'] === 'pending' && $failed['crm']['code'] === 'CRM_REQUEST_FAILED', 'failed CRM delivery was not retained as pending');
    check($failed['crm']['attemptCount'] === 1 && preg_match('/^\d{4}-\d{2}-\d{2}T/', $failed['crm']['attemptedAt']) === 1, 'failed CRM attempt metadata missing');
    check($failed['crm']['httpStatus'] === 503 && $failed['crm']['curlErrorNumber'] === 0 && $failed['crm']['errorCategory'] === 'http_5xx', 'CRM failure diagnostics were not persisted');
    $completed = atlasRentalsSyncCrm($preview, 'ARQ-2026-000004', $config, $poster);
    $duplicate = atlasRentalsSyncCrm($preview, 'ARQ-2026-000004', $config, $poster);
    check($completed['crm']['status'] === 'completed' && $duplicate['crm']['status'] === 'completed', 'successful CRM retry did not remain completed');
    check($calls === 2, 'completed CRM delivery was duplicated');
    foreach (['attemptCount', 'attemptedAt', 'httpStatus', 'curlErrorNumber', 'errorCategory'] as $diagnostic) {
        check(!array_key_exists($diagnostic, $completed['crm']), "successful CRM state retained {$diagnostic}");
    }
};
$tests['CRM state compatibility and attempt counting follow the HTTP boundary'] = function () use ($preview, $config, $statePath): void {
    $seed = static function (string $reference, array $state) use ($statePath): void {
        file_put_contents(atlasRentalsStateFile($statePath, 'CRM-' . $reference), json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    };
    $fingerprint = static fn(string $reference): string => hash('sha256', $preview['canonical'] . '|' . $reference);
    $calls = 0;
    $completedReference = 'ARQ-2026-000011';
    $seed($completedReference, ['crm' => ['fingerprint' => $fingerprint($completedReference), 'status' => 'completed', 'code' => 'CRM_ACCEPTED']]);
    $completed = atlasRentalsSyncCrm($preview, $completedReference, $config, function () use (&$calls): array { $calls++; return atlasRentalsSafeResult(false, 'CRM_REQUEST_FAILED'); });
    check($calls === 0 && $completed['crm'] === ['fingerprint' => $fingerprint($completedReference), 'status' => 'completed', 'code' => 'CRM_ACCEPTED'], 'legacy completed CRM state was not preserved');

    $pendingReference = 'ARQ-2026-000012';
    $seed($pendingReference, ['crm' => ['fingerprint' => $fingerprint($pendingReference), 'status' => 'pending', 'code' => 'CRM_REQUEST_FAILED']]);
    $configuration = atlasRentalsSyncCrm($preview, $pendingReference, $config, static fn(): array => atlasRentalsSafeResult(false, 'CRM_CONFIG_MISSING', true, ['attempted' => false, 'httpStatus' => 0, 'curlErrorNumber' => 0, 'errorCategory' => 'configuration']));
    check($configuration['crm']['attemptCount'] === 0 && !isset($configuration['crm']['attemptedAt']), 'configuration failure incremented a legacy pending state');

    $runtime = atlasRentalsSyncCrm($preview, $pendingReference, $config, static fn(): array => atlasRentalsSafeResult(false, 'CRM_HTTP_UNAVAILABLE', true, ['attempted' => false, 'httpStatus' => 0, 'curlErrorNumber' => 0, 'errorCategory' => 'runtime']));
    check($runtime['crm']['attemptCount'] === 0 && !isset($runtime['crm']['attemptedAt']), 'runtime failure incremented without an HTTP attempt');

    $attempted = atlasRentalsSyncCrm($preview, $pendingReference, $config, static fn(): array => atlasRentalsSafeResult(false, 'CRM_REQUEST_FAILED', true, ['attempted' => true, 'httpStatus' => 400, 'curlErrorNumber' => 0, 'errorCategory' => 'http_4xx']));
    check($attempted['crm']['attemptCount'] === 1 && isset($attempted['crm']['attemptedAt']), 'HTTP failure did not increment exactly once');

    $recovered = atlasRentalsSyncCrm($preview, $pendingReference, $config, static fn(): array => atlasRentalsSafeResult(true, 'CRM_ACCEPTED', false, ['attempted' => true]));
    check($recovered['crm']['status'] === 'completed', 'legacy pending CRM state did not recover');
    foreach (['attemptCount', 'attemptedAt', 'httpStatus', 'curlErrorNumber', 'errorCategory'] as $diagnostic) check(!array_key_exists($diagnostic, $recovered['crm']), "successful retry retained {$diagnostic}");

    $noCrmReference = 'ARQ-2026-000013';
    $seed($noCrmReference, ['legacy' => ['status' => 'present']]);
    $noCrm = atlasRentalsSyncCrm($preview, $noCrmReference, $config, static fn(): array => atlasRentalsSafeResult(false, 'CRM_REQUEST_FAILED', true, ['attempted' => true, 'httpStatus' => 500, 'curlErrorNumber' => 0, 'errorCategory' => 'http_5xx']));
    check($noCrm['legacy']['status'] === 'present' && $noCrm['crm']['attemptCount'] === 1, 'state without a CRM section was not preserved and initialized');
};
$tests['PDF contains required quotation content'] = function () use ($record, $pdfPath): void {
    check(is_file(ATLAS_RENTALS_PDF_LOGO_PATH), 'approved DY-PLUS logo asset is missing');
    $logo = atlasRentalsPdfLoadRgbaPng(ATLAS_RENTALS_PDF_LOGO_PATH);
    check($logo['width'] === 200 && $logo['height'] === 129, 'approved logo dimensions changed');
    $pdf = atlasRentalsGeneratePdf($record, $pdfPath); $bytes = file_get_contents($pdf['path']);
    check(str_starts_with($bytes, '%PDF-1.4') && str_ends_with($bytes, '%%EOF'), 'PDF structure invalid');
    check(str_contains($bytes, '/Subtype /Image') && str_contains($bytes, '/Width 200 /Height 129') && str_contains($bytes, '/SMask'), 'approved logo was not embedded with transparency');
    foreach (['DY-PLUS', 'ATLAS Rentals', 'Laptop Rental Quotation', 'ARQ-2026-000001', '04 September 2026', 'Ada User', 'Billable working days', 'Standard Business Laptop', 'High Performance Laptop', 'Technician', 'NGN 35,000.00', 'Delivery & retrieval', 'Standard rental service', 'ESTIMATED TOTAL', 'NGN 311,750.00', 'valid for 30 days', 'subject to equipment availability', 'does not confirm availability', 'Page 1'] as $text) check(str_contains($bytes, $text), "PDF missing {$text}");
    check(!str_contains($bytes, 'Compulsory service'), 'PDF retained obsolete service wording');
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
    $tieredPresentation = substr(hash('sha256', $record['normalized_payload'] . '|' . $record['pricing_snapshot'] . '|rentals-quotation-v5-tiered-rates'), 0, 16);
    $previousRatePlanPresentation = substr(hash('sha256', $record['normalized_payload'] . '|' . $record['pricing_snapshot'] . '|rentals-quotation-v6-rate-plan'), 0, 16);
    $previousTechnicianPresentation = substr(hash('sha256', $record['normalized_payload'] . '|' . $record['pricing_snapshot'] . '|rentals-quotation-v9-technician-quantity'), 0, 16);
    $previousTechnicianRatePresentation = substr(hash('sha256', $record['normalized_payload'] . '|' . $record['pricing_snapshot'] . '|rentals-quotation-v10-technician-unit-rate'), 0, 16);
    $pdf = atlasRentalsGeneratePdf($record, $pdfPath);
    check($pdf['fingerprint'] !== $old, 'presentation version did not rotate PDF fingerprint');
    check($pdf['fingerprint'] !== $previousPresentation, 'logo renderer reused the previous presentation fingerprint');
    check($pdf['fingerprint'] !== $logoPresentation, '30-day renderer reused the previous presentation fingerprint');
    check($pdf['fingerprint'] !== $tieredPresentation, 'rate-plan renderer reused the tiered-only presentation fingerprint');
    check($pdf['fingerprint'] !== $previousRatePlanPresentation, 'standard-service renderer reused the previous presentation fingerprint');
    check($pdf['fingerprint'] !== $previousTechnicianPresentation, 'technician unit-rate renderer reused the previous presentation fingerprint');
    check($pdf['fingerprint'] !== $previousTechnicianRatePresentation, 'line-break renderer reused the previous technician-rate fingerprint');
    check($pdf['fingerprint'] === substr(hash('sha256', $record['normalized_payload'] . '|' . $record['pricing_snapshot'] . '|' . ATLAS_RENTALS_PDF_PRESENTATION_VERSION), 0, 16), 'PDF fingerprint is not presentation-version bound');
};
$tests['PDF capability is stable authorized confined and side-effect free'] = function () use ($record, $preview, $config, $statePath, $pdfPath): void {
    $record['enquiry_reference'] = 'ARQ-2026-000003';
    $calls = ['client' => 0, 'admin' => 0];
    $adapters = ['crm' => fn() => [], 'pdf' => fn($item, $path) => atlasRentalsGeneratePdf($item, $path),
        'email' => function ($item, $pdf, $audience) use (&$calls): array { $calls[$audience]++; return atlasRentalsSafeResult(true, 'EMAIL_DELIVERED'); }];
    $first = atlasRentalsDeliver($record, $preview, $config, $adapters);
    $capability = atlasRentalsPdfCapability($first, $record['enquiry_reference']);
    check($capability['status'] === 'available' && str_starts_with((string)$capability['downloadUrl'], '/api/download-quotation.php?reference=ARQ-2026-000003&token='), 'available capability URL invalid');
    check(!str_contains(json_encode($capability, JSON_THROW_ON_ERROR), $pdfPath) && !str_contains(json_encode($capability, JSON_THROW_ON_ERROR), $statePath), 'private path leaked');
    parse_str((string)parse_url((string)$capability['downloadUrl'], PHP_URL_QUERY), $query);
    $stateFile = atlasRentalsStateFile($statePath, $record['enquiry_reference']); $before = hash_file('sha256', $stateFile);
    $resolved = atlasRentalsResolveDownloadPdf($first, $record['enquiry_reference'], (string)$query['token'], $pdfPath);
    check(is_string($resolved) && str_starts_with((string)file_get_contents($resolved), '%PDF-'), 'authorized PDF did not resolve');
    check(atlasRentalsResolveDownloadPdf($first, $record['enquiry_reference'], str_repeat('0', 64), $pdfPath) === null, 'wrong token resolved');
    check(atlasRentalsResolveDownloadPdf($first, '../ARQ-2026-000003', (string)$query['token'], $pdfPath) === null, 'traversal reference resolved');
    check(atlasRentalsResolveDownloadPdf($first, 'ARQ-2026-000003', 'short', $pdfPath) === null, 'malformed token resolved');
    check(hash_file('sha256', $stateFile) === $before, 'download resolution changed delivery state');
    $second = atlasRentalsDeliver($record, $preview, $config, $adapters);
    check(atlasRentalsPdfCapability($second, $record['enquiry_reference']) === $capability, 'duplicate submission changed download capability');
    check($calls === ['client' => 1, 'admin' => 1], 'duplicate submission repeated recipient delivery');
    check(atlasRentalsPdfCapability([], $record['enquiry_reference']) === ['status' => 'pending', 'downloadUrl' => null], 'pending capability invalid');
    check(atlasRentalsPdfCapability(['pdf' => ['status' => 'failed']], $record['enquiry_reference']) === ['status' => 'failed', 'downloadUrl' => null], 'failed capability invalid');
};
$tests['partial email failure resumes without duplicating completed client'] = function () use ($record, $preview, $config, $pdfPath): void {
    $calls = ['client' => 0, 'admin' => 0]; $failAdmin = true;
    $adapters = ['crm' => fn() => [], 'pdf' => fn($record, $path) => atlasRentalsGeneratePdf($record, $path),
        'email' => function ($record, $pdf, $audience) use (&$calls, &$failAdmin): array { $calls[$audience]++; return $audience === 'admin' && $failAdmin ? atlasRentalsSafeResult(false, 'EMAIL_DELIVERY_FAILED', true) : atlasRentalsSafeResult(true, 'EMAIL_DELIVERED'); }];
    atlasRentalsDeliver($record, $preview, $config, $adapters); $failAdmin = false; $state = atlasRentalsDeliver($record, $preview, $config, $adapters);
    check($calls['client'] === 1 && $calls['admin'] === 2, 'recipient retry behavior incorrect');
    check($state['clientEmail']['status'] === 'completed' && $state['adminEmail']['status'] === 'completed', 'delivery did not recover');
    check(atlasRentalsPdfCapability($state, $record['enquiry_reference'])['status'] === 'available', 'PDF availability incorrectly depended on recipient completion');
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
