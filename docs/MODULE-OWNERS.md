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

**When attribution matters, cite the commit SHA.** Every commit in this repo carries
the same `%an` — one machine, one git config — so authorship cannot separate
sessions either. The SHA is the only handle that survives a rename.

| Module | Files | Session, when last seen |
|---|---|---|
| **Customers** | `Api/CustomersController.php` · `Models/Customer.php` · `Policies/CustomerPolicy.php` · `Services/Customers/DuplicateCustomerFinder.php` · `Dashboard/Glances/CustomersGlance.php` · `Dashboard/Widgets/CustomersAdded.php` · `Tasks/RecordLinks/CustomerTaskLink.php` · `Middleware/RefuseStaffAuthOnCustomerHost.php` · `screens/customers_screen.dart` · `screens/customers/` · `test/customers_screen_test.dart` · `factories/CustomerFactory.php` | `ezbizi-0a` |
| **Jobs** | `Models/Job.php` · `Api/JobsController.php` · `Policies/JobPolicy.php` · `factories/JobFactory.php` · `Dashboard/Glances/JobsGlance.php` · `Dashboard/Widgets/Revenue.php` · `screens/jobs_screen.dart` · `screens/jobs/` · `core/job_status.dart` | `ezbizi-85` |
| **Phone** | `Api/Phone/` · `Support/Phone/` (incl. `PhoneLineHealth`, `TwimlRenderer`) · `Models/PhoneNumber.php` · `screens/phone/` · `test/phone_*.dart` · `tests/Feature/Phone/` | `ezbizi-0b` |
| **Web Management** | `Api/Web/` · `Models/WebSite.php` · `Models/WebValue.php` · `Support/Web/` · `Policies/WebSitePolicy.php` · `Dashboard/Glances/WebManagementGlance.php` · `screens/web/` · `wordpress-plugin/ezbizi/` · `tests/Feature/Web/` | `ezbizi-3d` |
| **Tasks** | `screens/tasks/` · `screens/tasks_screen.dart` · `Models/Task*.php` · `Policies/Task*.php` · `Support/Tasks/` · `Providers/TaskServiceProvider.php` · `Jobs/DeliverTaskNotificationIntent.php` · `Middleware/EnsureTaskRouteAccess.php` · `tests/Feature/Tasks/` | `ezbizi-13` |
| **Build · store · release** | `scripts/build-app.sh` · `build-web.sh` · `release.sh` · `windows-installer.iss` · `client/ios/` · `assets/logo` · `assets/installer` · **the Apple, Play, Meta, TikTok, Pinterest and Thumbtack consoles** | `ezbizi-71` |
| **Deploy** | `scripts/deploy.sh` · `scripts/sandbox.sh` · the production symlink and SSH path · the release rows in `EZBIZI-STATUS.md` | `ezbizi-a1` |
| **Fleet** | `Support/Fleet/` · `Api/Vehicle*Controller.php` · `Models/Vehicle*.php` · `Policies/VehiclePolicy.php` · `Dashboard/Glances/FleetGlance.php` · `Tasks/RecordLinks/VehicleTaskLink.php` · `Console/Commands/ImportA1ToolsVehicles.php` · `migrations/2026_08_28_170000_add_fleet_fields_carried_from_a1_tools.php` · `screens/fleet/` · `test/fleet_screen_test.dart` · `tests/Feature/Fleet/` | `ezbizi-e9` |
| **Inventory** | `Models/Inventory*.php` · `Policies/Inventory*Policy.php` · `Api/Inventory*Controller.php` · `Support/Inventory/` · `screens/inventory/` · `tests/Feature/Inventory/` | — |
| **Services** | `Models/{Service,ServiceGroup,ServiceLabel}.php` · `Api/{Services,ServiceTaxonomy,ServiceGroups,ServiceLabels}Controller.php` · `Policies/Service{,Group,Label}Policy.php` · `Support/Services/ServiceVisibility.php` · `Dashboard/Glances/ServicesGlance.php` · `screens/services/` · `test/services_screen_test.dart` · `tests/Feature/Services/` | `ezbizi-d0` |
| **Inspection** | `Api/Inspection*Controller.php` · `Models/Inspection*.php` · `Policies/Inspection*.php` · `Support/Inspection/` · `Support/Files/HardenedPdf.php` · `screens/inspection/` | — |
| **Marketing** | `Api/Social*Controller.php` · `Models/Social*.php` · `Jobs/PublishSocialTarget.php` · `Support/Social/` · `screens/marketing/` · `tests/Feature/Social/` | — |
| **EZ Leads** — `leads`, `reviews`, `business_profile` | `Models/{Lead,Review,ReviewReply,BusinessLocation}.php` · their policies and controllers · `Services/{Leads,Reviews,BusinessProfile}/` · `widgets/connect_account.dart` | — |
| **Email — inbound** | Shared mailboxes, IMAP, tenant OAuth. `Support/Email/` · `Api/Mailbox*Controller.php` · `Api/{GoogleMail,MicrosoftMail,GoogleWorkspace}AuthController.php` · `Models/Mailbox*.php` · `screens/email/` | — |
| **Platform outbound mail (SES)** | **Not the same module as Email — this is the platform's own outgoing transport.** `Support/Mail/` · `resources/views/vendor/mail/` · `Notifications/{PasswordResetLink,InvitationLink}.php` · `Console/Commands/SendTestEmail.php` · the mail block of `config/mail.php`. Off-repo and also theirs: the `MAIL_*` and `EZBIZI_BACKUP_ALERT_TO` keys in the server `.env` (a release rollback does **not** revert these), `/usr/local/bin/ezbizi-mail-warmup.sh` and its root cron, the `_dmarc` record in the BIND zone, and the AWS SES/SNS/IAM consoles | `ezbizi-77` |
| **Automations** | `Support/Automations/` · `Api/AutomationsController.php` · `Models/Automation*.php` · `Console/Commands/DispatchAutomationEvents.php` · `Support/Modules/{Module,Trigger,Action}Definition.php` · `screens/automation_builder.dart` | — |

## Files nobody owns

Name these rather than assuming a module covers them.

- **`Api/StaffClientVersionsController.php`**, `Middleware/EnforceMinimumClientVersion.php`, `Models/PlatformMinimumClientVersion.php` — control plane. A minimum client version is a **database row**: it needs no deploy, passes no gate, and 426s every client that falls below it. It has no module owner, so nobody is watching it.
- **The store consoles** are not code and appear in no file list. They carry real deadlines — Meta Tech Provider has a hard 2026-10-26 cutoff.

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
