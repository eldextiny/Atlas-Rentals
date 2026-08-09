<?php
declare(strict_types=1);

const ATLAS_RENTALS_PRICING = [
    'standard' => ['name' => 'Standard Business Laptop', 'dailyRate' => 10000, 'weeklyRate' => 59500, 'monthlyRate' => 185000],
    'performance' => ['name' => 'High Performance Laptop', 'dailyRate' => 15000, 'weeklyRate' => 89500, 'monthlyRate' => 225500],
    'daysPerWeek' => 7, 'daysPerMonth' => 30, 'deliveryFee' => 40000,
    'technicianDailyRate' => 35000, 'vatRate' => 0.075, 'minimumQuantity' => 5,
];
const ATLAS_RENTALS_RATE_PLANS = [
    'daily' => 'Daily Rate', 'weekly' => 'Weekly Rate - 7 days',
    'monthly' => 'Monthly Rate - 30 days', 'best' => 'Best Available Rate',
];

function atlasRentalsDecomposeDuration(int $totalDays): array
{
    if ($totalDays < 0) throw new InvalidArgumentException('Rental days must be non-negative.');
    $months = intdiv($totalDays, ATLAS_RENTALS_PRICING['daysPerMonth']);
    $remaining = $totalDays % ATLAS_RENTALS_PRICING['daysPerMonth'];
    $weeks = intdiv($remaining, ATLAS_RENTALS_PRICING['daysPerWeek']);
    return ['totalDays' => $totalDays, 'months' => $months, 'weeks' => $weeks, 'days' => $remaining % ATLAS_RENTALS_PRICING['daysPerWeek']];
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

function atlasRentalsTieredUnitPrice(int $totalDays, array $rates): array
{
    $duration = atlasRentalsDecomposeDuration($totalDays);
    $amount = $duration['months'] * (int)$rates['monthlyRate']
        + $duration['weeks'] * (int)$rates['weeklyRate']
        + $duration['days'] * (int)$rates['dailyRate'];
    return $duration + ['dailyRate' => (int)$rates['dailyRate'], 'weeklyRate' => (int)$rates['weeklyRate'], 'monthlyRate' => (int)$rates['monthlyRate'], 'perUnitRental' => $amount];
}

function atlasRentalsRatePlanUnitPrice(int $totalDays, array $rates, string $ratePlan): array
{
    if (!isset(ATLAS_RENTALS_RATE_PLANS[$ratePlan])) throw new InvalidArgumentException('Select a rental rate plan.');
    if ($ratePlan === 'weekly' && $totalDays % ATLAS_RENTALS_PRICING['daysPerWeek'] !== 0) throw new InvalidArgumentException('Weekly Rate requires the rental duration to be a whole multiple of 7 days.');
    if ($ratePlan === 'monthly' && $totalDays % ATLAS_RENTALS_PRICING['daysPerMonth'] !== 0) throw new InvalidArgumentException('Monthly Rate requires the rental duration to be a whole multiple of 30 days.');
    if ($ratePlan === 'daily') $duration = ['totalDays' => $totalDays, 'months' => 0, 'weeks' => 0, 'days' => $totalDays];
    elseif ($ratePlan === 'weekly') $duration = ['totalDays' => $totalDays, 'months' => 0, 'weeks' => intdiv($totalDays, 7), 'days' => 0];
    elseif ($ratePlan === 'monthly') $duration = ['totalDays' => $totalDays, 'months' => intdiv($totalDays, 30), 'weeks' => 0, 'days' => 0];
    else $duration = atlasRentalsDecomposeDuration($totalDays);
    $amount = $duration['months'] * (int)$rates['monthlyRate'] + $duration['weeks'] * (int)$rates['weeklyRate'] + $duration['days'] * (int)$rates['dailyRate'];
    return $duration + ['ratePlan' => $ratePlan, 'ratePlanLabel' => ATLAS_RENTALS_RATE_PLANS[$ratePlan], 'dailyRate' => (int)$rates['dailyRate'], 'weeklyRate' => (int)$rates['weeklyRate'], 'monthlyRate' => (int)$rates['monthlyRate'], 'perUnitRental' => $amount];
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
        'standardDailyRate' => ATLAS_RENTALS_PRICING['standard']['dailyRate'], 'standardWeeklyRate' => ATLAS_RENTALS_PRICING['standard']['weeklyRate'],
        'standardMonthlyRate' => ATLAS_RENTALS_PRICING['standard']['monthlyRate'], 'performanceDailyRate' => ATLAS_RENTALS_PRICING['performance']['dailyRate'],
        'performanceWeeklyRate' => ATLAS_RENTALS_PRICING['performance']['weeklyRate'], 'performanceMonthlyRate' => ATLAS_RENTALS_PRICING['performance']['monthlyRate'],
        'daysPerWeek' => ATLAS_RENTALS_PRICING['daysPerWeek'], 'daysPerMonth' => ATLAS_RENTALS_PRICING['daysPerMonth'],
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
