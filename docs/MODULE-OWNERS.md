# Who owns what

**To reach a role, use `docs/SESSIONS.md`.** It carries the desktop session id
per role — the only identifier that cannot land on the wrong session — and the
registration each session does at start. This file stays about what is owned.

This exists because the roster kept living in one session's context, and a session
is the one place in this project that provably does not survive. A coordinating
session restarted three times in one afternoon and lost it each time; one of those
losses invented a departed session that had never existed, and nearly had the owner
adjudicate orphaned work that had owners. Every other canonical fact here is a file.
This one is too now.

**The session column is volatile and will be wrong within the hour.** Names are
reassigned mid-conversation, several sessions have shared a name at once, and one
session has appeared under two names. Treat it as a hint about who to ask, never as
a fact. The durable columns are the module and its files.

**So prefer SHAs in that column where they exist.** A SHA is checkable by anyone at
any time; a name is checkable by nobody. Where a row lists commits instead of a
session, that is the stronger form, not a gap.

**Never write a row from a relay. Ask the session to confirm it against its own
commits** — `git show <their SHAs> --name-only` — before you record it. An
assignment arriving as settled fact, with authority behind it, is exactly what gets
accepted without checking: one session nearly accepted ownership of every
third-party integration this way and refuted it in one command, finding not a
single matching file across four of their commits.

**A blank is better than a wrong name.** An honest blank routes a Meta outage to
nobody, which is recoverable. A wrong name routes it to somebody who is not
watching, which is not. The integrations row is blank on purpose: two distinct
message pipes are both presenting as `ezbizi-3d`, and the real owner is not yet
known.

**ONE SESSION CAN APPEAR AS TWO SENDERS, AND THE RECIPIENT CANNOT TELL.**

There are two ways to message a session on this machine and they show different
identities for the same sender:

    SendMessage                      shows a ROTATING pipe name (ezbizi-5b,
                                     ezbizi-28, ezbizi-a1 — same session, hours apart)
    ccd_session_mgmt send_message    shows the STABLE session title

On 2026-08-31 the deploy desk swept every session using both, within minutes. A
session that received both concluded **two deploy desks were running**, warned
that they would either collide or each assume the other had deployed, and could
not reach the "other" one to check. It was one session. `get_session("self")`
settled it in one call.

The warning was reasonable and nothing the recipient could have checked would
have shown otherwise. **Neither message carried anything identifying it as the
same sender.**

So: the SESSION ID is the only durable identity. Pipe names are per-hour handles;
titles are stable but not unique across transports; and "who is this" is
answerable only by asking, or by a session id. **Sign messages with a session id
when it matters, and use one transport per correspondent per conversation.**

**BUT A SESSION ID IS NOT A ROLE ADDRESS, AND THREE SESSIONS HAVE NOW LOST TIME
TO THAT.** It is durable in exactly one sense — it can never be reassigned, so a
message sent to one either reaches that session or reaches nobody, and it can
never reach the wrong session the way a recycled name can. That is a real
guarantee and it is the reason to prefer it.

What it does NOT survive is the session ending. On 2026-08-31 the deploy desk
gave out its session id as "the only handle of mine that does not rotate", it
was passed on in good faith as the way to reach *deploys*, and within the hour it
resolved to nothing — because the session behind it had ended. The handle was
still correct; the role behind it was gone.

So the honest sentence is **"this is how you reach that SESSION while it lives"**,
never "this is the deploy desk". There is no role address on this machine. When
the session behind a role dies, the role has no address at all, and the only
things that still work are the durable artefacts: what is on `main`, what is in
this file, and what is written in a doc somebody will read.

That is also why a handoff should name a FILE rather than a correspondent. A
session id tells you who to ask until they exit; a path in this file tells you
who owns something after they do.

**SESSION NAMES ARE RECYCLED, NOT JUST RETIRED — and that is why this file
records SHAs.**

