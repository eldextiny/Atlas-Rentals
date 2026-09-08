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
    $checkedProduct = static function (int ...$values): int {
        $result = 1;
        foreach ($values as $value) {
            if ($value !== 0 && $result > intdiv(PHP_INT_MAX, $value)) throw new UnexpectedValueException('CRM payload monetary calculation exceeds the supported range.');
            $result *= $value;
        }
        return $result;
    };
    $checkedSum = static function (int ...$values): int {
        $result = 0;
        foreach ($values as $value) {
            if ($result > PHP_INT_MAX - $value) throw new UnexpectedValueException('CRM payload monetary calculation exceeds the supported range.');
            $result += $value;
        }
        return $result;
    };
    if (!is_string($reference) || preg_match('/^ARQ-\d{4}-\d{6}$/', $reference) !== 1) throw new UnexpectedValueException('CRM payload requires a valid enquiry reference.');
    $standardQuantity = $requiredInteger($data, 'standardQuantity');
    $performanceQuantity = $requiredInteger($data, 'performanceQuantity');
    if (($standardQuantity > 0) === ($performanceQuantity > 0)) throw new UnexpectedValueException('CRM payload requires exactly one laptop category.');
    $laptopQuantity = $checkedSum($standardQuantity, $performanceQuantity);
    $ratePlan = $requiredText($data, 'ratePlan');
    if ($ratePlan !== 'daily') throw new UnexpectedValueException('CRM payload requires the Daily Rate plan.');
    if (($pricing['ratePlan'] ?? null) !== $ratePlan || ($pricing['currency'] ?? null) !== 'NGN') throw new UnexpectedValueException('CRM payload pricing snapshot is inconsistent.');
    if (($pricing['standard']['quantity'] ?? null) !== $standardQuantity || ($pricing['performance']['quantity'] ?? null) !== $performanceQuantity) throw new UnexpectedValueException('CRM payload quantities are inconsistent.');
    $technicianRequired = $data['technicianRequired'] ?? null;
    if (!is_bool($technicianRequired)) throw new UnexpectedValueException('CRM payload requires valid technicianRequired.');
    $technicianQuantity = $requiredInteger($data, 'technicianQuantity');
    if (($technicianRequired && ($technicianQuantity < 1 || $technicianQuantity > 10)) || (!$technicianRequired && $technicianQuantity !== 0)) throw new UnexpectedValueException('CRM payload technician quantity is inconsistent.');
    if (($pricing['technicianQuantity'] ?? null) !== $technicianQuantity) throw new UnexpectedValueException('CRM payload technician pricing is inconsistent.');
    $email = $requiredText($data, 'email');
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new UnexpectedValueException('CRM payload requires a valid email.');
    $category = $standardQuantity > 0 ? 'Standard Business Laptop' : 'High Performance Laptop';
    $rentalDays = $requiredInteger($pricing, 'rentalDays');
    if ($rentalDays < 1) throw new UnexpectedValueException('CRM payload requires positive rentalDays.');
    $startDate = $requiredText($data, 'startDate'); $endDate = $requiredText($data, 'endDate');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) !== 1) throw new UnexpectedValueException('CRM payload requires valid rental dates.');
    $subtotal = $requiredInteger($pricing, 'subtotal'); $vat = $requiredInteger($pricing, 'vatAmount'); $total = $requiredInteger($pricing, 'estimatedTotal');
    if ($subtotal > PHP_INT_MAX - $vat || $subtotal + $vat !== $total) throw new UnexpectedValueException('CRM payload commercial totals are inconsistent.');
    $selectedPricing = $standardQuantity > 0 ? ($pricing['standard'] ?? null) : ($pricing['performance'] ?? null);
    if (!is_array($selectedPricing)) throw new UnexpectedValueException('CRM payload requires selected laptop pricing.');
    $dailyLaptopRate = $requiredInteger($selectedPricing, 'dailyRate');
    $laptopAmount = $requiredInteger($pricing, 'equipmentAmount');
    $technicianDays = $requiredInteger($data, 'technicianDays');
    $technicianDailyRate = $requiredInteger($pricing, 'technicianDailyRate');
    $technicianAmount = $requiredInteger($pricing, 'technicianAmount');
    $deliveryFee = $requiredInteger($pricing, 'deliveryFee');
    if (($technicianRequired && $technicianDays !== $rentalDays) || (!$technicianRequired && $technicianDays !== 0)) throw new UnexpectedValueException('CRM payload technician days are inconsistent.');
    if ($checkedProduct($laptopQuantity, $rentalDays, $dailyLaptopRate) !== $laptopAmount
        || $checkedProduct($technicianQuantity, $technicianDays, $technicianDailyRate) !== $technicianAmount
        || $checkedSum($laptopAmount, $technicianAmount, $deliveryFee) !== $subtotal) {
        throw new UnexpectedValueException('CRM payload component totals are inconsistent.');
    }
    return [
        'sourceModule' => 'Atlas Rental', 'documentType' => 'Laptop Rental Quotation',
        'documentReference' => $reference,
        'client' => ['organisation' => $requiredText($data, 'organization'), 'contactPerson' => $requiredText($data, 'fullName'), 'email' => $email, 'phone' => $requiredText($data, 'phone')],
        'title' => 'Laptop Rental Quotation', 'category' => $category,
        'serviceMode' => $requiredText($pricing, 'ratePlanLabel'), 'venue' => $requiredText($data, 'location'),
        'participants' => $laptopQuantity, 'durationValue' => $rentalDays, 'durationUnit' => 'days',
        'workingLanguages' => [],
        'commercial' => ['subtotalNgn' => $subtotal, 'vatNgn' => $vat, 'grandTotalNgn' => $total, 'currency' => 'NGN'],
        'pricingStatus' => 'Estimated', 'documentStatus' => 'Enquiry Received',
        'documentContext' => [
            'contractVersion' => '1', 'startDate' => $startDate, 'endDate' => $endDate, 'rentalDays' => $rentalDays,
            'laptopCategory' => $category, 'laptopQuantity' => $laptopQuantity,
            'ratePlan' => 'daily', 'dailyLaptopRate' => $dailyLaptopRate, 'laptopAmount' => $laptopAmount,
            'technicianRequired' => $technicianRequired, 'technicianQuantity' => $technicianQuantity, 'technicianDays' => $technicianDays,
            'technicianDailyRate' => $technicianDailyRate, 'technicianAmount' => $technicianAmount, 'deliveryFee' => $deliveryFee,
        ],
    ];
}

