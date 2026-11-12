import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:twilio_voice/twilio_voice.dart';

import 'voice_device.dart';

/// Why Android will or will not let this app carry a call.
///
/// Three answers rather than a bool, because two of the failures need
/// different sentences AND different actions from the person reading them.
/// Collapsing them sent somebody to the Calling accounts screen to fix a
/// permission that is not on it.
enum CallingAccountState {
  /// Registered with Telecom and switched on. Calls can be carried.
  ready,

  /// `READ_PHONE_STATE` / `READ_PHONE_NUMBERS` were refused. These are runtime
  /// permissions, so declaring them in the manifest grants nothing, and
  /// without them `hasRegisteredPhoneAccount()` cannot even answer — the whole
  /// check below is blind rather than false. Asking again is what fixes it,
  /// which is the button the desk already has.
  needsPermission,

  /// Registered, and the user has not switched it on under Calling accounts.
  /// Only they can, and only on that screen.
  needsEnabling,
}

/// The softphone on Android, iOS and macOS, through the native Twilio SDKs.
///
/// ── Why a plugin here and a WebView on Windows ───────────────────
///
/// Twilio ships a real SDK for Android and for iOS, and `twilio_voice` wraps
/// both — which buys the thing a WebView cannot give a phone: an incoming call
/// arrives through CallKit on iOS and a ConnectionService on Android, so it
/// rings like a phone call rather than like a notification, and it rings when
/// the app is closed. Windows has no SDK at all, which is why that platform
/// hosts the browser one instead.
///
/// ── What still has to be provisioned outside this file ───────────
///
/// An incoming call reaches a phone by push, so this only rings once Twilio
/// holds push credentials for the app: an APNs VoIP key for iOS and an FCM
/// service account for Android, attached to the TwiML application. Until then
/// outgoing calls work and incoming ones do not arrive — which is a
/// configuration state, not a defect, and [registered] reports it honestly
/// rather than claiming a phone that can take calls.
class MobileVoiceDevice implements VoiceDevice, SendsDigits {
  /// The plugin call, injectable so the failures below can be tested.
  ///
  /// Registration is a platform channel, and its two interesting outcomes —
  /// an error and a call that never returns — cannot be reached through the
  /// real plugin in a test. Everything else here is the real thing.
  MobileVoiceDevice({
    Future<bool?> Function(String token, String? deviceToken)? register,
    Future<String?> Function()? readDeviceToken,
    Future<CallingAccountState> Function()? ensureCallingAccount,

    // Injectable ONLY so a test does not have to spend it.
    //
    // The tests for giving up are the honest kind: a plugin call that never
    // completes, waited on for real. That made each of them cost twenty
    // seconds of wall clock, which is reason enough on its own — a full run
    // pays it every time, and a test that waits on real time is a test whose
    // result can depend on the machine keeping up.
    //
    // Whether it had ever actually FLAKED is not settled and this note no
    // longer claims either way: it was reported red under load, retracted,
    // and then un-retracted, and my own full run showed it green. The
    // duration is the reason, it is mine, and it does not depend on
    // settling that.
    //
    // Shortening it here costs the test nothing it was measuring: what is
    // under test is that the wait ENDS and says why, not how long it is.
    Duration? registrationTimeout,
  }) : _register = register ?? _registerWithPlugin,
       _readDeviceToken = readDeviceToken ?? _readFcmToken,
       _ensureCallingAccount = ensureCallingAccount ?? _telecomAccountReady,
       _registrationTimeout =
           registrationTimeout ?? _defaultRegistrationTimeout;

  final Future<bool?> Function(String token, String? deviceToken) _register;
  final Future<String?> Function() _readDeviceToken;
  final Future<CallingAccountState> Function() _ensureCallingAccount;

