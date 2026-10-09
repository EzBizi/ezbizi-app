import 'package:ezbizi_client/core/desktop_update.dart';
import 'package:ezbizi_client/core/desktop_updater.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';

/// Jose, 2026-10-09: "right now the update system auto updates and closes the
/// program, this should never happen if you are on a call on the
/// communication system". The installer closes the app, and the softphone is
/// the app.
void main() {
  late DesktopUpdater updater;
  late ValueNotifier<bool> call;
  late List<String> installed;

  setUp(() {
    updater = DesktopUpdater.instance;
    call = ValueNotifier(false);
    installed = [];
    updater.installer = (u) async {
      installed.add(u.version);
      return 'stubbed';
    };
    updater.guardCalls(call, () => call.value);
    updater.available = const AvailableUpdate(version: '9.9.9', url: 'x');
    updater.waitingForCall = false;
  });

  tearDown(() {
    updater.stop();
    updater.installer = DesktopUpdate.install;
    updater.available = null;
    updater.waitingForCall = false;
    updater.error = null;
  });

  test('install does not run while a call is live, and says it is waiting', () async {
    call.value = true;

    await updater.install();

    expect(installed, isEmpty);
    expect(updater.waitingForCall, isTrue);
    expect(updater.secondsLeft, isNull, reason: 'no countdown during a call');
  });

  testWidgets('when the call ends the normal minute starts, then it installs', (
    tester,
  ) async {
    call.value = true;
    await updater.install();

    call.value = false;
    expect(updater.waitingForCall, isFalse);
    expect(updater.secondsLeft, DesktopUpdater.grace.inSeconds);

    await tester.pump(DesktopUpdater.grace);
    await tester.pump();
    expect(installed, ['9.9.9']);
  });

  testWidgets('a call that starts during the minute stops the install', (
    tester,
  ) async {
    // Held for a call, then the call ends: the minute starts.
    call.value = true;
    await updater.install();
    call.value = false;
    expect(updater.secondsLeft, DesktopUpdater.grace.inSeconds);

    // Half way through, the phone rings and is answered.
    await tester.pump(const Duration(seconds: 30));
    call.value = true;
    await tester.pump(const Duration(seconds: 31));
    await tester.pump();

    expect(installed, isEmpty, reason: 'installed under a live call');
    expect(updater.waitingForCall, isTrue);

    // Hung up: the minute starts over, and only then does it install.
    call.value = false;
    await tester.pump(DesktopUpdater.grace);
    await tester.pump();
    expect(installed, ['9.9.9']);
  });
}
