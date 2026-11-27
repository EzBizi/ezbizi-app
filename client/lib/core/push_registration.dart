import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:twilio_voice/twilio_voice.dart';

import 'api_client.dart';

/// Tells the server which handset to wake.
///
/// OS notifications already worked while the app was RUNNING — that is
/// flutter_local_notifications, and it needs no server address. Waking a
/// CLOSED app is a push, and a push needs a device token the server has been
/// told about. Nothing sent one, so the server had a working sender and
/// nowhere to send.
///
/// BOTH PLATFORMS, through two channels that must never be confused.
///
/// This was Android-only until f7703961, and the reason was never that iOS
/// lacked push — it was where the TOKEN comes from. The twilio_voice property
/// the Android path reads holds a PushKit VoIP token on iOS: a different
/// credential that wakes an app for a CALL and can never receive a
/// notification. Both are hex strings of plausible length, the server cannot
/// tell them apart by looking, and a row carrying one reads as registered and
/// never delivers. That is the failure this module spent a week inside.
///
/// So the two paths below reach two different sources and neither can reach
/// the other's:
///
///   android   com.ezbizi.app/fcm    the FCM registration token
///   ios       com.ezbizi.app/apns   didRegisterForRemoteNotifications... only
///
/// The Swift half guarantees the second, because nothing here can.
class PushRegistration {
  /// The channel MainActivity.kt answers. Declared here rather than shared
  /// with the phone module's copy: one const string in two files is cheaper
  /// than a common file three sessions edit, and a wrong name throws
  /// MissingPluginException rather than failing quietly.
  static const _channel = MethodChannel('com.ezbizi.app/fcm');

  /// One flag PER PATH, because they guard different handlers.
  ///
  /// A single shared flag was the first thing the iOS tests caught: the
  /// Android path sets it, and the Apple path then skips attaching its own
  /// handler entirely — so a token arriving through `onToken` reaches nothing.
  /// Harmless on a real handset, which only ever runs one of the two, and
  /// invisible until something exercises both in one process. A flag that
  /// means "the Android listener is attached" cannot also mean "the Apple one
  /// is".
  static bool _androidListening = false;
  static bool _appleListening = false;

  /// Has this device told the server where to reach it?
  ///
  /// Read by the bell, which stops drawing its own OS notification once this
  /// is true — otherwise every notification appears TWICE while the app is
  /// open: once from the poll seeing a new row, once from the push. Measured
  /// on a real handset as two records in the shade, `tag=null` beside
  /// `tag=FCM-Notification:`.
  ///
  /// Set only after the POST is ACCEPTED, not after a token is obtained. A
  /// token the server never received is a device the server cannot reach, and
  /// suppressing the local notification on the strength of one would be
  /// silence rather than a duplicate.
  static bool get isRegistered => _registered;

  static bool _registered = false;

  /// Lets a test start from nothing. Static listener state outlives a test
  /// otherwise, and the second test of a pair then measures the first one's
  /// handler.
  @visibleForTesting
  static void resetForTest() {
    _androidListening = false;
    _appleListening = false;
    _registered = false;
    _lastToken = null;
  }

  /// Registers the current token, and keeps registering it when it rotates.
  ///
  /// Safe to call on every mount of the signed-in shell: the endpoint is
  /// idempotent on the token, and the rotation listener is attached once.
  ///
  /// Never throws. A handset that cannot register is a handset that does not
  /// buzz, which must not also be a handset that cannot show its dashboard.
  static Future<void> start(ApiClient api) async {
    if (defaultTargetPlatform == TargetPlatform.iOS) return _startApple(api);
    if (defaultTargetPlatform != TargetPlatform.android) return;

    if (!_androidListening) {
      _androidListening = true;

      // Rotation, and the reason there is no server-side catch-up sweep.
      //
      // The chain is: FCM onNewToken -> VoiceFirebaseMessagingService (the
      // service declared in our own AndroidManifest) -> TwilioVoiceFcm
      // .updateToken -> ACTION_NEW_TOKEN broadcast -> the plugin -> a
      // DEVICETOKEN| event -> this callback. Measured firing end to end on an
      // emulator, not assumed.
      //
      // The plugin BUFFERS those events while no sink is attached and flushes
      // them when one is, so a rotation during startup is not lost. That is
      // what makes "register at launch and on change" complete.
      //
      // NOTE FOR WHOEVER READS THIS NEXT: setOnDeviceTokenChanged sets a
      // SINGLE field on the plugin, not a listener list. A second caller
      // silently replaces this one. Nothing else in client/lib used it when
      // this landed; if the phone module needs it too, the two have to share
      // one callback rather than each calling the setter.
      TwilioVoicePlatform.instance.setOnDeviceTokenChanged((String token) {
        _register(api, token, 'android');
      });
    }

    final token = await _token();
    if (token != null && token.isNotEmpty) await _register(api, token, 'android');
  }