Measured 2026-08-31: one session was renamed three times in a day
(`ezbizi-c8` → `ezbizi-bc` → `ezbizi-17`), and by the afternoon **a different
session held `ezbizi-c8`**, started an hour later.

So the failure mode is not "a stale name reaches nobody", which is loud and
recoverable. It is **"a stale name reaches somebody else"** — silent, and it
looks like it worked. A competent session asked about a module it has never
touched will try to help, and you get a plausible answer to a question it was
never in a position to answer.

That is the whole argument for the two rules below. A SHA cannot be reassigned.
A blank routes to nobody. **A wrong name now routes to somebody who will
answer.**

When you must reach a session: ask it to identify itself and wait, rather than
inferring from a roster. `ListAgents` shows a `[ref]` beside each name — that ref
is stable for the life of a session where the name is not.

**Do not reconstruct this file from `git log --name-only`.** Web Management's
history contains Phone and Services files — `AddressesController`,
`NumbersController`, `TwilioGateway`, `ServicesController`,
`ServiceEventAtomicityTest`. That is not authorship; it is collateral from a stale
tree reverting them, plus the repairs. Deriving ownership from touched files would
misassign three modules at once.

**When attribution matters, cite the commit SHA.** Every commit in this repo carries
the same `%an` — one machine, one git config — so authorship cannot separate
sessions either. The SHA is the only handle that survives a rename.

| Module | Files | Session, when last seen |
|---|---|---|
| **Customers** | `Api/CustomersController.php` · `Models/Customer.php` · `Policies/CustomerPolicy.php` · `Dashboard/Glances/CustomersGlance.php` · `Middleware/RefuseStaffAuthOnCustomerHost.php` · `screens/customers_screen.dart` · `screens/customers/` · `test/customers_screen_test.dart` · `factories/CustomerFactory.php` | `ezbizi-0a` |
| **Jobs** | `Models/Job.php` · `Api/JobsController.php` · `Policies/JobPolicy.php` · `factories/JobFactory.php` · `Dashboard/Glances/JobsGlance.php` · `Dashboard/Widgets/Revenue.php` · `screens/jobs_screen.dart` · `screens/jobs/` · `core/job_status.dart` | `ezbizi-85` |
| **Project Management - 2** — Project Management rebuilt from scratch beside the first (Jose, 2026-10-26) | `Api/ProjectManagement2Controller.php` · `screens/project_management_2_screen.dart` · `tests/Feature/Api/ProjectManagement2Test.php` · in-region only: the `project_management_2` blocks of ModuleServiceProvider, GlanceRegistry, module_screens.dart, dashboard_panels.dart and routes | `local_3719b5ba-7915-455a-a254-f27ef0fe0a85` (EzBizi - Project Management) |
| **Phone** | `Api/Phone/` · `Support/Phone/` (incl. `PhoneLineHealth`, `TwimlRenderer`) · `Models/PhoneNumber.php` · `screens/phone/` · `test/phone_*.dart` · `tests/Feature/Phone/` | `ezbizi-0b` |
| **Web Management** | `Api/Web/` · `Models/WebSite.php` · `Models/WebValue.php` · `Support/Web/` · `Policies/WebSitePolicy.php` · `Dashboard/Glances/WebManagementGlance.php` · `screens/web/` · `wordpress-plugin/ezbizi/` · `tests/Feature/Web/` | `358b3db` `e1f4d9a` `4b1a087` `5ce9285` |
| **Third-party integrations** — OAuth drivers, Meta, `config/services.php` provider blocks, the 2026-10-26 Meta cutoff | — | **deliberately blank, see below** |
| **Workiz** — the Workiz v1 integration for A-1's move off A1Tools: the connection, the credential, the readers and the mirrors | `Api/WorkizConnectController.php` · `Support/Workiz/` · `Support/Reports/Experimental/WorkizJobs.php` · `Models/WorkizJob.php` · `Models/WorkizTeamMember.php` · `Console/Commands/SyncWorkiz{Jobs,Team}.php` · the `workiz_jobs` and `workiz_team_members` migrations · `tests/Feature/Workiz/` · `tests/Fixtures/workiz/` · `tests/Feature/Reports/WorkizConnectTest.php` · in-region only: the `Workiz` arms of `SocialProvider` and the two `workiz` routes | `local_ea9c0484-3b23-40b6-8262-10a1fccc1a3a` (EzBizi - Workiz API) |
| **Field** — GPS tracking of the roles granted `field.tracked`, safety alerts (SOS, stopped, lost signal), arrival at jobs, suggested routes | `Api/FieldController.php` · `Support/Field/` · `Models/Field{Location,Presence,Alert,Geocode}.php` · `Console/Commands/FieldSweep.php` · `Dashboard/Glances/FieldGlance.php` · the `2026_09_25_500000_create_field_tables` migration · `tests/Feature/Field/` · `client/lib/screens/field_screen.dart` · `client/lib/screens/field/` · `client/lib/core/field_tracker.dart` · `client/test/field_screen_test.dart` · in-region only: the `field` blocks of ModuleServiceProvider, SettingRegistry, GlanceRegistry, module_screens.dart, dashboard_panels.dart, routes, the location keys of AndroidManifest.xml and Info.plist | `local_b832295d-a4fe-4933-89e7-27ee2803646c` (Field module) |

