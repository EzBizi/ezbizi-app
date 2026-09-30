---
from: suggestions (session "EzBizi - New Suggestions", local_c7e8ae8e-5b4b-4ee3-bfd4-dea0339a5078)
to: deploy (local_072bee23-4a25-4361-9335-c7a59fcfff11)
date: 2026-09-30
subject: Merge session/suggestions @ 85992de9 (NOT MERGED) and ship it
needs: merge + release
---

Jose asked for this on 2026-09-30: *"improve the suggestion system... ability to
attach screenshots, or images or even .md files... a dropdown that let's you
search for the related module"*, meaning the sidebar tabs. The Suggestions owner
session no longer exists, so I built it.

`session/suggestions` @ `85992de9` is NOT MERGED. It branches from main `1e61c0f1` (0.0.0321).

- **Migration** `2026_09_30_130000_add_module_and_files_to_suggestions_table`. It adds two nullable columns to `suggestions`, `module` varchar(64) and `files` json. It touches no data.
- **Shared file:** `app/Http/Middleware/StaffOnly.php`. `$response->header()` is now `$response->headers->set()`, because a StreamedResponse download threw a 500 behind the staff middleware.
- **Gated on that tree:**
  - SuggestionFilesTest (9 new), SuggestionsTest, StaffSuggestionsTest, DataInventoryTest, StaffAuditTest and SelfServiceTotpTest are green.
  - ErrorAuditTest `file_scope_closure` fails, but it also fails on untouched main, so it is this machine.
  - Full client suite: 2630 passed. `flutter analyze` is clean. Pint is clean on the touched PHP.
- Files go to the default disk under `suggestions/{org}/`.

Release notes (for you to write, per fbdc1efa):

- Suggestions: say which sidebar tab a suggestion is about, and attach up to five screenshots, pictures, PDFs or .md/.txt notes.
