import 'dart:io' show File, Platform;
import 'dart:ui' as ui;

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart' show OffsetLayer;
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';

import 'package:ezbizi_client/core/module_screens.dart';
import 'package:ezbizi_client/main.dart' as app;
// Its own import: main.dart imports scope_switcher.dart but does not re-export
// it, so app.ScopeSwitcher does not resolve. NavigationSidebar is different -
// that one is declared in main.dart itself.
import 'package:ezbizi_client/widgets/scope_switcher.dart' show ScopeSwitcher;

/// App Store screenshots, taken from the real app on a real simulator — or,
/// since 4 Sep 2026, from the real app in a real window on the relay Mac.
///
/// ── Why a test and not a person with a mouse ────────────────────
///
/// Apple wants 1284x2778 for the 6.5" slot, which is an iPhone 14 Plus at 3x.
/// A browser cannot produce that: the web build renders at devicePixelRatio 1,
/// so anything captured there has to be upscaled three times and arrives soft.
/// The simulator renders at 3x natively.
///
/// Driving that simulator is the problem this file solves. `simctl` has no tap
/// command, and AppleScript is refused on the relay Mac — `osascript is not
/// allowed assistive access`, which needs somebody physically at the machine to
/// grant. integration_test drives the app through the Flutter engine instead,
/// so it needs no macOS permission at all.
///
/// The by-product is that this is repeatable. Screenshots for the next release
/// are one command, not an afternoon of cropping.
///
/// ── Desktop (the Mac App Store) ─────────────────────────────────
///
/// integration_test's macOS plugin implements nothing but allTestsFinished, so
/// `takeScreenshot` there is a MissingPluginException (measured 4 Sep 2026),
/// and `screencapture` from an ssh session on the relay fails with "could not
/// create image from display". So on desktop the test renders the root layer
/// itself — the same call matchesGoldenFile makes — and writes the PNG, which
/// it can do because on desktop the test process IS the host. The app is
/// sandboxed, so HOME is its container; scripts/store-screenshots.sh knows
/// where to collect from. The window is sized by EZBIZI_WINDOW (see
/// macos/Runner/MainFlutterWindow.swift) so the PNG lands on a size Apple lists.
///
/// ── Which account ───────────────────────────────────────────────
///
/// The demo tenant, never A1-Group. These images go on a public product page,
/// and the real tenant holds real customers, real jobs and real money. The
/// credentials come in as --dart-define so they are not written down here.
void main() {
  final binding = IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  const email = String.fromEnvironment('SHOT_EMAIL');
  const password = String.fromEnvironment('SHOT_PASSWORD');
  // --probe runs with a placeholder password and is EXPECTED to stay on the
  // sign-in screen; every other run must get past it, and says so below.
  const probe = bool.fromEnvironment('SHOT_PROBE');
  final desktop =
      !kIsWeb && (Platform.isMacOS || Platform.isWindows || Platform.isLinux);

  testWidgets('App Store screenshots', (tester) async {
    expect(email, isNotEmpty, reason: 'pass --dart-define=SHOT_EMAIL=');
    expect(password, isNotEmpty, reason: 'pass --dart-define=SHOT_PASSWORD=');

    app.main();

    // pumpAndSettle is not usable here. This app polls — the clock, the
    // notification bell — so the frame queue never actually empties and
    // pumpAndSettle times out rather than returning. Settling by the clock is
    // the honest way to wait for a screen that never goes quiet.
    Future<void> settle([int seconds = 3]) async {
      final end = DateTime.now().add(Duration(seconds: seconds));
      while (DateTime.now().isBefore(end)) {
        await tester.pump(const Duration(milliseconds: 100));
      }
    }

    Future<void> snap(String name) async {
      if (!desktop) {
        await binding.takeScreenshot(name);
        return;
      }
      final root = tester.binding.renderViews.first;
      final image = await (root.debugLayer! as OffsetLayer).toImage(
        root.paintBounds,
        pixelRatio: root.flutterView.devicePixelRatio,
      );
      final png = await image.toByteData(format: ui.ImageByteFormat.png);
      final home = Platform.environment['HOME'] ?? '.';
      final file = File(
        '$home/store-screenshots/${Platform.operatingSystem}/$name.png',
      );
      await file.parent.create(recursive: true);
      await file.writeAsBytes(png!.buffer.asUint8List());
      // ignore: avoid_print
      print('  wrote ${file.path} (${image.width}x${image.height})');
    }

    // Android renders into a SurfaceView, which the screenshot path cannot read
    // directly — takeScreenshot fails with 'call convertFlutterSurfaceToImage()
    // first'. This swaps the surface for one that can be captured.
    //
    // Android only. iOS neither needs it nor supports it, and calling it there
    // fails the run, so the same file has to ask which platform it is on rather
    // than doing it unconditionally.
    if (!kIsWeb && Platform.isAndroid) {
      await binding.convertFlutterSurfaceToImage();
    }

    await settle(6);
    // The sign-in screen, on every platform.
    //
    // The desktop path skipped it on the reasoning that a login page sells
    // nothing on a Mac listing, which is sound as far as it goes - Apple's own
    // 2.3.3 says screenshots must show the app in use, 'not merely the title
    // art, login page, or splash screen'. The operative word is MERELY: one
    // sign-in frame among ten of the product working is not that, and the iOS
    // listing already carries one. Jose asked for it, so both platforms take it.
    //
    // It must stay FIRST in name and LAST in the order Apple shows them only if
    // you upload it first - so upload 02 onward ahead of it if you would rather
    // the store lead with the dashboard.
    await snap('01-sign-in');

    // ── sign in ───────────────────────────────────────────────────
    final fields = find.byType(TextField);
    expect(fields, findsAtLeastNWidgets(2), reason: 'the sign-in form should be up');

    await tester.enterText(fields.at(0), email);
    await settle(1);
    await tester.enterText(fields.at(1), password);
    await settle(1);

    await tester.tap(find.text('Sign in').last);
    await settle(10);

    // An address used by more than one organization gets a chooser. The demo
    // account should not, but if it does, the demo organization is the one.
    if (find.textContaining('more than one organization').evaluate().isNotEmpty) {
      final demo = find.textContaining('Demo');
      if (demo.evaluate().isNotEmpty) {
        await tester.tap(demo.first);
        await settle(10);
      }
    }

    // ── a run that did not sign in must fail here, not pass ────────
    //
    // snap() captures whatever is on screen. A wrong or missing password
    // therefore produces a full set of plausible files - right names, right
    // size, right stamp - every one of them the login form, and the driver
    // still reports "All tests passed". That happened on 2026-09-04 and two
    // such frames overwrote a real set on disk before anyone opened them.
    if (!probe) {
      expect(find.textContaining('do not match'), findsNothing,
          reason: 'the server rejected the credentials - check screenshot.env on the Mac');
      expect(find.text('Sign in'), findsNothing,
          reason: 'still on the sign-in screen after signing in - nothing after this frame would be real');
    }
    // ── widen the view to the whole organization ──────────────────
    //
    // A fresh sign-in lands on whatever scope the account was last in, and for
    // the demo account that is one franchise: the first macOS run captured
    // 'Northgate Garage Doors > Northgate - Orlando', so the dashboard read
    // 'Nobody on the books yet' and Customers listed three of the tenant's
    // thirteen. The data was never thin; the window onto it was.
    //
    // The picker's widest row is labelled with the organization's own name
    // (scope_picker_panel.dart:81), so tapping the entry containing 'Demo'
    // selects it. Best-effort: if the switcher or the row is not found the run
    // continues and captures whatever scope it is in, because a narrow
    // screenshot beats no screenshot.
    try {
      final switcher = find.byType(ScopeSwitcher);
      if (switcher.evaluate().isNotEmpty) {
        await tester.tap(switcher.first);
        await settle(6);
        final org = find.textContaining('Demo');
        if (org.evaluate().isNotEmpty) {
          await tester.tap(org.last);
          await settle(10);
        } else {
          // ignore: avoid_print
          print('  scope: no organization row found, keeping the current view');
        }
      }
    } catch (e) {
      // ignore: avoid_print
      print('  scope: could not widen the view ($e) - continuing');
    }

    await snap('02-dashboard');

    // ── one screenshot per module worth showing ───────────────────
    //
    // On a phone, opened from the drawer by label, because that is how a
    // person gets anywhere. On desktop the sidebar's labels come from the
    // server, so the item is found by the icon moduleScreens gives it, inside
    // the sidebar — the dashboard draws the same icons on its cards.
    //
    // Each is tried rather than asserted: a module that is not reachable in
    // the demo tenant should not fail the whole run and leave you with nothing.
    // `tab` opens a named tab inside the module before the frame is taken.
    //
    // Inspection is why this exists. It opens on New Inspection, which is a
    // search box over nothing until somebody types, so the frame came back ~85%
    // empty black and was dropped from both the macOS and iPad sets. The
    // Inspection session filed three reports on the demo tenant on 2026-09-04,
    // and that ALONE would not have changed the picture by one pixel: the
    // reports live under Generated Reports and the default tab would still have
    // photographed the same empty search box. Data and the view onto it are two
    // different fixes and this is the second one.
    //
    // Best-effort, like everything else here: a tab that is not found prints a
    // line and the module's default view is captured instead, because a weak
    // frame beats a failed run.
    Future<void> shoot(String key, String label, String name, {String? tab}) async {
      try {
        if (desktop) {
          final icon = moduleScreens[key]?.icon;
          final item = icon == null
              ? null
              : find.descendant(
                  of: find.byType(app.NavigationSidebar),
                  matching: find.byIcon(icon),
                );
          if (item == null || item.evaluate().isEmpty) {
            // ignore: avoid_print
            print('  skipped $key — not in the sidebar');
            return;
          }
          await tester.tap(item.first);
        } else {
          final drawer = find.byTooltip('Open navigation menu');
          if (drawer.evaluate().isNotEmpty) {
            await tester.tap(drawer.first);
            await settle(2);
          }
          final item = find.text(label);
          if (item.evaluate().isEmpty) {
            // ignore: avoid_print
            print('  skipped $label — not in the drawer');
            return;
          }
          await tester.tap(item.first);
        }
        await settle(6);

        if (tab != null) {
          final t = find.text(tab);
          if (t.evaluate().isEmpty) {
            // ignore: avoid_print
            print('  $name: no "$tab" tab, keeping the default view');
          } else {
            await tester.tap(t.first);
            await settle(6);
          }
        }

        await snap(name);
      } catch (e) {
        // ignore: avoid_print
        print('  skipped $label — $e');
      }
    }

    await shoot('customers', 'Customers', '03-customers');
    await shoot('jobs', 'Jobs', '04-jobs');
    await shoot('inspection', 'Inspection', '05-inspection', tab: 'Generated Reports');
    await shoot('inventory', 'Inventory', '06-inventory');
    await shoot('fleet_management', 'Fleet Management', '07-fleet');
    // Scheduling on every platform: the week view is the phone's best frame.
    await shoot('scheduling', 'Scheduling', '08-scheduling');
    if (desktop) {
      // A Mac listing takes ten, and the wide window has room for desk work.
      await shoot('phone', 'Communication', '09-communication');
      await shoot('marketing', 'Marketing', '10-marketing');
    }

    // HR last, and on every platform.
    //
    // It was inside the desktop block, which is why the first iPad run stopped
    // at seven frames with nothing to show for the strongest module in the demo
    // tenant: eight people on the books, a chart, and real contract copy. That
    // was the best of the eleven macOS frames and the tablet could not reach it.
    //
    // Kept last so the desktop numbering does not move. The label matters here
    // in a way it does not on desktop: the desktop path finds the sidebar item
    // by moduleScreens' icon, but the drawer path matches rendered TEXT, and the
    // server calls this module 'Human Resources', not 'HR'. The old 'HR' string
    // was harmless only because the desktop path never read it. If the server
    // ever renames it, shoot() prints a skip line and the run still produces
    // every other frame.
    await shoot('hr', 'Human Resources', '11-hr');

    // Newer modules with demo data. A set is capped at ten per device, so a
    // run shoots more than ships; the frames are chosen when uploading.
    await shoot('leads', 'Leads', '12-leads');
    await shoot('reviews', 'Reviews', '13-reviews');
    await shoot('reports', 'Reports', '14-reports');
  });
}