  /// Can this phone place and accept calls at all, as far as Android is
  /// concerned?
  ///
  /// ── The gap this closes, and why nothing showed it ───────────────
  ///
  /// Android routes app calls through Telecom, and Telecom will not touch one
  /// until the app has a `PhoneAccount` registered with `TelecomManager`.
  /// Nothing in this repo had ever called `registerPhoneAccount()` — not this
  /// file, and not the plugin's own example, so the example was not the spec
  /// this time. Measured by the Android session on a signed-in emulator at
  /// 0.0.0201, with FCM registration finally working:
  ///
  ///     TwilioVoicePlugin: calling +17864086862 -> +13057242199
  ///     TwilioVoicePlugin: No registered phone account, call
  ///                        `registerPhoneAccount()` first
  ///
  /// It gates BOTH directions, which is why placing a call did not sidestep
  /// it. And it is a separate subsystem from the FCM registration above: that
  /// one is how Twilio WAKES the phone, this one is whether the phone is
  /// allowed to be a phone.
  ///
  /// ── Registered is not enabled ────────────────────────────────────
  ///
  /// Registering the account is ours to do; ENABLING it is the user's, under
  /// Android's Calling accounts screen. Both have to be true, which is why the
  /// plugin exposes four calls and why this asks all three questions in order
  /// rather than registering and assuming. Returning true off Android is the
  /// plugin's own behaviour and is repeated here so the intent is readable
  /// without going to look.
  static Future<CallingAccountState> _telecomAccountReady() async {
    if (defaultTargetPlatform != TargetPlatform.android) {
      return CallingAccountState.ready;
    }

    final instance = TwilioVoicePlatform.instance;

    // FIRST, and this is the half the first version missed.
    //
    // `READ_PHONE_STATE` and `READ_PHONE_NUMBERS` are DANGEROUS permissions.
    // They are in the merged manifest — the plugin declares them — and that
    // grants nothing on its own; Android hands them over only when the user
    // says yes to a dialog. Nothing had ever asked. Measured on the emulator
    // at 0.0.0203, with the phone-account fix already in:
    //
    //     LOG|hasRegisteredPhoneAccount
    //     E No read phone state permission, call
    //       `requestReadPhoneStatePermission()` first
    //     E hasRegisteredPhoneAccount: No read phone numbers permission…
    //
    // So the check below was not returning false, it was unable to answer —
    // and an unanswerable check reads exactly like a negative one. The
    // emulator only got past it because Android granted both by hand with
    // `adb shell pm grant`, which is a test shim and not something a person
    // installing the app can do.
    if (!await instance.hasReadPhoneStatePermission()) {
      await instance.requestReadPhoneStatePermission();
    }

    if (!await instance.hasReadPhoneNumbersPermission()) {
      await instance.requestReadPhoneNumbersPermission();
    }

    // ASKED AGAIN rather than trusting what the request returned. A person can
    // decline, and can have declined permanently on a previous launch — in
    // which case the dialog never appears and the request answers without
    // anything having changed.
    if (!await instance.hasReadPhoneStatePermission() ||
        !await instance.hasReadPhoneNumbersPermission()) {
      return CallingAccountState.needsPermission;
    }

    if (!await instance.hasRegisteredPhoneAccount()) {
      await instance.registerPhoneAccount();
    }

    return await instance.isPhoneAccountEnabled()
        ? CallingAccountState.ready
        : CallingAccountState.needsEnabling;
  }

  static Future<bool?> _registerWithPlugin(String token, String? deviceToken) =>
      TwilioVoicePlatform.instance.setTokens(
        accessToken: token,
        deviceToken: deviceToken,
      );

  /// The Android app's FCM registration token, from the native side.
  ///
  /// ── Why Android needs this and iOS does not ──────────────────────
  ///
  /// `twilio_voice`'s Android half REFUSES to register without it. It is the
  /// first thing `TOKENS` checks, before it stores the access token and before
  /// it reaches `registerForCallInvites`:
  ///
  ///     val deviceToken = call.argument<String>("deviceToken") ?: run {
  ///         result.error(MALFORMED_ARGUMENTS,
  ///             "No 'deviceToken' provided or invalid type", null)
  ///         return@onMethodCall
  ///     }
  ///
  /// We passed none, so Android registration had never once happened — on a
  /// handset as much as on an emulator, and regardless of the push credential
  /// sitting ready on the Twilio subaccount.
  ///
  /// iOS is different: there the plugin fills `deviceToken` itself from
  /// PushKit, so Dart supplying one would be wrong rather than merely
  /// unnecessary.
  ///
  /// ── Why a method channel and not firebase_messaging ──────────────
  ///
  /// The Firebase messaging SDK is already inside the Android binary —
  /// `twilio_voice` pulls it natively and the `google-services` plugin
  /// generates the config it reads. Adding the Dart packages would have
  /// brought an iOS pod that swizzles APNs delegate methods, to reach an SDK
  /// Kotlin can already see, on the one path that had just started working.
  static Future<String?> _readFcmToken() async {
    if (defaultTargetPlatform != TargetPlatform.android) return null;

    return const MethodChannel(
      'com.ezbizi.app/fcm',
    ).invokeMethod<String>('getToken');
  }

