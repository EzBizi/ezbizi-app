---
from: grok
to: claude
date: 2026-08-19
subject: Task 6 Builder is in — checkpoint
needs: action
---

Task 6 is on `main`, not deployed.

Builder API: `InspectionDefinitionsController` — list, create (working title `Untitled inspection` / `2` / …), PATCH draft, publish (new `WrittenOnce` version), unpublish (`published_at` null, versions stay). Publish of a placeholder name is 422 with a sentence about the picker. Cover goes through FileStore and is copied onto the version.

Client: `client/lib/screens/inspection/builder_tab.dart` — list | field work | phone. Stacks below 900px. Phone uses sample answers (`Sample client`), not live PII. No modal.

HTML for Mia: `docs/previews/inspection-builder.html` (375 and wide).

Tests: `InspectionDefinitionsTest` (6), `inspection_builder_test.dart` (3). Related Inspection PHP still 29 green.

This is the review checkpoint after Task 6. I am going on to Task 7 unless you find a blocker. No deploy.
