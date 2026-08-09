export const PRICING = Object.freeze({
  standardDailyRate: 10_000,
  standardWeeklyRate: 59_500,
  standardMonthlyRate: 185_000,
  performanceDailyRate: 15_000,
  performanceWeeklyRate: 89_500,
  performanceMonthlyRate: 225_500,
  daysPerWeek: 7,
  daysPerMonth: 30,
  deliveryRetrievalPerBooking: 40_000,
  technicianDailyRate: 35_000,
  vatRate: 0.075,
  minimumLaptopQuantity: 5,
});

export const LAPTOP_CATALOGUE = Object.freeze({
  standard: Object.freeze({
    title: "Standard Business Laptop",
    dailyRate: PRICING.standardDailyRate,
    weeklyRate: PRICING.standardWeeklyRate,
    monthlyRate: PRICING.standardMonthlyRate,
    summary: "Training, assessments, office and browser work",
    bestSuitedFor: "Training, assessments, office tools and browser-based work.",
    features: Object.freeze(["Business-class configuration", "Office and browser ready", "Everyday productivity"]),
  }),
  performance: Object.freeze({
    title: "High Performance Laptop",
    dailyRate: PRICING.performanceDailyRate,
    weeklyRate: PRICING.performanceWeeklyRate,
    monthlyRate: PRICING.performanceMonthlyRate,
    summary: "Creative, technical and data-intensive work",
    bestSuitedFor: "Creative, technical and data-intensive sessions.",
    features: Object.freeze(["Higher-spec configuration", "Creative and data workloads", "Technical sessions"]),
  }),
});

export const RATE_PLANS = Object.freeze({
  daily: Object.freeze({ label: "Daily Rate", help: "Charged for every inclusive rental day." }),
  weekly: Object.freeze({ label: "Weekly Rate — 7 days", help: "Available only when the inclusive duration is a whole multiple of 7 days." }),
  monthly: Object.freeze({ label: "Monthly Rate — 30 days", help: "Available only when the inclusive duration is a whole multiple of 30 days." }),
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

export function calculateRatePlanPerUnit(totalDays, rates, ratePlan) {
  requireNonNegativeInteger(totalDays, "totalDays");
  if (!Object.hasOwn(RATE_PLANS, ratePlan)) return Object.freeze({ ratePlan, valid: false, error: "Select a rental rate plan.", months: 0, weeks: 0, days: 0, perUnitRental: 0, ...rates });
  if (ratePlan === "weekly" && totalDays % PRICING.daysPerWeek !== 0) return Object.freeze({ ratePlan, valid: false, error: "Weekly Rate requires the rental duration to be a whole multiple of 7 days.", months: 0, weeks: 0, days: 0, perUnitRental: 0, ...rates });
  if (ratePlan === "monthly" && totalDays % PRICING.daysPerMonth !== 0) return Object.freeze({ ratePlan, valid: false, error: "Monthly Rate requires the rental duration to be a whole multiple of 30 days.", months: 0, weeks: 0, days: 0, perUnitRental: 0, ...rates });
  let duration;
  if (ratePlan === "daily") duration = { totalDays, months: 0, weeks: 0, days: totalDays };
  else if (ratePlan === "weekly") duration = { totalDays, months: 0, weeks: totalDays / PRICING.daysPerWeek, days: 0 };
  else duration = { totalDays, months: totalDays / PRICING.daysPerMonth, weeks: 0, days: 0 };
  const perUnitRental = duration.months * rates.monthlyRate + duration.weeks * rates.weeklyRate + duration.days * rates.dailyRate;
  return Object.freeze({ ...duration, ratePlan, ratePlanLabel: RATE_PLANS[ratePlan].label, valid: true, error: "", dailyRate: rates.dailyRate, weeklyRate: rates.weeklyRate, monthlyRate: rates.monthlyRate, perUnitRental });
}

export function formatDurationBreakdown({ months = 0, weeks = 0, days = 0 }) {
  const parts = [];
  if (months) parts.push(`${months} month${months === 1 ? "" : "s"}`);
  if (weeks) parts.push(`${weeks} week${weeks === 1 ? "" : "s"}`);
  if (days) parts.push(`${days} day${days === 1 ? "" : "s"}`);
  return parts.join(" + ") || "0 days";
}

export function calculateEstimate({
  standardQuantity = 0,
  performanceQuantity = 0,
  rentalDays = 0,
  ratePlan = "daily",
  technicianDays = 0,
} = {}) {
  requireNonNegativeInteger(standardQuantity, "standardQuantity");
  requireNonNegativeInteger(performanceQuantity, "performanceQuantity");
  requireNonNegativeInteger(rentalDays, "rentalDays");
  requireNonNegativeInteger(technicianDays, "technicianDays");

  const standardPricing = calculateRatePlanPerUnit(rentalDays, LAPTOP_CATALOGUE.standard, ratePlan);
  const performancePricing = calculateRatePlanPerUnit(rentalDays, LAPTOP_CATALOGUE.performance, ratePlan);
  const duration = standardPricing.valid
    ? { totalDays: rentalDays, months: standardPricing.months, weeks: standardPricing.weeks, days: standardPricing.days }
    : { totalDays: rentalDays, months: 0, weeks: 0, days: 0 };
  const standardRental = standardQuantity * standardPricing.perUnitRental;
  const performanceRental = performanceQuantity * performancePricing.perUnitRental;
  const rentalSubtotal = standardRental + performanceRental;
  const deliveryRetrieval = PRICING.deliveryRetrievalPerBooking;
  const technician = technicianDays * PRICING.technicianDailyRate;
  const subtotalBeforeVat = rentalSubtotal + deliveryRetrieval + technician;
  const vat = Math.round(subtotalBeforeVat * PRICING.vatRate);

  return Object.freeze({
    rentalDays,
    duration,
    durationLabel: formatDurationBreakdown(duration),
    ratePlan,
    ratePlanLabel: RATE_PLANS[ratePlan]?.label || "Rate plan pending",
    ratePlanError: standardPricing.error,
    standardPricing,
    performancePricing,
    standardQuantity,
    performanceQuantity,
    totalQuantity: standardQuantity + performanceQuantity,
    standardRental,
    performanceRental,
    rentalSubtotal,
    deliveryRetrieval,
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
  ratePlan = "",
} = {}) {
  const errors = {};

  if (typeof location !== "string" || location.trim().length < 2 || location.trim().length > 120) {
    errors.location = "Enter a valid service city.";
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

  if (!errors.dates) {
    const datePlan = calculateRatePlanPerUnit(rentalDays, LAPTOP_CATALOGUE.standard, ratePlan);
    if (!datePlan.valid) errors.ratePlan = datePlan.error;
  } else if (!Object.hasOwn(RATE_PLANS, ratePlan)) {
    errors.ratePlan = "Select a rental rate plan.";
  }
  return Object.freeze({ valid: Object.keys(errors).length === 0, errors, rentalDays });
}
