import 'dart:async';

import 'package:flutter/material.dart';

import '../screens/phone/voice_device.dart';
import 'api_client.dart';
import 'device_identity.dart';
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

    // Nullable, because "a device that is up with no line recorded" is a real
    // state and a dangerous one: it is what a desk looks like between stop()
    // and the start() that follows, and it is the state in which the shell
    // reaches for a line. A seam that could not express it could not test it.
    String? connectionId = 'cx-test',

    // Without an api the desk cannot RELEASE presence, so every test built on
    // this harness silently skipped the request that clears `on_call` on the
    // server — and a stuck `on_call` takes somebody out of the ring group
    // until they restart the app. Optional, so existing callers are unchanged,
    // but a test about what happens when a call ends wants one.
    ApiClient? api,
  }) {
    final service = PhoneCallService._();
    service._device = device;
    service._api = api;
    service._callerId = callerId;
    service._connectionId = connectionId;
    service._started = true;
    service._alerts = SilentCallAlerts();
    device.onIncoming = (incoming) => service._ringing(incoming.from);
    device.onRegistrationChanged = service.notifyListeners;
    device.onEnded = service._ended;
    device.onAnswered = service._answeredElsewhere;
    device.onCallFailed = service._callFailed;

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

  /// Every OTHER phone account this person takes calls on, one device each.
  ///
  /// A Twilio token belongs to one account, so a ring group on another
  /// account (EverDoors' "Owners", Jose 2026-11-11) could not reach the one
  /// device registered on the person's own line. These only receive; outbound
  /// stays on [_device].
  final Map<String, VoiceDevice> _extras = {};
  final Map<String, Timer> _extraRenewals = {};

  /// The extra device a call came in on, while it rings or is answered.
  VoiceDevice? _active;
  String? _activeConnection;

  VoiceDevice? get _live => _active ?? _device;

  /// Builds the extra devices; a test hands in fakes.
  @visibleForTesting
  VoiceDevice Function() newDevice = createVoiceDevice;

  /// The extra devices' views, which Windows must mount like [platformView].
  List<Object?> get extraViews => [
    for (final device in _extras.values) device.platformView,
  ];

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

    // ONE RING PER CALL. On a handset the OS has already started ringing —
    // see [VoiceDevice.ringsItself] for the whole rule and why it is one
    // sentence rather than a fix per platform.
    if (_device?.ringsItself ?? false) return;

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

  /// Bumped once the server has actually been told a call is over.
  ///
  /// ── The race this closes ─────────────────────────────────────────
  ///
  /// Ending a call cleared `_onCall` and notified listeners FIRST, then fired
  /// the request that clears `on_call` on the server. The app bar's listener
  /// refetches presence when a call ends — so it read the server while the
  /// release was still in flight, was told `on_call`, believed it, and never
  /// asked again. The icon stayed on a call that had ended until the app was
  /// restarted, on desktop and on mobile.
  ///
  /// Notifying twice is not enough on its own: the bar only reloads when
  /// `onCall` CHANGES, and by then it already has. So the second notify
  /// carries a token, and anything that reads presence from the server watches
  /// this rather than guessing at timing.
  int get presenceToken => _presenceToken;
  int _presenceToken = 0;

  /// Somebody else may have changed this person's availability.
  ///
  /// Availability belongs to the PERSON, and they can be signed in on a
  /// laptop, a desktop and a phone at once. Every one of those writes the
  /// server; none of them hears about the others. Whoever is holding a clock
  /// calls this and anything reading presence goes and asks again.
  void presenceMayHaveChanged() {
    _presenceToken++;
    notifyListeners();
  }

  /// This person's availability as the server last said: `registered`,
  /// `dnd`, `on_call`, `wrap_up`. Null until the Availability button has
  /// read it.
  String? get presence => _presence;
  String? _presence;

  /// Whether this person has a line to take calls on in the app. Someone
  /// without one never takes a call here.
  bool get presenceHasLine => _presenceHasLine;
  bool _presenceHasLine = false;

  /// Reported by the Availability button, which is what reads and writes
  /// presence, so others (the desktop updater) can follow it.
  void notePresence({required bool hasLine, required String state}) {
    if (hasLine == _presenceHasLine && state == _presence) return;
    _presenceHasLine = hasLine;
    _presence = state;
    notifyListeners();
  }

  /// The desk answered Twilio and can take a call.
  bool get registered => _device?.registered ?? false;

  /// Twilio's own reason when it did not, or null when it gave none.
  String? get registrationError => _device?.registrationError;

  /// Whether the desk should offer to open the system's calling settings —
  /// the one registration failure a retry cannot fix on its own.
  bool get needsCallingAccount => _device?.needsCallingAccount ?? false;

  Future<void> openCallingSettings() async =>
      _device?.openCallingSettings() ?? Future<void>.value();

  /// False on a platform with no Voice SDK at all.
  bool get supported => _device?.supported ?? false;

  /// Whether a line was found for this person. False means there is nothing to
  /// register and no phone to draw anywhere.
  bool get hasLine => _connectionId != null;

  /// The desk is coming up right now.
  ///
  /// It is torn down and rebuilt whenever the view changes company, and for
  /// that second everything below is false. Without this the desk-down banner
  /// flashes red across the top of the screen on every scope switch, which
  /// teaches people to ignore the one banner that matters.
  bool get starting => _starting;

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
  Future<void> start(ApiClient api, {VoiceDevice? device}) async {
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
      _device!.onCallFailed = _callFailed;
      _device!.onTokenExpiring = () => unawaited(_renewToken());

      // prepare(), then a FRAME, then the token. platformView is null until
      // prepare() has built the controller, so the shell cannot mount the
      // widget before this — and it is the mounted widget that the microphone
      // prompt is delivered to. Without the frame this is the same refusal
      // with an extra method in front of it.
      await _device!.prepare();
      notifyListeners();
      await WidgetsBinding.instance.endOfFrame;

      await _mintTokenAndRegister(api);

      _started = true;
      notifyListeners();

      await api.put('/phone/presence', {
        'connection_id': _connectionId,
        'state': 'registered',
      });

      unawaited(syncAccounts());
    } finally {
      _starting = false;
    }
  }

  /// Register on every phone account GET /phone/registrations lists, and drop the
  /// ones it no longer does. Called at start and whenever access changes.
  Future<void> syncAccounts() async {
    final api = _api;
    final primary = _device;
    if (api == null || primary == null || busy) return;

    // ponytail: a handset keeps its one account; the twilio_voice plugin holds
    // a single registration. Per-account push needs a plugin that allows more.
    if (primary.pushPlatform != null) return;

    final response = await api.get('/phone/registrations');
    if (!response.ok || _api != api) return;
    final want = <String>{
      for (final row
          in (response.data is List ? response.data as List : const []))
        if (row is Map) (row['connection_id'] ?? '').toString(),
    }..removeAll(['', ?_connectionId]);

    for (final id in _extras.keys.where((id) => !want.contains(id)).toList()) {
      _dropExtra(id);
    }

    for (final id in want.where((id) => !_extras.containsKey(id))) {
      final device = newDevice();
      _extras[id] = device;
      device.onIncoming = (incoming) {
        // One call at a time: the caller goes on to the next person.
        if (busy) {
          device.declineIncoming();
          return;
        }
        _active = device;
        _activeConnection = id;
        _ringing(incoming.from);
      };
      device.onRegistrationChanged = notifyListeners;
      device.onEnded = _ended;
      device.onAnswered = _answeredElsewhere;
      device.onCallFailed = _callFailed;
      device.onTokenExpiring = () => unawaited(_registerExtra(api, id));

      await device.prepare();
      notifyListeners();
      await WidgetsBinding.instance.endOfFrame;
      await _registerExtra(api, id);
      if (_extras[id] != device) continue;

      await api.put('/phone/presence', {
        'connection_id': id,
        'state': 'registered',
      });
    }
  }

  /// Mint this account's token and register; renew five minutes early, never mid-call.
  Future<void> _registerExtra(ApiClient api, String id) async {
    final device = _extras[id];
    if (device == null) return;
    _extraRenewals.remove(id)?.cancel();

    if (_active == device) {
      _extraRenewals[id] = Timer(
        const Duration(minutes: 1),
        () => unawaited(_registerExtra(api, id)),
      );
      return;
    }

    final token = await api.post('/phone/tokens', {
      'connection_id': id,
      'device_id': DeviceIdentity.value,
      'device_label': DeviceIdentity.label,
    });
    if (!token.ok || _extras[id] != device) return;

    final value = token.body['token'] as String? ?? '';
    if (value.isEmpty) return;
    await device.setup(value);
    if (_extras[id] != device) return;

    final seconds = int.tryParse('${token.body['expires_in']}') ?? 0;
    if (seconds <= 0) return;
    final ahead = seconds - 300;
    _extraRenewals[id] = Timer(
      Duration(seconds: ahead < 60 ? 60 : ahead),
      () => unawaited(_registerExtra(api, id)),
    );
  }

  void _dropExtra(String id) {
    _extraRenewals.remove(id)?.cancel();
    final device = _extras.remove(id);
    if (device == null) return;
    if (_active == device) {
      _active = null;
      _activeConnection = null;
    }
    device.onIncoming = null;
    device.onRegistrationChanged = null;
    device.onEnded = null;
    device.onCallFailed = null;
    device.dispose();
  }

  void _dropExtras() {
    for (final id in _extras.keys.toList()) {
      _dropExtra(id);
    }
  }

  /// Ask for a token and hand it to the device.
  ///
  /// ONE COPY, because there are now two callers — the first registration and
  /// every renewal after it — and a second copy of this request is a second
  /// place to forget the device id or the push platform. Both of those have
  /// already cost a platform that registered and never rang.
  Future<void> _mintTokenAndRegister(ApiClient api) async {
    final device = _device;
    if (device == null) return;

    final token = await api.post('/phone/tokens', {
      'connection_id': _connectionId,

      // WHICH INSTALL IS ASKING, so a call can ring this one as well as the
      // others. A `<Dial>` makes one leg per `<Client>`, so an identity that
      // named only the person rang whichever device Twilio picked — the desk
      // open on a PC stayed silent while the phone in a pocket rang.
      'device_id': DeviceIdentity.value,
      'device_label': DeviceIdentity.label,
      // Omitted on a browser. Present on a handset, and it is what makes the
      // minted token carry a push credential — without it the phone
      // registers and never rings.
      if (device.pushPlatform != null) 'platform': device.pushPlatform,
    });

    if (!token.ok) return;

    final value = token.body['token'] as String? ?? '';
    if (value.isEmpty) return;

    await device.setup(value);

    // STILL THE LIVE DESK. A renewal is several awaits long, and somebody can
    // sign out inside it — `stop()` cancels the pending timer, and without
    // this check the request already in flight would schedule a fresh one
    // afterwards, for a session that no longer exists. Found by four tests
    // ending with a pending timer, which is the same fact wearing a test
    // harness.
    if (_api == null || _device != device) return;

    _scheduleRenewal(token.body['expires_in']);
  }

  /// The next renewal, on the clock, because two platforms get no event.
  Timer? _renewal;

  /// Ask again before this token dies, whatever the platform tells us.
  ///
  /// ── Why a clock and not only an event ───────────────────────────
  ///
  /// The browser and the WebView are told by their SDK that a token is about
  /// to expire, and [VoiceDevice.onTokenExpiring] carries that. A HANDSET is
  /// not: Android's `twilio_voice` plugin surfaces `tokenWillExpire` in its
  /// web half only, and its Kotlin has no expiry callback at all — reported by
  /// the Android session on 2026-09-17, with the greps.
  ///
  /// So the field was assigned on every platform and fired on two, which is
  /// worse than missing it: the assignment made the wiring look complete
  /// everywhere. On a phone the renewal has to be predicted rather than
  /// awaited, and a clock is the only instrument available.
  ///
  /// ── Why the SERVER says how long ────────────────────────────────
  ///
  /// `expires_in` comes back with the token. A hard-coded hour here would be a
  /// second copy of a number that lives in `TwilioGateway::TOKEN_TTL_SECONDS`,
  /// and the day somebody shortens that, this would keep renewing too late
  /// and every handset would go quiet again with nothing failing.
  ///
  /// ── Why five minutes early, and a floor ─────────────────────────
  ///
  /// Renewing at the moment of expiry is renewing after it, once a round trip
  /// and a registration are counted. Five minutes is enough for both and short
  /// enough that the token is nearly all used. The floor stops an absurd or
  /// missing value from producing a timer that fires continuously — a
  /// renewal storm re-registers the device over and over, which would take the
  /// phone down rather than keep it up.
  void _scheduleRenewal(Object? expiresIn) {
    _renewal?.cancel();

    final seconds = switch (expiresIn) {
      int value => value,
      String value => int.tryParse(value) ?? 0,
      _ => 0,
    };

    // Nothing usable said. Better to leave the desk on the event, where there
    // is one, than to invent a cadence from a value the server did not send.
    if (seconds <= 0) return;

    final ahead = seconds - 300;
    _renewal = Timer(
      Duration(seconds: ahead < 60 ? 60 : ahead),
      () => unawaited(_renewToken()),
    );
  }

  /// A renewal fell due while somebody was talking, and is still owed.
  bool _tokenOwed = false;

  /// The token is about to expire, so get another one.
  ///
  /// ── Why this exists ─────────────────────────────────────────────
  ///
  /// A Twilio access token lasts 3600 seconds and the app asked for exactly
  /// one, at launch. An hour later the registration lapsed and the desk took
  /// no more incoming calls, showing nothing: no error, no banner, and
  /// outbound still working. The browser bridge had been announcing the
  /// expiry all along with nothing listening.
  ///
  /// ── NEVER MID-CALL ─────────────────────────────────────────────
  ///
  /// [VoiceDevice.setup] rebuilds the device, and on the web that means
  /// `Device.destroy()`, which REJECTS the call in progress — Twilio then
  /// reports that leg busy, which is the worst way for a conversation to end
  /// and exactly the failure that cost Jose a customer call on 2026-09-15.
  ///
  /// So a renewal that falls due during a call is deferred rather than
  /// dropped: [_releasePresence] runs at the end of every call and pays it.
  /// Dropping it would leave the desk holding a token it already knows is
  /// dead, which is the original defect with an extra step.
  Future<void> _renewToken() async {
    final api = _api;
    if (api == null) return;

    if (busy) {
      _tokenOwed = true;

      return;
    }

    _tokenOwed = false;
    await _mintTokenAndRegister(api);
    notifyListeners();
  }

  /// A call is in flight on this device, ringing or connected.
  ///
  /// The question anything about to tear the device down must ask. Tearing it
  /// down calls `Device.destroy()`, and destroy REJECTS a pending incoming
  /// call — so the caller does not hear it stop ringing, they hear the line
  /// refuse them and land in voicemail while somebody is looking at an Answer
  /// button.
  bool get busy => _onCall || _incomingFrom != null || _dialing;

  /// Between Call pressed and the platform placing it: not yet `onCall`, but
  /// closing the app now still drops the call being made.
  bool _dialing = false;

  /// Bring the desk up again after it failed.
  ///
  /// The "Try again" on the desk-down banner. Tears the old device down first:
  /// retrying on top of a half-registered one leaves two, and the second
  /// registration silently wins the routing.
  Future<void> restart(ApiClient api, {VoiceDevice? device}) async {
    _dropExtras();
    _device?.onIncoming = null;
    _device?.onRegistrationChanged = null;
    _device?.onEnded = null;
    _device?.onCallFailed = null;
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

    callRefusal = null;

    _dialing = true;
    notifyListeners();
    try {
      await device.connect(to: to, callerId: callerId);
    } on CallRefused catch (refused) {
      // NOT ON A CALL. Marking this person `on_call` for a call the platform
      // never placed takes them out of their own ring group until something
      // else clears it, and draws a Hang up button over nothing.
      callRefusal = refused.reason;
      notifyListeners();

      return;
    } finally {
      _dialing = false;
    }

    _onCall = true;
    notifyListeners();
  }

  /// Why the last Call pressed did not start one, when the platform said.
  ///
  /// Cleared at the start of the next attempt, so a sentence about a call that
  /// failed an hour ago does not sit on screen beside one that worked.
  String? callRefusal;

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

  /// Move the call I am on to my other signed-in devices.
  ///
  /// ── Why the server is asked rather than told ─────────────────
  ///
  /// This device knows its own leg, not the customer's, and the customer's is
  /// the one that has to move — redirecting our own would take the phone away
  /// from the person holding it and leave the customer bridged to nothing. So
  /// it sends WHO is asking and from WHERE, and the server finds the call.
  ///
  /// Returns null when it worked, and the reason when it did not — most often
  /// that the call ended while somebody was reaching for the button, which is
  /// a fact about the call rather than a fault.
  Future<String?> transferToMyOtherDevices() async {
    final api = _api;
    if (api == null) return 'The phone is not connected.';

    final response = await api.post('/phone/calls/transfer', {
      'from_device_id': DeviceIdentity.value,
    });

    if (response.ok) {
      // Deliberately NOT hanging up here. Twilio drops this leg when it
      // redirects the customer's, and the device tells us through onEnded —
      // ending it ourselves would race that and could hang up the call a
      // moment before the other phone answered.
      return null;
    }

    return response.reason;
  }

  void accept() {
    _live?.acceptIncoming();
    _stopRinging();
    _onCall = true;
    notifyListeners();
    unawaited(_silenceMyOtherDevices());
  }

  void decline() {
    _live?.declineIncoming();
    _stopRinging();
    notifyListeners();
    unawaited(_silenceMyOtherDevices());
  }

  /// This phone has dealt with the call. Stop the rest of mine ringing.
  ///
  /// ── What Jose reported ──────────────────────────────────────────
  ///
  /// 2026-09-17: "all 3 ring, i decline on desktop, it should update that it
  /// was declined on the others and stop ringing". Same for answering.
  ///
  /// ── Why the server has to do it ─────────────────────────────────
  ///
  /// Each of a person's devices is a separate `<Client>` leg, and only the
  /// server knows which legs belong to one human. Twilio ends the siblings
  /// when a Dial is ANSWERED, but declining must not end them — a leg one
  /// person refuses has to leave the call ringing for the colleagues it is
  /// also offered to, and Twilio cannot tell that from "my other phone has
  /// it".
  ///
  /// ── Why both paths post the same thing ──────────────────────────
  ///
  /// The rule is identical: end my legs except this device's. Two endpoints
  /// would be two chances to end the leg of the phone that just answered,
  /// which hangs up on a customer mid-sentence.
  ///
  /// Fire-and-forget on purpose. The call is already answered or refused on
  /// this device and the screen is already right; a failed request must not
  /// undo either. The worst case is the behaviour we had before, which is the
  /// other phones ringing on.
  Future<void> _silenceMyOtherDevices() async {
    final api = _api;
    if (api == null) return;

    await api.post('/phone/calls/silence', {
      'from_device_id': DeviceIdentity.value,
    });
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
  /// The call just placed failed on the device, after connect() returned.
  ///
  /// Said where Call was pressed, through [callRefusal], and then ended like
  /// any other call — which also takes the person off `on_call`, set the
  /// moment connect() returned.
  void _callFailed(String reason) {
    callRefusal = reason;
    _ended();
  }

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
    if (wasOnCall) unawaited(_releasePresence(_activeConnection));
    _active = null;
    _activeConnection = null;

    // A renewal deferred because a call was in flight — see [_renewToken].
    //
    // Paid HERE and not inside `_releasePresence`, which is the mistake this
    // comment exists to stop somebody repeating: that method runs only for a
    // call that was CONNECTED, and a token falling due while the phone was
    // merely ringing is owed exactly the same. Most calls ring and are never
    // connected, so the debt would have gone unpaid in the common case.
    if (_tokenOwed) unawaited(_renewToken());
  }

  /// Put the server back to whatever this person's standing answer is.
  ///
  /// Deliberately fire-and-forget: the desk is already correct on screen, and
  /// a failed write here must not leave the app believing it is still on a
  /// call. The next start() writes presence again regardless.
  Future<void> _releasePresence([String? on]) async {
    final api = _api;
    final connectionId = on ?? _connectionId;
    if (api == null || connectionId == null || connectionId.isEmpty) return;

    await api.put('/phone/presence', {
      'connection_id': connectionId,
      'state': 'registered',
    });

    // Now, and not before: anybody reading presence from the server before
    // this point is told the call is still going.
    _presenceToken++;
    notifyListeners();
  }

  /// Keys pressed on the live call, for a menu at the other end.
  void sendDigits(String digits) {
    final device = _live;
    if (device is SendsDigits && _onCall) {
      (device as SendsDigits).sendDigits(digits);
    }
  }

  void hangUp() {
    final wasOnCall = _onCall;

    _live?.hangUp();
    _onCall = false;
    _stopRinging();
    _callsToken++;
    notifyListeners();

    // Hanging up locally left `on_call` set on the server too — the same
    // stale-presence bug by a different route, and the one somebody would hit
    // every single call rather than only when the customer hung up first.
    if (wasOnCall) unawaited(_releasePresence(_activeConnection));
    _active = null;
    _activeConnection = null;

    // And the deferred token, for the same reason as in [_ended]: hanging up
    // is a way a call ends that does not go through it.
    if (_tokenOwed) unawaited(_renewToken());
  }

  /// Somebody signed out. The next person is not this person.
  void stop() {
    // Before anything else: a renewal that fires after sign-out would mint a
    // token for the person who just left.
    _renewal?.cancel();
    _renewal = null;
    _dropExtras();

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
