# Mobile review — findings

Owner: Mobile UI-UX Optimization (`local_a0957101-5096-4c54-adce-f78bd1b0180e`),
branch `session/mobile-uiux`. Jose's order (2026-11-24): review every screen,
option and click on iPhone and iPhone Duo, on the Mac, visibly.

## Rig (the Mac relay, `ssh mac`)

- Simulator.app, visible: iPhone 17 (iOS 27.0) `EE43C57B…` and iPhone Duo
  (iOS 27.2) `FFC16F00…`.
- Source copies: `~/ezbizi-mobile/client` (Xcode 26.0.1, the release pin) and
  `~/ezbizi-mobile27/client` (Xcode 27.2). Neither is `~/ezbizi-build`, the
  release relay's tree.
- `tmux` session `mui`: window `iphone` and window `duo`, each running
  `flutter run` (debug, `API_BASE=https://app.ezbizi.com`) for hot reload.
- `~/ezbizi-mobile/drive.py <iphone|duo> texts|tap|taptext|drag|type|back|shot`
  drives the app via Flutter's VM service (no macOS accessibility grant needed).
  Screenshots go to `~/ezbizi-mobile/shots/`.
- The Duo has two displays: `--display=1` cover (1398×2034 px) and
  `--display=3` inner (2007×2853 px). Fold/unfold is a Simulator menu item,
  not scriptable via simctl.

## Findings

Status: FIXED (sha) · OPEN · NEEDS-DECISION.

| # | Where | What | Cause | Fix | Status |
|---|---|---|---|---|---|
| 1 | Every picker / search dropdown, form panels | Opening one raised the phone keyboard and covered it (Jose 11-24) | 87 literal `autofocus: true` | `autofocusOnOpen` (lib/core/soft_keyboard.dart) on 34 search/form fields; literal kept only for codes, passwords, renames, replies; guard test | FIXED 62020272e |
| 2 | Every module, keyboard up | "When the keyboard is on, you can barely see stuff" (Leads › conversation: one line) | Company row, state strip, page title stay on screen over a halved viewport | Below 900 wide the shell folds them while the keyboard is up (`_typing`, `_HiddenWhileTyping`); top_bar_test | FIXED 7557319af |
| 3 | iPhone Duo, whole app | Content drawn ~80% wide, blank strip on the right with the status bar over it (Reports › Jobs looked clipped) | Built with the iOS 26 SDK (Xcode 26.0.1 pin): iOS 27 runs it in the legacy compatibility window. Built with Xcode 27.2, the Flutter view is the full display (466×678 pt; 84 pt right safe area for the status column) | iOS builds need the iOS 27 SDK; the pin is for the macOS lipo bug (flutter#188461). Verifying the iOS Xcode 27 build screen by screen, then a desk change to build iOS with Xcode 27 | OPEN |
| 4 | Relay tooling | `flutter run` hung at "Running Xcode build" forever | `impellerc` (Flutter cache, `darwin-x64/impellerc`, arm64) never got past `_dyld_start` when launched over ssh; a byte-identical fresh copy runs | Replaced with the fresh copy (original kept at `~/ezbizi-mobile/impellerc.orig`). Release iOS/macOS builds use the same binary | FIXED on the Mac |
| 5 | Sign-in (phone) | With the keyboard up the EzBizi wordmark slid under the Dynamic Island (shot s02-login-kbd) | `_FormPanel` scrolls with no SafeArea | SafeArea around the phone form panel (auth_shell.dart) | FIXED (next sha) |
| 6 | Guard vs main | Task Board's new update reply box (ddacafb9d) adds a literal autofocus | Typing-only (opened by Reply) | Allowance 7 → 8 in soft_keyboard_guard_test | FIXED (next sha) |
