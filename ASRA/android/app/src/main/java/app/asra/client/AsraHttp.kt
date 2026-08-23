package app.asra.client

import android.content.Context
import android.webkit.CookieManager
import java.io.BufferedReader
import java.io.InputStreamReader
import java.net.HttpURLConnection
import java.net.URL

object AsraHttp {
    fun get(app: Context, rawUrl: String, extraHeaders: Map<String, String> = emptyMap()): String? {
        return exchange(app, rawUrl, "GET", null, extraHeaders)
    }

    fun post(app: Context, rawUrl: String, body: String, extraHeaders: Map<String, String> = emptyMap()): String? {
        return exchange(app, rawUrl, "POST", body, extraHeaders)
    }

    private fun exchange(
        app: Context,
        rawUrl: String,
        method: String,
        body: String?,
        extraHeaders: Map<String, String>,
        retryOnBusy: Boolean = true
    ): String? {
        var connection: HttpURLConnection? = null
        return try {
            connection = (URL(rawUrl).openConnection() as HttpURLConnection).apply {
                // Optimize: short connect, moderate read; InfinityFree slow so 10s read
                connectTimeout = 6000
                readTimeout = 10000
                instanceFollowRedirects = false
                requestMethod = method
                setRequestProperty("Accept", "application/json")
                setRequestProperty("User-Agent", AsraSession.userAgent(app))
                setRequestProperty(AsraIdentity.HEADER_NAME, AsraIdentity.deviceId(app))
                setRequestProperty(AsraIdentity.PUSH_TOPIC_HEADER, AsraNtfy.topic(app))
                val cookie = AsraSession.cookie(app)
                if (cookie.isNotBlank()) setRequestProperty("Cookie", cookie)
                extraHeaders.forEach { (key, value) -> setRequestProperty(key, value) }
                if (body != null) {
                    doOutput = true
                    setRequestProperty("Content-Type", "application/json; charset=utf-8")
                }
            }
            if (body != null) {
                connection.outputStream.use { it.write(body.toByteArray(Charsets.UTF_8)) }
            }
            captureCookies(app, connection)
            val code = connection.responseCode
            if (code in 300..399) {
                val location = connection.getHeaderField("Location")
                if (!location.isNullOrBlank()) {
                    return get(app, resolve(rawUrl, location), extraHeaders)
                }
            }
            // 503 Service Busy -> sessiz retry (sunucu yoğun)
            if (code == 503 && retryOnBusy) {
                try { Thread.sleep(1500) } catch (_: Exception) {}
                connection.disconnect()
                return exchange(app, rawUrl, method, body, extraHeaders, retryOnBusy = false)
            }
            val stream = if (code in 200..399) connection.inputStream else connection.errorStream
            val raw = stream?.use { BufferedReader(InputStreamReader(it, Charsets.UTF_8)).readText() }
            // HTML dönerse (Cloudflare / 500 HTML) JSON hatası olmasın: null döndür, üst katman retry edecek
            if (raw != null && raw.trimStart().startsWith("<", ignoreCase = true) && raw.contains("<html", ignoreCase = true)) {
                // HTML hata sayfası - geçici, retry için null
                if (retryOnBusy) {
                    try { Thread.sleep(1200) } catch (_: Exception) {}
                    connection.disconnect()
                    return exchange(app, rawUrl, method, body, extraHeaders, retryOnBusy = false)
                }
                return null
            }
            raw
        } catch (_: Exception) {
            null
        } finally {
            connection?.disconnect()
        }
    }

    private fun resolve(base: String, location: String): String {
        return try {
            URL(URL(base), location).toString()
        } catch (_: Exception) {
            location
        }
    }

    private fun captureCookies(app: Context, connection: HttpURLConnection) {
        try {
            val fields = connection.headerFields ?: return
            val manager = CookieManager.getInstance()
            manager.setAcceptCookie(true)
            fields.forEach { (name, values) ->
                if (name != null && name.equals("Set-Cookie", ignoreCase = true)) {
                    values.orEmpty().forEach { raw ->
                        if (!raw.isNullOrBlank()) {
                            manager.setCookie(AsraIdentity.SITE_ORIGIN, raw)
                        }
                    }
                }
            }
            manager.flush()
            AsraSession.persist(app)
        } catch (_: Exception) {
        }
    }
}
