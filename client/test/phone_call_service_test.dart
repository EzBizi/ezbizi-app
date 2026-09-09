import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/call_alerts.dart';
import 'package:ezbizi_client/core/phone_call_service.dart';
import 'package:ezbizi_client/screens/phone/voice_device.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The desk that outlives the screen.
///
/// ── What these guard ─────────────────────────────────────────────
///
/// The softphone used to be created, registered and disposed by `CallTab`, and
/// the shell renders only the selected module — so leaving Communication took
/// the phone off the hook and nothing said so. The only way to see it was for
/// somebody to ring you while you were on another screen.
///
/// So the tests below are about LIFETIME and IDENTITY, which is what moved:
/// that the desk comes up without any screen asking, that it survives the Dial
/// tab being thrown away, and that it never substitutes its own line for the
/// one somebody chose.
void main() {
  // start() awaits a frame before it registers — see the ordering test — so
  // these need a binding even though none of them build a widget.
  TestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('the desk registers without any screen asking it to', (
    tester,
  ) async {
    final device = _FakeDevice();
    final service = PhoneCallService.forTest(device);

    // forTest skips start(); prove the real path instead.
    final live = await _started(tester, device: _FakeDevice());

    expect(
      live.registered,
      isTrue,
      reason: 'nothing registered the phone, so no call could ever arrive',
    );
    expect(live.hasLine, isTrue);

    service.stop();
    live.stop();
  });

  /// Prepared, and given a frame to mount, before anything registers.
  ///
  /// On Windows the microphone prompt is delivered to the mounted WebView2
  /// widget, and `platformView` is null until prepare() has built the
  /// controller — so registering in the same await left nothing in the tree to
  /// answer it. getUserMedia went unanswered, Twilio's register() rejected, and
  /// the desk reported itself disconnected on every desktop launch while the
  /// browser was fine.
  ///
  /// Lives here rather than on CallTab now, because this ordering moved with
  /// the device.
  testWidgets('the device is prepared before it is asked to register', (
    tester,
  ) async {
    final device = _FakeDevice();
    final service = await _started(tester, device: device);

    expect(
      device.prepareCalledBeforeSetup,
      isTrue,
      reason: 'setup() registered before the platform view could be mounted',
    );

    service.stop();
  });

  /// The line somebody CHOSE, never the desk's own.
  ///
  /// This is the defect the Calling as picker exists to prevent, and it came
  /// back the moment dialling moved into the service: with a default caller id
  /// the service quietly substituted the desk's line, so four people ringing
  /// four customers would all show one number and every callback would land on
  /// whoever answered. Caught by an existing CallTab test, and pinned here
  /// where the substitution would happen.
  testWidgets('a call goes out under the line it was given', (tester) async {
    final device = _FakeDevice();
    final service = await _started(tester, device: device);

    await service.connect(to: '+13055550199', callerId: '+13055550188');

    expect(device.callerId, '+13055550188');
    expect(
      device.callerId,
      isNot(service.callerId),
      reason: 'the service used its own line instead of the one it was given',
    );

    service.stop();
  });

  /// A person with no line is not a broken phone.
  ///
  /// Everything stays false and nothing throws, so the shell simply draws no
  /// bar rather than a control that cannot mean anything.
  test('somebody with no line leaves the desk quietly down', () async {
    final service = PhoneCallService.forTest(_FakeDevice())..stop();

    expect(service.hasLine, isFalse);
    expect(service.registered, isFalse);
    expect(service.incomingFrom, isNull);
  });

  /// Answering and hanging up are the service's, so they work from anywhere.
  test('a ringing call can be answered and ended without the Dial screen', () {
    final device = _FakeDevice();
    final service = PhoneCallService.forTest(device);

    var notified = 0;
    service.addListener(() => notified++);

    device.ring('+13055550100');
    expect(service.incomingFrom, '+13055550100');

    service.accept();
    expect(device.accepted, isTrue);
    expect(service.onCall, isTrue);
    expect(service.incomingFrom, isNull);

    service.hangUp();
    expect(service.onCall, isFalse);
    expect(
      notified,
      greaterThanOrEqualTo(3),
      reason: 'the shell listens to this; a silent change draws no bar',
    );

    service.stop();
  });

  /// Retrying does not leave two phones on one identity.
  testWidgets('restart tears the old device down before building a new one', (
    tester,
  ) async {
    final first = _FakeDevice();
    final service = await _started(tester, device: first);

    // A device is handed in, because without one start() builds a real
    // platform device and a test hangs on a channel that never answers.
    final restarting = service.restart(_apiFor(), device: _FakeDevice());
    await tester.pumpAndSettle();
    await restarting;

    expect(
      first.disposed,
      isTrue,
      reason: 'the failed device was left registered beside its replacement',
    );

    service.stop();
  });

  /// THE CALLER HANGING UP HAS TO CLEAR THE BANNER.
  ///
  /// Every path that cleared `incomingFrom` was a local action — accept,
  /// decline, hang up. A call that ends at the OTHER end is the commonest way
  /// a ringing call stops ringing: the caller gives up, or somebody else in
  /// the ring group answers it first and Twilio cancels this leg.
  ///
  /// Neither of those goes through a button here, so the bar stayed across the
  /// top of the app forever, offering to answer a call that no longer existed.
  /// Reported by Jose on 2026-09-09.
  testWidgets('a caller who hangs up before anybody answers clears the bar', (
    tester,
  ) async {
    final device = _FakeDevice();
    final service = await _started(tester, device: device);

    device.ring('+13057242199');
    await tester.pump();

    expect(service.incomingFrom, '+13057242199');

    // The caller gives up. No button is pressed, because nobody was there.
    device.end();
    await tester.pump();

    expect(
      service.incomingFrom,
      isNull,
      reason: 'the bar is still offering to answer a call that has ended',
    );

    service.stop();
  });

  /// AND THE SAME FOR A CALL THAT WAS ANSWERED.
  ///
  /// Jose: "ana responded, once the call was over, because the caller hung up,
  /// the ui also got stuck on the top bar available icon, and stuck on call."
  ///
  /// Two things were stuck and the second is the one that matters. `onCall`
  /// staying true is a Hang up button for a call that is over. But the desk
  /// also tells the server `on_call`, and ring groups SKIP anybody on a call —
  /// so a stale one takes that person out of the rotation silently. Worse,
  /// PresenceController::setMine refuses to overwrite `on_call` on purpose, so
  /// the person cannot clear it from the availability control either. Only a
  /// reload fixed it.
  testWidgets('a call ended by the other end puts the desk back on the hook', (
    tester,
  ) async {
    final device = _FakeDevice();
    final service = await _started(tester, device: device);

    device.ring('+13057242199');
    await tester.pump();
    service.accept();
    await tester.pump();

    expect(service.onCall, isTrue);

    // The customer hangs up.
    device.end();
    await tester.pump();

    expect(
      service.onCall,
      isFalse,
      reason: 'the desk still thinks it is on a call, so it is still telling '
          'the server on_call and the ring group is still skipping this person',
    );

    service.stop();
  });

  /// A call that arrives makes a noise, raises the window, and stops.
  ///
  /// ── Why the bar was not enough ─────────────────────────────────
  ///
  /// The incoming bar is a strip across a screen somebody has to be LOOKING
  /// at. A call arriving while the window was minimised, behind a browser, or
  /// on another monitor was a call nobody knew about. Jose: we cannot afford
  /// to lose any call.
  ///
  /// Asserted through the alerts collaborator rather than by listening,
  /// because a test cannot hear — and because the real one touches three
  /// plugins, which under a fake clock never return.
  testWidgets('an arriving call rings, and stops when it is answered', (
    tester,
  ) async {
    final device = _FakeDevice();
    final alerts = SilentCallAlerts();
    final service = await _started(tester, device: device);
    service.alerts = alerts;

    device.ring('+13055550123');
    await tester.pump();

    expect(alerts.rang, ['+13055550123']);
    expect(service.incomingFrom, '+13055550123');

    service.accept();
    await tester.pump();

    expect(alerts.stopped, 1, reason: 'the tone outlived the ringing');
    expect(service.incomingFrom, isNull);

    service.stop();
  });

  /// Every way a call stops arriving stops the sound.
  ///
  /// The commonest is not a button: a colleague answers first, or the caller
  /// gives up. A tone that outlived the call it announced would be the stuck
  /// incoming bar again, wearing a sound.
  testWidgets('declining, hanging up and the other end going away all silence it', (
    tester,
  ) async {
    for (final ending in ['decline', 'hangUp', 'ended']) {
      final device = _FakeDevice();
      final alerts = SilentCallAlerts();
      final service = await _started(tester, device: device);
      service.alerts = alerts;

      device.ring('+13055550123');
      await tester.pump();
      expect(alerts.rang, hasLength(1), reason: 'never rang for $ending');

      switch (ending) {
        case 'decline':
          service.decline();
        case 'hangUp':
          service.hangUp();
        case 'ended':
          device.end();
      }
      await tester.pump();

      expect(
        alerts.stopped,
        greaterThan(0),
        reason: 'still ringing after $ending',
      );
      expect(service.incomingFrom, isNull, reason: 'bar stuck after $ending');

      service.stop();
    }
  });

  /// Signing out takes the sound with it.
  ///
  /// A tone still ringing on the sign-in screen belongs to a call the next
  /// person at this machine cannot answer and did not receive.
  testWidgets('signing out silences a call that is still ringing', (
    tester,
  ) async {
    final device = _FakeDevice();
    final alerts = SilentCallAlerts();
    final service = await _started(tester, device: device);
    service.alerts = alerts;

    device.ring('+13055550123');
    await tester.pump();

    service.stop();
    await tester.pump();

    expect(alerts.stopped, greaterThan(0));
    expect(service.incomingFrom, isNull);
  });

  /// A handset asks for a token that can wake it.
  ///
  /// ── The defect ─────────────────────────────────────────────────
  ///
  /// Twilio can only ring a locked phone by sending a VoIP push, and it only
  /// sends one when the access token names a push credential. The token
  /// request said nothing about what was asking, so every token was minted for
  /// a browser: the TestFlight build registered, dialled out, showed itself
  /// connected, and never rang.
  ///
  /// Asserted on the REQUEST rather than on the device, because the server is
  /// what decides which credential goes in the grant and this is the only
  /// sentence that reaches it.
  testWidgets('a handset asks for a token for its own platform', (
    tester,
  ) async {
    String? body;
    final device = _FakeDevice()..pushPlatform = 'ios';
    final service = await _started(
      tester,
      device: device,
      onToken: (sent) => body = sent,
    );

    expect(
      body,
      contains('"platform":"ios"'),
      reason:
          'the token request named no platform, so it was minted without a '
          'push credential and nothing can wake this phone',
    );

    service.stop();
  });

  /// And a browser does not.
  ///
  /// The control. Without it, a client that hardcoded a platform would pass
  /// the test above while asking for an APNs credential on every desktop.
  testWidgets('a browser asks for a token without one', (tester) async {
    String? body;
    final service = await _started(
      tester,
      device: _FakeDevice(),
      onToken: (sent) => body = sent,
    );

    expect(body, isNotNull, reason: 'no token was ever requested');
    expect(body, isNot(contains('platform')));

    service.stop();
  });
}

