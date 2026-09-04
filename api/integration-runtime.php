<?php
declare(strict_types=1);

require_once __DIR__ . '/rentals-email-template.php';
require_once __DIR__ . '/document-engine/templates/rentals-quotation.php';
require_once __DIR__ . '/private-path.php';

const ATLAS_RENTALS_MAX_ATTACHMENT = 8388608;

function atlasRentalsIntegrationConfig(): array
{
    $path = atlasRentalsPrivatePath('ATLAS_RENTALS_INTEGRATIONS_CONFIG', 'atlas-rentals-integrations.php', 'file');
    if (!is_readable($path)) throw new RuntimeException('Integration configuration unavailable.');
    $private = require $path;
    if (!is_array($private)) throw new RuntimeException('Integration configuration invalid.');
    $map = [
        'resend_api_key' => 'ATLAS_RENTALS_RESEND_API_KEY', 'from_email' => 'ATLAS_RENTALS_FROM_EMAIL',
        'from_name' => 'ATLAS_RENTALS_FROM_NAME', 'reply_to' => 'ATLAS_RENTALS_REPLY_TO',
        'admin_email' => 'ATLAS_RENTALS_ADMIN_EMAIL', 'crm_endpoint' => 'ATLAS_RENTALS_CRM_ENDPOINT',
        'crm_token' => 'ATLAS_RENTALS_CRM_TOKEN', 'crm_source' => 'ATLAS_RENTALS_CRM_SOURCE',
        'crm_service' => 'ATLAS_RENTALS_CRM_SERVICE', 'whatsapp_number' => 'ATLAS_RENTALS_WHATSAPP_NUMBER',
    ];
    $result = [];
    foreach ($map as $key => $environment) {
        $value = getenv($environment);
        $result[$key] = trim((string)($value !== false ? $value : ($private[$key] ?? '')));
    }
    $result['state_path'] = atlasRentalsPrivatePath('ATLAS_RENTALS_DELIVERY_STATE_PATH', 'atlas-rentals/delivery-state', 'directory');
    $result['pdf_path'] = atlasRentalsPrivatePath('ATLAS_RENTALS_PDF_PATH', 'atlas-rentals/quotation-pdfs', 'directory');
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
    $data = is_array($preview['normalized'] ?? null) ? $preview['normalized'] : [];
    $pricing = is_array($preview['pricing'] ?? null) ? $preview['pricing'] : [];
    $requiredText = static function (array $source, string $key): string {
        $value = $source[$key] ?? null;
        if (!is_string($value) || trim($value) === '') throw new UnexpectedValueException("CRM payload requires {$key}.");
        return trim($value);
    };
    $requiredInteger = static function (array $source, string $key): int {
        $value = $source[$key] ?? null;
        if (!is_int($value) || $value < 0) throw new UnexpectedValueException("CRM payload requires valid {$key}.");
        return $value;
    };
    $requiredNumber = static function (array $source, string $key): int|float {
        $value = $source[$key] ?? null;
        if (!is_int($value) && !is_float($value) || $value < 0 || !is_finite((float)$value)) throw new UnexpectedValueException("CRM payload requires valid {$key}.");
        return $value;
    };
    if (!is_string($reference) || preg_match('/^ARQ-\d{4}-\d{6}$/', $reference) !== 1) throw new UnexpectedValueException('CRM payload requires a valid enquiry reference.');
    $standardQuantity = $requiredInteger($data, 'standardQuantity');
    $performanceQuantity = $requiredInteger($data, 'performanceQuantity');
    if (($standardQuantity > 0) === ($performanceQuantity > 0)) throw new UnexpectedValueException('CRM payload requires exactly one laptop category.');
    $ratePlan = $requiredText($data, 'ratePlan');
    // `best` is accepted here only to resume delivery from an authoritative historical pricing snapshot.
    if (!in_array($ratePlan, ['daily', 'weekly', 'monthly', 'best'], true)) throw new UnexpectedValueException('CRM payload rate plan is unsupported.');
    if (($pricing['ratePlan'] ?? null) !== $ratePlan || ($pricing['currency'] ?? null) !== 'NGN') throw new UnexpectedValueException('CRM payload pricing snapshot is inconsistent.');
    if (($pricing['standard']['quantity'] ?? null) !== $standardQuantity || ($pricing['performance']['quantity'] ?? null) !== $performanceQuantity) throw new UnexpectedValueException('CRM payload quantities are inconsistent.');
    $technicianRequired = $data['technicianRequired'] ?? null;
    if (!is_bool($technicianRequired)) throw new UnexpectedValueException('CRM payload requires valid technicianRequired.');
    $email = $requiredText($data, 'email');
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new UnexpectedValueException('CRM payload requires a valid email.');
    $category = $standardQuantity > 0 ? 'Standard Business Laptop' : 'High Performance Laptop';
    $rentalDays = $requiredInteger($pricing, 'rentalDays');
    if ($rentalDays < 1) throw new UnexpectedValueException('CRM payload requires positive rentalDays.');
    $startDate = $requiredText($data, 'startDate'); $endDate = $requiredText($data, 'endDate');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) !== 1) throw new UnexpectedValueException('CRM payload requires valid rental dates.');
    $subtotal = $requiredNumber($pricing, 'subtotal'); $vat = $requiredNumber($pricing, 'vatAmount'); $total = $requiredNumber($pricing, 'estimatedTotal');
    if (abs(($subtotal + $vat) - $total) > 0.001) throw new UnexpectedValueException('CRM payload commercial totals are inconsistent.');
    return [
        'sourceModule' => 'Atlas Rental', 'documentType' => 'Laptop Rental Quotation', 'documentReference' => $reference,
        'client' => ['organisation' => $requiredText($data, 'organization'), 'contactPerson' => $requiredText($data, 'fullName'), 'email' => $email, 'phone' => $requiredText($data, 'phone')],
        'title' => 'Laptop Rental Quotation', 'category' => $category,
        'serviceMode' => $requiredText($pricing, 'ratePlanLabel'), 'venue' => $requiredText($data, 'location'),
        'durationValue' => $rentalDays, 'durationUnit' => 'days',
        'commercial' => ['subtotalNgn' => $subtotal, 'vatNgn' => $vat, 'grandTotalNgn' => $total],
        'documentContext' => [
            'standardQuantity' => $standardQuantity, 'performanceQuantity' => $performanceQuantity,
            'technicianRequired' => $technicianRequired, 'technicianDays' => $requiredInteger($data, 'technicianDays'),
            'ratePlan' => $ratePlan, 'ratePlanLabel' => $requiredText($pricing, 'ratePlanLabel'),
            'startDate' => $startDate, 'endDate' => $endDate,
            'rentalDays' => $rentalDays, 'currency' => 'NGN', 'enquiryReference' => $reference,
        ],
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

function atlasRentalsPdfCapability(array $state, string $reference): array
{
    $pdf = is_array($state['pdf'] ?? null) ? $state['pdf'] : [];
    if (($pdf['status'] ?? '') !== 'completed') {
        return ['status' => ($pdf['status'] ?? '') === 'failed' ? 'failed' : 'pending', 'downloadUrl' => null];
    }
    $token = $pdf['downloadToken'] ?? null;
    if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
        return ['status' => 'failed', 'downloadUrl' => null];
    }
    return [
        'status' => 'available',
        'downloadUrl' => '/api/download-quotation.php?' . http_build_query(
            ['reference' => $reference, 'token' => $token],
            '',
            '&',
            PHP_QUERY_RFC3986
        ),
    ];
}

