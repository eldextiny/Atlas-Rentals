import { getCountries, getCountryCallingCode, parsePhoneNumberFromString } from "./vendor/libphonenumber.js";

const PAYLOAD_FIELDS = Object.freeze([
  "journeyId", "location", "startDate", "endDate", "ratePlan",
  "standardQuantity", "performanceQuantity", "technicianRequired",
  "technicianQuantity", "technicianDays", "fullName", "organization", "email", "phoneCountry", "phone",
]);
const NEW_ENQUIRY_RATE_PLANS = Object.freeze(["daily"]);
export const PHONE_VALIDATION_MESSAGE = "Enter a valid phone number for the selected country, or include the full international number beginning with +.";

function text(value) {
  return String(value ?? "").trim().replace(/\s+/g, " ");
}

function integer(value) {
  const number = Number(value);
  return Number.isInteger(number) && number >= 0 ? number : value;
}

export function phoneCountryOptions(displayName = (country) => country) {
  return getCountries().map((country) => ({
    country,
    callingCode: getCountryCallingCode(country),
    label: displayName(country),
  })).sort((left, right) => left.label.localeCompare(right.label, "en"));
}

export function normalizePhoneNumber(value, country) {
  const phone = text(value);
  if (!phone || !/^[A-Z]{2}$/.test(country || "") || !/^\+?[0-9 ()-]+$/.test(phone)) return null;
  const parsed = phone.startsWith("+")
    ? parsePhoneNumberFromString(phone)
    : parsePhoneNumberFromString(phone, country);
  return parsed?.isValid() ? parsed.number : null;
}

export function personalDetailsError(name, value, validity = {}, phoneCountry = "NG") {
  const empty = String(value ?? "").trim() === "";
  if (name === "fullName") return empty ? "Enter your full name." : "";
  if (name === "organization") return empty ? "Enter your organisation name." : "";
  if (name === "email") {
    if (empty) return "Enter your email address.";
    return validity.typeMismatch ? "Enter a valid email address." : "";
  }
  if (name === "phone") {
    if (empty) return "Enter your phone number.";
    return normalizePhoneNumber(value, phoneCountry) ? "" : PHONE_VALIDATION_MESSAGE;
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
    technicianQuantity: technicianRequired ? integer(values.technicianQuantity) : 0,
    technicianDays: technicianRequired ? integer(values.technicianDays) : 0,
    fullName: text(values.fullName),
    organization: text(values.organization),
    email: text(values.email).toLowerCase(),
    phoneCountry: text(values.phoneCountry).toUpperCase(),
    phone: normalizePhoneNumber(values.phone, text(values.phoneCountry).toUpperCase()) || text(values.phone),
  };
}

export function createJourneyId(storage = globalThis.sessionStorage, crypto = globalThis.crypto, now = Date.now) {
  const key = "atlas-rentals-journey-id";
  const existing = storage?.getItem(key);
  if (/^(?:[a-f0-9]{32}|j1\.[a-f0-9]{8}\.[a-f0-9]{32})$/.test(existing || "")) return existing;
  if (!crypto?.getRandomValues) throw new Error("Secure enquiry session is unavailable.");
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  const random = [...bytes].map((byte) => byte.toString(16).padStart(2, "0")).join("");
  if (/^0{32}$/.test(random)) throw new Error("Secure enquiry session is unavailable.");
  const issuedAt = Math.floor(now() / 1000);
  if (!Number.isSafeInteger(issuedAt) || issuedAt < 1 || issuedAt > 0xffffffff) throw new Error("Secure enquiry session is unavailable.");
  const id = `j1.${issuedAt.toString(16).padStart(8, "0")}.${random}`;
  storage?.setItem(key, id);
  return id;
}

export function clearJourneyId(storage = globalThis.sessionStorage) {
  storage?.removeItem("atlas-rentals-journey-id");
}

export function hasStablePayloadShape(payload) {
  return PAYLOAD_FIELDS.every((field) => Object.hasOwn(payload, field)) &&
    Object.keys(payload).every((field) => PAYLOAD_FIELDS.includes(field)) &&
    NEW_ENQUIRY_RATE_PLANS.includes(payload.ratePlan);
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
