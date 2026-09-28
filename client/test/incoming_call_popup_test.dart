import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/phone_call_service.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/voice_device.dart';
import 'package:ezbizi_client/widgets/incoming_call_popup.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The incoming-call window Jose asked for on 2026-09-22.
///
/// ── The rule this reverses, and why the switch is the test ───────
///
/// The shell shows a STRIP on purpose: a ringing phone must not take the app
/// away from somebody halfway through writing up a job. Jose asked for a
/// window AND for a setting, which is what lets both be true — so the case
/// that matters most here is the one where the setting is OFF and nothing
/// appears, because that is every other person in the company.
void main() {
  testWidgets('a ringing call opens the window when it is switched on', (
    tester,
  ) async {
    final device = _FakeDevice();
    final service = await _pump(tester, device: device, popup: true);
    addTearDown(service.stop);

    expect(find.byKey(const Key('incoming-call-popup')), findsNothing);

    device.ring('+13055550100');
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('incoming-call-popup')), findsOneWidget);

    // The database knows this number, so the NAME is the headline and the
    // number sits under it — his words.
    expect(find.text('Marta Delgado'), findsOneWidget);
    expect(find.text('+1 (305) 555-0100'), findsOneWidget);

    expect(find.widgetWithText(FilledButton, 'Accept'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Ignore'), findsOneWidget);
  });

  /// ON BY DEFAULT. Jose's own account had never touched the switch, and a
  /// call rang with only the strip: *"remember i asked for a pop up"*
  /// (2026-09-25). No key at all is the state that case was in.
  testWidgets('a person who never chose gets the window', (tester) async {
    final device = _FakeDevice();
    final service = await _pump(tester, device: device, popup: null);
    addTearDown(service.stop);

    device.ring('+13055550100');
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('incoming-call-popup')), findsOneWidget);
  });

  /// NOTHING UNDERNEATH IS BLOCKED. Jose, 2026-09-25, of the dimmed first
  /// version: *"it does block the screen"*. A tap beside the card reaches the
  /// app, which a full-screen backdrop would have swallowed.
  testWidgets('the app underneath stays usable while it rings', (tester) async {
    final device = _FakeDevice();
    var taps = 0;
    final service = await _pump(
      tester,
      device: device,
      popup: true,
      onUnderneath: () => taps++,
    );
    addTearDown(service.stop);

    device.ring('+13055550100');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('incoming-call-popup')), findsOneWidget);

    await tester.tapAt(const Offset(10, 890));
    expect(taps, 1);
  });

  /// *"we can move it around like draggable"*.
  testWidgets('the card can be dragged out of the way', (tester) async {
    final device = _FakeDevice();
    final service = await _pump(tester, device: device, popup: true);
    addTearDown(service.stop);

    device.ring('+13055550100');
    await tester.pumpAndSettle();

    final card = find.byKey(const Key('incoming-call-popup'));
    final before = tester.getTopLeft(card);

    await tester.drag(find.text('Marta Delgado'), const Offset(-150, 200));
    await tester.pumpAndSettle();

    expect(tester.getTopLeft(card), before + const Offset(-150, 200));
  });

  /// THE CONTROL: a company that switched it off does not get it.
  ///
  /// Without it the test above passes on a build that ignores the switch.
  testWidgets('it stays away when the setting is off', (tester) async {
    final device = _FakeDevice();
    final service = await _pump(tester, device: device, popup: false);
    addTearDown(service.stop);

    device.ring('+13055550100');
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('incoming-call-popup')), findsNothing);
  });

  /// A number nobody in the database holds shows as a number, once.
  ///
  /// The distinguishing half is `findsOneWidget`: a build that drew the number
  /// as both headline and subtitle would look almost right and would be saying
  /// the same thing twice.
  testWidgets('an unknown caller is shown as a number, and only once', (
    tester,
  ) async {
    final device = _FakeDevice();
    final service = await _pump(
      tester,
      device: device,
      popup: true,
      known: false,
    );
    addTearDown(service.stop);

    device.ring('+13055550100');
    await tester.pumpAndSettle();

    expect(find.text('+1 (305) 555-0100'), findsOneWidget);
    expect(find.text('Marta Delgado'), findsNothing);
  });

  testWidgets('Accept answers and Ignore refuses', (tester) async {
    final device = _FakeDevice();
    final service = await _pump(tester, device: device, popup: true);
    addTearDown(service.stop);

    device.ring('+13055550100');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('incoming-call-popup-accept')));
    await tester.pumpAndSettle();

    expect(device.accepted, isTrue);
    expect(device.declined, isFalse);

    device.ring('+13055550101');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('incoming-call-popup-ignore')));
    await tester.pumpAndSettle();

    expect(device.declined, isTrue);
  });

  /// THE REASON THIS IS NOT A PUSHED DIALOG.
  ///
  /// The commonest way a ringing call ends is that nobody here does anything:
  /// the caller gives up, or a colleague answers first and Twilio cancels this
  /// leg. A `showDialog` would still be on screen, over a call that no longer
  /// exists, with buttons that now answer nothing — and a modal cannot be
  /// ignored the way a stale strip can.
  testWidgets('the window leaves by itself when the call is cancelled', (
    tester,
  ) async {
    final device = _FakeDevice();
    final service = await _pump(tester, device: device, popup: true);
    addTearDown(service.stop);

    device.ring('+13055550100');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('incoming-call-popup')), findsOneWidget);

    device.onEnded!();
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('incoming-call-popup')), findsNothing);
  });

  /// A name that arrives after the caller has gone belongs to nobody.
  ///
  /// The lookup is a round trip and a ring is seconds. Without the guard, a
  /// reply landing after this call ended — or after a DIFFERENT number started
  /// ringing — would label whatever is on screen with the previous caller's
  /// name, which is the one mistake here that is worse than showing no name.
  testWidgets('a late name is not pinned to a different caller', (
    tester,
  ) async {
    final device = _FakeDevice();
    final replies = <void Function()>[];

    final service = await _pump(
      tester,
      device: device,
      popup: true,
      holdReplies: replies,
    );
    addTearDown(service.stop);

    device.ring('+13055550100');
    await tester.pumpAndSettle();

    // That caller gives up; another rings before the first lookup answers.
    device.onEnded!();
    device.ring('+13055550199');
    await tester.pumpAndSettle();

    // ONLY THE STALE ONE. Completing both let the second lookup's null
    // overwrite the first's name, so the assertion below passed whether or not
    // the guard existed — the fixture was doing the guard's job. Sabotaging
    // the guard is what exposed that.
    replies.first();
    await tester.pumpAndSettle();

    expect(find.text('Marta Delgado'), findsNothing);
    expect(find.text('+1 (305) 555-0199'), findsOneWidget);
  });
}

