import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';

import 'package:ezbizi_client/main.dart' as app;

/// App Store screenshots, taken from the real app on a real simulator.
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
/// ── Which account ───────────────────────────────────────────────
///
/// The demo tenant, never A1-Group. These images go on a public product page,
/// and the real tenant holds real customers, real jobs and real money. The
/// credentials come in as --dart-define so they are not written down here.
void main() {
  final binding = IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  const email = String.fromEnvironment('SHOT_EMAIL');
  const password = String.fromEnvironment('SHOT_PASSWORD');

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

    await settle(6);
    await binding.takeScreenshot('01-sign-in');

    // ── sign in ───────────────────────────────────────────────────
    final fields = find.byType(TextField);
    expect(fields, findsAtLeastNWidgets(2), reason: 'the sign-in form should be up');

    await tester.enterText(fields.at(0), email);
    await settle(1);
    await tester.enterText(fields.at(1), password);
    await settle(1);

    await tester.tap(find.text('Sign in').last);
    await settle(10);
    await binding.takeScreenshot('02-dashboard');

    // ── one screenshot per module worth showing ───────────────────
    //
    // Opened from the drawer, because on a phone that is how a person gets
    // anywhere. Each is tried rather than asserted: a module that is not
    // reachable in the demo tenant should not fail the whole run and leave you
    // with nothing.
    Future<void> shoot(String label, String name) async {
      try {
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
        await settle(6);
        await binding.takeScreenshot(name);
      } catch (e) {
        // ignore: avoid_print
        print('  skipped $label — $e');
      }
    }

    await shoot('Customers', '03-customers');
    await shoot('Jobs', '04-jobs');
    await shoot('Inspection', '05-inspection');
    await shoot('Inventory', '06-inventory');
    await shoot('Fleet Management', '07-fleet');
  });
}
