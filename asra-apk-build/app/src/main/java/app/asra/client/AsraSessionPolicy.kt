package app.asra.client

/** CookieManager.getCookie() bir Cookie başlığı döndürür, Set-Cookie değil.
 * Her oturum çerezi WebView'e AYRI bir Set-Cookie olarak geri yazılmalıdır. */
internal object AsraSessionCookies {
    private val names = setOf("ASRA_SID", "ASRA_REMEMBER")

    fun pairs(raw: String): List<Pair<String, String>> = raw.split(';').mapNotNull { part ->
        val pos = part.indexOf('=')
        if (pos < 1) return@mapNotNull null
        val name = part.substring(0, pos).trim()
        val value = part.substring(pos + 1).trim()
        // Değerler sunucuda PHP oturum kimliği veya hex:hex hatırla belirtecidir.
        if (name !in names || value.isEmpty() || value.length > 256 ||
            !value.all { it.isLetterOrDigit() && it.code < 128 || it in "_:,-" }
        ) return@mapNotNull null
        name to value
    }.distinctBy { it.first }

    fun header(raw: String): String = pairs(raw).joinToString("; ") { (name, value) -> "$name=$value" }
    fun hasSession(raw: String): Boolean = pairs(raw).isNotEmpty()

    /** Yeni CP kaydı varsa eski DE çereziyle üzerine yazma; yalnızca CP boşsa DE'yi taşı. */
    fun migrationCandidate(current: String, legacy: String): String =
        header(current).ifBlank { header(legacy) }

    fun restoreHeaders(raw: String): List<String> = pairs(raw).map { (name, value) ->
        "$name=$value; Path=/; Secure; HttpOnly; SameSite=Strict"
    }
}

internal object AsraUserAgent {
    fun current(raw: String, version: String): String {
        val cleaned = raw.replace(Regex("(?:^|\\s+)AsraApp/[^\\s]+"), "").trim()
        return if (cleaned.isEmpty()) "AsraApp/$version" else "$cleaned AsraApp/$version"
    }
}
