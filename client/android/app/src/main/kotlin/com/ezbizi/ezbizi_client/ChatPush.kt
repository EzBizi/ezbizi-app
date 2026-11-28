package com.ezbizi.ezbizi_client

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.app.Person
import androidx.core.app.RemoteInput
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import com.twilio.twilio_voice.fcm.TwilioVoiceFcm
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread

/// Chat pushes drawn natively, like WhatsApp: a ringing call (full screen,
/// Answer/Decline), Reply and Mark-as-read on a message, and the notification
/// cancelled when the chat is read on another device.
///
/// Why native: these need code to run when a push arrives with the app
/// closed, and a NOTIFICATION message never runs app code in the background.
/// So devices that register with capabilities ["chat_native"] get chat pushes
/// as DATA-only messages (contract agreed with Chat, 2026-11-27):
///   type  chat_message | chat_call | chat_call_end | chat_read
///   chat_id, message_id, call_id, title, sender, body, video, badge,
///   is_group, sent_at — all strings.
///
/// This service REPLACES twilio_voice's VoiceFirebaseMessagingService in the
/// manifest (only one MESSAGING_EVENT service receives messages). Everything
/// that is not a chat push is handed to TwilioVoiceFcm exactly as that
/// service did, so phone calls are unchanged.
class EzMessagingService : FirebaseMessagingService() {
    override fun onNewToken(token: String) {
        TwilioVoiceFcm.updateToken(applicationContext, token)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val d = message.data
        when (d["type"]) {
            "chat_message" -> ChatNotifier.message(applicationContext, d)
            "chat_call" -> ChatNotifier.call(applicationContext, d)
            "chat_call_end" -> ChatNotifier.cancelCall(applicationContext, d["call_id"])
            "chat_read" -> ChatNotifier.cancelChat(applicationContext, d["chat_id"])
            else -> TwilioVoiceFcm.handleMessage(applicationContext, d)
        }
    }
}

/// The signed-in session for code that runs without Flutter. Written by
/// MainActivity's com.ezbizi.app/native_session channel at push registration,
/// cleared at sign-out. App-private SharedPreferences: the same sandbox the
/// app's own storage lives in.
object NativeSession {
    private const val PREFS = "ezbizi_native_session"

    fun set(ctx: Context, token: String?, apiBase: String?) {
        ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString("token", token).putString("api_base", apiBase).apply()
    }

    fun clear(ctx: Context) {
        ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().clear().apply()
    }

    /// method "POST"/"PATCH", path under /api/v1. Returns the HTTP status, or
    /// -1 when there is no session or the network failed.
    fun call(ctx: Context, method: String, path: String, json: String): Int {
        val p = ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val token = p.getString("token", null) ?: return -1
        val base = p.getString("api_base", null)?.trimEnd('/') ?: return -1
        return try {
            val c = URL("$base/api/v1$path").openConnection() as HttpURLConnection
            // HttpURLConnection has no PATCH; Laravel honours the override.
            c.requestMethod = "POST"
            if (method != "POST") c.setRequestProperty("X-HTTP-Method-Override", method)
            c.setRequestProperty("Authorization", "Bearer $token")
            c.setRequestProperty("Accept", "application/json")
            c.setRequestProperty("Content-Type", "application/json")
            c.setRequestProperty("X-EzBizi-Client-Platform", "android")
            c.connectTimeout = 15000
            c.readTimeout = 15000
            c.doOutput = true
            c.outputStream.use { it.write(json.toByteArray()) }
            c.responseCode.also { c.disconnect() }
        } catch (e: Exception) {
            -1
        }
    }
}

object ChatNotifier {
    const val CALLS_CHANNEL = "ezbizi_calls"
    private const val REPLY_KEY = "chat_reply"

    private fun chatTag(chatId: String) = "chat-$chatId"
    private fun callTag(callId: String) = "chat-call-$callId"

