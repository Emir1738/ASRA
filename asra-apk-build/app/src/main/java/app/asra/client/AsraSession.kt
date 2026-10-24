package app.asra.client

import android.content.Context
import android.content.SharedPreferences
import android.os.Build
import android.webkit.CookieManager

object AsraSession {
    private const val PREFS = "asra_session"
    private const val KEY_COOKIE = "cookie"
    private const val KEY_UA = "user_agent"
    private const val KEY_MIGRATED_TO_CP = "migrated_to_credential_storage"

    /** Eski sürümler çerezleri kilit ekranı açıkken bile erişilebilen DE alana
     * yazıyordu. CP'de oturum varsa onu koru; yoksa DE kaydını taşı. DE
     * kopyasını ancak başarılı, kalıcı yazımdan SONRA sil. */
    @Synchronized
    fun prefs(context: Context): SharedPreferences {
        val app = context.applicationContext
        val store = app.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (Build.VERSION.SDK_INT < 24) return store
        val legacy = app.createDeviceProtectedStorageContext()
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (!store.getBoolean(KEY_MIGRATED_TO_CP, false)) {
            val oldCookie = legacy.getString(KEY_COOKIE, "").orEmpty()
            val ownCookie = store.getString(KEY_COOKIE, "").orEmpty()
            val latest = AsraSessionCookies.migrationCandidate(ownCookie, oldCookie)
            val oldUa = legacy.getString(KEY_UA, "").orEmpty()
            val ownUa = store.getString(KEY_UA, "").orEmpty()
            val edit = store.edit().putBoolean(KEY_MIGRATED_TO_CP, true)
            if (latest.isNotEmpty()) edit.putString(KEY_COOKIE, latest) else edit.remove(KEY_COOKIE)
            val agent = ownUa.ifBlank { oldUa }
            if (agent.isNotEmpty()) edit.putString(KEY_UA, agent)
            if (!edit.commit()) return store // DE kaydı kaybolmamalı
        }
        if (legacy.contains(KEY_COOKIE) || legacy.contains(KEY_UA) || legacy.contains("watch")) {
            legacy.edit().remove(KEY_COOKIE).remove(KEY_UA).remove("watch")
                .remove("migrated_de").commit()
        }
        return store
    }

    /** WebView deposu silindiğinde hatırla belirtecini AYRI bir çerez olarak
     * geri kur. Mevcut canlı oturum varsa başka kullanıcının kaydını ezme. */
    fun restoreToWebView(context: Context) {
        try {
            val manager = CookieManager.getInstance()
            if (AsraSessionCookies.hasSession(manager.getCookie(AsraIdentity.SITE_ORIGIN).orEmpty())) return
            val saved = prefs(context).getString(KEY_COOKIE, "").orEmpty()
            val headers = AsraSessionCookies.restoreHeaders(saved)
            if (headers.isEmpty()) return
            headers.forEach { manager.setCookie(AsraIdentity.SITE_ORIGIN, it) }
            manager.flush()
        } catch (_: Exception) {
        }
    }

    fun persist(context: Context) {
        val store = prefs(context)
        val live = try {
            AsraSessionCookies.header(CookieManager.getInstance().getCookie(AsraIdentity.SITE_ORIGIN).orEmpty())
        } catch (_: Exception) {
            // WebView okunamadıysa kayıtlı oturumu SİLME.
            return
        }
        val saved = store.getString(KEY_COOKIE, "").orEmpty()
        if (saved == live) return
        if (live.isEmpty()) store.edit().remove(KEY_COOKIE).apply()
        else store.edit().putString(KEY_COOKIE, live).apply()
    }

    fun rememberUserAgent(context: Context, userAgent: String) {
        if (userAgent.isNotBlank()) prefs(context).edit().putString(KEY_UA, userAgent).apply()
    }

    fun userAgent(context: Context): String {
        val saved = prefs(context).getString(KEY_UA, "").orEmpty()
        return AsraUserAgent.current(saved, BuildConfig.VERSION_NAME)
    }

    fun cookie(context: Context): String {
        val live = try {
            AsraSessionCookies.header(CookieManager.getInstance().getCookie(AsraIdentity.SITE_ORIGIN).orEmpty())
        } catch (_: Exception) {
            ""
        }
        if (live.isNotEmpty()) return live
        return AsraSessionCookies.header(prefs(context).getString(KEY_COOKIE, "").orEmpty())
    }

    fun hasSession(cookieHeader: String): Boolean = AsraSessionCookies.hasSession(cookieHeader)
}
