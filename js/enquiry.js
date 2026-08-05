const PAYLOAD_FIELDS = Object.freeze([
  "location", "deliveryAddress", "startDate", "endDate",
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

export function buildEnquiryPayload(form) {
  const values = Object.fromEntries(new FormData(form));
  const technicianRequired = form.elements.technicianRequired.checked;
  return {
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
