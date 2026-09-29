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

A1-Group gets two new roles, created as tenant data:
- **Recruiter** (for Brian Scheets): starts with HR permissions (people, invitations, onboarding). Jose can change them in Settings.
- **Training** (7 people): starts as a copy of Technician. Jose can change it in Settings.

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
