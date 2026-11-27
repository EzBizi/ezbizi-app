import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/phone_call_service.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/phone/voice_device.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// A ringing phone, seen from a screen that is not the phone.
///
/// ── Why this is pumped through the whole shell ───────────────────
///
/// Testing the bar on its own would prove the widget renders and nothing about
/// the thing that was broken. The defect was REACH: the banner was drawn by
/// CallTab, so it existed only while Communication was the open module, and
/// the softphone went down with that tab anyway.
///
/// That is the same shape as the consent card that existed on a phone and not
/// on a desk — a control that is present in the layout somebody happens to be
/// testing and absent from the one they use. So the assertion below stands on
/// a DIFFERENT module and asks whether the call can be answered from there.
void main() {
  tearDown(() => PhoneCallService.overrideForTest = null);

  testWidgets('a call can be answered from a screen that is not the phone', (
    tester,
  ) async {
    final device = _FakeDevice();
    final service = PhoneCallService.forTest(device);
    PhoneCallService.overrideForTest = service;

    await _pumpShell(tester);

    // Standing on the dashboard, which is where somebody actually is when the
    // phone goes — not on Dial.
    expect(find.byKey(const Key('incoming-call-bar')), findsNothing);

    device.ring('+13055550100');
    await tester.pumpAndSettle();

    expect(
      find.byKey(const Key('incoming-call-bar')),
      findsOneWidget,
      reason:
          'the phone rang and the only screen that said so was the one '
          'this person was not on',
    );
    expect(find.textContaining('305'), findsWidgets);

    // THE WHOLE WIDTH. It shrank to its contents and sat as a centred box;
    // Jose, 2026-09-25: *"make it so that top bar Extends 100%"*.
    expect(
      tester.getSize(find.byKey(const Key('incoming-call-bar'))).width,
      1280,
    );

    await tester.tap(find.byKey(const Key('incoming-call-answer')));
    await tester.pumpAndSettle();

    expect(device.accepted, isTrue);
  });

  /// Answering from elsewhere must leave a way to hang up from elsewhere.
  ///
  /// Otherwise the audio is live and the only control that ends it is on a
  /// screen you are not on — which is the original bug wearing a different
  /// hat.
  testWidgets('the on-call strip stays while you carry on working', (
    tester,
  ) async {
    final device = _FakeDevice();
    final service = PhoneCallService.forTest(device);
    PhoneCallService.overrideForTest = service;

    await _pumpShell(tester);

    device.ring('+13055550100');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('incoming-call-answer')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('on-call-bar')), findsOneWidget);

    await tester.tap(find.byKey(const Key('on-call-hang-up')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('on-call-bar')), findsNothing);
  });

  /// Jose, 2026-11-11: Meta's call said "press 1" and the bar had no keypad.
  testWidgets('the on-call keypad presses keys on the live call', (
    tester,
  ) async {
    final device = _DigitsDevice();
    final service = PhoneCallService.forTest(device);
    PhoneCallService.overrideForTest = service;

    await _pumpShell(tester);

    device.ring('+13055550100');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('incoming-call-answer')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('on-call-keypad')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('on-call-key-1')));
    await tester.tap(find.byKey(const Key('on-call-key-#')));
    await tester.sendKeyEvent(LogicalKeyboardKey.digit2);
    await tester.pumpAndSettle();

    expect(device.digits, ['1', '#', '2']);
    expect(find.text('1#2'), findsOneWidget);

    await tester.tap(find.byKey(const Key('on-call-hang-up')));
    await tester.pumpAndSettle();
  });

  /// A live call can be moved to the phone in your pocket.
  ///
  /// ── Why it is on this bar and not on the dial pad ────────────
  ///
  /// Jose asked for it as the RingCentral behaviour: answer at the desk, and
  /// take the call with you. The moment somebody wants it is the moment they
  /// are standing up, so it has to be on the one part of the phone that is on
  /// screen in every module — which is this strip, and is why the test stands
  /// on the dashboard rather than on Dial.
  ///
  /// Asserts the REQUEST, not the tap. A button that flips to "Moving…" and
  /// sends nothing looks exactly like one that works, right up until the call
  /// stays where it was.
  testWidgets('a call can be moved to my other devices from anywhere', (
    tester,
  ) async {
    final seen = <http.Request>[];
    final device = _FakeDevice();

    await _pumpShell(
      tester,
      seen: seen,
      device: device,
      handle: (request) => request.url.path.endsWith('/phone/calls/transfer')
          ? _json({
              'data': {'ringing': 1},
            })
          : null,
    );

    device.ring('+13055550100');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('incoming-call-answer')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('on-call-move')));
    await tester.pumpAndSettle();

    final asked = seen.lastWhere(
      (r) => r.url.path.endsWith('/phone/calls/transfer'),
      orElse: () => throw StateError('the call was never asked to move'),
    );

    // The device asking names itself, or the transfer rings the desk it is
    // being moved away from and nothing appears to happen.
    expect(
      jsonDecode(asked.body)['from_device_id'],
      isNotEmpty,
      reason: 'the server cannot tell which device to leave out',
    );
  });

  /// A call that ended first says so where the reader is looking.
  ///
  /// The refusal is the commonest outcome of pressing this a second too late,
  /// and somebody who has just pressed it is about to walk away from the
  /// screen. Shown in place rather than in a snack bar at the bottom of a
  /// window they have stopped watching.
  testWidgets('a refused move is said on the bar itself', (tester) async {
    final device = _FakeDevice();

    await _pumpShell(
      tester,
      device: device,
      handle: (request) => request.url.path.endsWith('/phone/calls/transfer')
          ? _json({'message': 'That call has already ended.'}, 409)
          : null,
    );

    device.ring('+13055550100');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('incoming-call-answer')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('on-call-move')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('on-call-move-error')), findsOneWidget);
    expect(find.textContaining('already ended'), findsOneWidget);
  });

  /// Nobody calling, nothing drawn.
  testWidgets('a quiet phone takes up no room at all', (tester) async {
    PhoneCallService.overrideForTest = PhoneCallService.forTest(_FakeDevice());

    await _pumpShell(tester);

    expect(find.byKey(const Key('incoming-call-bar')), findsNothing);
    expect(find.byKey(const Key('on-call-bar')), findsNothing);
  });
}

