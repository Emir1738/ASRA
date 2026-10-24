package app.asra.client

import java.net.URI
import java.util.Locale

/** WebView yalnızca uygulamanın HTTPS kökenine aittir; diğer bağlantılar tarayıcıya gider. */
internal object AsraNavigation {
    private val siteHost = URI(AsraIdentity.SITE_ORIGIN).host.lowercase(Locale.ROOT)
    private val externalSchemes = setOf("http", "https", "mailto", "tel", "sms", "smsto", "geo", "whatsapp")

    fun isTrustedUrl(raw: String?): Boolean = parse(raw)?.let { uri ->
        uri.scheme?.lowercase(Locale.ROOT) == "https" &&
            uri.host?.lowercase(Locale.ROOT) == siteHost &&
            (uri.port == -1 || uri.port == 443) &&
            uri.rawUserInfo == null
    } ?: false

    fun canOpenExternally(raw: String?): Boolean = parse(raw)?.let { uri ->
        val scheme = uri.scheme?.lowercase(Locale.ROOT) ?: return@let false
        if (scheme !in externalSchemes || uri.rawUserInfo != null) return@let false
        if (scheme == "http" || scheme == "https") !uri.host.isNullOrBlank() else true
    } ?: false

    enum class ExternalAction { VIEW, DIAL, SEND_TO }

    /** Posta/SMS uygulamaları ACTION_VIEW değil ACTION_SENDTO dinler. */
    fun externalAction(raw: String?): ExternalAction? {
        if (!canOpenExternally(raw)) return null
        return when (parse(raw)?.scheme?.lowercase(Locale.ROOT)) {
            "tel" -> ExternalAction.DIAL
            "mailto", "sms", "smsto" -> ExternalAction.SEND_TO
            else -> ExternalAction.VIEW
        }
    }

    private fun parse(raw: String?): URI? = try {
        if (raw.isNullOrBlank()) null else URI(raw)
    } catch (_: Exception) {
        null
    }
}
