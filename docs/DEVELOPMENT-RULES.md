# Development rules

These are current operating rules. Detailed incident evidence remains in the [archived StartUp](history/2026-09-08-startup.md); numbered architecture references point to the [build plan](EZBIZI-BUILD-PLAN.md).

## Safety and shared work

- Read STATUS, active constraints, the work board and module ownership before work. Track starting, completed, waiting and parked work in the same turn; STATUS/TODO remain the repo of record.
- Isolate concurrent implementation in worktrees and agree file ownership. Preserve unrelated edits. A worktree does not prevent a later merge from dropping content.
- Commit intended paths only. Inspect `scripts/index-landmines.sh` immediately before a shared-tree commit, then full paths and hunks. Private-index commits need one pinned base for tree, parent and compare-and-swap, plus shared-index reconciliation afterward; consult the archived recipe before using plumbing commands. Two ways this check gives a green that proves nothing (an exported `GIT_INDEX_FILE`, and a snapshot taken before a peer staged lines): `SESSIONS.md`, "Two ways a commit check lies". Never `git stash` or amend in the shared tree.
- Verify content at the final commit after merges. An ancestor check proves history, not preservation. Do not infer session authorship from shared Git names.
- A1 Tools is read-only reference. Secrets stay outside repo, board and chat. Diagnose production read-only; ship using release scripts. Never test against production or copy its environment into a sandbox.
- Handing a peer a SHA, say MERGED or NOT MERGED. A commit id names work; it does not say whether the other session can call it. Four sessions exchanged branch SHAs in one evening and every recipient had to run `git merge-base --is-ancestor` to find out — one of them concluded a shipped fix did not exist, correctly for the tree they measured and wrongly about the work. State it in the same sentence as the id, and scope any grep-based claim to the tree it was taken on ("on main", not "nowhere").
- Write the callee before the caller. A saved file that does not COMPILE reds every client suite that reaches the app shell, not only the one for the module you are editing — a peer could not gate `screens/phone/settings_tab.dart` because `automation_builder.dart` was calling a method not yet written. A green module suite is not evidence about the shared tree: which suites a broken file takes down depends on which surface each test file builds, not on how careful the edit was, so no gate the author could have run would have said anything.
- **A shared control that speaks POSITIONS owes its callers a stated range.**
  `TabBarLike` takes `selected` as an `int`. Measured 2026-09-24: of its 21 call
  sites, **16 build `labels` with a collection-`if` on a permission or a layout
  constraint**, so the list is a different length for different people and at
  different window widths — and an index held in State means a different tab.
  Six screens had each invented their own defence, and no two are the same:
  `inspection_screen` clamps, `reports_screen` resolves by name with three
  length guards, `leads_screen` does `indexWhere(...) + 1` so -1 lands on "All",
  `records_tab` and `automations_screen` use a bare `indexWhere` that answers
  **-1**, and `phone_screen` and `training_screen` use an `Offstage` loop that
  simply draws nothing. **Several answers to one question is the tell that the
  question belongs to the widget.** What they share is the failure mode: *nothing throws*. An
  out-of-range index lights no chip and paints a blank pane, and a screen
  drawing its body from the same number disagrees with its own strip — a bug
  report that says "the tab went blank" with no stack to read. `TabBarLike` now
  asserts the range in debug (`tab_bar_like_test`); it changed no behaviour and
  caught nothing on the day it landed, which is what a guard against recurrence
  looks like. The general rule: **when a shared widget takes a number that
  indexes something the CALLER owns, say what the legal numbers are.**

  **COPY `hr_screen.dart`'s `_activeTab` — it is the earliest instance and the
  only one that carries the WHY.** Landed `b823e153`, 2026-09-17:

      /// `_tab` starts at `dashboard` [...] and a role granted Records and
      /// Contracts and nothing else holds no such tab. THE FIRST VERSION OF
      /// THIS FIX FELL BACK IN THE BAR ONLY: the bar highlighted the first tab
      /// while the stack compared every pane against a key nobody had, so all
      /// of them stayed offstage and the screen rendered blank. A widget test
      /// with two grants and neither of them Dashboard caught it; the
      /// twenty-six tests that grant all seven passed against it.
      String get _activeTab =>
          _shownTabs.any((tab) => tab.$1 == _tab) || _shownTabs.isEmpty
          ? _tab
          : _shownTabs.first.$1;

  The state is a **string key**, never an index. The index is derived from it
  against the list being drawn *this build* (`_selectedIndex`), so it cannot be
  stale and cannot go out of range; a key whose tab has gone falls back to a tab
  that is actually there, rather than to a blank pane; and **one getter feeds
  both the bar and the stack, so the two cannot disagree** — which is precisely
  the bug its comment describes, and precisely what the other defences leave
  open. `work_hours_screen.dart`'s `_active` getter (2026-09-24; line number dropped 2026-11-13, it moves) is the same getter
  with the names shortened; `reports_screen`'s resolve-by-name is the same idea
  in a third shape.

  Read the comment, not just the code: the fallback branch and the single getter
  both look redundant until a role hides a tab, and the story of the version that
  fell back in the bar alone is what stops somebody "simplifying" them away.
  **Twenty-six passing tests granted all seven tabs and were blind to it** — see
  the whole-suite rule below, and grant *subsets* when a screen filters by grant.

  **Nothing here needed a new API.** The outstanding `TabBarLike` work is not
  designing a key-based contract, it is making the other call sites look like
  this one, which any session can do to its own screen without waiting for
  anybody.

