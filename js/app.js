import { calculateEstimate, calculateRentalDays, LAPTOP_CATALOGUE, PRICING, validateBooking } from "./pricing.js";
import { PHONE_VALIDATION_MESSAGE, buildEnquiryPayload, clearJourneyId, createJourneyId, createSubmissionGuard, normalizePhoneNumber, personalDetailsError, phoneCountryOptions } from "./enquiry.js";

const form = document.querySelector("#rental-form");
const steps = [...document.querySelectorAll(".form-step")];
const stepButtons = [...document.querySelectorAll(".step")];
const nextButton = document.querySelector("#next-button");
const backButton = document.querySelector("#back-button");
const formActions = document.querySelector("#form-actions");
const serviceCity = document.querySelector("#service-city");
const customCityWrap = document.querySelector("#custom-city-wrap");
const customCityInput = document.querySelector("#custom-city");
const laptopCategory = document.querySelector("#laptop-category");
const laptopQuantity = document.querySelector("#laptop-quantity");
const ratePlan = document.querySelector("#rate-plan");
const ratePlanHelp = document.querySelector("#rate-plan-help");
const ratePlanDetails = document.querySelector("#rate-plan-details");
const categoryDetails = document.querySelector("#category-details");
const technicianRequired = document.querySelector("#technician-required");
const technicianDaysInput = document.querySelector("#technician-days");
const phoneCountry = document.querySelector("#phone-country");
const phoneInput = document.querySelector("#phone");
const rateCards = [...document.querySelectorAll("[data-laptop-category]")];
const restartButton = document.querySelector("#restart-button");
const finishButton = document.querySelector("#finish-button");
const downloadQuote = document.querySelector("#download-quote");
const quotationPending = document.querySelector("#quotation-pending");
const submissionStatus = document.querySelector("#submission-status");
const submissionOverlay = document.querySelector("#submission-overlay");
const submissionOverlayTitle = document.querySelector("#submission-overlay-title");
const submissionOverlayMessage = document.querySelector("#submission-overlay-message");
const progressCompletionStatus = document.querySelector("#progress-completion-status");
const submissionSurfaces = [document.querySelector("header"), document.querySelector("main"), document.querySelector("footer")];
const planner = document.querySelector("#planner");
const totalSteps = 4;
const currency = new Intl.NumberFormat("en-NG", {
  style: "currency",
  currency: "NGN",
  maximumFractionDigits: 0,
});
const personalFieldNames = ["fullName", "organization", "email", "phone"];

let currentStep = 1;
let highestStep = 1;
let scheduleValidated = false;
let personalValidationActive = false;
let journeyId = createJourneyId();
let transitionInProgress = false;
let reviewPreparationInProgress = false;
let workflowComplete = false;
const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

function numberValue(name) {
  const value = Number(form.elements[name].value);
  return Number.isInteger(value) && value >= 0 ? value : 0;
}

function selectedLocation() {
  return serviceCity.value === "Others" ? customCityInput.value.trim() : serviceCity.value;
}

function populatePhoneCountries() {
  const selected = phoneCountry.value || "NG";
  const displayNames = new Intl.DisplayNames(["en"], { type: "region" });
  const fragment = document.createDocumentFragment();
  phoneCountryOptions((country) => displayNames.of(country) || country).forEach(({ country, callingCode, label }) => {
    const option = document.createElement("option");
    option.value = country;
    option.textContent = `${label} (+${callingCode})`;
    option.defaultSelected = country === "NG";
    fragment.append(option);
  });
  phoneCountry.replaceChildren(fragment);
  phoneCountry.value = selected;
  if (!phoneCountry.value) phoneCountry.value = "NG";
}

function updateCustomCityState({ clearWhenHidden = false } = {}) {
  const customCitySelected = serviceCity.value === "Others";
  customCityWrap.hidden = !customCitySelected;
  customCityInput.required = customCitySelected;
  if (!customCitySelected) {
    if (clearWhenHidden) customCityInput.value = "";
    customCityInput.removeAttribute("aria-invalid");
  }
}

