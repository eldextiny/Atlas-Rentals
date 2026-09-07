import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const service = readFileSync(new URL("../api/enquiry-service.php", import.meta.url), "utf8");
const serverPricing = readFileSync(new URL("../api/rentals-pricing.php", import.meta.url), "utf8");
const endpoint = readFileSync(new URL("../api/submit-enquiry.php", import.meta.url), "utf8");
const runtime = readFileSync(new URL("../api/integration-runtime.php", import.meta.url), "utf8");
const emailTemplate = readFileSync(new URL("../api/rentals-email-template.php", import.meta.url), "utf8");
const pdfTemplate = readFileSync(new URL("../api/document-engine/templates/rentals-quotation.php", import.meta.url), "utf8");
const pngHelper = readFileSync(new URL("../api/document-engine/pdf-png.php", import.meta.url), "utf8");
const submitEndpoint = readFileSync(new URL("../api/submit-enquiry.php", import.meta.url), "utf8");
const downloadEndpoint = readFileSync(new URL("../api/download-quotation.php", import.meta.url), "utf8");
const journeyIdentifier = readFileSync(new URL("../api/journey-identifier.php", import.meta.url), "utf8");
const reviewEndpoint = readFileSync(new URL("../api/review-enquiry.php", import.meta.url), "utf8");
const rateLimit = readFileSync(new URL("../api/rate-limit.php", import.meta.url), "utf8");
const privatePath = readFileSync(new URL("../api/private-path.php", import.meta.url), "utf8");
const technicianMigration = readFileSync(new URL("../docs/migrations/2026-09-05-technician-quantity.sql", import.meta.url), "utf8");

