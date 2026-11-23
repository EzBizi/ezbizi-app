import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_identity.dart';
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
  _otherAccountsCase();

  // start() awaits a frame before it registers — see the ordering test — so
  // these need a binding even though none of them build a widget.
  /// A desk that has been running for an hour must not go quiet.
  ///
  /// A Twilio access token lasts 3600 seconds. The app asked for one, at
  /// launch, and nothing ever asked again — so an hour in, the registration
  /// lapsed and the desk stopped receiving calls. Nothing said so: no error,
  /// no banner, and outbound calls kept working, which is what hid it.
  ///
  /// Measured on production 2026-09-17: three of the seven `<Client>` legs on
  /// the busiest line went to identities nothing was registered as, and 27 of
  /// 29 inbound calls over two days rang the full sixty seconds into
  /// voicemail.
  ///
  /// The browser had been announcing the expiry all along — the bridge raises
  /// `tokenExpiring` with a comment saying Dart refetches and calls setup
  /// again. Dart did not. This is the half that was missing.
  testWidgets('an expiring token is replaced, and the device re-registers', (
    tester,
  ) async {
    final device = _FakeDevice();
    final bodies = <String>[];
    final service = await _started(tester, device: device, onToken: bodies.add);
    expect(device.setups, 1, reason: 'the initial registration');
    expect(bodies, hasLength(1));

    device.onTokenExpiring!();
    await tester.pumpAndSettle();

    expect(device.setups, 2, reason: 'the desk did not re-register');
    expect(bodies, hasLength(2), reason: 'no second token was asked for');

    // The renewal must carry the device id, or the new identity names only the
    // person and rings whichever device Twilio picks — the bug that left a PC
    // silent while a phone in a pocket rang. Asserted because a second copy of
    // this request is exactly where that gets forgotten.
    expect(bodies.last, contains('device_id'));

    service.stop();
  });

  /// A CALL THE PLATFORM REFUSED IS NOT A CALL.
  ///
  /// Android will not place an outbound call without CALL_PHONE, and this app
  /// never asked for it — the plugin logs "Missing CALL_PHONE permission" and
  /// returns, with nothing reaching Dart. The first Android outbound call that
  /// connected (0.0.0277) did so only on an emulator where the permission had
  /// been granted by hand.
  ///
  /// The half pinned here is the desk's: a refusal must NOT mark this person
  /// on a call. `on_call` takes somebody out of their own ring group, and it
  /// would have been set for a call that never started.
  testWidgets('a refused call leaves the desk off a call, and says why', (
    tester,
  ) async {
    final device = _FakeDevice()
      ..refusesCalls =
          'Android has not given EzBizi permission to place calls.';
    final service = await _started(tester, device: device);

    await service.connect(to: '+13055550100', callerId: '+12035550142');

    expect(
      service.onCall,
      isFalse,
      reason: 'marked on a call that never started',
    );
    expect(service.callRefusal, contains('permission to place calls'));

    // In the body, as every other case in this file does. The helper's
    // `addTearDown` runs after the binding checks for pending timers, so it
    // cannot clear the renewal timer in time.
    service.stop();
  });

  /// The control: a call the platform places DOES put the desk on a call.
  ///
  /// Without it the case above passes on a desk that never marks anybody on a
  /// call at all.
  testWidgets('a placed call puts the desk on a call', (tester) async {
    final device = _FakeDevice();
    final service = await _started(tester, device: device);

    await service.connect(to: '+13055550100', callerId: '+12035550142');

    expect(service.onCall, isTrue);
    expect(service.callRefusal, isNull);

    service.stop();
  });

  /// A CALL THAT FAILS ON THE DEVICE, after Call returned, says why.
  ///
  /// Jose, 2026-09-29: Call "tries to open up something then nothing
  /// happens". On Windows connect() returns before the softphone page has
  /// tried; the page then found no microphone and its error was dropped.
  testWidgets('a call that fails on the device says why and ends', (
    tester,
  ) async {
    final device = _FakeDevice();
    final service = await _started(tester, device: device);

    await service.connect(to: '+13055550100', callerId: '+12035550142');
    expect(service.onCall, isTrue);

    device.onCallFailed!('No microphone found.');

    expect(service.onCall, isFalse, reason: 'left on a call that failed');
    expect(service.callRefusal, 'No microphone found.');

    service.stop();
  });

  /// A HANDSET GETS NO EXPIRY EVENT, so the desk renews on the clock.
  ///
  /// Android reported this on 2026-09-17 with the greps: `tokenWillExpire`
  /// exists only in the `twilio_voice` plugin's web half, and its Kotlin has
  /// no expiry callback at all. So `onTokenExpiring` was assigned on every
  /// platform and fired by two — worse than missing, because the assignment
  /// made the wiring look complete everywhere.
  ///
  /// The lifetime comes from the SERVER (`expires_in`), not from a constant
  /// here: a second copy of `TwilioGateway::TOKEN_TTL_SECONDS` would keep
  /// renewing too late the day somebody shortened it, silently.
  ///
  /// Pinned five minutes early rather than at the hour, because renewing at
  /// the moment of expiry is renewing after it once a round trip and a
  /// registration are counted. The pump at 54 minutes is the half that can
  /// tell an early renewal from a late one.
  testWidgets('the token is renewed on a timer, before it expires', (
    tester,
  ) async {
    final device = _FakeDevice();
    final bodies = <String>[];
    final service = await _started(tester, device: device, onToken: bodies.add);
    expect(device.setups, 1);

    // Not yet. A build renewing on any old cadence passes the assertion below
    // on its own; this is the one that says WHEN — five minutes before the
    // half hour the server named, so 25 minutes.
    await tester.pump(const Duration(minutes: 24));
    expect(device.setups, 1, reason: 'renewed earlier than five minutes ahead');

    await tester.pump(const Duration(minutes: 2));

    expect(device.setups, 2, reason: 'the handset was never renewed at all');
    expect(bodies, hasLength(2));
    expect(bodies.last, contains('device_id'));

    service.stop();
  });

  /// And it keeps going, because one renewal buys one more hour.
  ///
  /// A timer that is scheduled at start-up and never again produces a desk
  /// that survives two hours and dies in the third — which is the original
  /// defect with a longer fuse and would read as something else entirely.
  testWidgets('the renewal reschedules itself', (tester) async {
    final device = _FakeDevice();
    final service = await _started(tester, device: device);
    await tester.pump(const Duration(minutes: 26));
    expect(device.setups, 2);

    await tester.pump(const Duration(minutes: 26));
    expect(device.setups, 3, reason: 'the desk renewed once and then stopped');

    service.stop();
  });

  /// THE CASE THAT MUST NOT WORK.
  ///
  /// `setup()` rebuilds the device, and on the web that calls
  /// `Device.destroy()`, which REJECTS the call in progress — Twilio then
  /// reports the leg busy. Renewing during a conversation would end it in the
  /// worst possible way, which is the same failure that cost Jose a customer
  /// call on 2026-09-15.
  ///
  /// And the debt must not be dropped either: a desk that skipped its renewal
  /// is holding a token it already knows is dead. So the second half asserts
  /// it is paid when the call ends — for a call that only RANG, because that
  /// is the common case and the one the first version of this missed by paying
  /// the debt somewhere only connected calls reach.
  testWidgets('a renewal waits for the call to end, and then happens', (
    tester,
  ) async {
    final device = _FakeDevice();
    final bodies = <String>[];
    final service = await _started(tester, device: device, onToken: bodies.add);
    device.onIncoming!(const VoiceIncoming(from: '+13055550100'));
    await tester.pumpAndSettle();
    expect(service.busy, isTrue, reason: 'the fixture is not actually busy');

    device.onTokenExpiring!();
    await tester.pumpAndSettle();

    expect(device.setups, 1, reason: 'a live call was torn down to renew');
    expect(bodies, hasLength(1));

    device.onEnded!();
    await tester.pumpAndSettle();

    expect(device.setups, 2, reason: 'the deferred renewal was dropped');
    expect(bodies, hasLength(2));

    service.stop();
  });

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
      reason:
          'the desk still thinks it is on a call, so it is still telling '
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
  testWidgets(
    'declining, hanging up and the other end going away all silence it',
    (tester) async {
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
    },
  );

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

  /// Answering on the handset's own screen puts the desk on a call.
  ///
  /// ── The defect ─────────────────────────────────────────────────
  ///
  /// On a phone the operating system owns answering, so the desk's accept
  /// button is never pressed. Jose answered in CallKit and was then asked to
  /// accept the same call again in the app.
  ///
  /// Both halves are asserted, and the second is the one that bites: clearing
  /// the banner without marking the call would take this person off `on_call`
  /// at the moment the conversation started, and the ring group would route
  /// the next caller to somebody mid-sentence.
  testWidgets('a call answered on the handset is a call the desk is on', (
    tester,
  ) async {
    final device = _FakeDevice();
    final service = await _started(tester, device: device);

    device.ring('+13055550199');
    await tester.pump();
    expect(service.incomingFrom, isNotNull);

    device.answerNatively();
    await tester.pump();

    expect(
      service.incomingFrom,
      isNull,
      reason: 'the desk still offers to accept a call already in progress',
    );
    expect(
      service.onCall,
      isTrue,
      reason:
          'the desk cleared the banner without going on call, so this person '
          'reads as free while they are talking',
    );

    service.stop();
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

  /// The token request names this install, so a call can ring it.
  ///
  /// ── The defect ─────────────────────────────────────────────────
  ///
  /// A `<Dial>` makes exactly one call leg per `<Client>` noun, so an identity
  /// naming only a person rang whichever of their devices Twilio picked. Jose
  /// had the desk open on a PC and the app on an iPhone, both registered, and
  /// only the iPhone rang. The server can only dial both if the client says
  /// which install is asking.
  testWidgets('the token request says which device is asking', (tester) async {
    String? body;
    final service = await _started(
      tester,
      device: _FakeDevice(),
      onToken: (sent) => body = sent,
    );

    expect(body, isNotNull, reason: 'no token was ever requested');

    final sent = jsonDecode(body!) as Map<String, dynamic>;
    final id = (sent['device_id'] as String?) ?? '';

    expect(
      id,
      isNotEmpty,
      reason:
          'the server cannot tell this install from any other, so it can '
          'only ring one of them',
    );

    // Hex only: it becomes half of a Twilio client identity, and those are
    // alphanumeric. A stray hyphen makes an identity the SDK will not accept.
    expect(
      RegExp(r'^[0-9a-f]+$').hasMatch(id),
      isTrue,
      reason: 'device id "$id" is not usable inside an identity',
    );

    expect((sent['device_label'] as String?) ?? '', isNotEmpty);

    service.stop();
  });

  /// The same install twice is the same name.
  ///
  /// A fresh id per launch would leave a trail of registrations, and ten of
  /// them would fill the dial list with dead endpoints while the caller waits
  /// out the timeout.
  test('the device id is stable across reads', () {
    final first = DeviceIdentity.value;

    DeviceIdentity.forget();

    expect(DeviceIdentity.value, first);
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
/// EverDoors' "Owners" ring group rang nobody: a token belongs to one Twilio
/// account, and the desk registered only on its own line's (Jose 2026-11-11).
/// So it registers once more per other account, and a call ringing there is
/// answered on THAT device.
void _otherAccountsCase() {
  testWidgets(
    'the desk registers on every account and answers on the one that rang',
    (tester) async {
      final primary = _FakeDevice();
      final extra = _FakeDevice();
      final presence = <String>[];
      final api = ApiClient(
        client: MockClient((request) async {
          final path = request.url.path;
          if (path.endsWith('/phone/numbers')) {
            return _json([
              {
                'id': 'n-1',
                'connection_id': 'cx-1',
                'e164': '+12035550142',
                'assigned_to_me': true,
              },
            ]);
          }
          if (path.endsWith('/phone/registrations')) {
            return _json([
              {'connection_id': 'cx-1'},
              {'connection_id': 'cx-everdoors'},
            ]);
          }
          if (path.endsWith('/phone/tokens')) {
            return http.Response(
              jsonEncode({'token': 'a-token', 'expires_in': 1800}),
              200,
              headers: {'content-type': 'application/json'},
            );
          }
          if (path.endsWith('/phone/presence') && request.method == 'PUT') {
            presence.add(
              (jsonDecode(request.body) as Map)['connection_id'] as String,
            );
          }
          return _json(const []);
        }),
      );

      final service = PhoneCallService.forTest(primary)..stop();
      service.newDevice = () => extra;
      final starting = service.start(api, device: primary);
      await tester.pumpAndSettle();
      await starting;
      await tester.pumpAndSettle();

      expect(extra.setups, 1, reason: 'the other account was never registered');
      expect(presence, containsAll(['cx-1', 'cx-everdoors']));

      extra.ring('+15555550100');
      expect(service.incomingFrom, '+15555550100');
      service.accept();
      expect(extra.accepted, isTrue, reason: 'answered on the wrong device');
      expect(primary.accepted, isFalse);

      service.stop();
      expect(extra.disposed, isTrue);
    },
  );
}

Future<PhoneCallService> _started(
  WidgetTester tester, {
  required VoiceDevice device,
  void Function(String body)? onToken,
}) async {
  final service = PhoneCallService.forTest(device)..stop();
  final starting = service.start(_apiFor(onToken: onToken), device: device);

  await tester.pumpAndSettle();
  await starting;

  // EVERY started desk now holds a renewal timer, and `testWidgets` fails a
  // test that ends with one pending. Registered here rather than in each case
  // because it is a property of having started the desk, not of what any one
  // test does with it — four existing cases went red before this line existed,
  // none of them about renewal.
  addTearDown(service.stop);

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
        // HALF an hour, and deliberately not the 3600 the real endpoint
        // sends. `TwilioGateway::TOKEN_TTL_SECONDS` is 3600, so a fixture
        // saying 3600 cannot tell a desk that READS this field from one that
        // hard-codes an hour — both would renew at the same moment and the
        // test would pass on the copy that drifts. A value only the server
        // could have supplied is what makes the assertion mean anything.
        jsonEncode({'token': 'a-token', 'expires_in': 1800}),
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
  void Function()? onTokenExpiring;

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

  var setups = 0;
  String? lastToken;

  @override
  Future<void> setup(String token) async {
    prepareCalledBeforeSetup = _prepared;
    setups++;
    lastToken = token;
    registered = true;
    onRegistrationChanged?.call();
  }

  @override
  Future<void> connect({required String to, String? callerId}) async {
    // What Android does without CALL_PHONE, once the device says so out loud.
    final refuse = refusesCalls;
    if (refuse != null) throw CallRefused(refuse);

    connectedTo = to;
    this.callerId = callerId;
  }

  /// Set to make `connect` refuse the way an Android phone without CALL_PHONE
  /// now does.
  String? refusesCalls;

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

  /// Answered in CallKit or the ConnectionService, where a handset's calls are
  /// actually answered — nothing in the app was pressed.
  void answerNatively() => onAnswered?.call();

  /// The other end going away — a caller giving up, a colleague answering
  /// first, or the customer hanging up on a live call. All three arrive here.
  void end() => onEnded?.call();
}
