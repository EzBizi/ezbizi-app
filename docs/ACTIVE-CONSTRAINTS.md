# Active constraints — read before you write code

**REVIEW CLEARED 2026-08-31, confirmed by Jose. The freeze is over.** Opened
2026-08-28.

**This file is NOT being deleted, and the original instruction to delete it was
wrong.** §1 was temporary and is now history. §2, §3 and §5 are not — a minimum
client version is still dangerous, SES was still sandboxed then (production access
granted 2026-09-14, §3), and §4's rule now
lives in a test rather than in prose. A file whose header schedules its own
deletion takes the durable content with it, which was caught once already when
the CRLF section had to be moved to `StartUp.md` §8 to survive (since moved again
to `docs/DEVELOPMENT-RULES.md`; see §5).

It exists because these constraints were being broadcast to a session roster that
turns over every few minutes, so half the fleet never heard them and the other half
heard a version that had already gone stale. A message reaches whoever is listening;
a file reaches whoever arrives next.

---

## 1 · App Store review — CLEARED, constraint lifted

**Jose confirmed on 2026-08-31 that review has finished. Everything below is
recorded as history, not as a rule. You may change response shapes again.**

Still worth telling the deploy session when a release moves an existing response
shape — not as a veto, but because an installed client older than the release can
still be reading it, and knowing which release did it is the difference between a
report and an investigation.

What the constraint was, kept because it will return with the next submission:

Apple's reviewer ran the **submitted binary, frozen at `v0.0.0112`**, against
**live production**. The `.ipa` cannot change. Production can, and does, many times
an hour. The failure is invisible from our side: there is no broken reviewer session
to observe, only a rejection days later, and resubmitting restarts the clock.

**Do not, until review clears:**

- change an existing endpoint's **response fields** — removing or renaming one, or
  making a field that was always present become null or absent
- **tighten a permission answer** so a request that used to succeed now 403s
- alter a **`Refusal.php` sentence** the frozen client renders

**Free to ship:** new endpoints, new fields, new modules, and **all client-side
work** — the reviewer's client is the `.ipa`, not what we deploy.

### The frozen tree is readable. Check it instead of reasoning about it.

`8327833` is the commit `v0.0.0112` was built from.

```bash
git show 8327833:client/lib/screens/<whatever>.dart
```

That answers "does the reviewer's client read this field" definitively, in two
minutes. It has already overturned three confident guesses — including one of mine.

### Specific stop-rules found this way

- **`owning_franchise` is cast hard, non-nullable**, at `customers_screen.dart:287`
  and `:360` and `jobs_screen.dart:693`. Adding a nested key is safe. Making it null
  or absent **throws** on three screens across two modules.
- **Registering a new automation action is safe.** It is *inert* for the frozen
  client, not dangerous. `client/lib/screens/automation_builder.dart:177` enumerates
  `knownTypes` explicitly — one entry per key, never by prefix.
  `:527` returns any key outside that set as `enabled: false` with the badge "Needs a
  newer version of the app", and `:638` returns null for it, so the `StateError` at
  `:167` is unreachable. The docblock says so in as many words: *"an unknown type is
  never offered and the throw above is unreachable rather than merely unlikely."*
  New **triggers** render as their own raw key — `automation_catalogue.dart:118`,
  `CatalogueTrigger.tryFrom` falls back to `label: json['label'] ?? key`.

  *An earlier version of this file claimed an action outside one particular prefix
  would be offered and then crash on save. That was wrong in both directions:
  nothing crashes, and no prefix is safer than any other, because the set is
  enumerated rather than prefixed. It was corrected by checking the frozen tree
  instead of reasoning about it — which is what the paragraph above tells you to do.*
- **A registry addition changes an existing response by construction.**
  `GlanceRegistry`, `SettingRegistry`, `PermissionCatalogue` and the
  automation catalogue answer for the whole registry, not for your module. Safe only
  while the frozen client's parser is tolerant — and **tolerance must be checked on
  the write path, not just the decode.** The decode was tolerant in the case above;
  the crash is in serialisation on save.

  This is the category that gets through review of the diff itself. A new route, a
  new permission or a changed `Refusal` sentence can all be found by grepping a diff
  for the words. **A one-line entry in a registry's const array cannot** — it looks
  like adding a string to a list, and it changes what `GET /dashboard` returns. Ask
  "which existing responses does my registration appear in", not "which routes did I
  add".

  **Carry the mechanism next to the verdict, because they decay differently.** On
  present evidence all of triggers, actions, glances, settings and `SocialProvider`
  are safe — but for three different reasons. Glances and settings are safe because
  the client handles an unknown member **generically**, which is a property of the
  parser. Actions are safe because the write path is **guarded before it can be
  reached**, and a guard can be dropped by a later commit. `SocialProvider` is safe
  because a **filter** excludes the member from the response entirely, and a filter
  can be relaxed. "Safe" flattens three claims with different shelf lives.

  *Precision correction, twice. An earlier version said the dashboard dispatches on
  presentation rather than glance key — wrong. The replacement said it looks up by
  key with a default fallback — two-thirds right. `dashboard_panels.dart:71-73` (was 65-67) has
  **three arms**, and the middle one is a distinct second fallback:*

      byModule[key]                      // exact glance key
          ?? byModule[key.split('.').first]  // MODULE PREFIX — 13 icons registered here
          ?? Icons.insights_outlined         // default

  *So `training.progress` finds a `training` icon if one exists, and only then falls
  through. Two fallbacks, not one. Recorded at this length because a mechanism that is
  two-thirds right decays into a wrong one the moment somebody reasons from it — which
  is exactly how the first error got made.*

  **And declaring a thing is often only half of it.** `AutomationsController`
  holds a private `IMPLEMENTED_ACTIONS` (line 84 on 2026-11-13; was 50); an action
  absent from it is published as `implemented: false` (now ~:382) and refused (now
  ~:2124), so it is drawn as "not built
  yet" and can never run. Nothing fails if you forget — no test, no error, green
  everywhere. Before trusting that a registration is real, find the second const in
  the other file.

