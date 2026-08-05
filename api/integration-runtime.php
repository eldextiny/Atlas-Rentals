<?php
declare(strict_types=1);

const ATLAS_RENTALS_INTEGRATIONS_CONFIG = '/home/548005.cloudwaysapps.com/ezgshksprf/private_html/atlas-rentals-integrations.php';
const ATLAS_RENTALS_DELIVERY_STATE = '/home/548005.cloudwaysapps.com/ezgshksprf/private_html/atlas-rentals/delivery-state';
const ATLAS_RENTALS_PDF_STORAGE = '/home/548005.cloudwaysapps.com/ezgshksprf/private_html/atlas-rentals/quotation-pdfs';
const ATLAS_RENTALS_MAX_ATTACHMENT = 8388608;

function atlasRentalsIntegrationConfig(): array
{
    $path = getenv('ATLAS_RENTALS_INTEGRATIONS_CONFIG') ?: ATLAS_RENTALS_INTEGRATIONS_CONFIG;
    $private = is_readable($path) ? require $path : [];
    if (!is_array($private)) $private = [];
    $map = [
        'resend_api_key' => 'ATLAS_RENTALS_RESEND_API_KEY', 'from_email' => 'ATLAS_RENTALS_FROM_EMAIL',
        'from_name' => 'ATLAS_RENTALS_FROM_NAME', 'reply_to' => 'ATLAS_RENTALS_REPLY_TO',
        'admin_email' => 'ATLAS_RENTALS_ADMIN_EMAIL', 'crm_endpoint' => 'ATLAS_RENTALS_CRM_ENDPOINT',
        'crm_token' => 'ATLAS_RENTALS_CRM_TOKEN', 'crm_source' => 'ATLAS_RENTALS_CRM_SOURCE',
        'crm_service' => 'ATLAS_RENTALS_CRM_SERVICE',
    ];
    $result = [];
    foreach ($map as $key => $environment) {
        $value = getenv($environment);
        $result[$key] = trim((string)($value !== false ? $value : ($private[$key] ?? '')));
    }
    $result['state_path'] = getenv('ATLAS_RENTALS_DELIVERY_STATE_PATH') ?: ATLAS_RENTALS_DELIVERY_STATE;
    $result['pdf_path'] = getenv('ATLAS_RENTALS_PDF_PATH') ?: ATLAS_RENTALS_PDF_STORAGE;
    return $result;
}

function atlasRentalsSafeResult(bool $ok, string $code, bool $retryable = false, array $extra = []): array
{
    return ['ok' => $ok, 'code' => $code, 'retryable' => $retryable] + $extra;
}

function atlasRentalsStateFile(string $directory, string $key): string
{
    if (!preg_match('/^[A-Za-z0-9-]{12,80}$/', $key)) throw new RuntimeException('Invalid state identity.');
    if (!is_dir($directory) || !is_readable($directory) || !is_writable($directory)) throw new RuntimeException('Private state unavailable.');
    return rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $key . '.json';
}

