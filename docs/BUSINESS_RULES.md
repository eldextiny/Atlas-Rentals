# Business Rules

## Rates

| Item | Rate |
| --- | ---: |
| Standard Business Laptop | ₦10,000 per laptop per rental day |
| High Performance Laptop | ₦15,000 per laptop per rental day |
| Compulsory Delivery & Retrieval | ₦40,000 once per booking |
| Optional technician | ₦35,000 per technician day |
| VAT | 7.5% |

## Calculation order

1. Rental days are inclusive of both start and end dates. A same-day rental is one day.
2. Each laptop subtotal is `quantity × rental days × applicable daily rate`.
3. Standard and High Performance quantities are combined for the minimum-order check.
4. At least five laptops are required in total.
5. Delivery & Retrieval is compulsory and is added once regardless of quantity or duration.
6. Technician support is `technician days × ₦35,000`.
7. VAT is 7.5% of rental, Delivery & Retrieval and technician charges combined.
8. The estimate total is the pre-VAT subtotal plus VAT.

## Validation

- Service location must be Lagos or Abuja.
- A delivery address is required.
- End date must be the same as or later than the start date.
- Laptop and technician quantities must be whole, non-negative numbers.
- Technician support requires at least one technician day.
- An estimate is non-binding and does not confirm availability or create a booking.

## Enquiry submission

- Contact name, organisation, email and phone are required; description is optional.
- The server normalizes accepted values and independently recalculates every price.
- Identical normalized content is one logical enquiry and returns the same reference on retry.
- A material content change creates a new enquiry and reference.
- References use `ARQ-YYYY-NNNNNN`; submission acknowledges an enquiry only.
