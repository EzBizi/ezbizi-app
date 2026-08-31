# Who owns what

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
| **Customers** | `Api/CustomersController.php` · `Models/Customer.php` · `Policies/CustomerPolicy.php` · `Services/Customers/DuplicateCustomerFinder.php` · `Dashboard/Glances/CustomersGlance.php` · `Dashboard/Widgets/CustomersAdded.php` · `Tasks/RecordLinks/CustomerTaskLink.php` · `Middleware/RefuseStaffAuthOnCustomerHost.php` · `screens/customers_screen.dart` · `screens/customers/` · `test/customers_screen_test.dart` · `factories/CustomerFactory.php` | `ezbizi-0a` |
| **Jobs** | `Models/Job.php` · `Api/JobsController.php` · `Policies/JobPolicy.php` · `factories/JobFactory.php` · `Dashboard/Glances/JobsGlance.php` · `Dashboard/Widgets/Revenue.php` · `screens/jobs_screen.dart` · `screens/jobs/` · `core/job_status.dart` | `ezbizi-85` |
| **Phone** | `Api/Phone/` · `Support/Phone/` (incl. `PhoneLineHealth`, `TwimlRenderer`) · `Models/PhoneNumber.php` · `screens/phone/` · `test/phone_*.dart` · `tests/Feature/Phone/` | `ezbizi-0b` |
| **Web Management** | `Api/Web/` · `Models/WebSite.php` · `Models/WebValue.php` · `Support/Web/` · `Policies/WebSitePolicy.php` · `Dashboard/Glances/WebManagementGlance.php` · `screens/web/` · `wordpress-plugin/ezbizi/` · `tests/Feature/Web/` | `358b3db` `e1f4d9a` `4b1a087` `5ce9285` |
| **Third-party integrations** — OAuth drivers, Meta, `config/services.php` provider blocks, the 2026-10-26 Meta cutoff | — | **deliberately blank, see below** |
| **Tasks** | `screens/tasks/` · `screens/tasks_screen.dart` · `Models/Task*.php` · `Policies/Task*.php` · `Support/Tasks/` · `Providers/TaskServiceProvider.php` · `Jobs/DeliverTaskNotificationIntent.php` · `Middleware/EnsureTaskRouteAccess.php` · `tests/Feature/Tasks/` | `ezbizi-13` |
| **Android · Play** | `client/android/` · the android path of `scripts/build-app.sh` · `build-web.sh` · `release.sh` · `windows-installer.iss` · `assets/logo` · `assets/installer` · `client/integration_test/store_screenshots_test.dart` · `client/test_driver/screenshots.dart` · the **Play** Console | `ezbizi-16` |
| **iOS · Apple** | `client/ios/` · the ios path of `scripts/build-app.sh` · the Mac relay · App Store Connect, TestFlight, the Apple Developer consoles | **blank — and it is mid-review** |
| **Website** | `www/` — **every page under it**, plus `robots.txt`, `sitemap.xml`, `check-site.py`, and the `www/favicon.ico` size list in `scripts/make-brand-assets.py`. Public copy, page structure, meta and structured data. *Not* `scripts/deploy-www.sh` or `/var/www/www` — publishing is the deploy session's. | `3b5b79f` `57b4502` `436fd6f` |

