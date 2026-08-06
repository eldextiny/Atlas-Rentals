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
        'journeyId' => '0123456789abcdef0123456789abcdef', 'location' => 'Lagos',
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
$tests['identical retry returns original reference'] = function (): void {
    $service = service($store = new MemoryStore());
    $first = $service->submit(valid_payload());
    $second = $service->submit(valid_payload());
    expect($first['reference'] === $second['reference'], 'duplicate reference changed');
    expect($store->sequence === 1 && $second['duplicate'] === true, 'duplicate consumed a reference');
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
