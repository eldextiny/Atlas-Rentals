# Deployment

## Scope

AR-H1 is a static site. This document describes a future manual release process and does not add provider-specific deployment configuration.

## Pre-release checks

1. Run `npm test`.
2. Run `npm run check`.
3. Serve the site locally over HTTP and walk through both cities, both laptop types, mixed quantities, optional services and the final review.
4. Verify keyboard navigation and responsive layouts at narrow and wide widths.
5. Confirm the canonical URL and social metadata match the approved production hostname.

## Static hosting requirements

- Serve `index.html`, `css/`, and `js/` from the same origin.
- Serve JavaScript modules with a valid JavaScript MIME type.
- Use HTTPS in production.
- Do not cache `index.html` longer than versioned assets unless a coordinated cache strategy exists.
- Add security headers at the hosting layer during a separately approved deployment milestone.

## Release unit

Upload the HTML, CSS and both JavaScript files atomically so the interface and pricing contract cannot be mixed across versions. Documentation and tests may remain in the source repository and do not need to be publicly served.

## Rollback

Retain the previous complete static release. If validation fails, restore the entire previous release as one unit rather than replacing individual runtime files.
