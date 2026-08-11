<?php
declare(strict_types=1);

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($composerAutoload)) {
    throw new RuntimeException('Application dependencies are unavailable.');
}
require_once $composerAutoload;
require_once __DIR__ . '/rentals-pricing.php';

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
    public function findByReference(string $reference): ?array;
    public function save(array $enquiry, int $referenceYear): array;
}

final class PdoEnquiryStore implements EnquiryStore
{
    public function __construct(private readonly PDO $pdo) {}

    public function findByHash(string $hash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * '
            . 'FROM atlas_rental_enquiries WHERE idempotency_hash = :hash LIMIT 1'
        );
        $statement->execute(['hash' => $hash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findByReference(string $reference): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM atlas_rental_enquiries WHERE enquiry_reference = :reference LIMIT 1');
        $statement->execute(['reference' => $reference]);
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
    private const ALLOWED_FIELDS = [
        'journeyId', 'location', 'startDate', 'endDate', 'ratePlan', 'standardQuantity',
        'performanceQuantity', 'technicianRequired', 'technicianDays', 'fullName',
        'organization', 'email', 'phoneCountry', 'phone',
    ];
    private const REQUIRED_FIELDS = [
        'journeyId', 'location', 'startDate', 'endDate', 'ratePlan', 'standardQuantity',
        'performanceQuantity', 'technicianRequired', 'technicianDays', 'fullName',
        'organization', 'email', 'phoneCountry', 'phone',
    ];

    public function __construct(
        private readonly EnquiryStore $store,
        private readonly ?Closure $clock = null,
        private readonly ?Closure $randomBytes = null,
        private readonly ?Closure $journeyIdentifierCheck = null,
    ) {}

    public function submit(array $input): array
    {
        if (!array_key_exists('ratePlan', $input)) {
            $legacyInput = $input; $legacyInput['ratePlan'] = 'daily';
            $legacyPreview = $this->preview($legacyInput); $legacyNormalized = $legacyPreview['normalized'];
            unset($legacyNormalized['ratePlan']);
            $legacyCanonical = json_encode($legacyNormalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $historical = $this->store->findByHash(hash('sha256', $legacyCanonical));
            if ($historical !== null) return $this->result($historical, true);
            throw new EnquiryValidationException(['ratePlan' => 'Select a rental rate plan.']);
        }
        if ($input['ratePlan'] === 'best') {
            $historicalInput = $input; $historicalInput['ratePlan'] = 'daily';
            $historicalPreview = $this->preview($historicalInput);
            $historicalNormalized = $historicalPreview['normalized'];
            $historicalNormalized['ratePlan'] = 'best';
            $historicalCanonical = json_encode($historicalNormalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $historical = $this->store->findByHash(hash('sha256', $historicalCanonical));
            if ($historical !== null) return $this->result($historical, true);
            throw new EnquiryValidationException(['ratePlan' => 'Select a rental rate plan.']);
        }
        $preview = $this->preview($input);
        $normalized = $preview['normalized'];
        $canonical = $preview['canonical'];
        $hash = $preview['hash'];
        $existing = $this->store->findByHash($hash);
        if ($existing !== null) {
            return $this->result($existing, true);
        }

        $days = $this->rentalDays($normalized['startDate'], $normalized['endDate']);
        $pricing = atlasRentalsCalculatePricing($normalized, $days);
        $subtotal = $pricing['subtotal']; $vat = $pricing['vatAmount'];
        $bytes = $this->randomBytes ? ($this->randomBytes)(16) : random_bytes(16);
        $record = [
            'internal_id' => bin2hex($bytes), 'idempotency_hash' => $hash, 'status' => 'received',
            'location' => $normalized['location'], 'delivery_address' => null,
            'start_date' => $normalized['startDate'], 'end_date' => $normalized['endDate'],
            'rental_days' => $days, 'standard_quantity' => $normalized['standardQuantity'],
            'performance_quantity' => $normalized['performanceQuantity'],
            'technician_required' => $normalized['technicianRequired'] ? 1 : 0,
            'technician_days' => $normalized['technicianDays'], 'full_name' => $normalized['fullName'],
            'organization' => $normalized['organization'], 'email' => $normalized['email'],
            'phone' => $normalized['phone'], 'description' => null,
            'currency' => 'NGN', 'standard_daily_rate' => $this->money(ATLAS_RENTALS_PRICING['standard']['dailyRate']),
            'performance_daily_rate' => $this->money(ATLAS_RENTALS_PRICING['performance']['dailyRate']),
            'delivery_fee' => $this->money(ATLAS_RENTALS_PRICING['deliveryFee']),
            'technician_daily_rate' => $this->money(ATLAS_RENTALS_PRICING['technicianDailyRate']),
            'subtotal' => $this->money($subtotal), 'vat_rate' => '7.50',
            'vat_amount' => $this->money($vat), 'estimated_total' => $this->money($subtotal + $vat),
            'normalized_payload' => $canonical,
            'pricing_snapshot' => json_encode($pricing, JSON_THROW_ON_ERROR),
        ];
        $now = $this->clock ? ($this->clock)() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $this->result($this->store->save($record, (int) $now->format('Y')), false);
    }

    public function preview(array $input): array
    {
        $normalized = $this->normalizeAndValidate($input);
        $journeyId = $normalized['journeyId'];
        unset($normalized['journeyId']);
        $canonical = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $days = $this->rentalDays($normalized['startDate'], $normalized['endDate']);
        $pricing = atlasRentalsCalculatePricing($normalized, $days);
        return [
            'journeyId' => $journeyId, 'normalized' => $normalized, 'canonical' => $canonical,
            'hash' => hash('sha256', $canonical),
            'pricing' => $pricing,
        ];
    }

    private function normalizeAndValidate(array $input): array
    {
        $errors = [];
        $unexpected = array_diff(array_keys($input), self::ALLOWED_FIELDS);
        if ($unexpected) $errors['payload'] = 'Unexpected fields are not allowed.';
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                if ($field === 'ratePlan') $errors[$field] = 'Select a rental rate plan.';
                elseif ($field === 'phone' || $field === 'phoneCountry') $errors['phone'] = 'Enter a valid phone number for the selected country, or include the full international number beginning with +.';
                else $errors[$field] = 'This field is required.';
            }
        }
        if ($errors) throw new EnquiryValidationException($errors);

        $text = static fn(mixed $value): string => is_string($value)
            ? preg_replace('/\s+/u', ' ', trim($value)) : '';
        $value = [
            'journeyId' => $text($input['journeyId']), 'location' => $text($input['location']),
            'startDate' => $text($input['startDate']), 'endDate' => $text($input['endDate']), 'ratePlan' => $text($input['ratePlan']),
            'standardQuantity' => $input['standardQuantity'], 'performanceQuantity' => $input['performanceQuantity'],
            'technicianRequired' => $input['technicianRequired'], 'technicianDays' => $input['technicianDays'],
            'fullName' => $text($input['fullName']), 'organization' => $text($input['organization']),
            'email' => strtolower($text($input['email'])), 'phone' => $text($input['phone']),
        ];
        if (!preg_match('/^[a-f0-9]{32}$/', $value['journeyId'])) $errors['journeyId'] = 'The journey identity is invalid.';
        $this->length($value['location'], 2, 120, 'location', $errors);
        $this->length($value['fullName'], 2, 160, 'fullName', $errors);
        $this->length($value['organization'], 2, 200, 'organization', $errors);
        if (!filter_var($value['email'], FILTER_VALIDATE_EMAIL) || strlen($value['email']) > 254) $errors['email'] = 'Enter a valid email address.';
        $phoneCountry = strtoupper($text($input['phoneCountry']));
        $normalizedPhone = $this->normalizePhone($value['phone'], $phoneCountry);
        if ($normalizedPhone === null) {
            $errors['phone'] = 'Enter a valid phone number for the selected country, or include the full international number beginning with +.';
        } else {
            $value['phone'] = $normalizedPhone;
        }
        foreach (['standardQuantity', 'performanceQuantity', 'technicianDays'] as $field) {
            if (!is_int($value[$field]) || $value[$field] < 0 || $value[$field] > 10000) $errors[$field] = 'Enter a valid whole number.';
        }
        if (!is_bool($value['technicianRequired'])) $errors['technicianRequired'] = 'Choose whether a technician is required.';
        if (is_int($value['standardQuantity']) && is_int($value['performanceQuantity'])
            && $value['standardQuantity'] + $value['performanceQuantity'] < 5) $errors['quantity'] = 'Select at least 5 laptops.';
        if ($value['technicianRequired'] === true && (!is_int($value['technicianDays']) || $value['technicianDays'] < 1)) $errors['technicianDays'] = 'Enter at least 1 technician day.';
        if ($value['technicianRequired'] === false && $value['technicianDays'] !== 0) $errors['technicianDays'] = 'Technician days must be zero when support is not selected.';
        try {
            $rentalDays = $this->rentalDays($value['startDate'], $value['endDate']);
            atlasRentalsRatePlanUnitPrice($rentalDays, ATLAS_RENTALS_PRICING['standard'], $value['ratePlan']);
        }
        catch (InvalidArgumentException $error) {
            if (str_contains($error->getMessage(), 'Rate') || str_contains($error->getMessage(), 'rate plan')) $errors['ratePlan'] = $error->getMessage();
            else $errors['dates'] = 'Enter valid inclusive rental dates.';
        }
        if ($errors) throw new EnquiryValidationException($errors);
        if ($this->journeyIdentifierCheck !== null) ($this->journeyIdentifierCheck)($value['journeyId']);
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

    private function normalizePhone(string $phone, string $phoneCountry): ?string
    {
        if (preg_match('/^\+?[0-9 ()-]+$/u', $phone) !== 1 || preg_match('/^[A-Z]{2}$/', $phoneCountry) !== 1) return null;
        $util = \libphonenumber\PhoneNumberUtil::getInstance();
        if (!in_array($phoneCountry, $util->getSupportedRegions(), true)) return null;
        try {
            $number = $util->parse($phone, str_starts_with($phone, '+') ? null : $phoneCountry);
            if (!$util->isValidNumber($number)) return null;
            return $util->format($number, \libphonenumber\PhoneNumberFormat::E164);
        } catch (\libphonenumber\NumberParseException) {
            return null;
        }
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
