# Release notes

> **Not maintained since 2026-09-18.** What each version shipped is `client/lib/core/version_history.dart` (the deploy desk writes it); what is live is `ssh app 'cat /var/www/ezbizi-current/.ezbizi-release-sha /var/www/ezbizi-current/client/VERSION'`. The entries below are history.

This file distinguishes reviewed implementation from deployment. Current production measurements remain in [STATUS](EZBIZI-STATUS.md); the application version is sourced from `client/VERSION`. Earlier implementation history remains in [BUILD-LOG](EZBIZI-BUILD-LOG.md).

## 2026-09-18 — where production actually is, and the gap in this file

**Production runs `0.0.0239`, built from commit `2832a607`**, serving since 2026-09-17 22:32 UTC. Measured from the deployed bundle's compiled-in `BUILD_ID` rather than from any document; `build-web.sh` writes it with `--dart-define` on every build, so it moves whether or not `client/VERSION` was touched.

**Nothing between `0.0.0153` and `0.0.0239` has an entry below.** Eighty-seven versions shipped in ten days across many concurrent sessions, and this file was not written in the same pass. The record that *was* kept is `client/lib/core/version_history.dart`: its own docblock requires an entry in the same commit as the version bump, it is what the version stamp in the app's sidebar opens, and it matches `git log` for the versions spot-checked here. **Read it, not this file, for what a given version shipped.** This entry exists so the gap is stated rather than discovered.

What that means for the claims in this file: the passing test counts recorded under `0.0.0152` belong to that release and to no later one. No gated release has been recorded here since, so there is **no measured suite result for the code now in production**. `scripts/release.sh --gates` is what produces one.

Deployment state on 2026-09-18, checked rather than assumed: `app.ezbizi.com`, `dev.ezbizi.com` and `ezbizi.com` answer 200; `/api/v1/me` refuses unauthenticated callers with 401; `main` is three commits ahead of the deployed client and none of them touches `client/` or `app/`. The marketing site was separately published at `7d296a90` (2026-09-18 14:52 UTC, all seven pages byte-verified by `deploy-www.sh`) to correct a `/privacy/` sentence that denied EzBizi ever carries a business's mail, which three automation actions do.

## 2026-09-08 — 0.0.0152 review remediation deployed

**0.0.0152** shipped from commit `3a595a85e0f56865c5ddbb2297e01dd32f72a9bf` through the gated release pipeline. Web/API and Windows publication are verified. The [review ledger](PROJECT-REVIEW-2026-09-08.md) records finding-by-finding status and verification; [TODO](EZBIZI-TODO.md) and the work board retain unrelated obligations and external blockers.

- Newsletter writes honor owning scope; sends retain their selected account, reject duplicates, preserve custom HTML, and filter visible records before pagination. Recipient targeting respects local/provider opt-outs, uses bounded segment batches, and provides authenticated unsubscribe links. Scheduled and uncertain send outcomes remain explicit.
- Client requests carry their displayed view; legacy clients retain preference fallback. Delayed responses cannot restore an ended session. Multipart and staff transports handle expired authentication. Export and scope-picker behavior follow the displayed scope.
- Test setup refuses unsafe database configurations before migrations. Factor throttles isolate accounts. Outbound webhooks pin validated addresses and remote email pictures enforce transfer limits.
- Inventory, phone and automation ordering repairs are tracked individually in the review ledger; do not infer their verification from unrelated suite results.
- Release tooling passes one explicit commit through backend/client builds and uses serialized receive/activation helpers and identity checks. Installed minimum versions are unchanged.
- CommonMark is updated from 2.9.0 to 2.10.1 to address reported parser denial-of-service and XSS advisories. The locked Composer audit reports no advisories; dependency installation and combined backend regressions passed.
- Documentation now has a short entry point, ownership contract and index, with incident history retained. Unused Laravel welcome/Vite scaffolding is removed. Production's Flutter root remains unchanged; the local Laravel root keeps HTTP 200 and identifies the API. Mail templates, website snippet and HR resources are retained.

Validation before integration: combined backend regressions **409 tests/2,167 assertions** with the patched dependency and final schema; full Flutter **1,276 tests passed** and analysis clean; all three deployment shell suites passed. The full pinned Linux gate passed **2,866 backend tests/17,119 assertions**, then repeated all **1,276 Flutter tests** and clean analysis. Both production release markers match the pinned SHA; internal health and public web root return HTTP 200. The Windows manifest matches, and the published installer checksum equals the local artifact.

Publication: web/API and Windows are live; Android signed AAB is built under `Distribution/Android` and still requires Play Console upload/track selection. iOS upload validation and upload succeeded (build 152); macOS validation and upload also succeeded. Apple processing and public review/release are separate from upload. Installed minimum versions were not raised.

Subsequent deployment observation: another active session deployed API commit `c6bb8e55fc8334af99bb4a75466d40b5491184d3` after this release; UI remains `3a595a85`. Its generated status says no gates ran. The passing counts above belong to this reviewed release, and the newer API changes are not claimed as tested by it. The newer deployment was preserved.
