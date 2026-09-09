import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/phone_call_service.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/voice_device.dart';
import 'package:ezbizi_client/widgets/availability_button.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Whether you are taking calls — the control that has to keep up.
///
/// ── The defect these exist for ───────────────────────────────────
///
/// This button fetched `/phone/presence/me` once in `initState` and never
/// again. It lives in the app bar, so it is mounted for the whole session:
/// whatever presence said at sign-in was what it showed until the app was
/// reloaded.
///
/// That is invisible while nothing changes presence behind it. Taking a call
/// does — the desk writes `on_call` — so the icon and the truth came apart the
/// first time somebody answered the phone, and stayed apart. Reported by Jose
/// on 2026-09-09: "stuck on the top bar available icon, and stuck on call."
///
/// A poll would also have fixed the symptom. The desk is the thing that knows,
/// so the button listens to it instead — no timer, and correct in the same
/// frame the call ends rather than up to a poll interval later.
void main() {
  tearDown(() => PhoneCallService.overrideForTest = null);

  testWidgets('taking a call shows on the icon without a reload', (
    tester,
  ) async {
    final device = _FakeDevice();
    final service = PhoneCallService.forTest(device);
    PhoneCallService.overrideForTest = service;

    await _pump(tester, state: 'registered');

    expect(_dotColour(tester), isNot(_onCallColour(tester)));

    // A call arrives and is answered — no screen involved, as happens when
    // somebody answers from the app-wide bar.
    device.ring('+13057242199');
    service.accept();
    await tester.pump();

    expect(
      _dotColour(tester),
      _onCallColour(tester),
      reason: 'the icon still says available while this person is on a call, '
          'so a colleague reading it would ring them mid-conversation',
    );
  });

  testWidgets('and the icon comes back when the caller hangs up', (
    tester,
  ) async {
    final device = _FakeDevice();
    final service = PhoneCallService.forTest(device);
    PhoneCallService.overrideForTest = service;

    await _pump(tester, state: 'registered');

    device.ring('+13057242199');
    service.accept();
    await tester.pump();

    expect(_dotColour(tester), _onCallColour(tester));

    // The customer hangs up. Nothing here is pressed.
    device.end();
    await tester.pump();

    expect(
      _dotColour(tester),
      isNot(_onCallColour(tester)),
      reason: 'the icon is stuck on a call that has ended, which is what a '
          'reload used to be needed for',
    );
  });
}

Color _dotColour(WidgetTester tester) {
  final dot = tester.widget<Container>(
    find.descendant(
      of: find.byType(AvailabilityButton),
      matching: find.byType(Container),
    ),
  );

  return ((dot.decoration as BoxDecoration).color)!;
}

Color _onCallColour(WidgetTester tester) => Theme.of(
  tester.element(find.byType(AvailabilityButton)),
).colorScheme.tertiary;

Future<void> _pump(WidgetTester tester, {required String state}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/phone/presence/me')) {
        return http.Response(
          jsonEncode({
            'data': {'has_line': true, 'state': state},
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      return http.Response(
        jsonEncode({'data': <String, Object?>{}}),
        200,
        headers: {'content-type': 'application/json'},
      );
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        appBar: AppBar(actions: [AvailabilityButton(api: api)]),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

class _FakeDevice implements VoiceDevice {
  @override
  void Function(VoiceIncoming incoming)? onIncoming;

  @override
  void Function()? onRegistrationChanged;

  @override
  void Function()? onEnded;

  @override
  bool get supported => true;

  @override
  bool get registered => true;

  @override
  String? get registrationError => null;

  @override
  Object? get platformView => null;

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

  void end() => onEnded?.call();
}
