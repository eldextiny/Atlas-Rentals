import test from "node:test";
import assert from "node:assert/strict";
import {
  PRICING,
  calculateEstimate,
  calculateRentalDays,
  validateBooking,
} from "../js/pricing.js";

test("published rates and minimum remain fixed", () => {
  assert.deepEqual(PRICING, {
    standardDailyRate: 10_000,
    performanceDailyRate: 15_000,
    deliveryRetrievalPerBooking: 40_000,
    technicianDailyRate: 35_000,
    vatRate: 0.075,
    minimumLaptopQuantity: 5,
  });
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

test("technician remains optional while Delivery & Retrieval stays compulsory", () => {
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
  });
  assert.equal(result.valid, true);
});

test("technician selection requires at least one support day", () => {
  const result = validateBooking({
    location: "Lagos",
    startDate: "2026-08-04",
    endDate: "2026-08-04",
    standardQuantity: 5,
    technicianRequired: true,
    technicianDays: 0,
  });
  assert.equal(result.valid, false);
  assert.match(result.errors.technician, /at least 1/);
});
