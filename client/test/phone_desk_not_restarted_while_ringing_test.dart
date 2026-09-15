import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/phone_call_service.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/phone/voice_device.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The shell must never tear down a softphone that has a call on it.
///
/// ── What this cost ───────────────────────────────────────────────
///
/// Jose called an EzBizi number from his own phone, the desktop app rang, he
/// pressed Answer — and his phone said "please leave your message after the
/// tone". Twilio's log shows his desktop leg as **busy**: the desk that was
/// ringing refused the caller.
///
/// The shell restarts the desk when it has no line, so that changing company
/// picks up that company's phone. `restart()` disposes the device;
/// twilio-voice's `Device.destroy()` REJECTS a pending incoming call; Twilio
/// reports the leg busy; the caller goes to voicemail.
///
/// ── The guard I had, and removed ─────────────────────────────────
///
/// The first draft refused while `onCall`. When I narrowed the method to "only
/// when there is no line", I decided `hasLine` subsumed it — no line, no call.
/// That is wrong twice: `stop()` clears the connection id, so `hasLine` is
/// false on every shell remount, and a call can be RINGING rather than
/// connected, which `onCall` alone would also have missed.
///
/// So the question is about the DEVICE — is there a call on it, of any kind —
/// and no banner being briefly wrong is worth a dropped call.
void main() {
  tearDown(() => PhoneCallService.overrideForTest = null);

  testWidgets('a ringing desk is not restarted when the view changes', (
    tester,
  ) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);

    // No line: this is the state that makes the shell want to restart at all,
    // and it is the state a desk is in for the whole of a shell remount.
    final service = PhoneCallService.forTest(device, connectionId: null);
    PhoneCallService.overrideForTest = service;

    // A call arrives and is sitting there ringing, unanswered.
    device.ring('+13055550100');
    expect(service.incomingFrom, isNotNull, reason: 'the desk is not ringing');
    expect(service.busy, isTrue);

    // NOW the shell comes up around it. This is the real sequence: the shell
    // is remounted — a sign-in, a scope change, a rebuild — while a call is
    // already ringing on the singleton desk that outlives it.
    await _pump(tester, service);

    expect(
      device.disposed,
      0,
      reason: 'the softphone was torn down with a call ringing on it — '
          'Device.destroy() rejects a pending call, so the caller is refused '
          'and lands in voicemail while somebody is looking at Answer',
    );
    expect(
      service.incomingFrom,
      isNotNull,
      reason: 'the ringing call disappeared from under the person answering',
    );
  });
}

Future<void> _pump(WidgetTester tester, PhoneCallService service) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      // Empty on purpose: no line in this view, which is exactly when the
      // shell reaches for one.
      if (request.url.path.endsWith('/phone/numbers')) {
        return _json({'data': <dynamic>[]});
      }

      // Everything else refuses, the same way the other shell test does —
      // screens that cannot load say so rather than parsing a null.
      return _json({'message': 'Nothing stubbed for this screen.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: HomeShell(api: api, session: session, address: null),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

class _FakeDevice implements VoiceDevice {
  /// Settable so a test can be a handset, where the OS rings and EzBizi must
  /// not. Defaults to false, which is the desk case.
  @override
  bool ringsItself = false;

  var disposed = 0;

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
  void dispose() => disposed++;

  void ring(String from) => onIncoming?.call(VoiceIncoming(from: from));
}

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'permissions': <String>[],
  'modules': [
    {
      'key': 'dashboard',
      'label': 'Dashboard',
      'available': true,
      'reachable': true,
      'parent': null,
    },
  ],
  'preferences': <String, dynamic>{},
  'organization': {'id': 'org-1', 'name': 'Everdoors'},
};
