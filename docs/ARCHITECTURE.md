# Architecture

## Purpose

AR-H1 is a framework-free rental enquiry workflow. It persists enquiries for DY-PLUS review but deliberately excludes confirmed bookings and external delivery integrations.

## Components

- `index.html` contains semantic page structure, the four-step planner, SEO metadata and structured data.
- `css/styles.css` contains responsive presentation, accessible focus states and reduced-motion handling.
- `js/pricing.js` is the browser business-rule boundary. Its pure functions parse UTC calendar dates, reject weekend endpoints, calculate inclusive Monday-to-Friday duration, enforce daily-only pricing, and produce itemized estimates.
- `js/app.js` coordinates browser state, step navigation, form feedback, estimate rendering and the final local review.
- `js/enquiry.js` builds the stable normalized browser payload and guards in-flight submissions.
- `api/submit-enquiry.php` is the bounded same-origin JSON boundary and loads database configuration from outside the public document root.
- `api/rentals-pricing.php` owns reusable authoritative server pricing constants and deterministic tier helpers.
- `api/enquiry-service.php` owns server validation, authoritative pricing, canonical idempotency hashing and transactional persistence.
- `api/journey-identifier.php` owns private, atomic journey expiry state and bounded tombstones.
- `api/review-enquiry.php` synchronizes an idempotent CRM review draft using the journey identity.
- `api/integration-runtime.php` owns private configuration, recovery state, CRM transport, reusable PDF generation and recipient-specific Resend delivery.
- `tests/pricing.test.mjs` protects the deterministic calculation contract using Node's built-in test runner.
- `composer.json` and `composer.lock` pin the server-side `giggsey/libphonenumber-for-php` dependency for installation through Composer's optimized production autoloader.
- `package.json` and `package-lock.json` pin `libphonenumber-js` and the Rollup toolchain. `rollup.config.mjs` deterministically bundles `js/vendor-src/libphonenumber-entry.js` into the deployable `js/vendor/libphonenumber.js` ES module.

## Data flow

Form values remain in their controls while users move through the four steps. Step 2 holds one selected laptop category and one quantity; a fixed daily-plan value is submitted without exposing alternative choices. The browser maps the category to the existing standard/high-performance quantity fields and forces the unselected field to zero. The browser renders an estimate, then submits the stable payload. The server independently normalizes dates in UTC, rejects weekend endpoints, counts Monday-to-Friday days inclusively, rejects every non-daily plan, recalculates pricing, hashes the canonical material content, and stores the full audit snapshot in MySQL. Success is shown only after the transaction commits.

The yearly counter row is locked with `SELECT ... FOR UPDATE`. Counter increment and enquiry insertion occur in one InnoDB transaction. An identical retry resolves by the unique idempotency hash and returns its original reference. A failed transaction rolls back the counter increment.

The browser creates one random journey identity per planner journey. Review-stage CRM calls reuse it and update the same private recovery state. Submission persists first, then resumes CRM reference synchronization, PDF generation, client email and administrator email. Each completed operation remains completed across retries. PDFs and state remain outside `public_html` and expire after 30 days.

Historical records are read through their stored pricing snapshots. A historical `best` payload is eligible only for an existing idempotency-hash lookup; if no matching record exists it fails validation before pricing or persistence. Historical snapshots continue through CRM, email, PDF and retry presentation without repricing.

Journey identifiers are registered on first valid server use with a fixed 24-hour lifetime. The server checks the incoming identifier before cleanup, converts expired state to a retained tombstone, and returns a safe conflict without reaching persistence or delivery. Tombstones are retained for 90 days and cleanup never deletes the identifier currently being checked.

## Phone dependency foundation

The contact step builds its country list from the locally bundled libphonenumber metadata and defaults to Nigeria. National-format input is interpreted against the selected ISO region; a leading `+` invokes international parsing independently of that selection. Browser validation is advisory and builds a normalized request copy without overwriting the customer's visible input.

The request-only `phoneCountry` value gives PHP the region context needed for independent validation. `EnquiryService` loads Composer's production autoloader, rejects invalid or unsupported combinations, and replaces the request phone with E.164 before canonical hashing and persistence. `phoneCountry` is intentionally excluded from the normalized payload and database contract, so PDF, email and CRM continue receiving only the existing authoritative `phone` field. A server `fields.phone` error returns the browser to Step 3 without resetting any controls.

## Design boundaries

Pricing constants and formulas remain outside DOM code. Browser totals are display-only; the PHP service is the persistence authority and never accepts client rates or totals.

## Accessibility

The interface uses semantic landmarks, explicit labels, fieldsets, live estimate updates, keyboard-operable step controls, accessible progress check marks, visible focus styles, inline validation messages and reduced-motion support.
