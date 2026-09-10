package com.ezbizi.ezbizi_client

import com.google.firebase.messaging.FirebaseMessaging
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/// Hands Dart the FCM registration token, and nothing else.
///
/// twilio_voice's Android side REFUSES to register without one.
/// TwilioVoicePlugin.kt:439 reads `deviceToken` off the setTokens call and
/// returns MALFORMED_ARGUMENTS before it stores the access token or calls
/// registerForCallInvites, so a missing token is not a degraded registration —
/// there is no registration at all. That was true on every Android build ever
/// cut, and it reported as the generic "not connected to the phone network"
/// banner, which is why it survived so long.
///
/// iOS needs none of this: there the plugin fills deviceToken from PushKit
/// itself. Android has no equivalent and expects the app to supply it.
///
/// Why a method channel and not firebase_messaging (the Dart package): the
/// NATIVE SDK IS ALREADY IN THIS APP. twilio_voice pulls
/// com.google.firebase:firebase-messaging:24.1.0 and the google-services plugin
/// already generates the config it reads — measured on the shipped artefact,
/// which carries gcm_defaultSenderId and 6 firebase-*.properties. The Dart
/// package would add two packages plus an iOS pod that swizzles APNs delegate
/// methods, to reach an SDK Kotlin can already see, and iOS calling only began
/// working the day this was written. This costs no download size and has no
/// iOS surface at all.
class MainActivity : FlutterActivity() {
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, CHANNEL)
            .setMethodCallHandler { call, result ->
                if (call.method != "getToken") {
                    result.notImplemented()
                    return@setMethodCallHandler
                }

                // Every path below calls result exactly once.
                //
                // Deliberate, not incidental. The bug next door to this one was
                // an iOS path that returned without ever completing its result:
                // the Dart future never settled and start() waited behind it, so
                // a device with no token did not FAIL to register, it stopped —
                // and a stop reports as a timeout somewhere unrelated. A Task
                // covers success and failure, and getInstance() throws
                // synchronously when Firebase did not initialise, which is the
                // one way out of here that is not a Task at all.
                try {
                    FirebaseMessaging.getInstance().token
                        .addOnSuccessListener { token -> result.success(token) }
                        .addOnFailureListener { error ->
                            result.error(
                                "fcm_token_unavailable",
                                error.message ?: error.toString(),
                                null,
                            )
                        }
                } catch (e: Throwable) {
                    // Firebase not initialised — a missing or malformed
                    // google-services.json. Says so, rather than crashing the
                    // activity or hanging the caller.
                    result.error("fcm_unavailable", e.message ?: e.toString(), null)
                }
            }
    }

    private companion object {
        const val CHANNEL = "com.ezbizi.app/fcm"
    }
}
