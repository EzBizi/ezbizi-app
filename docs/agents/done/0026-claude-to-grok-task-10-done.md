---
from: claude
to: grok
date: 2026-08-19
subject: Task 10 done — STATUS and TODO now say built-not-deployed
needs: fyi
---

Mia split the last two: you have Task 9, I took Task 10. Done, so do not also do
it — check `git status` before you edit either document.

## What I changed

**`docs/EZBIZI-STATUS.md`** — two lines.

- Page date `2026-08-18` → `2026-08-19`. The page's own rule is that it only wins
  when it is the newest thing, so leaving yesterday's date on a page describing
  today's state would break the rule it opens with.
- Phase 4 row: Inspection is no longer "designed, not built". It now says built,
  on `main`, **not deployed**, with what Builder / Generated Reports / New
  Inspection actually do, and both the spec and plan paths.

I said explicitly that **production has no Inspection tab**. A reader who saw
"built" on the page that wins every disagreement would otherwise reasonably assume
it was live. The box is still on the 18 August release.

**Generated numbers block untouched.** Verified with a diff filter rather than by
being careful — `Tests`, `Client version`, `Production data` and `Permissions in
production` are all byte-identical, and I did not touch the markers.

**`docs/EZBIZI-TODO.md`** — one entry at the top of `## Done`, dated today.
Written for somebody who was not here: what the module does, and the three
decisions that are not obvious from the code — `scope_key` instead of a nullable
`job_id` and why MySQL's several-NULLs rule forced it, the two write holes on a
frozen report, and `scheduled_for` freezing as rendered text. It names Task 9 as
the remainder.

**Plan** — Task 10's two steps ticked. Step 2 said "agent mail to Claude"; since
I did the task, the mail went the other way. This is it.

## Ordering, so we do not fight over the same file

I updated STATUS **after** confirming Task 8 green here (60 backend tests, 678
assertions) rather than trusting `0025`. The plan's rule is STATUS moves with the
last green test, not before it.

When Task 9 lands, the TODO entry needs one word changed — the sentence naming
Task 9 as the remainder stops being true. Do that in your Task 9 commit rather
than sending mail about it; it is smaller than a message.

Nothing owed.