/// Pumps the shell, and — when a [device] is given — wires the desk to the
/// SAME api the shell is using and installs it before the first frame.
///
/// Both halves matter. A service with no api cannot make a request, so a test
/// about a request would pass on silence; and the shell reads
/// `PhoneCallService.current` while it builds, so a service installed after
/// the pump is a phone the strip never sees.
Future<ApiClient> _pumpShell(
  WidgetTester tester, {
  List<http.Request>? seen,
  http.Response? Function(http.Request request)? handle,
  _FakeDevice? device,
}) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      seen?.add(request);

      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      final answered = handle?.call(request);
      if (answered != null) return answered;

      // The bodies are not the point here; they may render their own error.
      return _json({'message': 'not stubbed'}, 500);
    }),
  );

  if (device != null) {
    PhoneCallService.overrideForTest = PhoneCallService.forTest(
      device,
      api: api,
    );
  }

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: ListenableBuilder(
        listenable: session,
        builder: (_, _) => HomeShell(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();

  return api;
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['phone.view'],
  'modules': [
    {
      'key': 'dashboard',
      'label': 'Dashboard',
      'available': true,
      'reachable': true,
    },
  ],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A1-Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A1-Group',
    'path': ['A1-Group'],
  },
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};

class _FakeDevice implements VoiceDevice {
  /// Settable so a test can be a handset, where the OS rings and EzBizi must
  /// not. Defaults to false, which is the desk case.
  @override
  bool ringsItself = false;

  // Android's Telecom account. Settable so a test can put the desk into the
  // one state a retry cannot fix, rather than only the happy one.
  @override
  bool needsCallingAccount = false;

  var openedCallingSettings = 0;

  @override
  Future<void> openCallingSettings() async => openedCallingSettings++;

  @override
  void Function()? onAnswered;

  @override
  void Function(String reason)? onCallFailed;

  @override
  String? pushPlatform;

  @override
  void Function(VoiceIncoming incoming)? onIncoming;

  @override
  void Function()? onRegistrationChanged;

  @override
  void Function()? onTokenExpiring;

  @override
  void Function()? onEnded;

  @override
  var supported = true;

  @override
  var registered = true;

  @override
  String? registrationError;

  @override
  Object? get platformView => null;

  var accepted = false;
  var declined = false;

  @override
  Future<void> prepare() async {}

  @override
  Future<void> setup(String token) async {}

  @override
  Future<void> connect({required String to, String? callerId}) async {}

  @override
  void hangUp() {}

  @override
  void acceptIncoming() => accepted = true;

  @override
  void declineIncoming() => declined = true;

  @override
  void dispose() => onIncoming = null;

  void ring(String from) => onIncoming?.call(VoiceIncoming(from: from));
}

class _DigitsDevice extends _FakeDevice implements SendsDigits {
  final digits = <String>[];

  @override
  void sendDigits(String value) => digits.add(value);
}
