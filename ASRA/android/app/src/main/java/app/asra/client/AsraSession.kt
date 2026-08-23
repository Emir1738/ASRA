package app.asra.client

import android.content.Context
import android.content.SharedPreferences
import android.os.Build
import android.webkit.CookieManager

object AsraSession {
    private const val PREFS = "asra_session"
    private const val KEY_COOKIE = "cookie"
    private const val KEY_WATCH = "watch"
    private const val KEY_UA = "user_agent"
    private const val KEY_MIGRATED = "migrated_de"

    fun prefs(context: Context): SharedPreferences {
        val app = context.applicationContext
        val store = if (Build.VERSION.SDK_INT >= 24) {
            app.createDeviceProtectedStorageContext()
        } else {
            app
        }
        val dest = store.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (!dest.getBoolean(KEY_MIGRATED, false)) {
            val src = app.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            dest.edit()
                .putString(KEY_COOKIE, src.getString(KEY_COOKIE, dest.getString(KEY_COOKIE, "")).orEmpty())
                .putBoolean(KEY_WATCH, src.getBoolean(KEY_WATCH, dest.getBoolean(KEY_WATCH, false)))
                .putString(KEY_UA, src.getString(KEY_UA, dest.getString(KEY_UA, "")).orEmpty())
                .putBoolean(KEY_MIGRATED, true)
                .apply()
        }
        return dest
    }

    fun persist(context: Context) {
        val store = prefs(context)
        val live = try {
            CookieManager.getInstance().getCookie(AsraIdentity.SITE_ORIGIN).orEmpty()
        } catch (_: Exception) {
            ""
        }
        val saved = store.getString(KEY_COOKIE, "").orEmpty()
        val editor = store.edit()
        if (hasSession(live)) {
            editor.putString(KEY_COOKIE, live)
            editor.putBoolean(KEY_WATCH, true)
        } else if (hasSession(saved)) {
            editor.putBoolean(KEY_WATCH, true)
        }
        editor.apply()
    }

    fun rememberUserAgent(context: Context, userAgent: String) {
        if (userAgent.isNotBlank()) {
            prefs(context).edit().putString(KEY_UA, userAgent).apply()
        }
    }

    fun userAgent(context: Context): String {
        val saved = prefs(context).getString(KEY_UA, "").orEmpty()
        return saved.ifBlank { "AsraApp/1.5.3" }
    }

    fun cookie(context: Context): String {
        val live = try {
            CookieManager.getInstance().getCookie(AsraIdentity.SITE_ORIGIN)
        } catch (_: Exception) {
            null
        }
        if (!live.isNullOrBlank() && hasSession(live)) return live
        return prefs(context).getString(KEY_COOKIE, "").orEmpty()
    }

    fun shouldWatch(context: Context): Boolean {
        val store = prefs(context)
        if (store.getBoolean(KEY_WATCH, false)) return true
        return hasSession(cookie(context))
    }

    fun setWatch(context: Context, enabled: Boolean) {
        prefs(context).edit().putBoolean(KEY_WATCH, enabled).apply()
        if (enabled) persist(context)
    }

    fun hasSession(cookieHeader: String): Boolean {
        return cookieHeader.contains("ASRA_SID=") || cookieHeader.contains("ASRA_REMEMBER=")
    }
}
