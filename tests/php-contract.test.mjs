import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const service = readFileSync(new URL("../api/enquiry-service.php", import.meta.url), "utf8");
const endpoint = readFileSync(new URL("../api/submit-enquiry.php", import.meta.url), "utf8");

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
  assert.match(endpoint, /getenv\('ATLAS_RENTALS_DB_CONFIG'\)/);
  assert.match(endpoint, /private_html\/atlas-rentals-db\.php/);
  assert.match(endpoint, /PDO::ATTR_EMULATE_PREPARES => false/);
  assert.doesNotMatch(endpoint, /(?:DB_PASSWORD|password)\s*=\s*['"][^'"]+['"]/i);
});

test("server pricing retains every protected rate", () => {
  assert.match(service, /STANDARD_RATE = 10000/);
  assert.match(service, /PERFORMANCE_RATE = 15000/);
  assert.match(service, /DELIVERY_FEE = 40000/);
  assert.match(service, /TECHNICIAN_RATE = 35000/);
  assert.match(service, /VAT_RATE = 0\.075/);
});