  static const _appleChannel = MethodChannel('com.ezbizi.app/apns');

  /// The iOS half. Listen FIRST, then ask — there is no retry loop and there
  /// does not need to be.
  ///
  /// getToken answers three ways on purpose, and collapsing them would cost
  /// the distinction that makes a retry unnecessary:
  ///
  ///   a token          register it
  ///   apns_unavailable STOP. A simulator has no APNs token and never will,
  ///                    so asking again is a loop that can only fail.
  ///   null             not yet. Registration is asynchronous and this is the
  ///                    ordinary answer during launch.
  ///
  /// `null` needs no retry because the handler is attached before the ask: if
  /// the token arrives a moment later, `onToken` carries it. And if it arrived
  /// BEFORE Dart was listening — which the Apple session measured as the usual
  /// case — the Swift side has cached it and replays it into the handler. A
  /// push-only design would have lost it on most launches and read as "APNs
  /// sometimes gives us no token", which is the kind of intermittency nobody
  /// ever finishes debugging.
  static Future<void> _startApple(ApiClient api) async {
    if (!_appleListening) {
      _appleListening = true;

      _appleChannel.setMethodCallHandler((call) async {
        switch (call.method) {
          case 'onToken':
            final token = call.arguments as String?;
            if (token != null && token.isNotEmpty) {
              await _register(api, token, 'ios');
            }
          case 'onTokenError':
            // Logged rather than swallowed: silence here is indistinguishable
            // from a token that simply has not arrived, and those want
            // different reactions from whoever is reading the console.
            debugPrint('push: APNs registration failed — ${call.arguments}');
        }
      });
    }

    try {
      final token = await _appleChannel.invokeMethod<String>('getToken');
      if (token != null && token.isNotEmpty) await _register(api, token, 'ios');
    } on PlatformException catch (e) {
      // apns_unavailable is the simulator, and it is permanent. Anything else
      // is still worth saying out loud.
      debugPrint('push: no APNs token (${e.code}) ${e.message}');
    } on MissingPluginException {
      debugPrint('push: the apns channel is not registered on this build');
    }
  }

  static Future<String?> _token() async {
    try {
      return await _channel.invokeMethod<String>('getToken');
    } on PlatformException catch (e) {
      // fcm_token_unavailable or fcm_unavailable — the Kotlin side says which
      // and why. A device with no Play Services is the common one.
      debugPrint('push: no FCM token (${e.code}) ${e.message}');
      return null;
    } on MissingPluginException {
      debugPrint('push: the fcm channel is not registered on this build');
      return null;
    }
  }

  /// Which app this handset token belongs to: `ezbizi`, or `ezchat` for the
  /// chat companion (main_ezchat.dart sets it). The server picks the APNs topic
  /// by it and sends EzChat only chat messages and call rings.
  static String app = 'ezbizi';

  /// The token this handset last registered, so sign-out can withdraw it.
  static String? _lastToken;

  /// This handset's push token, or null before it registered. Chat's pulse
  /// sends it with the chat on screen, so that chat's pushes skip this phone.
  static String? get token => _lastToken;

  /// Sign-out: this handset stops receiving the person's pushes. Best effort;
  /// a failure must never block signing out.
  static Future<void> unregister(ApiClient api) async {
    final token = _lastToken;
    if (token == null) return;
    try {
      await api.deleteWithBody('/notifications/devices', {'token': token});
    } catch (_) {}
    _lastToken = null;
    _registered = false;
  }

  static Future<void> _register(ApiClient api, String token, String platform) async {
    final response = await api.post('/notifications/devices', {
      'token': token,
      'platform': platform,
      'app': app,
    });

    if (!response.ok) {
      debugPrint('push: register failed ${response.statusCode}');

      return;
    }

    _registered = true;
    _lastToken = token;
  }
}
