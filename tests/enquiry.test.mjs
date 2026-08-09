import test from "node:test";
import assert from "node:assert/strict";
import { buildEnquiryPayload, createSubmissionGuard, hasStablePayloadShape, personalDetailsError } from "../js/enquiry.js";

const payload = {
  journeyId: "0123456789abcdef0123456789abcdef", location: "Lagos", startDate: "2026-08-05", ratePlan: "best",
  endDate: "2026-08-06", standardQuantity: 5, performanceQuantity: 0,
  technicianRequired: false, technicianDays: 0, fullName: "Ada User",
  organization: "Example Ltd", email: "ada@example.com", phone: "+2348000000000",
};

test("valid enquiry payload has the stable server contract", () => {
  assert.equal(hasStablePayloadShape(payload), true);
  assert.equal(hasStablePayloadShape({ ...payload, total: 1 }), false);
  assert.equal(hasStablePayloadShape((({ phone, ...rest }) => rest)(payload)), false);
});

test("payload creation normalizes a copy without mutating entered values", () => {
  const originalFormData = globalThis.FormData;
  globalThis.FormData = class {
    constructor() {}
    *[Symbol.iterator]() { yield* Object.entries({ ...payload, serviceCity: "Lagos", laptopCategory: "performance", laptopQuantity: 5, email: " ADA@Example.COM " }); }
  };
  const form = { elements: { technicianRequired: { checked: false } } };
  const result = buildEnquiryPayload(form, payload.journeyId);
  globalThis.FormData = originalFormData;
  assert.equal(result.email, "ada@example.com");
  assert.equal(result.standardQuantity, 0);
  assert.equal(result.performanceQuantity, 5);
  assert.equal(result.technicianDays, 0);
  assert.equal(result.ratePlan, "best");
  assert.equal(payload.email, "ada@example.com");
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
  assert.equal(personalDetailsError("phone", "123", { patternMismatch: true }), "Enter a valid phone number.");
  assert.equal(personalDetailsError("email", "ada@example.com", { typeMismatch: false }), "");
  assert.equal(personalDetailsError("phone", "+2348028557479", { patternMismatch: false }), "");
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
