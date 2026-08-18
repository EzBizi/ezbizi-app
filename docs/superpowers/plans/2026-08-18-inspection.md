# Inspection Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Do not deploy, `git push production`, or SSH.** Only when Mia says so in that session. Do not seed the live organization. Do not ship signup verification (#22).
>
> **Stop after Task 1–2 land if Claude has not yet reviewed this plan** (`docs/agents/inbox/` message when the plan is written). Table shape is cheaper to fix here than after a migration has run.

**Goal:** Ship the Inspection module as specified in `docs/superpowers/specs/2026-08-18-inspection-design.md`: Builder, Generated Reports, and New Inspection, with versioned named inspections, personal drafts, frozen reports, grants, and four automation events.

**Architecture:** Contracts-shaped templates (`inspection_definitions` + written-once versions). In-progress work is a per-person `inspection_runs` row keyed by `scope_key`, not by nullable `job_id`. Submit copies answers onto `inspection_reports` and deletes the run. Named-inspection access is a tenant grant like `mailbox_grants`, not a platform permission per Level 1. Layout presets and field types live in code. Extra work after submit is automations on `inspection.submitted`.

**Tech Stack:** Laravel 13 · PHP 8.4 · MySQL 8 (`ezbizi_testing`, name must contain `testing`) · Flutter web · FileStore · `WrittenOnce` · `ModuleRegistry` · `tab_bar_like.dart`

**Spec:** `docs/superpowers/specs/2026-08-18-inspection-design.md`

## Global Constraints

- Front door: `StartUp.md`. If documents disagree, `docs/EZBIZI-STATUS.md` wins.
- TDD: red test first; delete the production line once and watch it fail; restore.
- R8: user-facing sentences name what happened and what to do. No class names, `::`, or Exception in product copy. No “immutable.”
- Never copy A1 Tools code.
- No modals. Resume / start again is a screen or right panel.
- Colours from `client/lib/core/theme.dart`. No purple.
- 375px: Builder panes stack. Touch targets 44px.
- Tab permission is `inspection.view`. Actions: `view`, `create`, `edit`, `delete`, `archive`. Chips the person cannot use are not drawn.
- Unpublish / revoked grant: block new starts; already-open draft may submit; start again is a new start and is refused.
- `scheduled_for` bound values freeze as **rendered text** in the job franchise timezone at submit.
- Reports: two write exceptions only — `archived_at` and §9 erasure.
- FileStore for covers, photos, files, signatures. Never `Storage::disk()` in a controller.
- Register the module in `ModuleServiceProvider` **and** `client/lib/core/module_screens.dart` in the same commit (`ClientModuleScreensTest`).
- Automations: declare with `isRaised: false`, flip to `true` when the emit lands. Subject `null`. No Inspection automation *actions* (stated reason: reports do not change; rules act on job/customer).
- Seed command takes a named org; default is not live; blocked while TODO #9 is open.
- MySQL gate: `php artisan test` against a database whose name contains `testing`. GD/Dompdf missing locally is expected.

---

## File structure (locked)