> **iOS is unowned and it is not idle — it is mid-App-Store-review.** The session
> that held it is gone. What the next owner inherits: App Store Connect status
> *Waiting for Review* on build `0.0.0112`; `./scripts/build-app.sh ios --upload`
> ships a new one, signing and validation already wired; signing material lives on
> the Mac relay and in `H:/Development/Credentials/` (see below — it has no backup);
> and **EU trader status needs the Account Holder and blocks submission outright**.
> Fuller detail is on the board under `ios-build-and-testflight`.
> **Do not delete `docs/ACTIVE-CONSTRAINTS.md` while that review is open** — it is
> the only durable record of the freeze.
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
| **Fleet** | `Support/Fleet/` · `Api/Vehicle*Controller.php` · `Models/Vehicle*.php` · `Policies/VehiclePolicy.php` · `Dashboard/Glances/FleetGlance.php` · `Tasks/RecordLinks/VehicleTaskLink.php` · `Console/Commands/ImportA1ToolsVehicles.php` · `migrations/2026_08_28_170000_add_fleet_fields_carried_from_a1_tools.php` · `screens/fleet/` · `test/fleet_screen_test.dart` · `tests/Feature/Fleet/` | `ezbizi-e9` |
| **Inventory** | `Models/Inventory*.php` · `Policies/Inventory*Policy.php` · `Api/Inventory*Controller.php` · `Support/Inventory/` · `Console/Commands/InventorySeedDemo.php` · `screens/inventory_screen.dart` · `screens/inventory/` · `test/inventory_screen_test.dart` · `tests/Feature/Inventory/` · `migrations/2026_08_2{4_160000_create_inventory_tables,5_120000_create_inventory_sales_tables}.php` · `factories/Inventory{Item,Location,Vendor}Factory.php`. **Not Inventory despite the name:** `Support/Privacy/DataInventory.php` and `tests/Feature/Api/DataInventoryTest.php` are the §9 personal-data map — this has misled once already | `ezbizi-8f` |
| **Services** | `Models/{Service,ServiceGroup,ServiceLabel}.php` · `Api/{Services,ServiceTaxonomy,ServiceGroups,ServiceLabels}Controller.php` · `Policies/Service{,Group,Label}Policy.php` · `Support/Services/ServiceVisibility.php` · `Dashboard/Glances/ServicesGlance.php` · `screens/services/` · `test/services_screen_test.dart` · `tests/Feature/Services/` | `ezbizi-d0` |
| **Inspection** | `Api/Inspection*Controller.php` · `Models/Inspection*.php` · `Policies/Inspection*.php` · `Support/Inspection/` · `Support/Files/HardenedPdf.php` · `screens/inspection/` | — |
| **Marketing** | `Api/Social*Controller.php` · `Models/Social*.php` · `Jobs/PublishSocialTarget.php` · `Support/Social/` · `screens/marketing/` · `tests/Feature/Social/` | — |
| **EZ Leads** — `leads`, `reviews`, `business_profile` | `Models/{Lead,Review,ReviewReply,BusinessLocation}.php` · their policies and controllers · `Services/{Leads,Reviews,BusinessProfile}/` · `widgets/connect_account.dart` | — |
| **Email — inbound** | Shared mailboxes, IMAP, tenant OAuth. `Support/Email/` · `Api/Mailbox*Controller.php` · `Api/{GoogleMail,MicrosoftMail,GoogleWorkspace}AuthController.php` · `Models/{Mailbox*,MessageSeen,GoogleMailSignIn,MicrosoftMailSignIn}.php` · `screens/email/` · `test/email_*.dart` · `tests/Feature/{Email*,Imap*,MailboxWatch,GmailMailboxWatch,HtmlEmailSanitiser}Test.php` · `tests/Feature/Api/Mailbox*Test.php` | `ezbizi-22` |
| **Platform outbound mail (SES)** | **Not the same module as Email — this is the platform's own outgoing transport.** `Support/Mail/` · `resources/views/vendor/mail/` · `Notifications/{PasswordResetLink,InvitationLink}.php` · `Console/Commands/SendTestEmail.php` · the mail block of `config/mail.php`. Off-repo and also theirs: the `MAIL_*` and `EZBIZI_BACKUP_ALERT_TO` keys in the server `.env` (a release rollback does **not** revert these), `/usr/local/bin/ezbizi-mail-warmup.sh` and its root cron, the `_dmarc` record in the BIND zone, and the AWS SES/SNS/IAM consoles | `ezbizi-77` |
| **Automations** | `Support/Automations/` · `Api/AutomationsController.php` · `Jobs/DeliverAutomationEvent.php` · `Models/Automation{,Version,Event,Run}.php` · `Policies/AutomationPolicy.php` · `Console/Commands/DispatchAutomationEvents.php` · `Support/Modules/{Trigger,Action}Definition.php` · `Support/Modules/{AutomationDeclaration,AutomationSubject}.php` · `screens/{automations_screen,automation_builder}.dart` · `tests/Feature/ModuleRegistryTest.php` · `tests/Feature/Api/Automation*Test.php`. **Not theirs:** `Providers/ModuleServiceProvider.php` — every module writes its own block; shared, announce before editing | `ezbizi-b0` |

## Files nobody owns

Name these rather than assuming a module covers them.

- **`Api/StaffClientVersionsController.php`**, `Middleware/EnforceMinimumClientVersion.php`, `Models/PlatformMinimumClientVersion.php` — control plane. A minimum client version is a **database row**: it needs no deploy, passes no gate, and 426s every client that falls below it. It has no module owner, so nobody is watching it.
- **The store consoles** are not code and appear in no file list, so an unowned one does not read as unowned — it just goes quiet. They carry real deadlines: Meta Tech Provider has a hard 2026-10-26 cutoff, and **EU trader status needs the Account Holder and blocks App Store submission outright**.
- **Signing material is single-disk and outside every backup this project has.**
  `H:/Development/Credentials/` holds the Apple signing artefacts and the Android
  upload key. It is not in git, so the GitHub push does not cover it, and the
  App Store Connect `.p8` **downloads exactly once from Apple** — the copy there is
  the only one. Losing the Android upload key means a key-reset request to Google;
  losing the `.p8` cannot be undone by anyone. Do not commit any of it — this repo
  is pushed to production. It needs a backup that is not this machine, and it does
  not have one.

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
