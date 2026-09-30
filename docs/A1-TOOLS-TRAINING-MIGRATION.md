# A1 Tools Training into EzBizi

The deploy desk coordinates this. This file is the single source of truth for the part: Jose's words, what is measured, who builds what, and the order. It holds no personal data.

Earlier parts: `docs/A1-TOOLS-PEOPLE-MIGRATION.md` (staff) and `docs/A1-TOOLS-CLIENTS-JOBS-MIGRATION.md` (clients, jobs and money).

## Jose's words

2026-09-30, looking at an empty Training › Settings ("No guides to show", "No tests to show") in A1-Group:

> We need to import the Training Guides and Tests, etc from A1Tools into EzBizi, as usual adapt them to ezbizi, etc, so talk to the A1 Tools - Dismantler, for a1 chimney company on ezbizi.

## EzBizi's side (measured from the models, 2026-09-30)

- **`TrainingGuide`**: company_id, franchise_id, title, summary, status, and `screens`, a JSON array. A guide is written screen by screen.
- **`TrainingTest`**:
  - company_id, franchise_id, guide_id, title, status, and `questions`, a JSON array. A test is written question by question.
  - The rules: requires_guide, pass_mark, max_attempts, valid_for_days and count_attempts_after_pass.
- **`TrainingAssignment`**: company_id, franchise_id, guide_id, test_id, assignee_type and assignee_id (a person or a role), due_on, and assigned_by_user_id.
- **`TrainingAttempt`**: user_id, test_id, answers (JSON), score, passed, taken_at and expires_on.
- **`TrainingProgress`**: user_id, guide_id, last_screen and completed_at.

## Who builds what

1. **Inventory, read-only** (A1 Tools Dismantler, `local_2e076c5a-3340-4a54-a956-7a5de1fdb672`). What A1 holds for training, with counts:
   - guides and their pages or steps
   - media (images and video), and where the files live
   - tests, question types and scoring rules
   - assignments
   - attempts and results, and certificates
   - progress
   - which A-1 people each of those belongs to
   - It goes here, under "Inventory", with no personal data. Per-person detail stays on the Dismantler's machine.
2. **The importer and its file format** (Training module, `local_d30258ab-af2b-4b1b-9cf5-b0926fbe06ec`): `training:import-a1-tools`.
   - Training defines the file it reads, in a section here, the way Jobs, Workiz and HR each did.
   - It maps A1's shapes onto guides' screens and tests' questions, and says what does not fit.
3. **Export** (Dismantler): the bundle, in Training's format, staged like the earlier exports (www-data, 0700, deleted once verified).
4. **Run** (deploy desk): dry run, then a dump, then the real run, then verification.

## Rules the import must follow

- **Company:** everything lands under the **A-1 Chimney** company in A1-Group. Franchise-level placement happens only where A1 says so.
- **People:** A1 users map to EzBizi users by the staff migration's identities, by email. The crosswalk was deleted, so the Dismantler re-derives it. A person who is not in EzBizi is counted and left out, never created.
- **Media is copied, not linked.** Any file on A1's server goes into EzBizi's private storage, checksummed, like the inspection reports. A1's server is going away.
- **No side effects:**
  - Importing an assignment or a result sends no notification, email or push.
  - It raises no automation event.
  - It does not start anyone's due-date clock as if they had just been assigned.
- **Rollback:**
  - Record every id created, as a manifest and one audit row.
  - Offer `--dry-run`.
  - Be idempotent, keyed on A1's ids.

## Inventory

The Dismantler's counts, 2026-09-30. Everything is A-1 Chimney.

- **Guides:** 3 guides, 193 pages, stored as plain-text JSON blocks. 178 pages fit EzBizi's one-media screen as they are; 15 need splitting.
- **Tests:** 2 tests, 100 questions. All are single-choice with exactly one correct answer, so they fit TrainingTest.questions entirely.
- **Media:** 147 images on A1's own server (7.4 MB), which must be copied, and 73 Vimeo videos, which are hosted on Vimeo, not on A1.
- **Assignments and certificates:** none.
- **History:**
  - 54 test results, with per-answer detail
  - 3,085 page completions
  - 520 rows of videos watched
- **People:** 71 in total.
  - **44 match an A1-Group user: all current staff.** 41 match by email. The other 3 (A1 accounts 6, 8 and 62) have A1 emails that differ from their EzBizi ones, and match by phone or name exactly as the staff migration's crosswalk did.
  - 27 are counted and left out: 15 deleted and 12 former. No user is created.
  - People are resolved email, then phone (last 10 digits), then exact name, re-derived from ezbizi.users in A1-Group at export time. **The bundle keys each person by their EzBizi email.**

## Desk decisions (2026-09-30)

- **Content:** all guides and tests are imported. The 15 long pages are split into consecutive screens, in order.
- **Images:** copied into private storage with checksums. **Vimeo videos stay Vimeo links.** They do not live on A1's server, but they do depend on the Vimeo account staying open.
- **History** (attempts and progress): imported for the 44 matched people. For anyone not in EzBizi it is counted, never created.
- The "3 current staff not in EzBizi" were already in EzBizi, matched by phone or name, so no HR import is needed.
