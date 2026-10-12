# GBP API re-application, on or after 24 October 2026

The 60-day rule is met on 24 October: the profile was verified 25 August
2026. Google refused the 3 September filing for exactly this reason (see
docs/BUSINESS-PROFILE.md §9b). File **once**, because Google asked for no
duplicates.

## Where and as whom

- Sign in as **info@ezbizi.com**, the owner of EzBizi's Business Profile. Do not
  use development@: the form refuses it (*"There's no Business Profile linked"*),
  and it must stay clean of locations (§5).
- Go to support.google.com/business/contact/api_default, then
  **Application For Basic API Access**.
- No acknowledgement email arrives. Approval shows up as quota in the Cloud
  console: Business Profile APIs go from 0 QPM to 300 QPM.

## Fields (same as 3 September; nothing about a client)

| Field | Value |
|---|---|
| Cloud project ID / number | `ezbizi-506117` / `247590132639` |
| Company website | `https://www.ezbizi.com/` (exactly matches the profile's Website field) |
| Entity type | Software developer building a platform |
| Previous case | `6-2027000041783`, refused 22 Sept 2026 on the 60-day rule only |

**Primary reason (paste):**

> EzBizi is a field-service CRM. Its Reviews and Business Profile screens let
> each customer business connect the Business Profile it already owns or
> manages, by OAuth with the business.manage scope, to read its reviews and
> post its own replies. EzBizi never takes ownership of a customer's listing
> and never asks a customer to create its own API project. Calls used:
> accounts.list, locations.list, reviews.list, reviews.updateReply. This
> re-files case 6-2027000041783, which was declined on 22 September 2026 only
> because our listing was under 60 days old. It was verified on 25 August 2026
> and is now past 60 days.

## After filing

Write the case ID the confirmation screen shows into docs/BUSINESS-PROFILE.md
§8, read off the screen and nothing else. Then watch info@ezbizi.com for
questions from Google.
