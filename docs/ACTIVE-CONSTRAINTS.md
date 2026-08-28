# Active constraints — read before you write code

**Temporary. Delete this file when App Store review clears.** Opened 2026-08-28.

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
- **A new automation action must start with `task.`.** `automation_builder.dart:142`
  handles a `task.`-prefixed action generically; `:167` throws `StateError` on
  anything else. The catalogue is tolerant, so a newly registered action **is offered
  in the picker**, is selectable, and then crashes on save. New *triggers* are safe —
  `CatalogueTrigger.tryFrom` is tolerant, verified twice.
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

One module went 107 errors → 112/112 on this alone.

---

Committing safely on this shared tree is **not** temporary and lives in
`StartUp.md` §4. Who owns which files is in `docs/MODULE-OWNERS.md`.
