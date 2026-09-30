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

## Training importer: the file it reads

Training module, 2026-09-30. `php artisan training:import-a1-tools {dir} [--dry-run]`.

### The bundle

A directory, staged like the earlier exports (www-data, 0700, deleted once verified):

```
manifest.json
media/<sha256>.<ext>        one file per distinct image, named by its own checksum
```

Images are content-addressed: an image used on two pages is ONE file and one checksum. Nothing else goes in `media/` — Vimeo videos are links and travel as ids inside the manifest.

### manifest.json

```jsonc
{
  "format": "ezbizi-training-a1/1",   // refused if anything else
  "exported_at": "2026-09-30T18:00:00Z",

  "guides": [{
    "a1_id": 3,
    "title": "Technician knowledge base",
    "test_a1_id": 2,                   // knowledge_bases.test_id, or null
    "target_roles": ["Technician", "Lead Tech"],   // reported, NOT imported (see below)
    "pages": [{                        // in the order a learner meets them:
      "a1_id": 812,                    //   chapter, then section, then page sort_order
      "chapter": "Chimneys",
      "section": "Inspection levels",
      "title": "Level 2 inspection",
      "blocks": [                      // content_blocks, in order, typed:
        { "type": "text",  "text": "Plain text.\nLine breaks kept." },
        { "type": "image", "file": "media/9f2c…e1.jpg", "sha256": "9f2c…e1", "a1_path": "training-media/2026/01/x.jpg" },
        { "type": "image_missing", "a1_path": "training-media/2026/02/y.jpg" },
        { "type": "vimeo", "vimeo_id": "123456789" },
        { "type": "table", "rows": [["Level", "When"], ["1", "Annually"]] }
      ]
    }]
  }],

  "tests": [{
    "a1_id": 2,
    "title": "Technician test",
    "pass_fraction": 0.85,
    "max_attempts": 10,
    "questions": [{
      "a1_id": 4410,
      "prompt": "What does a Level 2 inspection add?",
      "options": ["…", "…", "…", "…"],   // CANONICAL order, as stored
      "correct_index": 2
    }]
  }],

  "attempts": [{                        // training_results, finished only
    "a1_id": 77,
    "test_a1_id": 2,
    "email": "person@a-1chimney.com",
    "taken_at": "2026-04-02T15:04:05Z",
    "score_fraction": 0.9,
    "passed": true,
    "answers": [{ "question_a1_id": 4410, "selected_text": "…" }]
  }],

  "progress": [{                        // one entry per (person, guide)
    "guide_a1_id": 3,
    "email": "person@a-1chimney.com",
    "completed": [{ "page_a1_id": 812, "at": "2026-01-11T09:00:00Z" }],
    "current_page_a1_id": 830          // kb_user_progress pointer, or null
  }]
}
```

Two fields need the exporter to do something rather than copy a column:

- **`vimeo_id`** — the bare numeric id, taken from either shape A1 holds (57 full `vimeo.com/<id>` URLs, 17 bare ids). The importer builds the URL, so one form reaches it.
- **`selected_text`** — the TEXT of the option the person chose, read as `answers_detail.options[selected_index]`, or `null` for a question they skipped (`selected_index` -1), which imports as unanswered. A1 shuffled options on screen, but the Dismantler measured the stored order as canonical in 2,620/2,620 answers (A1's client maps back before saving), so an index would also have been right; the importer matches by text so that it cannot drift either way.
- **A1 ids** for guides, pages, tests and questions may be strings (`"Technician_kb"`); the importer treats every id as a string.
- **One entry per person.** Two A1 accounts that reach the same EzBizi email are merged by the exporter: all attempts; completions unioned, earliest timestamp per page; the current page from the account with the latest activity.

People are joined by `email`. The bundle carries no names, and nobody is created.

### How it maps

| A1 | EzBizi |
|---|---|
| guide | `TrainingGuide`, **published**, under the A-1 Chimney company |
| chapter → section → page | flattened into `screens`, in the order above |
| page title | screen `title` |
| text blocks | screen `body`, joined by a blank line |
| table block | appended to `body` as plain rows, `Level · When` |
| image block | copied into private storage; the screen holds a reference, served to readers by a short-lived signed link |
| vimeo block | `media_kind: video`, `media_url: https://player.vimeo.com/video/<id>` — the embeddable form; a `vimeo.com/<id>` page refuses to be framed |
| test | `TrainingTest`, **published**, `pass_mark = round(pass_fraction × 100)`, `max_attempts` as A1's, no expiry, `guide_id` from `test_a1_id` |
| question | `{prompt, choices: [{text, correct}]}`, one correct |
| attempt | `TrainingAttempt` for a matched person: `answers` rebuilt in canonical order, `score = round(score_fraction × 100)`, `passed` and `taken_at` as A1 recorded them |
| progress | `TrainingProgress`: `last_screen` = the furthest screen reached; `completed_at` = the last completion, only when every page of that guide is complete |

**A page with more than one image or video** becomes consecutive screens: the first carries the page's text and first media, each next one carries one more media and is titled `<title> (2 of N)`. Every image survives; none is dropped to fit.

### What does not fit, and what happens to it

- **Chapters and sections.** Training has no grouping above a screen. Order is kept exactly; the names are kept in the archived manifest, not injected into anybody's text.
- **Targeting** (`target_roles`, `target_users`). Not imported as assignments. A1's role names have no crosswalk to EzBizi roles, and a wrong mapping would assign training to the wrong people with nothing to show it happened; `target_users` are all former staff. The guides land published, in the library, readable by anyone holding `training.view`. The dry run prints each guide's `target_roles` so whoever assigns them in EzBizi has the list.
- **Answer shuffling.** EzBizi does not shuffle choices. The test itself is unchanged.
- **"Hidden once passed."** A1 hid a passed test; EzBizi lets a learner retake within `max_attempts`.
- **Tests in progress** (6) and **videos watched** (520). EzBizi keeps neither — an unfinished test has no state here, and watching is covered by page completion. Counted, not imported.
- **Deleted pages** (1 completion, 4 pointers) and the **missing image** (1). Counted and skipped; the page still imports, without that image.
- **Attempt history is incomplete in A1 already** — "grant attempts" and "reset" deleted rows. Imported counts toward `max_attempts` from what remains, which is the state A1 was enforcing.

### Guarantees

- **No side effects.** Rows are written directly, never through the assignment, grading or progress paths, which are the ones that raise automation events. Training's models carry no observers (checked, with a control that finds the one observer the app does register). Nothing notifies, nothing emails, no due-date clock starts. No imported attempt carries an expiry, so the nightly expiry sweep has nothing to announce.
- **Integrity first.** Every image's sha256 is checked against the manifest before anything is written for it.
- **Idempotent, keyed on A1 ids.** Every created row is recorded against its A1 id, so a second run changes nothing and a later bundle — say, for someone who joins EzBizi later — adds only what is new.
- **Rollback.** That record is the manifest of every id created, and the run writes one audit row. `--dry-run` writes nothing and prints what would happen, including screens per guide and the matched and unmatched people.
