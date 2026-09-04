<?php
declare(strict_types=1);

const ATLAS_RENTALS_PRICING = [
    'standard' => ['name' => 'Standard Business Laptop', 'dailyRate' => 10000],
    'performance' => ['name' => 'High Performance Laptop', 'dailyRate' => 15000],
    'deliveryFee' => 40000,
    'technicianDailyRate' => 35000, 'vatRate' => 0.075, 'minimumQuantity' => 5,
];
const ATLAS_RENTALS_RATE_PLANS = ['daily' => 'Daily Rate'];

// Retained exclusively to interpret historical stored pricing material. New enquiries never call this path.
function atlasRentalsHistoricalDecomposeDuration(int $totalDays): array
{
    if ($totalDays < 0) throw new InvalidArgumentException('Rental days must be non-negative.');
    $months = intdiv($totalDays, 30);
    $remaining = $totalDays % 30;
    $weeks = intdiv($remaining, 7);
    return ['totalDays' => $totalDays, 'months' => $months, 'weeks' => $weeks, 'days' => $remaining % 7];
}

function atlasRentalsDurationLabel(array $duration): string
{
    $parts = [];
    foreach ([['months', 'month'], ['weeks', 'week'], ['days', 'day']] as [$key, $label]) {
        $count = (int)($duration[$key] ?? 0);
        if ($count > 0) $parts[] = $count . ' ' . $label . ($count === 1 ? '' : 's');
    }
    return $parts ? implode(' + ', $parts) : '0 days';
}

function atlasRentalsAppliedRatesLabel(array $tier, callable $money): string
{
    $parts = [];
    if ((int)$tier['months'] > 0) $parts[] = $tier['months'] . ' month' . ($tier['months'] === 1 ? '' : 's') . ' at ' . $money($tier['monthlyRate']);
    if ((int)$tier['weeks'] > 0) $parts[] = $tier['weeks'] . ' week' . ($tier['weeks'] === 1 ? '' : 's') . ' at ' . $money($tier['weeklyRate']);
    if ((int)$tier['days'] > 0) $parts[] = $tier['days'] . ' day' . ($tier['days'] === 1 ? '' : 's') . ' at ' . $money($tier['dailyRate']);
    return implode(' + ', $parts);
}

function atlasRentalsHistoricalTieredUnitPrice(int $totalDays, array $rates): array
{
    foreach (['dailyRate', 'weeklyRate', 'monthlyRate'] as $key) {
        if (!array_key_exists($key, $rates) || !is_int($rates[$key]) || $rates[$key] < 0) {
            throw new InvalidArgumentException('Historical tier pricing requires complete persisted rate values.');
        }
    }
    $duration = atlasRentalsHistoricalDecomposeDuration($totalDays);
    $amount = $duration['months'] * (int)$rates['monthlyRate']
        + $duration['weeks'] * (int)$rates['weeklyRate']
        + $duration['days'] * (int)$rates['dailyRate'];
    return $duration + ['dailyRate' => (int)$rates['dailyRate'], 'weeklyRate' => (int)$rates['weeklyRate'], 'monthlyRate' => (int)$rates['monthlyRate'], 'perUnitRental' => $amount];
}

function atlasRentalsRatePlanUnitPrice(int $totalDays, array $rates, string $ratePlan): array
{
    if ($ratePlan !== 'daily') throw new InvalidArgumentException('Daily Rate is the only supported rental rate plan.');
    $duration = ['totalDays' => $totalDays, 'months' => 0, 'weeks' => 0, 'days' => $totalDays];
    $amount = $duration['days'] * (int)$rates['dailyRate'];
    return $duration + ['ratePlan' => $ratePlan, 'ratePlanLabel' => ATLAS_RENTALS_RATE_PLANS[$ratePlan], 'dailyRate' => (int)$rates['dailyRate'], 'perUnitRental' => $amount];
}

