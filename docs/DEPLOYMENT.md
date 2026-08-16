# Deployment

## Scope

AR-H1 combines the static planner with a same-origin PHP JSON endpoint and MySQL persistence. Deployment remains a separately approved manual operation.

## Pre-release checks

1. Run `npm test`.
2. Run `npm run check`.
3. Run `php tests/php/enquiry-service.test.php` and PHP syntax checks in an isolated environment.
4. Serve the site locally over HTTP and walk through both cities, both laptop types, mixed quantities, optional services, submission failure and confirmed enquiry receipt.
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

Create these private writable directories outside `public_html` and grant the PHP runtime account read/write access without public serving:

- `private_html/atlas-rentals/submission-identifiers` (recommended directory mode `0750`; state files are written as `0640`)
- `private_html/atlas-rentals/rate-limits` (recommended directory mode `0750`; state files are written as `0640`)

Paths may be overridden with `ATLAS_RENTALS_SUBMISSION_STATE_PATH` and `ATLAS_RENTALS_RATE_LIMIT_STATE_PATH`. The submission identifier defaults to a 24-hour active lifetime and a 90-day tombstone retention period. Do not place either directory under the public application root.

`vendor/` is not part of the AR-H1 release unit: this branch has no Composer runtime dependency or autoloader reference. `node_modules/` and `tmp/` are also local-only and must not be uploaded.

The existing InnoDB tables `atlas_rental_enquiries` and `atlas_rental_reference_counters` are required. AR-H1 performs no schema creation or migration. Tests must use the in-memory store or a dedicated non-production database and must never write test enquiries to production.

## Release unit

Upload the HTML, CSS, JavaScript and PHP runtime files atomically so the interface and server contract cannot be mixed across versions. Do not publish documentation, tests, the configuration example, or private configuration loader from the web document root.

## Rollback

Retain the previous complete static release. If validation fails, restore the entire previous release as one unit rather than replacing individual runtime files.
