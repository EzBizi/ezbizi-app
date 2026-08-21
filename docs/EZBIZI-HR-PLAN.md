# EzBizi — Human Resources: implementation plan

**Written 2026-08-10**, from a decision session with the owner. §18 of the build
plan asks each module for its own plan with exact endpoints, migrations and
acceptance criteria rather than one document for the platform; this is that, for
HR. Every `§` reference points at `EZBIZI-BUILD-PLAN.md`.

> The owner: *"this is the most integral part of the app as it allows its basic
> function."* It is what lets an organization have employees at all.

## The shape

One gated module (see [[modules-gated-by-tab-permissions]]), five tabs:

| Tab | What it is |
|---|---|
| **Dashboard** | Headcount by franchise, onboarding in flight, stalled invitations, expiring certifications, starters and leavers |
| **Onboarding** | Creating and running an onboarding — the invitation, and the form the two sides fill |
| **Records** | Employees, and everything onboarding collected about them |
| **Invitations** | Every invitation and where it got to |
| **Contracts** | The template designer. **This is the schema for everything else.** |

## The central idea: the contract template IS the schema

The single most consequential decision in the feature. Owner's words:

> *"this should also all be managed from the onboarding screen - contract
> screen, which fields appear in onboarding, and how they change in the
> contract"* · *"no 2 contracts require the same stuff"*

So a contract template defines fields. Those definitions decide what onboarding
asks, who it asks, and what merges into the finished document. **Nothing about
the field list is coded.** This is §2.1's no-hardcoding rule applied to the part
of the product that varies most between customers.

### A field definition carries

| Property | Notes |
|---|---|
| key, label, type | text, number, date, choice, file, signature |
| **filled_by** | `employer` · `employee` · `either` — decided per field |
| required | |
| **sensitive** | see below; drives four real behaviours, not a label |
| retired | removed from new onboardings, still readable on records that have it |

`filled_by: either` exists for a specific reason the owner named: the employer
may pre-fill a date of birth and get it wrong, and the employee correcting it on
receipt should be ordinary rather than a support request.

**Consequence to build for:** with `either`, two people can write the same field,
so each answer needs **provenance** — who last set it and when — and the employee
must be able to see what was pre-filled versus what is blank. Without that, "the
employer typed it" and "I confirmed it" are indistinguishable, and for a bank
account that difference matters.

### The common core

Always present regardless of contract, because an invitation cannot be addressed
without some of it: **email, first name, last name, phone, date of birth**, and
**gender (optional)**. The employer *may* fill these when inviting but does not
have to; whatever is left blank the employee completes.

## Sensitive fields — what the flag actually does

The tenant defines the fields, so the app cannot know in advance that "Bank
account number" is sensitive. The creator marks it, and the mark means four
things:

1. **Encrypted at rest** — unreadable in a database dump. The mechanism already
   exists (TOTP secrets use it).
2. **Masked by default**, revealed only with permission, and **revealing is an
   audit event**. "Who looked at my bank details" is a question that gets asked.
3. **Excluded from exports** unless explicitly requested with permission.
   Otherwise the first CSV puts every account number on somebody's laptop.
4. **Named in the §9 data inventory** — which is what makes an erasure request
   answerable at all once fields are tenant-defined.

## Records, and the one-home rule

Core identity — name, phone, date of birth, gender, photo — **lives on the user
profile and is not copied**. HR points at it. Editing it is the same edit "Your
account" already performs.

| | Employee edits | Employer edits |
|---|---|---|
| Core identity | yes, owns it | read-only |
| Their own sensitive fields | yes, **audited and notified** | with permission |
| Employment terms (wage, start date, role) | read-only | yes |

Two homes for one fact is the trap this avoids; it is the same mistake as a
setting nobody reads.

## Contracts: template versus signed artefact

Two different things, and separating them resolves every question about editing:

- **The signed contract is frozen** — the rendered PDF and the answers, exactly
  as agreed. Editing the template never touches it. A signed document that
  changes retroactively is worthless.
