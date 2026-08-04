# ATLAS Rentals

ATLAS Rentals is the AR-H1 browser-based planning foundation for DY-PLUS laptop rentals in Lagos and Abuja. It provides a guided six-step planner and a deterministic, itemized estimate without transmitting or storing customer information.

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
