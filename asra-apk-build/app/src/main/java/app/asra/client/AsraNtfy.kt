package app.asra.client

import android.content.Context
import android.content.SharedPreferences
import android.os.Build
import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedReader
import java.io.InputStreamReader
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest

object AsraNtfy {
    private const val PREFS = "asra_ntfy"
    private const val KEY_TOPIC = "topic"
    private const val KEY_SINCE = "since"
    private const val KEY_SITE_SINCE = "site_since"
    private const val KEY_USER = "user_id"
    private const val KEY_LAST_REG = "last_reg"
    private const val KEY_SIGS = "shown_sigs"
    private const val HOST = "https://ntfy.sh"

    private val pollLock = Any()

    @Volatile
    private var registerBusy = false

    private fun store(context: Context): SharedPreferences {
        val app = context.applicationContext
        val holder = if (Build.VERSION.SDK_INT >= 24) app.createDeviceProtectedStorageContext() else app
        return holder.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
    }

    fun topic(context: Context): String {
        val prefs = store(context)
        val existing = prefs.getString(KEY_TOPIC, "")
        if (!existing.isNullOrBlank()) return existing
        val digest = MessageDigest.getInstance("SHA-256")
            .digest(AsraIdentity.deviceId(context).toByteArray(Charsets.UTF_8))
        val hex = digest.joinToString("") { "%02x".format(it) }.take(20)
        val created = "asra$hex"
        prefs.edit().putString(KEY_TOPIC, created).apply()
        return created
    }

    fun rememberUser(context: Context, userId: Int) {
        if (userId > 0) {
            store(context).edit().putInt(KEY_USER, userId).apply()
        }
    }

    fun ensureRegistered(context: Context) {
        val app = context.applicationContext
        if (!AsraSession.shouldWatch(app)) return
        val prefs = store(app)
        val last = prefs.getLong(KEY_LAST_REG, 0L)
        if (System.currentTimeMillis() - last < 60_000L) return
        if (registerBusy) return
        registerBusy = true
        Thread({
            try {
                registerWithSite(app)
            } finally {
                registerBusy = false
            }
        }, "asra-ntfy-reg").start()
    }

    fun pollOnce(context: Context) {
        val app = context.applicationContext
        synchronized(pollLock) {
            // Push mimarisi: hostumuzu yoklamıyoruz (poll_push kaldırıldı).
            // Olaylar ntfy.sh kanalından gelir; bu sorgu harici push
            // servisinedir, kendi sunucumuz hit yemez.
            pollNtfy(app)
        }
    }

    private fun pollSite(app: Context) {
        val topic = topic(app)
        val prefs = store(app)
        val since = prefs.getString(KEY_SITE_SINCE, "0").orEmpty().ifBlank { "0" }
        val self = prefs.getInt(KEY_USER, 0)
        try {
            val raw = AsraHttp.get(
                app,
                "${AsraIdentity.SITE_ORIGIN}/auth.php?action=poll_push&topic=$topic&since=$since"
            ) ?: return
            val root = JSONObject(raw)
            if (!root.optBoolean("ok", false)) return
            val events = root.optJSONArray("events") ?: JSONArray()
            var lastId = since
            for (i in 0 until events.length()) {
                val event = events.optJSONObject(i) ?: continue
                val id = event.optString("id")
                if (id.isNotBlank()) lastId = id
                val senderId = event.optInt("sid", 0)
                if (self > 0 && senderId == self) continue
                if (AsraWatch.shouldSuppressNotify(app)) continue
                if (!AsraWatch.canNotify(app)) continue
                val roomName = event.optString("rn").ifBlank { "Asra" }
                val roomId = event.optInt("rid", 0)
                val body = event.optString("tx").ifBlank { "Yeni bir kişi mesaj yazdı" }
                if (markShown(prefs, roomId, senderId, body)) continue
                AsraNotifier(app).showDirect(roomName, body, roomId)
            }
            if (lastId != since) {
                prefs.edit().putString(KEY_SITE_SINCE, lastId).apply()
            }
        } catch (_: Exception) {
        }
    }

