import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:twilio_voice/twilio_voice.dart';

import 'voice_device.dart';

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
class MobileVoiceDevice implements VoiceDevice {
  /// The plugin call, injectable so the failures below can be tested.
  ///
  /// Registration is a platform channel, and its two interesting outcomes —
  /// an error and a call that never returns — cannot be reached through the
  /// real plugin in a test. Everything else here is the real thing.
  MobileVoiceDevice({
    Future<bool?> Function(String token, String? deviceToken)? register,
    Future<String?> Function()? readDeviceToken,
  }) : _register = register ?? _registerWithPlugin,
       _readDeviceToken = readDeviceToken ?? _readFcmToken;

  final Future<bool?> Function(String token, String? deviceToken) _register;
  final Future<String?> Function() _readDeviceToken;

  static Future<bool?> _registerWithPlugin(String token, String? deviceToken) =>
      TwilioVoicePlatform.instance
          .setTokens(accessToken: token, deviceToken: deviceToken);

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

    return const MethodChannel('com.ezbizi.app/fcm')
        .invokeMethod<String>('getToken');
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
  static const _registrationTimeout = Duration(seconds: 20);

  StreamSubscription<CallEvent>? _events;
  bool _registered = false;
  String? _registrationError;

  @override
  void Function(VoiceIncoming incoming)? onIncoming;

  @override
  void Function()? onRegistrationChanged;

  @override
  void Function()? onEnded;

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
      _events = TwilioVoicePlatform.instance.callEventsListener
          .listen(_onEvent, onError: (_) {}, cancelOnError: false);
    } catch (_) {
      // The desk is registered either way; events arriving is a separate
      // concern and one the incoming-call bar reports on its own.
    }
  }

  void _onEvent(CallEvent event) {
    if (event == CallEvent.incoming || event == CallEvent.ringing) {
      // The native call UI already knows who is calling and is already
      // showing them. This tells the desk so its own banner agrees.
      onIncoming?.call(
        VoiceIncoming(from: TwilioVoicePlatform.instance.call.activeCall?.from ?? ''),
      );

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

  @override
  Future<void> connect({required String to, String? callerId}) async {
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
  void acceptIncoming() => unawaited(TwilioVoicePlatform.instance.call.answer());

  @override
  void declineIncoming() => unawaited(TwilioVoicePlatform.instance.call.hangUp());

  @override
  void dispose() {
    _events?.cancel();
    _events = null;
    onIncoming = null;
    onEnded = null;
  }
}
