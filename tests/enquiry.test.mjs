import test from "node:test";
import assert from "node:assert/strict";
import { buildEnquiryPayload, createSubmissionGuard, hasStablePayloadShape } from "../js/enquiry.js";

const payload = {
  location: "Lagos", deliveryAddress: "12 Marina Road", startDate: "2026-08-05",
  endDate: "2026-08-06", standardQuantity: 5, performanceQuantity: 0,
  technicianRequired: false, technicianDays: 0, fullName: "Ada User",
  organization: "Example Ltd", email: "ada@example.com", phone: "+2348000000000",
  description: "Training",
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
    *[Symbol.iterator]() { yield* Object.entries({ ...payload, email: " ADA@Example.COM " }); }
  };
  const form = { elements: { technicianRequired: { checked: false } } };
  const result = buildEnquiryPayload(form);
  globalThis.FormData = originalFormData;
  assert.equal(result.email, "ada@example.com");
  assert.equal(result.technicianDays, 0);
  assert.equal(payload.email, "ada@example.com");
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