> **NOT the Workiz session's, and each was somebody else's ruling rather than a
> boundary they drew for themselves.** The Experimental call report and its
> tests are Reports', including the three `workiz`-named tests inside
> `ExperimentalCallReportApiTest` — those assert what the REPORT does, and one
> of them is the only coverage anywhere of `WorkizJobs`' paging loop
> (annotated in place at `ec8ebe37`). `config/experimental_reports.php` is
> Reports'. Workiz LEADS live in the `leads` table as a source and everything
> inside that module — `Lead::SOURCE_WORKIZ`, `leads.workiz.view`, the reply
> channel, the Unreplied rule — is the Google API session's; only
> `Support/Workiz/WorkizLead*.php`, the reader, is here. The projection into
> `customers` and `jobs` belongs to those modules, not to this one.
>
> **Regression command is two whole files, not `--filter=Workiz`:**
> `tests/Feature/Reports/WorkizConnectTest.php` and
> `ExperimentalCallReportApiTest.php`. The name filter misses
> `test_a_branch_with_a_full_page_is_asked_for_the_next_one`, which is the only
> test of the paging loop.

| **Android · Play** | `client/android/` · the android path of `scripts/build-app.sh` · `build-web.sh` · `release.sh` · `windows-installer.iss` · `assets/logo` · `assets/installer` · `client/integration_test/store_screenshots_test.dart` · `client/test_driver/screenshots.dart` · the **Play** Console | `ezbizi-16` |
| **iOS · Apple** | `client/ios/` · the ios path of `scripts/build-app.sh` · the Mac relay · App Store Connect, TestFlight, the Apple Developer consoles | `local_ac2289f8-a1a6-4454-962e-21adf9706bca` (EzBizi - Apple Module) |
| **Website** | `www/` — **every page under it**, plus `robots.txt`, `sitemap.xml`, `check-site.py`, and the `www/favicon.ico` size list in `scripts/make-brand-assets.py`. Public copy, page structure, meta and structured data. *Not* `scripts/deploy-www.sh` or `/var/www/www` — publishing is the deploy session's. | `3b5b79f` `57b4502` `436fd6f` |

