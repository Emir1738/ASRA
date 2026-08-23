package app.asra.client

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.PowerManager

class AsraAlarmReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        if (!AsraSession.shouldWatch(context)) return
        AsraWatch.schedule(context, force = true)
        AsraWatch.startEngine(context)
        val pending = goAsync()
        Thread({
            val pm = context.getSystemService(Context.POWER_SERVICE) as PowerManager
            val lock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "asra:alarm")
            lock.setReferenceCounted(false)
            try {
                lock.acquire(25_000L)
                AsraWatch.startEngine(context)
                AsraInbox.poll(context)
            } catch (_: Exception) {
            } finally {
                try {
                    if (lock.isHeld) lock.release()
                } catch (_: Exception) {
                }
                pending.finish()
            }
        }, "asra-alarm").start()
    }
}
