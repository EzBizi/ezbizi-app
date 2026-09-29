# A1 Tools staff into EzBizi: the migration

Coordinated by the deploy desk, which gives the all-clear to each step. This
file is the single source of truth: Jose's decisions, what moves where, who
builds what, and the order. It holds no personal data. The per-person
inventory stays on this machine only (see Inventory below).

## Jose's words

2026-09-29, the request:

> There is another thing we need to do which is the Migration of A1Tools Accounts into EzBizi
> So first check all the accounts on A1Tools Vs the ones on EzBizi to see which ones need transfer, this will include everything if possible even their onboarding information, contracts, images, etc etc, obviously adapted to our ezbizi system and placed where they should be, current work hours, etc etc.
> take your time
> for Roles you can ask me although some may be evident

His answers, 2026-09-29. Each is the option he picked, or the text he wrote:

| Question | Answer |
|---|---|
| Brian Scheets (A1 "Management", no EzBizi equivalent) | "Make a new role for him called Recruiter" |
| The 9 remote dispatchers | Dispatcher |
| The 7 people in Training | New Training role |
| The 30 people who have left | "No, current staff only" |
| A company assignment also reaches its sub-companies (A-1 Chimney → AngiSweep, Chimney Star) | "Yes, that's fine" |
| SSNs, tax IDs, bank details, scans of licences, IDs, W-2/W-4 and signed contracts | "Yes, all of it": numbers stored encrypted, scans as private files on each person's onboarding record |
| Data with no EzBizi home: which get new HR fields | Pay rates, Emergency contacts, HR notes, Tools & equipment held (all four) |
| When the "set your password" email goes out and clock-in switches | "When the import is verified": import, verify, then send. A1's clock-in stays on until then |

Also on record, from the A1 Tools Dismantler session: "right now a1tools is only
being used for clock in's". A1's clock-in is the last thing to retire, after this
migration.

## Inventory (measured 2026-09-29, read-only)

- A1 accounts: 120 (80 current, 40 former).
- EzBizi A1-Group users: 13.
- Matched: 12, by email, then phone, then exact name.
- To migrate: the **current** A1-only people, 67 of them, not counting test, reviewer and service accounts.
- The 12 matched people are updated in place (schedule, photo, hire date, documents, hours). Former staff are not migrated.
- Three people have two A1 accounts (Moshe Gafni, Ron Levi, Holly McManaway). Each pair shares one email and becomes one person.

The per-person table and the full source-to-target mapping are in the deploy
desk's scratchpad, **not in the repo**:
`C:\Users\jose\AppData\Local\Temp\claude\H--Development-EzBizi\c0ae8ada-92d0-48a2-8f10-54e767b03b7e\scratchpad\a1-accounts-inventory.md`
and `a1-migration-mapping.md` beside it. Read them from there. Do not copy the
per-person table into git.

## Where things go

| A1 data | EzBizi home |
|---|---|
| Name, email, phone, birthday, address (parts joined), active | `users` |
| Company or franchise access | `assignments`. A1 "main" company scope becomes a company assignment. Franchise scope becomes franchise assignments under A-1 Chimney only. All 10 companies becomes an organization assignment |
| Hire date / termination | `employment_events` `started` (backdated to `date_of_employment`) |
| Profile photo (`api/profile/pictures`) | `users.avatar_path`, file copied to `avatars/{org}/…` |
| Weekly schedule | `users.work_schedule`, time zone America/New_York |
| Onboarding form (`onboarding_submissions`, decrypted PII) | one historical Onboarding per person, with encrypted `onboarding_answers` |
| SSN, tax ID type, EIN, bank account and routing, bank name and address | encrypted onboarding answers on that Onboarding |
| Documents (licence, ID, W-2/W-4, signed contract PDFs, uniform and car photos): 495 files, 507 MB | private `attachments` on that Onboarding |
| Pay rate / salary | **new HR field** |
| Emergency contact | **new HR field** |
| HR notes | **new HR field** |
| Tools and equipment held | **new HR field** (per-person custody list) |
| Clock-ins (`time_records`, 3,360, UTC) | `time_clock_shifts`, removing overlaps with shifts already in EzBizi (10 matched people clock in to both) |
| Day statuses (`time_day_status`, almost all absent) | `time_clock_days.tag` |
| Vehicle assignments (2) | `vehicle_assignments` |
| Workiz team links (61) | `workiz_team_members.user_id` |

Not migrated, and they stay in the A1 backup:
- GPS history (70k points)
- training history (EzBizi has no matching content)
- skills (Workiz owns them)
- per-person tab overrides (EzBizi permissions come from the role)
- schedule overrides, audio recordings, drawn signature images, monitoring

## Roles

A1-Group has two new roles. Both were created on 2026-09-29 through the Roles API
with Jose's administrator account as the actor, so they were validated and audited.
Jose can change either in Settings.
- **Recruiter** (for Brian Scheets): 13 permissions.
  - dashboard.view
  - hr.view, hr.dashboard.view, hr.records.view, hr.invitations.view, hr.onboarding.view, hr.contracts.view
  - hr.create, hr.edit
  - time_clock.view, work_hours.view, work_hours.general.view
  - training.view

  It is not `field.tracked`. That is Jose's call if he wants it.