- **Run the WHOLE client suite before you hand a client commit over, not your module's.** Scheduling's sentence, after their new Time slots card reintroduced a `DropdownButton` that `no_popup_dropdown_test` would have failed on: *"a guard named after a rule instead of a module is invisible to a module-scoped test run."* They had run only the scheduling tests and nothing was wrong with that habit — the guard simply has no module in its name, so no module-shaped command reaches it. Measured, these are the ones nobody's module filter will run: `no_popup_dropdown_test` and `theme_conventions_test` (both scan `lib/` itself), `field_prompt_vs_value_test` and `search_picker_test` (theme and shared control), `tab_stays_in_its_section_test` (the shell), `version_history_test` (the release), `tab_bar_like_test` (the shared tab strip), `tone_colours_test` (the status colours in `theme.dart`). A module suite is evidence about a module; the guards are about the app.
- Run `flutter analyze lib` on the MERGED tree, and the duty is whoever performs the merge. Two commits that are each self-consistent and each gated can produce a tree that does not compile, and the break exists only in the merge — which neither author ever built. On 2026-09-17 a branch commit added `if (_tabs.isEmpty)` and a main commit renamed that getter 23 seconds later; git merged them cleanly because a rename and a new call site touch different lines, and `client/lib` was red on main for twenty minutes. **Neither branch owner could have caught it** — by the time both sides exist, only the merger has a tree containing them. A conflict-marker grep is necessary and NOT sufficient: this kind has no markers, and `git show` on a merge commit never shows a name that no longer resolves. Four seconds.
- Owned developer connections use `development@ezbizi.com` after the Workspace mailbox exists. Customer OAuth uses their own identity. Do not move DNS to Route 53; self-hosting is settled architecture.

## Product and domain rules

- Follow the [ownership contract](OWNERSHIP.md). Parents are write-once; create a company and initial franchise transactionally.
- Gate access by release flag → entitlement → permission → assignment. Refuse with useful server sentences, rendered by the client. Do not enable forbidden actions.
- Ask `SettingsResolver`: franchise → company → parent company → organization → registry default, respecting allowed scopes. Keep platform invariants, deployment config, flags, entitlements, tenant settings and personal preferences distinct.
- Convert view-timezone boundaries to UTC before querying UTC datetime columns. Test both window edges; date-only fields have separate semantics.
- Use existing registries and domain services. Events enter the automation outbox; requests do not directly execute rules. Consider events, conditions and actions per module, documenting deliberate omissions.
- Customer configuration belongs in the app. Use plain words, honest empty/error states and working next actions. No modal dialogs: screens or side panels. Support touch and 375px widths. Use theme colors, not literals or decorative gradients. Review major screen designs in actual app chrome before implementation.
- **A recurring sweep never overwrites a field a person has touched.** Any repeating import, sync or derived rebuild creates freely and then stops: it must be able to tell "nobody has decided this" from "somebody decided it", and leave the second alone. Vendor truth and EzBizi's record stay in separate columns or separate tables — `leads` splits `source_status` (overwritten every sync) from `status` (ours, never), and the same split is why Workiz jobs land in a mirror rather than in `jobs`. The failure is silent in one direction only: too much data gets reported by whoever sees it, while a reverted correction looks like the person never made it. Four sessions derived this independently on 2026-09-23 — Customers ("never overwrite a field a human has touched"), Jobs ("the projection creates, then never overwrites"), Scheduling ("NULL means a person, and a person wins over the evidence") and Workiz — which is why it is written here rather than in any one module's spec.
- Comments explain non-obvious decisions, not code narration. Finish authorized work and state remaining blockers precisely.

## Development and verification

Use PHP 8.4 and MySQL. Inspect resolved DB configuration, including `DB_URL` and cached config, before setup/migrations. Each runner gets a distinct `ezbizi_testing_<task>` schema. The database safety guard runs before destructive test setup; a testing environment name alone is not protection.

```bash
composer dev                                      # local API
php artisan queue:listen --tries=1 --timeout=0     # separate terminal
php artisan pail --timeout=0                      # optional logs
DB_CONNECTION=mysql DB_DATABASE=ezbizi_testing_task php -d memory_limit=1G vendor/bin/phpunit tests/Feature/YourModule
cd client
flutter analyze
flutter test
```

Configure local DB host/user/password separately without exposing secrets. Server tests use `scripts/sandbox.sh <name>` and `--verify`, not the production checkout.

MySQL JSON object keys do not preserve insertion order. Assert semantic object equality while retaining meaningful array ordering. SQLite is not equivalent for MySQL types, string limits or SQL functions. Reproduce original failures and test negative ownership cases. Choose test files explicitly: a name filter can miss a module's differently named tests.

Inspect exit status, actual test count and failures: zero tests, partial output or a crash is not a pass. Allow sufficient PHP memory for large suites. Verify tested commit/artifact identity after integration; staging edits require revalidation. Counts do not prove the expected set survived. Inspect movement before regenerating visual goldens.

On Windows, release archives use `git -c core.autocrlf=false archive <sha>`; do not change the shared checkout's policy. Source-parsing tests tolerate CRLF where intended. `routes/console.php` defines current schedules; do not duplicate timings into another document.

Release gates are opt-in. Use `--gates` for a verified release and report skipped checks honestly. Do not set or raise minimum installed-client versions; read active constraints before compatibility changes or mail sends.