| Path | Responsibility |
|---|---|
| `database/migrations/2026_08_18_200000_create_inspection_tables.php` | Four tables + grants |
| `app/Models/InspectionDefinition.php` | Named inspection pointer |
| `app/Models/InspectionDefinitionVersion.php` | Written-once published shape |
| `app/Models/InspectionRun.php` | In-progress, owner-only |
| `app/Models/InspectionReport.php` | Frozen submitted record |
| `app/Models/InspectionDefinitionGrant.php` | Role may start this named inspection |
| `app/Support/Inspection/FieldType.php` | Closed type list (do not add `photo` to HR `FieldType`) |
| `app/Support/Inspection/LayoutPreset.php` | `step_strip`, `document`, `quiet_stack` |
| `app/Support/Inspection/BindableFields.php` | Catalogue published from Customer/Job columns |
| `app/Support/Inspection/ScopeKey.php` | `customer:{id}\|job:{id}` or `…\|job:none` |
| `app/Support/Inspection/FieldSet.php` | Validate screens JSON |
| `app/Support/Inspection/InspectionAccess.php` | Permission + grant + reach (MailboxAccess shape) |
| `app/Http/Controllers/Api/Inspection/*` | Definitions, runs, reports, search, grants |
| `app/Providers/ModuleServiceProvider.php` | Register `inspection` |
| `client/lib/core/module_screens.dart` | Map `inspection` → `InspectionScreen` |
| `client/lib/screens/inspection_screen.dart` | Three chips |
| `client/lib/screens/inspection/` | `new_inspection_tab.dart`, `reports_tab.dart`, `builder_tab.dart`, presets, phone preview |
| `client/test/inspection_*.dart` | Widget tests |
| `tests/Feature/Api/Inspection*.php` | Feature tests |
| `app/Console/Commands/InspectionSeedDemo.php` | Named-org seed only |

---

### Task 1: Tables and `scope_key`

**Files:**
- Create: `database/migrations/2026_08_18_200000_create_inspection_tables.php`
- Create: `app/Support/Inspection/ScopeKey.php`
- Test: `tests/Feature/Inspection/ScopeKeyTest.php`
- Test: `tests/Feature/Inspection/InspectionTablesTest.php`

**Interfaces:**
- Produces: `ScopeKey::for(string $customerId, ?string $jobId): string` → `customer:{uuid}|job:{uuid}` or `customer:{uuid}|job:none`

Schema (review this before running migrate on anything but `ezbizi_testing`):

`inspection_definitions`: `id` uuid PK, `organization_id` FK cascade, **`name` string 150 NOT NULL**, `intro` text nullable, `cover_path` string nullable, `cover_disk` string nullable, `current_version_id` uuid nullable (no FK, same reason as `contracts.current_version_id`), `published_at` timestamp nullable, `created_by` uuid nullable FK users nullOnDelete, timestamps. Unique `(organization_id, name)`.

Create (Task 6) generates a unique working title in the same transaction — `Untitled inspection`, `Untitled inspection 2`, … — so the unique index never 500s the create button. Publish still requires a real name; renaming to a taken published name is a 422. Do **not** use a nullable name.

`inspection_definition_versions`: `id` uuid PK, `organization_id`, `inspection_definition_id` FK cascade, `version` unsigned int, `layout_preset` string 32, `screens` json, `intro` text nullable, cover path/disk nullable (copied onto the version), `published_by` uuid nullable, `created_at` only (`UPDATED_AT = null`). Unique `(inspection_definition_id, version)`.

`inspection_runs`: `id` uuid PK, `organization_id`, `user_id` FK users cascade, `customer_id` FK customers **restrict** (customers soft-delete; a hard DELETE is org cascade via `organization_id`), `job_id` uuid nullable FK jobs **restrict** — **not** `nullOnDelete`. Customers and jobs use `softDeletes()`; a soft delete is an UPDATE, so `nullOnDelete` never fires and would be dead. `scope_key` embeds those ids, so the row staying put is what keeps the key consistent.

**Soft-deleted customer or job:**
- A **report** still lists and still opens (evidence). Generated Reports search joins customers and jobs **`withTrashed()`**, so a historical name/number still hits. After §9 erasure the name is blanked and search will not find it by the old name — that is the purge working.
- A **run** is not resumable. New Inspection will not offer resume, and start against a trashed customer/job is refused. The run row stays until discarded or the org is removed; it must not become a second live draft after a job is restored (same `scope_key`).

