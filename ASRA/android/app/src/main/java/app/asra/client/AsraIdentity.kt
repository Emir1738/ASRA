package app.asra.client

import android.content.Context
import android.webkit.CookieManager
import java.util.UUID

object AsraIdentity {
    const val HEADER_NAME = "X-Asra-Device-Id"
    const val PUSH_TOPIC_HEADER = "X-Asra-Push-Topic"
    const val COOKIE_NAME = "ASRA_APP_DEVICE"
    const val SITE_ORIGIN = "https://asra.freedev.app"

    private const val PREFS = "asra_identity"
    private const val KEY_DEVICE = "device_id"

    fun deviceId(context: Context): String {
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val existing = prefs.getString(KEY_DEVICE, null)
        if (!existing.isNullOrBlank()) return existing
        val created = "asra." + UUID.randomUUID().toString().replace("-", "")
        prefs.edit().putString(KEY_DEVICE, created).apply()
        return created
    }

    fun installSiteCookie(context: Context) {
        val id = deviceId(context)
        val cookie = "$COOKIE_NAME=$id; Path=/; Secure; SameSite=Strict; Max-Age=157680000"
        val manager = CookieManager.getInstance()
        manager.setAcceptCookie(true)
        manager.setCookie(SITE_ORIGIN, cookie)
        manager.flush()
    }
}
