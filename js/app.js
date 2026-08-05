import { calculateEstimate, calculateRentalDays, validateBooking } from "./pricing.js";
import { buildEnquiryPayload, createSubmissionGuard } from "./enquiry.js";

const form = document.querySelector("#rental-form");
const steps = [...document.querySelectorAll(".form-step")];
const stepButtons = [...document.querySelectorAll(".step")];
const nextButton = document.querySelector("#next-button");
const backButton = document.querySelector("#back-button");
const technicianRequired = document.querySelector("#technician-required");
const technicianDaysWrap = document.querySelector("#technician-days-wrap");
const technicianDaysInput = document.querySelector("#technician-days");
const rateCards = [...document.querySelectorAll("[data-rate-target]")];
const restartButton = document.querySelector("#restart-button");
const finishButton = document.querySelector("#finish-button");
const submissionStatus = document.querySelector("#submission-status");
const totalSteps = 4;
const currency = new Intl.NumberFormat("en-NG", {
  style: "currency",
  currency: "NGN",
  maximumFractionDigits: 0,
});

let currentStep = 1;
let highestStep = 1;
let scheduleValidated = false;

function numberValue(name) {
  const value = Number(form.elements[name].value);
  return Number.isInteger(value) && value >= 0 ? value : 0;
}

function selectedLocation() {
  return form.elements.location.value;
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
  return {
    location: selectedLocation(),
    startDate: form.elements.startDate.value,
    endDate: form.elements.endDate.value,
    standardQuantity: numberValue("standardQuantity"),
    performanceQuantity: numberValue("performanceQuantity"),
    rentalDays: rentalDays(),
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

function updateEstimate() {
  const state = plannerState();
  const result = estimate();
  setText("#standard-summary", `${result.standardQuantity} × ${result.rentalDays} days`);
  setText("#performance-summary", `${result.performanceQuantity} × ${result.rentalDays} days`);
  setText("#standard-cost", currency.format(result.standardRental));
  setText("#performance-cost", currency.format(result.performanceRental));
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
  return `
    <div class="summary-line"><span>Standard laptops (${result.standardQuantity} × ${result.rentalDays} days)</span><strong>${currency.format(result.standardRental)}</strong></div>
    <div class="summary-line"><span>High performance (${result.performanceQuantity} × ${result.rentalDays} days)</span><strong>${currency.format(result.performanceRental)}</strong></div>
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
      <div class="summary-line"><span>Delivery address</span><strong>${escaped(values.deliveryAddress)}</strong></div>
      <div class="summary-line"><span>Dates</span><strong>${escaped(state.startDate)} to ${escaped(state.endDate)} (${state.rentalDays} days)</strong></div>
    </div>
    <div class="summary-group"><h4>Equipment &amp; support</h4>
      <div class="summary-line"><span>Standard Business</span><strong>${result.standardQuantity}</strong></div>
      <div class="summary-line"><span>High Performance</span><strong>${result.performanceQuantity}</strong></div>
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

function validateCurrentStep() {
  const state = plannerState();
  const booking = validateBooking(state);
  showError("location-error");
  showError("dates-error");
  showError("quantity-error");
  showError("technician-error");
  showError("details-error");

  if (currentStep === 1) {
    showError("location-error", booking.errors.location);
    showError("dates-error", booking.errors.dates);
    if (booking.errors.location) {
      form.querySelector('input[name="location"]').focus();
      return false;
    }
    if (booking.errors.dates) {
      const dateTarget = form.elements.startDate.value ? form.elements.endDate : form.elements.startDate;
      dateTarget.focus();
      return false;
    }
    if (!form.elements.deliveryAddress.checkValidity()) {
      showError("dates-error", "Enter the delivery address for this enquiry.");
      form.elements.deliveryAddress.focus();
      return false;
    }
    return true;
  }
  if (currentStep === 2) {
    showError("quantity-error", booking.errors.quantity);
    if (booking.errors.quantity) {
      form.elements.standardQuantity.focus();
      return false;
    }
    return true;
  }
  if (currentStep === 3) {
    showError("technician-error", booking.errors.technician);
    if (booking.errors.technician) {
      technicianDaysInput.focus();
      return false;
    }
    const required = [...steps[2].querySelectorAll("[required]")];
    const invalid = required.find((field) => !field.checkValidity());
    if (invalid) {
      showError("details-error", "Complete all required personal details with valid information.");
      invalid.focus();
      return false;
    }
  }
  return true;
}

function goToStep(step, options = {}) {
  if (step < 1 || step > totalSteps || step > highestStep) return;
  currentStep = step;
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
  const focusTarget = options.focusTarget || steps[step - 1].querySelector("h3");
  const scrollBehavior = options.scrollBehavior || "smooth";
  focusTarget.focus({ preventScroll: true });
  document.querySelector("#planner").scrollIntoView({ behavior: scrollBehavior, block: "start" });
}

function openLaptopSelection(inputId) {
  const quantityInput = document.querySelector(`#${inputId}`);
  if (!quantityInput) return;

  highestStep = Math.max(highestStep, 2);
  const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  goToStep(2, {
    focusTarget: quantityInput,
    scrollBehavior: reducedMotion ? "auto" : "smooth",
  });
}

nextButton.addEventListener("click", () => {
  if (currentStep === 2 && !scheduleValidated) {
    goToStep(1);
    return;
  }
  if (!validateCurrentStep()) return;
  if (currentStep === 1) scheduleValidated = true;
  highestStep = Math.max(highestStep, currentStep + 1);
  goToStep(currentStep + 1);
});

backButton.addEventListener("click", () => goToStep(currentStep - 1));
stepButtons.forEach((button) => button.addEventListener("click", () => goToStep(Number(button.dataset.stepTarget))));
rateCards.forEach((card) => {
  card.addEventListener("click", () => openLaptopSelection(card.dataset.rateTarget));
  card.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" && event.key !== " ") return;
    event.preventDefault();
    openLaptopSelection(card.dataset.rateTarget);
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
    const enquiry = await submitEnquiry(buildEnquiryPayload(form));
    document.querySelector("#enquiry-reference").textContent = enquiry.reference;
    document.querySelector("#success-message").hidden = false;
    submissionStatus.textContent = "Enquiry received.";
    finishButton.hidden = true;
  } catch (error) {
    submissionStatus.textContent = error.message;
  } finally {
    form.removeAttribute("aria-busy");
    if (document.querySelector("#success-message").hidden) finishButton.disabled = false;
  }
});
restartButton.addEventListener("click", () => {
  form.reset();
  currentStep = 1;
  highestStep = 1;
  scheduleValidated = false;
  technicianDaysWrap.hidden = true;
  technicianDaysInput.disabled = true;
  finishButton.hidden = false;
  finishButton.disabled = false;
  submissionStatus.textContent = "";
  document.querySelector("#success-message").hidden = true;
  ["location-error", "dates-error", "quantity-error", "technician-error", "details-error"].forEach((id) => showError(id));
  const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  goToStep(1, { scrollBehavior: reducedMotion ? "auto" : "smooth" });
});

document.querySelectorAll(".form-step h3").forEach((heading) => heading.setAttribute("tabindex", "-1"));
document.querySelector("#year").textContent = new Date().getFullYear();
technicianDaysInput.disabled = true;
updateEstimate();