Future<PhoneCallService> _pump(
  WidgetTester tester, {
  required _FakeDevice device,
  required bool? popup,
  bool known = true,
  List<void Function()>? holdReplies,
  VoidCallback? onUnderneath,
}) async {
  tester.view.physicalSize = const Size(900, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            // The COMPANY's setting, on /me under `display`.
            'display': {'phone.incoming_popup': ?popup},
          },
        });
      }

      if (path.endsWith('/phone/caller')) {
        final asked = request.url.queryParameters['e164'];

        // ONE NUMBER, ONE NAME. A fake that answered "Marta Delgado" for every
        // number could not tell a late reply being IGNORED from one being
        // applied — both leave her name on screen — so the second caller is
        // deliberately a stranger.
        final name = known && asked == '+13055550100' ? 'Marta Delgado' : null;

        final body = _json({
          'data': {
            'e164': asked,
            'name': name,
            'customer_id': name == null ? null : 'c-1',
          },
        });

        if (holdReplies == null) return body;

        // Held open so a test can decide WHEN the answer lands.
        final gate = Completer<http.Response>();
        holdReplies.add(() => gate.complete(body));

        return gate.future;
      }

      return _json({'message': 'Unexpected $path'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  final service = PhoneCallService.forTest(device);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        // FULL SIZE, and it is load-bearing. A Stack sizes itself to its
        // non-positioned children, so a bare Text here made the Stack a strip
        // — `Positioned.fill` filled the strip, the buttons were laid out
        // outside the hit area, and `tap()` reported a miss while the window
        // was still perfectly findable. In the shell the sibling is the whole
        // app, so the fixture has to be full size too or it tests a different
        // geometry from the one that ships.
        body: Stack(
          children: [
            SizedBox.expand(
              child: TextButton(
                onPressed: onUnderneath,
                child: const Text('the estimate you were halfway through'),
              ),
            ),
            IncomingCallPopup(api: api, session: session, service: service),
          ],
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();

  return service;
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
    {'key': 'phone', 'label': 'Phone', 'available': true, 'reachable': true},
  ],
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};

class _FakeDevice implements VoiceDevice {
  @override
  bool ringsItself = false;

  @override
  bool needsCallingAccount = false;

  @override
  Future<void> openCallingSettings() async {}

  @override
  void Function()? onAnswered;

  @override
  String? pushPlatform;

  var declined = false;
  var accepted = false;
  String? connectedTo;
  String? callerId;

  @override
  void Function(VoiceIncoming incoming)? onIncoming;

  @override
  void Function()? onRegistrationChanged;

  @override
  void Function()? onTokenExpiring;

  @override
  void Function()? onEnded;

  @override
  var registered = true;

  @override
  bool get supported => true;

  @override
  String? get registrationError => null;

  @override
  Object? get platformView => null;

  var setups = 0;

  @override
  Future<void> prepare() async {}

  @override
  Future<void> setup(String token) async => setups++;

  @override
  Future<void> connect({required String to, String? callerId}) async {
    connectedTo = to;
    this.callerId = callerId;
  }

  @override
  void hangUp() {}

  @override
  void acceptIncoming() => accepted = true;

  @override
  void declineIncoming() => declined = true;

  void ring(String from) => onIncoming?.call(VoiceIncoming(from: from));

  @override
  void dispose() {}
}
