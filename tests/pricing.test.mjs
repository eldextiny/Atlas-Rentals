import test from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import {
  PRICING,
  calculateRatePlanPerUnit,
  calculateEstimate,
  calculateRentalDays,
  formatDurationBreakdown,
  formatRentalDate,
  formatRentalPeriod,
  LAPTOP_CATALOGUE,
  RATE_PLANS,
  validateBooking,
} from "../js/pricing.js";

test("rental periods use direct calendar components for display", () => {
  assert.equal(formatRentalPeriod("2026-09-07", "2026-09-10"), "Sep 7, 2026 to Sep 10, 2026");
  assert.equal(formatRentalPeriod("2026-12-31", "2027-01-04"), "Dec 31, 2026 to Jan 4, 2027");
});

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

test("one technician costs ₦35,000 for every billable calendar day", () => {
  const result = calculateEstimate({
    standardQuantity: 5,
    rentalDays: 3,
    technicianQuantity: 1,
    technicianDays: 3,
  });
  assert.equal(result.technician, 105_000);
});

test("multiple technicians multiply quantity by authoritative calendar days", () => {
  const result = calculateEstimate({ standardQuantity: 5, rentalDays: 5, technicianQuantity: 3, technicianDays: 5 });
  assert.equal(result.technician, 525_000);
  assert.equal(result.subtotalBeforeVat, 815_000);
  assert.equal(result.vat, 61_125);
  assert.equal(result.total, 876_125);
});

test("VAT applies after rental, Delivery & Retrieval and technician charges", () => {
  const result = calculateEstimate({
    standardQuantity: 3,
    performanceQuantity: 2,
    ratePlan: "daily",
    rentalDays: 2,
    technicianQuantity: 1,
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

test("calendar-day duration is inclusive, timezone-safe and includes weekends", () => {
  assert.equal(calculateRentalDays("2026-08-31", "2026-09-02"), 3);
  assert.equal(calculateRentalDays("2028-02-28", "2028-03-01"), 3);
  assert.equal(calculateRentalDays("2026-08-04", "2026-08-04"), 1);
  assert.equal(calculateRentalDays("2026-08-03", "2026-08-03"), 1);
  assert.equal(calculateRentalDays("2026-08-03", "2026-08-07"), 5);
  assert.equal(calculateRentalDays("2026-08-07", "2026-08-10"), 4);
  assert.equal(calculateRentalDays("2026-08-07", "2026-08-14"), 8);
  assert.equal(calculateRentalDays("2026-08-08", "2026-08-09"), 2);
  assert.equal(calculateRentalDays("2026-10-01", "2026-10-01"), 1);
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
    ratePlan: "daily",
  });
  assert.equal(result.valid, true);
});

test("selected technician quantity must be an integer from 1 through 10", () => {
  const base = { location: "Lagos", startDate: "2026-08-04", endDate: "2026-08-04", standardQuantity: 5, ratePlan: "daily", technicianRequired: true, technicianDays: 1 };
  for (const technicianQuantity of [0, -1, 1.5, "2", 11]) {
    const result = validateBooking({ ...base, technicianQuantity });
    assert.equal(result.valid, false);
    assert.match(result.errors.technician, /technicianQuantity|between 1 and 10/);
  }
  assert.equal(validateBooking({ ...base, technicianQuantity: 1 }).valid, true);
  assert.equal(validateBooking({ ...base, technicianQuantity: 10 }).valid, true);
});


test("display dates use English abbreviated months and unpadded days", () => {
  for (const [index, month] of ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"].entries()) {
    assert.equal(formatRentalDate(`2026-${String(index + 1).padStart(2, "0")}-08`), `${month} 8, 2026`);
  }
  assert.equal(formatRentalPeriod("2024-02-29", "2024-03-01"), "Feb 29, 2024 to Mar 1, 2024");
  assert.equal(formatRentalDate("2026-10-08"), "Oct 8, 2026");
  for (const value of ["", undefined, null, "2026-02-29", "2026-04-31", "2026-00-08", "2026-13-08", "2026-10-00", "2026-10-8", "2026-10-08T00:00:00Z"]) {
    assert.throws(() => formatRentalDate(value));
  }
});


test("date display and inclusive billing stay stable across timezones", () => {
  const moduleUrl = new URL("../js/pricing.js", import.meta.url).href;
  const source = `import assert from "node:assert/strict"; import {formatRentalDate, calculateRentalDays} from ${JSON.stringify(moduleUrl)}; assert.equal(formatRentalDate("2026-10-08"), "Oct 8, 2026"); assert.equal(formatRentalDate("2024-02-29"), "Feb 29, 2024"); assert.equal(calculateRentalDays("2026-10-08", "2026-10-09"), 2);`;
  for (const TZ of ["UTC", "America/Los_Angeles", "Pacific/Kiritimati", "Africa/Lagos"]) {
    const result = spawnSync(process.execPath, ["--input-type=module", "-e", source], { env: { ...process.env, TZ }, encoding: "utf8" });
    assert.equal(result.status, 0, TZ + ": " + result.stderr);
  }
});
