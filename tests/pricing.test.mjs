import test from "node:test";
import assert from "node:assert/strict";
import {
  PRICING,
  calculateTieredPerUnit,
  calculateRatePlanPerUnit,
  calculateEstimate,
  calculateRentalDays,
  decomposeRentalDuration,
  formatDurationBreakdown,
  LAPTOP_CATALOGUE,
  RATE_PLANS,
  validateBooking,
} from "../js/pricing.js";

test("published rates and minimum remain fixed", () => {
  assert.deepEqual([PRICING.standardDailyRate, PRICING.standardWeeklyRate, PRICING.standardMonthlyRate], [10_000, 59_500, 185_000]);
  assert.deepEqual([PRICING.performanceDailyRate, PRICING.performanceWeeklyRate, PRICING.performanceMonthlyRate], [15_000, 89_500, 225_500]);
  assert.deepEqual([PRICING.daysPerWeek, PRICING.daysPerMonth, PRICING.deliveryRetrievalPerBooking, PRICING.technicianDailyRate, PRICING.vatRate, PRICING.minimumLaptopQuantity], [7, 30, 40_000, 35_000, .075, 5]);
});

test("daily weekly and monthly plans calculate both categories exactly", () => {
  for (const [category, rates] of Object.entries(LAPTOP_CATALOGUE)) {
    const multiplier = category === "standard" ? 1 : 1;
    assert.equal(calculateRatePlanPerUnit(1, rates, "daily").perUnitRental, rates.dailyRate * multiplier);
    assert.equal(calculateRatePlanPerUnit(6, rates, "daily").perUnitRental, rates.dailyRate * 6);
    for (const days of [7, 14, 28]) assert.equal(calculateRatePlanPerUnit(days, rates, "weekly").perUnitRental, (days / 7) * rates.weeklyRate);
    for (const days of [30, 60]) assert.equal(calculateRatePlanPerUnit(days, rates, "monthly").perUnitRental, (days / 30) * rates.monthlyRate);
  }
  assert.deepEqual(Object.keys(RATE_PLANS), ["daily", "weekly", "monthly", "best"]);
});

test("weekly and monthly plans reject partial blocks without rounding", () => {
  for (const days of [8, 29, 30, 31]) assert.match(calculateRatePlanPerUnit(days, LAPTOP_CATALOGUE.standard, "weekly").error, /whole multiple of 7 days/);
  for (const days of [7, 29, 31, 37]) assert.match(calculateRatePlanPerUnit(days, LAPTOP_CATALOGUE.standard, "monthly").error, /whole multiple of 30 days/);
});

test("booking validation exposes precise rate-plan errors", () => {
  const base = { location: "Lagos", startDate: "2026-08-01", standardQuantity: 5 };
  assert.equal(validateBooking({ ...base, endDate: "2026-08-08", ratePlan: "weekly" }).errors.ratePlan, "Weekly Rate requires the rental duration to be a whole multiple of 7 days.");
  assert.equal(validateBooking({ ...base, endDate: "2026-08-31", ratePlan: "monthly" }).errors.ratePlan, "Monthly Rate requires the rental duration to be a whole multiple of 30 days.");
  assert.equal(validateBooking({ ...base, endDate: "2026-08-01", ratePlan: "" }).errors.ratePlan, "Select a rental rate plan.");
});

test("best available plan preserves required decomposition boundaries", () => {
  const expected = { 8:[0,1,1], 29:[0,4,1], 31:[1,0,1], 37:[1,1,0], 60:[2,0,0], 67:[2,1,0] };
  for (const [days, blocks] of Object.entries(expected)) {
    const result = calculateRatePlanPerUnit(Number(days), LAPTOP_CATALOGUE.performance, "best");
    assert.deepEqual([result.months, result.weeks, result.days], blocks);
  }
});

test("duration decomposition always applies months, then weeks, then days", () => {
  const expected = { 1: [0,0,1], 6: [0,0,6], 7: [0,1,0], 8: [0,1,1], 29: [0,4,1], 30: [1,0,0], 31: [1,0,1], 37: [1,1,0], 60: [2,0,0], 67: [2,1,0] };
  for (const [total, blocks] of Object.entries(expected)) {
    const result = decomposeRentalDuration(Number(total));
    assert.deepEqual([result.months, result.weeks, result.days], blocks);
  }
  assert.equal(formatDurationBreakdown(decomposeRentalDuration(40)), "1 month + 1 week + 3 days");
});

test("both categories use exact tiered per-unit charges at every boundary", () => {
  const standard = { 1:10000, 6:60000, 7:59500, 8:69500, 29:248000, 30:185000, 31:195000, 37:244500, 60:370000, 67:429500 };
  const performance = { 1:15000, 6:90000, 7:89500, 8:104500, 29:373000, 30:225500, 31:240500, 37:315000, 60:451000, 67:540500 };
  for (const [days, amount] of Object.entries(standard)) {
    assert.equal(calculateTieredPerUnit(Number(days), LAPTOP_CATALOGUE.standard).perUnitRental, amount);
    assert.equal(calculateEstimate({ standardQuantity: 5, rentalDays: Number(days) }).standardRental, amount * 5);
  }
  for (const [days, amount] of Object.entries(performance)) {
    assert.equal(calculateTieredPerUnit(Number(days), LAPTOP_CATALOGUE.performance).perUnitRental, amount);
    assert.equal(calculateEstimate({ performanceQuantity: 6, rentalDays: Number(days) }).performanceRental, amount * 6);
  }
});