/// A service that has been through the real [PhoneCallService.start].
///
/// Needs a WidgetTester because start() awaits `endOfFrame` before it
/// registers, and outside a pumped test no frame ever comes — the await simply
/// never returns. That await is not ceremony: it is what puts the WebView2
/// host in the tree before the microphone is asked for.
Future<PhoneCallService> _started(
  WidgetTester tester, {
  required VoiceDevice device,
  void Function(String body)? onToken,
}) async {
  final service = PhoneCallService.forTest(device)..stop();
  final starting = service.start(_apiFor(onToken: onToken), device: device);

  await tester.pumpAndSettle();
  await starting;

  return service;
}

ApiClient _apiFor({void Function(String body)? onToken}) => ApiClient(
  client: MockClient((request) async {
    final path = request.url.path;

    if (path.endsWith('/phone/numbers')) {
      return _json([
        {
          'id': 'n-1',
          'connection_id': 'cx-1',
          'e164': '+12035550142',
          'label': 'Mine',
          'assigned_to_me': true,
        },
      ]);
    }
    if (path.endsWith('/phone/tokens')) {
      onToken?.call(request.body);

      return http.Response(
        jsonEncode({'token': 'a-token'}),
        200,
        headers: {'content-type': 'application/json'},
      );
    }

    return _json(const []);
  }),
);

