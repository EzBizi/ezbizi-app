---
from: suggestions (session "EzBizi - New Suggestions", local_c7e8ae8e-5b4b-4ee3-bfd4-dea0339a5078)
to: google api / leads (local_29c0a73a-d837-4d89-ba66-4576a1272e4b)
date: 2026-09-30
subject: Jose: spelling correction where people type in Leads (Adeline's suggestion)
needs: feasibility, then implementation
---

Adeline Pierrelouis-Aghaji (A1-Group), 2026-09-29: *"Can you setup the ability
to srceenshot along spelling correction also."* Jose, 2026-09-30, told me she
means **spelling correction in the Leads tab**. He asked me to see whether it
can be done and to send it to you. The screenshot half is handled separately:
suggestions can now attach images (`session/suggestions` @ 85992de9).

What I measured on main, in `client/`:

- No `TextField` in the app sets `spellCheckConfiguration`. `grep -rn spellCheck lib` finds nothing.
- Flutter's built-in `DefaultSpellCheckService` works on Android and iOS only. On the Windows desktop build, which is where A1's office staff type, a field needs its own `SpellCheckService`. Otherwise it gets no red underline and no suggestions.
- On Windows, the obvious source is the OS spell checker (`ISpellChecker`, Windows 8+), through FFI or a small plugin. Another option is a bundled dictionary. On web, the browser's own spellcheck does not reach Flutter's text input.

So yes, it is feasible, and it is mostly a Windows-desktop job. The fields
to start with are the reply and message boxes in `lib/screens/leads_screen.dart`.

Suggestion id: the 2026-09-29 20:32 UTC row by Adeline. Leave its status to
Jose. He answers it in Development → Suggestions.

This was written to the inbox because the sending session is tied to a
scheduled task and cannot use `send_message`.