`inspection_reports`: `id` uuid PK, `organization_id`, `customer_id`, `job_id` nullable, `inspection_definition_id`, `inspection_definition_version_id`, `submitted_by` uuid, `submitted_at` timestamp, `archived_at` timestamp nullable, `answers` json (includes frozen bind text), `layout_preset` string 32, `definition_name` string 150 (stamp; the definition may later be renamed), timestamps. Index `(organization_id, archived_at)`, `(organization_id, customer_id)`, `(organization_id, job_id)`. FKs to customers/jobs restrict, same soft-delete reason.

`inspection_definition_grants`: like `mailbox_grants` — `organization_id`, `inspection_definition_id`, `role_id`, unique `(inspection_definition_id, role_id)`.

- [ ] **Step 1:** Failing test: two runs for the same user + definition + customer with `job_id` null must not both insert; `ScopeKey::for($c, null)` is `customer:{id}|job:none`.

```php
public function test_two_customer_only_drafts_for_the_same_person_are_refused(): void
{
    $this->assertSame(
        'customer:aaa|job:none',
        ScopeKey::for('aaa', null),
    );
}
```

- [ ] **Step 2:** Run it; expect FAIL (class missing).
- [ ] **Step 3:** Implement `ScopeKey` and migration. Unique index is on `scope_key`, never on nullable `job_id`.
- [ ] **Step 4:** Feature test against MySQL: insert two runs with `job_id` null and the same scope_key → query exception.
- [ ] **Step 5:** Commit: `Add inspection tables keyed by scope_key so two customer-only drafts cannot hide behind SQL NULL.`

---

### Task 2: Models, WrittenOnce, erasure hole

**Files:**
- Create the five models listed above
- `InspectionDefinitionVersion` uses `WrittenOnce`, refuses `updating`/`deleting` with a sentence
- `InspectionReport`: updating refused except `archived_at` and columns `HoldsPersonalData` names for erasure

**Interfaces:**
- Produces: `InspectionReport::archive(): void`, `InspectionReport::anonymise(Anonymiser): void` (or inventory entries the existing Anonymiser already walks)

- [ ] **Step 1:** Test that `InspectionDefinitionVersion::query()->update(['screens' => []])` throws `WrittenOnceRefusal`.
- [ ] **Step 2:** Test that `$report->update(['answers' => []])` is refused, `$report->update(['archived_at' => now()])` is allowed.
- [ ] **Step 3:** Test that personal-data purge can write the erasure columns (the second hole). A purge test that would fail if the write-once guard has only `archived_at`.
- [ ] **Step 4:** Implement; register `HoldsPersonalData` on report (names, photos, signatures, stamped customer facts).
- [ ] **Step 5:** Commit: `Refuse edits to published inspection versions and freeze reports except archive and erasure.`

---

### Task 3: Module registry + client screen stub (same commit)

**Files:**
- Modify: `app/Providers/ModuleServiceProvider.php` — add `inspection` in `businessModules()`
- Modify: `client/lib/core/module_screens.dart`
- Create: `client/lib/screens/inspection_screen.dart` — three chips. The three tabs always draw with **honest empty states** (no published inspections, no reports, no search yet) — not blank panes and not a “waiting for” placeholder that looks like a missing permission.
- Test: existing `ClientModuleScreensTest` and `ModuleRegistryTest` must stay green
- Test: `client/test/inspection_screen_test.dart` — chips New Inspection, Generated Reports, Builder; chips not drawn when the corresponding action is missing

Actions: `view`, `create`, `edit`, `delete`, `archive`.

Triggers (`isRaised: false` until emit exists):

| key | label |
|---|---|
| `inspection.started` | Inspection started |
| `inspection.submitted` | Inspection submitted |
| `inspection.archived` | Report archived |
| `inspection.discarded` | In-progress discarded |

`subject: null`. `automationActions: []` with the reason in a comment on the `ModuleDefinition` (empty array is enough for `satisfiesAutomationRule` because triggers are present).

