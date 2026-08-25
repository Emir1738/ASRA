package app.asra.client

/**
 * App -> WebView push köprüsü.
 *
 * Push mimarisi: sunucuya periyodik "yeni mesaj var mı?" yoklaması yapılmaz.
 * Olay (yeni mesaj, onay kararı) ntfy kanalıyla geldiğinde, uygulama ön
 * plandaysa WebView'e anında iletilir; web istemci tek istekle güncellenir.
 * (ntfy.sh sorguları harici push servisinedir; kendi hostumuzu hit yemez.)
 */
object AsraBus {
    @Volatile
    var webPush: ((String) -> Unit)? = null

    fun pushToWeb(payload: String) {
        val push = webPush ?: return
        try {
            push(payload)
        } catch (_: Exception) {
        }
    }
}
