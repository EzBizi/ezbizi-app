import 'dart:async';

import 'package:flutter/material.dart';

import '../screens/phone/voice_device.dart';
import 'api_client.dart';
import 'call_alerts.dart';

/// The softphone, for as long as the app is open — not for as long as the Dial
/// tab is on screen.
///
/// ── Why this had to leave the Dial tab ───────────────────────────
///
/// The device used to be created, registered and DISPOSED by `CallTab`. The
/// shell renders only the selected module (main.dart, a plain conditional with
/// no IndexedStack and no keep-alive), so walking from Communication to Jobs
/// disposed the tab, and `dispose()` tore the device down with it. Registration
/// also only ever happened inside `CallTab._load()`.
///
/// Both halves of that are the same bug: between signing in and first opening
/// Communication, and again the moment you navigated away, Twilio had nowhere
/// to route a call. Nothing said so. The dial pad looked perfect when you came
/// back, because coming back is what registered it — so the failure could only
/// be seen by somebody ringing you from another phone while you were on a
/// different screen, which is not a thing that happens on a schedule.
///
/// A singleton, in the shape `DesktopUpdater.instance` already uses, because
/// the thing it owns is genuinely global: one desk, one registration, one
/// person. Two of these would be two phones fighting over one identity.
///
/// ── The platform view still has to be in the tree ────────────────
///
/// [platformView] is mounted by the SHELL now, at zero size, for the same
/// reason CallTab used to mount it: WebView2 hands the microphone prompt to
/// that widget's own delegate, and a device whose view never enters the tree
/// registers and then cannot hear anybody. Moving the device up without moving
/// the view up would have been the deaf-phone bug again, one layer higher.
class PhoneCallService extends ChangeNotifier {
  PhoneCallService._();

  static final PhoneCallService instance = PhoneCallService._();

  /// Swapped in tests. Never null once [start] has run.
  @visibleForTesting
  static PhoneCallService? overrideForTest;

  static PhoneCallService get current => overrideForTest ?? instance;

  /// A desk that is already up, for a widget test.
  ///
  /// Skips [start] entirely: no `/phone/numbers`, no token, no presence. A test
  /// that wanted a working phone otherwise had to stub three endpoints in the
  /// right order and still raced the frame await inside start().
  @visibleForTesting
  factory PhoneCallService.forTest(
    VoiceDevice device, {
    String callerId = '+12035550142',
    String connectionId = 'cx-test',
  }) {
    final service = PhoneCallService._();
    service._device = device;
    service._callerId = callerId;
    service._connectionId = connectionId;
    service._started = true;
    service._alerts = SilentCallAlerts();
    device.onIncoming = (incoming) => service._ringing(incoming.from);
    device.onRegistrationChanged = service.notifyListeners;
    device.onEnded = service._ended;

    return service;
  }

  /// How a human is told a call is arriving: the tone, the window, the toast.
  ///
  /// Injectable because the real one touches three plugins, and a widget test
  /// that had to have working audio to test a banner would be a test nobody
  /// could run. [SilentCallAlerts] is the default under [forTest].
  CallAlerts _alerts = SystemCallAlerts();

  @visibleForTesting
  set alerts(CallAlerts alerts) {
    _alerts.dispose();
    _alerts = alerts;
  }

  VoiceDevice? _device;

  String? _connectionId;
  String? _callerId;

  /// Kept from start(), because presence has to be written when a call ENDS
  /// and nothing is passing an api in at that point — the event comes from the
  /// device, not from a screen.
  ApiClient? _api;

  bool _starting = false;
  bool _started = false;

  /// A call is arriving. ONE place, so the two device wirings cannot drift.
  ///
  /// State first, then the alerts. Every alert is fire-and-forget and none is
  /// awaited: a plugin call awaited under a widget test's fake clock never
  /// returns, and a desk that could not draw its banner until the speaker
  /// answered would be worse than one that never made a sound.
  void _ringing(String from) {
    _incomingFrom = from;
    notifyListeners();

    _alerts.incoming(from);
  }

  /// Nothing is arriving any more, for any reason: answered, declined, given
  /// up on, or taken by somebody else in the group.
  void _stopRinging() {
    _incomingFrom = null;
    _alerts.stop();
  }

