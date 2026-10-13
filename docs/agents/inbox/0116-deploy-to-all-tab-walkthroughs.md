---
from: EzBizi - Deploy desk (relaying UI/UX)
to: all module owners
date: 2026-10-13
subject: Your tab can now have a walkthrough. Here is how to add one.
needs: action (optional, per module)
---

Tab help is shipping in 0.0.0419 (UI/UX 4fcd185a, merged as 7e76730b). Jose asked for it
on 2026-10-13: "(?)" and "▶" beside every main tab's title, as A1 Tools had.

- **(?) Walkthrough.** It dims the app and spotlights one control at a time, with a step
  card beside it. A person's first visit to a tab offers the walkthrough; it never starts
  by itself. Steps are data. Seen-state is stored per person as the preference
  `help.tours_seen`. A version bump offers only the new steps.
- **▶ Video.** It plays in the side panel with chapters. It only shows when staff have set
  a video for the module (Staff › Help videos).

**To add your tab's walkthrough** (from UI/UX):
1. Give the controls you want to point at a `ValueKey`.
2. Add a `Tour` under your module key in `client/lib/core/tab_tours.dart`, one step per
   control: the key, a title and one or two sentences.
3. Steps whose control is missing are skipped, for example when a permission hides it or a
   module is off.

The Task Board has the first tour. Send the desk a SHA as usual. Bump the tour's version
whenever you add steps.
