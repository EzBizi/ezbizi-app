# Settings — review 2026-11-02

Measured on production on 2026-11-02, read-only: tinker as A1-Group's active administrator, plus direct counts. Nothing was written.

## 1. Working

- **Settings › Settings:** `GET /settings` answers A1-Group's organization scope with 136 rows in 19 ms.
  - All 99 stored values in production cast cleanly on read: no unknown keys, no rows that would throw.
- **Typed editors are live:**
  - subset: `jobs.earned_statuses` = ["Done"] for A1-Group, chosen from its own six statuses;
  - company and franchise map (Leads' call routing);
  - roles: Field's map;
  - number bounds: Field's estimates.
- **Module Settings tabs:** rows are gated on each module's Settings tab grant. Rows with a `readPermission` (Leads' call routing) are hidden from everyone without `leads.manage`.
- **Settings › Activity:** 100 rows in 79 ms on a 7-day window, against 25,872 events in 30 days.
  - It reads short columns only, so there's no sort-buffer error.
  - Opening one event (show) returns its before and after.
- **Settings › Pending:** 57 gaps across 29 places in 155 ms for A1-Group:
  - company: phone 9, address 8, logo 4;
  - branch: billing contact 19, Workiz connection 9, service area 1;
  - company: connected inbox 7.
- **Integrations:** 141 connections, all in the `connected` state.
  - Cards per provider, grouped by scope, with Connect from the pane.
  - "Also available" chips; "Open Marketing" / "Open Leads" buttons.
- **Roles & permissions:**
  - Organization, Settings and Integrations are tab grants.
  - Activity is gated on `audit.view`; Pending on `organizations.edit`.

## 2. Broken

- **Activity is nearly empty below the organization view.**
  - Evidence: 20,708 of 25,872 audit events in the last 30 days (80%) have no `scope_type`.
  - Why: `AuditLogger` records a scope only when the caller passes one, and most writers don't.
  - Effect: a branch or company manager's Activity tab misses four events in five. An org-reach person in a company view sees them only through the orgWide clause, unplaced.
  - The fix is in every writer, or in AuditLogger deriving the scope from the target's franchise or company. It's not mine alone, so it goes to the desk as a cross-module item. No fix SHA from me.

Nothing else was found broken.

## 3. Cohesiveness (UI/UX owns the fix list)

- Six settings still draw the raw-JSON editor:
  - `invoicing.channels`;
  - `jobs.pm_stages`, `jobs.pm_scope_statuses`, `jobs.pm_redo_words`, `jobs.pm_dispute_words`;
  - `jobs.thumbtack_status_map`.

  They're the only settings that ask a person to type braces.
- Two pop-up dialogs remain on the no-dialog ratchet list: Integrations' rename and Organization's picker.
- The Pending hints name paths in sentences ("Open Settings, then Organization, then the company"). OpenDestination lands on the destination, not on the inner tab.

## 4. Suggestions (ranked)

1. **Derive the audit scope in AuditLogger from the target model** (franchise_id, then company_id). Payoff: Activity works for every manager, not only org-wide.
2. **Typed editors for the six JSON settings.**
   - Word lists for the four PM keys (the existing list editor);
   - a status → Thumbtack-status map, like the code-to-place editor;
   - channels as a checklist.

   Payoff: no raw JSON left in Settings.
3. **Let OpenDestination take a tab key.** Pending's "open" items would then land on the exact screen, not a sentence.
4. **Mark more settings `expected: true`, module by module.** Today only `invoicing.billing_contact` is. Payoff: Pending covers settings as well as records.
5. **A per-check count on the Pending badge by module.** Payoff: owners can see which module leaves the most gaps.

## 5. Needs Jose

- Should every branch be expected to have its **own Workiz connection**? Pending lists 9 A-1 branches without one. If some branches don't use Workiz, Pending needs a way to say "not this branch" rather than nag.
- **Billing contact per branch** (19 open): fill these in now, or only as each branch starts invoicing?
