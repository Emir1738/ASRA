package app.asra.client

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.graphics.Color
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.os.Build
import org.json.JSONObject

class AsraNotifier(private val context: Context) {

    private val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
    private val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    fun ensureChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        manager.deleteNotificationChannel("asra_messages_v2")
        val existing = manager.getNotificationChannel(CHANNEL_ID)
        if (existing != null && existing.importance < NotificationManager.IMPORTANCE_HIGH) {
            manager.deleteNotificationChannel(CHANNEL_ID)
        }
        if (manager.getNotificationChannel(CHANNEL_ID) == null) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                "Asra mesajları",
                NotificationManager.IMPORTANCE_HIGH
            )
            channel.description = "Yeni oda mesajları"
            channel.enableLights(true)
            channel.lightColor = Color.WHITE
            channel.enableVibration(true)
            channel.vibrationPattern = longArrayOf(0, 180, 90, 180)
            channel.setShowBadge(true)
            channel.lockscreenVisibility = Notification.VISIBILITY_PRIVATE
            val sound = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)
            channel.setSound(
                sound,
                AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_NOTIFICATION)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                    .build()
            )
            manager.createNotificationChannel(channel)
        }
        if (manager.getNotificationChannel(ENGINE_CHANNEL_ID) == null) {
            val engine = NotificationChannel(
                ENGINE_CHANNEL_ID,
                "Asra arka plan",
                NotificationManager.IMPORTANCE_LOW
            )
            engine.description = "Yeni mesajlar bekleniyor"
            engine.setShowBadge(false)
            engine.enableLights(false)
            engine.enableVibration(false)
            engine.setSound(null, null)
            engine.lockscreenVisibility = Notification.VISIBILITY_PUBLIC
            manager.createNotificationChannel(engine)
        }
        manager.deleteNotificationChannel("asra_watch")
        manager.deleteNotificationChannel("asra_wake_silent")
        manager.deleteNotificationChannel("asra_messages")
    }

    fun engineNotification(): Notification {
        ensureChannel()
        val open = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
            action = Intent.ACTION_MAIN
            addCategory(Intent.CATEGORY_LAUNCHER)
        }
        val flags = PendingIntent.FLAG_UPDATE_CURRENT or
            if (Build.VERSION.SDK_INT >= 23) PendingIntent.FLAG_IMMUTABLE else 0
        val pending = PendingIntent.getActivity(context, 1, open, flags)
        val restart = Intent(context, AsraRestartReceiver::class.java).apply { action = "app.asra.client.RESTART" }
        val restartPending = PendingIntent.getBroadcast(context, 2, restart, flags)
        val builder = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Notification.Builder(context, ENGINE_CHANNEL_ID)
        } else {
            @Suppress("DEPRECATION")
            Notification.Builder(context)
        }
        builder.setSmallIcon(R.drawable.ic_stat_asra)
            .setContentTitle("Asra")
            .setContentText("Yeni mesajlar bekleniyor")
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setShowWhen(false)
            .setContentIntent(pending)
            .setDeleteIntent(restartPending)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            builder.setVisibility(Notification.VISIBILITY_PUBLIC)
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            builder.setCategory(Notification.CATEGORY_SERVICE)
        }
        if (Build.VERSION.SDK_INT >= 31) {
            builder.setForegroundServiceBehavior(Notification.FOREGROUND_SERVICE_IMMEDIATE)
        }
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            @Suppress("DEPRECATION")
            builder.setPriority(Notification.PRIORITY_LOW)
        }
        return builder.build()
    }

    fun handleRoomsPayload(raw: String, appVisible: Boolean) {
        try {
            val root = JSONObject(raw)
            val currentRoomId = root.optInt("currentRoomId", 0)
            val rooms = root.optJSONArray("rooms") ?: return
            val suppress = appVisible && AsraWatch.shouldSuppressNotify(context)
            for (i in 0 until rooms.length()) {
                val room = rooms.optJSONObject(i) ?: continue
                val id = room.optInt("id", 0)
                if (id <= 0) continue
                val unread = room.optInt("unread", room.optInt("unread_count", 0))
                val name = room.optString("name", "Asra")
                val previous = prefs.getInt(key(id), 0)
                if (unread <= 0) {
                    if (previous != 0) prefs.edit().putInt(key(id), unread).apply()
                    manager.cancel(notificationId(id))
                    continue
                }
                if (suppress || id == currentRoomId) {
                    prefs.edit().putInt(key(id), unread).apply()
                    manager.cancel(notificationId(id))
                    continue
                }
                if (unread <= previous) continue
                if (!AsraWatch.canNotify(context)) continue
                prefs.edit().putInt(key(id), unread).apply()
                showRoomNotification(id, name, unread)
            }
        } catch (_: Exception) {
        }
    }

    fun showDirect(title: String, body: String, roomId: Int) {
        if (AsraWatch.shouldSuppressNotify(context)) return
        show(title, body, roomId)
    }

    private fun showRoomNotification(roomId: Int, roomName: String, unread: Int) {
        val countLabel = if (unread > 99) "99+" else unread.toString()
        val body = if (unread == 1) {
            "Yeni bir kişi mesaj yazdı"
        } else {
            "$countLabel yeni mesaj"
        }
        show(roomName, body, roomId)
    }

    private fun show(title: String, body: String, roomId: Int) {
        if (!AsraWatch.canNotify(context)) return
        if (Build.VERSION.SDK_INT >= 24 && !manager.areNotificationsEnabled()) return
        ensureChannel()
        val open = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_NEW_TASK
            putExtra(MainActivity.EXTRA_ROOM_ID, roomId)
            action = "app.asra.client.OPEN_ROOM"
        }
        val flags = PendingIntent.FLAG_UPDATE_CURRENT or
            if (Build.VERSION.SDK_INT >= 23) PendingIntent.FLAG_IMMUTABLE else 0
        val pending = PendingIntent.getActivity(context, roomId, open, flags)
        val builder = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Notification.Builder(context, CHANNEL_ID)
        } else {
            @Suppress("DEPRECATION")
            Notification.Builder(context)
        }
        builder.setSmallIcon(R.drawable.ic_stat_asra)
            .setContentTitle(title.ifBlank { "Asra" })
            .setContentText(body)
            .setAutoCancel(true)
            .setContentIntent(pending)
            .setOnlyAlertOnce(false)
            .setDefaults(Notification.DEFAULT_SOUND or Notification.DEFAULT_VIBRATE or Notification.DEFAULT_LIGHTS)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            builder.setCategory(Notification.CATEGORY_MESSAGE)
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            builder.setVisibility(Notification.VISIBILITY_PRIVATE)
        }
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            @Suppress("DEPRECATION")
            builder.setPriority(Notification.PRIORITY_HIGH)
        }
        manager.notify(notificationId(roomId), builder.build())
    }

    private fun key(roomId: Int): String = "unread_$roomId"

    private fun notificationId(roomId: Int): Int = 7100 + roomId

    companion object {
        const val CHANNEL_ID = "asra_messages_v3"
        const val ENGINE_CHANNEL_ID = "asra_engine"
        const val WATCH_CHANNEL_ID = "asra_watch"
        private const val PREFS = "asra_notify_state"
    }
}