function rentalDays() {
  try {
    return calculateRentalDays(form.elements.startDate.value, form.elements.endDate.value);
  } catch {
    return 0;
  }
}

function plannerState() {
  const techSelected = form.elements.technicianRequired.checked;
  const category = form.elements.laptopCategory.value;
  const quantity = numberValue("laptopQuantity");
  const billableWorkingDays = rentalDays();
  technicianDaysInput.value = String(techSelected ? billableWorkingDays : 0);
  return {
    location: selectedLocation(),
    startDate: form.elements.startDate.value,
    endDate: form.elements.endDate.value,
    laptopCategory: category,
    laptopQuantity: quantity,
    standardQuantity: category === "standard" ? quantity : 0,
    performanceQuantity: category === "performance" ? quantity : 0,
    rentalDays: billableWorkingDays,
    ratePlan: ratePlan.value,
    technicianRequired: techSelected,
    technicianDays: techSelected ? billableWorkingDays : 0,
  };
}

function estimate() {
  const state = plannerState();
  return calculateEstimate(state);
}

function setText(selector, text) {
  document.querySelector(selector).textContent = text;
}

function renderRatePlanHelp(category, plan) {
  const details = LAPTOP_CATALOGUE[category];
  if (!details || plan !== "daily") {
    ratePlanHelp.textContent = "Daily pricing applies to each Monday-to-Friday rental day.";
    ratePlanDetails.hidden = true;
    ratePlanDetails.replaceChildren();
    return;
  }
  const applicable = `Daily rate: ${currency.format(details.dailyRate)} per billable working day.`;
  ratePlanHelp.textContent = applicable;
  ratePlanDetails.hidden = false;
  ratePlanDetails.innerHTML = `<span class="selected-category-state">Pricing</span><h4>Daily Rate</h4><p>Only Monday-to-Friday rental days are billed. Public holidays on weekdays remain billable.</p><dl><div><dt>Applicable rate</dt><dd>${applicable.replace(/\.$/, "")}</dd></div><div><dt>Weekend rule</dt><dd>Saturday and Sunday endpoints are not accepted, and intervening weekends are excluded.</dd></div></dl>`;
}

function renderCategoryDetails(category) {
  const details = LAPTOP_CATALOGUE[category] ?? null;
  categoryDetails.hidden = details === null;
  if (!details) {
    categoryDetails.replaceChildren();
    return;
  }
  categoryDetails.innerHTML = `<span class="selected-category-state">Selected category</span><h4>${details.title}</h4><div class="category-rates"><span>Daily: <strong>${currency.format(details.dailyRate)}</strong> per billable working day</span></div><p class="category-best-use"><strong>Best suited for:</strong> ${details.bestSuitedFor}</p><div class="category-specs">${details.features.map((detail) => `<span>${detail}</span>`).join("")}</div><p class="category-minimum">Minimum quantity: ${PRICING.minimumLaptopQuantity} laptops</p>`;
}