test("tiered per-unit charges multiply by quantity and only charge selected category", () => {
  const standard = calculateEstimate({ standardQuantity: 6, rentalDays: 37 });
  assert.equal(standard.standardPricing.perUnitRental, 244500);
  assert.equal(standard.standardRental, 1467000);
  assert.equal(standard.performanceRental, 0);
  const performance = calculateEstimate({ performanceQuantity: 5, rentalDays: 67 });
  assert.equal(performance.performancePricing.perUnitRental, 540500);
  assert.equal(performance.performanceRental, 2702500);
  assert.equal(performance.standardRental, 0);
});

test("standard rental is quantity multiplied by days and rate", () => {
  const result = calculateEstimate({ standardQuantity: 5, rentalDays: 3 });
  assert.equal(result.standardRental, 150_000);
  assert.equal(result.rentalSubtotal, 150_000);
});

test("performance rental uses its own daily rate", () => {
  const result = calculateEstimate({ performanceQuantity: 5, rentalDays: 2 });
  assert.equal(result.performanceRental, 150_000);
});

test("mixed laptop quantities satisfy the combined minimum", () => {
  const result = calculateEstimate({
    standardQuantity: 2,
    performanceQuantity: 3,
    rentalDays: 1,
  });
  assert.equal(result.totalQuantity, 5);
  assert.equal(result.meetsMinimum, true);
  assert.equal(result.rentalSubtotal, 65_000);
});

test("Delivery & Retrieval is charged exactly once in every booking", () => {
  const result = calculateEstimate({
    standardQuantity: 50,
    rentalDays: 10,
  });
  assert.equal(result.deliveryRetrieval, 40_000);
});

test("Delivery & Retrieval cannot be disabled by a caller", () => {
  const result = calculateEstimate({
    standardQuantity: 5,
    rentalDays: 1,
    deliveryRequired: false,
  });
  assert.equal(result.deliveryRetrieval, 40_000);
});

test("technician support costs ₦35,000 per selected day", () => {
  const result = calculateEstimate({
    standardQuantity: 5,
    rentalDays: 3,
    technicianDays: 2,
  });
  assert.equal(result.technician, 70_000);
});

test("VAT applies after rental, Delivery & Retrieval and technician charges", () => {
  const result = calculateEstimate({
    standardQuantity: 3,
    performanceQuantity: 2,
    ratePlan: "best",
    rentalDays: 2,
    technicianDays: 2,
  });
  assert.equal(result.rentalSubtotal, 120_000);
  assert.equal(result.deliveryRetrieval, 40_000);
  assert.equal(result.technician, 70_000);
  assert.equal(result.subtotalBeforeVat, 230_000);
  assert.equal(result.vat, 17_250);
  assert.equal(result.total, 247_250);
});

test("technician remains optional while Delivery & Retrieval stays included", () => {
  const result = calculateEstimate({ standardQuantity: 5, rentalDays: 1 });
  assert.equal(result.deliveryRetrieval, 40_000);
  assert.equal(result.technician, 0);
  assert.equal(result.vat, 6_750);
  assert.equal(result.total, 96_750);
});

test("rental duration is inclusive and stable across month boundaries", () => {
  assert.equal(calculateRentalDays("2026-08-31", "2026-09-02"), 3);
  assert.equal(calculateRentalDays("2028-02-28", "2028-03-01"), 3);
  assert.equal(calculateRentalDays("2026-08-04", "2026-08-04"), 1);
});

test("invalid and reversed dates are rejected", () => {
  assert.throws(() => calculateRentalDays("2026-02-30", "2026-03-01"));
  assert.throws(() => calculateRentalDays("2026-08-05", "2026-08-04"));
  assert.throws(() => calculateRentalDays("04/08/2026", "05/08/2026"));
});

test("negative and fractional quantities fail deterministically", () => {
  assert.throws(() => calculateEstimate({ standardQuantity: -1, rentalDays: 1 }));
  assert.throws(() => calculateEstimate({ performanceQuantity: 1.5, rentalDays: 1 }));
  assert.throws(() => calculateEstimate({ standardQuantity: 5, rentalDays: 1.2 }));
});

test("booking validation reports location, date and minimum errors", () => {
  const result = validateBooking({
    location: "X",
    startDate: "2026-08-05",
    endDate: "2026-08-04",
    standardQuantity: 2,
    performanceQuantity: 2,
  });
  assert.equal(result.valid, false);
  assert.match(result.errors.location, /valid service city/);
  assert.match(result.errors.dates, /on or after/);
  assert.match(result.errors.quantity, /at least 5/);
});

test("booking validation accepts a specified Nigerian service city", () => {
  const result = validateBooking({
    location: "Port Harcourt",
    startDate: "2026-08-05",
    endDate: "2026-08-05",
    standardQuantity: 5,
    ratePlan: "best",
  });
  assert.equal(result.valid, true);
});

test("technician selection requires at least one support day", () => {
  const result = validateBooking({
    location: "Lagos",
    startDate: "2026-08-04",
    endDate: "2026-08-04",
    standardQuantity: 5,
    ratePlan: "best",
    technicianRequired: true,
    technicianDays: 0,
  });
  assert.equal(result.valid, false);
  assert.match(result.errors.technician, /at least 1/);
});
