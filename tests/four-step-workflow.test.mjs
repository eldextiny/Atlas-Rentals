import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const html = readFileSync(new URL("../index.html", import.meta.url), "utf8");
const app = readFileSync(new URL("../js/app.js", import.meta.url), "utf8");
const css = readFileSync(new URL("../css/styles.css", import.meta.url), "utf8");

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

test("step one uses an accessible native service-city selector with a conditional custom city", () => {
  const stepOne = html.match(/<section class="form-step is-active" data-step="1"[\s\S]*?<\/section>/)?.[0] || "";
  assert.match(stepOne, /select id="service-city" name="serviceCity"[^>]*aria-describedby="service-city-hint location-error"[^>]*required/);
  const choices = [...stepOne.matchAll(/<option value="([^"]+)">([^<]+)<\/option>/g)].map((match) => [match[1], match[2].trim()]);
  assert.deepEqual(choices, [
    ["Abuja", "Abuja — Federal Capital Territory"],
    ["Lagos", "Lagos — Lagos metropolitan area"],
    ["Others", "Others — Specify another Nigerian city"],
  ]);
  assert.match(stepOne, /class="category-select-icon location-select-icon"[^>]*aria-hidden="true"/);
  assert.match(stepOne, /id="custom-city-wrap"[^>]*hidden/);
  assert.match(stepOne, /<label for="custom-city">Specify service city<\/label>/);
  assert.match(stepOne, /id="custom-city" name="customCity"/);
  assert.match(app, /serviceCity\.value === "Others" \? customCityInput\.value\.trim\(\) : serviceCity\.value/);
  assert.match(app, /customCityInput\.required = customCitySelected/);
  assert.match(app, /if \(clearWhenHidden\) customCityInput\.value = ""/);
  assert.match(app, /"Select a service city\."/);
  assert.match(app, /"Enter the service city\."/);
  assert.match(app, /serviceCity\.focus\(\)/);
  assert.match(app, /customCityInput\.focus\(\)/);
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

test("hero cards retain guarded category navigation without changing quantities", () => {
  assert.match(html, /data-laptop-category="standard"/);
  assert.match(html, /data-laptop-category="performance"/);
  assert.match(app, /currentStep = 1;[\s\S]*highestStep = 1;[\s\S]*goToStep\(1\)/);
  assert.doesNotMatch(app, /laptopQuantity\.value\s*=/);
});

test("step two uses one required category and maps one quantity to the stable contract", () => {
  const stepTwo = html.match(/<section class="form-step" data-step="2"[\s\S]*?<\/section>/)?.[0] || "";
  assert.match(stepTwo, /select id="laptop-category"[^>]*aria-describedby="category-hint quantity-error"[^>]*required/);
  assert.match(stepTwo, /Choose a laptop category/);
  assert.match(stepTwo, /Standard Business Laptop — Training, assessments, office and browser work — ₦10,000\/day/);
  assert.match(stepTwo, /High Performance Laptop — Creative, technical and data-intensive work — ₦15,000\/day/);
  assert.match(stepTwo, /id="category-details"[^>]*aria-live="polite"/);
  assert.match(stepTwo, /id="laptop-quantity"[^>]*min="5"[^>]*required/);
  assert.doesNotMatch(stepTwo, /name="standardQuantity"|name="performanceQuantity"/);
  assert.match(app, /standardQuantity: category === "standard" \? quantity : 0/);
  assert.match(app, /performanceQuantity: category === "performance" \? quantity : 0/);
});

test("step two requires an accessible native rental rate-plan selector", () => {
  const stepTwo = html.match(/<section class="form-step" data-step="2"[\s\S]*?<\/section>/)?.[0] || "";
  assert.match(stepTwo, /select id="rate-plan" name="ratePlan"[^>]*aria-describedby="rate-plan-help rate-plan-error"[^>]*required/);
  const plans = [...stepTwo.matchAll(/<option value="(daily|weekly|monthly|best)">([^<]+)<\/option>/g)].map((match) => [match[1], match[2].trim()]);
  assert.deepEqual(plans, [["daily", "Daily Rate"], ["weekly", "Weekly Rate — 7 days"], ["monthly", "Monthly Rate — 30 days"], ["best", "Best Available Rate — automatic monthly → weekly → daily decomposition"]]);
  assert.match(stepTwo, /class="category-select-icon rate-plan-select-icon"[^>]*aria-hidden="true"/);
  assert.match(app, /ratePlan: ratePlan\.value/);
  assert.match(app, /ratePlan\.focus\(\)/);
  assert.match(app, /ratePlan\.setAttribute\("aria-invalid", "true"\)/);
  assert.match(app, /ratePlan\.removeAttribute\("aria-invalid"\)/);
  const goToStepBody = app.match(/function goToStep[\s\S]*?\n\}/)?.[0] || "";
  assert.doesNotMatch(goToStepBody, /ratePlan\.value\s*=/);
});

test("native category selector exposes polished accessible state hooks", () => {
  assert.match(html, /class="category-select-wrap"/);
  assert.match(html, /class="category-select-icon"[^>]*aria-hidden="true"/);
  assert.match(html, /class="category-select-chevron"[^>]*aria-hidden="true"/);
  assert.match(css, /category-select-wrap:has\(select:focus-visible\)/);
  assert.match(css, /select\[aria-invalid="true"\]/);
  assert.match(css, /category-select-wrap:has\(select:valid\)/);
  assert.match(css, /selected-category-state/);
  assert.match(css, /prefers-reduced-motion: reduce/);
  assert.match(app, /selected-category-state/);
  assert.match(app, /category-rates/);
  assert.match(app, /category-best-use/);
  assert.match(app, /category-specs/);
  assert.match(app, /Best suited for:/);
  assert.match(app, /Minimum quantity:/);
  assert.match(app, /LAPTOP_CATALOGUE\[category\]/);
  assert.match(app, /Daily:/);
  assert.match(app, /Weekly —/);
  assert.match(app, /Monthly —/);
  assert.match(app, /Applied duration/);
  assert.match(app, /Per-unit rental/);
  assert.match(app, /Equipment amount/);
});

test("personal validation is field-local, focuses first invalid and clears corrected errors", () => {
  for (const [name, id] of [["fullName", "full-name-error"], ["organization", "organization-error"], ["email", "email-error"], ["phone", "phone-error"]]) {
    assert.match(html, new RegExp(`name="${name}"[^>]*aria-describedby="${id}"`));
    assert.match(html, new RegExp(`id="${id}"`));
  }
  assert.match(app, /firstInvalid\.field\.focus\(\)/);
  assert.match(app, /field\.setAttribute\("aria-invalid", "true"\)/);
  assert.match(app, /field\.removeAttribute\("aria-invalid"\)/);
  assert.match(app, /addEventListener\("input"[\s\S]*setPersonalFieldError/);
  assert.match(app, /showError\("details-error", firstInvalid\?\.message \|\| ""\)/);
  const removedGenericMessage = ["Complete all required", "personal details with valid information"].join(" ");
  assert.equal((app + html).includes(removedGenericMessage), false);
});

test("progress circles use accessible check marks instead of visible numbers", () => {
  const progress = html.match(/<nav class="steps header-progress"[\s\S]*?<\/nav>/)?.[0] || "";
  assert.equal((progress.match(/<span aria-hidden="true">&#10003;<\/span>/g) || []).length, 4);
  assert.doesNotMatch(progress, /<span>[1-4]<\/span>/);
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

test("successful submission hides navigation while failure keeps it available", () => {
  assert.match(html, /id="form-actions"/);
  const handler = app.match(/finishButton\.addEventListener\("click"[\s\S]*?\n\}\);/)?.[0] || "";
  assert.match(handler, /formActions\.hidden = true/);
  assert.match(handler, /catch \(error\)[\s\S]*submissionStatus\.textContent = error\.message/);
  assert.doesNotMatch(handler.match(/catch \(error\)[\s\S]*?finally/)?.[0] || "", /formActions\.hidden = true/);
  assert.match(app, /restartButton\.addEventListener[\s\S]*formActions\.hidden = false/);
});
