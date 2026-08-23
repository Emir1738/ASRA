package app.asra.client

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

class AsraRestartReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        if (!AsraSession.shouldWatch(context)) return
        AsraWatch.schedule(context, force = true)
        AsraWatch.startEngine(context)
    }
}
