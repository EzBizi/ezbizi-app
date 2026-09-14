# Active constraints — read before you write code

**REVIEW CLEARED 2026-08-31, confirmed by Jose. The freeze is over.** Opened
2026-08-28.

**This file is NOT being deleted, and the original instruction to delete it was
wrong.** §1 was temporary and is now history. §2, §3 and §5 are not — a minimum
client version is still dangerous, SES is still sandboxed, and §4's rule now
lives in a test rather than in prose. A file whose header schedules its own
deletion takes the durable content with it, which was caught once already when
the CRLF section had to be moved to `StartUp.md` §8 to survive.

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
  `GlanceRegistry`, `WidgetRegistry`, `SettingRegistry`, `PermissionCatalogue` and the
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
  key with a default fallback — two-thirds right. `dashboard_panels.dart:73-77` has
  **three arms**, and the middle one is a distinct second fallback:*

      byModule[key]                      // exact glance key
          ?? byModule[key.split('.').first]  // MODULE PREFIX — 13 icons registered here
          ?? Icons.insights_outlined         // default

  *So `training.progress` finds a `training` icon if one exists, and only then falls
  through. Two fallbacks, not one. Recorded at this length because a mechanism that is
  two-thirds right decays into a wrong one the moment somebody reasons from it — which
  is exactly how the first error got made.*

  **And declaring a thing is often only half of it.** `AutomationsController:50`
  holds a private `IMPLEMENTED_ACTIONS`; an action absent from it is published as
  `implemented: false` at `:217` and refused at `:918`, so it is drawn as "not built
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

Production currently has **no minimum set**. Nobody owns that controller; it is
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
"users already exists". `phpunit.xml:27` has no `force="true"`, so a real env var
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
only because `phpunit.xml:26` pins `DB_CONNECTION=mysql`. Everywhere else `.env`
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

## 6 · Configuring a sending identity ARMS a shipped action

**Before you configure a sending identity for any organisation, settle
`inspection_report.email` first.** They are linked, and nothing in the code says so.

Jose ruled twice on 2026-09-14, carried by two sessions independently. Via
Automations: *"no ezbizi SES is to be used only for EZBIZI Related actions like
notificaitons, account creation, password, resets, etc at least for now."* Via HR:
*"1 - yes, as they recommend."* Asked the sharper form afterwards - whether a
CONFIGURED tenant sending tenant-authored content to a member of the public over
EzBizi's SES is covered - he answered **yes**.

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
