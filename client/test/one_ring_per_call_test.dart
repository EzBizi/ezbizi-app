import 'dart:io';

import 'package:ezbizi_client/core/call_alerts.dart';
import 'package:ezbizi_client/core/phone_call_service.dart';
import 'package:ezbizi_client/screens/phone/voice_device.dart';
import 'package:flutter_test/flutter_test.dart';

/// One ring for incoming, one for outgoing, on every platform.
///
/// ── Why this test exists at all ──────────────────────────────────
///
/// Jose, after weeks of it: "1 ring for incoming, 1 for outgoing, it should be
/// simple, and the same type for all of them, we have been going around this
/// for weeks."
///
/// It kept coming back because each platform was fixed on its own. Three of
/// them were producing two sounds for one call, for three different reasons —
/// the browser's SDK tone, the desktop's vendored mp3, and the handset's own
/// OS ringtone — and fixing one taught nothing about the others.
///
/// So the rule is one sentence and this is where it is enforced:
///
///   INCOMING — exactly one, played by EzBizi wherever EzBizi owns the audio,
///   and by the OS on a handset, where CallKit and the Android
///   ConnectionService ring before our code runs.
///
///   OUTGOING — exactly one, and it is `<Dial ringTone>`, generated on the
///   server. Identical everywhere by construction rather than by agreement,
///   because no client produces it.
void main() {
  // PhoneCallService's default alerts construct an AudioPlayer, and a field
  // initializer runs before the constructor body — so it is built even though
  // forTest replaces it a line later, and building it needs the binding.
  TestWidgetsFlutterBinding.ensureInitialized();

  test('a desk rings once: ours', () async {
    final device = _Fake(ringsItself: false);
    final alerts = _CountingAlerts();
    final service = PhoneCallService.forTest(device)..alerts = alerts;

    device.ring('+13055550100');

    expect(
      alerts.rang,
      1,
      reason: 'the desk did not ring, or rang more than once for one call',
    );
  });

  /// The half that was double-ringing on phones, and the control for the test
  /// above: without this, a build that always rings passes the first test and
  /// still gives a handset two sounds.
  test('a handset does not ring on top of the OS', () async {
    final device = _Fake(ringsItself: true);
    final alerts = _CountingAlerts();
    final service = PhoneCallService.forTest(device)..alerts = alerts;

    device.ring('+13055550100');

    expect(
      alerts.rang,
      0,
      reason: 'EzBizi rang over CallKit or the ConnectionService, so the phone '
          'makes two sounds for one call',
    );

    // And the desk still KNOWS, which is the thing that must not be lost by
    // staying quiet — the banner and the Answer button are not the ringtone.
    expect(service.incomingFrom, '+13055550100');
  });

  /// Every outgoing tone a client could play is off.
  ///
  /// Read from source because neither of these runs in a Dart test: the web
  /// one is a JS interop call into a Twilio SDK that is not loaded here, and
  /// the desktop one lives in a file inside a WebView2. The only gate between
  /// an edit and somebody's ears is a person launching the app.
  test('no client plays its own outgoing tone', () {
    final web = File(
      'lib/screens/phone/voice_device_web.dart',
    ).readAsStringSync();

    expect(
      web,
      contains('device.audio?.outgoing(false.toJS)'),
      reason: 'the browser plays the SDK outgoing tone on top of the server '
          'ringback, which is two sounds for one outgoing call',
    );

    final bridge = File(
      'assets/softphone/softphone_bridge.js',
    ).readAsStringSync();

    // Line by line and uncommented, because `contains` alone passes on a call
    // that has been commented out — which is how the first version of the
    // desktop guard was sabotaged into passing.
    final live = bridge
        .split('\n')
        .map((line) => line.trim())
        .where((line) => line.startsWith('d.audio.outgoing(false);'));

    expect(
      live,
      isNotEmpty,
      reason: 'the desktop plays its vendored outgoing.mp3 on top of the '
          'server ringback',
    );
  });
}

class _CountingAlerts implements CallAlerts {
  int rang = 0;

  @override
  void incoming(String from) => rang++;

  @override
  void stop() {}

  @override
  void dispose() {}
}

class _Fake implements VoiceDevice {
  _Fake({required this.ringsItself});

  @override
  final bool ringsItself;

  @override
  bool needsCallingAccount = false;

  @override
  Future<void> openCallingSettings() async {}

  @override
  void Function()? onAnswered;

  @override
  void Function(VoiceIncoming incoming)? onIncoming;

  @override
  void Function()? onRegistrationChanged;

  @override
  void Function()? onEnded;

  @override
  bool get registered => true;

  @override
  String? get registrationError => null;

  @override
  bool get supported => true;

  @override
  Object? get platformView => null;

  @override
  String? get pushPlatform => null;

  @override
  Future<void> prepare() async {}

  @override
  Future<void> setup(String token) async {}

  @override
  Future<void> connect({required String to, String? callerId}) async {}

  @override
  void hangUp() {}

  @override
  void acceptIncoming() {}

  @override
  void declineIncoming() {}

  @override
  void dispose() {}

  void ring(String from) => onIncoming?.call(VoiceIncoming(from: from));
}