function atlasRentalsCrmHttpResult(string|false $body, int $status, int $curlErrorNumber): array
{
    $decoded = is_string($body) ? json_decode($body, true) : null;
    if ($body !== false && $curlErrorNumber === 0 && $status >= 200 && $status < 300 && is_array($decoded)) {
        return atlasRentalsSafeResult(true, 'CRM_ACCEPTED', false, ['attempted' => true]);
    }
    $category = $body === false || $curlErrorNumber !== 0 ? 'transport'
        : ($status >= 400 && $status < 500 ? 'http_4xx'
        : ($status >= 500 && $status < 600 ? 'http_5xx'
        : ($status >= 200 && $status < 300 ? 'invalid_response' : 'http_other')));
    $diagnostics = ['attempted' => true, 'httpStatus' => max(0, $status), 'curlErrorNumber' => max(0, $curlErrorNumber), 'errorCategory' => $category];
    return atlasRentalsSafeResult(false, 'CRM_REQUEST_FAILED', true, $diagnostics);
}

function atlasRentalsPostCrm(array $payload, array $config, ?callable $request = null, ?bool $curlAvailable = null): array
{
    if (!filter_var($config['crm_endpoint'] ?? '', FILTER_VALIDATE_URL)
        || ($config['crm_token'] ?? '') === '' || ($config['crm_source'] ?? '') === '' || ($config['crm_service'] ?? '') === '') {
        return atlasRentalsSafeResult(false, 'CRM_CONFIG_MISSING', true, ['attempted' => false, 'httpStatus' => 0, 'curlErrorNumber' => 0, 'errorCategory' => 'configuration']);
    }
    $curlAvailable ??= function_exists('curl_init');
    if (!$curlAvailable) return atlasRentalsSafeResult(false, 'CRM_HTTP_UNAVAILABLE', true, ['attempted' => false, 'httpStatus' => 0, 'curlErrorNumber' => 0, 'errorCategory' => 'runtime']);
    if ($request !== null) {
        $attempted = true;
        try {
            $response = $request($payload, $config);
            if (!is_array($response)) throw new UnexpectedValueException('CRM request seam returned an invalid result.');
            return atlasRentalsCrmHttpResult($response['body'] ?? false, (int)($response['status'] ?? 0), (int)($response['curlErrorNumber'] ?? 0));
        } catch (Throwable) {
            return atlasRentalsSafeResult(false, 'CRM_REQUEST_FAILED', true, ['attempted' => $attempted, 'httpStatus' => 0, 'curlErrorNumber' => 0, 'errorCategory' => 'transport']);
        }
    }
    $handle = curl_init($config['crm_endpoint']);
    if ($handle === false) return atlasRentalsSafeResult(false, 'CRM_HTTP_UNAVAILABLE', true, ['attempted' => false, 'httpStatus' => 0, 'curlErrorNumber' => 0, 'errorCategory' => 'runtime']);
    try {
        $configured = curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Atlas-CRM-Integration-Token: ' . $config['crm_token']],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        if (!$configured) return atlasRentalsSafeResult(false, 'CRM_HTTP_UNAVAILABLE', true, ['attempted' => false, 'httpStatus' => 0, 'curlErrorNumber' => 0, 'errorCategory' => 'runtime']);
        $attempted = false;
        try {
            $attempted = true;
            $body = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $curlErrorNumber = curl_errno($handle);
            return atlasRentalsCrmHttpResult($body, $status, $curlErrorNumber);
        } catch (Throwable) {
            return atlasRentalsSafeResult(false, 'CRM_REQUEST_FAILED', true, ['attempted' => $attempted, 'httpStatus' => 0, 'curlErrorNumber' => max(0, curl_errno($handle)), 'errorCategory' => 'transport']);
        }
    } catch (Throwable) {
        return atlasRentalsSafeResult(false, 'CRM_HTTP_UNAVAILABLE', true, ['attempted' => false, 'httpStatus' => 0, 'curlErrorNumber' => 0, 'errorCategory' => 'runtime']);
    } finally {
        curl_close($handle);
    }
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
    if (!is_string($reference) || preg_match('/^ARQ-\d{4}-\d{6}$/D', $reference) !== 1) {
        throw new UnexpectedValueException('CRM synchronization requires an allocated enquiry reference.');
    }
    $fingerprint = hash('sha256', $preview['canonical'] . '|' . $reference);
    return atlasRentalsWithState($config['state_path'], 'CRM-' . $reference, function (array $state) use ($preview, $reference, $config, $fingerprint, $poster): array {
        if (($state['crm']['fingerprint'] ?? '') === $fingerprint && ($state['crm']['status'] ?? '') === 'completed') return $state;
        $payload = atlasRentalsCrmPayload($preview, $reference, $config);
        $result = $poster ? $poster($payload, $config) : atlasRentalsPostCrm($payload, $config);
        $crm = ['fingerprint' => $fingerprint, 'status' => $result['ok'] ? 'completed' : 'pending', 'code' => $result['code']];
        if (!$result['ok']) {
            $previousAttempts = $state['crm']['attemptCount'] ?? 0;
            $previousAttempts = is_int($previousAttempts) && $previousAttempts >= 0 ? $previousAttempts : 0;
            $attempted = ($result['attempted'] ?? null) === true;
            $crm['attemptCount'] = $attempted ? min(PHP_INT_MAX, $previousAttempts + 1) : $previousAttempts;
            if ($attempted) $crm['attemptedAt'] = gmdate('c');
            elseif (is_string($state['crm']['attemptedAt'] ?? null)) $crm['attemptedAt'] = $state['crm']['attemptedAt'];
            $crm['httpStatus'] = is_int($result['httpStatus'] ?? null) && $result['httpStatus'] >= 100 && $result['httpStatus'] <= 599 ? $result['httpStatus'] : 0;
            $crm['curlErrorNumber'] = is_int($result['curlErrorNumber'] ?? null) ? max(0, $result['curlErrorNumber']) : 0;
            $category = $result['errorCategory'] ?? null;
            $crm['errorCategory'] = is_string($category) && in_array($category, ['configuration', 'runtime', 'transport', 'http_4xx', 'http_5xx', 'invalid_response', 'http_other'], true)
                ? $category : 'unknown';
        }
        $state['crm'] = $crm;
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
    $crmState = [];
    try { $crmState = ($adapters['crm'] ?? 'atlasRentalsSyncCrm')($preview, $record['enquiry_reference'], $config); } catch (Throwable) {}
    $delivery = atlasRentalsWithState($config['state_path'], $record['enquiry_reference'], function (array $state) use ($record, $config, $adapters): array {
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
    $delivery['crm'] = is_array($crmState['crm'] ?? null)
        ? $crmState['crm']
        : ['status' => 'pending', 'code' => 'CRM_SYNC_FAILED'];
    return $delivery;
}
