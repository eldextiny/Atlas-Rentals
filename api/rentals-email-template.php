<?php
declare(strict_types=1);

require_once __DIR__ . '/rentals-pricing.php';

function atlasRentalsHtml(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function atlasRentalsEmailModel(array $record): array
{
    $data = json_decode((string)$record['normalized_payload'], true, 32, JSON_THROW_ON_ERROR);
    $pricing = atlasRentalsPricingFromRecord($record);
    $money = static fn(mixed $value): string => '₦' . number_format((float)$value, 2);
    $days = (int)$record['rental_days'];
    $standard = $pricing['standard']; $performance = $pricing['performance'];
    return [
        'reference' => (string)$record['enquiry_reference'], 'name' => (string)$data['fullName'],
        'organization' => (string)$data['organization'], 'email' => (string)$data['email'],
        'phone' => (string)$data['phone'], 'location' => (string)$data['location'],
        'start' => (string)$data['startDate'], 'end' => (string)$data['endDate'], 'days' => $days,
        'legacyPricing' => (bool)($pricing['legacyPricing'] ?? false),
        'ratePlan' => (string)$pricing['ratePlan'], 'ratePlanLabel' => (string)$pricing['ratePlanLabel'],
        'durationLabel' => $pricing['durationLabel'], 'standardQuantity' => (int)$record['standard_quantity'],
        'standardDailyRate' => $money($standard['dailyRate']), 'standardWeeklyRate' => $money($standard['weeklyRate']), 'standardMonthlyRate' => $money($standard['monthlyRate']),
        'standardPerUnit' => $money($standard['perUnitRental']), 'standardAmount' => $money($standard['equipmentAmount']), 'performanceQuantity' => (int)$record['performance_quantity'],
        'performanceDailyRate' => $money($performance['dailyRate']), 'performanceWeeklyRate' => $money($performance['weeklyRate']), 'performanceMonthlyRate' => $money($performance['monthlyRate']),
        'performancePerUnit' => $money($performance['perUnitRental']), 'performanceAmount' => $money($performance['equipmentAmount']),
        'technicianRequired' => (int)$record['technician_required'] === 1,
        'technicianDays' => (int)$record['technician_days'], 'technicianRate' => $money($record['technician_daily_rate']),
        'technicianAmount' => $money($pricing['technicianAmount']), 'delivery' => $money($pricing['deliveryFee']),
        'subtotal' => $money($record['subtotal']), 'vat' => $money($record['vat_amount']),
        'total' => $money($record['estimated_total']),
    ];
}

function atlasRentalsEmailRows(array $rows): string
{
    $html = '';
    foreach ($rows as [$label, $value]) {
        $html .= '<tr><td style="padding:9px 12px;border-bottom:1px solid #e5e7eb;color:#64748b;font-size:13px;width:42%;">'
            . atlasRentalsHtml($label) . '</td><td style="padding:9px 12px;border-bottom:1px solid #e5e7eb;color:#0f172a;font-size:13px;font-weight:600;">'
            . atlasRentalsHtml($value) . '</td></tr>';
    }
    return $html;
}

function atlasRentalsEmailSection(string $title, array $rows): string
{
    return '<tr><td style="padding:0 28px 22px;"><h2 style="margin:0 0 10px;color:#12385b;font-size:16px;">'
        . atlasRentalsHtml($title) . '</h2><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e5e7eb;border-radius:8px;border-collapse:separate;overflow:hidden;">'
        . atlasRentalsEmailRows($rows) . '</table></td></tr>';
}

function atlasRentalsNormalizeWhatsAppNumber(mixed $value): ?string
{
    $digits = preg_replace('/\D+/', '', (string)$value) ?? '';
    return preg_match('/^[1-9]\d{7,14}$/D', $digits) === 1 ? $digits : null;
}

function atlasRentalsWhatsAppUrl(string $reference, array $config): ?string
{
    $number = atlasRentalsNormalizeWhatsAppNumber($config['whatsapp_number'] ?? '');
    if ($number === null) return null;
    $message = 'Hello DY-PLUS, I’m following up on laptop rental enquiry ' . $reference . '.';
    return 'https://wa.me/' . $number . '?text=' . rawurlencode($message);
}

function atlasRentalsEmailWhatsAppCta(string $url): string
{
    return '<tr><td align="center" style="padding:0 28px 26px;">'
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" bgcolor="#1f9d68" style="border-radius:999px;">'
        . '<a href="' . atlasRentalsHtml($url) . '" style="display:inline-block;padding:13px 21px;color:#ffffff;text-decoration:none;font-size:14px;line-height:1.25;font-weight:700;">Chat with us on WhatsApp</a>'
        . '</td></tr></table></td></tr>';
}

function atlasRentalsEmailShell(string $preheader, string $body): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . atlasRentalsHtml($preheader) . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(15,23,42,.08);">'
        . '<tr><td style="padding:24px 28px;background:#12385b;border-bottom:5px solid #1f9d68;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td><div style="color:#ffffff;font-size:21px;font-weight:700;letter-spacing:.3px;">DY-PLUS</div><div style="margin-top:4px;color:#d9f5e8;font-size:14px;font-weight:600;">ATLAS Rentals</div></td><td align="right" width="110"><img src="https://laptops.dyplus.com.ng/assets/dyplus-logo.png" width="100" alt="DY-PLUS company logo" style="display:block;width:100px;max-width:100%;height:auto;border:0;"></td></tr></table></td></tr>'
        . $body
        . '<tr><td style="padding:20px 28px;background:#f8fafc;border-top:1px solid #e5e7eb;text-align:center;color:#64748b;font-size:12px;line-height:1.6;">DY-PLUS NIG. LTD.<br>ATLAS Rentals by DY-PLUS</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function atlasRentalsBuildEmail(array $record, string $audience, array $config = []): array
{
    if (!in_array($audience, ['client', 'admin'], true)) throw new InvalidArgumentException('Invalid email audience.');
    $m = atlasRentalsEmailModel($record);
    $rentalRows = [
        ['Quotation reference', $m['reference']], ['Rental period', $m['start'] . ' to ' . $m['end']],
        ['Rental days', $m['days'] . ' day' . ($m['days'] === 1 ? '' : 's') . ' (' . $m['durationLabel'] . ')'], ['Rental rate plan', $m['ratePlanLabel']], ['Location', $m['location']],
    ];
    $itemRows = [];
    $rateText = static function (array $m, string $prefix): string {
        if ($m['legacyPricing'] || $m['ratePlan'] === 'daily') return 'Daily ' . $m[$prefix . 'DailyRate'];
        if ($m['ratePlan'] === 'weekly') return 'Weekly ' . $m[$prefix . 'WeeklyRate'];
        if ($m['ratePlan'] === 'monthly') return 'Monthly ' . $m[$prefix . 'MonthlyRate'];
        return 'Daily ' . $m[$prefix . 'DailyRate'] . ' | Weekly ' . $m[$prefix . 'WeeklyRate'] . ' | Monthly ' . $m[$prefix . 'MonthlyRate'];
    };
    if ($m['standardQuantity'] > 0) $itemRows = array_merge($itemRows, [
        ['Category', 'Standard Business Laptop'], ['Quantity', $m['standardQuantity'] . ' laptops'], ['Applied duration', $m['durationLabel']],
        ['Applied rate', $rateText($m, 'standard')],
        ['Per-unit rental', $m['standardPerUnit']], ['Equipment amount', $m['standardAmount']],
    ]);
    if ($m['performanceQuantity'] > 0) $itemRows = array_merge($itemRows, [
        ['Category', 'High Performance Laptop'], ['Quantity', $m['performanceQuantity'] . ' laptops'], ['Applied duration', $m['durationLabel']],
        ['Applied rate', $rateText($m, 'performance')],
        ['Per-unit rental', $m['performancePerUnit']], ['Equipment amount', $m['performanceAmount']],
    ]);
    if ($m['technicianRequired']) $itemRows[] = ['Technician', $m['technicianDays'] . ' days × ' . $m['technicianRate'] . ' — ' . $m['technicianAmount']];
    $itemRows[] = ['Delivery & retrieval', 'Standard rental service — ' . $m['delivery']];
    $totals = [['Subtotal before VAT', $m['subtotal']], ['VAT (7.5%)', $m['vat']], ['Estimated total', $m['total']]];

    if ($audience === 'client') {
        $intro = '<tr><td style="padding:28px 28px 20px;"><h1 style="margin:0 0 12px;color:#12385b;font-size:22px;">Laptop Rental Quotation</h1>'
            . '<p style="margin:0 0 10px;font-size:15px;line-height:1.6;">Dear ' . atlasRentalsHtml($m['name']) . ',</p>'
            . '<p style="margin:0;color:#475569;font-size:14px;line-height:1.65;">Thank you for contacting ATLAS Rentals by DY-PLUS. We have received your laptop rental enquiry and attached your quotation for review.</p></td></tr>';
        $whatsAppUrl = atlasRentalsWhatsAppUrl($m['reference'], $config);
        $body = $intro . atlasRentalsEmailSection('Rental summary', $rentalRows)
            . atlasRentalsEmailSection('Quotation breakdown', $itemRows)
            . atlasRentalsEmailSection('Commercial summary', $totals)
            . '<tr><td style="padding:0 28px 26px;"><div style="padding:14px 16px;background:#f0fdf4;border-left:4px solid #1f9d68;color:#334155;font-size:13px;line-height:1.6;">This estimate is valid for 30 days and remains subject to equipment availability and DY-PLUS review. This enquiry does not confirm availability or create a booking.</div></td></tr>'
            . ($whatsAppUrl === null ? '' : atlasRentalsEmailWhatsAppCta($whatsAppUrl));
        $opening = ['Dear ' . $m['name'] . ',', '', 'Thank you for contacting ATLAS Rentals by DY-PLUS. We have received your laptop rental enquiry.'];
    } else {
        $intro = '<tr><td style="padding:28px 28px 20px;"><h1 style="margin:0 0 12px;color:#12385b;font-size:22px;">New ATLAS Rentals Enquiry</h1>'
            . '<p style="margin:0;color:#475569;font-size:14px;line-height:1.65;">A new laptop rental enquiry requires operational and commercial review. The branded quotation is attached.</p></td></tr>';
        $contactRows = [['Customer', $m['name']], ['Organisation', $m['organization']], ['Email', $m['email']], ['Phone', $m['phone']]];
        $body = $intro . atlasRentalsEmailSection('Customer and contact', $contactRows)
            . atlasRentalsEmailSection('Operational details', $rentalRows)
            . atlasRentalsEmailSection('Equipment and services', $itemRows)
            . atlasRentalsEmailSection('Commercial review', $totals)
            . '<tr><td style="padding:0 28px 26px;"><div style="padding:14px 16px;background:#fff7ed;border-left:4px solid #f59e0b;color:#334155;font-size:13px;line-height:1.6;">This estimate is valid for 30 days. Review availability and confirm the booking position before making any commitment to the customer.</div></td></tr>';
        $opening = ['A new ATLAS Rentals enquiry requires operational and commercial review.'];
    }

    $lines = array_merge($opening, ['', 'REFERENCE', $m['reference'], '', 'CUSTOMER', $m['name'], 'Organisation: ' . $m['organization'], 'Email: ' . $m['email'], 'Phone: ' . $m['phone'], '', 'RENTAL DETAILS', 'Dates: ' . $m['start'] . ' to ' . $m['end'], 'Rental days: ' . $m['days'] . ' day(s) (' . $m['durationLabel'] . ')', 'Rental rate plan: ' . $m['ratePlanLabel'], 'Location: ' . $m['location'], '', 'QUOTATION BREAKDOWN']);
    foreach ($itemRows as [$label, $value]) $lines[] = $label . ': ' . $value;
    $lines = array_merge($lines, ['', 'COMMERCIAL SUMMARY', 'Subtotal before VAT: ' . $m['subtotal'], 'VAT (7.5%): ' . $m['vat'], 'Estimated total: ' . $m['total'], '', 'This estimate is valid for 30 days and remains subject to equipment availability and DY-PLUS review.', 'This enquiry does not confirm availability or create a booking.']);
    if ($audience === 'client' && isset($whatsAppUrl) && $whatsAppUrl !== null) $lines = array_merge($lines, ['', 'Chat with us on WhatsApp:', $whatsAppUrl]);
    $lines = array_merge($lines, ['', 'DY-PLUS NIG. LTD. | ATLAS Rentals']);
    return ['html' => atlasRentalsEmailShell($audience === 'client' ? 'Your ATLAS Rentals quotation is attached.' : 'A new ATLAS Rentals enquiry requires review.', $body), 'text' => implode("\n", $lines)];
}
