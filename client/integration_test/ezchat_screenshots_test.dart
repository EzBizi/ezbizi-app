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
    addTearDown(() => FlutterError.onError = testOnError);
    FlutterError.onError = (d) {
      if (d.exception is TestFailure) return testOnError?.call(d);
      // ignore: avoid_print
      print('  app error, run continues: ${d.exceptionAsString().split('\n').first}\n${d.stack.toString().split('\n').take(8).join('\n')}');
    };

    await app.main();
    // Cold start on the iPad sim is slow; wait for the button, not the clock.
    for (var i = 0; i < 30 && find.text('Sign in').evaluate().isEmpty; i++) {
      await settle(1);
    }

    final fields = find.byType(TextField);
    expect(fields, findsAtLeastNWidgets(2), reason: 'the sign-in form should be up');
    await tester.enterText(fields.at(0), email);
    await tester.enterText(fields.at(1), password);
    final signIn = find.text('Sign in');
    if (signIn.evaluate().isEmpty) {
      // ignore: avoid_print
      print('  no Sign in; texts: ${find.byType(Text).evaluate().map((e) => (e.widget as Text).data).take(30).toList()}');
    }
    await tester.tap(signIn.last);
    await settle(10);
    expect(find.text('Sign in'), findsNothing, reason: 'still on sign-in');

    Future<void> waitFor(Finder f) async {
      for (var i = 0; i < 20 && f.evaluate().isEmpty; i++) {
        await settle(1);
      }
      // ignore: avoid_print
      if (f.evaluate().isEmpty) print('  missing $f; texts: ${find.byType(Text).evaluate().map((e) => (e.widget as Text).data).take(40).toList()}');
    }

    await waitFor(find.text('Ada Vance').hitTestable());
    await binding.takeScreenshot('01-chats');

    await waitFor(find.text('Calls'));
    await tester.tap(find.text('Calls').last);
    await settle(5);
    await binding.takeScreenshot('03-calls');

    // Back to Chats: a wide layout hides the bottom bar once a thread is open,
    // so the thread is shot last.
    await tester.tap(find.text('Chats').last);
    await settle(3);
    await waitFor(find.text('Ada Vance').hitTestable());
    // The first conversation in the list.
    final tiles = find.text('Ada Vance').hitTestable();
    if (tiles.evaluate().isNotEmpty) {
      await tester.tap(tiles.first);
      await settle(5);
      await binding.takeScreenshot('02-thread');
      // A wide layout shows the thread beside the list: no back button.
      final back = find.byIcon(Icons.arrow_back).hitTestable();
      if (back.evaluate().isNotEmpty) await tester.tap(back.first);
      await settle(3);
    }

    FlutterError.onError = testOnError;
  });
}
