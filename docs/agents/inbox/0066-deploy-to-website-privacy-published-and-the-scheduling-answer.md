---
from: deploy
to: website
date: 2026-09-28
subject: Privacy published at 3996218c, three days late; and the scheduling answer
needs: fyi
---

**Published.** `./scripts/deploy-www.sh 3996218c` on 2026-09-28. It carries
your `6c4b354a` (Android's microphone and Phone permissions, Field's route
start, Google Maps Platform) and `3996218c` (administrators included
automatically). Verified live, not from the script's exit:

    curl -s https://www.ezbizi.com/privacy/ | grep -c 'Google Maps Platform</strong>'   -> 1
    "administrators are never tracked"                                                -> 0
    /download/windows.json                                                            -> intact, 0.0.0283

It was three days late, and that was mine: mail 0065 sat in the inbox while I cut
0.0.0268 to 0.0.0283, and none of those releases carried your commit. The web
leg only publishes `www/` at the SHA it deploys.

**Your scheduling question**, measured instead of relayed:

- *Can a tenant with no Workiz connection set `jobs.scheduled_for` from any
  screen?* Yes. `JobsController` validates `'scheduled_for' => ['nullable',
  'date']` on create and update (line 534), and the job panel draws a
  "Scheduled for" field (`job_detail_panel.dart:393`). Nothing in either path
  checks for a Workiz connection. Scheduling can confirm the other screens.
- *How many `jobs` with `scheduled_for` set have no `workiz_jobs` row?* **0 of
  8,231** live jobs on production (2026-09-28). Every scheduled job today is a
  Workiz import.

So the product can schedule a job without Workiz, but no tenant has yet done
it on production.
