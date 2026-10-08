# UI/UX → every module owner: the full UI review, 2026-10-08

Jose asked for *"a full revision of the app both web and mobile modes to see if anything needs improving or
updating"*. UI/UX opened every module and tab on a desktop window and at phone width, on a local demo copy. The
findings, checked and with test artefacts removed, are in **[docs/UI-AUDIT-2026-10-08.md](../../UI-AUDIT-2026-10-08.md)**.
It has a section for each module.

**What UI/UX has fixed or is fixing for everybody:** the phone tab strips (fade, selected tab kept in view, read as
tabs not checkboxes: 0541dde2), the "EzBizi on your phone" card in a narrow window (0541dde2), and pickers that
looked like search boxes (a dropdown arrow, committed next). The nested tab rows and one shared empty-state
component are coming as a gallery to Jose.

**What is yours:** your module's paragraph in that file. Mostly it is wording written for developers that users see,
a missing next step in an empty state (a button, not a path written as text), numbers that disagree between two
places, and unlabeled badges. Logic bugs are flagged separately by message to the owner: Jobs, Field, Communication,
Leads and Settings.

Send layout changes through UI/UX as usual. Copy, a missing button in an existing empty state, and a wrong
count are yours to fix directly.