function updateEstimate() {
  const state = plannerState();
  const result = estimate();
  renderCategoryDetails(state.laptopCategory);
  renderRatePlanHelp(state.laptopCategory, state.ratePlan);
  const selectedDetails = LAPTOP_CATALOGUE[state.laptopCategory] || null;
  const hasLaptopSelection = Boolean(selectedDetails && result.totalQuantity > 0);
  const selectedPricing = state.laptopCategory === "standard" ? result.standardPricing : result.performancePricing;
  const equipmentTotal = state.laptopCategory === "standard" ? result.standardRental : result.performanceRental;
  const rentalPeriod = result.rentalDays && state.startDate && state.endDate
    ? `${state.startDate} to ${state.endDate}`
    : "Dates pending";
  const ratePerLaptop = !selectedDetails || !result.rentalDays
    ? "Rate pending"
    : `${currency.format(selectedDetails.dailyRate)} per working day`;
  setText("#estimate-category", selectedDetails?.title || "Select a laptop category");
  setText("#estimate-quantity", selectedDetails ? `${result.totalQuantity} laptop${result.totalQuantity === 1 ? "" : "s"}` : "Quantity pending");
  setText("#estimate-duration-detail", rentalPeriod);
  setText("#estimate-rental-days", result.rentalDays ? `${result.rentalDays} day${result.rentalDays === 1 ? "" : "s"}` : "Days pending");
  setText("#estimate-rate-plan", "Daily Rate");
  setText("#estimate-billing-blocks", result.rentalDays ? result.durationLabel : "Working days pending");
  setText("#estimate-rate-per-laptop", ratePerLaptop);
  setText("#estimate-equipment-total", currency.format(equipmentTotal));
  setText("#summary-equipment-cost", currency.format(equipmentTotal));
  setText("#delivery-retrieval-cost", currency.format(result.deliveryRetrieval));
  setText("#technician-summary", result.technicianDays ? `${result.technicianDays} day${result.technicianDays === 1 ? "" : "s"} selected` : "Not selected");
  setText("#technician-cost", currency.format(result.technician));
  document.querySelector("#technician-estimate-row").hidden = result.technicianDays === 0;
  document.querySelector("#summary-technician-row").hidden = result.technicianDays === 0;
  setText("#estimate-subtotal", currency.format(result.subtotalBeforeVat));
  setText("#vat-cost", currency.format(result.vat));
  setText("#total-cost", currency.format(result.total));
  document.querySelector("#estimate-zero-state").hidden = hasLaptopSelection;
  document.querySelector("#estimate-services-group").hidden = !hasLaptopSelection;
  document.querySelector("#estimate-commercial-group").hidden = !hasLaptopSelection;

  const minimumStatus = document.querySelector("#minimum-status");
  minimumStatus.textContent = result.meetsMinimum
    ? `${result.totalQuantity} laptops selected — minimum met.`
    : `${result.totalQuantity} selected — add ${5 - result.totalQuantity} more to meet the minimum.`;
  minimumStatus.classList.toggle("is-valid", result.meetsMinimum);
  setText("#quantity-progress", `${Math.min(result.totalQuantity, 5)} of 5 minimum selected`);
  document.querySelector("#quantity-meter").value = Math.min(result.totalQuantity, 5);

  if (currentStep === 4) renderReview(state, result);
}

function estimateMarkup(result) {
  const technicianLine = result.technicianDays
    ? `<div class="summary-line"><span>Technician (${result.technicianDays} days)</span><strong>${currency.format(result.technician)}</strong></div>`
    : "";
  const selectedPricing = result.standardQuantity ? result.standardPricing : result.performancePricing;
  const categoryName = result.standardQuantity ? "Standard Business Laptop" : "High Performance Laptop";
  const quantity = result.standardQuantity || result.performanceQuantity;
  const equipmentAmount = result.standardQuantity ? result.standardRental : result.performanceRental;
  const categoryLine = `<div class="summary-group tiered-rental-summary"><h5>${categoryName}</h5><div class="summary-line"><span>Rate plan</span><strong>Daily Rate</strong></div><div class="summary-line"><span>Quantity</span><strong>${quantity}</strong></div><div class="summary-line"><span>Billable working days</span><strong>${result.durationLabel}</strong></div><div class="summary-line"><span>Applied rate</span><strong>${selectedPricing.days} × ${currency.format(selectedPricing.dailyRate)} daily</strong></div><div class="summary-line"><span>Per-unit rental</span><strong>${currency.format(selectedPricing.perUnitRental)}</strong></div><div class="summary-line"><span>Equipment amount</span><strong>${currency.format(equipmentAmount)}</strong></div></div>`;
  return `
    ${categoryLine}
    <div class="summary-line"><span>Rental subtotal</span><strong>${currency.format(result.rentalSubtotal)}</strong></div>
    <div class="summary-line"><span>Delivery &amp; Retrieval (included rental service, once per booking)</span><strong>${currency.format(result.deliveryRetrieval)}</strong></div>
    ${technicianLine}
    <div class="summary-line"><span>Subtotal before VAT</span><strong>${currency.format(result.subtotalBeforeVat)}</strong></div>
    <div class="summary-line"><span>VAT (7.5%)</span><strong>${currency.format(result.vat)}</strong></div>
    <div class="summary-line total"><span>Estimated total</span><strong>${currency.format(result.total)}</strong></div>`;
}

