package app.asra.client

import android.os.Handler
import android.os.Looper
import android.webkit.JavascriptInterface

/**
 * Sayfa <-> uygulama köprüsü.
 *
 * v1.7.0: Bildirim sistemi kaldırıldığı için bu köprü yalnızca iki şey
 * sağlar — cihaz kimliği ve "ben uygulamayım" bilgisi. Eski sürümlerin
 * sayfayla uyumlu kalması için bildirimle ilgili yöntemler İMZA OLARAK
 * durur ama hiçbir şey yapmaz (no-op); böylece eski bir sayfa yüklense
 * bile JavaScript tarafı hata vermez.
 */
class AsraBridge(
    private val identity: String,
    private val onOpenRoom: (Int) -> Unit
) {
    private val main = Handler(Looper.getMainLooper())

    @JavascriptInterface
    fun getDeviceId(): String = identity

    @JavascriptInterface
    fun isNativeApp(): Boolean = true

    /** Bildirim kaldırıldı: yalnızca eski sayfalarla uyumluluk için durur. */
    @JavascriptInterface
    fun notifyRooms(payload: String) {
    }

    /** Bildirim kaldırıldı: yalnızca eski sayfalarla uyumluluk için durur. */
    @JavascriptInterface
    fun notify(title: String, body: String, roomId: String) {
    }

    /** Bildirim kaldırıldı: izin İSTENMEZ (no-op). */
    @JavascriptInterface
    fun requestNotifications() {
    }

    /** Arka plan izleme kaldırıldı (no-op). */
    @JavascriptInterface
    fun setWatching(active: Boolean) {
    }

    @JavascriptInterface
    fun openRoom(roomId: String) {
        val id = roomId.toIntOrNull() ?: return
        main.post { onOpenRoom(id) }
    }
}