function atlasRentalsWithState(string $directory, string $key, callable $operation): array
{
    $path = atlasRentalsStateFile($directory, $key);
    $lock = fopen($path . '.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Private state lock unavailable.');
    try {
        $state = [];
        if (is_file($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (!is_array($decoded)) throw new RuntimeException('Private state invalid.');
            $state = $decoded;
        }
        $state = $operation($state);
        $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = tempnam($directory, $key . '.tmp.');
        if ($temporary === false || file_put_contents($temporary, $encoded, LOCK_EX) === false || !rename($temporary, $path)) {
            if (is_string($temporary) && is_file($temporary)) unlink($temporary);
            throw new RuntimeException('Private state write failed.');
        }
        return $state;
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}

function atlasRentalsCrmPayload(array $preview, ?string $reference, array $config): array
{
    $data = $preview['normalized'];
    return [
        'journeyId' => $preview['journeyId'], 'enquiryReference' => $reference,
        'contact' => ['fullName' => $data['fullName'], 'email' => $data['email'], 'phone' => $data['phone']],
        'organisation' => $data['organization'], 'location' => $data['location'],
        'dates' => ['start' => $data['startDate'], 'end' => $data['endDate'], 'inclusiveDays' => $preview['pricing']['rentalDays']],
        'laptops' => ['standard' => $data['standardQuantity'], 'highPerformance' => $data['performanceQuantity']],
        'technician' => ['required' => $data['technicianRequired'], 'days' => $data['technicianDays']],
        'estimate' => $preview['pricing'] + ['currency' => 'NGN'],
        'source' => $config['crm_source'], 'service' => $config['crm_service'], 'stage' => $reference ? 'enquiry_received' : 'review',
    ];
}

function atlasRentalsPostCrm(array $payload, array $config): array
{
    if (!filter_var($config['crm_endpoint'] ?? '', FILTER_VALIDATE_URL)
        || ($config['crm_token'] ?? '') === '' || ($config['crm_source'] ?? '') === '' || ($config['crm_service'] ?? '') === '') {
        return atlasRentalsSafeResult(false, 'CRM_CONFIG_MISSING', true);
    }
    if (!function_exists('curl_init')) return atlasRentalsSafeResult(false, 'CRM_HTTP_UNAVAILABLE', true);
    $handle = curl_init($config['crm_endpoint']);
    curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $config['crm_token']],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    $body = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE); curl_close($handle);
    $decoded = json_decode((string)$body, true);
    return $body !== false && $status >= 200 && $status < 300 && is_array($decoded)
        ? atlasRentalsSafeResult(true, 'CRM_ACCEPTED') : atlasRentalsSafeResult(false, 'CRM_REQUEST_FAILED', true);
}

function atlasRentalsCleanup(string $directory, array $extensions): void
{
    if (!is_dir($directory)) return;
    $cutoff = time() - (30 * 86400);
    foreach ($extensions as $extension) {
        foreach (glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.' . $extension) ?: [] as $path) {
            if (is_file($path) && filemtime($path) < $cutoff) @unlink($path);
        }
    }
}

function atlasRentalsSyncCrm(array $preview, ?string $reference, array $config, ?callable $poster = null): array
{
    $fingerprint = hash('sha256', $preview['canonical'] . '|' . ($reference ?? 'review'));
    return atlasRentalsWithState($config['state_path'], 'CRM-' . $preview['journeyId'], function (array $state) use ($preview, $reference, $config, $fingerprint, $poster): array {
        if (($state['crm']['fingerprint'] ?? '') === $fingerprint && ($state['crm']['status'] ?? '') === 'completed') return $state;
        $result = $poster ? $poster(atlasRentalsCrmPayload($preview, $reference, $config), $config) : atlasRentalsPostCrm(atlasRentalsCrmPayload($preview, $reference, $config), $config);
        $state['crm'] = ['fingerprint' => $fingerprint, 'status' => $result['ok'] ? 'completed' : 'pending', 'code' => $result['code']];
        return $state;
    });
}