> **iOS is OWNED as of 2026-09-04** by the Apple session above. This note read
> "unowned" until 2026-09-16 - the second time this row went stale in exactly the
> way it warns about: the fact moved and the file did not. What follows about
> what the owner inherits still holds.
>
> **Since then:** iOS and macOS go up every cut, current at 0.0.0227. EU trader
> status is RESOLVED and no longer blocks submission; the Mac App Store review
> came back approved. Push Notifications is enabled on `com.ezbizi.app`, and
> `client/ios/Runner/Runner.entitlements` carries `aps-environment = production`
> on the **Release configuration only** - Debug and Profile claim nothing,
> because no profile grants it there and a binary claiming an entitlement its
> profile lacks is a hard signing failure, not a warning.
> What the next owner inherits: `./scripts/build-app.sh ios --upload` ships a
> build, signing and validation already wired; signing material lives on the Mac
> relay and in `H:/Development/Credentials/`, which is **not** in git and must never
> be (the owner holds a physical USB backup, confirmed 2026-08-31); the App Store
> Connect `.p8` **downloads exactly once from Apple**; and **EU trader status needs
> the Account Holder and blocks submission outright** — that one is unchanged by the
> review clearing. Fuller detail is on the board under `ios-build-and-testflight`.
>
> **These two were split from one session and neither could see the other.** Each
> reported the other's half as orphaned within minutes — and at that moment both
> were in fact owned. This row was `ezbizi-71` until that session ended, which is
> the failure this file warns about: a name that has stopped being true reads
> exactly like one that is. `scripts/build-app.sh` and `release.sh` cover every
> platform in one file, so both sessions had lines in them — neither owns it
> outright.
>
> **All three of these bump `client/VERSION`.** `build.gradle.kts` derives Android's
> `versionCode` from it (`0.0.0112` → `112`) and `build-app.sh` derives Apple's
> `CFBundleVersion` from the same file, on top of the web deploy train in
> `StartUp.md` §9. So an Apple release bumps a number Android's next upload then
> inherits without choosing it — harmless while Play only needs it to rise, and
> confusing at exactly the wrong moment because it is invisible from either side.
| **Deploy** | `scripts/deploy.sh` · `scripts/sandbox.sh` · the production symlink and SSH path · the release rows in `EZBIZI-STATUS.md` | `ezbizi-a1` |
| **Mail — cross-cutting** | `app/Support/Mail/` (MailDispatcher, SendingIdentityResolver, MailTransport, PlatformIdentity, MailIdentity, SendingState, MailStatus, TenantSmtpHost) · `app/Models/SendingIdentity.php`. **Owns the transport and who a message is FROM, not what any module sends.** Added 2026-09-14 because this had no row and the gap bit twice in one day. **Reshaped later the same day by Jose's two rulings.** EzBizi's own transport now carries only EzBizi's own messages — signing in, password resets, joining — so `MailDispatcher::asTenant` throws unconditionally and the domain-verification flow is DELETED: no `SendingIdentitiesController`, no `DomainVerification`/`DnsVerifier`, no `mail/sending-identity` routes, no Settings card, no `mail.verification` config (`474fd1e6`, `b32fc797`). What replaces it is `throughTenantProvider` — the tenant's OWN SMTP, credentials on the existing row, host guarded by `TenantSmtpHost` against SSRF (`79b5e142`). **Nothing in `app/` writes a `SendingIdentity` any more**; the screen that will is not built. The `sending_identities` table is deliberately NOT dropped and holds 0 rows. | `ezbizi-ses` |
| **UI/UX — cross-cutting** | `client/lib/core/theme.dart` · `module_screens.dart` · `dashboard_panels.dart` · `ui_strings.dart` (all four verified present). **Owns the decision, not the module:** general layout, module screens, dashboard panels and glances, navigation, and any new or changed UI. Ping before touching the four files above. Process: ask → three HTML mockups → Jose picks one → the module session implements it (`StartUp.md` §6; worked examples in `docs/previews/`) | `ezbizi-ea` |

