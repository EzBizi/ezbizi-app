# A1 Tools staff into EzBizi: the migration

> **Status 2026-11-13: FINISHED 2026-09-29 — this is history, not a plan.** Both phases ran
> and A1's clock-in retired that day (results at the end). A1 Tools was switched off on
> 2026-09-28. The one-off commands named below (`hr:import-a1-tools`,
> `worktime:import-a1-tools`, `hr:send-a1-welcome`) were deleted in `2795b58b2` (2026-10-09),
> and `fleet:import-a1-tools` in `13dd2595c` (2026-10-30). The scratchpad inventory and mapping
> paths below no longer exist. The dumps named below are still in `/home/ubuntu/manual-dumps/`
> on the app box (checked 2026-11-13).

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
| The 9 remote dispatchers | Dispatcher. Later the same day, Jose: "make a Remote Dispatcher role and put the ones that had the remote role in there as well". Done: Remote Dispatcher is a copy of Dispatcher's 30 permissions, and the 9 were moved to it through the HR people screen's update, audited as `user.role_changed`. |
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
| Vehicle assignments (2) | Not migrated. Both are on Jose's A1-TEST vehicle (created 2026-07-27, deleted in A1, never imported into EzBizi); Fleet measured it and created nothing. Jose, 2026-09-29: "no that's a test". Not migrated, by his word |
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
- **Recruiter** (for Brian Scheets): 15 permissions.
  - dashboard.view
  - hr.view, hr.dashboard.view, hr.records.view, hr.invitations.view, hr.onboarding.view, hr.contracts.view
  - hr.create, hr.edit
  - time_clock.view, work_hours.view, work_hours.general.view
  - training.view

  Jose, 2026-09-29: "yes, it should be tracked too". So `field.tracked` and `field.view` were added the same way, making 15 permissions.
- **Training** (7 people): a copy of Technician's 15 permissions, `field.tracked` included.

The export is a bundle in HR's format: `people.json` plus `files/<sha256>.<ext>`,
the files copied in and named by content hash. It goes in `/var/lib/a1-people-export`
(see the Run plan). This supersedes an earlier manifest-only note: the importer
refuses any path outside `files/`.

The rest of the mapping:

| A1 role | EzBizi role |
|---|---|
| technician | Technician |
| dispatcher, customer_experience | Dispatcher |
| remote_dispatcher | Remote Dispatcher (Jose, later on 2026-09-29; they were Dispatchers until then) |
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

## Decisions made during the build (deploy desk, 2026-09-29)

The exporter's dry run on prod (counts only; every decrypt succeeded):
- 79 accounts, which make 76 or 77 people
- 330 bundle files, 305.6 MB
- 2,664 time_records (27 open) and 514 day statuses
- 2 vehicles, 33 Workiz links
- SSN 8 plaintext / 37 encrypted, bank 9 / 43, EIN 3

Rules:
- **People already in EzBizi (the 12) keep their EzBizi role and assignments.** The importer skips role and scope for them and never refuses on an unmapped A1 role or a null scope. Eve (A1 `administrator`) and Bar (no A1 company access) are in this set.
- **The person key is the EzBizi email.** The exporter maps A1 accounts through a crosswalk: email, then phone digits, then exact full name. It matches against `/home/ubuntu/a1-migration/ezbizi-users.json` (0600, 13 users), so the 6 people matched by phone or name land on the right users.
- **The pair of accounts with different emails (188 and 195)** is merged only if the full names are identical. The email of the account with the latest activity is kept.
- **161 orphan clock-ins** under 16 retired usernames that no account links to are dropped. They stay in A1's backup.
- **Extras** (vehicles, Workiz links): Fleet and Workiz read the exporter's provisional shape as it is.
- **Security:** A1's PII "encryption" used a fallback key committed in the a1_tools repo, so treat A1's encrypted values as readable. EzBizi re-encrypts with its own key. After verification, the desk recommends that Jose purge A1's PII tables and backups.

## Run plan (deploy desk, 2026-09-29)

