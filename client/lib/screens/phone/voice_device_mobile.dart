import 'dart:async';

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
  StreamSubscription<CallEvent>? _events;
  bool _registered = false;

  @override
  void Function(VoiceIncoming incoming)? onIncoming;

  @override
  void Function()? onRegistrationChanged;

  @override
  void Function()? onEnded;

  @override
  Object? get platformView => null;

  @override
  bool get supported => true;

  @override
  bool get registered => _registered;

  // Native SDK; a failure surfaces through its own callbacks, not here.
  @override
  String? get registrationError => null;

  // The native SDK owns its own audio session; no widget is involved.
  @override
  Future<void> prepare() async {}

  @override
  Future<void> setup(String token) async {
    try {
      // The microphone is asked for here rather than at the moment somebody
      // presses Call: a permission prompt arriving on top of a ringing call is
      // a call that gets missed while the prompt is read.
      if (!await TwilioVoicePlatform.instance.hasMicAccess()) {
        await TwilioVoicePlatform.instance.requestMicAccess();
      }

      final registered = await TwilioVoicePlatform.instance.setTokens(
        accessToken: token,
      );

      _registered = registered ?? false;

      await _events?.cancel();
      _events = TwilioVoicePlatform.instance.callEventsListener.listen(_onEvent);
    } catch (_) {
      // A refused token, or a platform channel that is not there. The desk
      // has a state for a phone that cannot take a call.
      _registered = false;
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