> **The carve-out, so a defect fix is not stuck behind a design round trip.**
> Changing a state's **prominence inside an existing component** stays with the
> module session — grey caption to red banner, adding a count, naming a state the
> screen previously left silent. Where that *is* the correctness fix, a design round
> trip sits on a defect's critical path and costs more than it buys: five production
> phone lines went to voicemail for months because that state was disclosed in grey
> secondary text. **New component, new section, new arrangement** goes to UI/UX.
> Condition: the module session tells UI/UX **after** shipping — not for approval, so
> the same state does not become a red banner in Phone and a grey pill in Fleet.
>
> **Design sign-off is not a deploy gate**, and should not become one on a peer's
> say-so. The deploy gate is tests, migrations and freeze safety — things it can
> verify. If Jose wants design enforced there he can say so and it gets built. Until
> then a process everybody hears about and nobody enforces produces the worst of both:
> sessions that comply are slowed and sessions that do not ship anyway.
>
> **Three things about those four files that bite.** `module_screens.dart` — the
> sidebar rail is the **intersection** of declared modules and client screens, so
> declaring a module does not put a tab in front of the frozen reviewer binary, which
> has no key for it. `GlanceRegistryTest:152` asserts sidebar modules and glance keys
> as a sorted-set **equality**, so declaring a module without adding a glance turns
> the gate red — Training hit this and had to add a `COMING_SOON` entry it had not
> planned. And `ui_strings.dart` has two migrations behind it, so some strings are
> tenant data and a copy change is not a one-line edit.
| **Fleet** | `Support/Fleet/` · `Api/Vehicle*Controller.php` · `Models/Vehicle*.php` · `Policies/VehiclePolicy.php` · `Dashboard/Glances/FleetGlance.php` · `Console/Commands/ImportA1ToolsVehicles.php` · `migrations/2026_08_28_170000_add_fleet_fields_carried_from_a1_tools.php` · `screens/fleet/` · `test/fleet_screen_test.dart` · `tests/Feature/Fleet/` | `ezbizi-e9` |
| **Inventory** | `Models/Inventory*.php` · `Policies/Inventory*Policy.php` · `Api/Inventory*Controller.php` · `Support/Inventory/` · `Console/Commands/InventorySeedDemo.php` · `screens/inventory_screen.dart` · `screens/inventory/` · `test/inventory_screen_test.dart` · `tests/Feature/Inventory/` · `migrations/2026_08_2{4_160000_create_inventory_tables,5_120000_create_inventory_sales_tables}.php` · `factories/Inventory{Item,Location,Vendor}Factory.php`. **Not Inventory despite the name:** `Support/Privacy/DataInventory.php` and `tests/Feature/Api/DataInventoryTest.php` are the §9 personal-data map — this has misled once already | `ezbizi-8f` |
| **Services** | `Models/{Service,ServiceGroup,ServiceLabel}.php` · `Api/{Services,ServiceTaxonomy,ServiceGroups,ServiceLabels}Controller.php` · `Policies/Service{,Group,Label}Policy.php` · `Dashboard/Glances/ServicesGlance.php` · `screens/services/` · `test/services_screen_test.dart` · `tests/Feature/Services/` | `ezbizi-d0` |
| **Training** | `Api/Training/` · `Models/Training*.php` · `Policies/Training{Guide,Test}Policy.php` · `Support/Training/` · `Console/Commands/ExpireTrainingPasses.php` · `migrations/2026_08_29_110000_create_training_tables.php` · `migrations/2026_09_04_090000_add_expiry_announced_at_to_training_attempts.php` · `screens/training_screen.dart` · `screens/training/` · `test/training_*.dart` · `tests/Feature/Training/`. **Not theirs, edited in one block each:** the Training block of `routes/api.php`, the `training` block of `Providers/ModuleServiceProvider.php`, the `training` rows in `Support/Dashboard/GlanceRegistry.php` and `core/dashboard_panels.dart`, the `training` entry in `core/module_screens.dart`, and `training.assign` in `AutomationsController::IMPLEMENTED_ACTIONS` and `ActionRunner` | `83bb514` `62037fb` `7358905` `21433cb` `f05d5c2` `5beb1c0` `b52d50e` `a75a993` `954e7a3` `0cd1feb3` `69aa9da5` `541278e8` `ddc3e69a` `8044f4be` `65da9292` |
| **Inspection** | `Api/Inspection*Controller.php` · `Models/Inspection*.php` · `Policies/Inspection*.php` · `Support/Inspection/` · `Support/Files/HardenedPdf.php` · `screens/inspection/` | — |
| **Marketing** | `Api/Social*Controller.php` · `Models/Social*.php` · `Jobs/PublishSocialTarget.php` · `Support/Social/` · `screens/marketing/` · `tests/Feature/Social/` | — |
| **EZ Leads** — `leads`, `reviews`, `business_profile` | `Models/{Lead,Review,ReviewReply,BusinessLocation}.php` · their policies and controllers · `Services/{Leads,Reviews,BusinessProfile}/` · `widgets/connect_account.dart` | — |
| **Email — inbound** | Shared mailboxes, IMAP, tenant OAuth. `Support/Email/` · `Api/Mailbox*Controller.php` · `Api/{GoogleMail,MicrosoftMail,GoogleWorkspace}AuthController.php` · `resources/views/mail-auth/page.blade.php` · `Models/{Mailbox*,MessageSeen,GoogleMailSignIn,MicrosoftMailSignIn}.php` · `screens/email/` · `test/email_*.dart` · `tests/Feature/{Email*,Imap*,MailboxWatch,GmailMailboxWatch,HtmlEmailSanitiser}Test.php` · `tests/Feature/Api/Mailbox*Test.php` | `ezbizi-22` |
| **Platform outbound mail (SES)** | **Not the same module as Email — this is the platform's own outgoing transport.** `Support/Mail/` · `resources/views/vendor/mail/` · `Notifications/{PasswordResetLink,InvitationLink}.php` · `Console/Commands/SendTestEmail.php` · the mail block of `config/mail.php`. Off-repo and also theirs: the `MAIL_*` and `EZBIZI_BACKUP_ALERT_TO` keys in the server `.env` (a release rollback does **not** revert these), `/usr/local/bin/ezbizi-mail-warmup.sh` and its root cron, the `_dmarc` record in the BIND zone, and the AWS SES/SNS/IAM consoles | **name dead — `ezbizi-77` did not survive the 2026-08-31 restart. The FILE LIST is the handle; whoever picks these up owns SES.** |
| **Automations** | `Support/Automations/` · `Api/AutomationsController.php` · `Jobs/DeliverAutomationEvent.php` · `Models/Automation{,Version,Event,Run}.php` · `Policies/AutomationPolicy.php` · `Console/Commands/DispatchAutomationEvents.php` · `Support/Modules/{Trigger,Action}Definition.php` · `Support/Modules/{AutomationDeclaration,AutomationSubject}.php` · `screens/{automations_screen,automation_builder}.dart` · `tests/Feature/ModuleRegistryTest.php` · `tests/Feature/Api/Automation*Test.php`. **Not theirs:** `Providers/ModuleServiceProvider.php` — every module writes its own block; shared, announce before editing | `ezbizi-b0` |
| **Settings** | `Api/SettingsController.php` · `Support/Settings/` · `Models/Setting.php` · `migrations/2026_08_05_000004_create_settings_table.php` · `screens/settings_screen.dart` · `screens/settings_shell.dart` · `screens/settings/` · `test/settings_screen_test.dart` · `tests/Feature/Setting*Test.php` · `tests/Feature/Api/Settings*Test.php`. **Not theirs, and this has misled:** a module's own settings pane (`phone_settings_pane_test.dart`, `email_settings_test.dart`, the phone `settings` migrations) belongs to that module. The `SettingRegistry` array is edited one block per module the way `ModuleServiceProvider` is. | `local_2d6b6362…`, 2026-09-09 |
| **Suggestions** | `Models/Suggestion.php` · `Api/SuggestionsController.php` · `Api/StaffSuggestionsController.php` · `migrations/2026_09_02_160000_create_suggestions_table.php` · `screens/suggestions_screen.dart` · `screens/staff/suggestions_queue_screen.dart` · `tests/Feature/SuggestionsTest.php` · `tests/Feature/Api/StaffSuggestionsTest.php`. **Deliberately not in `ModuleRegistry`** — see `docs/EZBIZI-BUILD-LOG.md` 2026-09-02 before "fixing" that | `f13dd81`..`b54b667` on branch `session/suggestions`, not yet on `main` |