function atlasRentalsCalculatePricing(array $normalized, int $rentalDays): array
{
    $ratePlan = (string)($normalized['ratePlan'] ?? '');
    $standard = atlasRentalsRatePlanUnitPrice($rentalDays, ATLAS_RENTALS_PRICING['standard'], $ratePlan);
    $performance = atlasRentalsRatePlanUnitPrice($rentalDays, ATLAS_RENTALS_PRICING['performance'], $ratePlan);
    $duration = ['totalDays' => $rentalDays, 'months' => $standard['months'], 'weeks' => $standard['weeks'], 'days' => $standard['days']];
    $standard['quantity'] = (int)$normalized['standardQuantity'];
    $performance['quantity'] = (int)$normalized['performanceQuantity'];
    $standard['equipmentAmount'] = $standard['quantity'] * $standard['perUnitRental'];
    $performance['equipmentAmount'] = $performance['quantity'] * $performance['perUnitRental'];
    $equipmentAmount = $standard['equipmentAmount'] + $performance['equipmentAmount'];
    $technicianAmount = (int)$normalized['technicianDays'] * ATLAS_RENTALS_PRICING['technicianDailyRate'];
    $subtotal = $equipmentAmount + ATLAS_RENTALS_PRICING['deliveryFee'] + $technicianAmount;
    $vat = (int)round($subtotal * ATLAS_RENTALS_PRICING['vatRate']);
    return [
        'currency' => 'NGN', 'ratePlan' => $ratePlan, 'ratePlanLabel' => ATLAS_RENTALS_RATE_PLANS[$ratePlan], 'rentalDays' => $rentalDays, 'duration' => $duration,
        'durationLabel' => atlasRentalsDurationLabel($duration), 'standard' => $standard, 'performance' => $performance,
        'standardDailyRate' => ATLAS_RENTALS_PRICING['standard']['dailyRate'], 'performanceDailyRate' => ATLAS_RENTALS_PRICING['performance']['dailyRate'],
        'equipmentAmount' => $equipmentAmount, 'deliveryFee' => ATLAS_RENTALS_PRICING['deliveryFee'],
        'technicianDailyRate' => ATLAS_RENTALS_PRICING['technicianDailyRate'], 'technicianAmount' => $technicianAmount,
        'vatRate' => ATLAS_RENTALS_PRICING['vatRate'], 'subtotal' => $subtotal, 'vatAmount' => $vat, 'estimatedTotal' => $subtotal + $vat,
    ];
}

function atlasRentalsPricingFromRecord(array $record): array
{
    $snapshot = json_decode((string)($record['pricing_snapshot'] ?? ''), true);
    if (is_array($snapshot) && isset($snapshot['standard']['perUnitRental'], $snapshot['performance']['perUnitRental'], $snapshot['duration'])) {
        if (!isset($snapshot['ratePlan'])) return ['historicalPricing' => true, 'ratePlan' => 'historical', 'ratePlanLabel' => 'Historical stored pricing'] + $snapshot;
        return $snapshot;
    }
    $normalized = json_decode((string)$record['normalized_payload'], true, 32, JSON_THROW_ON_ERROR);
    $days = (int)$record['rental_days'];
    $legacyTier = static function (int $quantity, int $dailyRate) use ($days): array {
        $perUnit = $days * $dailyRate;
        return ['totalDays' => $days, 'months' => 0, 'weeks' => 0, 'days' => $days, 'dailyRate' => $dailyRate, 'weeklyRate' => 0, 'monthlyRate' => 0, 'perUnitRental' => $perUnit, 'quantity' => $quantity, 'equipmentAmount' => $quantity * $perUnit];
    };
    $standard = $legacyTier((int)$normalized['standardQuantity'], (int)$record['standard_daily_rate']);
    $performance = $legacyTier((int)$normalized['performanceQuantity'], (int)$record['performance_daily_rate']);
    return [
        'legacyPricing' => true, 'currency' => 'NGN', 'ratePlan' => 'historical', 'ratePlanLabel' => 'Historical stored pricing', 'rentalDays' => $days,
        'duration' => ['totalDays' => $days, 'months' => 0, 'weeks' => 0, 'days' => $days],
        'durationLabel' => $days . ' day' . ($days === 1 ? '' : 's'), 'standard' => $standard, 'performance' => $performance,
        'equipmentAmount' => $standard['equipmentAmount'] + $performance['equipmentAmount'],
        'deliveryFee' => (int)$record['delivery_fee'], 'technicianDailyRate' => (int)$record['technician_daily_rate'],
        'technicianAmount' => (int)$record['technician_days'] * (int)$record['technician_daily_rate'],
        'vatRate' => (float)($snapshot['vatRate'] ?? 0.075), 'subtotal' => (int)$record['subtotal'],
        'vatAmount' => (int)$record['vat_amount'], 'estimatedTotal' => (int)$record['estimated_total'],
    ];
}
