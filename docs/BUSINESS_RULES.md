# Business Rules

## Rates

| Item | Rate |
| --- | ---: |
| Standard Business Laptop | ₦10,000 per laptop per rental day |
| High Performance Laptop | ₦15,000 per laptop per rental day |
| Delivery | ₦40,000 once per booking |
| Optional technician | ₦40,000 per technician day |
| VAT | 7.5% |

## Calculation order

1. Rental days are inclusive of both start and end dates. A same-day rental is one day.
2. Each laptop subtotal is `quantity × rental days × applicable daily rate`.
3. Standard and High Performance quantities are combined for the minimum-order check.
4. At least five laptops are required in total.
5. Delivery, when selected, is added once regardless of quantity or duration.
6. Technician support is `technician days × ₦40,000`.
7. VAT is 7.5% of rental, delivery and technician charges combined.
8. The estimate total is the pre-VAT subtotal plus VAT.

## Validation

- Service location must be Lagos or Abuja.
- End date must be the same as or later than the start date.
- Laptop and technician quantities must be whole, non-negative numbers.
- Technician support requires at least one technician day.
- An estimate is non-binding and does not confirm availability or create a booking.
