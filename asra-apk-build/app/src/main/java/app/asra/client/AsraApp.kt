package app.asra.client

import android.app.Application
import android.os.Build
import android.webkit.CookieManager
import android.webkit.WebView

/**
 * Uygulama girişi.
 *
 * v1.7.0: Bildirim/push altyapısı TAMAMEN kaldırıldı. Bu sınıfta artık
 * Firebase başlatması, bildirim kanalı kurulumu ve arka plan zamanlayıcısı
 * YOKTUR; uygulama yalnızca WebView ortamını hazırlar.
 */
class AsraApp : Application() {
    override fun onCreate() {
        super.onCreate()
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                val process = getProcessName()
                if (process != packageName) {
                    WebView.setDataDirectorySuffix(process)
                }
            }
            WebView.setWebContentsDebuggingEnabled(false)
            CookieManager.getInstance().setAcceptCookie(true)
        } catch (_: Exception) {
        }
    }
}
