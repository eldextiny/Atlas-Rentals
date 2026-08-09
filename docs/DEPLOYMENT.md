# Deployment

## Scope

AR-H1 combines the static planner with a same-origin PHP JSON endpoint and MySQL persistence. Deployment remains a separately approved manual operation.

## Pre-release checks

1. Run `npm test`.
2. Run `npm run check`.
3. Run `php tests/php/enquiry-service.test.php` and PHP syntax checks in an isolated environment.
4. Serve the site locally over HTTP and walk through all city choices, both laptop types, all three rate plans and incompatible 7/30-day boundaries, optional services, submission failure and confirmed enquiry receipt.
5. Verify keyboard navigation and responsive layouts at narrow and wide widths.
6. Confirm the canonical URL and social metadata remain unchanged.

## Runtime and configuration

- Serve `index.html`, `css/`, and `js/` from the same origin.
- Serve `api/submit-enquiry.php` through PHP 8.1 or newer with PDO MySQL enabled.
- Serve JavaScript modules with a valid JavaScript MIME type.
- Use HTTPS in production.
- Do not cache `index.html` longer than versioned assets unless a coordinated cache strategy exists.
- Add security headers at the hosting layer during a separately approved deployment milestone.

The endpoint reads the loader path from `ATLAS_RENTALS_DB_CONFIG`, falling back to the approved Cloudways private path. The loader must remain outside `public_html`, be readable by PHP, and return `host`, `port`, `database`, `username`, `password`, and `charset`. `api/database-config.example.php` documents this shape with placeholders only. Never copy production values into the repository.

Integration configuration is loaded from `/home/548005.cloudwaysapps.com/ezgshksprf/private_html/atlas-rentals-integrations.php`, with the documented `ATLAS_RENTALS_*` environment variables taking precedence. It supplies Resend sender/administrator settings and the bearer-authenticated CRM adapter. `api/integrations-config.example.php` contains placeholders only.

Create and verify these private, non-public runtime directories before release:

- `/home/548005.cloudwaysapps.com/ezgshksprf/private_html/atlas-rentals/delivery-state`
- `/home/548005.cloudwaysapps.com/ezgshksprf/private_html/atlas-rentals/quotation-pdfs`

PHP must be able to read and write both directories. The application does not alter their permissions. State and PDF retention is 30 days; quotation validity is 30 days and attachments are limited to 8 MB. PHP cURL and outbound HTTPS are required for CRM and Resend.

The pricing snapshot JSON stores the selected rate plan, duration blocks, all laptop rates, per-unit and equipment amounts, technician, delivery, subtotal, VAT and total without a schema change. New enquiries accept only Daily, Weekly and Monthly plans. Historical records, including stored `best` snapshots, are not rewritten or repriced and remain available to legitimate duplicate and delivery retries. The existing InnoDB tables `atlas_rental_enquiries` and `atlas_rental_reference_counters` are required. The nullable `delivery_address` and existing `description` columns receive SQL `NULL`; no schema creation or migration runs. Tests must use private temporary directories, the in-memory store, or a dedicated non-production database and must never write test enquiries to production.

## Release unit

Upload the HTML, CSS, JavaScript and PHP runtime files atomically so the interface and server contract cannot be mixed across versions. Do not publish documentation, tests, the configuration example, or private configuration loader from the web document root.

## Rollback

Retain the previous complete static release. If validation fails, restore the entire previous release as one unit rather than replacing individual runtime files.
