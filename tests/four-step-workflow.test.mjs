import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const html = readFileSync(new URL("../index.html", import.meta.url), "utf8");
const app = readFileSync(new URL("../js/app.js", import.meta.url), "utf8");
const enquiry = readFileSync(new URL("../js/enquiry.js", import.meta.url), "utf8");
const css = readFileSync(new URL("../css/styles.css", import.meta.url), "utf8");
const emailTemplate = readFileSync(new URL("../api/rentals-email-template.php", import.meta.url), "utf8");
const pdfTemplate = readFileSync(new URL("../api/document-engine/templates/rentals-quotation.php", import.meta.url), "utf8");
const businessRules = readFileSync(new URL("../docs/BUSINESS_RULES.md", import.meta.url), "utf8");

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
  assert.match(stepThree, /class="personal-details-card" aria-labelledby="personal-details-title"/);
  for (const contract of [
    /id="full-name" name="fullName"[^>]*aria-describedby="full-name-error"/,
    /id="organization" name="organization"[^>]*aria-describedby="organization-error"/,
    /id="email" name="email"[^>]*aria-describedby="email-error"/,
    /id="phone-country" name="phoneCountry"[^>]*aria-describedby="phone-country-hint phone-error"/,
    /id="phone" name="phone"[^>]*aria-describedby="phone-hint phone-error"/,
  ]) assert.match(stepThree, contract);
});

