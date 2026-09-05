import test from "node:test";
import assert from "node:assert/strict";
import { buildEnquiryPayload, createJourneyId, createSubmissionGuard, hasStablePayloadShape, personalDetailsError } from "../js/enquiry.js";

const payload = {
  journeyId: "0123456789abcdef0123456789abcdef", location: "Lagos", startDate: "2026-08-05", ratePlan: "daily",
  endDate: "2026-08-06", standardQuantity: 5, performanceQuantity: 0,
  technicianRequired: false, technicianQuantity: 0, technicianDays: 0, fullName: "Ada User",
  organization: "Example Ltd", email: "ada@example.com", phoneCountry: "NG", phone: "+2348028557479",
};

test("valid enquiry payload has the stable server contract", () => {
  assert.equal(hasStablePayloadShape(payload), true);
  assert.equal(hasStablePayloadShape({ ...payload, total: 1 }), false);
  assert.equal(hasStablePayloadShape((({ phone, ...rest }) => rest)(payload)), false);
  assert.equal(hasStablePayloadShape({ ...payload, ratePlan: "best" }), false);
  assert.equal(hasStablePayloadShape({ ...payload, ratePlan: "weekly" }), false);
  assert.equal(hasStablePayloadShape({ ...payload, ratePlan: "monthly" }), false);
});

test("new journey identifiers are versioned timestamp-bound and use 128 random bits", () => {
  const values = new Map();
  const storage = { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) };
  const crypto = { getRandomValues: (bytes) => { bytes.forEach((_, index) => { bytes[index] = index + 1; }); } };
  const identifier = createJourneyId(storage, crypto, () => 1_700_000_000_000);
  assert.equal(identifier, "j1.6553f100.0102030405060708090a0b0c0d0e0f10");
  assert.equal(values.get("atlas-rentals-journey-id"), identifier);
});

test("existing legacy journey remains unchanged while all new generation requires Web Crypto", () => {
  const legacy = "0123456789abcdef0123456789abcdef";
  assert.equal(createJourneyId({ getItem: () => legacy }, null), legacy);
  assert.throws(() => createJourneyId({ getItem: () => null }, null), /Secure enquiry session is unavailable/);
  assert.throws(() => createJourneyId({ getItem: () => null }, { getRandomValues: (bytes) => bytes.fill(0) }, () => 1_700_000_000_000), /Secure enquiry session is unavailable/);
});

test("payload creation normalizes a copy without mutating entered values", () => {
  const originalFormData = globalThis.FormData;
  const enteredPhone = "0802 855 7479";
  globalThis.FormData = class {
    constructor() {}
    *[Symbol.iterator]() { yield* Object.entries({ ...payload, serviceCity: "Lagos", laptopCategory: "performance", laptopQuantity: 5, email: " ADA@Example.COM ", phone: enteredPhone }); }
  };
  const form = { elements: { technicianRequired: { checked: false } } };
  const result = buildEnquiryPayload(form, payload.journeyId);
  globalThis.FormData = originalFormData;
  assert.equal(result.email, "ada@example.com");
  assert.equal(result.standardQuantity, 0);
  assert.equal(result.performanceQuantity, 5);
  assert.equal(result.technicianDays, 0);
  assert.equal(result.technicianQuantity, 0);
  assert.equal(result.ratePlan, "daily");
  assert.equal(result.phoneCountry, "NG");
  assert.equal(result.phone, "+2348028557479");
  assert.equal(enteredPhone, "0802 855 7479");
  assert.equal(payload.email, "ada@example.com");
});

test("selected technician quantity is included without reusing technician days", () => {
  const originalFormData = globalThis.FormData;
  globalThis.FormData = class { *[Symbol.iterator]() { yield* Object.entries({ ...payload, serviceCity: "Lagos", laptopCategory: "standard", laptopQuantity: 5, technicianQuantity: 3, technicianDays: 5 }); } };
  const result = buildEnquiryPayload({ elements: { technicianRequired: { checked: true } } }, payload.journeyId);
  globalThis.FormData = originalFormData;
  assert.equal(result.technicianQuantity, 3);
  assert.equal(result.technicianDays, 5);
});

test("single category maps to the stable two-quantity backend contract", () => {
  const originalFormData = globalThis.FormData;
  const form = { elements: { technicianRequired: { checked: false } } };
  globalThis.FormData = class { *[Symbol.iterator]() { yield* Object.entries({ ...payload, serviceCity: "Abuja", laptopCategory: "standard", laptopQuantity: 7 }); } };
  const standard = buildEnquiryPayload(form, payload.journeyId);
  globalThis.FormData = class { *[Symbol.iterator]() { yield* Object.entries({ ...payload, serviceCity: "Lagos", laptopCategory: "performance", laptopQuantity: 8 }); } };
  const performance = buildEnquiryPayload(form, payload.journeyId);
  globalThis.FormData = originalFormData;
  assert.deepEqual([standard.standardQuantity, standard.performanceQuantity], [7, 0]);
  assert.deepEqual([performance.standardQuantity, performance.performanceQuantity], [0, 8]);
  assert.equal(hasStablePayloadShape(standard), true);
  assert.equal(hasStablePayloadShape(performance), true);
});

test("service-city choices map into the unchanged location field", () => {
  const originalFormData = globalThis.FormData;
  const form = { elements: { technicianRequired: { checked: false } } };
  const build = (serviceCity, customCity = "") => {
    globalThis.FormData = class { *[Symbol.iterator]() { yield* Object.entries({ ...payload, serviceCity, customCity, laptopCategory: "standard", laptopQuantity: 5 }); } };
    return buildEnquiryPayload(form, payload.journeyId);
  };
  assert.equal(build("Abuja").location, "Abuja");
  assert.equal(build("Lagos").location, "Lagos");
  assert.equal(build("Others", "  Port Harcourt  ").location, "Port Harcourt");
  globalThis.FormData = originalFormData;
});

test("personal details return one exact field-specific message", () => {
  assert.equal(personalDetailsError("fullName", ""), "Enter your full name.");
  assert.equal(personalDetailsError("organization", "  "), "Enter your organisation name.");
  assert.equal(personalDetailsError("email", ""), "Enter your email address.");
  assert.equal(personalDetailsError("email", "not-email", { typeMismatch: true }), "Enter a valid email address.");
  assert.equal(personalDetailsError("phone", ""), "Enter your phone number.");
  assert.equal(personalDetailsError("phone", "123", {}, "NG"), "Enter a valid phone number for the selected country, or include the full international number beginning with +.");
  assert.equal(personalDetailsError("email", "ada@example.com", { typeMismatch: false }), "");
  assert.equal(personalDetailsError("phone", "+2348028557479", {}, "US"), "");
});

test("in-flight double submission returns one request promise", async () => {
  let calls = 0;
  let release;
  const pending = new Promise((resolve) => { release = resolve; });
  const guarded = createSubmissionGuard(async () => { calls += 1; await pending; return "ARQ-2026-000001"; });
  const first = guarded(payload);
  const second = guarded(payload);
  assert.equal(first, second);
  assert.equal(calls, 0);
  await Promise.resolve();
  assert.equal(calls, 1);
  release();
  assert.equal(await first, "ARQ-2026-000001");
});