- **Training** (7 people): a copy of Technician's 15 permissions, `field.tracked` included.

The export writes a manifest (path, sha256, size) to `/root/a1-people-export/`
instead of copying the files. The importer reads each file in place on the same
box and checks its sha256.

The rest of the mapping:

| A1 role | EzBizi role |
|---|---|
| technician | Technician |
| dispatcher, remote_dispatcher, customer_experience | Dispatcher |
| local_manager | Local Manager |
| marketing | Marketing |
| accounting_manager | Accounting |
| developer (Jose, Bar) | Administrator (already) |
| management (Brian) | Recruiter |
| training | Training |

Test, reviewer and client accounts are not migrated. Eve, A1's administrator, is
already Lead Manager.

## Who builds what

1. **HR** (`local_4c933357-…`):
   - The four new HR fields, gated. Pay needs its own permission.
   - `hr:import-a1-tools {file} --dry-run`, following `fleet:import-a1-tools`. It is idempotent by email per organization.
   - A "set your password" link for imported people that lasts longer than the 60-minute reset. It is not sent until the desk says so.
2. **Clock In** (`local_8b413ba1-…`): `worktime:import-a1-tools {file} --dry-run` for shifts and day tags, with the overlap rule above.
3. **Export** (the A1 Tools Dismantler, who knows A1's `decryptPII`): one JSON bundle plus a file manifest, in the format HR and Clock In agree.
   - It is written to a root-owned 0700 staging directory on the box.
   - It is deleted after the import is verified.
   - No personal value is ever printed to a log, a chat or git.
4. **Workiz** (`local_ea9c0484-…`): link the migrated users to their Workiz team rows.
5. **Fleet** (`local_7b76b5b7-…`): the two vehicle assignments. They can also be part of HR's importer.
6. **Deploy desk**:
   - Creates the two roles.
   - Ships the code.
   - Takes a dump before the run.
   - Runs the dry runs and then the real runs.
   - Verifies the counts per person against the inventory.
   - Sends the emails only when Jose's condition is met.
   - Then gives the Dismantler the all-clear for clock-in.

## Order and safety

1. Code lands and ships in a release. The fields, importers and link are all dormant.
2. The export is written to staging.
3. Both importers run with `--dry-run`, and their counts are checked against the inventory.
4. A dump of users, assignments, employment_events, onboardings, onboarding_answers, attachments, time_clock_shifts and time_clock_days is taken to `/home/ubuntu/manual-dumps/` (0600).
5. The real runs, then verification.
6. Jose is told the result. The emails go out, clock-in moves to EzBizi, and A1's clock-in retires.
7. The staging export is deleted.

## Clock In export format (`worktime:import-a1-tools`)

Added by Clock In, 2026-09-29. One JSON object, UTF-8, written to the staging
directory beside HR's bundle. Current staff only; former staff are skipped
anyway, because they have no EzBizi account to match.

```json
{
  "time_records": [
    {
      "id": 4812,
      "email": "person@example.com",
      "clock_in": "2026-09-01 12:00:00",
      "clock_out": "2026-09-01 20:30:00"
    }
  ],
  "day_statuses": [
    { "email": "person@example.com", "date": "2026-09-07", "status": "absent" }
  ]
}
```

- `time_records`: one per A1 `time_records` row. `id` is A1's row id and is
  REQUIRED: it becomes `import_ref = a1tools:<id>`, which is what makes a
  second run (the cut-over) write only new rows. `clock_in` and `clock_out`
  are UTC exactly as A1 stores them, `Y-m-d H:i:s`. `clock_out` is `null` for
  an open shift. For a person with two A1 accounts, write both accounts' rows
  under the one shared email.
- `day_statuses`: one per A1 `time_day_status` row. `date` is `Y-m-d`, the
  day as A1 recorded it. `status` is passed through as A1 has it; only
  `absent` is imported (`other` has no EzBizi tag).
- `email` is matched case-insensitively to the users `hr:import-a1-tools`
  created, so this import runs AFTER HR's.

What the importer does with it:
- An A1 shift overlapping any EzBizi shift of the same person is skipped
  (EzBizi wins). Two A1 shifts overlapping each other keep the earlier.
- An open A1 shift is imported open, with the org's cap
  (`time_clock.max_hours`, 16) as its end, unless the person already has an
  open shift. One started longer ago than the cap is imported closed at the
  cap, `ended_by = schedule`.
- `absent` is written as the day's tag only if the person has no shift that
  day and the day carries no tag, note or extra hours already.
- Output is counts, plus EzBizi user ids with `--per-person`. It prints no
  name, email or time.

```
php artisan worktime:import-a1-tools /path/to/worktime.json --organization=A1-Group --dry-run --per-person
```