## 2 · Do not set or raise a minimum client version

This outranks everything above, because it needs no deploy and passes no gate.

`Api/StaffClientVersionsController.php` writes a `PlatformMinimumClientVersion` row.
`Middleware/EnforceMinimumClientVersion.php` refuses anything below it with **426
Upgrade Required**. It is a **database row**, set from the control plane — so every
safeguard this project has built (the pinned SHA, the content check, the
compare-and-swap, the deploy gate) inspects *commits* and cannot see it.

Raising that floor during review gives the reviewer a 426 on **every request** and
the app is dead in their hands, with nothing red anywhere on our side. The obvious
reason to raise it — forcing clients onto a new build after a wire change — is
exactly the reflex to suppress this week.

Production currently has **no minimum set** (re-measured 2026-11-13: 0 rows in
`platform_minimum_client_versions`). Nobody owns that controller; it is
control plane and appears on no module map.

## 3 · SES production access is GRANTED — this constraint is over

**Granted 2026-09-14.** Kept as history, like §1, rather than deleted: the
correction at the bottom outlived the constraint.

Measured on the SES Account dashboard rather than relayed: daily quota **50,000
per 24h** (was 200), send rate **14/second** (was 1), account Healthy, and the
sandbox banner replaced by a "Request a limit increase" link that only renders in
production. Both support cases closed.

**Mail now reaches any recipient.** There is no longer a list of verified
identities to add somebody to before you can send them anything.

### What this unblocked

TODO #22, signup email verification, which this section named as blocked. It is
built: signup sends a confirmation, and `RequireVerifiedEmail` refuses WRITES
(not reads) until the address is confirmed. Invited people arrive already
confirmed, because redeeming an invitation link is itself proof of the mailbox.

### The correction worth keeping

This section claimed that a sandbox **554 lands on the Rejects metric AWS reads**,
and therefore that a careless send "argues against us". **That was wrong, and it
was never measured.** 554s occurred for an unverified Gmail on 26 and 27 August
and again on 2 September, and the dashboard showed Rejects flat at **0%** across
that entire window. A 554 is refused during the SMTP transaction, so SES counts it
as neither a Send nor a Reject — the Rejects metric means a message SES *accepted*
and then dropped.

The habit that produced it is the one to keep an eye on: a plausible mechanism,
written down confidently, repeated to two other sessions, and never checked
against the dashboard that was one click away.

### Still true, and not about SES

**A successful send does not prove much on its own.** The first sign production
access had landed was one message to an unverified address being accepted instead
of refused — but verifying that single address would have looked identical. Read
the account dashboard before telling anyone the state changed.

## 4 · Never test against the shared `ezbizi_testing` schema

`RefreshDatabase` drops it, so two sessions testing at once destroy each other with
no useful error — the tell is an impossible pair, "migrations doesn't exist" beside
"users already exists". `phpunit.xml:30` (was :27) has no `force="true"`, so a real env var
wins:

```bash
DB_DATABASE=ezbizi_testing_<yourmodule> vendor/bin/phpunit --filter=<YourTest>
```

One module went 107 errors → 112/112 on this alone. Another went 153 → 17 the
same week, after reading this file.

**The collision does not look like a collision.** It looks like *you* broke the
tenant boundary — the red lands in `TenantIsolation`, `TimeClock`, `Inspection`
and `Web Management` at once, which reads as a fleet-wide security failure rather
than as two runners sharing a schema. That is the most alarming possible false
signal, and it points at the one thing you would most want to be wrong about. If
a run suddenly says you have broken tenancy everywhere, re-run it on your own
schema **before** you believe it.

**That prefix works for the test runner and nowhere else.** It names a *schema*
only because `phpunit.xml:29` (was :26) pins `DB_CONNECTION=mysql`. Everywhere else `.env`
says sqlite, and Laravel reads `DB_DATABASE` as a **file path**:

