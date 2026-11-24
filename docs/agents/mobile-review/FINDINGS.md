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
| 3 | iPhone Duo, whole app | Content drawn ~80% wide, blank strip on the right with the status bar over it (Reports › Jobs looked clipped) | Built with the iOS 26 SDK (Xcode 26.0.1 pin): iOS 27 runs it in the legacy compatibility window. Built with Xcode 27.2, the Flutter view is the full display (466×678 pt; 84 pt right safe area for the status column) | Already done by the desk: iOS builds with Xcode 27.2 since 0.0.0630 (eb9742ad8). Checked in the IPAs: 0630/0631 `DTXcode 2720`, `iphoneos27.2`; 0625 `2601`, `iphoneos26.0`. The report came from an older installed build | FIXED 0.0.0630 |
| 4 | Relay tooling | `flutter run` hung at "Running Xcode build" forever | `impellerc` (Flutter cache, `darwin-x64/impellerc`, arm64) never got past `_dyld_start` when launched over ssh; a byte-identical fresh copy runs | Replaced with the fresh copy (original kept at `~/ezbizi-mobile/impellerc.orig`). Release iOS/macOS builds use the same binary | FIXED on the Mac |
| 5 | Sign-in (phone) | With the keyboard up the EzBizi wordmark slid under the Dynamic Island (shot s02-login-kbd) | `_FormPanel` scrolls with no SafeArea | SafeArea around the phone form panel (auth_shell.dart) | FIXED a2b146991 |
| 6 | Guard vs main | Task Board's new update reply box (ddacafb9d) adds a literal autofocus | Typing-only (opened by Reply) | Allowance 7 → 8 in soft_keyboard_guard_test | FIXED a2b146991 |
| 7 | Leads › conversation, keyboard up (Jose's example) | ~2 messages visible | App toolbar (56 pt) and the lead's Status/Book/⋯ row stayed; the lead's "details fold while typing" rule read `MediaQuery.viewInsets`, which a Scaffold body sees as 0, so it never fired | Toolbar collapses to 0 while typing on a phone; the moves row folds; `keyboardUp()` reads the window (shots k03 → k05) | FIXED ef0299abe |
| 8 | Customers, Automations headers | Title cut to "Cust…", "Autom…" beside the primary button | Header budgeted 46 px for the (?) and video buttons; they are 86 | Budget 86; test with both buttons at 402 wide | FIXED ef0299abe |
| 9 | Project Management › Unscheduled, keyboard up | "BOTTOM OVERFLOWED BY 35 PIXELS" stripe | `_fills()` decides by screen height; totals + filters left the table −35 px | Totals strip folds while typing (FoldWhileTyping) | FIXED 53dd63e93 |
| 10 | Jobs, keyboard up | One job visible while searching | Money tiles (2 rows) + status chips above the search | Both fold while typing | FIXED 53dd63e93 |
| 11 | Communication › Phone, Email › Inbox, keyboard up | Keypad / Compose floating buttons cover the list over the keyboard | — | Fold while typing | FIXED 53dd63e93 |
| 12 | Communication › Phone (simulator only) | Red "could not connect … missing VoIP push token" banner | Simulators have no push token | None (not a defect on a device) | NOT A BUG |
| 13 | Test account side effects | The new account was clocked in (from the web invite's clock-in page) and "Taking calls" | Defaults for a new Administrator | Clocked out, calls off on the test account | DONE |
| 14 | Every side panel (Book a job, Invite somebody, ~30 more), keyboard up | Save / Create / Book job buttons hidden under the keyboard (Android shots aa-01, aa-12) | `showSidePanel` is a dialog route; nothing resized it for the keyboard | Panel content padded by max(keyboard, home bar); side_panel_test | FIXED 8ed18b9fd |
| 15 | Inventory › Orders header | "Add purchase order" scaled small beside the title | The header scales an action down to its room (≥140) rather than wrapping | Open: wrap to its own line when the scale would drop below ~0.9 | OPEN |
| 16 | Android emulator | Gboard showed a floating toolbar instead of a keyboard, so nothing folded | Emulator keyboard mode, not the app | `settings put secure show_ime_with_hard_keyboard 1` + cold restart | RIG |
| 17 | Location prompt on first Android launch | "Allow EzBizi to access this device's location?" | Field tracking asks at launch | Denied for the test account (so it does not show on Field's map) | RIG |
