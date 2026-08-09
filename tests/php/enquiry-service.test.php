<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/enquiry-service.php';
require_once __DIR__ . '/../../api/http-request.php';

final class MemoryStore implements EnquiryStore
{
    public array $records = [];
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
        'technicianRequired' => true, 'technicianDays' => 2,
        'fullName' => 'Ada User', 'organization' => 'Example Limited',
        'email' => 'ADA@example.com', 'phone' => '08028557479',
    ];
}

function service(MemoryStore $store): EnquiryService
{
    return new EnquiryService(
        $store,
        static fn(): DateTimeImmutable => new DateTimeImmutable('2026-08-05T00:00:00Z'),
        static fn(int $length): string => str_repeat("\x01", $length),
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
$tests['server pricing, inclusive dates and technician rate'] = function (): void {
    $result = service($store = new MemoryStore())->submit(valid_payload());
    expect($result['reference'] === 'ARQ-2026-000001', 'reference format mismatch');
    expect($result['estimatedTotal'] === 311750.0, 'server total mismatch');
};
$tests['specified service city uses the existing location contract'] = function (): void {
    $payload = valid_payload(); $payload['location'] = 'Port Harcourt';
    $preview = service(new MemoryStore())->preview($payload);
    expect($preview['normalized']['location'] === 'Port Harcourt', 'custom location was not preserved');
    $payload['location'] = 'X'; expect_validation($payload, 'location');
};
$tests['server calculates every explicit plan for both categories'] = function (): void {
    $cases = [
        ['daily', 1, 10000, 15000], ['daily', 6, 60000, 90000],
        ['weekly', 7, 59500, 89500], ['weekly', 14, 119000, 179000], ['weekly', 28, 238000, 358000],
        ['monthly', 30, 185000, 225500], ['monthly', 60, 370000, 451000],
    ];
    foreach ($cases as [$plan, $days, $standard, $performance]) {
        $payload = valid_payload(); $payload['ratePlan'] = $plan; $payload['startDate'] = '2026-01-01';
        $payload['endDate'] = (new DateTimeImmutable('2026-01-01'))->modify('+' . ($days - 1) . ' days')->format('Y-m-d');
        $payload['standardQuantity'] = 5; $payload['performanceQuantity'] = 0;
        $pricing = service(new MemoryStore())->preview($payload)['pricing']; expect($pricing['standard']['perUnitRental'] === $standard, "standard {$plan}/{$days} mismatch");
        $payload['standardQuantity'] = 0; $payload['performanceQuantity'] = 6;
        $pricing = service(new MemoryStore())->preview($payload)['pricing']; expect($pricing['performance']['perUnitRental'] === $performance, "performance {$plan}/{$days} mismatch");
    }
};
$tests['server rejects unsupported and incompatible rate plans'] = function (): void {
    foreach ([['weekly', 8], ['weekly', 29], ['weekly', 30], ['weekly', 31], ['monthly', 7], ['monthly', 29], ['monthly', 31], ['monthly', 37], ['best', 30], ['unsupported', 30]] as [$plan, $days]) {
        $payload = valid_payload(); $payload['ratePlan'] = $plan; $payload['startDate'] = '2026-01-01';
        $payload['endDate'] = (new DateTimeImmutable('2026-01-01'))->modify('+' . ($days - 1) . ' days')->format('Y-m-d');
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
$tests['historical retry without rate plan is lookup-only and never repriced'] = function (): void {
    $store = new MemoryStore(); $service = service($store); $payload = valid_payload();
    $preview = $service->preview($payload); $normalized = $preview['normalized']; unset($normalized['ratePlan']);
    $legacyHash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $store->records[$legacyHash] = ['enquiry_reference' => 'ARQ-2025-000123', 'status' => 'received', 'estimated_total' => '999.00', 'currency' => 'NGN'];
    unset($payload['ratePlan']); $result = $service->submit($payload);
    expect($result['reference'] === 'ARQ-2025-000123' && $result['estimatedTotal'] === 999.0 && $store->sequence === 0, 'historical retry was changed or persisted');
    $new = valid_payload(); unset($new['ratePlan']); $new['email'] = 'new@example.com'; expect_validation($new, 'ratePlan');
};
$tests['historical best retry is lookup-only and can never create a new enquiry'] = function (): void {
    $store = new MemoryStore(); $service = service($store); $payload = valid_payload();
    $preview = $service->preview($payload); $normalized = $preview['normalized']; $normalized['ratePlan'] = 'best';
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
    $payload = valid_payload(); $payload['technicianDays'] = 0; expect_validation($payload, 'technicianDays');
};
$tests['phone representations normalize to E.164'] = function (): void {
    foreach (['08028557479', '2348028557479', '+2348028557479'] as $phone) {
        $payload = valid_payload(); $payload['phone'] = $phone;
        $preview = service(new MemoryStore())->preview($payload);
        expect($preview['normalized']['phone'] === '+2348028557479', 'phone normalization mismatch');
    }
    $international = valid_payload(); $international['phone'] = '+442071838750';
    expect(service(new MemoryStore())->preview($international)['normalized']['phone'] === '+442071838750', 'international phone normalization mismatch');

    foreach ([
        'too short' => '0802',
        'alphabetic' => '0802ABC7479',
        'zero country code' => '+00012345678',
        'invalid Nigerian prefix' => '02028557479',
        'bare non-Nigerian digits' => '442071838750',
        'too long' => '+1234567890123456',
    ] as $case => $phone) {
        $payload = valid_payload(); $payload['phone'] = $phone;
        try { service(new MemoryStore())->submit($payload); }
        catch (EnquiryValidationException $error) {
            expect(isset($error->errors['phone']), "Expected phone validation error for {$case}: {$phone}");
            continue;
        }
        throw new RuntimeException("Expected validation failure for {$case}: {$phone}");
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