- [ ] **Step 1:** Add the client screen key without the PHP module → `ClientModuleScreensTest` must fail. Then add both.
- [ ] **Step 2:** `php artisan test --filter=ClientModuleScreensTest`
- [ ] **Step 3:** Widget test: role with only `inspection.view` sees Generated Reports, not New Inspection, not Builder.
- [ ] **Step 4:** Commit: `Declare Inspection in the registry and the client map so the tab can be granted.`

---

### Task 4: Field types, presets, bindable catalogue

**Files:**
- Create: `app/Support/Inspection/FieldType.php` — `text`, `number`, `date`, `choice`, `file`, `photo`, `signature` (one type). `takesOptions`, `allowsMultiple` on choice.
- Create: `app/Support/Inspection/LayoutPreset.php` — `step_strip` (default), `document`, `quiet_stack`
- Create: `app/Support/Inspection/BindableFields.php` — customer and job keys from the spec §8, including `scheduled_for`
- Create: `app/Support/Inspection/FieldSet.php` — refuse unknown types, require `max_count` default 1 on photo, `required_if` same-screen only
- Test: `tests/Feature/Inspection/FieldSetTest.php`

- [ ] **Step 1:** Unknown type refused. Photo `max_count` defaults to 1. Choice `multiple` bool. `required_if` pointing at another screen refused.
- [ ] **Step 2:** Bindable list contains `customer.name` and `job.scheduled_for`, not a made-up column.
- [ ] **Step 3:** Implement catalogues. Do not add keys to HR `FieldType`.
- [ ] **Step 4:** Commit: `Publish inspection field types, presets, and bindable customer/job keys as registries.`

---

### Task 5: Access — permission, grant, reach

**Files:**
- Create: `app/Support/Inspection/InspectionAccess.php` (copy the three-question shape from `MailboxAccess`, do not copy Email product code)
- Modify: `client/lib/screens/roles_screen.dart` — under Inspection, nested list of named inspections (same composition as inboxes)
- API: grant/revoke on role save
- Test: `tests/Feature/Api/InspectionAccessTest.php`

Rules:
- `create` without grants → picker empty sentence: the organization has not offered any inspections to this role.
- Revoked grant: cannot start; can submit an open draft.
- Reach: jobs franchise-scoped; customers org-visible (`CustomerPolicy`). Search uses existing reach.

- [ ] **Step 1:** Test Carlos cannot see Rodrigo’s run (different `user_id`).
- [ ] **Step 2:** Test picker omits ungranted published definitions.
- [ ] **Step 3:** Test submit of an open run still works after grant revoke.
- [ ] **Step 4:** Implement.
- [ ] **Step 5:** Commit: `Grant named inspections per role the way inboxes are granted, and keep drafts personal.`

---

### Task 6: Builder API + three-pane UI (get this in front of Mia early)

**Files:**
- `InspectionDefinitionsController` — list, create, update draft, publish (new version), unpublish
- Publish requires name; writes `InspectionDefinitionVersion` (`WrittenOnce`); points `current_version_id`; sets `published_at`
- Unpublish nulls `published_at`; open runs remain
- Client: `builder_tab.dart` — list | field work | live phone; stack at 375px
- Cover via FileStore
- Test: `tests/Feature/Api/InspectionDefinitionsTest.php`
- Test: `client/test/inspection_builder_test.dart` — three panes on a wide window; stacked on 375

- [ ] **Step 1:** Publish without a name 422 with a sentence.
- [ ] **Step 2:** Second publish increments `version`; in-progress run still pins v1.
- [ ] **Step 3:** Unpublish hides from picker, does not delete versions.
- [ ] **Step 4:** Flutter: no modal; phone preview uses placeholder answers, not live PII.
- [ ] **Step 5:** Commit: `Let Builder publish versioned inspections and preview them on a phone pane.`
- [ ] **Step 6:** Screenshot or HTML of Builder at 375 and wide — put it in front of Mia before filling every field type (0004 suggestion). Not a deploy.

---

### Task 7: Generated Reports

Smallest tab; forces freeze and version pin.

