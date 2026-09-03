# ATLAS Rentals

ATLAS Rentals is the DY-PLUS laptop-rental enquiry planner for Nigerian service cities. Its four-step experience lets a customer select one laptop category, quantity and weekday rental dates, provides a deterministic daily-rate estimate based on Monday-to-Friday billable days, and securely submits validated enquiries for review. An enquiry is not a confirmed booking; availability and final pricing remain subject to DY-PLUS confirmation.

## Run locally

Serve the repository from any static web server and open `index.html`. ES modules do not reliably load from a `file://` URL.

Example with Python:

```text
python -m http.server 8080
```

Then visit `http://localhost:8080`.

## Validation

```text
npm ci
npm run build
npm test
npm run check
composer install --no-dev --classmap-authoritative
php tests/php/dependency-foundation.test.php
```

The dependency lockfiles are the release authority. `npm run build` creates the local, deployable `js/vendor/libphonenumber.js` browser bundle; browsers never fetch phone metadata from a CDN or package registry. Composer installs the PHP phone metadata under `vendor/`. The contact step uses the bundled browser metadata for advisory country-aware validation, while PHP independently validates and converts accepted numbers to E.164 before persistence and delivery.

See `docs/` for architecture, business rules, roadmap and deployment guidance.

## Enquiry persistence

The same-origin PHP endpoints validate and normalize planner data, independently reject weekend endpoints and unsupported rate plans, calculate authoritative working-day pricing, persist an auditable snapshot, synchronize review-stage CRM leads, generate private quotation PDFs, and deliver separate client and administrator emails. Identical retries return the original enquiry reference and resume unfinished delivery; materially changed content creates a new enquiry. No payment or booking-confirmation integration is included.

Journey identifiers are checked against private server-side expiry state before submission. Expired identifiers fail closed and remain represented by bounded tombstones, preventing silent reuse during their retention period.

Production database and integration credentials must be loaded from PHP files outside `public_html`. See `docs/DEPLOYMENT.md`; never commit real loaders or values.
