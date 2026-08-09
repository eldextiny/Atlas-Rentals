import { calculateEstimate, calculateRentalDays, LAPTOP_CATALOGUE, PRICING, RATE_PLANS, validateBooking } from "./pricing.js";
import { buildEnquiryPayload, clearJourneyId, createJourneyId, createSubmissionGuard, personalDetailsError } from "./enquiry.js";

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
const categoryDetails = document.querySelector("#category-details");
const technicianRequired = document.querySelector("#technician-required");
const technicianDaysWrap = document.querySelector("#technician-days-wrap");
const technicianDaysInput = document.querySelector("#technician-days");
const rateCards = [...document.querySelectorAll("[data-laptop-category]")];
const restartButton = document.querySelector("#restart-button");
const finishButton = document.querySelector("#finish-button");
const submissionStatus = document.querySelector("#submission-status");
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

function numberValue(name) {
  const value = Number(form.elements[name].value);
  return Number.isInteger(value) && value >= 0 ? value : 0;
}

function selectedLocation() {
  return serviceCity.value === "Others" ? customCityInput.value.trim() : serviceCity.value;
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
  return {
    location: selectedLocation(),
    startDate: form.elements.startDate.value,
    endDate: form.elements.endDate.value,
    laptopCategory: category,
    laptopQuantity: quantity,
    standardQuantity: category === "standard" ? quantity : 0,
    performanceQuantity: category === "performance" ? quantity : 0,
    rentalDays: rentalDays(),
    ratePlan: ratePlan.value,
    technicianRequired: techSelected,
    technicianDays: techSelected ? numberValue("technicianDays") : 0,
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
  const selectedPlan = RATE_PLANS[plan];
  if (!details || !selectedPlan) {
    ratePlanHelp.textContent = "Choose how the inclusive rental duration should be priced.";
    return;
  }
  const applicable = plan === "daily" ? `Daily rate: ${currency.format(details.dailyRate)}.`
    : plan === "weekly" ? `Weekly rate: ${currency.format(details.weeklyRate)} per 7 days.`
      : plan === "monthly" ? `Monthly rate: ${currency.format(details.monthlyRate)} per 30 days.`
        : `Uses monthly ${currency.format(details.monthlyRate)}, weekly ${currency.format(details.weeklyRate)}, then daily ${currency.format(details.dailyRate)} blocks.`;
  ratePlanHelp.textContent = `${selectedPlan.help} ${applicable}`;
}

function renderCategoryDetails(category) {
  const details = LAPTOP_CATALOGUE[category] ?? null;
  categoryDetails.hidden = details === null;
  if (!details) {
    categoryDetails.replaceChildren();
    return;
  }
  categoryDetails.innerHTML = `<span class="selected-category-state">Selected category</span><h4>${details.title}</h4><div class="category-rates"><span>Daily: <strong>${currency.format(details.dailyRate)}</strong></span><span>Weekly — ${PRICING.daysPerWeek} days: <strong>${currency.format(details.weeklyRate)}</strong></span><span>Monthly — ${PRICING.daysPerMonth} days: <strong>${currency.format(details.monthlyRate)}</strong></span></div><p class="category-best-use"><strong>Best suited for:</strong> ${details.bestSuitedFor}</p><div class="category-specs">${details.features.map((detail) => `<span>${detail}</span>`).join("")}</div><p class="category-minimum">Minimum quantity: ${PRICING.minimumLaptopQuantity} laptops</p>`;
}

function updateEstimate() {
  const state = plannerState();
  const result = estimate();
  renderCategoryDetails(state.laptopCategory);
  renderRatePlanHelp(state.laptopCategory, state.ratePlan);
  setText("#standard-summary", `${result.standardQuantity} × ${result.rentalDays} days`);
  setText("#performance-summary", `${result.performanceQuantity} × ${result.rentalDays} days`);
  setText("#standard-cost", currency.format(result.standardRental));
  setText("#performance-cost", currency.format(result.performanceRental));
  document.querySelector("#standard-estimate-row").hidden = state.laptopCategory !== "standard";
  document.querySelector("#performance-estimate-row").hidden = state.laptopCategory !== "performance";
  setText("#delivery-retrieval-cost", currency.format(result.deliveryRetrieval));
  setText("#technician-summary", result.technicianDays ? `${result.technicianDays} days` : "Not selected");
  setText("#technician-cost", currency.format(result.technician));
  document.querySelector("#technician-estimate-row").hidden = currentStep === 4 && result.technicianDays === 0;
  setText("#vat-cost", currency.format(result.vat));
  setText("#total-cost", currency.format(result.total));
  setText("#estimate-duration", result.rentalDays ? `${result.rentalDays} day${result.rentalDays === 1 ? "" : "s"}` : "Dates pending");

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
  const categoryLine = `<div class="summary-group tiered-rental-summary"><h5>${categoryName}</h5><div class="summary-line"><span>Rate plan</span><strong>${result.ratePlanLabel}</strong></div><div class="summary-line"><span>Quantity</span><strong>${quantity}</strong></div><div class="summary-line"><span>Applied duration</span><strong>${result.durationLabel}</strong></div><div class="summary-line"><span>Applied rates</span><strong>${selectedPricing.months ? `${selectedPricing.months} × ${currency.format(selectedPricing.monthlyRate)} monthly` : ""}${selectedPricing.months && (selectedPricing.weeks || selectedPricing.days) ? " + " : ""}${selectedPricing.weeks ? `${selectedPricing.weeks} × ${currency.format(selectedPricing.weeklyRate)} weekly` : ""}${selectedPricing.weeks && selectedPricing.days ? " + " : ""}${selectedPricing.days ? `${selectedPricing.days} × ${currency.format(selectedPricing.dailyRate)} daily` : ""}</strong></div><div class="summary-line"><span>Per-unit rental</span><strong>${currency.format(selectedPricing.perUnitRental)}</strong></div><div class="summary-line"><span>Equipment amount</span><strong>${currency.format(equipmentAmount)}</strong></div></div>`;
  return `
    ${categoryLine}
    <div class="summary-line"><span>Rental subtotal</span><strong>${currency.format(result.rentalSubtotal)}</strong></div>
    <div class="summary-line"><span>Delivery &amp; Retrieval (compulsory, once per booking)</span><strong>${currency.format(result.deliveryRetrieval)}</strong></div>
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
      <div class="summary-line"><span>Dates</span><strong>${escaped(state.startDate)} to ${escaped(state.endDate)} (${state.rentalDays} days)</strong></div>
    </div>
    <div class="summary-group"><h4>Equipment &amp; support</h4>
      <div class="summary-line"><span>${state.laptopCategory === "standard" ? "Standard Business Laptop" : "High Performance Laptop"}</span><strong>${state.laptopQuantity}</strong></div>
      <div class="summary-line"><span>Delivery &amp; Retrieval</span><strong>Compulsory</strong></div>
      ${state.technicianRequired ? `<div class="summary-line"><span>Technician</span><strong>${result.technicianDays} days</strong></div>` : ""}
    </div>
    <div class="summary-group"><h4>Personal details</h4>
      <div class="summary-line"><span>Contact</span><strong>${escaped(values.fullName)}</strong></div>
      <div class="summary-line"><span>Organization</span><strong>${escaped(values.organization)}</strong></div>
      <div class="summary-line"><span>Email</span><strong>${escaped(values.email)}</strong></div>
      <div class="summary-line"><span>Phone</span><strong>${escaped(values.phone)}</strong></div>
    </div>
    <div class="summary-group"><h4>Estimate</h4>${estimateMarkup(result)}</div>`;
}

function showError(id, message = "") {
  document.querySelector(`#${id}`).textContent = message;
}

function personalFieldError(field) {
  return personalDetailsError(field.name, field.value, field.validity);
}

function setPersonalFieldError(field, message) {
  showError(`${field.id}-error`, message);
  if (message) field.setAttribute("aria-invalid", "true");
  else field.removeAttribute("aria-invalid");
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
      const dateTarget = form.elements.startDate.value ? form.elements.endDate : form.elements.startDate;
      dateTarget.focus();
      return false;
    }
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
      technicianDaysInput.focus();
      return false;
    }
    if (!validatePersonalDetails()) return false;
  }
  return true;
}