function atlasRentalsPdfEscape(string $value): string
{
    $value = preg_replace('/[^\x20-\x7E]/', '', $value);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function atlasRentalsGeneratePdf(array $record, string $directory): array
{
    $reference = (string)$record['enquiry_reference'];
    $fingerprint = substr(hash('sha256', (string)$record['normalized_payload'] . '|' . (string)$record['pricing_snapshot']), 0, 16);
    $path = atlasRentalsStateFile($directory, $reference . '-' . $fingerprint);
    $path = substr($path, 0, -5) . '.pdf';
    if (is_file($path) && filesize($path) > 100) return ['path' => $path, 'fingerprint' => $fingerprint];
    $data = json_decode((string)$record['normalized_payload'], true, 32, JSON_THROW_ON_ERROR);
    $pricing = json_decode((string)$record['pricing_snapshot'], true, 32, JSON_THROW_ON_ERROR);
    $created = (new DateTimeImmutable((string)$record['created_at']))->format('d M Y');
    $validUntil = (new DateTimeImmutable((string)$record['created_at']))->modify('+7 days')->format('d M Y');
    $lines = [
        'ATLAS RENTALS BY DY-PLUS', 'LAPTOP RENTAL QUOTATION', 'Reference: ' . $reference,
        'Created: ' . $created . ' | Valid until: ' . $validUntil,
        'Client: ' . $data['fullName'], 'Organisation: ' . $data['organization'],
        'Email: ' . $data['email'], 'Phone: ' . $data['phone'], 'Location: ' . $data['location'],
        'Rental: ' . $data['startDate'] . ' to ' . $data['endDate'] . ' (' . $record['rental_days'] . ' inclusive days)',
        'Standard laptops: ' . $record['standard_quantity'] . ' x ' . $record['rental_days'] . ' x NGN ' . number_format((float)$record['standard_daily_rate'], 2) . ' = NGN ' . number_format($record['standard_quantity'] * $record['rental_days'] * (float)$record['standard_daily_rate'], 2),
        'High Performance: ' . $record['performance_quantity'] . ' x ' . $record['rental_days'] . ' x NGN ' . number_format((float)$record['performance_daily_rate'], 2) . ' = NGN ' . number_format($record['performance_quantity'] * $record['rental_days'] * (float)$record['performance_daily_rate'], 2),
        'Delivery & Retrieval (compulsory): NGN ' . number_format((float)$record['delivery_fee'], 2),
    ];
    if ((int)$record['technician_required'] === 1) $lines[] = 'Technician: ' . $record['technician_days'] . ' x NGN ' . number_format((float)$record['technician_daily_rate'], 2) . ' = NGN ' . number_format($record['technician_days'] * (float)$record['technician_daily_rate'], 2);
    $lines = array_merge($lines, [
        'Subtotal before VAT: NGN ' . number_format((float)$record['subtotal'], 2),
        'VAT (7.5%): NGN ' . number_format((float)$record['vat_amount'], 2),
        'ESTIMATED TOTAL: NGN ' . number_format((float)$record['estimated_total'], 2),
        'This estimate is valid for 7 days and remains subject to availability and DY-PLUS review.',
        'This submission is an enquiry and does not confirm availability or create a booking.',
        'DY-PLUS NIG. LTD. | Atlas Rentals | Quotation Reference ' . $reference . ' | Page 1 of 1',
    ]);
    $displayLines = [];
    foreach ($lines as $index => $line) {
        foreach (explode("\n", wordwrap($line, $index < 2 ? 58 : 92, "\n", true)) as $wrapped) $displayLines[] = [$wrapped, $index < 2 ? (16 - ($index * 2)) : 9.5];
    }
    $content = "BT\n"; $y = 800;
    foreach ($displayLines as [$line, $size]) {
        $content .= "/F1 {$size} Tf\n1 0 0 1 42 {$y} Tm\n(" . atlasRentalsPdfEscape($line) . ") Tj\n";
        $y -= $size > 10 ? 24 : 18;
    }
    $content .= "ET";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n"; $offsets = [0];
    foreach ($objects as $index => $object) { $offsets[] = strlen($pdf); $pdf .= ($index + 1) . " 0 obj\n{$object}\nendobj\n"; }
    $xref = strlen($pdf); $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    for ($i = 1; $i <= 5; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    $pdf .= "trailer << /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    $temporary = tempnam($directory, $reference . '.tmp.');
    if ($temporary === false || file_put_contents($temporary, $pdf, LOCK_EX) === false || !rename($temporary, $path)) throw new RuntimeException('PDF generation failed.');
    if (filesize($path) > ATLAS_RENTALS_MAX_ATTACHMENT) { unlink($path); throw new RuntimeException('PDF attachment too large.'); }
    return ['path' => $path, 'fingerprint' => $fingerprint];
}

function atlasRentalsSendEmail(array $record, array $pdf, string $audience, array $config): array
{
    $recipient = $audience === 'client' ? $record['email'] : $config['admin_email'];
    foreach (['resend_api_key', 'from_email', 'admin_email'] as $key) if (($config[$key] ?? '') === '') return atlasRentalsSafeResult(false, 'EMAIL_CONFIG_MISSING', true);
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || !function_exists('curl_init')) return atlasRentalsSafeResult(false, 'EMAIL_UNAVAILABLE', true);
    $reference = $record['enquiry_reference'];
    $subject = $audience === 'client' ? "We received your Atlas Rentals enquiry {$reference}" : "New Atlas Rentals enquiry {$reference}";
    $summary = "Reference: {$reference}\nClient: {$record['full_name']}\nOrganisation: {$record['organization']}\nLocation: {$record['location']}\nDates: {$record['start_date']} to {$record['end_date']}\nEstimated total: NGN " . number_format((float)$record['estimated_total'], 2);
    $text = $audience === 'client' ? "Thank you. DY-PLUS received your laptop rental enquiry.\n{$summary}\nAvailability and booking remain subject to DY-PLUS confirmation." : "A new Atlas Rentals enquiry requires review.\n{$summary}\nJourney and operational details are contained in the attached quotation.";
    $payload = ['from' => trim(($config['from_name'] ?: 'Atlas Rentals by DY-PLUS') . ' <' . $config['from_email'] . '>'), 'to' => [$recipient], 'subject' => $subject, 'text' => $text,
        'attachments' => [['filename' => $reference . '-quotation.pdf', 'content' => base64_encode((string)file_get_contents($pdf['path']))]]];
    if (filter_var($config['reply_to'] ?? '', FILTER_VALIDATE_EMAIL)) $payload['reply_to'] = $config['reply_to'];
    $handle = curl_init('https://api.resend.com/emails');
    curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['resend_api_key'], 'Content-Type: application/json', 'Idempotency-Key: atlas-rentals-' . strtolower($reference) . '-' . $audience],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    $body = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE); curl_close($handle);
    return $body !== false && $status >= 200 && $status < 300 ? atlasRentalsSafeResult(true, 'EMAIL_DELIVERED') : atlasRentalsSafeResult(false, 'EMAIL_DELIVERY_FAILED', true);
}