| Command | Driver | `DB_DATABASE` means |
|---|---|---|
| `php artisan test` | mysql | schema `ezbizi_testing_<m>` |
| `DB_DATABASE=… php artisan migrate` | **sqlite** | **a FILE `./ezbizi_testing_<m>`** |
| `DB_CONNECTION=mysql DB_DATABASE=… php artisan migrate` | mysql | schema `ezbizi_testing_<m>` |

The middle row **exits 0 and lists every migration as though it ran.** It has
already happened: a 925 KB SQLite file named `ezbizi_testing_twilio` was sitting
in the repo root, untracked and — until this was written — unignored, one
`git add -A` from being committed. For anything that is not `phpunit`, set
`DB_CONNECTION=mysql` as well.

---

Committing safely on this shared tree is **not** temporary and lives in
[development rules](DEVELOPMENT-RULES.md). The original incident record is in [archived StartUp](history/2026-09-08-startup.md). Who owns which files is in `docs/MODULE-OWNERS.md`.

---

## 5 · CRLF — moved, because it outlives this file

The `git archive` bug and the `$`-anchored-regex hazard now live in
the [development rules](DEVELOPMENT-RULES.md), with the original incident under "A green suite proves the code works, not that it is what you shipped" in [archived StartUp](history/2026-09-08-startup.md).

They were written here first and that was a mistake: this file's own header says
to delete it when App Store review clears, and CRLF checkouts have nothing to do
with App Store review. Whoever eventually deletes this file will be following the
instruction correctly, and durable knowledge must not be sitting in it.

Raised by ezbizi-ea, who noticed the header contradicted the content.

---

## 6 · Configuring a sending identity ARMED a shipped action — CLOSED

**Superseded 2026-09-17 (`be3cfb7ad`): the refusal below is GONE, by Jose's
reversal.** On 2026-09-16 he asked for automation email on *"Email ( the Ezbizi
SES one, not the company one )"*. `ActionRunner::emailInspectionReport` now SENDS
`inspection_report.email` through `MailDispatcher::platformForTenant` (EzBizi's
address, the company named in the body); `asTenant` remains an unconditional
throw. So this section's "refuses unconditionally" and the two quoted rulings
below describe the state before that reversal. Do not "restore" the refusal from
this text. (Measured 2026-11-13: production has 10 organizations and still 0
`sending_identities` rows; `SendingServersController` can write one since
`97de664cc`.)

**Closed 2026-09-14 by `6e0d7e98`, hours after this section was written.**
Inspection now refuses `inspection_report.email` unconditionally, and the refusal
returns ABOVE the Platform check rather than relying on it, so it no longer depends
on what is in `sending_identities`. Configuring a sending identity is safe again.
The catalogue entry stays and says SWITCHED OFF, so a rule somebody already saved
records why on every run - which is the case removing the row would have missed.

**Kept, not deleted, and re-read before you lift that refusal.** The mechanism below
is what made a correct guard unsafe, and it comes back the moment the refusal goes.
It is also the general shape: a check that passes because a table is empty is a fact
about the DATA wearing the clothes of a fact about the CODE.

Jose ruled twice on 2026-09-14, carried by two sessions independently. Via
Automations: *"no ezbizi SES is to be used only for EZBIZI Related actions like
notificaitons, account creation, password, resets, etc at least for now."* Via HR, asked a
third time through a different door and answered in his own capitals:

> *"No - Email should not be sent as EzBizi, EZBIZI EMAIL IS ONLY FOR EZBIZI
> ACTIONS LIKE ACCOUNT RECOVERY, PASSWORD, RESERT, VERIFICAIONS, ETC"*

That is the SERVICE reading, not the from-line reading: verifying a domain does
not buy the right to send customer content through us. A customer's own mail goes
through the customer's own provider, as newsletters already do via Mailchimp.

**Citation corrected 2026-09-14.** This section first supported that with a bare
relayed *"yes"*. Two other sessions then reported that the *"yes"* answered item 1
of a different numbered list - the mail-health layout - so the ruling was right and
my evidence for it was not. A one-word answer relayed without its question is not a
quotation. The block above is his sentence, carried verbatim.

`inspection_report.email` is shipped and selectable, and it mails a finished report
to the customer through `asTenant()`. It is **latent, not live**, and the reason is
a coincidence rather than a design:

    the MailTransport::Platform guard sits BEFORE the asTenant call
    production has 2 organizations and 0 rows in sending_identities
    so every organisation resolves to Platform, and the guard refuses

**The guard does not fail. Its premise changes.** "Configure a sending identity for
A-1 Group" is an open item on Jose's list, raised by Inspection themselves. The day
somebody does it, that action stops refusing and starts sending - silently,
correctly by its own lights, against a ruling made after it shipped. Nothing
announces the transition, and the first evidence would be a customer receiving a
report.

**The lever is Inspection's arm, not the Automations catalogue.** Removing the row
from `IMPLEMENTED_ACTIONS` is one line and looks like the fix; it is not. That const
stops NEW rules being written while the arm still runs every rule already saved.

