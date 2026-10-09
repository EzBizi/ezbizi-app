import 'package:ezbizi_client/core/field_tracker.dart';
import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';

/// A location prompt raised from the background brings EzBizi to the front
/// over the browser and strands an OAuth sign-in (Android, 2026-10-09). The
/// tracker asks only on screen, and only once a run.
void main() {
  testWidgets('asks only in the foreground, and only once', (tester) async {
    var asked = 0;
    for (final name in [
      'flutter.baseflow.com/geolocator',
      'flutter.baseflow.com/geolocator_android',
    ]) {
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        MethodChannel(name),
        (call) async => switch (call.method) {
          'isLocationServiceEnabled' => true,
          'checkPermission' => 0, // denied
          'requestPermission' => () {
            asked++;
            return 0;
          }(),
          _ => null,
        },
      );
    }

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    expect(await FieldTracker.currentPosition(), isNull);
    expect(asked, 0, reason: 'In the background it must not ask.');

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    expect(await FieldTracker.currentPosition(), isNull);
    expect(await FieldTracker.currentPosition(), isNull);
    expect(asked, 1, reason: 'On screen it asks once, never again this run.');
  });
}
