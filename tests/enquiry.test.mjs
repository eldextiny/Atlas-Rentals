import test from "node:test";
import assert from "node:assert/strict";
import { buildEnquiryPayload, clearSubmissionId, createSubmissionGuard, createSubmissionId, hasStablePayloadShape } from "../js/enquiry.js";

const payload = {
  submissionId: "000003e80123456789abcdef0123456789abcdef",
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
  const result = buildEnquiryPayload(form, payload.submissionId);
  globalThis.FormData = originalFormData;
  assert.equal(result.email, "ada@example.com");
  assert.equal(result.technicianDays, 0);
  assert.equal(payload.email, "ada@example.com");
});

test("submission identifier is collision-resistant, stable and explicitly reset", () => {
  const values = new Map();
  const storage = { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: (key) => values.delete(key) };
  let calls = 0;
  const crypto = { getRandomValues(bytes) { calls += 1; bytes.fill(calls); return bytes; } };
  const first = createSubmissionId(storage, crypto, () => 1_000_000);
  assert.equal(first, "000003e8" + "01".repeat(16));
  assert.equal(createSubmissionId(storage, crypto, () => 2_000_000), first);
  assert.equal(calls, 1);
  clearSubmissionId(storage);
  assert.equal(createSubmissionId(storage, crypto, () => 2_000_000), "000007d0" + "02".repeat(16));
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