Jose may reasonably distinguish "the customer whose chimney was swept" from "an
address a rule names". That is his call and it is in front of him. What must not
happen is the identity work quietly arming the action while nobody is looking at
both at once.

---

## 7 · `Mail::fake()` does not stop a real socket being opened

**A test that fakes mail and then reaches the dispatch will DIAL whatever host its
fixture names.** It looks exactly like a faked send, and the only symptom is an
outbound connection from wherever the suite runs.

Verified in `vendor/` on 2026-09-14, both halves:

    Illuminate\Support\Testing\Fakes\MailFake   has NO build() override
    Illuminate\Mail\MailManager::build()          exists, line 143

So `Mail::build($config)` - which is how the tenant-SMTP transport makes a mailer
from an array - forwards straight past the fake to the real manager. `Mail::fake()`
intercepts `send`, not `build`.

**What to do instead.** Assert against `MailDispatcher::smtpConfigFor()`, which is
public precisely for this: it throws every refusal and touches no network. Do not
write a test that gets as far as sending through a tenant provider.

Found by SES while building the transport and confirmed by Reports against their
own fixture - which, had it carried a plausible hostname rather than an obvious
one, would have begun dialling it in CI. It is a property of the shared test
environment, so every future caller of `throughTenantProvider` inherits it.

---

## 8 · A private-index commit leaves a landmine in the SHARED index

**After committing with `GIT_INDEX_FILE` pointed at a private index, clear the same
paths out of `.git/index` - or the next person to type `git commit` reverts your work.**

The mechanism, worked out by the SES session on 2026-09-14 after it had bitten four
times in one afternoon: a private-index commit moves HEAD and does **not** touch
`.git/index`, which still holds the PRE-COMMIT tree. `git status` then compares the
two and reports your own new files as **staged deletions** and your own edits as
**staged reverts**. So the discipline that stops you sweeping up a peer's uncommitted
work creates a landmine aimed squarely at your own.

What it nearly took, all in one afternoon:

    a staged deletion of TenantSmtpHost.php - the tenant mail transport, hours old
    a staged re-ADD of the sending-identity panel Jose had just asked be removed
    a staged revert of Phone's inbound-call fix, mid-release

Each was found by staging one file and reading `git diff --cached` BEFORE committing.
None was found by anybody looking for it.

**The missing step, every time:** after a private-index commit, `git restore --staged`
the exact paths that commit touched, in a separate call, then confirm the shared index
reads clean. Index only, never the working tree, and named paths only - anything
broader clears somebody else's deliberate staging.

`bash scripts/index-landmines.sh` is what makes this readable: it separates **stale**
(a snapshot of an already-committed tree - safe to clear) from **live** (somebody's
real work - do not touch). Run it in its own shell call, because a staged line
outlives the check that did not look for it.

---

## 9 · A commit can carry more than its message says — check before reverting

**`97de664c` is load-bearing for TWO features.** Its subject is *"A business can
save the mail server its own mail goes through"*, and it also contains **call
pickup's two routes**:

    git log -S "phone/calls/{call}/pickup" -- routes/api.php   ->  97de664c

Phone had those lines uncommitted in the shared tree when SES committed that path,
and `git commit -- <path>` takes the whole worktree content of a file. Phone
noticed because their own commit minutes later reported `routes/api.php`
unchanged - the absence, not the presence, is what showed it.

**Revert that commit as "just the mail-server change" and call pickup loses its
routes** while its controller, screen and tests remain. The tests go red, which is
the good outcome; the feature is gone from a release whose notes never mention the
phone.

Deliberately NOT re-attributed: the lines are correct and `route:list` resolves
both. Rewriting a commit already in other people's history to tidy the record is a
worse trade than a slightly wrong record. Phone's call, and the right one.

**The rule: before reverting anything, `git show <sha> --stat` and ask whether
every file in it belongs to the subject line.** On a shared worktree a
path-limited commit is not a promise about scope. §8 is the same mechanism from
the other side; this is what it costs when nobody notices for an hour.

---

## 10 · Never clear SHARED compiled state to debug your own run

**`php artisan optimize:clear` in the shared tree is a change to every other
session, not to yours.** It deletes the compiled views, and a dozen sessions then
race to recompile them:

    rename(storage/framework/views/c981E1D.tmp, .../c98666455c25d14c5cbf7f47159b8b84.php):
    Access is denied (code: 5)

2026-09-15, SES, reported unprompted. Worse than the burst: **it looked like it
worked.** One suite went green immediately after, which was almost certainly the
pause rather than the clear - so the command both broke other people's runs and
awarded itself the credit.

The same applies to anything else that rewrites state the whole tree shares:
`config:clear`, `view:clear`, `cache:clear`, `composer dump-autoload`,
`git stash`, `reset --hard`.

**What the symptom actually was, every time so far: §4.** A session was running
`RefreshDatabase` inside ANOTHER MODULE'S schema - `ezbizi_testing_email` rather
than their own - while that module worked in it. Alternating 17/17 then 1-passed,
same tree, same minute. On their own schema: three consecutive 18/18, then all
seven suites in ONE batch, 77/77 in 34 seconds.

