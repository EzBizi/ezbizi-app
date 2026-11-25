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
    device.ring('+13055550107');
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

    device.ring('+13055550107');
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
  /// ── The defect this exists for ─────────────────────────────────
  ///
  /// The desk clears `on_call` locally, tells its listeners, and THEN fires
  /// the request that clears `on_call` on the server. So the button refetched
  /// presence while the server still said `on_call`, believed it, and never
  /// asked again — the icon stayed on a call that had ended until the app was
  /// restarted, on desktop and on mobile. Jose reported it on 0.0.0184.
  ///
  /// The tests above could not see it because their fake server answered
  /// `registered` to every request whatever had happened. This one HOLDS
  /// state: it answers `on_call` until the release actually lands, which is
  /// what the real server does.
  testWidgets('the icon does not believe a presence read taken mid-release', (
    tester,
  ) async {
    final device = _FakeDevice();

    final server = _PresenceServer();
    final api = ApiClient(client: MockClient((r) async => server.handle(r)));

    // The service needs the api to RELEASE presence — without one the request
    // that clears on_call is skipped, and this test would pass for a desk that
    // never told the server anything.
    final started = PhoneCallService.forTest(device, api: api);
    PhoneCallService.overrideForTest = started;

    await _pump(tester, server: server, api: api);

    // A call arrives, is answered, and the server is told — which is what the
    // webhook does on a real call.
    device.ring('+13055550107');
    started.accept();
    server.state = 'on_call';
    await tester.pump();

    expect(_dotColour(tester), _onCallColour(tester));

    // The customer hangs up. The desk clears its own state and releases
    // presence; the release is a request and does not land instantly.
    device.end();
    await tester.pumpAndSettle();

    expect(
      server.state,
      'registered',
      reason: 'the desk never released on_call, so the ring group will keep '
          'skipping this person',
    );

    expect(
      _dotColour(tester),
      isNot(_onCallColour(tester)),
      reason: 'the icon is stuck on a call that ended — it read presence '
          'before the release landed and never asked again',
    );
  });

/// Another device changed it, and this one finds out when you look.
///
/// ── The report ───────────────────────────────────────────────────
///
/// Jose set Do Not Disturb on the web and the desktop app went on showing a
/// green dot — then a call arrived while the two disagreed. Availability
/// belongs to the PERSON; this button belongs to one app, read the server once
/// at startup, and after that only heard about changes its own app made.
///
/// Refreshing when the app comes forward rather than on a timer: the moment
/// the answer matters is the moment somebody looks, and switching windows is
/// what the report describes — two apps side by side, clicking between them.
  testWidgets('coming back to the app picks up another device\'s change', (
    tester,
  ) async {
    final server = _PresenceServer();

    await _pump(tester, server: server);
    expect(find.byTooltip('Taking calls — tap to stop'), findsOneWidget);

    final readsAtStart = server.reads;

    // The other device sets it. Nothing tells this app.
    server.state = 'dnd';
    await tester.pump();
    expect(
      find.byTooltip('Taking calls — tap to stop'),
      findsOneWidget,
      reason: 'it changed without anybody looking, which is not the behaviour '
          'under test',
    );

    // Somebody clicks back into this window.
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();

    expect(
      server.reads,
      greaterThan(readsAtStart),
      reason: 'the app came forward and never asked',
    );
    expect(find.byTooltip('Not taking calls — tap to start again'), findsOneWidget);
  });

  /// And it does not ask on every lifecycle event.
  ///
  /// The control. A refresh wired to every state change would also pass the
  /// test above while asking the server each time the app is backgrounded,
  /// hidden or paused — on every desk in the company, all day.
  testWidgets('being backgrounded does not ask', (tester) async {
    final server = _PresenceServer();

    await _pump(tester, server: server);
    final readsAtStart = server.reads;

    for (final state in [
      AppLifecycleState.inactive,
      AppLifecycleState.paused,
      AppLifecycleState.detached,
    ]) {
      tester.binding.handleAppLifecycleStateChanged(state);
      await tester.pump();
    }

    expect(server.reads, readsAtStart);
  });

}

/// A presence endpoint that remembers, because the bug is about ordering.
class _PresenceServer {
  String state = 'registered';
  int reads = 0;

  http.Response handle(http.Request request) {
    final path = request.url.path;

    if (path.endsWith('/phone/presence') && request.method == 'PUT') {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      state = (body['state'] ?? state).toString();

      return _json({'data': {'state': state}});
    }

    if (path.endsWith('/phone/presence/me')) {
      reads++;

      return _json({'data': {'has_line': true, 'state': state}});
    }

    return _json({'data': <String, Object?>{}});
  }
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);

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

Future<void> _pump(
  WidgetTester tester, {
  String? state,
  _PresenceServer? server,
  ApiClient? api,
}) async {
  final client = api ?? ApiClient(
    client: MockClient((request) async {
      if (server != null) return server.handle(request);

      if (request.url.path.endsWith('/phone/presence/me')) {
        return _json({
          'data': {'has_line': true, 'state': state ?? 'registered'},
        });
      }

      return _json({'data': <String, Object?>{}});
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        appBar: AppBar(actions: [AvailabilityButton(api: client)]),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

class _FakeDevice implements VoiceDevice {
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

  /// Settable so a test can be a handset, where the OS rings and EzBizi
  /// must not. Defaults to false, which is the desk case.
  @override
  bool ringsItself = false;

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
