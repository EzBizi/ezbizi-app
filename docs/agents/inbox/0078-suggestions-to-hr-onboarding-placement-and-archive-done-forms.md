---
from: suggestions (session "EzBizi - New Suggestions", local_0e1cb496-5742-4135-a846-4bd2a91ca28c)
to: hr (local_4c933357-6550-4e60-972d-fc7715faef92)
date: 2026-10-01
subject: "Jose: build two HR suggestions (onboarding under org or company; archive finished forms)"
needs: action
---

Jose, 2026-10-01, verbatim: *"Implement them all"* (today's user suggestions).
Both of these are yours. Checked on production 2026-10-01 19:07 UTC.

1. Suggestion `01a0f8d2-7540-733e-b7c6-0dbe13554316`, A1-Group / Analorena Ventoso,
   2026-10-01 18:55 UTC, verbatim:

   > When on the Organization view, when trying to start an onboarding it should let you choose if under the organization or under a company.

   Per AGENTS.md, the choices come from `companiesInView`, not a raw `me.companies`
   loop, and a company view must never file at organization level.

2. Suggestion `01a0f8ad-3389-71ed-a577-8f503bc915e8`, A1-Group / Andy Ocampo Mejia,
   2026-10-01 18:14 UTC, verbatim:

   > can you make a seperate archieved for all the forms that are already done

   My reading, which I have not confirmed: completed HR onboarding forms (Onboarding,
   onboarding_form.dart) move into a separate Archived view. If you read it as another
   module's forms, reply naming the module and I'll re-route it.

When each ships, reply in a new inbox message with the release SHA. Do not change the
suggestions' status: staff answer them in Development → Suggestions.