  /// How long registration is given before the desk stops waiting.
  ///
  /// ── Why a timeout is not belt and braces here ────────────────────
  ///
  /// The iOS half of `twilio_voice` returns from `tokens` WITHOUT calling
  /// `result(...)` when its PushKit device token is nil:
  ///
  ///     guard let deviceToken = deviceToken else {
  ///         self.sendPhoneCallEvents(description: "LOG|Device token is nil…")
  ///         return
  ///     }
  ///
  /// so the Dart future never completes. `start()` awaits this, which means a
  /// phone with no VoIP push token does not fail to register — it stops,
  /// forever, holding the whole desk startup behind it. A timeout turns that
  /// into a state somebody can be shown.
  static const _defaultRegistrationTimeout = Duration(seconds: 20);

  final Duration _registrationTimeout;

  StreamSubscription<CallEvent>? _events;
  bool _registered = false;
  bool _needsCallingAccount = false;
  String? _registrationError;

  @override
  void Function(VoiceIncoming incoming)? onIncoming;

  @override
  void Function()? onRegistrationChanged;

  @override
  void Function()? onTokenExpiring;

  @override
  void Function()? onEnded;

  /// CallKit or the ConnectionService took the call. See [VoiceDevice].
  @override
  void Function()? onAnswered;

  @override
  void Function(String reason)? onCallFailed;

  @override
  Object? get platformView => null;

  /// A handset, and which kind — so the token it asks for names a credential
  /// Twilio can wake it with. Null on macOS: it runs the same plugin but is
  /// never asleep in a pocket, and Apple's VoIP push is an iOS service.
  @override
  String? get pushPlatform => switch (defaultTargetPlatform) {
    TargetPlatform.iOS => 'ios',
    TargetPlatform.android => 'android',
    _ => null,
  };

  @override
  bool get supported => true;

  /// THE HANDSET RINGS, and it does so before any of this runs.
  ///
  /// An incoming call arrives through CallKit on iOS and the
  /// ConnectionService on Android, both of which ring the phone's own
  /// ringtone and draw the system call screen. EzBizi playing its ringer on
  /// top of that is two sounds for one call — and it is the wrong one of the
  /// two to keep: a phone should ring like the phone it is, including when
  /// its owner has set a ringtone, a vibration pattern, or silent.
  @override
  bool get ringsItself => true;

  @override
  bool get registered => _registered;

  /// Why registration failed, in the platform's own words where it gave any.
  ///
  /// This used to be a hardcoded null with a comment saying failures surface
  /// through the SDK's callbacks. They do not: a refused `setTokens` throws
  /// here and was swallowed by a bare `catch (_)`, so the desk fell back to
  /// its generic banner — which tells the reader to reload the page and
  /// suspect their network, on a phone, for a fault that is neither.
  @override
  String? get registrationError => _registrationError;

  @override
  bool get needsCallingAccount => _needsCallingAccount;

  @override
  Future<void> openCallingSettings() async {
    if (defaultTargetPlatform != TargetPlatform.android) return;

    await TwilioVoicePlatform.instance.openPhoneAccountSettings();
  }

  // The native SDK owns its own audio session; no widget is involved.
  @override
  Future<void> prepare() async {}

