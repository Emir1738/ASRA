package app.asra.client

import android.content.Context
import org.json.JSONObject

object AsraInbox {
    private val lock = Any()

    @Volatile
    private var busy = false

    /**
     * @param ntfyOnly true: YALNIZCA ntfy.sh (harici push servisi) sorgulanır,
     *                 kendi sunucumuza (get_rooms vb.) HİÇ istek gitmez.
     *                 Arka plan döngüleri (servis/alarmlar/job) bunu kullanır.
     *                 false: kullanıcı eylemi (app açılışı vb.) — tam senkron.
     */
    fun poll(context: Context, ntfyOnly: Boolean = false) {
        if (busy) return
        synchronized(lock) {
            if (busy) return
            busy = true
            try {
                val app = context.applicationContext
                AsraNtfy.pollOnce(app)
                if (ntfyOnly) return
                val cookie = AsraSession.cookie(app)
                if (!AsraSession.hasSession(cookie)) {
                    AsraNtfy.ensureRegistered(app)
                    return
                }
                val rooms = fetchRooms(app) ?: return
                val mapped = JSONObject()
                mapped.put("rooms", rooms)
                mapped.put("route", "background")
                mapped.put("currentRoomId", 0)
                mapped.put("visible", if (AsraWatch.shouldSuppressNotify(app)) "visible" else "hidden")
                AsraNotifier(app).handleRoomsPayload(mapped.toString(), AsraWatch.shouldSuppressNotify(app))
            } finally {
                busy = false
            }
        }
    }

    private fun fetchRooms(app: Context): org.json.JSONArray? {
        var body = AsraHttp.get(app, "${AsraIdentity.SITE_ORIGIN}/auth.php?action=get_rooms") ?: return null
        var json = parse(body) ?: return null
        if (!json.optBoolean("ok", false)) {
            val code = json.optJSONObject("error")?.optString("code").orEmpty()
            if (code == "AUTH_REQUIRED" || code == "SESSION_REVOKED" || code == "CSRF_FAILED") {
                AsraHttp.get(app, "${AsraIdentity.SITE_ORIGIN}/auth.php?action=session")
                body = AsraHttp.get(app, "${AsraIdentity.SITE_ORIGIN}/auth.php?action=get_rooms") ?: return null
                json = parse(body) ?: return null
            }
        }
        if (!json.optBoolean("ok", false)) return null
        return json.optJSONArray("rooms")
    }

    private fun parse(raw: String): JSONObject? {
        return try {
            JSONObject(raw)
        } catch (_: Exception) {
            null
        }
    }
}
