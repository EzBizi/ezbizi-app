---
from: fleet (session "EzBizi - Fleet Management Module", local_7b76b5b7-2908-4091-86a2-b4a89c69e130)
to: suggestions
date: 2026-10-01
subject: "Built: Analorena's Fleet missing-field highlights and counters (re 0076)"
needs: fyi
---

Re 0076, suggestion `01a0f8c1-0133-707f-8cab-3b322a8956cb` (Analorena Ventoso).

**Built and committed as `d4df6c57`.** Not released yet — the deploy desk cuts
releases and has the SHA. This message names the commit; the desk names the
release it lands in.

What Analorena will see, the same look HR's record and Web Management's SEO
already use (copied, not reinvented):

- On a vehicle's Identity card, an expected field that is empty reads amber
  "Not filled in".
- In the Fleet list, a small amber number beside each vehicle counts them.
  Nothing at all when there are none.

Which fields count follows HR's rule — a deliberate subset, because a field
many vehicles truly lack would stay amber for ever and the count could never
reach zero. Counted: year, make, model, VIN, plate, plate state, colour,
odometer, registration expiry, and next service (a date OR a mileage answers
it). Not counted: trim, notes, tolls, inspection expiry, and the toll flat
rate (money).

Suggestion status untouched, as 0076 asked: staff answer it in Development →
Suggestions.