function goToStep(step, options = {}) {
  if (step < 1 || step > totalSteps || step > highestStep) return;
  currentStep = step;
  planner.dataset.currentStep = String(step);
  steps.forEach((section) => {
    const active = Number(section.dataset.step) === step;
    section.hidden = !active;
    section.classList.toggle("is-active", active);
  });
  stepButtons.forEach((button, index) => {
    const buttonStep = index + 1;
    button.disabled = buttonStep > highestStep;
    button.classList.toggle("is-active", buttonStep === step);
    const completed = buttonStep < highestStep && (buttonStep !== 1 || scheduleValidated);
    button.classList.toggle("is-complete", completed);
    button.toggleAttribute("aria-current", buttonStep === step);
  });
  backButton.hidden = step === 1;
  nextButton.hidden = step === totalSteps;
  nextButton.textContent = step === 3 ? "Review estimate" : "Continue";
  document.querySelector("#success-message").hidden = true;
  updateEstimate();
  if (options.focusTarget) options.focusTarget.focus({ preventScroll: true });
}

function openLaptopSelection(category) {
  laptopCategory.value = category;
  currentStep = 1;
  highestStep = 1;
  goToStep(1);
  updateEstimate();
  planner.scrollIntoView({ behavior: "auto", block: "start" });
}

