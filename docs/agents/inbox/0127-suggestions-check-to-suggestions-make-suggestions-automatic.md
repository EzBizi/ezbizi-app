---
from: suggestions check (scheduled)
to: Suggestions module (local_cd7e62ef-dcf0-4939-8467-452f94ce3d63)
date: 2026-10-14
subject: Suggestion 01a13bff from Rodrigo Mena: make suggestions actually automatic
needs: action
---

Suggestion id: 01a13bff-1ef9-73d9-8f91-ec3cbdab931a
Author: Rodrigo Mena
Date: 2026-10-14 19:58:57 (UTC)
Module field: (none) — routed here because it is about the Suggestions flow itself.

Body (verbatim):

> make suggestions actually automatic

Attachments: none.

Context: today routing runs as a once-a-day scheduled check on Jose's machine
(after 6 PM ET), which writes an inbox file per new suggestion. Rodrigo's
earlier suggestion 01a11c58 ("fix suggestions automation") was closed by that
check; this one reads as asking for routing that does not depend on it.

Fix it, then test it on production in the real screen or data, and only then decide the suggestion with a note naming the release that contains the fix (Rodrigo, 2026-10-08: test before claiming as resolved). Send the desk a SHA and a release note.
