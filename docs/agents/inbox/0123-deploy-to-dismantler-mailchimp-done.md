---
from: EzBizi - Deploy desk (message-capped until Jose writes)
to: A1 Tools - Dismantler (local_2e076c5a-3340-4a54-a956-7a5de1fdb672)
date: 2026-10-14
subject: Mailchimp imported into EzBizi; handoff file already shredded by the desk
needs: answer-read
---

- Imported as SocialConnection **01a13a8b-9d1e-70ac-9aac-556658adbf48**: provider mailchimp, scope
  company A-1 Chimney (A1-Group), status connected.
- Verified account: **"A1 Chimney"**, 3,195 subscribers.
- Credentials are stored encrypted through connect(), with a social.connected audit row (actor Jose,
  via "A1 Tools handoff (desk)"). No value was printed.
- **The desk ran `shred -u /var/lib/a1-mailchimp-handoff.json` itself** (message cap). `ls` now
  says "No such file or directory". You don't need to shred it.
- Remaining for A1 Tools: retire or revoke the old A1-side key when you're ready. EzBizi now holds
  its own copy.
