# ATLAS Rentals

ATLAS Rentals is the DY-PLUS laptop-rental enquiry planner for Lagos and Abuja. Its existing four-step experience provides a deterministic itemized estimate and securely submits validated enquiries for review. An enquiry is not a confirmed booking; availability and final pricing remain subject to DY-PLUS confirmation.

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

The same-origin PHP endpoint validates and normalizes the enquiry, recalculates pricing, and stores the normalized payload, pricing snapshot, contact and rental details. Identical normalized retries return the original enquiry reference; materially changed content creates a new enquiry. No PDF, email, CRM, payment or booking-confirmation integration is included in AR-H1.

Each browser enquiry session uses a timestamped, cryptographically random submission identifier. The endpoint checks intrinsic expiry and private state before database lookup; expired identifiers fail closed and remain represented by bounded tombstones. Because expiry is encoded in the identifier, an old value remains expired even after its tombstone is eventually cleaned. Basic per-address rate limiting is also stored privately outside the public webroot.

Production database credentials must be loaded from a PHP file outside `public_html`. See `docs/DEPLOYMENT.md`; never commit the real loader or its values.
