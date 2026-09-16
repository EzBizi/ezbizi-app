# Development rules

These are current operating rules. Detailed incident evidence remains in the [archived StartUp](history/2026-09-08-startup.md); numbered architecture references point to the [build plan](EZBIZI-BUILD-PLAN.md).

## Safety and shared work

- Read STATUS, active constraints, the work board and module ownership before work. Track starting, completed, waiting and parked work in the same turn; STATUS/TODO remain the repo of record.
- Isolate concurrent implementation in worktrees and agree file ownership. Preserve unrelated edits. A worktree does not prevent a later merge from dropping content.
- Commit intended paths only. Inspect `scripts/index-landmines.sh` immediately before a shared-tree commit, then full paths and hunks. Private-index commits need one pinned base for tree, parent and compare-and-swap, plus shared-index reconciliation afterward; consult the archived recipe before using plumbing commands.
- Verify content at the final commit after merges. An ancestor check proves history, not preservation. Do not infer session authorship from shared Git names.
- A1 Tools is read-only reference. Secrets stay outside repo, board and chat. Diagnose production read-only; ship using release scripts. Never test against production or copy its environment into a sandbox.
- Handing a peer a SHA, say MERGED or NOT MERGED. A commit id names work; it does not say whether the other session can call it. Four sessions exchanged branch SHAs in one evening and every recipient had to run `git merge-base --is-ancestor` to find out — one of them concluded a shipped fix did not exist, correctly for the tree they measured and wrongly about the work. State it in the same sentence as the id, and scope any grep-based claim to the tree it was taken on ("on main", not "nowhere").
- Write the callee before the caller. A saved file that does not COMPILE reds every client suite that reaches the app shell, not only the one for the module you are editing — a peer could not gate `screens/phone/settings_tab.dart` because `automation_builder.dart` was calling a method not yet written. A green module suite is not evidence about the shared tree: which suites a broken file takes down depends on which surface each test file builds, not on how careful the edit was, so no gate the author could have run would have said anything.
- Owned developer connections use `development@ezbizi.com` after the Workspace mailbox exists. Customer OAuth uses their own identity. Do not move DNS to Route 53; self-hosting is settled architecture.

## Product and domain rules

- Follow the [ownership contract](OWNERSHIP.md). Parents are write-once; create a company and initial franchise transactionally.
- Gate access by release flag → entitlement → permission → assignment. Refuse with useful server sentences, rendered by the client. Do not enable forbidden actions.
- Ask `SettingsResolver`: franchise → company → parent company → organization → registry default, respecting allowed scopes. Keep platform invariants, deployment config, flags, entitlements, tenant settings and personal preferences distinct.
- Convert view-timezone boundaries to UTC before querying UTC datetime columns. Test both window edges; date-only fields have separate semantics.
- Use existing registries and domain services. Events enter the automation outbox; requests do not directly execute rules. Consider events, conditions and actions per module, documenting deliberate omissions.
- Customer configuration belongs in the app. Use plain words, honest empty/error states and working next actions. No modal dialogs: screens or side panels. Support touch and 375px widths. Use theme colors, not literals or decorative gradients. Review major screen designs in actual app chrome before implementation.
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
