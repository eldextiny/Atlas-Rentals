<?php
declare(strict_types=1);

require_once __DIR__ . '/rentals-email-template.php';
require_once __DIR__ . '/document-engine/templates/rentals-quotation.php';

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
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Atlas-CRM-Integration-Token: ' . $config['crm_token']],
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

function atlasRentalsPdfEscape(string $value, bool $allowBalancedParentheses = false): string
{
    $value = preg_replace('/[^\x20-\x7E]/', '', $value);
    return $allowBalancedParentheses
        ? str_replace('\\', '\\\\', $value)
        : str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function atlasRentalsGeneratePdf(array $record, string $directory): array
{
    $reference = (string)$record['enquiry_reference'];
    $fingerprint = substr(hash('sha256', (string)$record['normalized_payload'] . '|' . (string)$record['pricing_snapshot'] . '|' . ATLAS_RENTALS_PDF_PRESENTATION_VERSION), 0, 16);
    $path = atlasRentalsStateFile($directory, $reference . '-' . $fingerprint);
    $path = substr($path, 0, -5) . '.pdf';
    if (is_file($path) && filesize($path) > 100) return ['path' => $path, 'fingerprint' => $fingerprint];
    $pdf = atlasRentalsRenderQuotationPdf($record);
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
    $message = atlasRentalsBuildEmail($record, $audience);
    $payload = ['from' => trim(($config['from_name'] ?: 'Atlas Rentals by DY-PLUS') . ' <' . $config['from_email'] . '>'), 'to' => [$recipient], 'subject' => $subject, 'html' => $message['html'], 'text' => $message['text'],
        'attachments' => [['filename' => $reference . '-quotation.pdf', 'content' => base64_encode((string)file_get_contents($pdf['path']))]]];
    if (filter_var($config['reply_to'] ?? '', FILTER_VALIDATE_EMAIL)) $payload['reply_to'] = $config['reply_to'];
    $handle = curl_init('https://api.resend.com/emails');
    curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['resend_api_key'], 'Content-Type: application/json', 'Idempotency-Key: ' . atlasRentalsEmailIdempotencyKey($reference, $audience)],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    $body = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE); curl_close($handle);
    return $body !== false && $status >= 200 && $status < 300 ? atlasRentalsSafeResult(true, 'EMAIL_DELIVERED') : atlasRentalsSafeResult(false, 'EMAIL_DELIVERY_FAILED', true);
}

function atlasRentalsEmailIdempotencyKey(string $reference, string $audience): string
{
    if (!in_array($audience, ['client', 'admin'], true)) throw new InvalidArgumentException('Invalid email audience.');
    return 'atlas-rentals-' . strtolower($reference) . '-' . $audience;
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
