# Deployment

## Scope

AR-H1 combines the static planner with a same-origin PHP JSON endpoint and MySQL persistence. Deployment remains a separately approved manual operation.

## Pre-release checks

1. Run `composer validate --strict`.
2. Run `composer install --no-dev --classmap-authoritative --no-interaction` from the committed lockfile.
3. Run `npm ci` from the committed lockfile.
4. Run `npm run build` and verify `js/vendor/libphonenumber.js` exists.
5. Run `npm test` and `npm run check`.
6. Run `php tests/php/dependency-foundation.test.php`, `php tests/php/enquiry-service.test.php` and PHP syntax checks in an isolated environment.
7. Serve the site locally over HTTP and walk through all city choices, both laptop types, all three rate plans and incompatible 7/30-day boundaries, optional services, submission failure and confirmed enquiry receipt.
8. Verify keyboard navigation and responsive layouts at narrow and wide widths.
9. Confirm the canonical URL and social metadata remain unchanged.

## Reproducible dependency build

Cloudways uses PHP 8.4.24, Composer 2.10.2, Node 18.17.1 and npm 9.6.7. Do not run dependency-update commands during deployment. `composer.lock` and `package-lock.json` are the only approved resolution inputs.

```text
composer install --no-dev --classmap-authoritative --no-interaction
npm ci
npm run build
```

Composer creates the production `vendor/` tree. npm creates the temporary build-time `node_modules/` tree. Rollup creates `js/vendor/libphonenumber.js`, the only browser libphonenumber runtime artifact. The browser bundle is served from the application origin and must never be replaced with a CDN URL or a runtime package-registry import. Phase A does not load this bundle from `index.html` and does not load Composer's autoloader from the enquiry endpoints.

## Runtime and configuration

- Serve `index.html`, `css/`, and `js/` from the same origin.
- Serve `api/submit-enquiry.php` through PHP 8.1 or newer with PDO MySQL enabled.
- Serve JavaScript modules with a valid JavaScript MIME type.
- Deploy the generated `js/vendor/libphonenumber.js` file with the static JavaScript release and retain the Composer-generated `vendor/` tree for the later server integration phase.
- Use HTTPS in production.
- Do not cache `index.html` longer than versioned assets unless a coordinated cache strategy exists.
- Add security headers at the hosting layer during a separately approved deployment milestone.

The endpoint reads the loader path from `ATLAS_RENTALS_DB_CONFIG`, falling back to the approved Cloudways private path. The loader must remain outside `public_html`, be readable by PHP, and return `host`, `port`, `database`, `username`, `password`, and `charset`. `api/database-config.example.php` documents this shape with placeholders only. Never copy production values into the repository.

Integration configuration is loaded from `/home/548005.cloudwaysapps.com/ezgshksprf/private_html/atlas-rentals-integrations.php`, with the documented `ATLAS_RENTALS_*` environment variables taking precedence. It supplies Resend sender/administrator settings and the bearer-authenticated CRM adapter. `api/integrations-config.example.php` contains placeholders only.

Create and verify these private, non-public runtime directories before release:

- `/home/548005.cloudwaysapps.com/ezgshksprf/private_html/atlas-rentals/delivery-state`
- `/home/548005.cloudwaysapps.com/ezgshksprf/private_html/atlas-rentals/quotation-pdfs`
- `/home/548005.cloudwaysapps.com/ezgshksprf/private_html/atlas-rentals/journey-identifiers`

PHP must be able to read and write these private directories. The application does not alter directory permissions. Journey identifiers have a fixed 24-hour lifetime and bounded 90-day expiry tombstones; `ATLAS_RENTALS_JOURNEY_STATE_PATH`, `ATLAS_RENTALS_JOURNEY_TTL` and `ATLAS_RENTALS_JOURNEY_TOMBSTONE_TTL` may override those values. Delivery state and PDF retention is 30 days; quotation validity is 30 days and attachments are limited to 8 MB. PHP cURL and outbound HTTPS are required for CRM and Resend.

The pricing snapshot JSON stores the selected rate plan, duration blocks, all laptop rates, per-unit and equipment amounts, technician, delivery, subtotal, VAT and total without a schema change. New enquiries accept only Daily, Weekly and Monthly plans. Historical records, including stored `best` snapshots, are not rewritten or repriced and remain available to legitimate duplicate and delivery retries. The existing InnoDB tables `atlas_rental_enquiries` and `atlas_rental_reference_counters` are required. The nullable `delivery_address` and existing `description` columns receive SQL `NULL`; no schema creation or migration runs. Tests must use private temporary directories, the in-memory store, or a dedicated non-production database and must never write test enquiries to production.

## Release unit

Upload the HTML, CSS, generated JavaScript bundle, PHP runtime files and Composer `vendor/` tree atomically so the interface, metadata and server contract cannot be mixed across versions. `node_modules/`, documentation, tests, configuration examples and private configuration loaders must not be published from the web document root.

## Rollback

Retain the previous complete release, including its generated JavaScript and Composer dependency trees. If validation fails, restore that release as one unit rather than replacing individual runtime files. Do not run `composer update` or `npm install` as a rollback strategy.