**Files:**
- `InspectionReportsController` — search by client name or job `number`, list, show, archive/unarchive (`archive` permission)
- Search: job-number pattern → **Job not found**; else **Client not found** when empty
- Archived hidden unless filter on
- Render read-only using `layout_preset` + `answers` on the report (not live definition)
- `definition_name` and submitted stamp on the document
- Test: `tests/Feature/Api/InspectionReportsTest.php`
- Test: `client/test/inspection_reports_test.dart`

- [ ] **Step 1:** Archive hides from default list; filter returns it. Raises `inspection.archived` (`isRaised` true on that trigger).
- [ ] **Step 2:** Updating answers after submit 422.
- [ ] **Step 3:** Bound `scheduled_for` on the report is a string in the franchise zone, unchanged if timezone later changes (store rendered text at submit in Task 8; assert the column is a string not a datetime).
- [ ] **Step 4:** Commit: `List frozen inspection reports behind search and an Archived filter.`

---

### Task 8: New Inspection — search, picker, run, submit

**Files:**
- Search customers/jobs with existing reach
- Picker: published + granted
- Start: insert run with `answers` `{}` (column is NOT NULL), emit `inspection.started`
- Resume vs start again panel (not a modal). Start again discards own run (`inspection.discarded`) then starts — refused if unpublished or ungranted
- Fill: step strip in order; Next blocked with sentence naming first missing required field and focus; photo required-if; camera or file
- Submit: write report, copy answers, freeze bind text (including `scheduled_for`), delete run, emit `inspection.submitted`
- Two signature fields are two `signature` type fields with labels
- Test: `tests/Feature/Api/InspectionRunsTest.php`
- Test: `client/test/inspection_new_test.dart`

- [ ] **Step 1:** Search miss copy.
- [ ] **Step 2:** Second start of same scope_key for same user returns the resume/start-again choice, not a unique-index 500.
- [ ] **Step 3:** Carlos starting the same job gets his own run.
- [ ] **Step 4:** Next with empty required Crown → sentence contains `Crown`.
- [ ] **Step 5:** Submit after unpublish still works; start again after unpublish 422.
- [ ] **Step 6:** Flip remaining triggers `isRaised: true`. `ModuleRegistryTest` mentions-in-code check must see the emit keys.
- [ ] **Step 7:** Commit: `Start, save, and submit personal inspection runs without a second filing place.`

---

### Task 9: Seed command (not production)

**Files:**
- `app/Console/Commands/InspectionSeedDemo.php` — signature `inspection:seed-demo {organization}`
- Both required, **AND** not OR: a named `{organization}` **and** the connected database name contains `testing` (`tests/TestCase.php` marker). `APP_ENV=testing` alone is not enough — that is a line in `.env`. Never default the org. Refuse on a database named `ezbizi`.

- [ ] **Step 1:** Test: missing argument fails. Database name without `testing` refused even if `APP_ENV=testing`. Named org plus testing DB required.
- [ ] **Step 2:** On `ezbizi_testing`, 10 customers + 10 jobs via factories.
- [ ] **Step 3:** Commit: `Add a named-org inspection demo seed that cannot silently hit production.`

---

### Task 10: STATUS / TODO

- Note Inspection as built (not deployed) on `docs/EZBIZI-STATUS.md` phase 4 when Task 8 is green.
- Do not bump STATUS numbers by hand.

- [ ] **Step 1:** Update STATUS in the same commit as the last green test, not before.
- [ ] **Step 2:** Agent mail to Claude: what landed, file:line for migrations, no deploy.

---

## Out of this plan

- Deploy, production seed, mail, PDF, Jobs-screen “Inspect”, offline, per-inspection colours.

## Review gate for Claude

Please check Task 1 schema especially: `scope_key` unique, `current_version_id` without FK, report stamps `definition_name` + rendered bind text, grants table not `permissions` rows.