    /// The house tap path: MainActivity hands notification_id to Dart, which
    /// opens the record. "chat:{id}" opens the chat; "?call=" joins the call.
    private fun open(ctx: Context, notificationId: String, req: Int): PendingIntent {
        val i = Intent(ctx, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            .putExtra("notification_id", notificationId)
        return PendingIntent.getActivity(ctx, req, i,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
    }

    private fun action(ctx: Context, act: String, d: Map<String, String?>, req: Int, mutable: Boolean = false): PendingIntent {
        val i = Intent(ctx, ChatActionReceiver::class.java).setAction(act)
            .putExtra("chat_id", d["chat_id"]).putExtra("call_id", d["call_id"])
            .putExtra("title", d["title"]).putExtra("is_group", d["is_group"])
        val flags = PendingIntent.FLAG_UPDATE_CURRENT or
            (if (mutable) PendingIntent.FLAG_MUTABLE else PendingIntent.FLAG_IMMUTABLE)
        return PendingIntent.getBroadcast(ctx, req, i, flags)
    }

    fun message(ctx: Context, d: Map<String, String>) {
        val chatId = d["chat_id"] ?: return
        val nm = ctx.getSystemService(NotificationManager::class.java) ?: return
        val me = Person.Builder().setName("You").build()
        // Append to the conversation already showing, WhatsApp-style.
        val existing = nm.activeNotifications.firstOrNull { it.tag == chatTag(chatId) }
        val style = existing?.let { NotificationCompat.MessagingStyle.extractMessagingStyleFromNotification(it.notification) }
            ?: NotificationCompat.MessagingStyle(me)
        style.conversationTitle = d["title"]
        style.isGroupConversation = d["is_group"] == "1"
        style.addMessage(d["body"] ?: "", d["sent_at"]?.toLongOrNull() ?: System.currentTimeMillis(),
            Person.Builder().setName(d["sender"] ?: d["title"] ?: "EzChat").build())
        post(ctx, chatId, style, d)
    }

    private fun post(ctx: Context, chatId: String, style: NotificationCompat.MessagingStyle, d: Map<String, String?>) {
        val req = chatId.hashCode()
        val reply = NotificationCompat.Action.Builder(0, "Reply", action(ctx, ChatActionReceiver.REPLY, d, req, mutable = true))
            .addRemoteInput(RemoteInput.Builder(REPLY_KEY).setLabel("Reply").build())
            .setSemanticAction(NotificationCompat.Action.SEMANTIC_ACTION_REPLY)
            .setAllowGeneratedReplies(true).build()
        val read = NotificationCompat.Action.Builder(0, "Mark as read", action(ctx, ChatActionReceiver.READ, d, req + 1))
            .setSemanticAction(NotificationCompat.Action.SEMANTIC_ACTION_MARK_AS_READ)
            .setShowsUserInterface(false).build()
        val n = NotificationCompat.Builder(ctx, "ezbizi_notifications")
            .setSmallIcon(ctx.applicationInfo.icon)
            .setStyle(style)
            .setCategory(NotificationCompat.CATEGORY_MESSAGE)
            .setContentIntent(open(ctx, "chat:$chatId", req + 2))
            .setAutoCancel(true)
            .setOnlyAlertOnce(false)
            .setNumber(d["badge"]?.toIntOrNull() ?: 0)
            .addAction(reply).addAction(read)
            .build()
        try { NotificationManagerCompat.from(ctx).notify(chatTag(chatId), 0, n) } catch (_: SecurityException) {}
    }

    fun call(ctx: Context, d: Map<String, String>) {
        val callId = d["call_id"] ?: return
        val chatId = d["chat_id"] ?: return
        ensureCallsChannel(ctx)
        val req = callId.hashCode()
        val caller = Person.Builder().setName(d["sender"] ?: d["title"] ?: "EzChat").setImportant(true).build()
        val answer = open(ctx, "chat:$chatId?call=$callId", req)
        val decline = action(ctx, ChatActionReceiver.DECLINE, d, req + 1)
        val video = d["video"] == "1"
        val b = NotificationCompat.Builder(ctx, CALLS_CHANNEL)
            .setSmallIcon(ctx.applicationInfo.icon)
            .setContentTitle(d["sender"] ?: d["title"])
            .setContentText(if (video) "Incoming video call" else "Incoming voice call")
            .setStyle(NotificationCompat.CallStyle.forIncomingCall(caller, decline, answer).setIsVideo(video))
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setOngoing(true)
            .setTimeoutAfter(45_000)
            .setContentIntent(answer)
        // Android 14+: Play grants full-screen intents only to calling apps
        // (declared in Play Console). Without the grant the call still shows
        // as a heads-up notification that rings.
        val canFull = Build.VERSION.SDK_INT < 34 ||
            ctx.getSystemService(NotificationManager::class.java)?.canUseFullScreenIntent() == true
        if (canFull) b.setFullScreenIntent(answer, true)
        try { NotificationManagerCompat.from(ctx).notify(callTag(callId), 0, b.build()) } catch (_: SecurityException) {}
    }

    fun cancelCall(ctx: Context, callId: String?) {
        if (callId != null) NotificationManagerCompat.from(ctx).cancel(callTag(callId), 0)
    }

    fun cancelChat(ctx: Context, chatId: String?) {
        if (chatId != null) NotificationManagerCompat.from(ctx).cancel(chatTag(chatId), 0)
    }

    /// After a Reply the notification must change, or Android leaves the
    /// action spinning. The sent line is appended as "You".
    fun appendMine(ctx: Context, chatId: String, text: String?, failed: Boolean, extras: Map<String, String?>) {
        val nm = ctx.getSystemService(NotificationManager::class.java) ?: return
        val existing = nm.activeNotifications.firstOrNull { it.tag == chatTag(chatId) }
        val style = existing?.let { NotificationCompat.MessagingStyle.extractMessagingStyleFromNotification(it.notification) }
            ?: NotificationCompat.MessagingStyle(Person.Builder().setName("You").build())
        style.addMessage(if (failed) "Not sent: $text" else (text ?: ""), System.currentTimeMillis(), null as Person?)
        post(ctx, chatId, style, extras)
    }

    /// Ringtone + vibration, max importance. Created here, not in MainActivity,
    /// because the first call can arrive before the app was ever opened again.
    fun ensureCallsChannel(ctx: Context) {
        val nm = ctx.getSystemService(NotificationManager::class.java) ?: return
        if (nm.getNotificationChannel(CALLS_CHANNEL) != null) return
        val ch = NotificationChannel(CALLS_CHANNEL, "Calls", NotificationManager.IMPORTANCE_HIGH)
        ch.description = "Incoming chat voice and video calls."
        ch.setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE),
            AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build())
        ch.enableVibration(true)
        ch.vibrationPattern = longArrayOf(0, 1000, 1000, 1000, 1000)
        nm.createNotificationChannel(ch)
    }
}

