package app.asra.client

import android.app.Application
import android.os.Build
import android.os.UserManager
import android.webkit.CookieManager
import android.webkit.WebView

class AsraApp : Application() {
    override fun onCreate() {
        super.onCreate()
        try {
            if (Build.VERSION.SDK_INT >= 24) {
                val unlocked = try {
                    getSystemService(UserManager::class.java)?.isUserUnlocked != false
                } catch (_: Exception) {
                    true
                }
                if (!unlocked) {
                    AsraWatch.schedule(this, force = true)
                    return
                }
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                val process = getProcessName()
                if (process != packageName) {
                    WebView.setDataDirectorySuffix(process)
                }
            }
            WebView.setWebContentsDebuggingEnabled(false)
            CookieManager.getInstance().setAcceptCookie(true)
            AsraNotifier(this).ensureChannel()
            AsraWatch.schedule(this, force = true)
        } catch (_: Exception) {
        }
    }
}
