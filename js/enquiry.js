const PAYLOAD_FIELDS = Object.freeze([
  "journeyId", "location", "startDate", "endDate", "ratePlan",
  "standardQuantity", "performanceQuantity", "technicianRequired",
  "technicianDays", "fullName", "organization", "email", "phone",
]);

function text(value) {
  return String(value ?? "").trim().replace(/\s+/g, " ");
}

function integer(value) {
  const number = Number(value);
  return Number.isInteger(number) && number >= 0 ? number : value;
}

export function personalDetailsError(name, value, validity = {}) {
  const empty = String(value ?? "").trim() === "";
  if (name === "fullName") return empty ? "Enter your full name." : "";
  if (name === "organization") return empty ? "Enter your organisation name." : "";
  if (name === "email") {
    if (empty) return "Enter your email address.";
    return validity.typeMismatch ? "Enter a valid email address." : "";
  }
  if (name === "phone") {
    if (empty) return "Enter your phone number.";
    return validity.patternMismatch ? "Enter a valid phone number." : "";
  }
  return "";
}

export function buildEnquiryPayload(form, journeyId) {
  const values = Object.fromEntries(new FormData(form));
  const technicianRequired = form.elements.technicianRequired.checked;
  const laptopQuantity = integer(values.laptopQuantity);
  const standardSelected = values.laptopCategory === "standard";
  const performanceSelected = values.laptopCategory === "performance";
  const location = values.serviceCity === "Others" ? text(values.customCity) : text(values.serviceCity);
  return {
    journeyId,
    location,
    startDate: text(values.startDate),
    endDate: text(values.endDate),
    ratePlan: text(values.ratePlan),
    standardQuantity: standardSelected ? laptopQuantity : 0,
    performanceQuantity: performanceSelected ? laptopQuantity : 0,
    technicianRequired,
    technicianDays: technicianRequired ? integer(values.technicianDays) : 0,
    fullName: text(values.fullName),
    organization: text(values.organization),
    email: text(values.email).toLowerCase(),
    phone: text(values.phone),
  };
}

export function createJourneyId(storage = globalThis.sessionStorage, crypto = globalThis.crypto) {
  const key = "atlas-rentals-journey-id";
  const existing = storage?.getItem(key);
  if (/^[a-f0-9]{32}$/.test(existing || "")) return existing;
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  const id = [...bytes].map((byte) => byte.toString(16).padStart(2, "0")).join("");
  storage?.setItem(key, id);
  return id;
}

export function clearJourneyId(storage = globalThis.sessionStorage) {
  storage?.removeItem("atlas-rentals-journey-id");
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
