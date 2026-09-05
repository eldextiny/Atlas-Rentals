<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/enquiry-service.php';
require_once __DIR__ . '/../../api/http-request.php';

final class MemoryStore implements EnquiryStore
{
    public array $records = [];
    public ?array $lastSaved = null;
    public int $sequence = 0;
    public bool $fail = false;
    public function findByHash(string $hash): ?array { return $this->records[$hash] ?? null; }
    public function findByReference(string $reference): ?array {
        foreach ($this->records as $record) if ($record['enquiry_reference'] === $reference) return $record;
        return null;
    }
    public function save(array $enquiry, int $year): array
    {
        if ($this->fail) throw new RuntimeException('simulated failure');
        $this->lastSaved = $enquiry;
        $this->sequence++;
        $row = [
            'internal_id' => $enquiry['internal_id'],
            'enquiry_reference' => sprintf('ARQ-%04d-%06d', $year, $this->sequence),
            'status' => 'received', 'estimated_total' => $enquiry['estimated_total'], 'currency' => 'NGN',
        ];
        $this->records[$enquiry['idempotency_hash']] = $row;
        return $row;
    }
}

function valid_payload(): array
{
    return [
        'journeyId' => '0123456789abcdef0123456789abcdef', 'location' => 'Lagos', 'ratePlan' => 'daily',
        'startDate' => '2026-08-05', 'endDate' => '2026-08-07',
        'standardQuantity' => 3, 'performanceQuantity' => 2,
        'technicianRequired' => true, 'technicianQuantity' => 1, 'technicianDays' => 2,
        'fullName' => 'Ada User', 'organization' => 'Example Limited',
        'email' => 'ADA@example.com', 'phoneCountry' => 'NG', 'phone' => '08028557479',
    ];
}

function service(MemoryStore $store, ?Closure $journeyIdentifierCheck = null): EnquiryService
{
    return new EnquiryService(
        $store,
        static fn(): DateTimeImmutable => new DateTimeImmutable('2026-08-05T00:00:00Z'),
        static fn(int $length): string => str_repeat("\x01", $length),
        $journeyIdentifierCheck,
    );
}

function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function expect_validation(array $payload, string $field): void
{
    try { service(new MemoryStore())->submit($payload); }
    catch (EnquiryValidationException $error) {
        expect(isset($error->errors[$field]), "Expected validation error for {$field}");
        return;
    }
    throw new RuntimeException("Expected validation failure for {$field}");
}