- **Export location:** `/var/lib/a1-people-export`. The directory is owned by www-data:www-data at 0700 and the files are 0600. Every importer runs as www-data (`sudo -u www-data php8.4 artisan …`), so the files it writes stay readable by the app.
- **Exporter's final dry run:** 76 people from 79 accounts (3 merges; 188+195 on a per-pair case-insensitive flag). Scopes: 42 company, 31 franchise, 2 organization, 1 none (Bar). 330 files (305.6 MB; 2 missing at source). 2,665 time_records (28 open), 514 day statuses.
- **Phase 1** (after HR's importer ships):
  1. Export everything.
  2. `hr:import-a1-tools` with `--dry-run`, check the counts, take a dump, then run it for real and verify.
  3. `worktime:import-a1-tools` runs only as `--dry-run`.
- **Phase 2**, the cutover:
  1. Send the "set your password" emails.
  2. People clock in on EzBizi, and the Dismantler retires A1's clock-in.
  3. Re-export worktime, with every shift closed, and import it once.

  The worktime import waits for the final export because `import_ref` would skip a row it had already imported while it was still open.

## HR's importer: the bundle it reads, and how history is stored

Written by HR, 2026-09-29, for the Dismantler (who writes the bundle) and the
desk (who runs it). `hr:import-a1-tools` reads exactly this. A key it does not
know makes the whole run refuse before it writes anything, so a typo in the
export cannot become silently missing data.

### The bundle

A directory, written to the root-owned 0700 staging directory:

```
bundle/
  people.json
  files/<sha256>.<ext>      one file per distinct document or photo
```

`people.json`:

```json
{
  "format": "ezbizi.a1-people/1",
  "exported_at": "2026-09-30T14:00:00Z",
  "organization": "A1-Group",
  "files": [
    { "path": "files/9f2c…e1.jpg", "sha256": "9f2c…e1", "bytes": 183422, "mime": "image/jpeg" }
  ],
  "people": [ { … one object per person, below … } ]
}
```

Every `path` a person names must be listed in `files`. The importer checks each
file's size and sha256 against its entry before it writes anything, and stores
the sha256 as the attachment's checksum, which is also how a re-run knows a
document is already there.

One person:

```json
{
  "email": "someone@example.com",
  "a1_user_ids": [83],
  "first_name": "Jon", "middle_name": null, "last_name": "Ames",
  "phone": "(203) 555-0148",
  "address": "41 Elm St, Apt 2, Stamford, CT 06902, US",
  "date_of_birth": "1991-03-04",
  "gender": null,
  "a1_role": "dispatcher",
  "scope": { "type": "companies", "companies": ["A1 Chimney"] },
  "started_on": "2025-06-02",
  "work_schedule": { "monday": { "start": "08:00", "end": "17:00", "off": false } },
  "lock_screen_exempt": false,
  "avatar": "files/0b1d…77.jpg",
  "onboarding": {
    "submitted_at": "2026-08-01T15:20:00Z",
    "answers": { "tax_id_type": "SSN", "ssn": "123456789", "bank_name": "…" },
    "documents": [
      { "kind": "drivers_license_front", "file": "files/…jpg", "original_name": "front.jpg" }
    ]
  },
  "pay_rates": [
    { "basis": "hourly", "amount": "27.50", "period": "hour", "effective_on": "2026-06-01", "note": null }
  ],
  "emergency_contacts": [ { "name": "…", "relationship": "Sister", "phone": "…" } ],
  "hr_notes": [ "…" ],
  "equipment": [
    { "name": "Chimney camera", "category": "equipment", "quantity": 1, "identifier": "CAM-17",
      "issued_on": "2026-05-01", "returned_on": null, "note": null }
  ]
}
```

Rules for the exporter:

- **`email`** is the key. It is trimmed and lower-cased before matching, per
  organization. The three people with two A1 accounts are ONE object each, with
  both ids in `a1_user_ids` (kept for the desk's count check, never stored).
- **Null, not a placeholder.** `0000-00-00`, `NA`, `N/A`, `TBD` and empty strings
  are `null`. The importer refuses a date it cannot parse rather than guessing.
- **`address`** is one line, the A1 parts joined in order with ", ", empty parts
  left out.
- **`a1_role`** is the A1 ACCOUNT role key (`a1tools_users.role`), untranslated.
  The importer maps it with the Roles table above, so the mapping lives in one
  place. A key that table does not name refuses the run.
- **`scope`**, one of:
  - `{"type": "organization"}` — all ten A1 companies.
  - `{"type": "companies", "companies": [...]}` — A1 company NAMES as A1 spells
    them ("A1 Chimney", "Crestline"); the importer maps them with section G1.
  - `{"type": "franchises", "franchises": [...]}` — A1 franchise CODES ("CT",
    "OH", "NY"); always under A-1 Chimney (G2).
- **`pay_rates[].period`** is `hour`, `week`, `month` or `year`, or A1's own
  word for one of them (`hourly`, `weekly`, `monthly`, `yearly`, `annual`),
  which the importer maps. Anything else still refuses the run.
- **`work_schedule`** is A1's own stored shape, unchanged. The importer converts
  it to EzBizi's `{timezone, days}` with America/New_York, and a day with
  `off: true` or no times becomes a day off.
- **`onboarding`** is `null` for somebody with no submission and no documents.
  `answers` keys must be from the list below. Anything tool- or equipment-shaped
  in A1's onboarding payload (`tool_selections`, `equipment_checklist`) goes in
  `equipment` instead, not in `answers`.
- **Sensitive values are decrypted by the exporter**, with A1's own
  `decryptPII`, and handle both forms A1 holds (plain digits on 18 rows). They
  appear in `people.json` as plain strings, which is why the directory is
  root-owned 0700 and deleted after the import is verified. The importer
  encrypts each one as it writes it.
- **Former staff are not in the bundle.** The importer does not filter them.

`answers` keys:

| Key | Label on the record | Sensitive |
|---|---|---|
| `job_title` | Job title in A1 Tools | |
| `tax_id_type` | Tax ID type | |
| `ssn` | Social Security number | yes |
| `ein` | EIN | yes |
| `ein_company_name` | EIN company name | |
| `bank_name` | Bank name | |
| `bank_address` | Bank address | |
| `bank_account_name` | Name on the bank account | |
| `bank_account_number` | Bank account number | yes |
| `bank_routing_number` | Bank routing number | yes |
| `whatsapp_phone` | WhatsApp number | |
| `csia_number` | CSIA number | |
| `vehicle_plate` | Personal vehicle plate | |

`documents[].kind`, each at most once per person:

`drivers_license_front`, `drivers_license_back`, `id_document`, `w2`, `w4`,
`signed_contract`, `uniform_photo`, `car_front`, `car_back`, `car_left`,
`car_right`, `car_interior`, `car_mileage`, and `other` for anything else. A
second document of a kind the person already has, and every `other`, is filed
as `other_1` … `other_10`. More than ten refuses that person rather than
dropping a file. The two rows that still carry the old SiteGround path, and the
one `.url` text file, are the exporter's to resolve before writing the bundle.

### How a historical onboarding is represented (HR's design)

An Onboarding needs a pinned contract version, and its invitation is optional:
`onboardings.invitation_id` is already nullable. So:

- **One contract per organization, "A1 Tools record (imported)"**, created by
  the importer the first time it runs, archived at once. Its one version has the
  thirteen answer fields and the file fields above, all filled in by the
  employer. Archived means it is not offered when somebody starts a new
  onboarding.
- **One Onboarding per person, pinned to that version.** No invitation. Status
  `completed`. `submitted_at` and `completed_at` are A1's submission time, or
  the import time when A1 has none. Nobody is recorded as having started it.
- **Answers** are ordinary `onboarding_answers`, encrypted at rest like every
  answer. The four sensitive keys are masked on screen and every reveal is
  audited, because the version marks them sensitive, not because the importer
  does anything special.
- **Documents** are FILE answers: each file becomes a private attachment on
  that Onboarding (`attachments`, the default private disk), and the answer
  holds its id. That is what makes them visible on the record: the Onboarding
  page shows files only as answers to file fields.
- **Why not the real A-1 templates.** They have no SSN, EIN, routing-number or
  document-kind fields, so the data would be stored under keys the form never
  draws: kept, and invisible. A version made for the import shows every value.
- **Why not a signature.** A1 signatures are drawn images over a PDF A1
  composed. EzBizi's are typed names hashed over the rendered document, and an
  import cannot honestly produce one. The signed PDF is kept as the
  `signed_contract` document instead.
- **Nothing fires.** The importer writes directly, so no `onboarding.completed`,
  `user.activated` or invitation event reaches automations. These are
  historical records, not things happening today.
- **It is also the re-run marker.** A person who already has an Onboarding on
  the A1 record contract is reported "already imported" and skipped whole, so a
  second run changes nothing.

### What the importer writes, per person

| | New person (A1 only) | Matched person (already in EzBizi) |
|---|---|---|
| `users` | created: names, email, phone, address, birth date, gender, role, active, and a random password nobody knows | names, phone, address, birth date and gender filled only where EzBizi's are empty; role and email untouched |
| `assignments` | from `scope` | untouched (EzBizi's are authoritative, see G3) |
| `employment_events` | `started` on `started_on` | earliest `started` moved to `started_on`, or written |
| avatar | copied | copied only if they have none (never over a photo they chose) |
| `work_schedule`, `lock_screen_exempt` | set | set |
| Onboarding, answers, documents | created | created |
| pay, contacts, notes, equipment | created | created |

One transaction per person. A person who fails is reported with the reason and
the run carries on; files copied for a person whose transaction rolled back are
deleted again. `--dry-run` reads everything, validates everything and writes
nothing, and prints per-person counts and a total the desk can hold against the
inventory. No personal value is ever printed: people are named by their A1 account ids, which is also how the inventory is keyed.

## Phase 1 result (deploy desk, 2026-09-29)

- **Export:** 76 people, 330 files (305.6 MB). The HR dry run matched it: 64 new, 12 matched.
- **Dump:** `/home/ubuntu/manual-dumps/hr-before-a1-people-import-20260929T155356Z.sql.gz` (13 tables).
- **First run:** 23 created, 10 updated, 43 failed, every one of them `League\Flysystem\UnableToCreateDirectory`. `storage/app/private/attachments` was `ubuntu:ubuntu 0700`, created on Aug 11 by a process running as ubuntu, and it held 9 older files the app could not read either. It was chowned to www-data like its siblings. Each failure had rolled back whole.
- **Re-run:** 41 created, 2 updated, 0 failed.
- **Verified:**
  - 77 A1-Group users (64 new, all active). New by role: Dispatcher 29, Technician 20, Training 7, Local Manager 6, Accounting 1, Recruiter 1.
  - All 64 new users have an assignment (33 company, 31 franchise).
  - 76 onboardings, 681 answers, 1 archived contract.
  - 328 attachments, all on disk with matching checksums (320.8 MB).
  - 35 pay rates, 3 emergency contacts, 52 notes, 294 equipment items.
  - 0 failed jobs, and no notifications from the import.
- **Welcome:** 64 sent (`hr:send-a1-welcome --send`). 64 unused links, valid until 2026-10-06.
- **A1 clock-in:** Jose, asked when it should stop: "Now". The Dismantler retires it, runs phase 2 (worktime only), and the desk imports the hours.

## Phase 2 and clean-up (deploy desk, 2026-09-29)

- **Hours:** A1's clock-in retired at 16:01:13 UTC. The final worktime export held 2,666 records (29 open). Dump: `worktime-before-a1-hours-import-20260929T160218Z.sql.gz`.
  - `worktime:import-a1-tools` imported 2,562 shifts (20 open, 2 closed at the cap). 93 overlapped EzBizi, 4 overlapped A1, 7 were unreadable. 497 days were tagged.
  - 50 people, 2024-01-01 to today. A re-run is a no-op.
- **Workiz team links:** Workiz's reviewed script ran after a dump (`workiz-team-before-a1-links-20260929T160820Z.sql.gz`). 20 were linked and 13 were already linked, so all 33 are done.
- **Clean-up:** `/var/lib/a1-people-export` (with its decrypted PII) and the crosswalk file were deleted once everything was verified.