test("PDO store uses prepared statements and one locked transaction", () => {
  assert.match(service, /beginTransaction\(\)/);
  assert.match(service, /SELECT last_sequence[\s\S]*FOR UPDATE/);
  assert.match(service, /UPDATE atlas_rental_reference_counters/);
  assert.match(service, /INSERT INTO atlas_rental_enquiries/);
  assert.match(service, /rollBack\(\)/);
  assert.match(service, /->prepare\(/);
});

test("backend never creates, alters, truncates or drops schema", () => {
  assert.doesNotMatch(service + endpoint, /\b(?:CREATE|ALTER|DROP|TRUNCATE)\s+TABLE\b/i);
});

test("endpoint uses only the private configurable loader contract", () => {
  const database = readFileSync(new URL("../api/database-runtime.php", import.meta.url), "utf8");
  assert.match(database, /atlasRentalsPrivatePath\('ATLAS_RENTALS_DB_CONFIG', 'atlas-rentals-db\.php', 'file'\)/);
  assert.match(database, /PDO::ATTR_EMULATE_PREPARES => false/);
  assert.doesNotMatch(endpoint, /(?:DB_PASSWORD|password)\s*=\s*['"][^'"]+['"]/i);
});

test("private defaults are application-relative isolated and fail closed", () => {
  const runtimeSources = [privatePath, readFileSync(new URL("../api/database-runtime.php", import.meta.url), "utf8"), runtime, journeyIdentifier, rateLimit, readFileSync(new URL("../tools/configure-whatsapp.php", import.meta.url), "utf8")].join("\n");
  assert.doesNotMatch(runtimeSources, /ezgshksprf|vfajfrnkst/);
  assert.match(privatePath, /strtolower\(basename\(\$public\)\) !== 'public_html'/);
  assert.match(privatePath, /dirname\(\$public\) \. DIRECTORY_SEPARATOR \. 'private_html'/);
  assert.match(privatePath, /\$override !== false && \$override !== ''/);
  assert.match(privatePath, /atlasRentalsPathIsWithin\(\$resolved, \$public\)/);
  assert.match(privatePath, /realpath\(\$candidate\)/);
  assert.doesNotMatch(privatePath, /glob\(|cloudwaysapps/);
  for (const environment of ['ATLAS_RENTALS_DB_CONFIG', 'ATLAS_RENTALS_INTEGRATIONS_CONFIG', 'ATLAS_RENTALS_DELIVERY_STATE_PATH', 'ATLAS_RENTALS_PDF_PATH', 'ATLAS_RENTALS_JOURNEY_STATE_PATH', 'ATLAS_RENTALS_RATE_LIMIT_STATE_PATH']) assert.match(runtimeSources, new RegExp(environment));
});

test("delivery runtime has ordered resumable PDF and recipient operations", () => {
  assert.match(runtime, /atlasRentalsGeneratePdf[\s\S]*\['client', 'admin'\]/);
  assert.match(runtime, /atlasRentalsEmailIdempotencyKey\(\$reference, \$audience\)/);
  assert.match(runtime, /quotation-pdfs/);
  assert.match(runtime, /delivery-state/);
  assert.match(runtime, /ATLAS_RENTALS_PDF_PRESENTATION_VERSION/);
  assert.match(runtime, /'html' => \$message\['html'\], 'text' => \$message\['text'\]/);
  assert.match(runtime, /atlasRentalsBuildEmail\(\$record, \$audience, \$config\)/);
  assert.match(runtime, /\['client', 'admin'\]/);
  assert.match(runtime, /attachments/);
});

test("submission exposes a tokenized PDF capability without private paths", () => {
  assert.match(submitEndpoint, /atlasRentalsPdfCapability\(\$delivery, \$result\['reference'\]\)/);
  assert.match(runtime, /bin2hex\(random_bytes\(32\)\)/);
  assert.match(runtime, /downloadToken/);
  assert.match(runtime, /\/api\/download-quotation\.php\?/);
  assert.doesNotMatch(submitEndpoint, /pdf_path|state_path|ATLAS_RENTALS_PDF_STORAGE/);
});

test("quotation download endpoint is GET-only, generic, confined and side-effect free", () => {
  assert.match(downloadEndpoint, /REQUEST_METHOD[^\n]+GET/);
  assert.match(downloadEndpoint, /header\('Allow: GET'\)/);
  assert.match(downloadEndpoint, /http_response_code\(405\)/);
  assert.match(downloadEndpoint, /http_response_code\(404\)/);
  assert.match(downloadEndpoint, /Content-Type: application\/pdf/);
  assert.match(downloadEndpoint, /Content-Disposition: attachment/);
  assert.match(downloadEndpoint, /Cache-Control: private, no-store/);
  assert.match(downloadEndpoint, /X-Content-Type-Options: nosniff/);
  assert.match(runtime, /hash_equals\(\$storedToken, \$token\)/);
  assert.match(runtime, /realpath\(\$directory\)/);
  assert.match(runtime, /dirname\(\$candidate\) !== \$base/);
  assert.match(runtime, /\$header === '%PDF-'/);
  assert.doesNotMatch(downloadEndpoint, /atlasRentalsDeliver|atlasRentalsSyncCrm|atlasRentalsSendEmail|EnquiryService|PdoEnquiryStore/);
});

test("CRM transport uses the receiver integration-token header, never Bearer auth", () => {
  const crmTransport = runtime.match(/function atlasRentalsPostCrm[\s\S]*?\n\}/)?.[0] || "";
  assert.match(crmTransport, /'X-Atlas-CRM-Integration-Token: ' \. \$config\['crm_token'\]/);
  assert.doesNotMatch(crmTransport, /Authorization:\s*Bearer/);
});

test("CRM diagnostics are application-owned, private and out of the public response", () => {
  assert.match(runtime, /'attempted'\s*=>\s*(?:true|false|\$attempted)/);
  assert.match(runtime, /'httpStatus'\s*=>/);
  assert.match(runtime, /'curlErrorNumber'\s*=>/);
  assert.match(runtime, /'errorCategory'\s*=>/);
  assert.match(runtime, /'attemptCount'\s*=>|\['attemptCount'\]/);
  assert.match(runtime, /'attemptedAt'\s*=>|\['attemptedAt'\]/);
  assert.doesNotMatch(submitEndpoint, /httpStatus|curlErrorNumber|errorCategory|attemptCount|attemptedAt|attempted/);
});

test("review defers CRM until final persistence allocates the authoritative reference", () => {
  assert.doesNotMatch(reviewEndpoint, /atlasRentalsSyncCrm|atlasRentalsDeliver|atlasRentalsCrmPayload/);
  assert.match(reviewEndpoint, /\$service->preview\(\$input\);[\s\S]*reviewRespond\(202, \['ok' => true, 'crm' => 'pending'\]\)/);
  assert.ok(submitEndpoint.indexOf('$service->submit($input)') < submitEndpoint.indexOf("$store->findByReference($result['reference'])"));
  assert.ok(submitEndpoint.indexOf("$store->findByReference($result['reference'])") < submitEndpoint.indexOf('atlasRentalsDeliver($record, $preview'));
  assert.match(runtime, /atlasRentalsSyncCrm[\s\S]*CRM synchronization requires an allocated enquiry reference/);
  assert.match(runtime, /atlasRentalsWithState\(\$config\['state_path'\], 'CRM-' \. \$reference/);
  assert.match(runtime, /atlasRentalsSyncCrm'\)\(\$preview, \$record\['enquiry_reference'\], \$config\)/);
  assert.match(submitEndpoint, /\$complete = \(\$delivery\['crm'\]\['status'\] \?\? ''\) === 'completed'/);
});

test("CRM payload builder uses only the deployed commercial-document field contract", () => {
  const builder = runtime.match(/function atlasRentalsCrmPayload[\s\S]*?\n\}/)?.[0] || "";
  for (const field of ["sourceModule", "documentType", "crm", "journeyId", "lifecycleStage", "document", "reference", "client", "organisation", "contactPerson", "eventTitle", "eventType", "serviceMode", "venue", "participants", "durationValue", "durationUnit", "workingLanguages", "subtotalNgn", "vatNgn", "grandTotalNgn", "pricingStatus", "documentStatus", "documentContext"]) assert.match(builder, new RegExp(`'${field}'\\s*=>`));
  assert.match(builder, /'sourceModule' => 'Atlas Rental'/);
  assert.match(builder, /'documentType' => 'Laptop Rental Quotation'/);
  assert.match(builder, /'crm' => \['journeyId' => 'atlas-rental-' \. \$reference, 'lifecycleStage' => 'quotation_generated'\]/);
  assert.match(builder, /'document' => \[[\s\S]*'reference' => \$reference/);
  assert.doesNotMatch(builder, /'documentReference'\s*=>/);
  for (const obsolete of ["enquiryReference' => \\$reference,\\n        'contact", "'estimate' =>", "'source' =>", "'service' =>", "'stage' =>"]) assert.doesNotMatch(builder, new RegExp(obsolete));
});

test("presentation templates are branded, escaped and Rentals-specific", () => {
  assert.match(emailTemplate, /htmlspecialchars\(\(string\)\$value, ENT_QUOTES \| ENT_SUBSTITUTE, 'UTF-8'\)/);
  assert.match(emailTemplate, /Laptop Rental Quotation/);
  assert.match(emailTemplate, /New ATLAS Rentals Enquiry/);
  assert.match(emailTemplate, /Delivery & retrieval/);
  assert.match(emailTemplate, /Standard rental service/);
  assert.match(pdfTemplate, /Standard rental service/);
  assert.match(emailTemplate, /Billable working days/);
  assert.match(pdfTemplate, /Billable working days/);
  assert.doesNotMatch(emailTemplate + pdfTemplate, /Inclusive duration|inclusive day\(s\)/i);
  assert.doesNotMatch(emailTemplate + pdfTemplate, /Compulsory service/);
  assert.match(emailTemplate, /Chat with us on WhatsApp/);
  assert.match(emailTemplate, /rawurlencode\(\$message\)/);
  assert.match(emailTemplate, /https:\/\/wa\.me\//);
  assert.match(emailTemplate, /\$audience === 'client'/);
  assert.match(pdfTemplate, /ATLAS_RENTALS_PDF_PRESENTATION_VERSION/);
  assert.match(pdfTemplate, /assets\/dyplus-logo\.png/);
  assert.match(pdfTemplate, /\/Im1 Do/);
  assert.match(pngHelper, /\/Subtype \/Image|atlasRentalsPdfLoadRgbaPng/);
  assert.match(pdfTemplate, /Laptop Rental Quotation/);
  assert.match(pdfTemplate, /Availability and Booking/);
  assert.doesNotMatch(emailTemplate + pdfTemplate, /sourceLanguage|targetLanguage|translation request/i);
});

test("WhatsApp destination is privately configured and never hardcoded", () => {
  const example = readFileSync(new URL("../api/integrations-config.example.php", import.meta.url), "utf8");
  assert.match(runtime, /'whatsapp_number' => 'ATLAS_RENTALS_WHATSAPP_NUMBER'/);
  assert.match(example, /'whatsapp_number' => 'international-digits-only'/);
  assert.doesNotMatch(runtime + emailTemplate + example, /(?:wa\.me\/|whatsapp_number'\s*=>\s*')[0-9]{8,}/i);
  assert.match(emailTemplate, /preg_replace\('\/\\D\+\/', '', \(string\)\$value\)/);
});

test("removed fields are absent from browser and server request contracts", () => {
  const enquiry = readFileSync(new URL("../js/enquiry.js", import.meta.url), "utf8");
  assert.doesNotMatch(enquiry, /deliveryAddress|description/);
  const allowed = service.match(/private const ALLOWED_FIELDS[\s\S]*?\];/)?.[0] || "";
  assert.doesNotMatch(allowed, /deliveryAddress|description/);
  assert.match(service, /'delivery_address' => null/);
  assert.match(service, /'description' => null/);
});

test("phone validation uses Composer metadata and preserves the existing downstream phone field", () => {
  const submit = readFileSync(new URL("../api/submit-enquiry.php", import.meta.url), "utf8");
  assert.match(service, /vendor\/autoload\.php/);
  assert.match(service, /PhoneNumberUtil::getInstance\(\)/);
  assert.match(service, /PhoneNumberFormat::E164/);
  assert.match(service, /'phoneCountry'/);
  assert.match(service, /'phone' => \$normalized\['phone'\]/);
  assert.doesNotMatch(service, /'phone_country'|phone_country/);
  assert.match(submit, /catch \(EnquiryValidationException \$error\)[\s\S]*respond\(422, \['ok' => false, 'error' => 'validation_failed', 'fields' => \$error->errors\]\)/);
});

test("server pricing retains every protected rate", () => {
  assert.match(serverPricing, /'dailyRate' => 10000/);
  assert.match(serverPricing, /'dailyRate' => 15000/);
  assert.match(serverPricing, /'deliveryFee' => 40000/);
  assert.match(serverPricing, /'technicianDailyRate' => 35000/);
  assert.match(serverPricing, /'vatRate' => 0\.075/);
});

test("technician selection derives authoritative working days and reaches every downstream surface", () => {
  assert.match(service, /\$value\['technicianDays'\] = \$value\['technicianRequired'\] \? \$rentalDays : 0/);
  assert.match(service, /'technician_required' => \$normalized\['technicianRequired'\] \? 1 : 0/);
  assert.match(service, /'technician_days' => \$normalized\['technicianDays'\]/);
  assert.match(service, /'technician_quantity' => \$normalized\['technicianQuantity'\]/);
  assert.match(serverPricing, /\$technicianAmount = \$technicianQuantity \* \(int\)\$normalized\['technicianDays'\] \* ATLAS_RENTALS_PRICING\['technicianDailyRate'\]/);
  assert.match(runtime, /'technicianRequired' => \$technicianRequired, 'technicianQuantity' => \$technicianQuantity, 'technicianDays'/);
  assert.match(emailTemplate, /technicianLabel.*technicianQuantity/);
  assert.match(pdfTemplate, /Technician - .*technicianLabel/);
});

test("technician quantity schema migration is explicit idempotent and nullable for historical inference", () => {
  assert.match(technicianMigration, /ALTER TABLE atlas_rental_enquiries/);
  assert.match(technicianMigration, /ADD COLUMN IF NOT EXISTS technician_quantity TINYINT UNSIGNED NULL/);
  assert.match(service, /'technician_quantity' => \$normalized\['technicianQuantity'\]/);
  assert.match(serverPricing, /technician_required.*\? 1 : 0/s);
});

test("journey identifiers use private check-before-cleanup expiry tombstones", () => {
  assert.match(journeyIdentifier, /atlasRentalsPrivatePath\('ATLAS_RENTALS_JOURNEY_STATE_PATH', 'atlas-rentals\/journey-identifiers', 'directory'\)/);
  assert.match(journeyIdentifier, /ATLAS_RENTALS_JOURNEY_STATE_PATH/);
  assert.match(journeyIdentifier, /hash\('sha256', \$identifier\)/);
  assert.match(journeyIdentifier, /if \(is_file\(\$path\)\)[\s\S]*JourneyIdentifierExpiredException[\s\S]*atlasRentalsCleanupJourneyTombstones/);
  assert.match(journeyIdentifier, /tempnam\([\s\S]*rename\(\$temporary, \$path\)/);
  assert.match(submitEndpoint, /JourneyIdentifierExpiredException[\s\S]*respond\(409, \['ok' => false, 'error' => 'journey_expired'\]\)/);
  assert.match(reviewEndpoint, /JourneyIdentifierExpiredException[\s\S]*reviewRespond\(409, \['ok' => false, 'error' => 'journey_expired'\]\)/);
  assert.match(journeyIdentifier, /\^j1\\\.\(\[a-f0-9\]\{8\}\)\\\.\(\[a-f0-9\]\{32\}\)\$/);
  assert.match(journeyIdentifier, /ATLAS_RENTALS_JOURNEY_FUTURE_TOLERANCE = 300/);
  assert.match(journeyIdentifier, /\$intrinsicExpiry = \$versioned \? \$issuedAt \+ \$ttl : null/);
  assert.match(journeyIdentifier, /elseif \(\$legacy\)[\s\S]*JourneyIdentifierExpiredException/);
  assert.match(journeyIdentifier, /elseif \(\$now >= \$intrinsicExpiry\)[\s\S]*'status' => 'expired'/);
});

test("private endpoint rate limits are independent authoritative and precede side effects", () => {
  assert.match(rateLimit, /atlasRentalsPrivatePath\('ATLAS_RENTALS_RATE_LIMIT_STATE_PATH', 'atlas-rentals\/rate-limits', 'directory'\)/);
  assert.match(rateLimit, /\['submission', 'review'\]/);
  assert.match(rateLimit, /filter_var\(\$clientAddress, FILTER_VALIDATE_IP\)/);
  assert.match(rateLimit, /hash\('sha256', \$clientAddress\)/);
  assert.match(rateLimit, /fopen\(\$path \. '\.lock', 'c\+'\)/);
  assert.match(rateLimit, /tempnam\(\$directory, 'limit\.tmp\.'\)[\s\S]*rename\(\$temporary, \$path\)/);
  assert.doesNotMatch(submitEndpoint + reviewEndpoint, /HTTP_X_FORWARDED_FOR|HTTP_FORWARDED|X-Forwarded-For|Forwarded/);
  assert.match(rateLimit, /function atlasRentalsEnforceRateLimit\([\s\S]*string \$clientAddress,[\s\S]*\?string \$directory = null,[\s\S]*int \$limit = 10,[\s\S]*int \$windowSeconds = 600,[\s\S]*\?Closure \$clock = null,[\s\S]*string \$namespace = 'submission'/);
  assert.match(submitEndpoint, /atlasRentalsEnforceRateLimit\([\s\S]*clientAddress: \(string\)\(\$_SERVER\['REMOTE_ADDR'\] \?\? ''\),[\s\S]*limit: 10,[\s\S]*windowSeconds: 600,[\s\S]*namespace: 'submission'/);
  assert.match(reviewEndpoint, /atlasRentalsEnforceRateLimit\([\s\S]*clientAddress: \(string\)\(\$_SERVER\['REMOTE_ADDR'\] \?\? ''\),[\s\S]*limit: 30,[\s\S]*windowSeconds: 600,[\s\S]*namespace: 'review'/);
  for (const endpointSource of [submitEndpoint, reviewEndpoint]) {
    assert.match(endpointSource, /header\('Retry-After: ' \. \$retryAfter\)/);
    assert.match(endpointSource, /429, \['ok' => false, 'error' => 'rate_limited'\]/);
    assert.match(endpointSource, /503, \['ok' => false, 'error' => 'submission_unavailable'\]/);
  }
  assert.ok(submitEndpoint.indexOf('atlasRentalsEnforceRateLimit(') < submitEndpoint.indexOf('atlasRentalsDatabase()'));
  assert.ok(reviewEndpoint.indexOf('atlasRentalsEnforceRateLimit(') < reviewEndpoint.indexOf('atlasRentalsDatabase()'));
  assert.doesNotMatch(reviewEndpoint, /atlasRentalsSyncCrm\(/);
  assert.ok(submitEndpoint.indexOf('atlasRentalsEnforceRateLimit(') < submitEndpoint.indexOf('atlasRentalsDeliver('));
});

test("new enquiries accept daily only while historical best is lookup-only", () => {
  const plans = serverPricing.match(/const ATLAS_RENTALS_RATE_PLANS = \[[\s\S]*?\];/)?.[0] || "";
  assert.match(plans, /'daily'/);
  assert.doesNotMatch(plans, /'weekly'|'monthly'/);
  assert.doesNotMatch(plans, /'best'|Best Available/i);
  const activeCalculator = serverPricing.match(/function atlasRentalsRatePlanUnitPrice[\s\S]*?\n\}/)?.[0] || "";
  assert.doesNotMatch(activeCalculator, /atlasRentalsHistoricalDecomposeDuration|best/i);
  assert.match(serverPricing, /function atlasRentalsHistoricalDecomposeDuration/);
  assert.match(service, /if \(\$input\['ratePlan'\] === 'best'\)[\s\S]*findByHash[\s\S]*throw new EnquiryValidationException/);
});

test("historical tier pricing requires persisted rates without restoring active plan rates", () => {
  const activePricing = serverPricing.match(/const ATLAS_RENTALS_PRICING = \[[\s\S]*?\n\];/)?.[0] || "";
  assert.doesNotMatch(activePricing, /weeklyRate|monthlyRate/);
  const historicalCalculator = serverPricing.match(/function atlasRentalsHistoricalTieredUnitPrice[\s\S]*?\n\}/)?.[0] || "";
  assert.match(historicalCalculator, /array_key_exists\(\$key, \$rates\)/);
  assert.match(historicalCalculator, /Historical tier pricing requires complete persisted rate values/);
  assert.match(historicalCalculator, /\$rates\['weeklyRate'\]/);
  assert.match(historicalCalculator, /\$rates\['monthlyRate'\]/);
});
