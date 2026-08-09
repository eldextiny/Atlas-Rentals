# Business Rules

## Rates

| Laptop category | Daily | Weekly (7 days) | Monthly (30 days) |
| --- | ---: | ---: | ---: |
| Standard Business Laptop | ₦10,000 | ₦59,500 | ₦185,000 |
| High Performance Laptop | ₦15,000 | ₦89,500 | ₦225,500 |

| Other item | Rate |
| --- | ---: |
| Included Delivery & Retrieval | ₦40,000 once per booking |
| Optional technician | ₦35,000 per technician day |
| VAT | 7.5% |

## Calculation order

1. Rental days are inclusive of both start and end dates. A same-day rental is one day.
2. Every new enquiry selects exactly one rate plan: Daily, Weekly, Monthly or Best Available.
3. Daily is `inclusive days × daily rate`; Weekly requires a whole multiple of 7 days; Monthly requires a whole multiple of 30 days.
4. Best Available decomposes inclusive days deterministically: `months = floor(days / 30)`, then `weeks = floor((days % 30) / 7)`, then remaining `days = (days % 30) % 7`.
5. Best Available per-unit charge is `(months × monthly rate) + (weeks × weekly rate) + (remaining days × daily rate)`. This is not a cheapest-combination search, and partial weeks/months are never rounded upward.
6. Equipment amount is `per-unit charge × quantity`.
7. The selected category maps to its existing server quantity field and the unselected category maps to zero.
8. At least five laptops are required.
9. Delivery & Retrieval is an included rental service and is added once regardless of quantity or duration.
10. Technician support is `technician days × ₦35,000`.
11. VAT is 7.5% of rental, Delivery & Retrieval and technician charges combined.
12. The estimate total is the pre-VAT subtotal plus VAT.

## Validation

- Service location may be Abuja, Lagos, or another specified Nigerian city.
- End date must be the same as or later than the start date.
- Laptop and technician quantities must be whole, non-negative numbers.
- Technician support requires at least one technician day.
- An estimate is non-binding and does not confirm availability or create a booking.
- A quotation estimate is valid for 30 days.

## Enquiry submission

- Contact name, organisation, email and phone are required.
- Phone values are normalized to an international `+` representation; supported Nigerian local and country-code forms normalize to `+234…`.
- The server normalizes accepted values and independently recalculates every price.
- Identical normalized content is one logical enquiry and returns the same reference on retry.
- A material content change creates a new enquiry and reference.
- References use `ARQ-YYYY-NNNNNN`; submission acknowledges an enquiry only.
- CRM review synchronization does not submit or book the enquiry.
- A persisted enquiry can report quotation delivery as pending; identical retries resume PDF and recipient delivery without allocating another reference.
