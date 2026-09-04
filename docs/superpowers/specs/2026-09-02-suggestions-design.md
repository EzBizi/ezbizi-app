# Suggestions — a private channel from any user to EzBizi

| | |
|---|---|
| Date | 2 September 2026 |
| Status | Design approved by Jose, not yet planned |
| Shape | One table, two controllers, two screens. No module registry entry. |

---

## 1 · What this is

**Anybody signed into the app can tell EzBizi what the software should do
differently, and find out what happened to it.** They write text, they send it,
they can correct or withdraw it while nobody has read it, and the tab tells them
whether it is new, in review, approved or denied — with the reason, in the
reviewer's own words.

The other half is the Development side: a queue of every suggestion from every
organization, in one list, where staff move each one along and write the sentence
the author reads.

## 2 · Why it is not a module

**It is deliberately absent from `ModuleRegistry`, and that absence is the whole
mechanism.**

The requirement is that an organization cannot take it away. Every removal
surface in this product is keyed off a registry entry: `roles` composes
permissions from `PermissionCatalogue`, which reads the registry;
`ModuleAvailability` looks the key up in the registry and refuses a flag for one
it cannot find; the client rail draws `me.modules`, which the server builds from
the same place. A module with no entry has nothing for any of those to point at.

This is exactly how Development works today and why nobody has ever had to
defend it: there is no `ModuleDefinition` for it, and it appears in
`main.dart:725` as a hardcoded rail group gated on `me.development != null`.
Suggestions is the same trick with the gate removed — it is always populated,
for everybody.

**The rejected alternative was to register it and simply never ship a
`PlatformModuleFlag`.** That gets a dashboard glance, automation triggers and a
permission tree for free, and it fails the one requirement: the moment
`suggestions.view` exists in the catalogue, an org administrator can switch it
off for every role but their own. Availability is EzBizi's switch; permissions
are theirs. Registering the module hands them the second one.

The consequences of staying out are real and accepted:

| Lost | Judgement |
|---|---|
| Automation triggers | A suggestion is addressed to EzBizi, not to the customer's business. There is no rule an organization would write about one. |
| Dashboard glance | The bell already carries the only event that matters — a decision landing. |
| Permission keys | The point. |

## 3 · Who sees what

**Author and EzBizi staff. Nobody else — not their manager, not the
organization's owner.**

People will write things about their own company that they would not write on a
board their supervisor reads, and a feedback channel that is quietly readable by
the boss collects only the suggestions nobody needed to hear. The privacy is not
a nicety; it is what makes the answers worth reading.

**This is why the module writes no business audit rows.** An `AuditLogger` call
on create would put "Jose created a suggestion" into the organization's own Audit
tab, publishing the existence — and the timing, and the frequency — of every
private suggestion to exactly the people this section excludes. The content would
stay hidden and the channel would still be dead.

That is a stated exemption, not the silence `docs/EZBIZI-TODO.md` records against
five other modules. Those five write nothing because nobody decided; this one
writes nothing because the audit trail belongs to the organization and this
record does not. The reason is carried in the model's docblock so the next reader
finds a decision rather than a gap.

Staff activity is a different question and is already answered: the Development
banner tells staff their work there is recorded, and the platform's own logging
is unchanged by this.

## 4 · Storage

One new migration file —
`database/migrations/2026_09_02_160000_create_suggestions_table.php`. A new file,
never an edit to a deployed one: incremental deploys make a spent migration's
added tables silently never run.

| Column | Notes |
|---|---|
| `id` | uuid, primary |
| `organization_id` | uuid, FK cascade. The tenant boundary §5.1 requires of every tenant-owned row |
| `user_id` | uuid, FK cascade. The author |
| `body` | text |
| `status` | string(20), default `new` |
| `decision_note` | text, nullable — what staff wrote back |
| `decided_by` | uuid, nullable — the staff account that answered, as `PhoneServiceRequest.decided_by` records it |
| `decided_at` | timestamp, nullable |
| timestamps | |

Index on `(organization_id, user_id, created_at)` — the author's own list is the
query made on every open of the tab.

`App\Models\Suggestion` uses `BelongsToTenant`, `HasUuids`, `HasFactory`, and
holds its statuses as class constants (`STATUS_NEW`, `STATUS_REVIEWING`,
`STATUS_APPROVED`, `STATUS_DENIED`) rather than methods returning literals.

**Two obligations a new table carries here, both enforced by `RetentionTest`:**

- `StatesRetention`. Policy is `RetentionPolicy::forever()`, whose constructor
  demands the reason: an approved suggestion is the record of why a feature
  exists, and a window that deletes it throws away the only account of the
  decision. Growth is not a concern — this table takes a row when a person
  chooses to type one.
- `HoldsPersonalData`, because `body` is free text written by an identified
  person and can name colleagues or customers. `personalDataHeld` names `body`;
  `personalDataAbout` reaches rows by `user_id`.

## 5 · The author's API

`v1`, behind `auth:sanctum` + `tenant`. `SuggestionsController`.

| Route | Does |
|---|---|
| `GET suggestions` | Your own, newest first. Never anybody else's — the tenant scope is not sufficient on its own, so the query also filters `user_id` |
| `POST suggestions` | `body` required, 2000 characters |
| `PATCH suggestions/{suggestion}` | `body`. Yours, and only while `new` |
| `DELETE suggestions/{suggestion}` | Yours, and only while `new` |