nextButton.addEventListener("click", async () => {
  if (currentStep === 2 && !scheduleValidated) {
    goToStep(1);
    return;
  }
  if (!validateCurrentStep()) return;
  if (currentStep === 1) scheduleValidated = true;
  highestStep = Math.max(highestStep, currentStep + 1);
  const nextStep = currentStep + 1;
  goToStep(nextStep);
  if (nextStep === 4) {
    submissionStatus.textContent = "Preparing your review…";
    try {
      const response = await fetch("api/review-enquiry.php", {
        method: "POST", headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify(buildEnquiryPayload(form, journeyId)),
      });
      const body = await response.json().catch(() => null);
      if (!response.ok || !body?.ok) throw new Error();
      submissionStatus.textContent = body.crm === "accepted"
        ? "Review ready."
        : "Review ready. CRM synchronization is pending and will be retried safely.";
    } catch {
      submissionStatus.textContent = "Review ready. CRM synchronization is pending and will be retried safely.";
    }
  }
});

backButton.addEventListener("click", () => goToStep(currentStep - 1));
stepButtons.forEach((button) => button.addEventListener("click", () => goToStep(Number(button.dataset.stepTarget))));
rateCards.forEach((card) => {
  card.addEventListener("click", () => openLaptopSelection(card.dataset.laptopCategory));
  card.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" && event.key !== " ") return;
    event.preventDefault();
    openLaptopSelection(card.dataset.laptopCategory);
  });
});

technicianRequired.addEventListener("change", () => {
  technicianDaysWrap.hidden = !technicianRequired.checked;
  technicianDaysInput.disabled = !technicianRequired.checked;
  if (technicianRequired.checked && numberValue("technicianDays") === 0) technicianDaysInput.value = "1";
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
  if (!response.ok || !body?.ok || !body.enquiry?.reference) {
    throw new Error("The enquiry could not be saved. Please check your details and try again.");
  }
  return body.enquiry;
});

finishButton.addEventListener("click", async () => {
  document.querySelector("#success-message").hidden = true;
  finishButton.disabled = true;
  form.setAttribute("aria-busy", "true");
  submissionStatus.textContent = "Submitting your enquiry securely…";
  try {
    const enquiry = await submitEnquiry(buildEnquiryPayload(form, journeyId));
    document.querySelector("#enquiry-reference").textContent = enquiry.reference;
    document.querySelector("#success-message").hidden = false;
    submissionStatus.textContent = enquiry.deliveryComplete
      ? "Enquiry received and quotation delivered."
      : "Enquiry received. Quotation delivery is pending and can be retried safely.";
    finishButton.hidden = true;
    formActions.hidden = true;
  } catch (error) {
    submissionStatus.textContent = error.message;
  } finally {
    form.removeAttribute("aria-busy");
    if (document.querySelector("#success-message").hidden) finishButton.disabled = false;
  }
});
restartButton.addEventListener("click", () => {
  form.reset();
  clearJourneyId();
  journeyId = createJourneyId();
  currentStep = 1;
  highestStep = 1;
  scheduleValidated = false;
  personalValidationActive = false;
  technicianDaysWrap.hidden = true;
  technicianDaysInput.disabled = true;
  finishButton.hidden = false;
  finishButton.disabled = false;
  formActions.hidden = false;
  submissionStatus.textContent = "";
  document.querySelector("#success-message").hidden = true;
  personalFieldNames.forEach((name) => setPersonalFieldError(form.elements[name], ""));
  updateCustomCityState({ clearWhenHidden: true });
  serviceCity.removeAttribute("aria-invalid");
  customCityInput.removeAttribute("aria-invalid");
  laptopCategory.removeAttribute("aria-invalid");
  ratePlan.removeAttribute("aria-invalid");
  laptopQuantity.removeAttribute("aria-invalid");
  ["location-error", "dates-error", "quantity-error", "rate-plan-error", "technician-error", "details-error"].forEach((id) => showError(id));
  goToStep(1);
});

document.querySelectorAll(".form-step h3").forEach((heading) => heading.setAttribute("tabindex", "-1"));
document.querySelector("#year").textContent = new Date().getFullYear();
technicianDaysInput.disabled = true;
updateCustomCityState();
updateEstimate();
planner.dataset.currentStep = "1";