test("step one uses an accessible native service-city selector with a conditional custom city", () => {
  const stepOne = html.match(/<section class="form-step is-active" data-step="1"[\s\S]*?<\/section>/)?.[0] || "";
  assert.match(stepOne, /select id="service-city" name="serviceCity"[^>]*aria-describedby="service-city-hint location-error"[^>]*required/);
  const choices = [...stepOne.matchAll(/<option value="([^"]+)">([^<]+)<\/option>/g)].map((match) => [match[1], match[2].trim()]);
  assert.deepEqual(choices, [
    ["Abuja", "Abuja — Federal Capital Territory"],
    ["Lagos", "Lagos - Mainland &amp; Island"],
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
  assert.match(app, /technician-estimate-row"\)\.hidden = result\.technicianDays === 0/);
});

test("confirmation is persisted-enquiry wording rather than booking confirmation", () => {
  assert.match(html, /Submit enquiry/);
  assert.match(html, /Your enquiry has been received\. Availability and booking remain subject to confirmation by DY-PLUS\./);
  assert.match(app, /await submitEnquiry\(buildEnquiryPayload\(form, journeyId\)\)/);
  assert.match(app, /finishButton\.disabled = true/);
  assert.match(app, /const enquiry = await submitEnquiry[\s\S]*successMessage\.hidden = false[\s\S]*catch \(error\)/);
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
  assert.match(stepTwo, /Standard Business Laptops - Core i3, 4GB RAM, Win 11/);
  assert.match(stepTwo, /High Performance Laptops - Core i5 &amp; i7, 16GB RAM, Win 11 Pro, 512GB SSD/);
  assert.match(stepTwo, /id="category-details"[^>]*aria-live="polite"/);
  assert.match(stepTwo, /id="laptop-quantity"[^>]*min="5"[^>]*required/);
  assert.doesNotMatch(stepTwo, /name="standardQuantity"|name="performanceQuantity"/);
  assert.match(app, /standardQuantity: category === "standard" \? quantity : 0/);
  assert.match(app, /performanceQuantity: category === "performance" \? quantity : 0/);
});

test("step two requires an accessible native rental rate-plan selector", () => {
  const stepTwo = html.match(/<section class="form-step" data-step="2"[\s\S]*?<\/section>/)?.[0] || "";
  assert.match(stepTwo, /select id="rate-plan" name="ratePlan"[^>]*aria-describedby="rate-plan-help rate-plan-error"[^>]*required/);
  const ratePlanSelect = stepTwo.match(/<select id="rate-plan"[\s\S]*?<\/select>/)?.[0] || "";
  const plans = [...ratePlanSelect.matchAll(/<option value="([^"]+)">([^<]+)<\/option>/g)].map((match) => [match[1], match[2].trim()]);
  assert.deepEqual(plans, [["daily", "Daily Rate — Flexible billing for each inclusive rental day"], ["weekly", "Weekly Rate — Fixed blocks of 7 rental days"], ["monthly", "Monthly Rate — Fixed blocks of 30 rental days"]]);
  assert.doesNotMatch(stepTwo, /Best Available|value="best"/i);
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
    assert.match(html, new RegExp(`name="${name}"[^>]*aria-describedby="[^"]*${id}[^"]*"`));
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

test("progress markers form one labelled row with three decorative connector segments", () => {
  const progress = html.match(/<nav class="steps header-progress"[\s\S]*?<\/nav>/)?.[0] || "";
  assert.deepEqual([...progress.matchAll(/<b>([^<]+)<\/b>/g)].map((match) => match[1]), ["Rental Details", "Laptops", "Support &amp; Details", "Review"]);
  assert.equal((progress.match(/class="step(?: is-active)?"/g) || []).length, 4);
  assert.match(css, /\.steps \{[^}]*grid-template-columns: repeat\(4, minmax\(0, 1fr\)\)/);
  assert.match(css, /\.step:not\(:last-child\)::before/);
  assert.match(css, /\.step\.is-complete:not\(:last-child\)::before/);
  assert.match(css, /\.step\.is-active span::after/);
  assert.match(css, /@media \(max-width: 600px\)[\s\S]*--marker-size: 1\.5rem/);
  assert.match(app, /button\.setAttribute\("aria-current", "step"\)/);
  assert.match(app, /button\.removeAttribute\("aria-current"\)/);
});

test("step progress is sticky-header content and Review reveals from the workflow top", () => {
  const header = html.match(/<header class="site-header">[\s\S]*?<\/header>/)?.[0] || "";
  assert.match(header, /header-progress/);
  assert.deepEqual([...header.matchAll(/data-step-target="(\d)"/g)].map((match) => Number(match[1])), [1, 2, 3, 4]);
  const goToStepBody = app.match(/function goToStep[\s\S]*?\n\}/)?.[0] || "";
  assert.match(app, /function scrollWorkflowToTop\(\)[\s\S]*planner\.scrollIntoView\(\{ behavior: reducedMotion\.matches \? "auto" : "smooth", block: "start" \}\)/);
  assert.match(app, /scrollWorkflowToTop\(\)[\s\S]*await goToStep\(nextStep\)/);
  assert.match(goToStepBody, /incoming\.querySelector\("h3"\)[\s\S]*focus\(\{ preventScroll: true \}\)/);
});

test("review overlay validates first, delays Step 4 and locks duplicate navigation", () => {
  const handler = app.match(/nextButton\.addEventListener\("click"[\s\S]*?\n\}\);/)?.[0] || "";
  assert.match(handler, /if \(reviewPreparationInProgress\) return/);
  assert.match(handler, /if \(!validateCurrentStep\(\)\) return[\s\S]*showSubmissionOverlay\("review"\)/);
  assert.match(handler, /reviewPreparationInProgress = true[\s\S]*nextButton\.disabled = true/);
  assert.match(handler, /showSubmissionOverlay\("review"\)[\s\S]*await overlayDelay\(800\)[\s\S]*hideSubmissionOverlay\(\)[\s\S]*scrollWorkflowToTop\(\)[\s\S]*await goToStep\(nextStep\)/);
  assert.match(app, /Preparing your estimate…/);
  assert.match(app, /We’re organising your rental details and pricing\./);
  assert.match(app, /function overlayDelay\(milliseconds\)[\s\S]*reducedMotion\.matches \? 80 : milliseconds/);
  assert.doesNotMatch(handler, /form\.reset\(\)/);
});

test("mobile estimate is limited to review step while desktop remains available", () => {
  assert.match(html, /class="estimate-card"/);
  assert.match(app, /planner\.dataset\.currentStep = String\(step\)/);
  assert.match(css, /planner-shell:not\(\[data-current-step="4"\]\) \.estimate-card \{ display: none; \}/);
});

test("phone is required client-side and review uses reassuring customer copy", () => {
  assert.match(html, /name="phoneCountry"[^>]*autocomplete="country"[^>]*required/);
  assert.match(html, /name="phone"[^>]*autocomplete="tel"[^>]*aria-describedby="phone-hint phone-error"[^>]*required/);
  assert.match(html, /value="NG" selected>Nigeria \(\+234\)/);
  assert.match(enquiry, /phoneCountryOptions[\s\S]*getCountryCallingCode/);
  assert.match(app, /nextStep === 4[\s\S]*fetch\("api\/review-enquiry\.php"/);
  assert.match(app, /Everything looks good\. Your enquiry is ready to submit\./);
  assert.doesNotMatch(app + html, /CRM synchronization|retried safely|delivery-state/i);
});

test("server phone rejection returns to contact details without clearing entered values", () => {
  const submitter = app.match(/const submitEnquiry = createSubmissionGuard[\s\S]*?\n\}\);/)?.[0] || "";
  const handler = app.match(/finishButton\.addEventListener\("click"[\s\S]*?\n\}\);/)?.[0] || "";
  assert.match(submitter, /response\.status === 422[\s\S]*body\.fields\?\.phone[\s\S]*error\.fields = body\.fields/);
  assert.match(app, /async function returnToPhoneError[\s\S]*goToStep\(3, \{ focusTarget: phoneInput \}\)[\s\S]*setPersonalFieldError\(phoneInput, message\)[\s\S]*phoneInput\.focus/);
  assert.match(handler, /error\.fields\?\.phone[\s\S]*await returnToPhoneError\(error\.fields\.phone\)/);
  assert.doesNotMatch(handler, /catch \(error\)[\s\S]*form\.reset\(\)/);
});

test("successful submission exposes only the authoritative quotation download lifecycle", () => {
  assert.match(html, /id="finish-button"[^>]*>Submit enquiry/);
  assert.match(html, /id="download-quote"[^>]*download[^>]*hidden[^>]*aria-label="Download your Atlas Rentals quotation PDF"/);
  assert.match(html, /id="quotation-pending"[^>]*disabled[^>]*hidden>Preparing quotation…/);
  assert.match(app, /const pdf = enquiry\.pdf/);
  assert.match(app, /success-estimate-summary[\s\S]*enquiry\.estimatedTotal/);
  assert.match(app, /pdf\.downloadUrl\.startsWith\("\/api\/download-quotation\.php\?"\)/);
  assert.match(app, /downloadQuote\.href = pdf\.downloadUrl/);
  assert.match(app, /Preparing your quotation…/);
  assert.match(app, /quotation is not yet available for download/);
  assert.doesNotMatch(app, /downloadQuote\.addEventListener[\s\S]*submitEnquiry/);
});

test("confirmed enquiry result prioritises reference, quotation and restrained restart", () => {
  const success = html.match(/<section class="success-message"[\s\S]*?<\/section>/)?.[0] || "";
  assert.match(success, /Enquiry received/);
  assert.match(success, /Your enquiry reference/);
  assert.match(success, /id="enquiry-reference"/);
  assert.match(success, /Authoritative estimate:[\s\S]*id="success-estimate-summary"/);
  assert.match(success, /Download Quote/);
  assert.match(success, /button-tertiary[^>]*restart-button/);
  assert.match(success, /availability and booking remain subject to confirmation by DY-PLUS/i);
  assert.doesNotMatch(success, /wa\.me|whatsapp_number/i);
  assert.match(css, /\.success-reference \{[^}]*font-size: clamp/);
  assert.match(css, /\.success-actions \{[^}]*display: grid/);
});

test("estimate presents rental selection, additional services and ordered costs", () => {
  const estimate = html.match(/<aside class="estimate-card"[\s\S]*?<\/aside>/)?.[0] || "";
  for (const heading of ["Estimated rental cost", "Rental selection", "Additional services", "Cost summary", "Estimated total"]) {
    assert.match(estimate, new RegExp(heading));
  }
  assert.match(estimate, /Your estimate updates as you change the rental details\./);
  assert.match(estimate, /class="estimate-badge">Estimate only/);
  for (const detail of ["Laptop category", "Quantity", "Rental period", "Rental days", "Selected rate plan", "Applied duration", "Rate per laptop", "Equipment rental total"]) {
    assert.match(estimate, new RegExp(detail));
  }
  assert.match(estimate, /id="estimate-rental-days"/);
  assert.match(app, /setText\("#estimate-rental-days", result\.rentalDays/);
  assert.match(app, /<span>Rental days<\/span>/);
  assert.match(estimate, /id="estimate-duration-detail"/);
  assert.match(estimate, /id="estimate-rate-plan"/);
  assert.match(estimate, /id="estimate-subtotal"/);
  assert.match(estimate, /not a confirmed booking/);
  assert.match(app, /setText\("#estimate-rate-plan", state\.ratePlan \? result\.ratePlanLabel : "Plan pending"\)/);
  assert.match(app, /setText\("#estimate-subtotal", currency\.format\(result\.subtotalBeforeVat\)\)/);
  assert.match(css, /\.total-row strong \{[^}]*font-size: clamp/);
  assert.doesNotMatch(estimate, /Compulsory/i);
  const orderedRows = ["summary-equipment-cost", "summary-technician-row", "delivery-retrieval-cost", "estimate-subtotal", "vat-cost", "total-cost"];
  const positions = orderedRows.map((id) => estimate.indexOf(`id="${id}"`));
  assert.ok(positions.every((position) => position >= 0));
  assert.deepEqual([...positions].sort((a, b) => a - b), positions);
});

test("estimate starts at zero and withholds charges until a laptop is selected", () => {
  const estimate = html.match(/<aside class="estimate-card"[\s\S]*?<\/aside>/)?.[0] || "";
  assert.match(estimate, /id="estimate-equipment-total">₦0\.00/);
  assert.match(estimate, /id="estimate-zero-state"><strong>₦0\.00<\/strong>/);
  assert.match(estimate, /id="estimate-services-group"[^>]*hidden/);
  assert.match(estimate, /id="estimate-commercial-group"[^>]*hidden/);
  assert.match(app, /const hasLaptopSelection = Boolean\(selectedDetails && result\.totalQuantity > 0\)/);
  assert.match(app, /estimate-services-group"\)\.hidden = !hasLaptopSelection/);
  assert.match(app, /estimate-commercial-group"\)\.hidden = !hasLaptopSelection/);
  assert.match(app, /setText\("#estimate-subtotal", currency\.format\(result\.subtotalBeforeVat\)\)/);
  assert.match(app, /setText\("#total-cost", currency\.format\(result\.total\)\)/);
});

test("FAQ uses native accessible disclosure cards and shared review heading treatment", () => {
  const faq = html.match(/<section class="faq-section"[\s\S]*?<\/section>/)?.[0] || "";
  assert.equal((faq.match(/<details class="faq-item">/g) || []).length, 7);
  assert.equal((faq.match(/<summary>/g) || []).length, 7);
  assert.equal((faq.match(/class="faq-indicator" aria-hidden="true"/g) || []).length, 7);
  assert.match(faq, /class="section-display-heading" id="faq-title">Frequently Asked Questions/);
  assert.match(html, /class="section-display-heading" id="step-4-title">Review Your Estimate/);
  assert.match(css, /\.faq-item summary:focus-visible/);
});

test("estimate shows one selected category and conditionally hides technician", () => {
  assert.doesNotMatch(html, /standard-estimate-row|performance-estimate-row/);
  assert.match(app, /setText\("#estimate-category", selectedDetails\?\.title \|\| "Select a laptop category"\)/);
  assert.match(app, /const equipmentTotal = state\.laptopCategory === "standard" \? result\.standardRental : result\.performanceRental/);
  assert.match(html, /id="technician-estimate-row" hidden/);
  assert.match(html, /id="summary-technician-row" hidden/);
  assert.match(app, /document\.querySelector\("#technician-estimate-row"\)\.hidden = result\.technicianDays === 0/);
  assert.match(app, /document\.querySelector\("#summary-technician-row"\)\.hidden = result\.technicianDays === 0/);
  assert.match(html, /class="included-status">Included/);
});

test("review includes compact customer reassurance before submission", () => {
  const stepFour = html.match(/<section class="form-step" data-step="4"[\s\S]*?<\/section>/)?.[0] || "";
  assert.match(stepFour, /class="review-reassurance"[^>]*role="note"/);
  assert.match(stepFour, /Secure enquiry handling/);
  assert.match(stepFour, /Transparent pricing/);
  assert.match(stepFour, /No booking is confirmed until DY-PLUS verifies availability/);
  assert.match(stepFour, /review-reassurance[\s\S]*id="finish-button"/);
});

test("presentation polish preserves native selectors and responsive focus contracts", () => {
  assert.equal((html.match(/<select id="(?:service-city|laptop-category|rate-plan)"/g) || []).length, 3);
  assert.doesNotMatch(app, /role=["']combobox|createElement\(["']select/);
  assert.match(css, /category-select-wrap:has\(select:focus-visible\)/);
  assert.match(css, /category-select-wrap:has\(select\[aria-invalid="true"\]\)/);
  assert.match(css, /category-select-wrap:has\(select:valid\)/);
  assert.match(css, /@media \(max-width: 768px\)/);
  assert.match(css, /min-height: 48px/);
  assert.match(css, /prefers-reduced-motion: reduce/);
});

test("every native workflow select uses readable Atlas-blue typography", () => {
  assert.equal((html.match(/<select\b/g) || []).length, 4);
  assert.match(css, /\.category-select-wrap select \{[^}]*color: var\(--brand-blue\)[^}]*font-size: 1\.025rem[^}]*line-height: 1\.55/);
  assert.match(css, /\.category-select-wrap select:required:invalid \{[^}]*color: var\(--muted\)/);
  assert.match(css, /\.category-select-wrap select:disabled \{[^}]*color: var\(--muted\)/);
  assert.match(css, /\.category-select-wrap select option \{[^}]*color: var\(--brand-blue\)[^}]*font-size: 1rem[^}]*line-height: 1\.5/);
  assert.match(css, /\.category-select-wrap select option\[value=""\] \{[^}]*color: var\(--muted\)/);
  assert.match(css, /@media \(max-width: 600px\)[\s\S]*\.category-select-wrap select \{[^}]*min-height: 3\.25rem[^}]*padding: \.72rem 2\.8rem \.72rem 3rem[^}]*font-size: \.9rem[^}]*line-height: 1\.45/);
  assert.match(css, /@media \(max-width: 600px\)[\s\S]*\.category-select-wrap select option \{[^}]*font-size: \.9rem[^}]*line-height: 1\.45/);
});