That last number matters because the collision had produced a second, wrong
conclusion: *"this machine cannot sustain a multi-suite run"*, which had them
gating one suite at a time for an hour. **A shared-schema collision does not only
redden tests - it manufactures theories about the machine.**

**One hole kept deliberately, because the tidy story is what gets quoted.**
Mid-diagnosis the plain `mysql` CLI over TCP returned the same `1698 Access
denied` as PHP, which is what convinced them it was server-side: a schema
collision has no business producing an AUTH error. Three commands later, TCP,
named pipe and TCP again all succeeded with no intervention, and it never
reproduced. The correlation with the schema is strong; the causal chain has a gap
in it and nobody has closed it.

So: **check `DB_DATABASE` before concluding anything about this machine.**

**COUNTER-EVIDENCE, added hours later, and it breaks the tidy half of this
section.** Phone hit the identical 1698 twice - 335 errors, then 68 on the same
suite and unchanged tree, then 364/364 clean minutes later - while running in
their OWN schema throughout. They proved which schema by artefact rather than by
reasoning about PHPUnit precedence: `phone_callbacks` is a table only their
migration creates, and it exists in `ezbizi_testing_phone` and NOT in the bare
`ezbizi_testing`. So the env var really did win, and an isolated schema produced
1698 anyway.

They named what keeps it from being a clean refutation, which is why it is worth
believing: they cannot see whether another session is also in
`ezbizi_testing_phone` (an `ezbizi_testing_phoneivr` exists, so somebody has
worked this module from another seat), and **there are 58 `ezbizi_testing*`
schemas**, every one running the full migration set on every `RefreshDatabase`.
So whatever 1698 is, it happens on one MySQL under very heavy concurrent DDL.

**1698 is an AUTH code and every theory anyone has offered is about something
else** - which is usually the shape of a symptom nobody has found the cause of.
Two sessions, two mechanisms proposed, both wrong or unproven, and SES's plain
`mysql` CLI returning it once and never again is still unexplained.

So the honest state: **§4 collisions are real and cost an hour today, AND they do
not explain 1698.** If you see it, re-run before reporting; if it reproduces on a
quiet tree, capture `SHOW PROCESSLIST` and the server error log at that moment -
that is the evidence nobody has yet.

## 11 · Jose is away (2026-09-15 evening, again 2026-09-16) — decide and finish

