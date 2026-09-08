import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
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
}) async {
  final service = PhoneCallService.forTest(device)..stop();
  final starting = service.start(_apiFor(), device: device);

  await tester.pumpAndSettle();
  await starting;

  return service;
}

ApiClient _apiFor() => ApiClient(
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
  }

  void ring(String from) => onIncoming?.call(VoiceIncoming(from: from));
}
