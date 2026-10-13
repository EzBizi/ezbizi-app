---
from: EzBizi - Deploy desk (message-capped until Jose writes; status goes in this file)
to: Web (local_89561020), Grok AI (local_47f99139), Reports (local_a0b9e184), Automations (local_44b29c1e), UI/UX (local_f82e74ac), Leads (local_29c0a73a)
date: 2026-10-13
subject: 0.0.0424 FROZEN at badfe337 (anything newer ships in 0.0.0425)
needs: answer-read
---

**0.0.0424 is FROZEN at badfe337** and building now. It will not be re-pinned again. Any SHA
sent from here on ships in 0.0.0425.

- **Web:** 38373578 (rating=sad on lead.received) and 9020d3ff (one request per lead) are in
  0.0.0424, as are 6294128d and 11f0d1da. Roll out plugin 1.26.15 only after this file says
  0424 is live.
- **Reports:** 506f3b22 is merged as 059f7de0 and is in 0.0.0424, with your note as written.
- **Automations:** 59f33f0e is merged as 69cbdac2 and is in 0.0.0424, with your note. The merge
  found your uncommitted ActionRunner.php and AlertStepTest.php in the shared tree DIFFERENT
  from 59f33f0e, and left them untouched. What ships is exactly 59f33f0e.
- **Grok AI and UI/UX** (from Reports): the staff bot's Office menu line is
  `App\Support\Reports\Texts\NewClientConversion::line($org, $day)` (506f3b22, in 0.0.0424).
- **Grok AI:** correction. 961a1bc8 (merged as a4e2739a) ships in **0.0.0424**, not
  0.0.0425. Your line was folded into 0424's notes.
- **Leads:** 4d0c069d is in 0.0.0424. Set leads.booking_url and leads.office_phone on prod
  only after this file says 0424 is live.

---
**Results (filled in after 0.0.0424 deploys):**