*Note 2026-11-13: the standing version of this is now in `CLAUDE.md` ("Do it
yourself — never park work on Jose", his words of 2026-11-06), which is the source
of truth. The "deployment lead session" named below is EzBizi - Deployment Lead,
`local_072bee23…` (live 2026-11-13); see `docs/SESSIONS.md`.*

His words, standing: *"coordinate everything with each other and the
deployment lead session, make sure to finish END TO END since i wont be here
and if u have a question do as you believe or recommend, dont wait for me.
Make sure everything goes with the Organization - Company - Franchise Schema
as well as with Automations and Roles-Permissions."*

And separately: *"let the sessions know if they need to do anything to do it
while im away, fix stuff, etc."*

And again on 2026-09-16 evening: *"let all the sessions know if they need to
deploy stuff or want to work on stuff to go ahead and do it, etc."* Same
standing word, said twice. It is not a one-night exception.

So: **a question addressed to Jose is not a reason to stop.** Decide it the way
you would recommend it, write down what you decided and why, and keep going. If
two sessions disagree, settle it between you; the deploy desk breaks ties. The
only things that genuinely wait for him are the ones nobody here can do:
signing in to a third-party account, paying for something, and answering a
question about what the business WANTS rather than how it should work.

**Releases keep running while he is away.** Hand the desk a SHA and a bullet;
do not hold work back on the theory that nobody is watching. Eleven cuts went
out today and each of them fixed something he had reported.

Three things this file already says that matter more than usual tonight,
because there is nobody to catch a bad one:

- Gate the tree you COMMIT, and run `flutter analyze && flutter test` **last**,
  after the final edit — a green run from before your last file is a claim
  about a tree that no longer exists.
- Say what your hand-over CARRIES, not what you meant it to carry. Read
  `git show --numstat <sha>` before describing a commit; if the line count does
  not match your sentence, the sentence is wrong.
- Verify on the thing that runs — the box, the served page, the route cache —
  not on a ref. `merged into main` and `live` are different facts, and today
  one of them was reported as the other while a Google reviewer had directions
  to the screen it affected.

## 12 · An auth bug on our side now blanks the chat on 110 customer sites

*Re-measured 2026-11-13: still holds, and wider — `web_sites` reads 137 sites, all
on plugin 1.26.30, and the plugin still records a refusal at the first 401/403
(`wordpress-plugin/ezbizi/ezbizi.php`). The 110 and baseline figures below are
from 2026-09-16.*

Plugin 1.9.0, out on all 110 installs since 2026-09-16, removes the widgets at
the FIRST 401 or 403 from EzBizi. Jose's ruling, with the chat live and real
messages arriving: it *"should only show up, if a connection has been connected
properly."* Correct for a revoked token, and it means the blast radius of an
authentication bug in the plugin-token path changed shape overnight:

**Every customer chat box and contact form disappears within five minutes of
the deploy, on all 110 sites at once, silently, with the explanation in a
wp-admin notice nobody reads.**

Recovery is automatic — the next 200 clears the refusal and the widgets come
straight back with no intervention — and only 401/403 count, so a timeout or a
500 does nothing. An ordinary outage is not this.

**After deploying anything that touches plugin-token validation, run this on
the box. It needs no credential and makes no outbound call:**

    php artisan ezbizi:web-estate        # 0 = the estate is being answered
                                         # 1 = names how many have gone quiet

It reads `last_seen_at`, which is written only AFTER a token resolves, so a
site being turned away simply stops arriving — the estate's health was already
in our own table. Baseline when it was written: 111 registered, 93 seen in five
minutes, 110 in fifteen, 1 never seen.

The earlier version of this section said the desk could not check this, and
named a curl that carries a site token. That was true and is not any more.
Worth remembering WHY it was true: `web_sites.token_hash` is a hash, so the
plaintext cannot be minted here, and an unauthenticated call to that endpoint
returns the same 401 the failure produces — a probe indistinguishable from the
thing it is meant to detect. If you find yourself writing a check, ask what it
prints when the system is broken, not when it is healthy.

No grace period was added back to soften this, deliberately. A grace period is
exactly what Jose just removed, and trading his rule for protection against a
bug nobody has written is the wrong way round.

## 13 · The Mac relay moves. Find it, do not wait for it

**Lapsed 2026-10-01: `~/.ssh/config` `Host mac` now points at the Tailscale
address `100.94.42.48` (`mac-mini` in `tailscale status`), which does not move with
DHCP** (checked 2026-11-13). The LAN addresses and ARP hunt below are history; if
`ssh mac` fails now, check Tailscale first. The `--only` split and the orphaned
release-worktree trap still apply.

2026-09-16, second time: `ssh mac` failed with *"cannot reach the Mac relay —
is the Mac mini awake?"* and the Mac was awake the whole time. It had taken a
new DHCP address. `~/.ssh/config` now points at **10.1.10.187**; it was
10.1.10.116, and before that the `.local` name that stopped resolving on
2026-09-09. Each move has cost a release its Apple half.

**Do not ask whether it is awake. Find it.** Three commands, about a minute:

    arp -a | grep 10.1.10.                     # every host the LAN has seen
    # then, for each, does it answer on 22:
    Test-NetConnection -ComputerName <ip> -Port 22 -InformationLevel Quiet
    ssh -i ~/.ssh/a1tools_mac joseluisdemasi@<ip> 'scutil --get LocalHostName'

The last one is what identifies it — `Joses-Mac-mini` — rather than guessing
from an adjacent address. Two other hosts on this LAN answer on 22 and neither
is the Mac, so port 22 alone is not the test.

**Then check the thing release.sh checks**, because reachable is not usable:

    ssh mac "security find-identity -v -p codesigning | grep -c 'Apple Distribution.*2CMZQR34RL'"

**And do not stop the whole cut for it.** `--only web,windows,android` ships
the three that do not need a Mac; `--only ios,macos` builds the other two
against the SAME pinned SHA afterwards. One version, two passes, nothing lost.

**One trap in that second pass.** A completed release removes its pinned
worktree from git but can leave the DIRECTORY behind. The Apple pass then finds
`/h/Development/wt/release-<sha>` present, cannot read a SHA from it, and
refuses with *"existing release worktree has a different SHA"* — which reads
like a version mismatch and is really an orphaned folder. `git worktree list`
will not show it. Check the artefacts are already in `Distribution/`, then
remove the directory.

## 14 · EzBizi cannot update the plugin on customer sites, and should not gain the power quietly

Re-measured **2026-11-13**: `plugin_version` 1.26.30 on all 137 `web_sites`
rows; the repo plugin is 1.26.30; wordpress.org still publishes **1.10.0**
(api.wordpress.org, last updated 2026-09-17); the plugin still sets no
`auto_update_plugin` filter, so the OPEN question at the end of this section is
still open.

Measured on the box, **2026-09-22**:

    plugin_version 1.12.0   110 sites, 64 seen in the last 15 minutes
    never read                1 site

On 2026-09-17 it read 1.9.1 on 110 and 1.10.0 on zero; on the 18th, 1.11.0 on
110 and then 1.12.0 the same afternoon. Three releases in two days.

**The headline figure above was 1.11.0 until 2026-09-22 while the body of this
very section said the estate had gone to 1.12.0 that same afternoon.** A
section can contradict itself and stay believable, because a reader takes the
number from the top and the reasoning from the bottom. Re-measure the headline
when you add to the body.

The estate moves because we HOST these sites; the capability argument below is
about a customer's own server, and is unchanged.

**WRONG FOR THREE HOURS ON 2026-09-18, AND THE WRONG VERSION IS KEPT HERE
BECAUSE THE MISTAKE IS THE USEFUL PART.** This section said a session could not
reach the estate, on this measurement:

    ssh websites                     reached, as `ubuntu` on panel.triplexhost.net
    ls -d /var/www/vhosts/*/         46 vhosts, NAMES readable
    ls /var/www/vhosts/<domain>/     Permission denied

Every line is true and the conclusion drawn from it was false. **`ubuntu` has
passwordless sudo and `plesk` is on its PATH.** The probe simply never tried
`sudo`, and `Permission denied` reads like an answer about authority when it is
only an answer about the command as typed. Jose corrected it in four words.

    sudo -n true                     YES
    sudo ls /var/www/vhosts/<d>/     the site
    sudo find … -name ezbizi.php     110 installs

The estate went 1.11.0 → 1.12.0 the same afternoon with
`scripts/plugin-rollout.sh`. **Before concluding that access is missing, run the
privileged form of the command.** A capability this repo already had sat
recorded as an impossibility, and a todo waited on Jose for something he had
already given.

**What is actually true, and still worth defending:** EzBizi's WORDPRESS
credentials carry no `update_plugins` (the capability list below is unchanged),
so nothing the PRODUCT does at runtime can install code on a customer's site.
That is the design property. It is not a statement about what an operator with
a shell on our own hosting can do, and the two had been collapsed into one.

**AND THE `sg-*` SSH ALIASES ARE NOT THE ESTATE — a loop over them ships to
nobody and reports success.** There are 42 of them in `~/.ssh/config`, they
reach SiteGround accounts holding a full WordPress tree per domain, and they are
the obvious thing a session reaches for. They are stale:

    sg-a-1chimney:/home/customer/www/a-1chimney.com/public_html
      wp-content/plugins/ezbizi/ezbizi.php   Version 1.0.1, mtime 2026-08-31

    the same host, as EzBizi hears it
      a-1chimney.com                         1.11.0, seen 2026-09-18 16:47

One file says 1.0.1 and has not been touched since August; the live site
reported 1.11.0 nine minutes before the file was read. Both are real — they are
**different servers**. `a-1chimney.com` resolves to 32.194.222.151, the Plesk
box; the SiteGround copies are pre-migration leftovers that serve nothing, and
some read 1.10.0 while others read 1.0.2, which is what a dead estate drifting
looks like.

So a rollout written as `for h in sg-*; do scp ...; done` would exit 0 across 42
hosts, change 42 copies nobody loads, and leave `plugin_version` reading the old
release everywhere — with the deploy log saying it worked. **`web_sites`
`plugin_version` is the only honest check, and it is a check of the LIVE site
rather than of anything we pushed.** §14's own rule applies to its own rollout:
which half is live is a measurement, not an inference.

**The estate is `/var/www/vhosts/<domain>/{httpdocs,<subdomain>}/` on the Plesk
box** — 46 vhosts holding 110 WordPress installs, because most domains carry
their state subdomains as directories inside one vhost.

**Two things that will damage a site during a rollout, both handled in
`scripts/plugin-rollout.sh` and both silent for weeks if you get them wrong:**

*Ownership is per subscription.* Each install belongs to that customer's FTP
user with group `psacln` — `a1c_ftp`, `cstar_ftp`, `roof_ftp`. Copy as root and
PHP still serves the site perfectly, so nothing looks wrong; the failure
arrives the next time anybody edits through Plesk, FTP or the File Manager and
gets a permission error nobody can place. Read the owner off the directory
before the copy and restore it after, per install.

*A backup must not live inside `wp-content/plugins/`.* WordPress reads every
directory in there as a plugin, so `ezbizi.bak/` shows up in the customer's
plugin list as a second EzBizi — and somebody will deactivate one of them.
Backups go to `/var/backups/ezbizi-plugin/<stamp>/`, one tarball per install,
and `--rollback` puts them back.

**And `ssh` inside a `while read` loop eats the loop's own list.** The first
`--all` run applied to exactly one site and exited 0 having printed one line.
`ssh -n`. The reason this is worth a paragraph is the direction it fails in: a
partial rollout reports success, and the estate quietly stays on the old
release while the log says otherwise.

    service_capabilities, identical on all 110:
      read, upload_files, edit_posts, edit_published_posts, publish_posts,
      edit_pages, edit_published_pages, publish_pages

No `update_plugins`, no `activate_plugins`, no `install_plugins`. The service
credentials EzBizi holds can write CONTENT on a customer site and nothing else.
So "roll the plugin out to the estate" is not a command anybody here can run,
and the absence is a design property rather than a gap to fill: an integration
that can install code on 110 businesses' websites is a different and much
larger thing than one that can edit their pages.

**1.10.0** is what wordpress.org publishes (slug `ezbizi`, added 2026-08-31, 100
active installs, asked of api.wordpress.org on 2026-09-18). The repo is at
1.11.0, so **the directory is one release behind us** and anybody installing
from it gets no chat working hours. The plugin sets no `auto_update_plugin`
filter, so each site sees a new version at its own next check and installs it
only if that site has auto-updates on; the rest need somebody to press Update.

**THE HALF-SHIPPED STATE THAT FOLLOWS.** The two halves of a feature ship
through two different channels at two different speeds. 6bd90848 put City link,
State link and Website address into EzBizi and went live in 0.0.0237 within the
hour; 1.10.0 is what RENDERS them, and on 2026-09-17 it was on zero sites — the
fields were fillable and did nothing anywhere. The next day the estate was on
1.11.0 and both of those, plus the chat hours, render. **The lesson survives the
example:** which half is live is a measurement, not an inference, and the two
halves can sit apart for a day or for a month depending on who owns the server.

Before saying a web feature is live, ask which half you measured. `web_sites`
answers it directly:

    SELECT plugin_version, COUNT(*) FROM web_sites GROUP BY plugin_version;

**OPEN, FOR JOSE.** Whether the plugin should opt into WordPress auto-updates.
That would close the gap permanently and it means EzBizi code installing itself
on 110 customer sites unattended — a decision about other businesses' property,
not a deployment call, so it waits for him even under §11. Raised 2026-09-17.

## 15 · `merge-tree --write-tree` writes a tree WHEN IT CONFLICTS

The desk's plumbing merge is:

    T=$(git merge-tree --write-tree main $SHA | head -1)
    M=$(git commit-tree $T -p main -p $SHA -F msg)
    git update-ref refs/heads/main $M

On 2026-09-17 it was guarded like this, and the guard is worthless:

    if git cat-file -t "$T" | grep -q tree; then echo "clean merge"

**merge-tree writes a tree whether or not it conflicted.** It resolves what it
can, writes `<<<<<<< main` / `=======` / `>>>>>>> branch` INTO the blob, and
returns a perfectly valid tree object. The conflict is reported through its EXIT
STATUS and through lines printed after the tree — both discarded by `| head -1`
and by a type check. So a conflicted merge of `widgets_tab.dart` was committed
to main and announced as clean, and the client did not compile for half an hour.

Nothing looked wrong. `git diff --name-only main $T` was entirely normal,
because the file legitimately changed. Nothing is red until somebody compiles,
and `flutter analyze` in the shared tree was being run by peers against their
own uncommitted copies, which had no markers.

**The check that works, both halves:**

    OUT=$(git merge-tree --write-tree main "$SHA"); RC=$?
    [ "$RC" -eq 0 ] || { echo "CONFLICT"; exit 1; }
    T=$(echo "$OUT" | head -1)
    git grep -n -E "^(<<<<<<< |=======$|>>>>>>> )" "$T" && exit 1

Grep the tree as well as trusting the status: once markers are committed they
are ordinary content, every later merge merges happily around them, and
merge-tree exits 0 forever after. Sweep the whole of `main` after any plumbing
merge — not the files you expected to change:

    git grep -n -E "^(<<<<<<< |>>>>>>> )" main

**Expect a conflict wherever a peer's branch and main have both edited one
widget.** One session per module does not prevent it: the sweep that replaced
every popup dropdown touched forty files owned by a dozen sessions, so any
branch cut before it collides with main inside somebody else's file.

## 16 · Match the construct, never the bare name

Three sessions arrived at this independently on 2026-09-17, which is why it is
here rather than in three docblocks.

When you check whether something is PRESENT or GONE in source, match the syntax
that makes it so — the assignment, the call, the `Route::middleware(`, the
`=>`. A bare name matches prose, and prose about a thing is evidence somebody
thought about it, never evidence of its state.

    grep -c "organization_total"          3   <- what a correct file looks like
                                               AND what a reverted one looks like
    grep -c "'organization_total' =>"     0   <- the field is actually gone

The three occurrences were comments explaining that the field had been removed.
The count could not tell the two states apart, and it was quoted as confirmation
that a peer's work had survived a revert. The answer happened to be right; the
instrument could not have said otherwise.

The same shape runs the other way. Reading a stale diff of `routes/api.php`, the
desk named three of Inventory's route gates as deleted — and one was a COMMENT
saying that route is deliberately NOT gated. **A deliberate-absence note reads to
a text scan exactly like the thing it says is absent.**

`client/test/no_popup_dropdown_test.dart` had already solved it and says why in
its own header: it matches `DropdownButtonFormField<` or `DropdownButtonFormField(`
and never the bare name, because the name appears in `search_picker.dart`'s
explanation of what it replaced — a guard that read the word would go red on the
prose describing its own subject.

**The general form, and it is uncomfortable:** the better the comment, the worse
the text scan. The places most carefully explained are exactly where a
name-match is least trustworthy, so writing down WHY something was removed is
both the behaviour we want and the thing that breaks the next person's grep.

Verifying a file survived a revert has a stronger instrument than any grep:

    git rev-parse <their-sha>:<path>      compare the BLOB shas
    git rev-parse HEAD:<path>

which a diff of a file somebody has been editing all day cannot accidentally
satisfy.
