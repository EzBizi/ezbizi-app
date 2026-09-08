# Release notes

This file distinguishes reviewed implementation from deployment. Current production measurements remain in [STATUS](EZBIZI-STATUS.md); the application version is sourced from `client/VERSION`. Earlier implementation history remains in [BUILD-LOG](EZBIZI-BUILD-LOG.md).

## 2026-09-08 — review remediation, deployment pending

The release coordinator has chosen **0.0.0152**. These changes are not a claim that production or any app store has received it. The [review ledger](PROJECT-REVIEW-2026-09-08.md) records finding-by-finding status and verification; [TODO](EZBIZI-TODO.md) and the work board retain unrelated obligations and external blockers.

- Newsletter writes honor owning scope; sends retain their selected account, reject duplicates, preserve custom HTML, and filter visible records before pagination. Recipient targeting respects local/provider opt-outs, uses bounded segment batches, and provides authenticated unsubscribe links. Scheduled and uncertain send outcomes remain explicit.
- Client requests carry their displayed view; legacy clients retain preference fallback. Delayed responses cannot restore an ended session. Multipart and staff transports handle expired authentication. Export and scope-picker behavior follow the displayed scope.
- Test setup refuses unsafe database configurations before migrations. Factor throttles isolate accounts. Outbound webhooks pin validated addresses and remote email pictures enforce transfer limits.
- Inventory, phone and automation ordering repairs are tracked individually in the review ledger; do not infer their verification from unrelated suite results.
- Release tooling passes one explicit commit through backend/client builds and uses serialized receive/activation helpers and identity checks. Installed minimum versions are unchanged.
- CommonMark is updated from 2.9.0 to 2.10.1 to address reported parser denial-of-service and XSS advisories. The locked Composer audit reports no advisories; dependency installation and combined backend regressions passed.
- Documentation now has a short entry point, ownership contract and index, with incident history retained. Unused Laravel welcome/Vite scaffolding is removed. Production's Flutter root remains unchanged; the local Laravel root keeps HTTP 200 and identifies the API. Mail templates, website snippet and HR resources are retained.

Validation before integration: combined backend regressions **409 tests/2,167 assertions** with the patched dependency and final schema; full Flutter **1,276 tests passed** and analysis clean; all three deployment shell suites passed. Full committed release gates and deployment are pending.