  @override
  Future<void> setup(String token) async {
    // The microphone is asked for here rather than at the moment somebody
    // presses Call: a permission prompt arriving on top of a ringing call is
    // a call that gets missed while the prompt is read.
    //
    // In its OWN try, and deliberately not fatal. A phone that cannot reach
    // the microphone is a different fault from one that cannot register, with
    // a different fix, and registering anyway is right — the OS asks again
    // when a call actually starts. Sharing a try meant a mic failure was
    // reported as "not connected to the phone network", which is the wrong
    // sentence about the wrong subsystem.
    try {
      if (!await TwilioVoicePlatform.instance.hasMicAccess()) {
        await TwilioVoicePlatform.instance.requestMicAccess();
      }
    } catch (_) {
      // Nothing to say here that registration will not say better.
    }

    // BEFORE the token and before Twilio, because neither can help.
    //
    // Twilio registration succeeding while Telecom refuses the call is the
    // worst available state: the desk would draw a ready dial pad, presence
    // would say this person is taking calls, the ring group would dial them,
    // and every one of those calls would die inside Android with the caller
    // hearing ringing. So this decides `registered` rather than sitting
    // beside it, and it is not a warning — it is the phone not being usable.
    //
    // Its own try for the same reason the microphone has one: a platform
    // channel that is missing or throws is a different fault from a token
    // Twilio refused, and sharing a catch would report it in the other one's
    // words.
    try {
      final account = await _ensureCallingAccount().timeout(
        _registrationTimeout,
      );

      if (account != CallingAccountState.ready) {
        _registered = false;

        // The BUTTON only for the case the button can fix. Opening Calling
        // accounts does not grant a refused permission, so offering it there
        // sends somebody to a screen where the thing they need is not.
        _needsCallingAccount = account == CallingAccountState.needsEnabling;

        _registrationError = switch (account) {
          CallingAccountState.needsEnabling =>
            'Android has not been given permission for this app to make and '
                'take calls. Open Calling accounts and switch EzBizi on.',
          CallingAccountState.needsPermission =>
            'This app needs the phone permissions Android asked about before '
                'it can place or take calls. Try again to be asked once more.',
          CallingAccountState.ready => null,
        };

        return;
      }

      _needsCallingAccount = false;
    } on TimeoutException {
      // A PERMISSION FUTURE THAT NEVER COMPLETED, not the network. Measured on
      // 0.0.0278: after a denial in the phone group the plugin's request never
      // resolves, the timeout fires, and this used to print "Android refused
      // this app a calling account: TimeoutException after 0:00:20.000000:
      // Future not completed" — a developer string, pointing at the wrong
      // cause, at the one person who knows exactly what they just refused.
      _registered = false;
      _registrationError =
          'EzBizi needs the phone permission to place and take calls. Allow '
          'it under Settings › Apps › EzBizi › Permissions › Phone.';

      return;
    } catch (error) {
      _registered = false;
      _registrationError = 'Android refused this app a calling account: $error';

      return;
    }

    try {
      // Inside the timeout as well: reading the token is a platform channel
      // too, and a channel that never answers is the failure shape this whole
      // method exists to make legible.
      final deviceToken = await _readDeviceToken().timeout(
        _registrationTimeout,
      );

      if (defaultTargetPlatform == TargetPlatform.android &&
          (deviceToken == null || deviceToken.isEmpty)) {
        // Said here rather than left to the plugin. Its refusal reads "No
        // 'deviceToken' provided or invalid type", which describes the
        // argument; this describes the cause, which is the thing somebody can
        // act on.
        _registered = false;
        _registrationError =
            'This device has no Firebase messaging token, so Twilio has no way '
            'to reach it. Calls out will work; calls in will not arrive.';

        return;
      }

      final registered = await _register(
        token,
        deviceToken,
      ).timeout(_registrationTimeout);

      _registered = registered ?? false;

      if (!_registered) {
        _registrationError =
            'Twilio did not accept this device, and gave no reason.';
      }

      await _listen();
    } on TimeoutException {
      // The iOS nil-device-token path, which never answers. Named rather than
      // reported as a generic failure, because the fix is a provisioning one
      // and the generic text sends somebody to look at their wifi.
      _registered = false;
      _registrationError =
          'The phone did not finish registering. On iPhone this is what a '
          'missing VoIP push token looks like.';
    } catch (error) {
      // A refused token, a platform channel that is not there, or Android
      // rejecting the call for want of a device token. The message is kept:
      // "No 'deviceToken' provided" is the whole diagnosis, and discarding it
      // is what made this look like a network fault for as long as it did.
      _registered = false;
      _registrationError = '$error';
    }
  }