- **The template is versioned** and keeps evolving. New onboardings use the
  current version; each record remembers which version produced it.
- **Adding** a field is always safe. **Removing** one retires it rather than
  deleting it.
- **Amendment**, for the owner's case of one wrong bank account: correct the
  field, re-render, keep both versions and who changed what. The original stays
  because it is what was signed; the amendment is what is true.

Output is **PDF, kept forever**. E-signature is **self-built** — ESIGN/UETA means
intent, consent to transact electronically, attribution, a tamper-evident record
and retrievability — and the record is to be designed so a vendor could be
swapped in later without a migration.

## Invitations

Single-use, **expiring after one week**, abandoned on the same timeout. Six
statuses: sent · received (the link was opened) · in progress · completed ·
abandoned · **revoked**. Resend and revoke both exist. One at a time; no bulk yet.

Revoked is its own status, decided 2026-08-11. It was first collapsed into
abandoned because the original list named five — but "we changed our mind" and
"they never came" are different facts about different failures, and a list that
cannot tell them apart cannot tell you whether your invitations are being
ignored.

**An invitation belongs to the ORGANIZATION, not to the person who sent it.**
Also decided 2026-08-11, correcting the first implementation. Reach and role were
re-checked at acceptance against the inviter *as they are now*, so a manager
leaving invalidated every link they had outstanding — a new starter turned away
because of a change on the other side of a business they had not joined yet.
What must still hold at acceptance is what the ORGANIZATION can still offer: the
role still exists and its permissions are still available under §13, and the
scope still exists and is active. Whether the individual who sent it is still
there, or could still send another, is not the question.

**Delivered as a copyable link now and by email when TODO #1 lands** — nothing on
this platform can send mail today, so an email-only invitation would be an
invitation nobody receives.

The automation triggers `user.invited`, `user.activated` and `user.deactivated`
are already declared and unraised. This is what makes them real; raise them.

## Who may do what

Governed entirely by the tab permissions an organization grants its own roles.
There are no preset roles beyond Administrator, which is **immutable** —
"it could lock itself out of its own powers, too dangerous."

Assignment is chosen **during onboarding** by the person running it, and bounded
by their own reach: *"he should only be able to see same level or downwards when
his options to assign an employee are presented"* — which is §4's existing rule,
not a new one. Records obey the same reach.

Every employee is a user account, and the record survives deactivation.

## Order

Roles and the permission editor first — **in progress**, and a prerequisite for
all of it. Then invitations → onboarding → records → contracts → dashboard.
Contracts last because they need an attachment primitive that does not exist yet
(no polymorphic file table, and storage is hard-wired to the local driver, so a
signed PDF would sit outside every backup that covers customer data).

§9's missing mechanisms — anonymise-in-place, data inventory, consent — are built
**alongside** this rather than deferred. Owner: *"yes of course."* Employee bank
details are precisely the data those exist for, and §9's own argument is that
they are cheap to design in and brutal to retrofit.

## Adapted A-1 role templates

The eleven A-1 Tools onboarding agreements are catalog data. Publish with
`php artisan hr:publish-onboarding-catalog "{organization name}"`. New signups
do not inherit them.

EzBizi now owns the capabilities those files needed, in this grammar:

- **Home address** is common core (`{{address}}`), on the profile.
- **Business address** is `{{organization.address}}`, set under Settings.
- **Paperwork date parts** `{{document.day}}` `{{document.month}}` `{{document.year}}` are the same day as `{{document.date}}`, never the signing time.
- **Sections** `{{#field}}…{{/field}}` keep a clause when that field is filled; `{{#pay_basis.hourly}}` keeps it when a choice is that option.
- **Declared markup** the renderer owns: `#` headings, `**bold**`, `-` lists, `|` tables. Author HTML is still escaped.
- **Checklist** field type: items with quantity and received date, merged as a table we drew.

## Still open

- Whether an employee may change their own bank account without an administrator
  seeing it. Payroll fraud looks exactly like that; the recommendation is to
  allow it, audit it and notify.
- What the Dashboard counts, precisely, once there are employees to count.
