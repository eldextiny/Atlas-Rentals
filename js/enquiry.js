const PAYLOAD_FIELDS = Object.freeze([
  "submissionId", "location", "deliveryAddress", "startDate", "endDate",
  "standardQuantity", "performanceQuantity", "technicianRequired",
  "technicianDays", "fullName", "organization", "email", "phone", "description",
]);

function text(value) {
  return String(value ?? "").trim().replace(/\s+/g, " ");
}

function integer(value) {
  const number = Number(value);
  return Number.isInteger(number) && number >= 0 ? number : value;
}

export function buildEnquiryPayload(form, submissionId) {
  const values = Object.fromEntries(new FormData(form));
  const technicianRequired = form.elements.technicianRequired.checked;
  return {
    submissionId,
    location: text(values.location),
    deliveryAddress: text(values.deliveryAddress),
    startDate: text(values.startDate),
    endDate: text(values.endDate),
    standardQuantity: integer(values.standardQuantity),
    performanceQuantity: integer(values.performanceQuantity),
    technicianRequired,
    technicianDays: technicianRequired ? integer(values.technicianDays) : 0,
    fullName: text(values.fullName),
    organization: text(values.organization),
    email: text(values.email).toLowerCase(),
    phone: text(values.phone),
    description: text(values.description),
  };
}

export function createSubmissionId(storage = globalThis.sessionStorage, crypto = globalThis.crypto, now = Date.now) {
  const key = "atlas-rentals-submission-id";
  const existing = storage?.getItem(key);
  if (/^[a-f0-9]{40}$/.test(existing || "")) return existing;
  if (!crypto?.getRandomValues) throw new Error("Secure enquiry submission is unavailable.");
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  const issuedAt = Math.floor(now() / 1000);
  if (!Number.isSafeInteger(issuedAt) || issuedAt < 1 || issuedAt > 0xffffffff) throw new Error("Secure enquiry submission is unavailable.");
  const timestamp = issuedAt.toString(16).padStart(8, "0");
  const random = [...bytes].map((byte) => byte.toString(16).padStart(2, "0")).join("");
  const identifier = timestamp + random;
  storage?.setItem(key, identifier);
  return identifier;
}

export function clearSubmissionId(storage = globalThis.sessionStorage) {
  storage?.removeItem("atlas-rentals-submission-id");
}

export function hasStablePayloadShape(payload) {
  return PAYLOAD_FIELDS.every((field) => Object.hasOwn(payload, field)) &&
    Object.keys(payload).every((field) => PAYLOAD_FIELDS.includes(field));
}

export function createSubmissionGuard(submit) {
  let inFlight = null;
  return (payload) => {
    if (inFlight) return inFlight;
    inFlight = Promise.resolve().then(() => submit(payload)).finally(() => {
      inFlight = null;
    });
    return inFlight;
  };
}