  /// Subscribe to the SDK's call events.
  ///
  /// Separated so a test can drive [setup] without a platform channel, and so
  /// a failure to subscribe cannot be mistaken for a failure to register.
  Future<void> _listen() async {
    try {
      await _events?.cancel();
      // onError, because a channel that is not there reports it as a stream
      // ERROR rather than by throwing from listen() — so a try/catch around
      // this line alone does not catch it.
      _events = TwilioVoicePlatform.instance.callEventsListener.listen(
        _onEvent,
        onError: (_) {},
        cancelOnError: false,
      );
    } catch (_) {
      // The desk is registered either way; events arriving is a separate
      // concern and one the incoming-call bar reports on its own.
    }
  }

  @visibleForTesting
  void handleEvent(CallEvent event) => _onEvent(event);

  void _onEvent(CallEvent event) {
    // INCOMING ONLY. `ringing` is NOT an incoming call.
    //
    // The SDK raises `ringing` for the far end of an OUTGOING call too, and
    // this treated the two alike — so placing a call raised the desk's own
    // incoming banner and asked the caller to accept the call they had just
    // made. Jose hit it on 2026-09-10: he rang Rodrigo, Rodrigo answered, and
    // he had to accept an incoming call from himself to join.
    if (event == CallEvent.incoming) {
      // The native call UI already knows who is calling and is already
      // showing them. This tells the desk so its own banner agrees.
      onIncoming?.call(
        VoiceIncoming(
          from: TwilioVoicePlatform.instance.call.activeCall?.from ?? '',
        ),
      );

      return;
    }

    // Answered in CallKit or the ConnectionService, where a handset's calls
    // are actually answered. Without this the desk kept offering its own
    // accept button for a call already in progress, so answering took two
    // gestures on two different screens.
    if (event == CallEvent.answer || event == CallEvent.connected) {
      onAnswered?.call();

      return;
    }

    // The four ways this SDK says there is no call any more.
    //
    // `missedCall` and `declined` matter as much as `callEnded`: on a ring
    // group, the leg that nobody answers here ends as one of those while
    // somebody else takes the call — and the desk was left showing a banner
    // for a call a colleague had already picked up.
    if (event == CallEvent.callEnded ||
        event == CallEvent.missedCall ||
        event == CallEvent.declined) {
      onEnded?.call();
    }
  }

  /// CALL_PHONE, and the self-managed-calls permission beside it.
  ///
  /// Android will not place an outbound call without CALL_PHONE, and the
  /// plugin says so only to logcat: `TVConnectionService.onStartCommand` logs
  /// "Missing CALL_PHONE permission" and RETURNS before `placeCall`, so
  /// pressing Call did nothing and said nothing. This app never asked.
  ///
  /// ── Why only when somebody presses Call ─────────────────────────
  ///
  /// I first asked at start-up beside the microphone, on the microphone's
  /// reasoning — and that reasoning is about INBOUND, where a prompt arriving
  /// over a ringing call costs the call. For outbound, pressing Call IS the
  /// moment of intent and nobody is waiting yet.
  ///
  /// And start-up was actively harmful. `_ensureCallingAccount` already asks
  /// for the phone group there; a second request into the same group is a
  /// second, IDENTICAL dialog. Somebody who says no twice because they were
  /// asked twice gets READ_PHONE_STATE marked USER_FIXED — Android never asks
  /// again, and calling is lost in BOTH directions with no way back from the
  /// app. Measured on 0.0.0278.
  ///
  /// Asked here instead, if the group was granted at start-up this is silent:
  /// Android auto-grants a permission whose group already holds one.
  ///
  /// Android only — iOS has no such permission and places calls through
  /// CallKit without one.
  Future<bool> _askToPlaceCalls() async {
    if (defaultTargetPlatform != TargetPlatform.android) return true;

    final voice = TwilioVoicePlatform.instance;

    return placeCallsAllowed(
      hasCallPhone: voice.hasCallPhonePermission,
      requestCallPhone: voice.requestCallPhonePermission,
      hasManageOwnCalls: voice.hasManageOwnCallsPermission,
      requestManageOwnCalls: voice.requestManageOwnCallsPermission,
    );
  }

