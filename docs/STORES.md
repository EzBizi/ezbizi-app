# App builds, distribution and store submissions

Repository behavior verified 2026-09-08; Windows signing and the Play upload re-checked against `scripts/release.sh` on 2026-11-13. This document describes what the build scripts do; it does not assert current public store availability, account approval or successful upload of a particular release. Record those outcomes with a dated console/release result on the work board and in [STATUS](EZBIZI-STATUS.md).

Developer accounts and connections owned by EzBizi use **development@ezbizi.com**, after that Workspace mailbox exists. Use the organization identity and existing registered app records. Customer OAuth remains the customer's identity. Keep signing credentials and reviewer passwords outside the repository, board and chat.

## Build and publication matrix

| Target | Current repository identity | Build artifact | Publication implemented |
|---|---|---|---|
| Windows | EzBizi | Flutter release folder, packaged as an Inno Setup `.exe` | `release.sh` uploads versioned/current installers and then `windows.json` to the website download directory. This is the desktop updater's channel; MSIX/Partner Center publication is not implemented here. |
| Android | `com.ezbizi.app`, display label EzBizi | Release `.aab`, signed with the configured local upload key | The release saves the AAB under `Distribution/Android`, then `scripts/play-upload.sh` uploads it to the Play **production** track (since 2026-11-02, impersonating `play-publisher@ezbizi-506117` through the caller's gcloud account). Play still reviews every production release. A failed upload warns and leaves the AAB for a manual upload. |
| iOS | `com.ezbizi.app`, display name EzBizi | Signed `.ipa` exported on the Mac relay when the correct distribution identity/profile is available | `build-app.sh --upload` invokes the relay upload helper; `release.sh` requests this upload. Upload submits a build to App Store Connect for processing/testing, not public review or release. |
| macOS | `com.ezbizi.app`, product name EzBizi | Signed archive exported to `.pkg` on the Mac relay | With `--upload`, the relay helper submits the package to App Store Connect. This does not submit the app version for public review or establish store availability. |

Source of truth: [release orchestration](../scripts/release.sh), [native builds](../scripts/build-app.sh), [Windows installer](../scripts/windows-installer.iss), [desktop updater](../client/lib/core/desktop_update.dart), [Android configuration](../client/android/app/build.gradle.kts), [Android label](../client/android/app/src/main/AndroidManifest.xml), [iOS project](../client/ios/Runner.xcodeproj/project.pbxproj), [iOS display name](../client/ios/Runner/Info.plist), and [macOS identity](../client/macos/Runner/Configs/AppInfo.xcconfig).

*(Superseded 2026-10-26, `06f85ac8a`:)* The Windows installer currently has no signing step. Do not describe it as Microsoft-signed or assume a certificate purchase is required by this workflow. The A1 Chimney YubiKey is not used here. **Now:** `release.sh` signs `ezbizi.exe` and the installer with the EzBizi LLC DigiCert certificate on the SafeNet eToken (`signtool /n "EzBizi LLC"`); if the token is absent or its password is not entered within 5 minutes, the build ships **unsigned** with a warning, so check the release output before calling a build signed. Android's local upload key is separate from any app-signing key managed in Play Console; the repository does not establish that console configuration.

Standalone Apple build helpers have unsigned/ad-hoc compile fallbacks when signing identities are absent. An unsigned compile check is not an installable/store-upload result. The release orchestrator performs signing preflights; report the actual signed artifact and upload outcome rather than treating any successful compilation as distribution.

Artifacts go to the main checkout's `Distribution/Windows`, `Distribution/Android` and `Distribution/Apple`, including when the release runs from a pinned worktree. `client/VERSION` and the pinned commit supply release identity. Do not take the product version from the template version in `client/pubspec.yaml`.

## Console work, separate from building

These are checks for the next submission, not claims that every item is currently missing. Inspect existing console records and the [work board](http://localhost:8787) before creating duplicate accounts, identifiers or listings.

- **Apple:** verify organization membership, registered app record/platform, team, signing profile and upload credentials. After upload, confirm processing, resolve any required compliance answers, select the intended build, update metadata/screenshots/privacy answers and reviewer access, then explicitly submit the app version for review when authorized. Record review and public-release outcomes separately from TestFlight/build upload.
- **Google Play:** verify the organization account and existing `com.ezbizi.app` record, upload/app-signing configuration, policy declarations and selected testing/production track. `release.sh` uploads the signed AAB to production through `scripts/play-upload.sh`; upload by hand only when that step warns. Track review and production rollout explicitly; a local AAB does not complete them.
- **Microsoft:** the current release path is website `.exe` distribution. Any Microsoft Store listing, packaging/signing route or Partner Center submission is separate work, to be scoped and verified before implementation. Do not treat the old MSIX plan as an existing capability.
- **All submissions:** maintain usable reviewer access with representative tenant data and a valid subscription. Check privacy, support and marketing links; answer data declarations from actual collection and use, including the `HoldsPersonalData` inventory. Keep screenshots consistent with the submitted build.

Fees, enrollment requirements, test-track rules, screenshot dimensions and review policies are deliberately not quoted as current facts here. Verify the relevant provider's current requirements and the account's actual console state when doing that work; no provider-console checks were performed for this documentation update.

The previous document was an early enrollment plan. Its claims that identifiers/display names remained Flutter defaults, Apple releases were always unsigned, and Windows shipped through MSIX are superseded by the repository evidence above. Existing incident and submission records remain in [build history](EZBIZI-BUILD-LOG.md) and the [documentation index](README.md).

## Installed-client compatibility

The client sends platform/version information and the server supports a minimum version per platform through the control plane. **Do not set or raise that minimum as part of these steps.** Follow [active constraints](ACTIVE-CONSTRAINTS.md): a database floor can lock out installed clients and reviewers without any code deployment.

The middleware's current compatibility behavior is defined in [EnforceMinimumClientVersion](../app/Http/Middleware/EnforceMinimumClientVersion.php). A build upload or review delay must not be worked around by forcing installed users onto an unavailable version. API deployment precedes new client publication; release gates, SHA checks and partial-failure handling are documented in [DEPLOY](DEPLOY.md).
