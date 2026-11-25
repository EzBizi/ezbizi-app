// App Store screenshots for EzChat, the chat sister app, on a simulator.
// Run by scripts/store-screenshots.sh --ezchat --device <udid> <set>.
//
// Signed in as the demo tenant (SHOT_EMAIL / SHOT_PASSWORD from the relay's
// screenshot.env), never a real company: these go on a public product page.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';

import 'package:ezbizi_client/main_ezchat.dart' as app;

void main() {
  final binding = IntegrationTestWidgetsFlutterBinding.ensureInitialized();
  const email = String.fromEnvironment('SHOT_EMAIL');
  const password = String.fromEnvironment('SHOT_PASSWORD');

  testWidgets('EzChat App Store screenshots', (tester) async {
    expect(email, isNotEmpty, reason: 'pass --dart-define=SHOT_EMAIL=');

    // The app polls, so it never settles; wait by the clock.
    Future<void> settle([int s = 3]) async {
      final end = DateTime.now().add(Duration(seconds: s));
      while (DateTime.now().isBefore(end)) {
        await tester.pump(const Duration(milliseconds: 100));
      }
    }

    // App errors are logged, not fatal; a harness expect() still fails.
    final testOnError = FlutterError.onError;
    FlutterError.onError = (d) {
      if (d.exception is TestFailure) return testOnError?.call(d);
      // ignore: avoid_print
      print('  app error, run continues: ${d.exceptionAsString().split('\n').first}');
    };

    await app.main();
    await settle(6);

    final fields = find.byType(TextField);
    expect(fields, findsAtLeastNWidgets(2), reason: 'the sign-in form should be up');
    await tester.enterText(fields.at(0), email);
    await tester.enterText(fields.at(1), password);
    await tester.tap(find.text('Sign in').last);
    await settle(10);
    expect(find.text('Sign in'), findsNothing, reason: 'still on sign-in');

    await binding.takeScreenshot('01-chats');

    // The first conversation in the list.
    final tiles = find.byType(ListTile);
    if (tiles.evaluate().isNotEmpty) {
      await tester.tap(tiles.first);
      await settle(5);
      await binding.takeScreenshot('02-thread');
      try {
        await tester.pageBack();
      } catch (_) {} // a wide layout shows the thread beside the list
      await settle(3);
    }

    await tester.tap(find.text('Calls').last);
    await settle(5);
    await binding.takeScreenshot('03-calls');

    FlutterError.onError = testOnError;
  });
}
