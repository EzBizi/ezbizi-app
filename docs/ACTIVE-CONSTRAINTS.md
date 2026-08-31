# Active constraints — read before you write code

**Temporary. Delete this file when App Store review clears.** Opened 2026-08-28.

**Do not delete it while App Store Connect still reports *Waiting for Review*.**
It is the only durable record of the freeze; everything else about it lives in
sessions that do not survive the afternoon. Check the status before removing it,
and if you cannot check, leave it.

It exists because these constraints were being broadcast to a session roster that
turns over every few minutes, so half the fleet never heard them and the other half
heard a version that had already gone stale. A message reaches whoever is listening;
a file reaches whoever arrives next.

---

## 1 · App Store review is live

Apple's reviewer runs the **submitted binary, frozen at `v0.0.0112`**, against
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
  `knownTypes` explicitly — by key, including the six `task.*` ones, not by prefix.
  `:527` returns any key outside that set as `enabled: false` with the badge "Needs a
  newer version of the app", and `:638` returns null for it, so the `StateError` at
  `:167` is unreachable. The docblock says so in as many words: *"an unknown type is
  never offered and the throw above is unreachable rather than merely unlikely."*
  New **triggers** render as their own raw key — `automation_catalogue.dart:118`,
  `CatalogueTrigger.tryFrom` falls back to `label: json['label'] ?? key`.

  *An earlier version of this file claimed a non-`task.` action would be offered and
  then crash on save. That was wrong in both directions: nothing crashes, and a new
  `task.`-prefixed action is no safer than any other, because the set is enumerated
  rather than prefixed. It was corrected by checking the frozen tree instead of
  reasoning about it — which is what the paragraph above tells you to do.*
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

## 3 · SES is in the sandbox — every recipient must be verified first

**Delete this section when production access is granted.** Appeal case
`178717006200996`, denied once already for zero sending history.

SES delivers **only** to hand-verified identities and rejects everything else with
**554**. Every rejection lands on the Rejects metric AWS reads while judging the
pending appeal — so a careless send does not merely fail, it argues against us.

**Any new mail recipient — an alert address, a notification target, a test send —
must be a verified identity first.**

This has already bitten once: the backup alert pointed at a personal Gmail, which
was harmless while nothing could send and became a live Reject the hour outbound
mail started working. It also blocks TODO #22 signup verification, which would
return 200 on our side and put nothing in a real customer's inbox — the failure is
invisible from here, which is the shape everything else in this file shares.

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
tenant boundary — the red lands in `TenantIsolation`, `TimeClock`, `Tasks` and
`Web Management` at once, which reads as a fleet-wide security failure rather
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
`StartUp.md` §4. Who owns which files is in `docs/MODULE-OWNERS.md`.

---

## 5 · A green suite does not prove the sandbox matches production

Until 2026-08-31 the deploy gate shipped its sandbox copy with `git archive`,
which runs on the Windows machine, where `core.autocrlf=true` — and applies it.
**1791 of 2131 files differed from what production receives**, 430 KB of pure CR,
on every deploy the gate had ever run. Production is fed by `git push` and checks
out on the box, in LF.

Fixed in `b0a4c4e`. The lesson outlived the bug: 2500 tests ran green for months
against a tree production never receives, because **PHP does not care about line
endings**. The suite was not confirming the trees matched; it was demonstrating
that almost nothing in it can tell.

**On Windows this still bites you locally.** Your checkout has CRLF on disk, so
any assertion anchored with `$` fails on a file that is correct in git:

```
/^Stable tag:\s*(\S+)$/m   against "Stable tag: 1.0.2
"  ->  0
                            against "Stable tag: 1.0.2
"    ->  1
```

**Swept, and the population is small.** Three `$`-anchored multiline regexes
exist in `tests/` in total. Two were in `WebManagementTest` — the plugin's
`Stable tag` and `Version` headers — and Web Management fixed both in `7149803`
with `[^
]+`, which cannot match a CR rather than merely tolerating one. The
third, `ClientModuleScreensTest.php:54`, anchors at `^` only with no trailing
`$`, so nothing can get between the pattern and the anchor. Nothing else in the
suite has the shape.

**If you write a new one, this is the trap.** `grep "Stable tag" file | od -c`
shows the line ending in a bare `
` with no CR, because grep strips it — which
is how somebody talks themselves out of a correct diagnosis. The only reading to
trust is `file_get_contents` plus the actual pattern.