function atlasRentalsDeliver(array $record, array $preview, array $config, array $adapters = []): array
{
    atlasRentalsCleanup($config['state_path'], ['json', 'lock']);
    atlasRentalsCleanup($config['pdf_path'], ['pdf']);
    try { ($adapters['crm'] ?? 'atlasRentalsSyncCrm')($preview, $record['enquiry_reference'], $config); } catch (Throwable) {}
    return atlasRentalsWithState($config['state_path'], $record['enquiry_reference'], function (array $state) use ($record, $config, $adapters): array {
        $fingerprint = hash('sha256', $record['normalized_payload'] . '|' . $record['pricing_snapshot']);
        if (isset($state['fingerprint']) && $state['fingerprint'] !== $fingerprint) throw new RuntimeException('Delivery state conflict.');
        $state['fingerprint'] = $fingerprint;
        try {
            $pdf = ($adapters['pdf'] ?? 'atlasRentalsGeneratePdf')($record, $config['pdf_path']); $state['pdf'] = ['status' => 'completed', 'fingerprint' => $pdf['fingerprint']];
        } catch (Throwable) { $state['pdf'] = ['status' => 'pending']; return $state; }
        foreach (['client', 'admin'] as $audience) {
            if (($state[$audience . 'Email']['status'] ?? '') === 'completed') continue;
            $result = ($adapters['email'] ?? 'atlasRentalsSendEmail')($record, $pdf, $audience, $config);
            $state[$audience . 'Email'] = ['status' => $result['ok'] ? 'completed' : 'pending', 'code' => $result['code']];
            if (!$result['ok']) break;
        }
        return $state;
    });
}