function escaped(value) {
  const node = document.createElement("span");
  node.textContent = value || "Not provided";
  return node.innerHTML;
}

function renderReview(state, result) {
  const values = Object.fromEntries(new FormData(form));
  document.querySelector("#review-content").innerHTML = `
    <div class="summary-group"><h4>Schedule</h4>
      <div class="summary-line"><span>Location</span><strong>${escaped(state.location)}</strong></div>
      <div class="summary-line"><span>Dates</span><strong>${escaped(state.startDate)} to ${escaped(state.endDate)}</strong></div>
      <div class="summary-line"><span>Billable working days</span><strong>${state.rentalDays}</strong></div>
    </div>
    <div class="summary-group"><h4>Equipment &amp; support</h4>
      <div class="summary-line"><span>${state.laptopCategory === "standard" ? "Standard Business Laptop" : "High Performance Laptop"}</span><strong>${state.laptopQuantity}</strong></div>
      <div class="summary-line"><span>Delivery &amp; Retrieval</span><strong>Included service</strong></div>
      ${state.technicianRequired ? `<div class="summary-line"><span>Technician</span><strong>${result.technicianDays} days</strong></div>` : ""}
    </div>
    <div class="summary-group"><h4>Personal details</h4>
      <div class="summary-line"><span>Contact</span><strong>${escaped(values.fullName)}</strong></div>
      <div class="summary-line"><span>Organization</span><strong>${escaped(values.organization)}</strong></div>
      <div class="summary-line"><span>Email</span><strong>${escaped(values.email)}</strong></div>
      <div class="summary-line"><span>Phone</span><strong>${escaped(normalizePhoneNumber(values.phone, values.phoneCountry) || values.phone)}</strong></div>
    </div>
    <div class="summary-group"><h4>Estimate</h4>${estimateMarkup(result)}</div>`;
}

function showError(id, message = "") {
  document.querySelector(`#${id}`).textContent = message;
}

function personalFieldError(field) {
  return personalDetailsError(field.name, field.value, field.validity, phoneCountry.value);
}

function setPersonalFieldError(field, message) {
  showError(`${field.id}-error`, message);
  if (message) field.setAttribute("aria-invalid", "true");
  else field.removeAttribute("aria-invalid");
  if (field === phoneInput) {
    if (!phoneCountry.value && message) phoneCountry.setAttribute("aria-invalid", "true");
    else phoneCountry.removeAttribute("aria-invalid");
  }
}

async function returnToPhoneError(message = PHONE_VALIDATION_MESSAGE) {
  personalValidationActive = true;
  highestStep = Math.max(highestStep, 3);
  if (currentStep !== 3) await goToStep(3, { focusTarget: phoneInput });
  setPersonalFieldError(phoneInput, message);
  showError("details-error", message);
  phoneInput.focus({ preventScroll: true });
}

function validatePersonalDetails() {
  personalValidationActive = true;
  let firstInvalid = null;
  personalFieldNames.forEach((name) => {
    const field = form.elements[name];
    const message = personalFieldError(field);
    setPersonalFieldError(field, message);
    if (!firstInvalid && message) firstInvalid = { field, message };
  });
  showError("details-error", firstInvalid?.message || "");
  if (!firstInvalid) return true;
  firstInvalid.field.focus();
  return false;
}

function clearLaptopSelectionErrorIfValid() {
  if (laptopCategory.value) laptopCategory.removeAttribute("aria-invalid");
  if (laptopQuantity.checkValidity()) laptopQuantity.removeAttribute("aria-invalid");
  if (laptopCategory.value && laptopQuantity.checkValidity()) showError("quantity-error");
}

