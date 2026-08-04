import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const html = readFileSync(new URL("../index.html", import.meta.url), "utf8");
const app = readFileSync(new URL("../js/app.js", import.meta.url), "utf8");

test("planner exposes exactly four ordered steps", () => {
  const sections = [...html.matchAll(/<section class="form-step[^>]*data-step="(\d)"/g)].map((match) => Number(match[1]));
  const controls = [...html.matchAll(/data-step-target="(\d)"/g)].map((match) => Number(match[1]));

  assert.deepEqual(sections, [1, 2, 3, 4]);
  assert.deepEqual(controls, [1, 2, 3, 4]);
  assert.doesNotMatch(html, /Step [56] of|data-step(?:-target)?="[56]"/);
  assert.match(app, /const totalSteps = 4;/);
  assert.doesNotMatch(app, /currentStep === [56]|step > 6|steps\[[45]\]/);
});

test("support and personal details share step three", () => {
  const stepThree = html.match(/<section class="form-step" data-step="3"[\s\S]*?<\/section>/)?.[0] || "";

  assert.match(stepThree, /Included automatically/);
  assert.match(stepThree, /id="technician-required"/);
  assert.match(stepThree, /name="fullName"/);
  assert.match(stepThree, /name="organization"/);
  assert.match(stepThree, /name="email"/);
  assert.match(stepThree, /name="phone"/);
  assert.doesNotMatch(stepThree, /type="checkbox"[^>]*delivery|name="delivery/i);
});

test("final review and local reset contracts are retained", () => {
  const stepFour = html.match(/<section class="form-step" data-step="4"[\s\S]*?<\/section>/)?.[0] || "";

  assert.match(stepFour, /id="review-content"/);
  assert.match(stepFour, /id="finish-button"/);
  assert.match(stepFour, /id="restart-button"/);
  assert.match(app, /restartButton\.addEventListener\("click"/);
  assert.match(app, /form\.reset\(\)/);
  assert.match(app, /technicianDaysInput\.disabled = true/);
  assert.match(app, /currentStep === 4 && result\.technicianDays === 0/);
});

test("hero cards retain guarded focus navigation without changing quantities", () => {
  assert.match(html, /data-rate-target="standard-quantity"/);
  assert.match(html, /data-rate-target="performance-quantity"/);
  assert.match(app, /currentStep === 2 && !scheduleValidated/);
  assert.match(app, /prefers-reduced-motion: reduce/);
  assert.doesNotMatch(app, /quantityInput\.value\s*=/);
});
