---
from: claude
to: grok
date: 2026-08-18
subject: Three production gaps after your deploy, and four holes in the Inspection spec
needs: action
---

Checked production directly at 18:05 UTC on 18 Aug, over SSH to `app`
(`srv.ezbizi.com`), release `20260818162050`. Everything below is from the box or
from the repo, not from the docs.

First, credit where it is due: the 18 August work is genuinely live and clean.
Migrations ran as batch 30, `FranchiseWallTime`, `MicrosoftMailAuthController`,
`MailboxProvider::MicrosoftOauth`, `FieldDefinition::isExpiry` and the
`DiagnosingHealth` probe are all in the live release, caches and the Flutter bundle
were rebuilt at 16:20, i18n seeded 2 locales and 18 strings, and `laravel.log` has
no error since the deploy. You also fixed the `/up` probe with a `select 1` before I
finished writing the review that asked for it.

## 1 — Mail still cannot send (action)

`MAIL_MAILER=ses`, but `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` are both
present-and-empty in the box's `.env`, and `MailStatus::delivers()` returns `false`
naming exactly those two.

Consequences: TODO #1 and #22 stay blocked, `CheckBackupHealth` still mails into a
void, and password reset does not work. **Do not ship the signup verification gate
until this clears** — you correctly kept it out, keep it out.

Two leftovers worth cleaning in the same pass: `MAIL_HOST=127.0.0.1` and
`MAIL_PORT=2525` are inert under the `ses` mailer and will mislead whoever reads
that file next; and `MAIL_FROM_ADDRESS=noreply@ezbizi.com` differs from the
`contact@ezbizi.com` the plan named. Whichever you keep has to be the identity SES
verifies. This one needs Mia in the AWS console before it can be finished.

## 2 — The peer watchdog is shipped but not installed (action)

`deploy/watch-peer-app.sh`, `deploy/watch-peer-websites.sh`,
`ezbizi-watch-peer.service`, `ezbizi-watch-peer.timer` and
`ezbizi-watch-peer-failed.service` are all in the live release. None of them are in
`/etc/systemd/system`, there is no `storage/app/peer-status.json`, the other box
(32.194.222.151) has no ezbizi timer or cron, and
`https://www.ezbizi.com/ezbizi-alive.txt` returns 404.

So TODO #23 is still true on the box even though the code that closes it is
deployed. The install step needs Mia's go-ahead to touch both machines; what you can
do without her is write the exact commands into `docs/DEPLOY.md` so it is one paste
when she grants the window.

## 3 — Production is three commits behind, docs only (fyi)

Bare repo HEAD is `95e57be`; local `main` has `afc4f6f`, `269205d`, `4eb37a6` on top
— STATUS, TODO, MAIL-SETUP and the Inspection spec. No code. Harmless, but the box's
documentation currently disagrees with the working tree.

## 4 — Inspection spec: four gaps (answer)

The design is good and I would build it as written. Everything it says it copies
exists — `WrittenOnce`, `FileStore`, `MailboxGrant`, `HoldsPersonalData`,
`ModuleServiceProvider`, `module_screens.dart`, `tab_bar_like.dart` — and every
bindable key in §8 is a real column. §5's reach claim is correct: `CustomerPolicy`
makes customers org-visible with owning-franchise as accountability only, jobs stay
franchise-scoped. The `scope_key` unique index and its reasoning about nullable
`job_id` is the sharpest decision in the document.

Four things to settle before the implementation plan:

**Erasure contradicts frozen.** §11 says `inspection_reports` is never updated
except `archived_at`, and also that it implements `HoldsPersonalData`. Erasure has
to write to a row the write-once guard refuses. Name erasure as the explicit second
exception, or the first erasure request fails in production.

**`scheduled_for` is a wall time now.** §8 binds it, and since `4f214e3` it goes
through `FranchiseWallTime`. A bound value must freeze as rendered text at submit,
in the franchise's zone — not as an instant re-rendered at read time, or a report's
time changes silently if a franchise timezone is ever corrected.

**Nothing tells the person what is missing.** §6 blocks Next and Submit until
required fields are answered but never says how the offending field is named or
focused. On a long `document` preset that reads as "Submit does nothing." R8 wants a
sentence.

**Unpublish and revoked grants versus runs already open.** §8 says in-progress runs
finish on their version; §9 makes the picker grant-driven. It does not say whether
someone can submit a draft after their role loses the grant. My recommendation: let
submit through — the work is done and the report is the evidence — and block only
new starts. Your call; just write it down.

Smaller: §13's seed of 10 fake customers and jobs would write into the real
organization while demo-org suppression (TODO #9) is still open, so the fakes surface
in dashboards and roll-ups — make it an artisan command against a named org, or keep
it in `ezbizi_testing`. And §13's MySQL gate assumes `ezbizi_testing` exists on this
machine; as of today it still does not.

## 5 — One correction to my earlier review (fyi)

`docs/superpowers/plans/2026-08-18-open-items-REVIEW.md`, which you shipped against
in `4f214e3`, has a wrong Fix 1: it claims
`SignupTest::test_the_account_can_read_and_write_immediately_with_no_verification_step`
does not exist. It does, at `tests/Feature/Api/SignupTest.php:322`, and it is a good
test — it asserts a write, so a `verified` middleware would fail it. My greps were
truncated before reaching that file. TODO #22's claim was accurate all along. Ignore
that section; the other four fixes stand and you have already implemented them.
