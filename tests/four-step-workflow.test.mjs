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

test("removed optional fields are absent from the workflow", () => {
  const form = html.match(/<form id="rental-form"[\s\S]*?<\/form>/)?.[0] || "";
  assert.doesNotMatch(form + app, /deliveryAddress|Delivery address|Additional details|name="description"/i);
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

test("confirmation is persisted-enquiry wording rather than booking confirmation", () => {
  assert.match(html, /Submit enquiry/);
  assert.match(html, /enquiry only; availability and booking remain subject to DY-PLUS confirmation/);
  assert.match(app, /await submitEnquiry\(buildEnquiryPayload\(form, journeyId\)\)/);
  assert.match(app, /finishButton\.disabled = true/);
  assert.match(app, /const enquiry = await submitEnquiry[\s\S]*success-message[\s\S]*catch \(error\)/);
});

test("hero cards retain guarded focus navigation without changing quantities", () => {
  assert.match(html, /data-rate-target="standard-quantity"/);
  assert.match(html, /data-rate-target="performance-quantity"/);
  assert.match(app, /currentStep = 1;[\s\S]*highestStep = 1;[\s\S]*goToStep\(1\)/);
  assert.doesNotMatch(app, /quantityInput\.value\s*=/);
});

test("step progress is sticky-header content and transitions do not force scrolling", () => {
  const header = html.match(/<header class="site-header">[\s\S]*?<\/header>/)?.[0] || "";
  assert.match(header, /header-progress/);
  assert.deepEqual([...header.matchAll(/data-step-target="(\d)"/g)].map((match) => Number(match[1])), [1, 2, 3, 4]);
  const goToStepBody = app.match(/function goToStep[\s\S]*?\n\}/)?.[0] || "";
  assert.doesNotMatch(goToStepBody, /scrollIntoView|scrollTo/);
});

test("mobile estimate is limited to review step while desktop remains available", () => {
  assert.match(html, /class="estimate-card"/);
  assert.match(app, /planner\.dataset\.currentStep = String\(step\)/);
  const css = readFileSync(new URL("../css/styles.css", import.meta.url), "utf8");
  assert.match(css, /planner-shell:not\(\[data-current-step="4"\]\) \.estimate-card \{ display: none; \}/);
});

test("phone is required client-side and review triggers same-origin CRM", () => {
  assert.match(html, /name="phone"[^>]*pattern="[^"]+"[^>]*required/);
  assert.match(app, /nextStep === 4[\s\S]*fetch\("api\/review-enquiry\.php"/);
  assert.match(app, /CRM synchronization is pending/);
});

test("persisted response distinguishes complete delivery from pending delivery", () => {
  assert.match(app, /enquiry\.deliveryComplete/);
  assert.match(app, /Quotation delivery is pending and can be retried safely/);
});