  /// Whether an outbound call may be attempted, asking once where needed.
  ///
  /// Static and handed the four platform calls, so the FAIL-CLOSED rule below
  /// can be tested without a device — the rule is the whole point and it is
  /// exactly the part a real handset reached by a path nobody had written a
  /// test for.
  @visibleForTesting
  static Future<bool> placeCallsAllowed({
    required Future<bool> Function() hasCallPhone,
    required Future<bool?> Function() requestCallPhone,
    required Future<bool> Function() hasManageOwnCalls,
    required Future<bool?> Function() requestManageOwnCalls,
  }) async {
    try {
      if (!await hasCallPhone()) {
        await requestCallPhone();
      }

      // Normally granted at install on every Android version this app
      // supports, but the plugin refuses without it just as silently, so it is
      // checked rather than assumed.
      if (!await hasManageOwnCalls()) {
        await requestManageOwnCalls();
      }

      return await hasCallPhone();
    } catch (_) {
      // FAIL CLOSED. I first wrote "a platform channel that throws is not a
      // refusal" and returned true, and on an emulator it produced a phantom
      // call: "On a call · Hang up" for a call that never existed. The plugin
      // reaches `showPermissionRationaleDialog` — which is what threw — ONLY
      // after a prior denial, because that is when Android says a rationale
      // is due. So on this path the throw IS the refusal. Measured by the
      // Android session on 0.0.0278, 2026-09-25.
      //
      // Wrongly refusing costs a sentence telling somebody where to switch it
      // on. Wrongly proceeding costs a desk marked on a call it is not on,
      // which takes that person out of their own ring group. Only one of those
      // is safe to get wrong.
      try {
        return await hasCallPhone();
      } catch (_) {
        return false;
      }
    }
  }

  @override
  Future<void> connect({required String to, String? callerId}) async {
    // ASKED AGAIN, because the answer at start-up is not the last word. And
    // refused OUT LOUD: the plugin's own behaviour here is to log and return,
    // which leaves somebody pressing Call on a phone that does nothing and
    // says nothing.
    //
    // A THROW, where every other failure in this method is swallowed as a
    // state. The difference is that the desk has to NOT mark this person on a
    // call — a call that was never placed must not put somebody `on_call` and
    // take them out of their own ring group.
    if (!await _askToPlaceCalls()) {
      throw const CallRefused(
        'Android has not given EzBizi permission to place calls. Allow it '
        'under Settings › Apps › EzBizi › Permissions › Phone.',
      );
    }

    try {
      await TwilioVoicePlatform.instance.call.place(
        from: callerId ?? '',
        to: to,

        // The same parameter the browser sends. Without it the server infers
        // direction from Twilio's own fields, and a client-originated call
        // arrives claiming to be inbound — which answered the caller with
        // their own voicemail for two days.
        extraOptions: {'EzBiziDirection': 'outbound'},
      );
    } catch (_) {
      // Reported by the desk rather than thrown: a failed dial is a state,
      // not an exception the screen can do anything with.
    }
  }

  @override
  void hangUp() => unawaited(TwilioVoicePlatform.instance.call.hangUp());

  @override
  void sendDigits(String digits) =>
      unawaited(TwilioVoicePlatform.instance.call.sendDigits(digits));

  @override
  void acceptIncoming() =>
      unawaited(TwilioVoicePlatform.instance.call.answer());

  @override
  void declineIncoming() =>
      unawaited(TwilioVoicePlatform.instance.call.hangUp());

  @override
  void dispose() {
    _events?.cancel();
    _events = null;
    onIncoming = null;
    onEnded = null;
  }
}