function validateCurrentStep() {
  const state = plannerState();
  const booking = validateBooking(state);
  showError("location-error");
  showError("dates-error");
  showError("quantity-error");
  showError("rate-plan-error");
  showError("technician-error");
  showError("details-error");

  if (currentStep === 1) {
    showError("dates-error", booking.errors.dates);
    if (!serviceCity.value) {
      showError("location-error", "Select a service city.");
      serviceCity.setAttribute("aria-invalid", "true");
      customCityInput.removeAttribute("aria-invalid");
      serviceCity.focus();
      return false;
    }
    if (serviceCity.value === "Others" && booking.errors.location) {
      showError("location-error", "Enter the service city.");
      serviceCity.removeAttribute("aria-invalid");
      customCityInput.setAttribute("aria-invalid", "true");
      customCityInput.focus();
      return false;
    }
    serviceCity.removeAttribute("aria-invalid");
    customCityInput.removeAttribute("aria-invalid");
    if (booking.errors.dates) {
      const startError = booking.errors.dates.startsWith("Rental start date");
      const endError = booking.errors.dates.startsWith("Rental end date");
      form.elements.startDate.toggleAttribute("aria-invalid", startError);
      form.elements.endDate.toggleAttribute("aria-invalid", endError || (!startError && Boolean(form.elements.startDate.value)));
      const dateTarget = startError || !form.elements.startDate.value ? form.elements.startDate : form.elements.endDate;
      dateTarget.focus();
      return false;
    }
    form.elements.startDate.removeAttribute("aria-invalid");
    form.elements.endDate.removeAttribute("aria-invalid");
    return true;
  }
  if (currentStep === 2) {
    if (!state.laptopCategory) {
      showError("quantity-error", "Choose a laptop category.");
      laptopCategory.setAttribute("aria-invalid", "true");
      laptopQuantity.removeAttribute("aria-invalid");
      laptopCategory.focus();
      return false;
    }
    if (booking.errors.ratePlan) {
      showError("rate-plan-error", booking.errors.ratePlan);
      ratePlan.setAttribute("aria-invalid", "true");
      ratePlan.focus();
      return false;
    }
    ratePlan.removeAttribute("aria-invalid");
    showError("quantity-error", booking.errors.quantity);
    if (booking.errors.quantity) {
      laptopCategory.removeAttribute("aria-invalid");
      laptopQuantity.setAttribute("aria-invalid", "true");
      laptopQuantity.focus();
      return false;
    }
    laptopCategory.removeAttribute("aria-invalid");
    laptopQuantity.removeAttribute("aria-invalid");
    return true;
  }
  if (currentStep === 3) {
    showError("technician-error", booking.errors.technician);
    if (booking.errors.technician) {
      technicianRequired.focus();
      return false;
    }
    if (!validatePersonalDetails()) return false;
  }
  return true;
}

function transitionDelay(milliseconds) {
  return reducedMotion.matches ? Promise.resolve() : new Promise((resolve) => window.setTimeout(resolve, milliseconds));
}

function overlayDelay(milliseconds) {
  return new Promise((resolve) => window.setTimeout(resolve, reducedMotion.matches ? 80 : milliseconds));
}

function scrollWorkflowToTop() {
  planner.scrollIntoView({ behavior: reducedMotion.matches ? "auto" : "smooth", block: "start" });
}

function updateStepChrome(step) {
  planner.dataset.currentStep = String(step);
  stepButtons.forEach((button, index) => {
    const buttonStep = index + 1;
    const active = !workflowComplete && buttonStep === step;
    button.disabled = workflowComplete || buttonStep > highestStep;
    button.classList.toggle("is-active", active);
    const completed = workflowComplete || (buttonStep < highestStep && (buttonStep !== 1 || scheduleValidated));
    button.classList.toggle("is-complete", completed);
    if (workflowComplete) button.setAttribute("aria-label", `${button.querySelector("b").textContent} — completed`);
    else button.removeAttribute("aria-label");
    if (active) button.setAttribute("aria-current", "step");
    else button.removeAttribute("aria-current");
  });
  backButton.hidden = workflowComplete || step === 1;
  nextButton.hidden = workflowComplete || step === totalSteps;
  nextButton.textContent = step === 3 ? "Review estimate" : "Continue";
  progressCompletionStatus.textContent = workflowComplete ? "All four enquiry steps completed." : "";
}

