# EzBizi project review — 2026-09-08

The original read-only audit is preserved below in priority order. P1 means address first; P2 means a material defect or security concern; P3 means maintainability. The audit itself performed no fixes or deployment. Subsequent authorized remediation is recorded separately here; original line numbers refer to the audited tree.

## Remediation ledger — deployed 2026-09-08, version 0.0.0152

All 28 original findings are fixed in release `3a595a85e0f56865c5ddbb2297e01dd32f72a9bf`, including the supplemental repairs below. Web/API and Windows publication are verified. Native distribution outcomes are recorded in [release notes](RELEASE-NOTES.md); a build/upload does not establish public store availability. Unrelated work from other sessions is preserved. The table records individual regression evidence; the full release gates below cover the integrated tree.

| Finding | Resolution in review | Status |
|---|---|---|
| 1 | Effective DB guard before destructive setup | Targeted tests passed |
| 2 | Shared owning-scope write predicate | Newsletter/ownership tests passed |
| 3 | Exact newsletter connection retained | Two-account scheduling test passed |
| 4 | Eligible recipient segment, both suppression sources, bounded batches | Newsletter tests passed |
| 5 | Transactional admission; admitted content immutable | Duplicate/scheduled/sending tests passed |
| 6 | Validated request view with legacy fallback | Backend and Flutter tests passed |
| 7 | Explicit release SHA passed to deployment | Local shell identity tests passed |
| 8 | Account-specific factor throttle | Isolation tests passed |
| 9 | Session generations reject stale responses | Flutter tests passed |
| 10 | Webhook DNS pinning | Transport tests passed |
| 11 | Streamed image size enforcement | Transport tests passed |
| 12 | Authenticated unsubscribe proof, throttle, once-only event | Newsletter tests passed |
| 13 | Provider recipient unsubscribe footer | Generated-content test passed |
| 14 | Failure signature repaired; ambiguous outcome explicit | Failure regression passed |
| 15 | Sweep failures inside tenant context; continue other rows | Sweep tests passed |
| 16 | Inventory parent locks | Combined backend regressions passed: 409 tests/2,167 assertions |
| 17 | Monotonic phone states | Combined backend regressions passed: 409 tests/2,167 assertions |
| 18 | Serialized callback deduplication | Combined backend regressions passed: 409 tests/2,167 assertions |
| 19 | View predicate before list limit | Sibling-pressure test passed |
| 20 | Persist custom HTML on create | Round-trip test passed |
| 21 | Retain deleted automation subject scope | Combined backend regressions passed: 409 tests/2,167 assertions |
| 22 | Durable action outcomes and selective retries | Combined backend regressions passed: 409 tests/2,167 assertions |
| 23 | Export follows displayed view | Backend regressions passed |
| 24 | Keep reachable orphan subcompany roots | Flutter tests passed |
| 25 | Multipart expired-session handling | Flutter tests passed |
| 26 | Staff unauthorized-response subscription | Flutter tests passed |
| 27 | Short frontdoor, indexed docs, canonical ownership and accurate release policy | Reviewed; archived history verified |
| 28 | Remove starter Vite/welcome; retain local HTTP 200 API identity | Root regression passed: 1 test/2 assertions; references checked |

Observed validation: the final combined backend regression run passed **409 tests/2,167 assertions** with CommonMark 2.10.1 installed and the complete migrations. Full Flutter suite **1,276 passed**, whole-client analysis clean. All **three deployment shell suites passed**. Independent implementation reviews found no remaining blockers. The earlier broad local preflight was stopped during schema changes and is not a pass. The pinned release then passed the full Linux backend gate: **2,866 tests/17,119 assertions**, and repeated the full client gate successfully. Production API and UI markers both match the release SHA; internal health and public web root return HTTP 200. The live Windows manifest matches version 0.0.0152 and commit 3a595a85, and its installer checksum matches the local artifact.

### Operational follow-up and limits