$tests = [];
$tests['server pricing, working dates and technician rate'] = function (): void {
    $payload = valid_payload(); $payload['technicianDays'] = 1;
    $result = service($store = new MemoryStore())->submit($payload);
    expect($result['reference'] === 'ARQ-2026-000001', 'reference format mismatch');
    expect($result['estimatedTotal'] === 349375.0, 'server total mismatch');
    $snapshot = json_decode($store->lastSaved['pricing_snapshot'], true, 32, JSON_THROW_ON_ERROR);
    $normalized = json_decode($store->lastSaved['normalized_payload'], true, 32, JSON_THROW_ON_ERROR);
    expect($normalized['technicianDays'] === 3 && $store->lastSaved['technician_days'] === 3, 'authoritative working days were not persisted');
    expect($normalized['technicianQuantity'] === 1 && $store->lastSaved['technician_quantity'] === 1, 'technician quantity was not persisted');
    expect($snapshot['technicianQuantity'] === 1 && $snapshot['technicianDailyRate'] === 35000 && $snapshot['technicianAmount'] === 105000, 'authoritative technician price was not persisted');
};
$tests['multiple technicians multiply quantity and working days'] = function (): void {
    $payload = valid_payload(); $payload['technicianQuantity'] = 3;
    $preview = service(new MemoryStore())->preview($payload);
    expect($preview['pricing']['technicianAmount'] === 315000, 'three-technician amount mismatch');
    expect($preview['pricing']['subtotal'] === 535000 && $preview['pricing']['vatAmount'] === 40125 && $preview['pricing']['estimatedTotal'] === 575125, 'quantity did not update commercial totals');
};
$tests['unselected technician is normalized and persisted as zero'] = function (): void {
    $payload = valid_payload(); $payload['technicianRequired'] = false; $payload['technicianQuantity'] = 0; $payload['technicianDays'] = 0;
    $preview = service($store = new MemoryStore())->preview($payload);
    expect($preview['normalized']['technicianQuantity'] === 0 && $preview['normalized']['technicianDays'] === 0 && $preview['pricing']['technicianAmount'] === 0, 'unselected preview included technician support');
    service($store)->submit($payload);
    expect($store->lastSaved['technician_required'] === 0 && $store->lastSaved['technician_quantity'] === 0 && $store->lastSaved['technician_days'] === 0, 'unselected technician was not persisted as zero');
};
$tests['specified service city uses the existing location contract'] = function (): void {
    $payload = valid_payload(); $payload['location'] = 'Port Harcourt';
    $preview = service(new MemoryStore())->preview($payload);
    expect($preview['normalized']['location'] === 'Port Harcourt', 'custom location was not preserved');
    $payload['location'] = 'X'; expect_validation($payload, 'location');
};
$tests['server calculates daily pricing for working-day durations'] = function (): void {
    $cases = [
        ['2026-08-03', '2026-08-03', 1, 10000, 15000],
        ['2026-08-03', '2026-08-07', 5, 50000, 75000],
        ['2026-08-07', '2026-08-10', 2, 20000, 30000],
        ['2026-08-07', '2026-08-14', 6, 60000, 90000],
    ];
    foreach ($cases as [$start, $end, $days, $standard, $performance]) {
        $payload = valid_payload(); $payload['startDate'] = $start; $payload['endDate'] = $end;
        $payload['standardQuantity'] = 5; $payload['performanceQuantity'] = 0;
        $pricing = service(new MemoryStore())->preview($payload)['pricing']; expect($pricing['rentalDays'] === $days && $pricing['standard']['perUnitRental'] === $standard, "standard {$days}-day mismatch");
        $payload['standardQuantity'] = 0; $payload['performanceQuantity'] = 6;
        $pricing = service(new MemoryStore())->preview($payload)['pricing']; expect($pricing['performance']['perUnitRental'] === $performance, "performance {$days}-day mismatch");
    }
};
$tests['server rejects every unsupported rate plan'] = function (): void {
    foreach (['weekly', 'monthly', 'best', 'unsupported'] as $plan) {
        $payload = valid_payload(); $payload['ratePlan'] = $plan;
        expect_validation($payload, 'ratePlan');
    }
};
$tests['browser commercial fields cannot override server pricing'] = function (): void {
    foreach (['total', 'subtotal', 'vat', 'monthlyRate', 'duration'] as $field) {
        $payload = valid_payload(); $payload[$field] = 1; expect_validation($payload, 'payload');
    }
};
$tests['identical retry returns original reference'] = function (): void {
    $service = service($store = new MemoryStore());
    $first = $service->submit(valid_payload());
    $second = $service->submit(valid_payload());
    expect($first['reference'] === $second['reference'], 'duplicate reference changed');
    expect($store->sequence === 1 && $second['duplicate'] === true, 'duplicate consumed a reference');
};
$tests['current one-technician retry matches a pre-quantity historical enquiry'] = function (): void {
    $store = new MemoryStore(); $service = service($store); $payload = valid_payload();
    $normalized = $service->preview($payload)['normalized'];
    unset($normalized['technicianQuantity']);
    $legacyHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $store->records[$legacyHash] = ['enquiry_reference' => 'ARQ-2026-000099', 'status' => 'received', 'estimated_total' => '349375.00', 'currency' => 'NGN'];
    $result = $service->submit($payload);
    expect($result['reference'] === 'ARQ-2026-000099' && $result['duplicate'] === true, 'current retry did not match historical identity');
    expect($store->sequence === 0, 'historical retry created a duplicate record');
};
$tests['expired journey identifier fails before persistence'] = function (): void {
    $store = new MemoryStore();
    $guard = static function (string $identifier): void { throw new RuntimeException('expired journey'); };
    try { service($store, $guard)->submit(valid_payload()); }
    catch (RuntimeException $error) {
        expect($error->getMessage() === 'expired journey', 'journey error was changed');
        expect($store->sequence === 0 && $store->records === [], 'expired journey reached persistence');
        return;
    }
    throw new RuntimeException('expired journey was accepted');
};
$tests['historical retry without rate plan is lookup-only and never repriced'] = function (): void {
    $store = new MemoryStore(); $service = service($store); $payload = valid_payload();
    $preview = $service->preview($payload); $normalized = $preview['normalized']; $normalized['technicianDays'] = $payload['technicianDays']; unset($normalized['ratePlan']);
    $legacyHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $store->records[$legacyHash] = ['enquiry_reference' => 'ARQ-2025-000123', 'status' => 'received', 'estimated_total' => '999.00', 'currency' => 'NGN'];
    unset($payload['ratePlan']); $result = $service->submit($payload);
    expect($result['reference'] === 'ARQ-2025-000123' && $result['estimatedTotal'] === 999.0 && $store->sequence === 0, 'historical retry was changed or persisted');
    $new = valid_payload(); unset($new['ratePlan']); $new['email'] = 'new@example.com'; expect_validation($new, 'ratePlan');
};
$tests['historical best retry is lookup-only and can never create a new enquiry'] = function (): void {
    $store = new MemoryStore(); $service = service($store); $payload = valid_payload();
    $preview = $service->preview($payload); $normalized = $preview['normalized']; $normalized['technicianDays'] = $payload['technicianDays']; $normalized['ratePlan'] = 'best';
    $historicalHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $store->records[$historicalHash] = ['enquiry_reference' => 'ARQ-2025-000456', 'status' => 'received', 'estimated_total' => '123456.00', 'currency' => 'NGN'];
    $payload['ratePlan'] = 'best'; $result = $service->submit($payload);
    expect($result['reference'] === 'ARQ-2025-000456' && $result['estimatedTotal'] === 123456.0 && $store->sequence === 0, 'historical best retry was repriced or persisted');
    $payload['email'] = 'new-best@example.com'; expect_validation($payload, 'ratePlan');
    expect($store->sequence === 0, 'new best enquiry reached persistence');
};
$tests['material change creates a different reference'] = function (): void {
    $service = service($store = new MemoryStore());
    $first = $service->submit(valid_payload());
    $changed = valid_payload(); $changed['organization'] = 'Different Organisation';
    $second = $service->submit($changed);
    expect($first['reference'] !== $second['reference'] && $store->sequence === 2, 'material change was deduplicated');
};
$tests['technician quantity is canonical material while identical retries remain idempotent'] = function (): void {
    $service = service($store = new MemoryStore());
    $first = $service->submit(valid_payload());
    $same = $service->submit(valid_payload());
    $changedPayload = valid_payload(); $changedPayload['technicianQuantity'] = 2;
    $changed = $service->submit($changedPayload);
    expect($first['reference'] === $same['reference'] && $changed['reference'] !== $first['reference'], 'technician quantity was not canonical commercial material');
    expect($store->sequence === 2, 'technician quantity retry/reference behavior changed');
};
$tests['failed persistence does not consume a reference'] = function (): void {
    $store = new MemoryStore(); $store->fail = true;
    try { service($store)->submit(valid_payload()); } catch (RuntimeException) {}
    expect($store->sequence === 0, 'failed save consumed a reference');
};
$tests['validation rejects identity quantity dates contact and unexpected fields'] = function (): void {
    foreach ([
        ['journeyId', 'bad', 'journeyId'], ['standardQuantity', -1, 'standardQuantity'],
        ['endDate', '2026-08-01', 'dates'], ['email', 'invalid', 'email'],
        ['phone', 'abc', 'phone'], ['extra', 'bad', 'payload'],
    ] as [$key, $value, $field]) { $payload = valid_payload(); $payload[$key] = $value; expect_validation($payload, $field); }
    $payload = valid_payload(); $payload['standardQuantity'] = 2; $payload['performanceQuantity'] = 2; expect_validation($payload, 'quantity');
    $payload = valid_payload(); unset($payload['organization']); expect_validation($payload, 'organization');
    foreach ([0, -1, 1.5, '2', 11] as $quantity) { $payload = valid_payload(); $payload['technicianQuantity'] = $quantity; expect_validation($payload, 'technicianQuantity'); }
    $payload = valid_payload(); $payload['startDate'] = '2026-08-08'; expect_validation($payload, 'dates');
    $payload = valid_payload(); $payload['endDate'] = '2026-08-09'; expect_validation($payload, 'dates');
};
$tests['global phone fixtures normalize to E.164 and reject invalid numbers'] = function (): void {
    $fixtures = json_decode(file_get_contents(__DIR__ . '/../fixtures/phone-numbers.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($fixtures['valid'] as $fixture) {
        $payload = valid_payload(); $payload['phoneCountry'] = $fixture['region']; $payload['phone'] = $fixture['input'];
        $preview = service(new MemoryStore())->preview($payload);
        expect($preview['normalized']['phone'] === $fixture['e164'], "phone normalization mismatch for {$fixture['region']}: {$fixture['input']}");
        expect(!array_key_exists('phoneCountry', $preview['normalized']), 'request-only phone country leaked into normalized contract');
    }
    foreach ($fixtures['invalid'] as $fixture) {
        $payload = valid_payload(); $payload['phoneCountry'] = $fixture['region']; $payload['phone'] = $fixture['input'];
        try { service(new MemoryStore())->submit($payload); }
        catch (EnquiryValidationException $error) {
            expect(($error->errors['phone'] ?? '') === 'Enter a valid phone number for the selected country, or include the full international number beginning with +.', "Expected exact phone validation error for {$fixture['region']}: {$fixture['input']}");
            continue;
        }
        throw new RuntimeException("Expected validation failure for {$fixture['region']}: {$fixture['input']}");
    }
    foreach (['phone', 'phoneCountry'] as $missing) {
        $payload = valid_payload(); unset($payload[$missing]);
        try { service(new MemoryStore())->submit($payload); }
        catch (EnquiryValidationException $error) {
            expect(isset($error->errors['phone']) && !isset($error->errors['phoneCountry']), "Missing {$missing} did not fail through fields.phone");
            continue;
        }
        throw new RuntimeException("Missing {$missing} was accepted");
    }
};
$tests['http parser rejects method content type malformed and oversized bodies'] = function (): void {
    foreach ([
        [['REQUEST_METHOD' => 'GET', 'CONTENT_TYPE' => 'application/json'], '{}', 405],
        [['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'text/plain'], '{}', 415],
        [['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json'], '{', 400],
        [['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json'], str_repeat('x', 20), 413],
    ] as [$server, $body, $status]) {
        try { parse_enquiry_request($server, $body, 16); }
        catch (EnquiryHttpException $error) { expect($error->status === $status, 'wrong HTTP error'); continue; }
        throw new RuntimeException('Expected HTTP rejection');
    }
};

$failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "PASS {$name}\n"; }
    catch (Throwable $error) { $failed++; fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n"); }
}
echo sprintf("%d passed, %d failed\n", count($tests) - $failed, $failed);
exit($failed === 0 ? 0 : 1);