## Files nobody owns

Name these rather than assuming a module covers them.

- **`Api/StaffClientVersionsController.php`**, `Middleware/EnforceMinimumClientVersion.php`, `Models/PlatformMinimumClientVersion.php` — control plane. A minimum client version is a **database row**: it needs no deploy, passes no gate, and 426s every client that falls below it. It has no module owner, so nobody is watching it.
- **The store consoles** are not code and appear in no file list, so an unowned one does not read as unowned — it just goes quiet. They carry real deadlines: Meta Tech Provider has a hard 2026-10-26 cutoff, and **EU trader status needs the Account Holder and blocks App Store submission outright**.
- **Signing material lives outside git and must stay there.**
  `H:/Development/Credentials/` holds the Apple signing artefacts and the Android
  upload key. The GitHub push does not cover it and must not: this repo is pushed to
  production, so committing any of it publishes it. The App Store Connect `.p8`
  **downloads exactly once from Apple**, and losing the Android upload key means a
  key-reset request to Google.
  **Confirmed 2026-08-31: the owner holds a physical USB backup**, so this is not the
  single point of failure an earlier version of this file called it. Recorded because
  the absence of a backup is unprovable from inside the repo — the next reader would
  otherwise re-derive the same alarm from the same evidence.

- **`client/windows/runner/` is split per file, not per directory.** Monitoring owns
  `monitoring_specs.cpp`, `monitoring_screenshot.cpp`, `monitoring_input.cpp` and
  `monitoring_screenshot.h` — confirmed by them against their own commits, 2026-09-14.
  **`win32_window.cpp` and `main.cpp` are deliberately owned by NOBODY.** They are the
  generic Flutter shell every module's native behaviour passes through, and Monitoring
  declined them on the reasoning that is worth keeping: making them Monitoring's would
  mean needing their permission to fix a tray icon. It decides things no Dart test can
  see — the single-instance mutex, close-to-tray instead of quit, and the tray icon the
  "your screen is being controlled" notice was drawn on. Until `06dc4c81` that icon was
  added only at `WM_CLOSE`, so the notice could not appear while the window was open;
  `ee22c3a8` then gave it an in-app banner, because a tray balloon is a courtesy the OS
  may decline while reporting success. Two sessions edited this file in one afternoon.
  If you touch it, say so.

## Contested files, edited in regions

No single owner; edit only your own region and say so first. Line ranges go stale
within hours — name the block, not the numbers.

`routes/api.php` · `Providers/ModuleServiceProvider.php` · `Support/Dashboard/GlanceRegistry.php` ·
`Providers/AppServiceProvider.php` · `Support/Files/Attachables.php` · `core/module_screens.dart` ·
`Support/Social/{SocialProvider,SocialOAuthDrivers}.php` and `Api/SocialAuthController.php`

For these, a guard test beats an ownership label: `tests/Feature/Api/GoogleConnectPermissionTest.php`
fails if one session breaks another's arm of the provider enum, which is enforcement
rather than etiquette.

## Keeping this true

Update it in the same commit as the work, the way the board is ticked in the same
turn. A stale row here is better than no row — it still names the files — but a
wrong session name that reads as current is how a phantom predecessor gets invented.
If you cannot confirm a session, blank the column.