http.Response _json(Object body) => http.Response(
  jsonEncode({'data': body}),
  200,
  headers: {'content-type': 'application/json'},
);

class _FakeDevice implements VoiceDevice {
  @override
  void Function(VoiceIncoming incoming)? onIncoming;

  @override
  void Function()? onRegistrationChanged;

  @override
  var supported = true;

  @override
  var registered = false;

  @override
  String? registrationError;

  @override
  Object? get platformView => null;

  @override
  String? pushPlatform;

  @override
  void Function()? onEnded;

  String? callerId;
  String? connectedTo;
  var accepted = false;
  var declined = false;
  var disposed = false;

  /// The order IS the bug — see the ordering test above.
  var prepareCalledBeforeSetup = false;
  var _prepared = false;

  @override
  Future<void> prepare() async => _prepared = true;

  @override
  Future<void> setup(String token) async {
    prepareCalledBeforeSetup = _prepared;
    registered = true;
    onRegistrationChanged?.call();
  }

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

  @override
  void dispose() {
    disposed = true;
    onIncoming = null;
    onEnded = null;
  }

  void ring(String from) => onIncoming?.call(VoiceIncoming(from: from));

  /// The other end going away — a caller giving up, a colleague answering
  /// first, or the customer hanging up on a live call. All three arrive here.
  void end() => onEnded?.call();
}