  /// Who is ringing, if anybody. Null the rest of the time.
  String? get incomingFrom => _incomingFrom;
  String? _incomingFrom;

  /// Audio is flowing, either way.
  bool get onCall => _onCall;
  bool _onCall = false;

  /// The desk answered Twilio and can take a call.
  bool get registered => _device?.registered ?? false;

  /// Twilio's own reason when it did not, or null when it gave none.
  String? get registrationError => _device?.registrationError;

  /// False on a platform with no Voice SDK at all.
  bool get supported => _device?.supported ?? false;

  /// Whether a line was found for this person. False means there is nothing to
  /// register and no phone to draw anywhere.
  bool get hasLine => _connectionId != null;

  /// The line calls go out on, for a screen that wants to name it.
  String? get callerId => _callerId;

  /// Mounted by the shell. Null on every platform but Windows.
  Object? get platformView => _device?.platformView;

  /// Bumped when a call ends, so a list that just gained a row can refetch
  /// without the screen holding it knowing where the call came from.
  int get callsToken => _callsToken;
  int _callsToken = 0;

  /// Bring the desk up. Safe to call repeatedly; only the first one works.
  ///
  /// Takes the connection rather than finding it, because the shell already
  /// knows whether this person has phone access and the service should not
  /// grow a second opinion about that.
  Future<void> start(
    ApiClient api, {
    VoiceDevice? device,
  }) async {
    if (_started || _starting) return;
    _starting = true;

    try {
      final numbers = await api.get('/phone/numbers');
      final rows = (numbers.data as List? ?? const []);
      if (rows.isEmpty) return;

      // Their own line first, by `assigned_to_me` — the field Team writes and
      // the one the Dial pad's picker already defaults to. Falling straight to
      // the first row is what once put four people's outbound calls on one
      // number, so every callback landed on whoever happened to answer.
      Map<String, dynamic>? mine;
      for (final row in rows) {
        if (row is! Map<String, dynamic>) continue;
        mine ??= row;
        if (row['assigned_to_me'] == true) {
          mine = row;
          break;
        }
      }
      if (mine == null) return;

      _api = api;
      _connectionId = (mine['connection_id'] ?? '').toString();
      _callerId = (mine['e164'] ?? '').toString();

      _device = device ?? createVoiceDevice();
      _device!.onIncoming = (incoming) => _ringing(incoming.from);
      _device!.onRegistrationChanged = notifyListeners;
      _device!.onEnded = _ended;
      _device!.onAnswered = _answeredElsewhere;

      // prepare(), then a FRAME, then the token. platformView is null until
      // prepare() has built the controller, so the shell cannot mount the
      // widget before this — and it is the mounted widget that the microphone
      // prompt is delivered to. Without the frame this is the same refusal
      // with an extra method in front of it.
      await _device!.prepare();
      notifyListeners();
      await WidgetsBinding.instance.endOfFrame;

      final token = await api.post('/phone/tokens', {
        'connection_id': _connectionId,
        // Omitted on a browser. Present on a handset, and it is what makes the
        // minted token carry a push credential — without it the phone
        // registers and never rings.
        if (_device!.pushPlatform != null) 'platform': _device!.pushPlatform,
      });

      if (token.ok) {
        final value = token.body['token'] as String? ?? '';
        if (value.isNotEmpty) await _device!.setup(value);
      }

      _started = true;
      notifyListeners();

      await api.put('/phone/presence', {
        'connection_id': _connectionId,
        'state': 'registered',
      });
    } finally {
      _starting = false;
    }
  }

  /// Bring the desk up again after it failed.
  ///
  /// The "Try again" on the desk-down banner. Tears the old device down first:
  /// retrying on top of a half-registered one leaves two, and the second
  /// registration silently wins the routing.
  Future<void> restart(ApiClient api, {VoiceDevice? device}) async {
    _device?.onIncoming = null;
    _device?.onRegistrationChanged = null;
    _device?.onEnded = null;
    _device?.dispose();
    _device = null;
    _started = false;
    _starting = false;
    notifyListeners();

    await start(api, device: device);
  }

  /// Place a call.
  ///
  /// [callerId] is REQUIRED and not defaulted. The desk's own line is the
  /// wrong answer whenever somebody has used the Calling as picker, and
  /// silently substituting it is precisely the defect that picker exists to
  /// prevent: four people ringing four customers under one number, every
  /// callback landing on whoever happened to answer.
  Future<void> connect({required String to, required String callerId}) async {
    final device = _device;
    if (device == null || !device.supported) return;

    await device.connect(to: to, callerId: callerId);
    _onCall = true;
    notifyListeners();
  }