async function goToStep(step, options = {}) {
  if (transitionInProgress || step < 1 || step > totalSteps || step > highestStep || step === currentStep) return false;
  const outgoing = steps.find((section) => Number(section.dataset.step) === currentStep);
  const incoming = steps.find((section) => Number(section.dataset.step) === step);
  const direction = step > currentStep ? "forward" : "reverse";
  transitionInProgress = true;
  form.dataset.transitioning = "true";
  nextButton.disabled = true; backButton.disabled = true;
  stepButtons.forEach((button) => { button.disabled = true; });
  outgoing.inert = true;
  outgoing.setAttribute("aria-hidden", "true");
  outgoing.classList.add(`is-exiting-${direction}`);
  await transitionDelay(180);
  outgoing.hidden = true;
  outgoing.classList.remove("is-active", `is-exiting-${direction}`);
  await transitionDelay(80);
  incoming.hidden = false;
  incoming.inert = false;
  incoming.setAttribute("aria-hidden", "false");
  incoming.classList.add(`is-entering-${direction}`);
  currentStep = step;
  updateStepChrome(step);
  document.querySelector("#success-message").hidden = true;
  updateEstimate();
  void incoming.offsetWidth;
  incoming.classList.add("is-active");
  await transitionDelay(240);
  incoming.classList.remove(`is-entering-${direction}`);
  transitionInProgress = false;
  delete form.dataset.transitioning;
  nextButton.disabled = false; backButton.disabled = false;
  updateStepChrome(step);
  (options.focusTarget || incoming.querySelector("h3"))?.focus({ preventScroll: true });
  return true;
}

function openLaptopSelection(category) {
  laptopCategory.value = category;
  highestStep = 1;
  if (currentStep === 1) updateStepChrome(1);
  else void goToStep(1);
  updateEstimate();
  planner.scrollIntoView({ behavior: "auto", block: "start" });
}

nextButton.addEventListener("click", async () => {
  if (reviewPreparationInProgress) return;
  if (currentStep === 2 && !scheduleValidated) {
    goToStep(1);
    return;
  }
  if (!validateCurrentStep()) return;
  if (currentStep === 1) scheduleValidated = true;
  highestStep = Math.max(highestStep, currentStep + 1);
  const nextStep = currentStep + 1;
  if (nextStep === 4) {
    reviewPreparationInProgress = true;
    nextButton.disabled = true;
    showSubmissionOverlay("review");
    try {
      await overlayDelay(800);
      hideSubmissionOverlay();
      scrollWorkflowToTop();
      await goToStep(nextStep);
    } finally {
      hideSubmissionOverlay();
      reviewPreparationInProgress = false;
      if (currentStep === 3) nextButton.disabled = false;
    }
  } else {
    await goToStep(nextStep);
  }
  if (nextStep === 4) {
    submissionStatus.textContent = "Preparing your review…";
    try {
      const response = await fetch("api/review-enquiry.php", {
        method: "POST", headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify(buildEnquiryPayload(form, journeyId)),
      });
      const body = await response.json().catch(() => null);
      if (response.status === 422 && body?.fields?.phone) {
        await returnToPhoneError(body.fields.phone);
        return;
      }
      if (!response.ok || !body?.ok) throw new Error();
      submissionStatus.textContent = "Everything looks good. Your enquiry is ready to submit.";
    } catch {
      submissionStatus.textContent = "Everything looks good. Your enquiry is ready to submit.";
    }
  }
});

backButton.addEventListener("click", () => { void goToStep(currentStep - 1); });
stepButtons.forEach((button) => button.addEventListener("click", () => { void goToStep(Number(button.dataset.stepTarget)); }));
rateCards.forEach((card) => {
  card.addEventListener("click", () => openLaptopSelection(card.dataset.laptopCategory));
  card.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" && event.key !== " ") return;
    event.preventDefault();
    openLaptopSelection(card.dataset.laptopCategory);
  });
});

technicianRequired.addEventListener("change", () => {
  updateEstimate();
});

