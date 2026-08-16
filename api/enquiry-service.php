<?php
declare(strict_types=1);

final class EnquiryValidationException extends RuntimeException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The enquiry is invalid.');
    }
}

interface EnquiryStore
{
    public function findByHash(string $hash): ?array;
    public function save(array $enquiry, int $referenceYear): array;
}

final class PdoEnquiryStore implements EnquiryStore
{
    public function __construct(private readonly PDO $pdo) {}

    public function findByHash(string $hash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT internal_id, enquiry_reference, status, estimated_total, currency '
            . 'FROM atlas_rental_enquiries WHERE idempotency_hash = :hash LIMIT 1'
        );
        $statement->execute(['hash' => $hash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function save(array $enquiry, int $referenceYear): array
    {
        $this->pdo->beginTransaction();
        try {
            $existing = $this->findByHash($enquiry['idempotency_hash']);
            if ($existing !== null) {
                $this->pdo->commit();
                return $existing;
            }

            $counter = $this->pdo->prepare(
                'INSERT INTO atlas_rental_reference_counters (reference_year, last_sequence) '
                . 'VALUES (:year, 0) ON DUPLICATE KEY UPDATE last_sequence = last_sequence'
            );
            $counter->execute(['year' => $referenceYear]);

            $lock = $this->pdo->prepare(
                'SELECT last_sequence FROM atlas_rental_reference_counters '
                . 'WHERE reference_year = :year FOR UPDATE'
            );
            $lock->execute(['year' => $referenceYear]);
            $sequence = (int) $lock->fetchColumn() + 1;
            if ($sequence > 999999) {
                throw new RuntimeException('Reference capacity exhausted.');
            }

            $update = $this->pdo->prepare(
                'UPDATE atlas_rental_reference_counters SET last_sequence = :sequence '
                . 'WHERE reference_year = :year'
            );
            $update->execute(['sequence' => $sequence, 'year' => $referenceYear]);
            $enquiry['enquiry_reference'] = sprintf('ARQ-%04d-%06d', $referenceYear, $sequence);

            $columns = array_keys($enquiry);
            $insert = $this->pdo->prepare(sprintf(
                'INSERT INTO atlas_rental_enquiries (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns))
            ));
            $insert->execute($enquiry);
            $this->pdo->commit();
            return [
                'internal_id' => $enquiry['internal_id'],
                'enquiry_reference' => $enquiry['enquiry_reference'],
                'status' => $enquiry['status'],
                'estimated_total' => $enquiry['estimated_total'],
                'currency' => $enquiry['currency'],
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $existing = $this->findByHash($enquiry['idempotency_hash']);
            if ($existing !== null) {
                return $existing;
            }
            throw $error;
        }
    }
}

final class EnquiryService
{
    private const STANDARD_RATE = 10000;
    private const PERFORMANCE_RATE = 15000;
    private const DELIVERY_FEE = 40000;
    private const TECHNICIAN_RATE = 35000;
    private const VAT_RATE = 0.075;
    private const ALLOWED_FIELDS = [
        'submissionId', 'location', 'deliveryAddress', 'startDate', 'endDate', 'standardQuantity',
        'performanceQuantity', 'technicianRequired', 'technicianDays', 'fullName',
        'organization', 'email', 'phone', 'description',
    ];
    private const REQUIRED_FIELDS = [
        'submissionId', 'location', 'deliveryAddress', 'startDate', 'endDate', 'standardQuantity',
        'performanceQuantity', 'technicianRequired', 'technicianDays', 'fullName',
        'organization', 'email', 'phone',
    ];

    public function __construct(
        private readonly EnquiryStore $store,
        private readonly ?Closure $clock = null,
        private readonly ?Closure $randomBytes = null,
        private readonly ?Closure $submissionIdentifierCheck = null,
    ) {}

    public function submit(array $input): array
    {
        $normalized = $this->normalizeAndValidate($input);
        if ($this->submissionIdentifierCheck) ($this->submissionIdentifierCheck)($normalized['submissionId']);
        $material = $normalized;
        unset($material['submissionId']);
        $canonical = json_encode($material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $canonical);
        $existing = $this->store->findByHash($hash);
        if ($existing !== null) {
            return $this->result($existing, true);
        }

        $days = $this->rentalDays($normalized['startDate'], $normalized['endDate']);
        $rental = ($normalized['standardQuantity'] * self::STANDARD_RATE
            + $normalized['performanceQuantity'] * self::PERFORMANCE_RATE) * $days;
        $technician = $normalized['technicianDays'] * self::TECHNICIAN_RATE;
        $subtotal = $rental + self::DELIVERY_FEE + $technician;
        $vat = (int) round($subtotal * self::VAT_RATE);
        $pricing = [
            'currency' => 'NGN', 'standardDailyRate' => self::STANDARD_RATE,
            'performanceDailyRate' => self::PERFORMANCE_RATE,
            'deliveryFee' => self::DELIVERY_FEE, 'technicianDailyRate' => self::TECHNICIAN_RATE,
            'vatRate' => self::VAT_RATE, 'subtotal' => $subtotal,
            'vatAmount' => $vat, 'estimatedTotal' => $subtotal + $vat,
        ];
        $bytes = $this->randomBytes ? ($this->randomBytes)(16) : random_bytes(16);
        $record = [
            'internal_id' => bin2hex($bytes), 'idempotency_hash' => $hash, 'status' => 'received',
            'location' => $normalized['location'], 'delivery_address' => $normalized['deliveryAddress'],
            'start_date' => $normalized['startDate'], 'end_date' => $normalized['endDate'],
            'rental_days' => $days, 'standard_quantity' => $normalized['standardQuantity'],
            'performance_quantity' => $normalized['performanceQuantity'],
            'technician_required' => $normalized['technicianRequired'] ? 1 : 0,
            'technician_days' => $normalized['technicianDays'], 'full_name' => $normalized['fullName'],
            'organization' => $normalized['organization'], 'email' => $normalized['email'],
            'phone' => $normalized['phone'], 'description' => $normalized['description'] ?: null,
            'currency' => 'NGN', 'standard_daily_rate' => $this->money(self::STANDARD_RATE),
            'performance_daily_rate' => $this->money(self::PERFORMANCE_RATE),
            'delivery_fee' => $this->money(self::DELIVERY_FEE),
            'technician_daily_rate' => $this->money(self::TECHNICIAN_RATE),
            'subtotal' => $this->money($subtotal), 'vat_rate' => '7.50',
            'vat_amount' => $this->money($vat), 'estimated_total' => $this->money($subtotal + $vat),
            'normalized_payload' => $canonical,
            'pricing_snapshot' => json_encode($pricing, JSON_THROW_ON_ERROR),
        ];
        $now = $this->clock ? ($this->clock)() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $this->result($this->store->save($record, (int) $now->format('Y')), false);
    }

    private function normalizeAndValidate(array $input): array
    {
        $errors = [];
        $unexpected = array_diff(array_keys($input), self::ALLOWED_FIELDS);
        if ($unexpected) $errors['payload'] = 'Unexpected fields are not allowed.';
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $input)) $errors[$field] = 'This field is required.';
        }
        if ($errors) throw new EnquiryValidationException($errors);

        $text = static fn(mixed $value): string => is_string($value)
            ? preg_replace('/\s+/u', ' ', trim($value)) : '';
        $value = [
            'submissionId' => $text($input['submissionId']),
            'location' => $text($input['location']), 'deliveryAddress' => $text($input['deliveryAddress']),
            'startDate' => $text($input['startDate']), 'endDate' => $text($input['endDate']),
            'standardQuantity' => $input['standardQuantity'], 'performanceQuantity' => $input['performanceQuantity'],
            'technicianRequired' => $input['technicianRequired'], 'technicianDays' => $input['technicianDays'],
            'fullName' => $text($input['fullName']), 'organization' => $text($input['organization']),
            'email' => strtolower($text($input['email'])), 'phone' => $text($input['phone']),
            'description' => $text($input['description'] ?? ''),
        ];
        if (preg_match('/^[a-f0-9]{40}$/', $value['submissionId']) !== 1) $errors['submissionId'] = 'Submission identifier is invalid.';
        if (!in_array($value['location'], ['Lagos', 'Abuja'], true)) $errors['location'] = 'Choose Lagos or Abuja.';
        $this->length($value['deliveryAddress'], 5, 500, 'deliveryAddress', $errors);
        $this->length($value['fullName'], 2, 160, 'fullName', $errors);
        $this->length($value['organization'], 2, 200, 'organization', $errors);
        if (strlen($value['description']) > 5000) $errors['description'] = 'Description is too long.';
        if (!filter_var($value['email'], FILTER_VALIDATE_EMAIL) || strlen($value['email']) > 254) $errors['email'] = 'Enter a valid email address.';
        if (!preg_match('/^\+?[0-9][0-9 ()-]{6,38}$/', $value['phone'])) {
            $errors['phone'] = 'Enter a valid phone number.';
        } else {
            $international = str_starts_with($value['phone'], '+');
            $value['phone'] = ($international ? '+' : '') . preg_replace('/\D+/', '', $value['phone']);
        }
        foreach (['standardQuantity', 'performanceQuantity', 'technicianDays'] as $field) {
            if (!is_int($value[$field]) || $value[$field] < 0 || $value[$field] > 10000) $errors[$field] = 'Enter a valid whole number.';
        }
        if (!is_bool($value['technicianRequired'])) $errors['technicianRequired'] = 'Choose whether a technician is required.';
        if (is_int($value['standardQuantity']) && is_int($value['performanceQuantity'])
            && $value['standardQuantity'] + $value['performanceQuantity'] < 5) $errors['quantity'] = 'Select at least 5 laptops.';
        if ($value['technicianRequired'] === true && (!is_int($value['technicianDays']) || $value['technicianDays'] < 1)) $errors['technicianDays'] = 'Enter at least 1 technician day.';
        if ($value['technicianRequired'] === false && $value['technicianDays'] !== 0) $errors['technicianDays'] = 'Technician days must be zero when support is not selected.';
        try { $this->rentalDays($value['startDate'], $value['endDate']); }
        catch (Throwable) { $errors['dates'] = 'Enter valid inclusive rental dates.'; }
        if ($errors) throw new EnquiryValidationException($errors);
        return $value;
    }

    private function rentalDays(string $start, string $end): int
    {
        foreach ([$start, $end] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Invalid date.');
        }
        $from = new DateTimeImmutable($start, new DateTimeZone('UTC'));
        $to = new DateTimeImmutable($end, new DateTimeZone('UTC'));
        if ($to < $from) throw new InvalidArgumentException('Invalid range.');
        return (int) $from->diff($to)->days + 1;
    }

    private function length(string $value, int $min, int $max, string $field, array &$errors): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length < $min || $length > $max) $errors[$field] = 'Enter a valid value.';
    }

    private function money(int $value): string { return number_format($value, 2, '.', ''); }
    private function result(array $row, bool $duplicate): array
    {
        return [
            'reference' => $row['enquiry_reference'], 'status' => $row['status'],
            'currency' => $row['currency'], 'estimatedTotal' => (float) $row['estimated_total'],
            'duplicate' => $duplicate,
        ];
    }
}
