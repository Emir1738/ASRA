package app.asra.client

import android.app.Service
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import java.util.concurrent.Executors
import java.util.concurrent.ScheduledFuture
import java.util.concurrent.TimeUnit

class AsraNotifyService : Service() {

    private val executor = Executors.newSingleThreadScheduledExecutor { runnable ->
        Thread(runnable, "asra-inbox").apply { isDaemon = true }
    }
    private var task: ScheduledFuture<*>? = null

    override fun onCreate() {
        super.onCreate()
        AsraWatch.serviceAlive = true
        enterForeground()
        task = executor.scheduleWithFixedDelay({
            val pm = getSystemService(POWER_SERVICE) as PowerManager
            val lock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "asra:inbox")
            lock.setReferenceCounted(false)
            try {
                lock.acquire(20_000L)
                // Push mimarisi: arka plan döngüsü hostu YOKLAMAZ (yalnız ntfy.sh)
                AsraInbox.poll(this, ntfyOnly = true)
            } catch (_: Exception) {
            } finally {
                try {
                    if (lock.isHeld) lock.release()
                } catch (_: Exception) {
                }
            }
        }, 15, 60, TimeUnit.SECONDS)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        AsraWatch.serviceAlive = true
        enterForeground()
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        AsraWatch.serviceAlive = false
        task?.cancel(false)
        executor.shutdownNow()
        if (AsraSession.shouldWatch(this)) {
            AsraWatch.schedule(this, force = true)
        }
        super.onDestroy()
    }

    override fun onTaskRemoved(rootIntent: Intent?) {
        AsraSession.persist(this)
        AsraWatch.schedule(this, force = true)
        super.onTaskRemoved(rootIntent)
    }

    private fun enterForeground() {
        val notification = AsraNotifier(this).engineNotification()
        try {
            if (Build.VERSION.SDK_INT >= 34) {
                startForeground(
                    ENGINE_ID,
                    notification,
                    ServiceInfo.FOREGROUND_SERVICE_TYPE_REMOTE_MESSAGING
                )
            } else {
                startForeground(ENGINE_ID, notification)
            }
        } catch (_: Exception) {
            try {
                startForeground(ENGINE_ID, notification)
            } catch (_: Exception) {
            }
        }
    }

    companion object {
        const val ENGINE_ID = 7001
    }
}