form.addEventListener("input", updateEstimate);
form.addEventListener("change", updateEstimate);
personalFieldNames.forEach((name) => {
  form.elements[name].addEventListener("input", () => {
    const field = form.elements[name];
    const message = personalFieldError(field);
    if (personalValidationActive || message === "") setPersonalFieldError(field, message);
    if (personalValidationActive) {
      const firstMessage = personalFieldNames.map((fieldName) => personalFieldError(form.elements[fieldName])).find(Boolean) || "";
      showError("details-error", firstMessage);
    }
  });
});
phoneCountry.addEventListener("change", () => {
  const message = personalFieldError(phoneInput);
  if (personalValidationActive || message === "") setPersonalFieldError(phoneInput, message);
  if (personalValidationActive) {
    const firstMessage = personalFieldNames.map((fieldName) => personalFieldError(form.elements[fieldName])).find(Boolean) || "";
    showError("details-error", firstMessage);
  }
});
laptopCategory.addEventListener("change", clearLaptopSelectionErrorIfValid);
laptopQuantity.addEventListener("input", clearLaptopSelectionErrorIfValid);
ratePlan.addEventListener("change", () => {
  const state = plannerState();
  const message = validateBooking(state).errors.ratePlan || "";
  showError("rate-plan-error", message);
  if (message) ratePlan.setAttribute("aria-invalid", "true");
  else ratePlan.removeAttribute("aria-invalid");
  updateEstimate();
});
serviceCity.addEventListener("change", () => {
  updateCustomCityState({ clearWhenHidden: true });
  if (serviceCity.value) serviceCity.removeAttribute("aria-invalid");
  if (serviceCity.value !== "Others") showError("location-error");
  updateEstimate();
});
customCityInput.addEventListener("input", () => {
  if (customCityInput.value.trim().length >= 2) {
    customCityInput.removeAttribute("aria-invalid");
    showError("location-error");
  }
});
form.addEventListener("submit", (event) => event.preventDefault());
const submitEnquiry = createSubmissionGuard(async (payload) => {
  const response = await fetch("api/submit-enquiry.php", {
    method: "POST",
    headers: { "Content-Type": "application/json", "Accept": "application/json" },
    body: JSON.stringify(payload),
  });
  const body = await response.json().catch(() => null);
  if (response.status === 409 && body?.error === "journey_expired") {
    throw new Error("Your enquiry session has expired. Your entered details are still here; refresh when you are ready to start a new session.");
  }
  if (response.status === 422 && body?.error === "validation_failed") {
    const error = new Error(body.fields?.phone || "The enquiry could not be saved. Please check your details and try again.");
    error.fields = body.fields || {};
    throw error;
  }
  if (!response.ok || !body?.ok || !body.enquiry?.reference) {
    throw new Error("The enquiry could not be saved. Please check your details and try again.");
  }
  return body.enquiry;
});

function setSubmissionSurfacesInert(inert) {
  submissionSurfaces.forEach((surface) => {
    if (surface) surface.inert = inert;
  });
}

function showSubmissionOverlay(state) {
  const succeeded = state === "success";
  const reviewing = state === "review";
  submissionOverlay.dataset.state = state;
  submissionOverlayTitle.textContent = succeeded ? "Enquiry received" : reviewing ? "Preparing your estimate…" : "Submitting your enquiry…";
  submissionOverlayMessage.textContent = succeeded
    ? "Your quotation is ready."
    : reviewing ? "We’re organising your rental details and pricing." : "Please wait while we securely prepare your quotation.";
  submissionOverlay.toggleAttribute("aria-busy", !succeeded);
  submissionOverlay.hidden = false;
  document.body.classList.add("has-submission-overlay");
  setSubmissionSurfacesInert(true);
  submissionOverlay.focus({ preventScroll: true });
}

function hideSubmissionOverlay() {
  submissionOverlay.hidden = true;
  submissionOverlay.removeAttribute("data-state");
  submissionOverlay.removeAttribute("aria-busy");
  document.body.classList.remove("has-submission-overlay");
  setSubmissionSurfacesInert(false);
}

