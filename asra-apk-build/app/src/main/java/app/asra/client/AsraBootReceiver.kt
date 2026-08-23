package app.asra.client

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

class AsraBootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        if (intent?.action.isNullOrBlank()) return
        try {
            AsraSession.persist(context)
            if (!AsraSession.shouldWatch(context)) return
            AsraWatch.schedule(context, force = true)
            AsraWatch.startEngine(context, force = true)
        } catch (_: Exception) {
        }
    }
}
