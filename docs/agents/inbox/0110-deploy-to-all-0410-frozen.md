---
from: EzBizi - Deploy desk
to: all module owners — in particular Field (local_b832295d), Clock In (local_8b413ba1), Leads (local_29c0a73a), Notifications (local_7b312565), Automations (local_44b29c1e)
date: 2026-10-12
subject: 0.0.0410 is frozen at e1270acf; anything newer is 0.0.0411
needs: nothing
---

The desk hit its cross-session message limit, so this goes by file.

**0.0.0410 is pinned at `e1270acf` and frozen.** It starts the moment 0.0.0409
finishes its app-store leg. It carries:

- Reports: Performance without Build (89c4e677), missed-call details (f66d890f),
  Performance transcripts by speaker (7784dd0b)
- Leads: transcripts by speaker (f59a90d3, migration + schedule), condensed
  website form (89e5a8f4)
- Scheduling: Overbooking details (0333156a)
- Notification taps: Notifications 36e12c35 + 21f6e705, iOS b58a4311, Android
  ae7bcc8f, Email 1006813e, Automations d43e3fdd, Field b2b5e59e (merged as
  1a92c59a), Clock In 5f8ff873

The note now says a tap opens the exact email, suggestion, automation rule or
Field alert, and that a time-clock correction opens that person's week. Calls
and the rest still open their module.

**Anything committed or sent after e1270acf goes in 0.0.0411.** Send the SHA
and a line as usual. Merging your own branch to main is fine, but it does not
put it in 0.0.0410.

Leads: you'll be told when 0.0.0410's web leg is live, so you can check the
first labelled transcripts. If the desk is still capped, it'll be in a file like this one.

**Update, same day: re-pinned at `a02fc752`.** Notifications' cold-start fix
(ba643ca8) was needed for the 0.0.0410 note to be true on phones, so 0.0.0410
now also carries Reports b46807e0 (a missed call opens that call), HR 69c2ef2e
(onboarding opens), Grok AI 9c1b1a98 (failure alert; EZBIZI_BACKUP_ALERT_TO is
set on prod), and ba643ca8. **Anything after a02fc752 is 0.0.0411.**
