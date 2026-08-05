# Architecture

## Purpose

AR-H1 is a framework-free rental enquiry workflow. It persists enquiries for DY-PLUS review but deliberately excludes confirmed bookings and external delivery integrations.

## Components

- `index.html` contains semantic page structure, the four-step planner, SEO metadata and structured data.
- `css/styles.css` contains responsive presentation, accessible focus states and reduced-motion handling.
- `js/pricing.js` is the business-rule boundary. Its pure functions calculate inclusive rental duration, itemized estimates and booking validation results.
- `js/app.js` coordinates browser state, step navigation, form feedback, estimate rendering and the final local review.
- `js/enquiry.js` builds the stable normalized browser payload and guards in-flight submissions.
- `api/submit-enquiry.php` is the bounded same-origin JSON boundary and loads database configuration from outside the public document root.
- `api/enquiry-service.php` owns server validation, authoritative pricing, canonical idempotency hashing and transactional persistence.
- `api/review-enquiry.php` synchronizes an idempotent CRM review draft using the journey identity.
- `api/integration-runtime.php` owns private configuration, recovery state, CRM transport, reusable PDF generation and recipient-specific Resend delivery.
- `tests/pricing.test.mjs` protects the deterministic calculation contract using Node's built-in test runner.

## Data flow

Form values remain in their controls while users move through the four steps. The browser renders an estimate, then submits a stable payload. The server independently normalizes and validates it, recalculates pricing, hashes the canonical material content, and stores it in MySQL. Success is shown only after the transaction commits.

The yearly counter row is locked with `SELECT ... FOR UPDATE`. Counter increment and enquiry insertion occur in one InnoDB transaction. An identical retry resolves by the unique idempotency hash and returns its original reference. A failed transaction rolls back the counter increment.

The browser creates one random journey identity per planner journey. Review-stage CRM calls reuse it and update the same private recovery state. Submission persists first, then resumes CRM reference synchronization, PDF generation, client email and administrator email. Each completed operation remains completed across retries. PDFs and state remain outside `public_html` and expire after 30 days.

## Design boundaries

Pricing constants and formulas remain outside DOM code. Browser totals are display-only; the PHP service is the persistence authority and never accepts client rates or totals.

## Accessibility

The interface uses semantic landmarks, explicit labels, fieldsets, live estimate updates, keyboard-operable step controls, visible focus styles, inline validation messages and reduced-motion support.