**Editing and deleting stop the moment staff opens it.** A suggestion that can be
rewritten under a reviewer mid-read produces a decision about text that no longer
exists, and an author who deletes after a denial erases the reason. The refusal
says which of the two it is, in a sentence, through `Refusal.php` — a dead end
with no explanation is what §6 of the build plan forbids.

## 6 · The Development API

`v1/staff`, behind `auth:sanctum` + `staff`. `StaffSuggestionsController`.

Modelled directly on `StaffPhoneRequestsController`, which is the same shape
already working in this repo: a cross-tenant queue with a decision that carries a
mandatory sentence.

| Route | Does |
|---|---|
| `GET suggestions` | Every organization. `withoutGlobalScopes()`, eager-loading `organization:id,name` and `author:id,name`, `new` first, then `reviewing`, then decided; `latest` within each; limit 200 |
| `POST suggestions/{suggestion}/decide` | `status` + `note` |

`decide` accepts `reviewing`, `approved` or `denied`. It does not accept `new`:
that is the state of a suggestion nobody has touched, and restoring it would
hand the author back the right to rewrite text a reviewer has already read. A
second `decide` on an already-decided suggestion is allowed and replaces both the
status and the note — a reversal is a thing that happens, and the author is told
again.

**Unscoped reads live in this controller and nowhere else.** The `staff`
middleware puts `TenantContext` into platform scope, which is what makes a
deliberate cross-tenant query distinguishable from an accidentally unscoped one.
The same query on a tenant-facing route is a leak.

**A note is required to deny.** Phone requests already enforce this and the
reasoning transfers unchanged: the customer reads the sentence verbatim, and a
refusal without one comes back as the same suggestion next month.

## 7 · Telling the author

A decision to `approved` or `denied` writes one row into `notifications`,
addressed to the author, with `subject_type` = `Suggestion::class` and
`subject_id`.

**Moving one to `reviewing` is silent.** It is not news — nothing has been
decided — and the bell is the wrong place to learn that somebody has started
reading. The author sees the state on the record, which is also where they will
see that they can no longer edit it.

**Written directly, not through `NotificationDispatcher`.** The dispatcher calls
`$this->tenant->idOrFail()` to stamp `organization_id`, and a staff request has
no tenant in context by design. The suggestion already carries the organization,
so the row is composed in the controller. Nothing else about the dispatcher is
wanted here either — its audience resolution answers "who could have seen this
record", and this record has exactly one reader who is already known.

`source` is `system`. `NotificationDispatcher::SOURCE_SETTINGS` leaves `system`
out deliberately and permanently, so an organization's notification toggles
cannot suppress it — which is consistent with §2: the organization does not
control this channel.

No client work is needed for the bell. `notification_bell.dart:208` routes only
`task` notifications on tap, so this one renders and reads as an ordinary row.

## 8 · The client

| File | Change |
|---|---|
| `client/lib/main.dart:704` | A second `Destination` in `pinned`, beside Settings, unconditional |
| `client/lib/main.dart` `_apply` | `/suggestions` resolves to `_Rail.pinned`, as `/settings` does |
| `client/lib/screens/suggestions_screen.dart` | New. The author's tab |
| `client/lib/screens/development_screen.dart:174` | A seventh entry in `tabs` |
| `client/lib/screens/staff/suggestions_queue_screen.dart` | New. Modelled on `phone_requests_screen.dart` |

**Pinned rather than a rail destination.** The business rail is assembled from
`me.modules` per person and can change length while somebody is looking at it;
Suggestions is not in that list and never varies. Pinned is where the two
destinations that are always there already live.

**The two new screens go through design first.** `docs/UI-DESIGN-QUEUE.md`: a new
tab or major screen is presented as numbered HTML mockups in the app's own chrome
with the real palette, and Jose answers by number. Backend and tests are built
first; the Flutter is written against the chosen number.

## 9 · Tests

`tests/Feature/SuggestionsTest.php` and `tests/Feature/Api/StaffSuggestionsTest.php`.

- A user's list contains their own and not a colleague's in the same organization
- A user's list contains nothing from another organization
- Edit and delete succeed while `new`, and are refused with a sentence once `reviewing`
- Editing or deleting somebody else's is refused
- The staff list spans organizations; the tenant list never does
- Deny with no note is refused; approve with no note is allowed
- `decide` to `new` is refused
- Approving or denying writes exactly one notification, addressed to the author,
  carrying the organization the suggestion belongs to
- Moving to `reviewing` writes none
- `RetentionTest` and `DataInventoryTest` pass without an exception-list entry

Run on an own schema, never the shared one:
`DB_DATABASE=ezbizi_testing_suggestions vendor/bin/phpunit --filter=Suggestion`

## 10 · Not building

| Not building | Why |
|---|---|
| Voting, comments, a public roadmap | §3 makes suggestions private. A board people vote on is a different product, and it is the one that leaks. |
| Attachments or screenshots | Text was the ask. Storage, scanning and retention for a file are each their own decision. |
| Categories or tags | Four statuses and a body. Structure invented before there is a corpus is structure nobody fills in. |
| Email on a decision | The bell is in-app and free. SES is still sandboxed (`docs/ACTIVE-CONSTRAINTS.md` §3) and every recipient would need verifying first. |
| Editing after review | §5. |
