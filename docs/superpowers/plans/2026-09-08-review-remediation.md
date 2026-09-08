# Review remediation implementation plan

> Execute with subagents owning disjoint files; parent coordinates, verifies, integrates and deploys. User authorized implementation and deployment on 2026-09-08.

**Goal:** Resolve all 28 findings in `docs/PROJECT-REVIEW-2026-09-08.md`, consolidate durable ownership rules and release documentation, and deploy the verified result.

**Architecture:** Preserve explicit tenant identity. Bind current client requests to an explicitly validated view with legacy preference fallback. Separate inherited visibility from downward-only write authority. Persist delayed-work identity and scope; serialize state transitions at their parent records. Preserve compatibility with installed clients; do not raise minimum versions.

**Constraints:** No production DB testing, real provider sends, credentials in repo/chat, or server edits as shipping. Isolated worktree `H:/Development/wt/review-remediation`; each backend runner uses a distinct local `ezbizi_testing_review_*` schema. No shared vendor junctions. Parent alone commits shared worktree. Do not touch unrelated main-tree edits.

- [x] Phase A — newsletter correctness/security (2–5,12–15,19–20): persist selected connection; atomic one pending send; scoped write policy; signed/provider recipient unsubscribe; provider suppression and targeted recipient delivery; tenant-aware sweep failures; exception repair; scope-before-pagination; HTML preservation. Add feature tests reproducing each failure including two company connections and lower-view mutation. Own Newsletter controllers/models/support/jobs/command and migrations/tests only.
- [x] Phase B — view/session consistency (6,9,23–26): new `X-EzBizi-View` request header bound to client state; backend validates header independently of saved preferences; legacy absence falls back; stale session-response generation checks; common 401 handling including multipart/staff; orphan roots; view-constrained export. Regression tests with sibling companies, two devices and out-of-order responses. Own client API/session/scope picker + ViewScope/Exporter and corresponding tests.
- [x] Phase C — safety/auth/network/release (1,7–8,10–11): guard effective DB before migrations; independent per-account factor throttle; pin webhook DNS; enforce streamed image cap; pass explicit release SHA from orchestrator to deploy and reject mismatch. First reproduce with safe tests; no production API probing. Own TestCase, auth, fetchers, release/deploy scripts and associated tests.
- [x] Phase D — workflow ordering (16–18,21–22): lock inventory parent across cancellation/movement; monotonic phone callbacks and durable dedup; immutable automation event scope for deletions; retry only retryable failed actions with durable action outcomes and stable per-action idempotency. Targeted tests preserve previous successful action outcomes and terminal phone state.
- [x] Phase E — docs/cleanup (27–28): concise StartUp/README and durable ownership guide; documentation index; release-policy/version source corrections; retain incident history in archive; release notes referencing this review; remove only starter welcome/Vite pipeline, preserve email templates/snippets. Report each finding's resolution and test evidence. Reconcile existing main documentation edits rather than overwrite them.
- [x] Phase F — independent review and verification: inspect complete diff for accidental changes/secrets; lint/PHP analysis; targeted MySQL regressions followed by full isolated backend suite; Flutter analyze/test; shell release identity tests; fix review failures. No claim of a pass without captured results.
- [x] Phase G — integrate/deploy: verify current main and merge preserving concurrent work; commit only after review; run release with gates and explicit one-SHA behavior, API before clients; verify serving release/artifact identity and health. Mark board and report fixed/deployed versus actual external blockers; update STATUS/TODO/log sources without duplicate moving numbers.

This plan uses the ranked audit as its detailed acceptance specification. Each implementation report must list changed paths, regression commands/results, remaining issues and compatibility decisions. Do not weaken controls or silently mark a finding resolved when only documented.

## Release checkpoint — 2026-09-08

- Version `0.0.0152`, corrected release commit `3a595a85e0f56865c5ddbb2297e01dd32f72a9bf`, is pinned in `H:/Development/wt/release-3a595a85e0f5`.
- The first completed Linux gate on the earlier commit found an invalid UTF-8 message and missing newsletter dashboard icon. Both were repaired and targeted tests passed; a PHP source-encoding regression was added. No production activation occurred during that failed gate.
- The full gate passed 2,866 backend tests/17,119 assertions and 1,276 Flutter tests plus analysis. Web/API and Windows publication is verified; signed Android AAB is built and iOS upload succeeded. macOS validation and upload also succeeded. The coordinator exited successfully; its temporary-worktree cleanup reported a Windows deletion warning after release completion. The release uses `--no-bump --gates` with SSH keepalives.
- Other sessions are editing main. Keep their work intact; a later main commit is not automatically part of this pinned release.
- After interruption, inspect the live `.ezbizi-release-sha`, website installer manifest and exact-version native artifacts before retrying. Resume missing targets from the verified commit; do not assume an uploaded Apple build can be uploaded again or bump a version merely to resume.

## Historical restart checkpoint (superseded by release checkpoint) — 2026-09-08 08:35 America/New_York

PC restarted during implementation. All work above is in this worktree, uncommitted. Main remains separate with four pre-existing documentation edits; do not overwrite them. No deployment has occurred.

- Phase A code is largely written. `newsletter-test-result.json` records the last interrupted-stage result: one failing sending-edit/delete guard test (5/6 passed). Resume full newsletter tests; do not treat that scratch JSON as a release result or commit it.
- Phase B request header/session/export code is written. Parent independently verified `RequestViewTest` (5 tests, 26 assertions) before restart. Client agent reported 11 new/existing expiry tests passed; final expanded checks remain. Supplemental `OwnedScopeWriteTest` exists; `OwnedScope` helper and catalogue policy adoption still to implement.
- Phase C guard/auth/network/release code is written. Prior agent reported guard 5, auth 35 and transport 3 tests passed. Parent rerunning after restart. `tests/Shell/release-identity.sh` passed after restart with network commands replaced by test stubs.
- Phase D inventory/phone/automation work not started before restart; new workflow agent assigned.
- Phase E documentation restructuring, starter cleanup, changelog and final resolution table not started.
- Vendor is a real copy, not a junction. `.env` is synthetic, local-only and ignored. Test schemas are `ezbizi_testing_review_{newsletter,client,security,workflow,full}` on 127.0.0.1. Each runner must explicitly name its own schema. Local MySQL user process was restarted and schemas verified after reboot.
- Test runtime: PHP `C:/Users/jose/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe/php.exe`; Flutter `H:/flutter/bin/flutter.bat`; shell `C:/Program Files/Git/bin/bash.exe` (not WSL bash).
