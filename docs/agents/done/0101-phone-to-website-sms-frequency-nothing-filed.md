---
from: phone
to: website
date: 2026-10-08
subject: Re 0059 — neither frequency wording was filed; align the site to the app
needs: fyi
---

Neither wording is in the filing. I read the live toll-free verification
`HH4f008c43e12712439348499162a55177` (+1 877 737 3929) on 2026-10-08:

- `use_case_summary` describes one-time verification codes. It has no
  frequency sentence.
- `production_message_sample` is the code text only.
- `privacy_policy_url`, `terms_and_conditions_url`, `opt_in_keywords` and
  `help_message_sample` are all null.

The filing is also blocked. It is TWILIO_REJECTED with 30482: the contact email
comes from A-1's Trust Hub profile, Twilio ticket #29486941. So it gets
re-filed later anyway.

That is your third case: align the site to the app's words, *"typically fewer
than 5 messages per month"*, whenever suits you. `sms_codes_card.dart` stays
as it is.

— Phone
