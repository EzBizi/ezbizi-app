# How I build things in EzBizi

For an agent joining this repo. `StartUp.md` says what the rules *are*; this says
what a piece of work actually *looks like* from start to deployed, in the order the
steps happen. Worked example throughout: Phone › Reports, built 2026-08-28, from
"we need reports on calls" to live in one session.

Nothing here overrides `StartUp.md` or `docs/EZBIZI-STATUS.md`. STATUS wins.

---

## 0 · Before writing anything

Four reads, in this order, and none of them takes long:

1. **`docs/EZBIZI-STATUS.md`** — current state. Two documents disagree, this one wins.
2. **The board** (`H:\Development\integrations-board`, http://localhost:8787) — is
   this already a track? Create it if not. Tick steps *in the same turn* as the work.
3. **`docs/agents/inbox/`** — a second agent works this repo and we never share a
   chat. Read the mail before starting. *(Since September many role sessions work
   here at once; reach one through [docs/SESSIONS.md](SESSIONS.md), and the inbox is
   the fallback when a role is unstaffed.)*
4. **The nearest existing module.** Not the docs — the code. Reports was written by
   reading `Phone/CallsController.php` and `PhoneNumberAccess`, because a report is
   the same access question in aggregate form.

That fourth one is the one agents skip and the one that costs the most. Every
convention in this codebase is already implemented three times somewhere; find the
implementation, don't reinvent it from the prose.

---

## 1 · Ask the questions that change the shape, numbered

Ambiguity that changes what gets built gets asked, in a numbered list, before code.
Ambiguity that doesn't gets a stated assumption and I keep going.

For Reports the shape questions were: *does a saved report store dates or a period?*
(period — a question worth saving gets asked again in October) and *can somebody
report on a line they cannot read?* (no — an aggregate is still an answer about rows
they are not allowed to see). Both are in the controller docblock now, because both
would otherwise get "fixed" by the next person.

---

## 2 · Layers land in this order, each one checked before the next

```
migration → model → support class (the real logic) → controller → routes
  → policy/gates → tests → UI mockups → Flutter screen → version bump → deploy
```

Bottom-up, because a break is then one layer deep instead of an archaeology dig.
Concretely, for Reports:

| Layer | File | What goes there |
|---|---|---|
| Migration | `database/migrations/…_create_phone_reports_table.php` | Saved reports. Period as a string, not two dates. |
| Model | `app/Models/PhoneReport.php` | Casts, relations. Nothing clever. |
| Support | `app/Support/Phone/CallReport.php` | **The query engine.** All the counting lives here. |
| Controller | `app/Http/Controllers/Api/Phone/ReportsController.php` | HTTP shape, validation, and the docblock that is the design record. |
| Routes | `routes/api.php` | Beside the other phone routes, matching their comment style. |
| Registry | `app/Providers/ModuleServiceProvider.php` | Triggers and actions, if the feature emits anything. |
| Tests | `tests/Feature/Phone/PhoneReportTest.php` | Six of them. See §4. |
| UI | `client/lib/screens/phone/reports_tab.dart` | After the mockup is picked, not before. |

**The logic never lives in the controller.** Controllers validate, authorize, and
call a class in `app/Support/<Module>/`. That is why `CallReport` can be tested
without HTTP, and why the same counting can later answer a dashboard widget.

**The controller docblock is the design record.** Not "this controller handles
reports" — the two or three decisions a reasonable person would get wrong. Read the
top of `ReportsController.php`; that is the target length and the target subject.

---

## 3 · Four things I check on every module, without being asked

These are the ones that do not fail a test and are wrong for a month:

1. **§4 gates, all four, in order** — release flag → entitlement → permission →
   assignment. A permission check alone is not access control here. And if the answer
   would be 403, the client does not draw an enabled button.
2. **View, not reach.** `companiesInView` / `franchisesInView` / `filingCompanyId` —
   never a loop over `me.companies`. This has already leaked in Inventory, Fleet and
   Phone. The proof is a widget test with a sibling company in `/me`. For Reports it
   meant every query starts from `PhoneNumberAccess::readable()`.
3. **Settings through `SettingsResolver`**, never off a row. Reports resolves the
   organization's timezone that way, so "last 30 days" means their clock, not the
   server's.
4. **Automations.** Does this emit anything? If yes, declare it in
   `ModuleServiceProvider` with a plain-English description. If genuinely not, say so
   out loud with the reason — do not invent a trigger, and do not skip the question.

Refusals carry a sentence from the server (`app/Support/Access/Refusal.php`) and the
client shows *that* sentence. Two copies of a refusal reason drift.

---

## 4 · Tests: few, targeted, and proven able to fail

**Scope equals blast radius.** While building I run only what the diff could break:

```bash
php artisan test --filter=PhoneReportTest
```

The full backend suite is the **deploy gate** — run once, by `scripts/deploy.sh --gates`
(gates are off by default), or on its own by `scripts/preflight-backend.sh`, at
the end. It is not a development loop, and running it by hand "to be sure" is how a
session turns into a day.

**MySQL, not sqlite.** `phpunit.xml` forces `DB_DATABASE=ezbizi_testing`. MySQL
reorders JSON object keys; nine tests once passed locally and failed the real gate on
exactly that. Never assert on the key order of something read back from the database.

**Every test gets broken on purpose once.** Delete the line of production code it
targets, watch it go red, restore. Ten seconds. This repo has shipped tests that
passed against the exact bug they claimed to catch — one measured the wrong widget,
one accepted any HTTP verb. Assume yours is one of them until you have seen it fail.
For Reports that meant corrupting `MISSED_STATUSES` and confirming the count moved.

**Six real tests beat two hundred.** Cover the auth boundary, the scoping boundary,
and the one calculation that is easy to get wrong. Not the framework.

**When a suite gets slow, diagnose it — do not out-wait it.** A repeating
`migrate:fresh` in the output is a bug, not progress: a test that runs DDL or commits
kills the wrapping transaction, so `RefreshDatabase` rebuilds the whole schema
(~78 seconds a time). A second rebuild in one run means stop and look.
`php artisan schema:dump` turns that rebuild into a single SQL load.

Budget for any one step: **30 minutes**. Past that the step is wrong, not slow. Kill
it and find the cause.

---

## 5 · UI comes after a mockup, and after the palette

For a new tab or a major screen: build **several HTML alternatives** in
`docs/previews/`, in the app's own chrome, with the real palette and real-shaped
data, **numbered** so the owner can answer with a number. Then build the one they
pick. Going straight to Flutter and guessing wrong costs far more than the mockup
does. See `docs/previews/phone-layouts.html` and `training-layouts.html`.

Then, in Flutter:

- Colours come from `client/lib/core/theme.dart`. **Never a literal.** No purple, no
  gradient stripes.
- **No modals, ever.** A new screen or a right-side panel. Dropdowns are not modals.
- **375px wide must work.** Touch and pinch are first-class; nothing assumes hover.
- Plain words for humans. Never a section number, a class name, or a word like
  "immutable" on screen.
- `flutter analyze` must be clean before you go near the deploy script — it is a hard
  gate and it will stop the release when gates run (`--gates`), and the merger runs it
  on the merged tree ([DEVELOPMENT-RULES](DEVELOPMENT-RULES.md)).

---

## 6 · Comments: decisions only

A long comment is for a decision that constrains the future. The test: *would
somebody reasonably do the obvious thing here and be wrong?* Yes → write why. No →
write nothing. `// set the value` is worse than silence.

Say each thing once and point at it from elsewhere. If you change behaviour a comment
describes, change the comment in the same edit. `structure_canvas.dart` once reached
1,513 lines of comment against 1,150 of code and two real defects still went through
it untouched — length made a wrong thing look considered.

---

## 7 · Shipping

> **Superseded for module sessions.** Do not run the release scripts yourself: push
> your branch to `origin` and ask the deploy desk to cut ([SESSIONS](SESSIONS.md));
> send your release-note line with the request, because the desk writes
> `client/lib/core/version_history.dart` (its header says so). Gates are **off by
> default** — `--gates` turns them on. [DEPLOY](DEPLOY.md) is the source of truth for
> the scripts and flags; the steps below are kept as a description of what a cut does.

```bash
./scripts/deploy.sh
```

Commit first: the gate pins `GATED_SHA` with `git rev-parse main` and tests that
commit, not your working tree — and pushes the same SHA, so what was gated is what
ships. `client/VERSION` is bumped once per release by whoever cuts it, not in every
contributing commit (`scripts/release.sh` bumps and commits it); see §9 of the
[former StartUp](history/2026-09-08-startup.md). `--code` skips the Flutter build.

The script is the gate and it is the only way to ship:

1. With `--gates`, the pinned commit (`git archive <sha>`, not the working tree) is
   extracted into the sandbox at `/opt/ezbizi-test` on the box, and the
   **backend suite runs there on MySQL** — its own `.env.testing`, its own database,
   a lock so two runs cannot collide.
2. With `--gates`, `flutter analyze` and `flutter test`, locally, in a worktree at the pinned SHA.
3. Web client built and stamped with `client/VERSION`.
4. **Code pushed first, UI second** — the UI goes straight live with no staging step,
   so the reverse order puts a new client in front of an old API.
5. The release backs up the database, migrates, syncs the permission catalogue,
   health-checks as the serving user, and only then swaps the symlink.

Red anywhere means nothing ships — when the gates ran. Trust it; do not pre-run its suite by hand.

**Never** edit on the server as a way of shipping, and never point a sandbox at the
production database (`scripts/sandbox.sh <name> --verify` proves it is not).

---

## 8 · Reporting

State, not vibes. "Gate green, 2,186 passed, Flutter clean, deployed, smoke-checked
Reports on the live app" — including, explicitly, whether production changed. Never
call a gate green you have not watched pass, and never describe the server from a
note that might be stale: `ssh app` and look.

If something is blocked, finish everything else and say plainly what you left and
why. Scaling the work down is the owner's call.

Do not invent constraints to sound agreeable, and do not invent agreement. If a plan
is wrong, say which part in a sentence or two, then do the work.

---

## 9 · Done means

Merged, gate green, deployed, one live smoke check, board ticked, and said out loud.
Not "implementation complete, only release remains." Release is part of the work.