finishButton.addEventListener("click", async () => {
  const successMessage = document.querySelector("#success-message");
  if (finishButton.disabled || !validateCurrentStep()) return;
  successMessage.hidden = true;
  finishButton.disabled = true;
  form.setAttribute("aria-busy", "true");
  submissionStatus.textContent = "";
  showSubmissionOverlay("processing");
  try {
    const enquiry = await submitEnquiry(buildEnquiryPayload(form, journeyId));
    showSubmissionOverlay("success");
    await overlayDelay(600);
    document.querySelector("#enquiry-reference").textContent = enquiry.reference;
    document.querySelector("#success-estimate-summary").textContent = `${enquiry.currency || "NGN"} ${Number(enquiry.estimatedTotal).toLocaleString("en-NG", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    successMessage.hidden = false;
    finishButton.hidden = true;
    formActions.hidden = true;
    restartButton.hidden = false;
    const pdf = enquiry.pdf || { status: "failed", downloadUrl: null };
    downloadQuote.hidden = pdf.status !== "available";
    quotationPending.hidden = pdf.status !== "pending";
    if (pdf.status === "available" && typeof pdf.downloadUrl === "string" && pdf.downloadUrl.startsWith("/api/download-quotation.php?")) {
      downloadQuote.href = pdf.downloadUrl;
      submissionStatus.textContent = "Enquiry received. Your quotation is ready to download.";
    } else if (pdf.status === "pending") {
      downloadQuote.removeAttribute("href");
      submissionStatus.textContent = "Preparing your quotation…";
    } else {
      downloadQuote.removeAttribute("href");
      submissionStatus.textContent = "Your enquiry was received, but the quotation is not yet available for download.";
    }
    workflowComplete = true;
    highestStep = totalSteps;
    updateStepChrome(totalSteps);
    hideSubmissionOverlay();
    successMessage.focus({ preventScroll: true });
  } catch (error) {
    hideSubmissionOverlay();
    if (error.fields?.phone) {
      submissionStatus.textContent = "";
      await returnToPhoneError(error.fields.phone);
    } else {
      submissionStatus.textContent = error.message;
      finishButton.focus({ preventScroll: true });
    }
  } finally {
    form.removeAttribute("aria-busy");
    if (successMessage.hidden) finishButton.disabled = false;
  }
});
restartButton.addEventListener("click", () => {
  hideSubmissionOverlay();
  form.reset();
  clearJourneyId();
  journeyId = createJourneyId();
  highestStep = 1;
  workflowComplete = false;
  scheduleValidated = false;
  personalValidationActive = false;
  finishButton.hidden = false;
  finishButton.disabled = false;
  restartButton.hidden = true;
  downloadQuote.hidden = true;
  downloadQuote.removeAttribute("href");
  quotationPending.hidden = true;
  formActions.hidden = false;
  submissionStatus.textContent = "";
  document.querySelector("#success-estimate-summary").textContent = "";
  document.querySelector("#success-message").hidden = true;
  personalFieldNames.forEach((name) => setPersonalFieldError(form.elements[name], ""));
  phoneCountry.removeAttribute("aria-invalid");
  updateCustomCityState({ clearWhenHidden: true });
  serviceCity.removeAttribute("aria-invalid");
  customCityInput.removeAttribute("aria-invalid");
  laptopCategory.removeAttribute("aria-invalid");
  ratePlan.removeAttribute("aria-invalid");
  laptopQuantity.removeAttribute("aria-invalid");
  ["location-error", "dates-error", "quantity-error", "rate-plan-error", "technician-error", "details-error"].forEach((id) => showError(id));
  if (currentStep === 1) updateStepChrome(1);
  else void goToStep(1);
});

document.querySelectorAll(".form-step h3").forEach((heading) => heading.setAttribute("tabindex", "-1"));
document.querySelector("#year").textContent = new Date().getFullYear();
populatePhoneCountries();
updateCustomCityState();
updateEstimate();
planner.dataset.currentStep = "1";
steps.forEach((section, index) => { section.inert = index !== 0; section.setAttribute("aria-hidden", index === 0 ? "false" : "true"); });
