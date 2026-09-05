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
7. Serve the site locally over HTTP and walk through all city choices, both laptop types, weekday and rejected weekend endpoints, periods spanning weekends, optional services, submission failure and confirmed enquiry receipt.
8. Verify keyboard navigation and responsive layouts at narrow and wide widths.
9. Confirm the canonical URL and social metadata remain unchanged.

## Reproducible dependency build

Cloudways uses PHP 8.4.24, Composer 2.10.2, Node 18.17.1 and npm 9.6.7. Do not run dependency-update commands during deployment. `composer.lock` and `package-lock.json` are the only approved resolution inputs.

```text
composer install --no-dev --classmap-authoritative --no-interaction
npm ci
npm run build
```

Composer creates the production `vendor/` tree. npm creates the temporary build-time `node_modules/` tree. Rollup creates `js/vendor/libphonenumber.js`, the only browser libphonenumber runtime artifact. The browser bundle is served from the application origin and must never be replaced with a CDN URL or a runtime package-registry import. `js/enquiry.js` imports this bundle, and `api/enquiry-service.php` requires the root Composer autoloader; both dependency trees must therefore be present before the application release becomes active.

## Runtime and configuration

- Serve `index.html`, `css/`, and `js/` from the same origin.
- Serve `api/submit-enquiry.php` through PHP 8.1 or newer with PDO MySQL enabled.
- Serve JavaScript modules with a valid JavaScript MIME type.
- Deploy the generated `js/vendor/libphonenumber.js` file and Composer-generated `vendor/` tree with the application runtime.
- Verify that a Nigerian national number and at least one non-Nigerian national number normalize identically in the browser and PHP fixture suites before release.
- Use HTTPS in production.
- Do not cache `index.html` longer than versioned assets unless a coordinated cache strategy exists.
- Add security headers at the hosting layer during a separately approved deployment milestone.

The endpoint reads the loader path from `ATLAS_RENTALS_DB_CONFIG`. Without an override it derives `<current-application>/private_html/atlas-rentals-db.php` from the executing `<current-application>/public_html`; it never searches another application. The loader must remain outside `public_html`, be readable by PHP, and return `host`, `port`, `database`, `username`, `password`, and `charset`. `api/database-config.example.php` documents this shape with placeholders only. Never copy production values into the repository.

Integration configuration defaults to `<current-application>/private_html/atlas-rentals-integrations.php`, derived only from the executing application's sibling `public_html` and `private_html` directories. `ATLAS_RENTALS_INTEGRATIONS_CONFIG` overrides that loader path, and the documented per-value `ATLAS_RENTALS_*` environment variables retain precedence over loader values. It supplies Resend sender/administrator settings and the token-authenticated CRM adapter. `api/integrations-config.example.php` contains placeholders only.

Create and verify these private, non-public runtime directories before release:

- `<current-application>/private_html/atlas-rentals/delivery-state`
- `<current-application>/private_html/atlas-rentals/quotation-pdfs`
- `<current-application>/private_html/atlas-rentals/journey-identifiers`
- `<current-application>/private_html/atlas-rentals/rate-limits/submission`
- `<current-application>/private_html/atlas-rentals/rate-limits/review`

PHP must be able to read and write these private directories. The application does not create them or alter permissions. Every default is derived from the current application's sibling `private_html`; every explicit path is resolved canonically and rejected if it is inside the current `public_html`. Resolution fails closed if `public_html`, its sibling `private_html`, a required loader, or a required state directory is missing. Journey identifiers have an intrinsic 24-hour lifetime and bounded 90-day expiry tombstones; `ATLAS_RENTALS_JOURNEY_STATE_PATH`, `ATLAS_RENTALS_JOURNEY_TTL` and `ATLAS_RENTALS_JOURNEY_TOMBSTONE_TTL` may override those values. `ATLAS_RENTALS_RATE_LIMIT_STATE_PATH` may override the rate-limit base directory, beneath which the separate `submission` and `review` directories must exist. Delivery state and PDF retention is 30 days; quotation validity is 30 days and attachments are limited to 8 MB. PHP cURL and outbound HTTPS are required for CRM and Resend.

Before deploying technician quantity support, run `docs/migrations/2026-09-05-technician-quantity.sql` once against the application database and verify `atlas_rental_enquiries.technician_quantity` exists. The migration is idempotent and deliberately leaves historical rows `NULL`: historical support rows then retain one-technician meaning, while historical non-support rows retain zero-technician meaning. New records persist an explicit validated value from 0 through 10. Do not backfill or reprice historical rows.

The pricing snapshot JSON stores the fixed Daily rate plan, billable working-day duration, laptop daily rates, per-unit and equipment amounts, technician quantity/rate/amount, delivery, subtotal, VAT and total. New enquiries accept only Daily. Historical records, including stored legacy rate-plan snapshots, are not rewritten or repriced and remain available to legitimate duplicate and delivery retries. The existing InnoDB tables `atlas_rental_enquiries` and `atlas_rental_reference_counters` are required. The nullable `delivery_address` and existing `description` columns receive SQL `NULL`; runtime code never creates or alters schema. Tests must use private temporary directories, the in-memory store, or a dedicated non-production database and must never write test enquiries to production.

## Release unit

Upload the HTML, CSS, generated JavaScript bundle, PHP runtime files and Composer `vendor/` tree atomically so the interface, metadata and server contract cannot be mixed across versions. `node_modules/`, documentation, tests, configuration examples and private configuration loaders must not be published from the web document root.

## Rollback

Retain the previous complete release, including its generated JavaScript and Composer dependency trees. If validation fails, restore that release as one unit rather than replacing individual runtime files. Do not run `composer update` or `npm install` as a rollback strategy.
