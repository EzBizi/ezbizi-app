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
/// Android only. iOS is refused by the endpoint itself, deliberately: the
/// token below is the FCM registration token, and on iOS the same plugin
/// property holds a PushKit VoIP token instead — a different credential that
/// wakes an app for a CALL and cannot receive a notification. A row carrying
/// one would read as registered and never deliver, which is the failure this
/// module spent a week inside. iOS joins when there is an APNs sender.
class PushRegistration {
  /// The channel MainActivity.kt answers. Declared here rather than shared
  /// with the phone module's copy: one const string in two files is cheaper
  /// than a common file three sessions edit, and a wrong name throws
  /// MissingPluginException rather than failing quietly.
  static const _channel = MethodChannel('com.ezbizi.app/fcm');

  static bool _listening = false;

  /// Registers the current token, and keeps registering it when it rotates.
  ///
  /// Safe to call on every mount of the signed-in shell: the endpoint is
  /// idempotent on the token, and the rotation listener is attached once.
  ///
  /// Never throws. A handset that cannot register is a handset that does not
  /// buzz, which must not also be a handset that cannot show its dashboard.
  static Future<void> start(ApiClient api) async {
    if (defaultTargetPlatform != TargetPlatform.android) return;

    if (!_listening) {
      _listening = true;

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
        _register(api, token);
      });
    }

    final token = await _token();
    if (token != null && token.isNotEmpty) await _register(api, token);
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

  static Future<void> _register(ApiClient api, String token) async {
    final response = await api.post('/notifications/devices', {
      'token': token,
      'platform': 'android',
    });

    if (!response.ok) {
      debugPrint('push: register failed ${response.statusCode}');
    }
  }
}