    private fun pollNtfy(app: Context) {
        // Sayfa görünürde ve kendi ntfy kanalını çalıştırıyorsa gerçek
        // zamanlı akış sayfada; motor bu aralıkta ntfy'yi sorgulamaz
        // (ntfy.sh yükü ve pil tasarrufu). Arka planda bildirimler aynen
        // çalışmaya devam eder (appVisible=false).
        if (AsraWatch.appVisible && AsraWatch.pageNtfyChannel) return
        val topic = topic(app)
        val prefs = store(app)
        val since = prefs.getString(KEY_SINCE, "0").orEmpty().ifBlank { "0" }
        val self = prefs.getInt(KEY_USER, 0)
        var connection: HttpURLConnection? = null
        try {
            val url = URL("$HOST/$topic/json?poll=1&since=$since")
            connection = (url.openConnection() as HttpURLConnection).apply {
                connectTimeout = 8000
                readTimeout = 8000
                instanceFollowRedirects = true
                requestMethod = "GET"
                setRequestProperty("Accept", "application/x-ndjson")
                setRequestProperty("User-Agent", "AsraApp/1.5.3")
            }
            if (connection.responseCode !in 200..299) return
            val reader = BufferedReader(InputStreamReader(connection.inputStream, Charsets.UTF_8))
            var lastId = since
            reader.useLines { lines ->
                lines.forEach { line ->
                    if (line.isBlank()) return@forEach
                    val json = try {
                        JSONObject(line)
                    } catch (_: Exception) {
                        return@forEach
                    }
                    val id = json.optString("id")
                    if (id.isNotBlank()) lastId = id
                    if (json.optString("event") != "message") return@forEach
                    val raw = json.optString("message")
                    var roomName = json.optString("title").ifBlank { "Asra" }
                    var roomId = 0
                    var senderId = 0
                    var body = "Yeni bir kişi mesaj yazdı"
                    try {
                        val payload = JSONObject(raw)
                        roomName = payload.optString("rn", roomName)
                        roomId = payload.optInt("rid", 0)
                        senderId = payload.optInt("sid", 0)
                        body = payload.optString("tx", body)
                    } catch (_: Exception) {
                        if (raw.isNotBlank()) body = raw
                    }
                    if (self > 0 && senderId == self) return@forEach
                    // Push mimarisi: olay gelince WebView anında güncellenir.
                    // Uygulama ön plandaysa bildirim yerine WebView'e iletilir
                    // (web istemci tek istekle tazelenir; periyodik yoklama yok).
                    if (AsraWatch.appVisible) {
                        val payload = JSONObject()
                            .put("rid", roomId)
                            .put("rn", roomName)
                            .put("tx", body)
                            .toString()
                        AsraBus.pushToWeb(payload)
                    }
                    if (AsraWatch.shouldSuppressNotify(app)) return@forEach
                    if (!AsraWatch.canNotify(app)) return@forEach
                    if (markShown(prefs, roomId, senderId, body)) return@forEach
                    AsraNotifier(app).showDirect(roomName, body, roomId)
                }
            }
            if (lastId != since) {
                prefs.edit().putString(KEY_SINCE, lastId).apply()
            }
        } catch (_: Exception) {
        } finally {
            connection?.disconnect()
        }
    }

    fun registerWithSite(context: Context) {
        val app = context.applicationContext
        val cookie = AsraSession.cookie(app)
        if (!AsraSession.hasSession(cookie)) return
        val topic = topic(app)
        val session = AsraHttp.get(app, "${AsraIdentity.SITE_ORIGIN}/auth.php?action=session") ?: return
        val root = try {
            JSONObject(session)
        } catch (_: Exception) {
            return
        }
        val csrf = root.optString("csrf_token")
        val user = root.optJSONObject("user")
        if (user != null) rememberUser(app, user.optInt("id", 0))
        if (csrf.isBlank()) return
        val result = AsraHttp.post(
            app,
            "${AsraIdentity.SITE_ORIGIN}/auth.php?action=register_push",
            JSONObject().put("topic", topic).toString(),
            mapOf("X-CSRF-Token" to csrf)
        )
        if (!result.isNullOrBlank()) {
            val parsed = try {
                JSONObject(result)
            } catch (_: Exception) {
                null
            }
            if (parsed != null && parsed.optBoolean("ok", false)) {
                store(app).edit().putLong(KEY_LAST_REG, System.currentTimeMillis()).apply()
            }
        }
    }

    private fun markShown(prefs: SharedPreferences, roomId: Int, senderId: Int, body: String): Boolean {
        val sig = "$roomId|$senderId|${body.take(80)}"
        val now = System.currentTimeMillis()
        val kept = mutableListOf<String>()
        val raw = prefs.getString(KEY_SIGS, "").orEmpty()
        if (raw.isNotBlank()) {
            raw.split('\n').forEach { line ->
                val parts = line.split('|', limit = 2)
                if (parts.size != 2) return@forEach
                val at = parts[0].toLongOrNull() ?: return@forEach
                if (now - at > 180_000L) return@forEach
                kept += line
                if (parts[1] == sig) return true
            }
        }
        kept += "$now|$sig"
        while (kept.size > 24) kept.removeAt(0)
        prefs.edit().putString(KEY_SIGS, kept.joinToString("\n")).apply()
        return false
    }
}
