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
  `--display=3` inner (2007×2853 px). Fold/unfold is a Device Hub control,
  not a simctl command (`screenConfig power` crashed SpringBoard).
- ssh processes on the Mac hold Accessibility: `~/ezbizi-mobile/probe/press
  <pid> "Window>iPhone Duo (iOS 27.2)"` presses a Device Hub menu item, `ax2`
  and `hit` read its controls. All of it needs the Mac's screen unlocked: a
  locked screen (`probe/lock` prints `locked=1`) hides every window.

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
| 14 | Every side panel (Book a job, Invite somebody, ~30 more), keyboard up | Save / Create / Book job buttons hidden under the keyboard (Android shots aa-01, aa-12) | `showSidePanel` is a dialog route; nothing resized it for the keyboard | Panel content padded by max(keyboard, home bar); side_panel_test. Verified on the iPhone: Book a job, Filters, Add customer, Invite somebody, New report, Add company all keep their footer above the keyboard | FIXED 8ed18b9fd |
| 15 | Inventory › Orders header | "Add purchase order" scaled small beside the title | The header scales an action down to its room (≥140) rather than wrapping | The header measures the action: beside the title only if it fits there at 90% or more, else its own line at full size (`_TitleThenActions`); module_page_header_test | FIXED 13d928c79 |
| 16 | Android emulator | Gboard showed a floating toolbar instead of a keyboard, so nothing folded | Emulator keyboard mode, not the app | `settings put secure show_ime_with_hard_keyboard 1` + cold restart | RIG |
| 17 | Location prompt on first Android launch | "Allow EzBizi to access this device's location?" | Field tracking asks at launch | Denied for the test account (so it does not show on Field's map) | RIG |
| 18 | Work Hours › General (requests) | "BOTTOM OVERFLOWED BY 21 PIXELS"; request text ~80 px beside its buttons | Requests card grows unbounded above the week; Row with buttons | Card capped at 45% and scrolls; buttons under text below 520 | FIXED cb6337bb5 |
| 19 | Work Hours › General, Inventory › Orders (iPhone Duo) | Overflow 20 px / 80 px | Fixed header Column above the list | HeaderAboveList (header scrolls with the list on phones) | FIXED 2b7e383ed |
| 20 | Empty lists inside HeaderAboveList (iOS) | "This part of the screen could not be drawn" (Stack Overflow) on an empty Orders list | EmptyState's scroll view took the NestedScrollView's primary controller | EmptyState `primary: false` | FIXED 8f8c15681 |
| 21 | Email › Compose (phone) | Compose first opens a ~40-address "From" popup with no search, covering the screen | PopupMenu of every mailbox | Email made it the searchable "Send from" picker (dddf73460, session/email) | FIXED BY EMAIL |
| 22 | Review side effect | My action pass tapped Inspection › Builder › "Add a screen", which creates and autosaves at once: an empty "Screen 5" in the Chimney Inspection draft | A create button in a capture list | Deleted in the app (draft back to 4 screens, version 6 still published); creates removed from the list; RetardMoments | REVERTED |
| 23 | Inventory › Orders › Add purchase order (phone) | "Stack Overflow" in NestedScrollView.setParent | My 2b7e383ed put a HeaderAboveList inside the one InventorySplitScaffold already draws | Orders on a phone is one ListView (tiles and search are its first rows); HeaderAboveList never nests a second NestedScrollView | FIXED ce418069c (0.0.0638; 0637 web carried it ~10 min, native never) |
| 24 | Large text (iOS Dynamic Type XXL 1.24x, AX-medium 1.65x), Duo | Overflows: Marketing week day cell 2 px, Guidelines card 6 px, Financials tiles 21 px, Services card 24 px, Settings header 45 px, Phone banners 127 px | Fixed heights / fixed headers | Heights scale with textScaler; Settings HeaderAboveList; banners capped 40%. AX-extra-large (2.35x): no overflows left | FIXED e6663a7fa |
| 25 | Dark mode (account theme Dark), every module on the Duo | No unthemed panels or unreadable text found | The app follows the person's theme setting, not the phone's | — | PASSED |
| 26 | Your account › any row's page (e.g. Appearance), Duo | Card ran under the clock column on the right | Pushed Scaffold body had no SafeArea | SafeArea(top: false) around the body | FIXED 5d2934ca1 |
| 27 | Phone on its side (Android 923×411), whole app | Desktop sidebar drawn; its bottom rows left the module list −54 px | Shell chose desktop by width only | A touch device under 560 tall keeps the phone shape | FIXED cce774823 |
| 28 | Phone on its side, 10 modules (Leads thread, Customer Experience, Marketing, Communication, Email, Task Board, Services, Work Hours, AI Assistance, Reports) | Fixed headers overflowed 6–158 px and left the list 0 px | The page under the bars is ~200 px | Module page keeps 420 px and scrolls as a whole (same scroll view at every height, so rotating or typing never rebuilds the screen); HeaderAboveList scrolls its header in short windows too; Reviews drops its desktop strip in a short pane | FIXED 74b28cad5 |
| 29 | Phone on its side, chrome | Company row + 72 px bottom bar left ~100 px of page | — | Company picker moves into the app bar when it is 700+ wide; bottom bar draws labels beside icons at 48 px | FIXED 74b28cad5 |
| 30 | Leads list, narrow pane (landscape) | The lead's name got 0 px: AI and "viewing" marks took the line (9.9 px overflow) | Marks were not flexible | Marks capped at 40% of the line and clipped; name keeps the rest | FIXED 74b28cad5 |
| 31 | iPhone on its side (874×402), Marketing › Social Media › calendar | Month grid overflowed 4 px and its card 26 px | Wide enough for the desktop grid, ~220 px tall | Below 480 tall the planner draws the phone's week and day list | FIXED 326af248a |
| 32 | Guidelines cards, 3 across (iPhone landscape; desktop with long titles too) | Card overflowed 14 px | Budget held a two-line title OR a two-line summary, not both | Text budget 118 → 132 | FIXED 326af248a |
| 33 | iPhone and Android landscape after 27–32 | All 27 modules open with no layout errors on both | — | — | PASSED |
| 34 | Duo on its side (593 pt beside its status column), bottom bar | "Schedu…" | The 52 px icon pill stayed in the 48 px side-by-side bar | Pill 36 px sideways; phone_bottom_bar_test | FIXED 05769ad70 |
| 35 | Every form on the iPhone (number pad: Year, odometer, amounts; multi-line notes) | The keyboard could not be put away: the number pad has no Done key, and a tap outside a box does nothing on a phone | Flutter drops focus on a tap outside only on desktop | Dragging any page closes the keyboard (`dragClosesKeyboard`, the app's ScrollBehavior); drag_closes_keyboard_test. Verified on the iPhone (vehicle › Year) | FIXED (next sha) |
