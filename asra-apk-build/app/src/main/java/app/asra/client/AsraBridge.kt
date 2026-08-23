package app.asra.client

import android.content.Context
import android.os.Handler
import android.os.Looper
import android.webkit.JavascriptInterface

class AsraBridge(
    private val context: Context,
    private val identity: String,
    private val notifier: AsraNotifier,
    private val onOpenRoom: (Int) -> Unit,
    private val isAppVisible: () -> Boolean,
    private val requestNotifyPermission: () -> Unit
) {
    private val main = Handler(Looper.getMainLooper())

    @JavascriptInterface
    fun getDeviceId(): String = identity

    @JavascriptInterface
    fun isNativeApp(): Boolean = true

    @JavascriptInterface
    fun notifyRooms(payload: String) {
        main.post {
            notifier.handleRoomsPayload(payload, isAppVisible())
            AsraWatch.sync(context)
        }
    }

    @JavascriptInterface
    fun notify(title: String, body: String, roomId: String) {
        val id = roomId.toIntOrNull() ?: 0
        main.post { notifier.showDirect(title, body, id) }
    }

    @JavascriptInterface
    fun requestNotifications() {
        main.post {
            requestNotifyPermission()
            AsraWatch.sync(context)
        }
    }

    @JavascriptInterface
    fun setWatching(active: Boolean) {
        main.post {
            if (active) AsraWatch.start(context) else AsraWatch.sync(context)
        }
    }

    @JavascriptInterface
    fun openRoom(roomId: String) {
        val id = roomId.toIntOrNull() ?: return
        main.post { onOpenRoom(id) }
    }
}
