import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import { PHONE_VALIDATION_MESSAGE, normalizePhoneNumber, phoneCountryOptions } from "../js/enquiry.js";

const fixtures = JSON.parse(await readFile(new URL("./fixtures/phone-numbers.json", import.meta.url), "utf8"));

test("browser libphonenumber normalizes every approved regional fixture to E.164", () => {
  for (const fixture of fixtures.valid) {
    assert.equal(normalizePhoneNumber(fixture.input, fixture.region), fixture.e164, `${fixture.region}: ${fixture.input}`);
  }
});

test("browser libphonenumber rejects malformed, impossible and mismatched fixtures", () => {
  for (const fixture of fixtures.invalid) {
    assert.equal(normalizePhoneNumber(fixture.input, fixture.region), null, `${fixture.region}: ${fixture.input}`);
  }
});

test("international input overrides the selected country", () => {
  assert.equal(normalizePhoneNumber("+44 20 7183 8750", "NG"), "+442071838750");
});

test("country options include every metadata region with ISO and calling codes", () => {
  const options = phoneCountryOptions((country) => country);
  assert.ok(options.length > 200);
  for (const region of ["NG", "GH", "GB", "US", "FR", "DE", "IN", "ZA"]) {
    const option = options.find(({ country }) => country === region);
    assert.ok(option, `${region} missing`);
    assert.match(option.callingCode, /^\d{1,3}$/);
  }
});

test("the coordinated invalid-number message remains exact", () => {
  assert.equal(PHONE_VALIDATION_MESSAGE, "Enter a valid phone number for the selected country, or include the full international number beginning with +.");
});