1. Existing queued newsletters that predate stored connection identity fail explicitly if that identity is missing. They must be reviewed and re-scheduled with the intended account; this release never guesses an account. No production inventory of such rows was performed.
2. An interrupted mail/SMS action with an unknown provider outcome is not automatically repeated. Webhook delivery remains at least once; receivers must honor the supplied stable idempotency key. Historical deleted subjects without an ownership snapshot cannot have that lost scope reconstructed automatically.
3. Android bundle creation and Apple build upload are distinct from public store rollout. The actual publication matrix and any console follow-up are documented in [STORES](STORES.md). The server lock covers API/UI activation, not simultaneous native builds or store publication.
4. The regression suite and source review do not constitute real provider sends, a live penetration test, or a multiprocess load test. Existing unrelated provider/console/DNS obligations remain on TODO and the board.

### Full-gate follow-up

The first completed Linux gate ran 2,864 backend tests and caught two additional defects: invalid UTF-8 in the read-only automation refusal, and an omitted newsletter dashboard icon. The message now uses valid text, a PHP source-encoding regression prevents recurrence, and the newsletter has an explicit icon. Targeted verification and the new full gate passed before deployment; no production activation occurred during the failed run.

### Supplemental P1 — dependency security

The scan found CommonMark 2.9.0 affected by high-severity advisories [GHSA-8rr7-cvq3-gmfh](https://github.com/advisories/GHSA-8rr7-cvq3-gmfh), [GHSA-jjv6-8j6v-6j52](https://github.com/advisories/GHSA-jjv6-8j6v-6j52), [GHSA-f8fg-pg57-v4j8](https://github.com/advisories/GHSA-f8fg-pg57-v4j8) and [GHSA-j8pm-gj4c-rq4x](https://github.com/advisories/GHSA-j8pm-gj4c-rq4x). The lock is minimally updated to **2.10.1**, with no other package changes. `composer audit --locked` reports no advisories; installation and combined backend regressions passed; full release gates passed.

### Supplemental P1 — production receive identity and serialization

The old hook read the received SHA but checked out mutable `main`. The updated hook archives the received commit, writes `.ezbizi-release-sha` and uses a production lock. The receive wrapper installs pinned helpers, holds the lock through API/UI activation and checks identity. A failed UI swap restores the previous UI and reports partial activation. All three shell suites passed. Live API/UI release markers and the installed hook checksum were verified against this release; see [DEPLOY](DEPLOY.md).

### Supplemental P2 — reusable ownership policy

The inherited-read/upward-write defect extended beyond newsletters. `OwnedScope` centralizes active-user, tenant, assignment and displayed-view checks for owned resources. Dedicated policies retain organization-shared customer semantics. The [ownership contract](OWNERSHIP.md) includes missing-owner and sibling regressions. Independent review also caught and repaired actual franchise inherited-read visibility and newsletter create admission. Those follow-up checks passed with the combined newsletter/ownership regressions. The helper does not replace module permissions or make incomplete owner fields valid.

## Original audit

## Capabilities and overall assessment

EzBizi implements a substantial multi-tenant field-service product: organizational hierarchy and roles; customers, jobs and service catalogues; inventory purchasing, receiving, issuing, transfers and sales; fleet; HR contracts/onboarding and time clock; inspections; training; phone/SMS and routing; mail; social publishing; newsletters; lead/review integrations; availability scheduling; monitoring; website business-data integration; automations, audit, data transfer and a staff control plane. Flutter targets web and native platforms, with repository release tooling for Windows, Android and Apple builds.

This describes repository implementation, not verified production readiness or provider approval. Integration availability, store/console status, and historical claims in STATUS/TODO were not independently checked against live services.

Strong foundations include explicit TenantContext restoration, fail-closed tenant model scopes, resource policies, private attachment authorization, view-aware filing helpers, transactional automation emission, inventory decimal arithmetic and locked balances, and meaningful regression tests. The main coherence problem is inconsistent use of those foundations at workflow boundaries: read visibility versus write authority, view versus reach, and interactive versus delayed execution.

## P1 — highest priority

### 1. Test database refusal happens after destructive setup

**Evidence:** `tests/TestCase.php:56` calls `parent::setUp()` before the database-name check at lines 60–66. Laravel's `Testing/TestCase.php:75` enters `setUpTheTestEnvironment`; `InteractsWithTestCaseLifecycle.php:106,227` invokes RefreshDatabase, whose `RefreshDatabase.php:119` runs `migrate:fresh`.

**Trigger/impact:** a wrong connection supplied by environment or cached configuration can be migrated/dropped before the later safety exception. The earlier Windows guard only rejects the literal shared test schema; it does not validate the effective connection. This is a destructive safety defect, not a claim that any database was damaged during this review.

**Fix:** validate the resolved connection before RefreshDatabase can run, including URL/cached configuration, and restrict the test DB account's privileges to isolated test schemas. Verify with a harmless test double that destructive setup is never reached after an unsafe configuration.

### 2. Company/franchise users can modify organization newsletters

**Evidence:** `app/Http/Controllers/Api/NewslettersController.php:173,212,253` uses `findInView` for update/delete/send. Its read predicate delegates to `app/Support/Social/SocialConnectionAccess.php:34,44`, which makes organization records visible in lower views.

**Trigger/impact:** a lower-scope user with newsletter edit/delete/send permission can act on an inherited organization newsletter. This violates the explicit rule that authority does not flow upward.

**Fix:** keep inherited read access, but authorize mutation against the newsletter's owning scope with a separate write predicate/policy.

### 3. Scheduled newsletters forget which account was selected

**Evidence:** `app/Http/Controllers/Api/NewslettersController.php:288` persists the provider, not connection ID. `app/Console/Commands/SendDueNewsletters.php:48` later selects the first connection for that organization/provider.

**Trigger/impact:** with two company Mailchimp connections, a mailing scheduled under B may use A's credentials. At minimum it can fail against the wrong account; if the target list is accessible there, it can send through that account.

**Fix:** persist the exact connection ID and validate it on release. Disconnection must fail explicitly, never select a replacement account implicitly.

### 4. Confirmed local unsubscribes do not suppress later Mailchimp campaigns

**Evidence:** `app/Jobs/SendNewsletter.php:135` filters local unsubscribes from the uploaded people. `app/Support/Newsletter/MailchimpSender.php:51` only synchronizes remaining people; `app/Support/Newsletter/MailchimpClient.php:133` targets the entire existing remote list.

**Trigger/impact:** someone uploaded by an earlier campaign remains subscribed in Mailchimp after locally unsubscribing and can receive the next campaign despite the confirmation page.

**Fix:** synchronize suppression appropriately or target an explicit recipient segment that respects both local scope and provider subscription state. Test two successive campaigns with an unsubscribe between them.

### 5. Repeated Send requests create duplicate mailings

**Evidence:** `app/Http/Controllers/Api/NewslettersController.php:255` rejects only `sent`; line 288 creates a fresh send for every accepted request.

**Trigger/impact:** submitting again while scheduled or sending produces multiple rows/jobs and separate real campaigns. A sequential double submission of a scheduled newsletter suffices; this does not require an unusually tight race.

**Fix:** atomically admit one pending send, with a durable request/state uniqueness rule. Repeated requests should return that attempt or refuse until resolved.

### 6. Another tab/device changes where an open draft is filed

**Evidence:** `client/lib/core/session.dart:222` saves global `navigation.scope`; `app/Support/Scoping/ViewScope.php:67` reads it per request. `client/lib/core/api_client.dart:158` carries no expected view. A ring-group form submits only its connection at `client/lib/screens/phone/ring_groups_pane.dart:174`; `app/Http/Controllers/Api/Phone/RingGroupsController.php:115` files using the latest server preference.

**Trigger/impact:** open a draft in A, switch to B on another device, then save the first draft using an organization-shared phone connection. It is filed in B while the first screen still says A. This is cross-view misfiling within the user's reach, not a demonstrated cross-tenant bypass.

**Fix:** bind each request/draft to its originating view and validate that view's reach server-side. Use the persisted preference only as a default for future sessions.

### 7. The release's pinned commit does not pin the web/API deployment

**Evidence:** `scripts/release.sh:218` pins a detached worktree to HEAD, then invokes deploy at line 385. `scripts/deploy.sh:71` independently resolves the shared `main` branch, not the detached release HEAD.

**Trigger/impact:** if main advances between pinning and deploy, web/API ship the newer commit while native installers build the older pinned commit. Even `--gates` can test a different commit from those installers.

**Fix:** resolve one release SHA and explicitly pass it to every builder/deployer. Assert artifact and deployed commit identity. A worktree alone does not pin another branch reference.

### 8. Login to another account can reset a protected account's TOTP guesses

**Evidence:** `app/Http/Controllers/Api/AuthController.php:261` clears the shared email+IP throttle before returning an ambiguous organization choice. `app/Services/Auth/AuthenticateUser.php:168` can return that choice when one matching account needs TOTP and another does not.

**Trigger/impact:** an attacker who already knows a password shared by two accounts under the same email can alternate wrong protected-account `/auth/choose` codes with an unprotected/ambiguous `/auth/login`, resetting the counter. The second-factor guess limit is therefore bypassable under these preconditions.

**Fix:** independently throttle factor attempts per protected account, clearing only on successful verification for that account. Merely moving the clear below the ambiguous branch is insufficient if another account's successful login still clears the same key.

## P2 — material bugs and security concerns

### 9. A delayed refresh can repopulate a logged-out session

`client/lib/core/session.dart:109` adopts `/me` responses unconditionally; logout at line 238 does not invalidate outstanding requests. A delayed success after logout restores private profile/scope state and the shell while the token remains null; it can also overwrite a newly adopted identity. Add a session generation guard around asynchronous state adoption and invalidate it on logout, expiry and identity change. This does not restore server authentication by itself.

### 10. Outbound webhook validation leaves DNS resolution unpinned

`app/Support/Automations/OutboundWebhook.php:72,124,163` validates one DNS resolution, then asks the HTTP client to resolve the hostname again. The code itself documents the residual. An attacker-controlled changing DNS answer can direct the subsequent connection toward an address the validation rejected. HTTPS certificate verification still constrains successful interaction; this review did not demonstrate metadata extraction or a live exploit. Pin the validated address while preserving hostname/SNI, following the transport pattern already used by `app/Support/Email/RemotePictureFetcher.php:62`.

### 11. Remote email pictures are size-checked after downloading

`app/Support/Email/RemotePictureFetcher.php:73,100` retrieves/materializes the full body before enforcing its 2 MiB limit. Opening a sender-controlled large image can consume excessive transfer, temporary storage and PHP memory before rejection. Enforce an incremental byte ceiling, including undeclared/chunked and decoded bodies, and reject excessive declared lengths early.

### 12. Public unsubscribe accepts forged identities and repeated events

`app/Http/Controllers/Api/NewsletterUnsubscribeController.php:60,81,91` accepts any email for a known newsletter UUID, writes it, and emits an automation event on every request. `routes/api.php:355` exposes this publicly without a route throttle. A recipient can submit other addresses or replay events. Require recipient-specific signed proof or verified provider events, throttle, and emit only on a real subscription-state change.

### 13. Generated unsubscribe links cannot complete the form

`app/Jobs/SendNewsletter.php:89` generates a URL without the recipient's `e` parameter. `app/Support/Newsletter/BlockRenderer.php:161` inserts it verbatim; `NewsletterUnsubscribeController.php:47,65` requires that value. Following the actual rendered link and submitting yields 422. Use a working provider unsubscribe link or a signed per-recipient URL; test the URL extracted from rendered content rather than manually constructing one.

### 14. Unexpected newsletter errors cause a second TypeError

`app/Jobs/SendNewsletter.php:101` calls `fail($send, $events, ...)`, but line 159 accepts `(NewsletterSend, string)`. An unexpected sender Throwable therefore fails while recording failure and leaves mailing state unresolved. Remove the extra argument and distinguish unknown delivery outcome from confirmed non-delivery; do not promise “Nothing was sent” after an ambiguous timeout.

### 15. A disconnected mailing account can abort the scheduled sweep

`app/Console/Commands/SendDueNewsletters.php:61` calls `markFailed` outside TenantContext; it also model-saves the newsletter. `app/Models/NewsletterSend.php:68` uses `save()`, and `app/Models/Concerns/BelongsToTenant.php:74` requires tenant context on updates. The intended disconnection failure path throws instead of marking the item, and stops the sweep before later items. Perform these writes within the appropriate tenant context and isolate individual sweep failures.

### 16. Inventory cancellation races with stock movement

`app/Http/Controllers/Api/InventoryPurchaseOrdersController.php:245` and `InventorySalesController.php:250` check movement quantities then cancel without locking the parent. `app/Support/Inventory/InventoryMover.php:191,390` checks an already-read parent and locks line rows later. Interleaving these operations can leave a canceled order with received/fulfilled stock. Lock and refresh the same parent in every cancel/receive/fulfill transaction before checking state.

### 17. Late phone callbacks regress completed calls

`app/Http/Controllers/Api/Phone/StatusWebhookController.php:106` preserves missed/voicemail states but accepts earlier statuses after completed. Delivering completed then delayed ringing leaves the record ringing. Enforce valid monotonic transitions or callback ordering while retaining useful duration/attestation updates.

### 18. Concurrent phone callback duplicates can emit duplicate automations

`app/Http/Controllers/Api/Phone/StatusWebhookController.php:59,117,193` and `RecordingWebhookController.php:55,69,77` use separate unlocked reads/existence checks before event insertion. Concurrent deliveries can both see no event and insert separate IDs. Automation run uniqueness per event does not deduplicate those IDs. Serialize on the call or use a durable unique receipt/event key; sequential duplicate tests are insufficient.

### 19. Newsletter pagination hides valid company records

`app/Http/Controllers/Api/NewslettersController.php:61` limits the newest organization-wide records to 50 before applying view filtering at line 63. Fifty newer sibling records can make a company's list empty even though its count is nonzero. Apply scope conditions in the query before pagination.

### 20. Newsletter creation discards supplied HTML

`app/Http/Controllers/Api/NewslettersController.php:140` omits `html_override` during creation, although validation accepts it at line 410 and update stores it at line 192. A create request with custom HTML and no blocks loses its content. Persist the validated field on create and verify a create/read round trip.

### 21. Deleted subjects lose automation placement

`app/Support/Automations/AutomationEngine.php:318` resolves subjects with the normal query, hiding deleted customers/jobs. `scopeOf` at line 343 returns no scope for a missing subject. `CustomersController.php:269` and `JobsController.php:256` emit deletion events before deleting; by asynchronous delivery, company/franchise rules no longer match. Persist event placement at emission and use it independently of the current subject. Job deletion payload also lacks its franchise ID, so relying on existing payload is insufficient.

### 22. Transient action failures are marked delivered without retry

`app/Support/Automations/AutomationEngine.php:202` catches action errors; line 208 records unsuccessful results; line 116 still marks the event delivered. The job's backoff only applies to escaping exceptions. A temporary webhook failure therefore receives no advertised action retry, and the claimed run prevents replaying the same version. Introduce durable per-action outcomes/retry scheduling and stable action-specific idempotency, preserving successful actions. Treat crash-after-send as an explicitly ambiguous state instead of blindly replaying everything.

### 23. Job CSV exports ignore the displayed view

`app/Support/Data/Exporter.php:261` filters by all reachable franchises, while `app/Http/Controllers/Api/JobsController.php:58` uses current ViewScope. Exporting from A can include B's jobs when the person reaches both. This is a mismatch with the displayed dataset and an accidental disclosure risk when sharing exports, not a demonstrated escape from reach. Capture current view when preparing the export; keep any deliberate whole-organization export separately explicit.

### 24. Reachable subcompanies vanish from the scope picker

`client/lib/widgets/scope_picker_panel.dart:121` only traverses roots with null parent. `routes/api.php:1687` returns companies behind reachable franchises, without necessarily returning their inaccessible ancestors. A user reaching only a subcompany with multiple branches can lose every company/branch choice. Treat a company whose parent is absent from the returned collection as a visible root, without making the inaccessible parent selectable.

### 25. Multipart uploads omit expired-session handling

`client/lib/core/api_client.dart:267,325` handles upgrade status but never invokes `onUnauthorized` for multipart responses. An expired upload leaves the client apparently signed in. Centralize authenticated response-status handling across JSON, bytes and both multipart paths; preserve unauthenticated login behavior.

### 26. Staff sessions do not subscribe to unauthorized responses

`client/lib/core/staff_session.dart:16` lacks the tenant Session's expiry callback registration. Revoked/expired staff tokens leave the control-plane shell active and individual screens failing. Register shared expiry handling that clears staff state and storage.

## P3 — structure, simplification and documentation

### 27. Product and release documentation contradict their implementation

`docs/EZBIZI-STATUS.md:21` still reports v0.0.0148 while reviewed `client/VERSION` is 0.0.0151. StartUp's release explanation still presents mandatory gates while STATUS and `scripts/deploy.sh:20` explicitly describe opt-in gates. The front door still introduces Flutter as web-only despite native release tooling. Consolidate current capability/release facts and keep incident narratives separate from operational instructions. STATUS is the declared authority; this is a documentation consistency finding, not permission to reverse the owner's release policy.

### 28. The Laravel welcome-page build pipeline is unrelated to the product client

`routes/web.php:5` serves the starter welcome page; root `package.json` and `vite.config.js` build only `resources/css/app.css` and `resources/js/app.js`. Composer's starter setup/dev scripts still install/run that pipeline, while the product is Flutter and marketing lives in `www/`. Replace the root welcome route with an intentional product/API response, retire its assets and Vite/Tailwind pipeline, and rewrite README/setup around the real architecture. Preserve the mail Blade templates and website integration snippet.

Simplification tags: **delete** starter welcome/test artifacts; **native** keep one actual Flutter client build path; **shrink** centralize response handling (finding 25); **shrink** one durable prepare-send operation shared by immediate/scheduled mail (findings 3/5); **shrink** reusable scope predicates with explicit read/write semantics (finding 2), rather than creating temporary SocialConnection models to ask about newsletter placement.

Potential reduction for starter cleanup alone: roughly 250 source/config lines and five direct root npm development dependencies, excluding lockfile reductions and replacement README/setup text. This is an estimate, not a measured patch. The `tests/Unit/ExampleTest.php` true-is-true assertion can also be deleted. The policy/test abstractions were not proposed for removal merely because they have few implementations.

## Existing operational risks and capability gaps

- Release gates are explicitly off by the owner's recorded decision in `scripts/deploy.sh:20`. There is no automatic full-suite assurance on the normal release path. An improvement compatible with fast releases is a small, fast blocking regression set for tenant/view boundaries, authentication and send admission, plus asynchronous broader checks. This is an accepted operational risk, not a newly discovered policy violation.
- STATUS/TODO already track infrastructure/provider/console limitations, including second-machine DNS resilience. No live infrastructure audit was performed; historical statuses should not be represented as new verified outages.
- Module presence is not proof that every account/network is connected, every publisher is approved, or every scheduled business workflow is complete. Publish a generated capability matrix distinguishing implemented, configured, externally approved and exercised behavior.

## Verification and limitations

- Reviewed routing/middleware/model isolation, auth/TOTP, scoped client/session behavior, newsletter account/recipient/state flows, inventory transitions, phone callbacks, automation delivery, data export, email fetching and release scripts. Findings were traced through supporting code, including installed Laravel lifecycle implementation.
- Ran the database-independent EnrolmentQr and SpaAssetCache PHP tests: **7 tests passed, 24 assertions**.
- Client review ran `flutter test test/session_expiry_test.dart test/deployment_test.dart test/desktop_update_test.dart`: **14 tests passed**. Those tests do not cover the identified multipart/staff/delayed-refresh defects.
- No database-backed suite or concurrency/exploit reproduction was run. Race findings describe concrete source-level interleavings; they are not load-test results. No dependency advisory scan, live penetration test, provider-console audit, or comprehensive visual/device QA was performed.
- This is a broad, evidence-based review, not a claim that every line or every possible defect has been inspected. Suggested fixes remain unimplemented and should receive focused regression coverage before deployment.
