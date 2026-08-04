export const PRICING = Object.freeze({
  standardDailyRate: 10_000,
  performanceDailyRate: 15_000,
  deliveryPerBooking: 40_000,
  technicianDailyRate: 40_000,
  vatRate: 0.075,
  minimumLaptopQuantity: 5,
});

function requireNonNegativeInteger(value, fieldName) {
  if (!Number.isInteger(value) || value < 0) {
    throw new TypeError(`${fieldName} must be a non-negative integer.`);
  }
}

function parseCalendarDate(value, fieldName) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    throw new TypeError(`${fieldName} must use YYYY-MM-DD format.`);
  }

  const [year, month, day] = value.split("-").map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));
  if (
    date.getUTCFullYear() !== year ||
    date.getUTCMonth() !== month - 1 ||
    date.getUTCDate() !== day
  ) {
    throw new RangeError(`${fieldName} is not a valid calendar date.`);
  }
  return date;
}

export function calculateRentalDays(startDate, endDate) {
  const start = parseCalendarDate(startDate, "startDate");
  const end = parseCalendarDate(endDate, "endDate");
  const elapsedDays = (end.getTime() - start.getTime()) / 86_400_000;

  if (elapsedDays < 0) {
    throw new RangeError("endDate must be on or after startDate.");
  }

  return elapsedDays + 1;
}

export function calculateEstimate({
  standardQuantity = 0,
  performanceQuantity = 0,
  rentalDays = 0,
  deliveryRequired = false,
  technicianDays = 0,
} = {}) {
  requireNonNegativeInteger(standardQuantity, "standardQuantity");
  requireNonNegativeInteger(performanceQuantity, "performanceQuantity");
  requireNonNegativeInteger(rentalDays, "rentalDays");
  requireNonNegativeInteger(technicianDays, "technicianDays");

  if (typeof deliveryRequired !== "boolean") {
    throw new TypeError("deliveryRequired must be a boolean.");
  }

  const standardRental = standardQuantity * rentalDays * PRICING.standardDailyRate;
  const performanceRental =
    performanceQuantity * rentalDays * PRICING.performanceDailyRate;
  const rentalSubtotal = standardRental + performanceRental;
  const delivery = deliveryRequired ? PRICING.deliveryPerBooking : 0;
  const technician = technicianDays * PRICING.technicianDailyRate;
  const subtotalBeforeVat = rentalSubtotal + delivery + technician;
  const vat = Math.round(subtotalBeforeVat * PRICING.vatRate);

  return Object.freeze({
    rentalDays,
    standardQuantity,
    performanceQuantity,
    totalQuantity: standardQuantity + performanceQuantity,
    standardRental,
    performanceRental,
    rentalSubtotal,
    delivery,
    technicianDays,
    technician,
    subtotalBeforeVat,
    vat,
    total: subtotalBeforeVat + vat,
    meetsMinimum:
      standardQuantity + performanceQuantity >= PRICING.minimumLaptopQuantity,
  });
}

export function validateBooking({
  location,
  startDate,
  endDate,
  standardQuantity = 0,
  performanceQuantity = 0,
  technicianRequired = false,
  technicianDays = 0,
} = {}) {
  const errors = {};

  if (!new Set(["Lagos", "Abuja"]).has(location)) {
    errors.location = "Choose Lagos or Abuja.";
  }

  let rentalDays = 0;
  try {
    rentalDays = calculateRentalDays(startDate, endDate);
  } catch (error) {
    errors.dates = error.message;
  }

  try {
    requireNonNegativeInteger(standardQuantity, "standardQuantity");
    requireNonNegativeInteger(performanceQuantity, "performanceQuantity");
    if (
      standardQuantity + performanceQuantity <
      PRICING.minimumLaptopQuantity
    ) {
      errors.quantity = "Select at least 5 laptops across both types.";
    }
  } catch (error) {
    errors.quantity = error.message;
  }

  if (technicianRequired) {
    try {
      requireNonNegativeInteger(technicianDays, "technicianDays");
      if (technicianDays < 1) {
        errors.technician = "Enter at least 1 technician day.";
      }
    } catch (error) {
      errors.technician = error.message;
    }
  }

  return Object.freeze({ valid: Object.keys(errors).length === 0, errors, rentalDays });
}
