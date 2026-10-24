package app.asra.client

/**
 * Geri tuşu, WebView'in asenkron JavaScript yanıtını beklerken başka bir geri
 * isteği göndermez. Sayfa değişirse veya renderer çökerse eski callback yeni
 * ekrana uygulanamaz. Activity zaman aşımını ayrıca yönetir.
 */
internal class AsraBackGate {
    private var sequence = 0L
    private var pending: Long? = null

    fun begin(): Long? {
        if (pending != null) return null
        val token = ++sequence
        pending = token
        return token
    }

    fun complete(token: Long): Boolean {
        if (pending != token) return false
        pending = null
        return true
    }

    fun cancel() {
        pending = null
    }
}
