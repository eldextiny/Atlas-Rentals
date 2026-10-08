# Business Rules

## Rates

| Laptop category | Daily rate per billable calendar day |
| --- | ---: |
| Standard Business Laptop | ₦10,000 |
| High Performance Laptop | ₦15,000 |

| Other item | Rate |
| --- | ---: |
| Included Delivery & Retrieval | ₦40,000 once per booking |
| Optional technician | ₦35,000 per technician per billable calendar day (1–10 technicians) |
| VAT | 7.5% |

## Calculation order

1. Start and end dates may be any valid calendar date, including Saturdays and Sundays.
2. Billable days include both endpoints and every intervening calendar day. A same-day rental is one day.
3. Saturdays, Sundays and Nigerian public holidays are billable at the same daily rate.
4. Daily is the only rate plan: `billable calendar days × daily rate`.
5. Equipment amount is `per-unit charge × quantity`.
6. The selected category maps to its existing server quantity field and the unselected category maps to zero.
7. At least five laptops are required.
8. Delivery & Retrieval is an included rental service and is added once regardless of quantity or duration.
9. Optional technician support is `technicianQuantity × billable calendar days × ₦35,000`. `technicianQuantity` is 1–10 when selected and zero otherwise; persisted `technicianDays` remains derived from the authoritative rental duration.
10. VAT is 7.5% of rental, Delivery & Retrieval and technician charges combined.
11. The estimate total is the pre-VAT subtotal plus VAT.

Historical stored pricing snapshots, including snapshots whose `ratePlan` is `best`, remain authoritative for duplicate lookup, rendering and delivery retry. They are never recalculated, migrated or accepted as new enquiries. Non-daily historical snapshots cannot enter the daily-only CRM v1 contract and remain pending rather than being relabeled or repriced.

## Validation

- Service location may be Abuja, Lagos, or another specified Nigerian city.
- End date must be the same as or later than the start date.
- Start and end dates may be any valid calendar dates; dates are parsed in UTC to avoid timezone shifts.
- Laptop quantities must be whole, non-negative numbers. Selected technician quantity must be a whole number from 1 through 10.
- Technician support, when selected, covers every billable calendar day.
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