function atlasRentalsReadDeliveryState(string $directory, string $reference): array
{
    if (preg_match('/^ARQ-\d{4}-\d{6}$/D', $reference) !== 1) return [];
    try { $path = atlasRentalsStateFile($directory, $reference); }
    catch (Throwable) { return []; }
    if (!is_file($path) || !is_readable($path)) return [];
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function atlasRentalsResolveDownloadPdf(array $state, string $reference, string $token, string $directory): ?string
{
    if (preg_match('/^ARQ-\d{4}-\d{6}$/D', $reference) !== 1
        || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) return null;
    $pdf = is_array($state['pdf'] ?? null) ? $state['pdf'] : [];
    $storedToken = $pdf['downloadToken'] ?? null; $fingerprint = $pdf['fingerprint'] ?? null;
    if (($pdf['status'] ?? '') !== 'completed' || !is_string($storedToken)
        || preg_match('/^[a-f0-9]{64}$/D', $storedToken) !== 1 || !hash_equals($storedToken, $token)
        || !is_string($fingerprint) || preg_match('/^[a-f0-9]{16}$/D', $fingerprint) !== 1) return null;
    $base = realpath($directory);
    if ($base === false || !is_dir($base)) return null;
    $candidate = realpath($base . DIRECTORY_SEPARATOR . $reference . '-' . $fingerprint . '.pdf');
    if ($candidate === false || dirname($candidate) !== $base || !is_file($candidate) || !is_readable($candidate)) return null;
    $handle = fopen($candidate, 'rb');
    if ($handle === false) return null;
    $header = fread($handle, 5); fclose($handle);
    return $header === '%PDF-' ? $candidate : null;
}

function atlasRentalsSendEmail(array $record, array $pdf, string $audience, array $config): array
{
    $recipient = $audience === 'client' ? $record['email'] : $config['admin_email'];
    foreach (['resend_api_key', 'from_email', 'admin_email'] as $key) if (($config[$key] ?? '') === '') return atlasRentalsSafeResult(false, 'EMAIL_CONFIG_MISSING', true);
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || !function_exists('curl_init')) return atlasRentalsSafeResult(false, 'EMAIL_UNAVAILABLE', true);
    $reference = $record['enquiry_reference'];
    $subject = $audience === 'client' ? "We received your Atlas Rentals enquiry {$reference}" : "New Atlas Rentals enquiry {$reference}";
    $message = atlasRentalsBuildEmail($record, $audience, $config);
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
        $storedPdf = is_array($state['pdf'] ?? null) ? $state['pdf'] : [];
        $storedToken = $storedPdf['downloadToken'] ?? null;
        try {
            $resolved = is_string($storedToken)
                ? atlasRentalsResolveDownloadPdf($state, (string)$record['enquiry_reference'], $storedToken, $config['pdf_path'])
                : null;
            $pdf = $resolved === null
                ? ($adapters['pdf'] ?? 'atlasRentalsGeneratePdf')($record, $config['pdf_path'])
                : ['path' => $resolved, 'fingerprint' => $storedPdf['fingerprint']];
            $downloadToken = $storedToken;
            if (!is_string($downloadToken) || preg_match('/^[a-f0-9]{64}$/D', $downloadToken) !== 1) $downloadToken = bin2hex(random_bytes(32));
            $state['pdf'] = ['status' => 'completed', 'fingerprint' => $pdf['fingerprint'], 'downloadToken' => $downloadToken];
        } catch (Throwable) {
            $state['pdf'] = ['status' => 'failed'];
            if (is_string($storedToken) && preg_match('/^[a-f0-9]{64}$/D', $storedToken) === 1) $state['pdf']['downloadToken'] = $storedToken;
            if (is_string($storedPdf['fingerprint'] ?? null) && preg_match('/^[a-f0-9]{16}$/D', $storedPdf['fingerprint']) === 1) $state['pdf']['fingerprint'] = $storedPdf['fingerprint'];
            return $state;
        }
        foreach (['client', 'admin'] as $audience) {
            if (($state[$audience . 'Email']['status'] ?? '') === 'completed') continue;
            $result = ($adapters['email'] ?? 'atlasRentalsSendEmail')($record, $pdf, $audience, $config);
            $state[$audience . 'Email'] = ['status' => $result['ok'] ? 'completed' : 'pending', 'code' => $result['code']];
            if (!$result['ok']) break;
        }
        return $state;
    });
}