test("successful submission hides navigation while failure keeps it available", () => {
  assert.match(html, /id="form-actions"/);
  const handler = app.match(/finishButton\.addEventListener\("click"[\s\S]*?\n\}\);/)?.[0] || "";
  assert.match(handler, /formActions\.hidden = true/);
  assert.match(handler, /catch \(error\)[\s\S]*submissionStatus\.textContent = error\.message/);
  assert.doesNotMatch(handler.match(/catch \(error\)[\s\S]*?finally/)?.[0] || "", /formActions\.hidden = true/);
  assert.match(app, /restartButton\.addEventListener[\s\S]*formActions\.hidden = false/);
});

test("expired journey response preserves entered data and never reports success", () => {
  const submitter = app.match(/const submitEnquiry = createSubmissionGuard[\s\S]*?\n\}\);/)?.[0] || "";
  assert.match(submitter, /response\.status === 409[\s\S]*body\?\.error === "journey_expired"/);
  assert.match(submitter, /Your entered details are still here/);
  assert.doesNotMatch(submitter, /form\.reset|clearJourneyId|Enquiry received/);
});

test("directional transitions lock navigation, focus headings and respect reduced motion", () => {
  assert.match(app, /let transitionInProgress = false/);
  assert.match(app, /if \(transitionInProgress/);
  assert.match(app, /is-exiting-\$\{direction\}/);
  assert.match(app, /is-entering-\$\{direction\}/);
  assert.match(app, /outgoing\.inert = true/);
  assert.match(app, /incoming\.inert = false/);
  assert.match(app, /setAttribute\("aria-hidden", "true"\)/);
  assert.match(app, /incoming\.querySelector\("h3"\)/);
  assert.match(app, /reducedMotion\.matches \? Promise\.resolve\(\)/);
  assert.match(app, /transitionDelay\(180\)[\s\S]*transitionDelay\(80\)[\s\S]*transitionDelay\(240\)/);
  assert.match(css, /is-exiting-forward[\s\S]*180ms/);
  assert.match(css, /is-entering-forward[\s\S]*240ms/);
  assert.match(css, /prefers-reduced-motion: reduce[\s\S]*transform: none/);
});

test("submission overlay waits for validation and authoritative success", () => {
  assert.match(html, /id="submission-overlay"[^>]*role="status"[^>]*aria-live="polite"[^>]*aria-atomic="true"[^>]*tabindex="-1"[^>]*hidden/);
  assert.match(html, /Submitting your enquiry…/);
  assert.match(html, /Please wait while we securely prepare your quotation\./);
  const handler = app.match(/finishButton\.addEventListener\("click"[\s\S]*?\n\}\);/)?.[0] || "";
  assert.match(handler, /if \(finishButton\.disabled \|\| !validateCurrentStep\(\)\) return/);
  assert.match(handler, /showSubmissionOverlay\("processing"\)[\s\S]*await submitEnquiry/);
  assert.match(handler, /await submitEnquiry[\s\S]*showSubmissionOverlay\("success"\)/);
  assert.match(handler, /await submitEnquiry[\s\S]*showSubmissionOverlay\("success"\)[\s\S]*await overlayDelay\(600\)/);
  assert.match(handler, /catch \(error\)[\s\S]*hideSubmissionOverlay\(\)[\s\S]*error\.message[\s\S]*finishButton\.focus\(\{ preventScroll: true \}\)/);
  assert.match(app, /Enquiry received/);
  assert.match(app, /Your quotation is ready\./);
  assert.match(app, /document\.body\.classList\.add\("has-submission-overlay"\)/);
  assert.match(app, /setSubmissionSurfacesInert\(true\)/);
  assert.match(css, /body\.has-submission-overlay \{ overflow: hidden; \}/);
  assert.match(css, /\.submission-overlay \{[^}]*position: fixed[^}]*inset: 0/);
  assert.match(css, /prefers-reduced-motion: reduce[\s\S]*submission-overlay-card/);
});

test("confirmed submission completes every progress marker and restart resets progress", () => {
  const handler = app.match(/finishButton\.addEventListener\("click"[\s\S]*?\n\}\);/)?.[0] || "";
  assert.match(handler, /await submitEnquiry[\s\S]*workflowComplete = true[\s\S]*updateStepChrome\(totalSteps\)/);
  assert.match(app, /const active = !workflowComplete/);
  assert.match(app, /const completed = workflowComplete \|\|/);
  assert.match(app, /if \(active\) button\.setAttribute\("aria-current", "step"\);\s*else button\.removeAttribute\("aria-current"\)/);
  assert.match(app, /All four enquiry steps completed\./);
  assert.match(app, /aria-label[^\n]*completed/);
  assert.match(app, /restartButton\.addEventListener[\s\S]*workflowComplete = false[\s\S]*goToStep\(1\)/);
});

test("customer-facing output uses included-service wording", () => {
  assert.doesNotMatch(html + app + emailTemplate + pdfTemplate + businessRules, /Compulsory/i);
  assert.match(html + app, /Included(?: rental service| service)?/i);
  assert.match(app, /Included service/);
});
