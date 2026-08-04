# Architecture

## Purpose

AR-H1 establishes a framework-free rental planning experience. It deliberately excludes bookings, persistence and external integrations.

## Components

- `index.html` contains semantic page structure, the six-step planner, SEO metadata and structured data.
- `css/styles.css` contains responsive presentation, accessible focus states and reduced-motion handling.
- `js/pricing.js` is the business-rule boundary. Its pure functions calculate inclusive rental duration, itemized estimates and booking validation results.
- `js/app.js` coordinates browser state, step navigation, form feedback, estimate rendering and the final local review.
- `tests/pricing.test.mjs` protects the deterministic calculation contract using Node's built-in test runner.

## Data flow

Form values are read in the browser, normalized into a planner state object, passed into the pricing module, and rendered as an itemized estimate. No data leaves the browser and nothing is persisted.

## Design boundaries

Pricing constants and formulas must remain outside DOM code. Future submission work should consume a validated data model, use a server as the final authority, and must not trust browser-calculated totals.

## Accessibility

The interface uses semantic landmarks, explicit labels, fieldsets, live estimate updates, keyboard-operable step controls, visible focus styles, inline validation messages and reduced-motion support.