  /// The handset's own call UI answered, so the desk stops offering to.
  ///
  /// Not [_ended]: there IS a call, and it has just started. Clearing the
  /// banner through the ended path would take this person off `on_call` at
  /// the moment the conversation began, which is the ring group quietly
  /// routing the next caller to somebody who is busy.
  void _answeredElsewhere() {
    _stopRinging();
    _onCall = true;
    notifyListeners();
  }

  void accept() {
    _device?.acceptIncoming();
    _stopRinging();
    _onCall = true;
    notifyListeners();
  }

  void decline() {
    _device?.declineIncoming();
    _stopRinging();
    notifyListeners();
  }

  /// The call ended and nobody here pressed anything.
  ///
  /// ── The two things this puts right ───────────────────────────
  ///
  /// The banner, which used to sit across every screen offering to answer a
  /// call that had already gone — a caller giving up, or a colleague in the
  /// ring group answering first.
  ///
  /// And `on_call` on the server, which is the one that cost something. Ring
  /// groups skip anybody on a call, so a stale one takes that person out of
  /// the rotation and their phone simply stops ringing. They cannot clear it
  /// themselves either: PresenceController::setMine refuses to overwrite
  /// `on_call` on purpose, so the availability control is powerless against
  /// it. Before this, only signing out and back in put somebody back in the
  /// rotation, and nothing on screen said that was what was needed.
  ///
  /// Writes `registered`, not the person's standing choice, because the desk
  /// does not hold that — availability is server state, read by the app bar.
  /// Somebody who had set Do Not Disturb had it overwritten by `on_call` when
  /// the call started, so `registered` is the honest restore: they are on the
  /// hook again and the control shows it. Do Not Disturb surviving a call is a
  /// separate question and belongs on the server, where the choice lives.
  void _ended() {
    final wasOnCall = _onCall;

    // Silence FIRST. The commonest way a call ends is somebody else in the
    // ring group answering it, and a tone that outlived the call it announced
    // would be the stuck incoming bar again, wearing a sound.
    _stopRinging();
    _onCall = false;
    _callsToken++;
    notifyListeners();

    // Only for a call that was actually connected. A ringing call that was
    // never answered never set `on_call`, and writing presence for it would be
    // a request per missed call for no change.
    if (wasOnCall) unawaited(_releasePresence());
  }

  /// Put the server back to whatever this person's standing answer is.
  ///
  /// Deliberately fire-and-forget: the desk is already correct on screen, and
  /// a failed write here must not leave the app believing it is still on a
  /// call. The next start() writes presence again regardless.
  Future<void> _releasePresence() async {
    final api = _api;
    final connectionId = _connectionId;
    if (api == null || connectionId == null || connectionId.isEmpty) return;

    await api.put('/phone/presence', {
      'connection_id': connectionId,
      'state': 'registered',
    });
  }

  void hangUp() {
    final wasOnCall = _onCall;

    _device?.hangUp();
    _onCall = false;
    _stopRinging();
    _callsToken++;
    notifyListeners();

    // Hanging up locally left `on_call` set on the server too — the same
    // stale-presence bug by a different route, and the one somebody would hit
    // every single call rather than only when the customer hung up first.
    if (wasOnCall) unawaited(_releasePresence());
  }

  /// Somebody signed out. The next person is not this person.
  void stop() {
    _device?.onIncoming = null;
    _device?.onRegistrationChanged = null;
    _device?.dispose();
    _device = null;
    _api = null;
    _connectionId = null;
    _callerId = null;

    // Signing out has to take the sound with it. A tone still ringing on the
    // sign-in screen belongs to a call the next person at this machine cannot
    // answer and did not receive.
    _stopRinging();
    _onCall = false;
    _started = false;
    notifyListeners();
  }

  /// Never disposed: it outlives every screen by design, and a
  /// ChangeNotifier disposed while the shell still listens throws on the next
  /// call that arrives.
  @override
  void dispose() {
    assert(false, 'PhoneCallService is a singleton; call stop() instead.');
    super.dispose();
  }
}
