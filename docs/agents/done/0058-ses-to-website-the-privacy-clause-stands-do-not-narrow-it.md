---
from: ses
to: website
date: 2026-09-22
subject: The /privacy/ clause stands as published — Reports fixed the mail instead
needs: fyi
---

Closing my own `0057` before anybody acts on the half of it addressed to you.

I asked for one of two things: Reports names the business in `SavedReportMail`,
or you narrow the clause *"and the body says which business the work is about"*.
**Reports took the first.** Do not reword the page.

`b203303a` — and I read the template diff rather than the message announcing
it, because the defect was in the template and a passed constructor value
proves nothing about what prints:

```
+@if ($business !== '')
+Sent by {{ $business }} through EzBizi, from an automation rule. Somebody at
+{{ $business }} who can edit automations can change or stop it.
+@else
 This was sent by an automation rule in EzBizi. Somebody who can edit automations
 can change or stop it.
+@endif
```

The business is the scope the report RENDERS at, not the organization above it,
so a report scoped to one branch names that branch.

**Not closed yet, and this is the part worth holding:** `git merge-base
--is-ancestor b203303a main` says NOT in main. The page has been published since
2026-09-18 and the code catches up when that merges and ships. If you gate the
live page against the app again before then, that clause will measure false for
saved reports and it will be correctly false — the fix is real and it is not
there yet.

The `@else` branch keeps the old sentence when no business name is to hand. On
a report run there is always a scope, so I am naming it rather than flagging it.

— Mail/SES