/// Reply / Mark as read / Decline, run without opening the app.
class ChatActionReceiver : BroadcastReceiver() {
    companion object {
        const val REPLY = "com.ezbizi.chat.REPLY"
        const val READ = "com.ezbizi.chat.READ"
        const val DECLINE = "com.ezbizi.chat.DECLINE"
    }

    override fun onReceive(ctx: Context, intent: Intent) {
        val chatId = intent.getStringExtra("chat_id") ?: return
        val callId = intent.getStringExtra("call_id")
        val extras = mapOf("chat_id" to chatId, "title" to intent.getStringExtra("title"),
            "is_group" to intent.getStringExtra("is_group"))
        val pending = goAsync()
        val app = ctx.applicationContext
        thread {
            try {
                when (intent.action) {
                    REPLY -> {
                        val text = RemoteInput.getResultsFromIntent(intent)
                            ?.getCharSequence("chat_reply")?.toString()?.trim()
                        if (!text.isNullOrEmpty()) {
                            val code = NativeSession.call(app, "POST", "/chat/chats/$chatId/messages",
                                "{\"body\":${jsonString(text)}}")
                            ChatNotifier.appendMine(app, chatId, text, code !in 200..299, extras)
                        }
                    }
                    READ -> {
                        ChatNotifier.cancelChat(app, chatId)
                        NativeSession.call(app, "PATCH", "/chat/chats/$chatId/settings", "{\"marked_unread\":false}")
                    }
                    DECLINE -> {
                        ChatNotifier.cancelCall(app, callId)
                        if (callId != null) NativeSession.call(app, "POST", "/chat/calls/$callId/decline", "{}")
                    }
                }
            } finally {
                pending.finish()
            }
        }
    }

    private fun jsonString(s: String): String {
        val sb = StringBuilder("\"")
        for (c in s) when (c) {
            '"' -> sb.append("\\\""); '\\' -> sb.append("\\\\")
            '\n' -> sb.append("\\n"); '\r' -> sb.append("\\r"); '\t' -> sb.append("\\t")
            else -> if (c < ' ') sb.append(String.format("\\u%04x", c.code)) else sb.append(c)
        }
        return sb.append('"').toString()
    }
}
