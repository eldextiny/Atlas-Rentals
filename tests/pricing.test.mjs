import test from "node:test";
import assert from "node:assert/strict";
import {
  PRICING,
  calculateRatePlanPerUnit,
  calculateEstimate,
  calculateRentalDays,
  formatDurationBreakdown,
  LAPTOP_CATALOGUE,
  RATE_PLANS,
  validateBooking,
} from "../js/pricing.js";

test("published rates and minimum remain fixed", () => {
  assert.deepEqual([PRICING.standardDailyRate, PRICING.performanceDailyRate], [10_000, 15_000]);
  assert.deepEqual([PRICING.deliveryRetrievalPerBooking, PRICING.technicianDailyRate, PRICING.vatRate, PRICING.minimumLaptopQuantity], [40_000, 35_000, .075, 5]);
});

test("daily is the only active plan and calculates both categories exactly", () => {
  for (const rates of Object.values(LAPTOP_CATALOGUE)) {
    assert.equal(calculateRatePlanPerUnit(1, rates, "daily").perUnitRental, rates.dailyRate);
    assert.equal(calculateRatePlanPerUnit(6, rates, "daily").perUnitRental, rates.dailyRate * 6);
  }
  assert.deepEqual(Object.keys(RATE_PLANS), ["daily"]);
});

test("unsupported plans fail safely in pricing and booking validation", () => {
  for (const plan of ["weekly", "monthly", "best", "unsupported"]) {
    const unit = calculateRatePlanPerUnit(30, LAPTOP_CATALOGUE.standard, plan);
    assert.equal(unit.valid, false);
    assert.equal(unit.error, "Daily Rate is the only supported rental rate plan.");
    const booking = validateBooking({ location: "Lagos", startDate: "2026-08-03", endDate: "2026-08-07", standardQuantity: 5, ratePlan: plan });
    assert.equal(booking.valid, false);
    assert.equal(booking.errors.ratePlan, "Daily Rate is the only supported rental rate plan.");
  }
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

test("technician support costs ₦35,000 for every billable working day", () => {
  const result = calculateEstimate({
    standardQuantity: 5,
    rentalDays: 3,
    technicianDays: 3,
  });
  assert.equal(result.technician, 105_000);
});

test("VAT applies after rental, Delivery & Retrieval and technician charges", () => {
  const result = calculateEstimate({
    standardQuantity: 3,
    performanceQuantity: 2,
    ratePlan: "daily",
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

test("working-day duration is inclusive, timezone-safe and excludes weekends", () => {
  assert.equal(calculateRentalDays("2026-08-31", "2026-09-02"), 3);
  assert.equal(calculateRentalDays("2028-02-28", "2028-03-01"), 3);
  assert.equal(calculateRentalDays("2026-08-04", "2026-08-04"), 1);
  assert.equal(calculateRentalDays("2026-08-03", "2026-08-03"), 1);
  assert.equal(calculateRentalDays("2026-08-03", "2026-08-07"), 5);
  assert.equal(calculateRentalDays("2026-08-07", "2026-08-10"), 2);
  assert.equal(calculateRentalDays("2026-08-07", "2026-08-14"), 6);
  assert.equal(calculateRentalDays("2026-10-01", "2026-10-01"), 1);
});

test("invalid and reversed dates are rejected", () => {
  assert.throws(() => calculateRentalDays("2026-02-30", "2026-03-01"));
  assert.throws(() => calculateRentalDays("2026-08-05", "2026-08-04"));
  assert.throws(() => calculateRentalDays("04/08/2026", "05/08/2026"));
  assert.throws(() => calculateRentalDays("2026-08-08", "2026-08-10"), /start date must be a weekday/);
  assert.throws(() => calculateRentalDays("2026-08-07", "2026-08-09"), /end date must be a weekday/);
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
    ratePlan: "daily",
  });
  assert.equal(result.valid, true);
});

test("technician selection requires at least one support day", () => {
  const result = validateBooking({
    location: "Lagos",
    startDate: "2026-08-04",
    endDate: "2026-08-04",
    standardQuantity: 5,
    ratePlan: "daily",
    technicianRequired: true,
    technicianDays: 0,
  });
  assert.equal(result.valid, false);
  assert.match(result.errors.technician, /at least 1/);
});
