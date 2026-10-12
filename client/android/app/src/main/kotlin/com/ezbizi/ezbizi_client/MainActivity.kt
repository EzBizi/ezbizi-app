package com.ezbizi.ezbizi_client

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Intent
import android.os.Bundle
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
    /// The bell row a push tap names, waiting for Dart to ask for it.
    private var pendingTap: String? = null
    private var tapChannel: MethodChannel? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        // Before super: the engine, and so the channel Dart asks on, is set up
        // inside super.onCreate. A recreation (rotation, a dark-mode switch)
        // replays the launch intent, so it must not open the record twice.
        if (savedInstanceState == null) pendingTap = notificationIdOf(intent)
        super.onCreate(savedInstanceState)
        createNotificationChannel()
    }

    /// A tap on a push while the app is already running (launchMode
    /// singleTop delivers it here, not to onCreate).
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        val id = notificationIdOf(intent) ?: return
        val channel = tapChannel
        if (channel == null) pendingTap = id else channel.invokeMethod("tapped", id)
    }

    /// The `notification_id` FCM copies from the push's data into the extras
    /// of the intent it launches when the notification is tapped.
    ///
    /// Null when the app was reopened from Recents: Android re-delivers the
    /// ORIGINAL launch intent then, extras and all, and opening a record the
    /// person tapped yesterday would be a ghost navigation.
    private fun notificationIdOf(intent: Intent?): String? {
        if (intent == null) return null
        if (intent.flags and Intent.FLAG_ACTIVITY_LAUNCHED_FROM_HISTORY != 0) return null
        return intent.extras?.getString("notification_id")?.takeIf { it.isNotBlank() }
    }

    /// Makes the one EzBizi notification channel exist, at launch, always.
    ///
    /// A push whose channel does not exist on the device does not fail — FCM
    /// quietly delivers it on `fcm_fallback_notification_channel` instead.
    /// Measured on the emulator before this landed. That works, and it means
    /// EzBizi's notifications appear in Settings under a generic name a person
    /// cannot tune separately from anything else. Invisible until somebody
    /// tries to mute ONE kind of notification and finds they can only mute all
    /// of them, by which point they are a customer.
    ///
    /// flutter_local_notifications creates this same channel, but LAZILY — the
    /// first time it shows a LOCAL notification. A handset that has only ever
    /// received pushes never reaches that, which is exactly the handset this
    /// matters for. So it is created here instead, unconditionally.
    ///
    /// The values are copied from os_notifications.dart, deliberately and
    /// exactly. A channel is IMMUTABLE once created: whoever creates it first
    /// decides the name a person reads forever, and the two halves creating it
    /// with different names would be a coin toss over which won on any given
    /// device. If that file's channel changes, change this with it.
    ///
    /// No SDK guard: minSdk is 26 and channels arrived in 26.
    private fun createNotificationChannel() {
        val channel = NotificationChannel(
            "ezbizi_notifications",
            "EzBizi notifications",
            NotificationManager.IMPORTANCE_DEFAULT,
        )
        channel.description =
            "Assignments, mentions, reminders and alerts from EzBizi."

        // What should interrupt: alerts, new leads, missed calls. HIGH, so a
        // heads-up banner and sound. A second channel rather than raising the
        // one above, because Android never lets an app raise an existing
        // channel's importance (Jose, 2026-10-08: "do it now"). The server
        // names it per push — PushSender::ANDROID_URGENT_CHANNEL, same values.
        val urgent = NotificationChannel(
            "ezbizi_urgent",
            "EzBizi urgent",
            NotificationManager.IMPORTANCE_HIGH,
        )
        urgent.description = "Alerts, new leads and missed calls."

        getSystemService(NotificationManager::class.java)
            ?.createNotificationChannels(listOf(channel, urgent))
    }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        // Cold start: Dart asks once for the tap that launched the app, and
        // the answer is consumed so a later rebuild cannot replay it.
        tapChannel = MethodChannel(flutterEngine.dartExecutor.binaryMessenger, TAP_CHANNEL)
            .also { channel ->
                channel.setMethodCallHandler { call, result ->
                    if (call.method == "initialTap") {
                        result.success(pendingTap)
                        pendingTap = null
                    } else {
                        result.notImplemented()
                    }
                }
            }

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
        const val TAP_CHANNEL = "com.ezbizi.app/notification_tap"
    }
}
