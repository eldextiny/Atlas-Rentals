# ATLAS Rentals

ATLAS Rentals is the DY-PLUS laptop-rental enquiry planner for Nigerian service cities. Its four-step experience lets a customer select one laptop category, quantity and required Daily, Weekly, Monthly or Best Available rate plan, provides a deterministic itemized estimate, and securely submits validated enquiries for review. An enquiry is not a confirmed booking; availability and final pricing remain subject to DY-PLUS confirmation.

## Run locally

Serve the repository from any static web server and open `index.html`. ES modules do not reliably load from a `file://` URL.

Example with Python:

```text
python -m http.server 8080
```

Then visit `http://localhost:8080`.

## Validation

```text
npm test
npm run check
```

See `docs/` for architecture, business rules, roadmap and deployment guidance.

## Enquiry persistence

The same-origin PHP endpoints validate and normalize planner data, independently apply the selected rate plan, persist an auditable pricing snapshot, synchronize review-stage CRM leads, generate private quotation PDFs, and deliver separate client and administrator emails. Identical retries return the original enquiry reference and resume unfinished delivery; materially changed content creates a new enquiry. No payment or booking-confirmation integration is included.

Production database and integration credentials must be loaded from PHP files outside `public_html`. See `docs/DEPLOYMENT.md`; never commit real loaders or values.
