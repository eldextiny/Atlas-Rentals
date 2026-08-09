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
  assert.match(database, /getenv\('ATLAS_RENTALS_DB_CONFIG'\)/);
  assert.match(database, /private_html\/atlas-rentals-db\.php/);
  assert.match(database, /PDO::ATTR_EMULATE_PREPARES => false/);
  assert.doesNotMatch(endpoint, /(?:DB_PASSWORD|password)\s*=\s*['"][^'"]+['"]/i);
});

test("delivery runtime has ordered resumable PDF and recipient operations", () => {
  assert.match(runtime, /atlasRentalsGeneratePdf[\s\S]*\['client', 'admin'\]/);
  assert.match(runtime, /atlasRentalsEmailIdempotencyKey\(\$reference, \$audience\)/);
  assert.match(runtime, /quotation-pdfs/);
  assert.match(runtime, /delivery-state/);
  assert.match(runtime, /ATLAS_RENTALS_PDF_PRESENTATION_VERSION/);
  assert.match(runtime, /'html' => \$message\['html'\], 'text' => \$message\['text'\]/);
  assert.match(runtime, /\['client', 'admin'\]/);
  assert.match(runtime, /attachments/);
});

test("CRM transport uses the receiver integration-token header, never Bearer auth", () => {
  const crmTransport = runtime.match(/function atlasRentalsPostCrm[\s\S]*?\n\}/)?.[0] || "";
  assert.match(crmTransport, /'X-Atlas-CRM-Integration-Token: ' \. \$config\['crm_token'\]/);
  assert.doesNotMatch(crmTransport, /Authorization:\s*Bearer/);
});

test("CRM payload builder uses only the deployed commercial-document field contract", () => {
  const builder = runtime.match(/function atlasRentalsCrmPayload[\s\S]*?\n\}/)?.[0] || "";
  for (const field of ["sourceModule", "documentType", "documentReference", "client", "organisation", "contactPerson", "title", "category", "serviceMode", "venue", "durationValue", "durationUnit", "commercial", "subtotalNgn", "vatNgn", "grandTotalNgn", "documentContext"]) assert.match(builder, new RegExp(`'${field}'\\s*=>`));
  assert.match(builder, /'sourceModule' => 'Atlas Rental'/);
  assert.match(builder, /'documentType' => 'Laptop Rental Quotation'/);
  for (const obsolete of ["journeyId", "enquiryReference' => \\$reference,\\n        'contact", "'estimate' =>", "'source' =>", "'service' =>", "'stage' =>"]) assert.doesNotMatch(builder, new RegExp(obsolete));
});

test("presentation templates are branded, escaped and Rentals-specific", () => {
  assert.match(emailTemplate, /htmlspecialchars\(\(string\)\$value, ENT_QUOTES \| ENT_SUBSTITUTE, 'UTF-8'\)/);
  assert.match(emailTemplate, /Laptop Rental Quotation/);
  assert.match(emailTemplate, /New ATLAS Rentals Enquiry/);
  assert.match(emailTemplate, /Delivery & retrieval/);
  assert.match(pdfTemplate, /ATLAS_RENTALS_PDF_PRESENTATION_VERSION/);
  assert.match(pdfTemplate, /assets\/dyplus-logo\.png/);
  assert.match(pdfTemplate, /\/Im1 Do/);
  assert.match(pngHelper, /\/Subtype \/Image|atlasRentalsPdfLoadRgbaPng/);
  assert.match(pdfTemplate, /Laptop Rental Quotation/);
  assert.match(pdfTemplate, /Availability and Booking/);
  assert.doesNotMatch(emailTemplate + pdfTemplate, /sourceLanguage|targetLanguage|translation request/i);
});

test("removed fields are absent from browser and server request contracts", () => {
  const enquiry = readFileSync(new URL("../js/enquiry.js", import.meta.url), "utf8");
  assert.doesNotMatch(enquiry, /deliveryAddress|description/);
  const allowed = service.match(/private const ALLOWED_FIELDS[\s\S]*?\];/)?.[0] || "";
  assert.doesNotMatch(allowed, /deliveryAddress|description/);
  assert.match(service, /'delivery_address' => null/);
  assert.match(service, /'description' => null/);
});

test("server pricing retains every protected rate", () => {
  assert.match(serverPricing, /'dailyRate' => 10000, 'weeklyRate' => 59500, 'monthlyRate' => 185000/);
  assert.match(serverPricing, /'dailyRate' => 15000, 'weeklyRate' => 89500, 'monthlyRate' => 225500/);
  assert.match(serverPricing, /'deliveryFee' => 40000/);
  assert.match(serverPricing, /'technicianDailyRate' => 35000/);
  assert.match(serverPricing, /'vatRate' => 0\.075/);
});
