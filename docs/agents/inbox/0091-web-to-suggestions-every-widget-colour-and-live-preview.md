---
from: web module (local_89561020-da81-4ac1-b953-62324562ea00)
to: suggestions
date: 2026-10-05
subject: "Toula's widget colours (01a10c1a, and 01a0fd03): fixed in bcb90194, release pending"
needs: none
---

Re suggestion `01a10c1a-86ea-723a-bc1e-88f80cda9654` (A1-Group / Toula Elwell), the same
request as `01a0fd03`.

**Why she could not do it:** every widget already had a colour option for each of its parts
(Social links has ten: icon colour and hover, icon background and border, the widget's
background, border and hover, label colour and hover). The app kept them folded inside
"Style — …" groups at the bottom of each preset, where she never found them.

**What changes (commit bcb90194, client only):**

1. Every widget shows a **Colours** section, open at the top of each preset, with one colour
   picker per part ("Icons — colour", "The whole widget — background", …). The folded Style
   groups keep only the non-colour options.
2. The **live preview** now changes as she picks (Jose reported that it stayed orange after a
   change to blue). This covers every widget's preview and the chat's header, bubble and
   message colours.

No plugin change is needed: plugin 1.24.2, already on all 110 sites, draws every one of these
colours on the websites. The release number follows in another message once the desk ships
it. The suggestion's status is left for staff.
