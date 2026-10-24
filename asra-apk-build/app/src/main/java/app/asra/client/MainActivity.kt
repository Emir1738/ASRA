package app.asra.client

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.app.DownloadManager
import android.content.ActivityNotFoundException
import android.content.ClipData
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Handler
import android.os.Looper
import android.content.res.Configuration
import android.graphics.Bitmap
import android.graphics.Color
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.http.SslError
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.os.Message
import android.provider.MediaStore
import android.view.View
import android.view.ViewGroup
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.view.WindowManager
import android.webkit.CookieManager
import android.webkit.DownloadListener
import android.webkit.GeolocationPermissions
import android.webkit.MimeTypeMap
import android.webkit.PermissionRequest
import android.webkit.RenderProcessGoneDetail
import android.webkit.SslErrorHandler
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebBackForwardList
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebView.WebViewTransport
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.FrameLayout
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import java.io.File
import java.util.Locale

class MainActivity : Activity() {

    private lateinit var webContainer: FrameLayout
    private lateinit var fullscreenContainer: FrameLayout
    private lateinit var progressBar: ProgressBar
    private lateinit var errorOverlay: View
    private lateinit var errorMessage: TextView
    private lateinit var retryButton: Button

    private var webView: WebView? = null
    private var popupWebView: WebView? = null
    private val backGate = AsraBackGate()
    private var backTimeout: Runnable? = null
    private var customView: View? = null
    private var customViewCallback: WebChromeClient.CustomViewCallback? = null
    private var originalSystemUi: Int = 0

    private var filePathCallback: ValueCallback<Array<Uri>>? = null
    private val cameraOutputUris = mutableListOf<Uri>()
    private var pendingCaptureOnly: Boolean = false
    private var pendingAcceptTypes: Array<String> = emptyArray()
    private var pendingAllowMultiple: Boolean = false
    private var pendingWebPermission: PermissionRequest? = null

    private var lastFailedUrl: String = START_URL
    private var pageHadError: Boolean = false
    private var pendingRoomId: Int = 0

    private lateinit var deviceId: String
    private val mainHandler = Handler(Looper.getMainLooper())

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        deviceId = AsraIdentity.deviceId(this)
        AsraIdentity.installSiteCookie(this)
        // Güncelleme/temizlik sonrası silinen oturum çerezlerini WebView'e geri yaz
        AsraSession.restoreToWebView(this)
        applyWindowChrome()
        setContentView(R.layout.activity_main)
        captureRoomIntent(intent)

        webContainer = findViewById(R.id.webview_container)
        fullscreenContainer = findViewById(R.id.fullscreen_container)
        progressBar = findViewById(R.id.progress)
        errorOverlay = findViewById(R.id.error_overlay)
        errorMessage = findViewById(R.id.error_message)
        retryButton = findViewById(R.id.retry_button)
        retryButton.setOnClickListener { retryLoad() }
        cleanupStaleCaptures()

        if (Build.VERSION.SDK_INT >= 33) {
            onBackInvokedDispatcher.registerOnBackInvokedCallback(1000) { handleBack() }
        }

        attachWebView(savedInstanceState)
        startCookieSync()
    }

    private fun applyWindowChrome() {
        window.addFlags(WindowManager.LayoutParams.FLAG_DRAWS_SYSTEM_BAR_BACKGROUNDS)
        window.statusBarColor = Color.BLACK
        window.navigationBarColor = Color.BLACK
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            window.attributes = window.attributes.apply {
                layoutInDisplayCutoutMode =
                    WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES
            }
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            window.setDecorFitsSystemWindows(true)
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun attachWebView(savedInstanceState: Bundle?) {
        val view = WebView(this).apply {
            id = WEBVIEW_ID
            layoutParams = FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
            )
            setBackgroundColor(Color.BLACK)
            overScrollMode = View.OVER_SCROLL_NEVER
            isFocusable = true
            isFocusableInTouchMode = true
            isVerticalScrollBarEnabled = false
            isHorizontalScrollBarEnabled = false
            setLayerType(View.LAYER_TYPE_HARDWARE, null)
        }
        configureSettings(view)
        view.removeJavascriptInterface("AsraNative")
        view.addJavascriptInterface(
            AsraBridge(
                identity = deviceId,
                onOpenRoom = { openRoomInSite(it) }
            ),
            "AsraNative"
        )
        view.webViewClient = AsraClient()
        view.webChromeClient = AsraChrome()
        view.setDownloadListener(AsraDownloads())
        // Site ve API aynı kökende; üçüncü taraf çerezlerine gerek yok.
        CookieManager.getInstance().setAcceptThirdPartyCookies(view, false)

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            view.setRendererPriorityPolicy(WebView.RENDERER_PRIORITY_BOUND, true)
        }

        webContainer.removeAllViews()
        webContainer.addView(view)
        webView = view

        val restored = savedInstanceState?.let { view.restoreState(it) }
        if (restored == null || restored.size == 0 ||
            !AsraNavigation.isTrustedUrl(restored.currentItem?.url)
        ) {
            // Önceki sürümde kayda giren dış URL'yi geri YÜKLEME.
            try {
                view.stopLoading()
                view.clearCache(true)
                CookieManager.getInstance().flush()
            } catch (_: Exception) {}
            view.loadUrl("$START_URL?_v=${BuildConfig.VERSION_NAME}_${System.currentTimeMillis()}")
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun configureSettings(view: WebView) {
        val settings = view.settings
        settings.javaScriptEnabled = true
        settings.domStorageEnabled = true
        settings.databaseEnabled = true
        settings.javaScriptCanOpenWindowsAutomatically = false // sadece kullanıcı dokunuşuyla yeni pencere
        settings.setSupportMultipleWindows(true)
        settings.loadsImagesAutomatically = true
        settings.blockNetworkImage = false
        settings.blockNetworkLoads = false
        settings.useWideViewPort = true
        settings.loadWithOverviewMode = true
        settings.setSupportZoom(true)
        settings.builtInZoomControls = true
        settings.displayZoomControls = false
        settings.mediaPlaybackRequiresUserGesture = false
        settings.allowFileAccess = false
        settings.allowContentAccess = true
        settings.allowFileAccessFromFileURLs = false
        settings.allowUniversalAccessFromFileURLs = false
        settings.mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
        settings.cacheMode = WebSettings.LOAD_DEFAULT
        settings.offscreenPreRaster = true
        settings.layoutAlgorithm = WebSettings.LayoutAlgorithm.NORMAL
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            settings.safeBrowsingEnabled = true
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            settings.isAlgorithmicDarkeningAllowed = false
        }
        settings.userAgentString = AsraUserAgent.current(settings.userAgentString.orEmpty(), BuildConfig.VERSION_NAME)
        AsraSession.rememberUserAgent(this, settings.userAgentString.orEmpty())
    }

    private fun retryLoad() {
        hideError()
        pageHadError = false
        val target = lastFailedUrl.takeIf { AsraNavigation.isTrustedUrl(it) } ?: START_URL
        val view = webView
        if (view == null) {
            attachWebView(null)
        } else if (!hasNetwork()) {
            showError(getString(R.string.error_offline), target)
        } else {
            try {
                view.clearCache(true)
                // Cache bypass ile yeniden yükle - sunucu hatası sonrası eski HTML kalmasın
                val bustTarget = target + (if (target.contains("?")) "&" else "?") + "_retry=" + System.currentTimeMillis()
                view.loadUrl(bustTarget)
            } catch (_: Exception) {
                view.loadUrl(target)
            }
        }
    }

    private fun showError(message: String, failedUrl: String) {
        lastFailedUrl = failedUrl.takeIf { AsraNavigation.isTrustedUrl(it) } ?: START_URL
        errorMessage.text = message
        errorOverlay.visibility = View.VISIBLE
        progressBar.visibility = View.GONE
    }

    private fun hideError() {
        errorOverlay.visibility = View.GONE
    }

    private val cookieSync = object : Runnable {
        override fun run() {
            try {
                CookieManager.getInstance().flush()
                AsraSession.persist(this@MainActivity)
            } catch (_: Exception) {
            }
            mainHandler.postDelayed(this, 15000)
        }
    }

    private fun startCookieSync() {
        mainHandler.removeCallbacks(cookieSync)
        mainHandler.post(cookieSync)
    }

    private fun captureRoomIntent(intent: Intent?) {
        val roomId = intent?.getIntExtra(EXTRA_ROOM_ID, 0) ?: 0
        if (roomId > 0) pendingRoomId = roomId
    }

    private fun injectBridge(view: WebView) {
        view.evaluateJavascript(AsraInject.SCRIPT, null)
        if (pendingRoomId > 0) {
            mainHandler.postDelayed({ openRoomInSite(pendingRoomId) }, 700)
        }
    }

    private fun openRoomInSite(roomId: Int) {
        if (roomId <= 0) return
        pendingRoomId = roomId
        val view = webView ?: return
        view.evaluateJavascript("window.asraOpenRoom && window.asraOpenRoom($roomId);", null)
        mainHandler.postDelayed({ pendingRoomId = 0 }, 2500)
    }

    private fun hasNetwork(): Boolean {
        val cm = getSystemService(CONNECTIVITY_SERVICE) as ConnectivityManager
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            val network = cm.activeNetwork ?: return false
            val caps = cm.getNetworkCapabilities(network) ?: return false
            caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
        } else {
            @Suppress("DEPRECATION")
            cm.activeNetworkInfo?.isConnected == true
        }
    }

    private fun cancelPendingBack() {
        backTimeout?.let { mainHandler.removeCallbacks(it) }
        backTimeout = null
        backGate.cancel()
    }

    private fun handleBack() {
        if (customView != null) {
            hideCustomView()
            return
        }
        val view = webView
        if (view == null || errorOverlay.visibility == View.VISIBLE ||
            !AsraNavigation.isTrustedUrl(view.url)
        ) {
            finish()
            return
        }
        val token = backGate.begin() ?: return
        // Renderer yanıt vermezse geri tuşu kalıcı olarak kilitlenmemeli.
        // Yalnızca bu isteğe ait zamanlayıcı çıkış yapabilir; sayfa değişirse
        // veya renderer yeniden kurulursa cancelPendingBack() onu iptal eder.
        val timeout = Runnable {
            if (backGate.complete(token)) {
                backTimeout = null
                if (webView === view && !isFinishing && !isDestroyed) finish()
            }
        }
        backTimeout = timeout
        mainHandler.postDelayed(timeout, BACK_TIMEOUT_MS)
        // WebView geçmişi SPA ekranları için boş, yenileme sonrası ise bayat
        // giriş sayfaları içerir. Önce site içindeki modal/menü/geri düğmesini
        // kullan; ana ekranda doğrudan çık.
        try {
            view.evaluateJavascript(AsraInject.BACK_SCRIPT) { result ->
                if (!backGate.complete(token)) return@evaluateJavascript
                mainHandler.removeCallbacks(timeout)
                backTimeout = null
                if (webView !== view || isFinishing || isDestroyed) return@evaluateJavascript
                if (result != "\"handled\"") finish()
            }
        } catch (_: Exception) {
            if (backGate.complete(token)) {
                mainHandler.removeCallbacks(timeout)
                backTimeout = null
                finish()
            }
        }
    }

    @Deprecated("Deprecated in Java")
    override fun onBackPressed() {
        handleBack()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        webView?.saveState(outState)
    }

    override fun onConfigurationChanged(newConfig: Configuration) {
        super.onConfigurationChanged(newConfig)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        captureRoomIntent(intent)
        if (pendingRoomId > 0) {
            openRoomInSite(pendingRoomId)
        }
    }

    override fun onPause() {
        cancelPendingBack()
        mainHandler.removeCallbacks(cookieSync)
        CookieManager.getInstance().flush()
        AsraSession.persist(this)
        webView?.onPause()
        super.onPause()
    }

    override fun onResume() {
        super.onResume()
        webView?.onResume()
        AsraSession.persist(this)
        startCookieSync()
    }

    override fun onDestroy() {
        cancelPendingBack()
        cancelFileCallback()
        pendingWebPermission?.deny()
        pendingWebPermission = null
        destroyPopupWebView()
        hideCustomView()
        CookieManager.getInstance().flush()
        AsraSession.persist(this)
        val view = webView
        webView = null
        if (view != null) {
            webContainer.removeView(view)
            view.stopLoading()
            view.webChromeClient = null
            view.webViewClient = WebViewClient()
            view.setDownloadListener(null)
            view.removeAllViews()
            view.destroy()
        }
        mainHandler.removeCallbacks(cookieSync)
        super.onDestroy()
    }

    private fun cancelFileCallback() {
        filePathCallback?.onReceiveValue(null)
        filePathCallback = null
        cleanupCaptureUris(emptySet())
    }

    private fun openExternal(uri: Uri): Boolean {
        // javascript:, intent:, file:, content: vb. şemaları WebView'e de
        // başka bir uygulamaya da geçirme. Yalnızca bilinen bağlantılar açılır.
        val action = when (AsraNavigation.externalAction(uri.toString())) {
            AsraNavigation.ExternalAction.DIAL -> Intent.ACTION_DIAL
            AsraNavigation.ExternalAction.SEND_TO -> Intent.ACTION_SENDTO
            AsraNavigation.ExternalAction.VIEW -> Intent.ACTION_VIEW
            null -> return true
        }
        val intent = Intent(action, uri)
        return try {
            startActivity(intent)
            true
        } catch (_: ActivityNotFoundException) {
            Toast.makeText(this, "Uygun uygulama bulunamadı", Toast.LENGTH_SHORT).show()
            true
        } catch (_: Exception) {
            Toast.makeText(this, "Bağlantı açılamadı", Toast.LENGTH_SHORT).show()
            true
        }
    }

    private fun cleanupCaptureUris(keep: Set<Uri>) {
        val permissions = Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_GRANT_WRITE_URI_PERMISSION
        for (uri in cameraOutputUris) {
            if (uri !in keep) {
                val name = uri.lastPathSegment ?: continue
                File(CaptureFileProvider.captureDir(this), name).delete()
            }
            try { revokeUriPermission(uri, permissions) } catch (_: Exception) {}
        }
        cameraOutputUris.clear()
    }

    private fun cleanupStaleCaptures() {
        val cutoff = System.currentTimeMillis() - 24L * 60L * 60L * 1000L
        CaptureFileProvider.captureDir(this).listFiles()?.forEach { file ->
            if (file.isFile && file.name.startsWith("asra_") && file.lastModified() < cutoff) {
                file.delete()
            }
        }
    }

    private fun launchFileChooser(captureOnly: Boolean, acceptTypes: Array<String>, allowMultiple: Boolean) {
        pendingCaptureOnly = captureOnly
        pendingAcceptTypes = acceptTypes
        pendingAllowMultiple = allowMultiple

        val accept = AcceptSpec.parse(acceptTypes)
        // Sadece dosya/fotoğraf SEÇEN kullanıcıdan kamera izni istenmez.
        // İzin reddedilirse capture ipucunda bile belge seçimine düşülür.
        if (AsraCapturePolicy.needsCameraPrompt(
                captureOnly,
                packageManager.hasSystemFeature(PackageManager.FEATURE_CAMERA_ANY),
                checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED,
                accept.images,
                accept.videos
            )
        ) {
            requestPermissions(arrayOf(Manifest.permission.CAMERA), REQ_CAMERA)
            return
        }
        startChooserIntents(captureOnly, accept, allowMultiple)
    }

    private fun startChooserIntents(captureOnly: Boolean, accept: AcceptSpec, allowMultiple: Boolean) {
        cleanupCaptureUris(emptySet())
        val extras = mutableListOf<Intent>()
        val (photo, video) = AsraCapturePolicy.cameraOptions(
            checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED,
            accept.images,
            accept.videos
        )
        if (photo) createImageCaptureIntent()?.let { extras += it }
        if (video) createVideoCaptureIntent()?.let { extras += it }

        if (AsraCapturePolicy.directCapture(captureOnly, extras.size)) {
            try {
                startActivityForResult(extras.first(), REQ_FILE)
                return
            } catch (_: Exception) {
                // Kamera uygulaması başlatılamasa bile ek dosya seçilebilmeli.
                cleanupCaptureUris(emptySet())
                extras.clear()
            }
        }

        val picker = Intent(Intent.ACTION_GET_CONTENT).apply {
            addCategory(Intent.CATEGORY_OPENABLE)
            type = accept.primaryType
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            putExtra(Intent.EXTRA_ALLOW_MULTIPLE, allowMultiple)
            if (accept.extraMimes != null) {
                putExtra(Intent.EXTRA_MIME_TYPES, accept.extraMimes)
            }
        }

        // accept=image/*,video/* + capture iki türü de kabul eder: eski kod
        // yalnızca ilk (fotoğraf) uygulamasını açıp video çekimini saklıyordu.
        val cameraOnlyChooser = captureOnly && extras.size > 1
        val chooser = Intent(Intent.ACTION_CHOOSER).apply {
            putExtra(Intent.EXTRA_INTENT, if (cameraOnlyChooser) extras.first() else picker)
            putExtra(Intent.EXTRA_TITLE, getString(R.string.chooser_title))
            val alternatives = if (cameraOnlyChooser) extras.drop(1) else extras
            if (alternatives.isNotEmpty()) {
                putExtra(Intent.EXTRA_INITIAL_INTENTS, alternatives.toTypedArray())
            }
        }
        try {
            startActivityForResult(chooser, REQ_FILE)
        } catch (_: Exception) {
            cleanupCaptureUris(emptySet())
            try {
                startActivityForResult(picker, REQ_FILE)
            } catch (_: Exception) {
                cancelFileCallback()
                Toast.makeText(this, "Dosya seçici bulunamadı", Toast.LENGTH_SHORT).show()
            }
        }
    }

    private fun createImageCaptureIntent(): Intent? {
        if (!packageManager.hasSystemFeature(PackageManager.FEATURE_CAMERA_ANY)) return null
        val name = "asra_${System.currentTimeMillis()}.jpg"
        val file = File(CaptureFileProvider.captureDir(this), name)
        try {
            if (!file.exists()) file.createNewFile()
        } catch (_: Exception) {
            return null
        }
        val uri = CaptureFileProvider.uriFor(name)
        val intent = Intent(MediaStore.ACTION_IMAGE_CAPTURE)
        intent.putExtra(MediaStore.EXTRA_OUTPUT, uri)
        intent.clipData = ClipData.newUri(contentResolver, "capture", uri)
        intent.addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION or Intent.FLAG_GRANT_READ_URI_PERMISSION)
        if (intent.resolveActivity(packageManager) == null) { file.delete(); return null }
        cameraOutputUris += uri
        grantUriToCameraApps(intent, uri)
        return intent
    }

    private fun createVideoCaptureIntent(): Intent? {
        if (!packageManager.hasSystemFeature(PackageManager.FEATURE_CAMERA_ANY)) return null
        val name = "asra_${System.currentTimeMillis()}.mp4"
        val file = File(CaptureFileProvider.captureDir(this), name)
        try {
            if (!file.exists()) file.createNewFile()
        } catch (_: Exception) {
            return null
        }
        val uri = CaptureFileProvider.uriFor(name)
        val intent = Intent(MediaStore.ACTION_VIDEO_CAPTURE)
        intent.putExtra(MediaStore.EXTRA_OUTPUT, uri)
        intent.clipData = ClipData.newUri(contentResolver, "capture", uri)
        intent.addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION or Intent.FLAG_GRANT_READ_URI_PERMISSION)
        if (intent.resolveActivity(packageManager) == null) { file.delete(); return null }
        cameraOutputUris += uri
        grantUriToCameraApps(intent, uri)
        return intent
    }

    private fun grantUriToCameraApps(intent: Intent, uri: Uri) {
        val flags = Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_GRANT_WRITE_URI_PERMISSION
        val resInfo = packageManager.queryIntentActivities(intent, PackageManager.MATCH_DEFAULT_ONLY)
        for (info in resInfo) {
            grantUriPermission(info.activityInfo.packageName, uri, flags)
        }
    }

    override fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        when (requestCode) {
            REQ_CAMERA -> {
                if (filePathCallback != null) {
                    val accept = AcceptSpec.parse(pendingAcceptTypes)
                    startChooserIntents(pendingCaptureOnly, accept, pendingAllowMultiple)
                }
            }
            REQ_WEB_PERM -> resolveWebPermission()
        }
    }

    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode != REQ_FILE) return
        val callback = filePathCallback
        filePathCallback = null
        if (callback == null) {
            cleanupCaptureUris(emptySet())
            return
        }

        val uris = if (resultCode == RESULT_OK) {
            val captured = cameraOutputUris.filter { uri ->
                val name = uri.lastPathSegment ?: return@filter false
                val file = File(CaptureFileProvider.captureDir(this), name)
                file.isFile && file.length() > 0L
            }
            val picked = mutableListOf<Uri>()
            val clip = data?.clipData
            if (clip != null) {
                for (i in 0 until clip.itemCount) {
                    clip.getItemAt(i).uri?.let { picked += it }
                }
            } else {
                data?.data?.let { picked += it }
            }
            // Kamera thumbnail URI döndürse bile dolu tam boyutlu dosya
            // gönderilir. Tek dosyalı form çoklu URI ile beslenmez.
            AsraCapturePolicy.selectedUris(captured, picked, pendingAllowMultiple)
        } else emptyList()
        cleanupCaptureUris(uris.toSet())
        callback.onReceiveValue(if (uris.isEmpty()) null else uris.toTypedArray())
    }

    private fun requestWebPermissions(request: PermissionRequest) {
        // İzin kökeni HTTPS Asra olmalı; DRM kimliği/gelecek kaynak türleri
        // sessizce onaylanmaz. İzin diyaloğu sayfa değiştirilirken de denetlenir.
        if (!AsraNavigation.isTrustedUrl(request.origin?.toString()) ||
            !AsraNavigation.isTrustedUrl(webView?.url) ||
            request.resources.any {
                it != PermissionRequest.RESOURCE_VIDEO_CAPTURE &&
                    it != PermissionRequest.RESOURCE_AUDIO_CAPTURE
            }
        ) {
            request.deny()
            return
        }
        pendingWebPermission?.deny()
        pendingWebPermission = request
        val needed = mutableListOf<String>()
        for (res in request.resources) {
            when (res) {
                PermissionRequest.RESOURCE_VIDEO_CAPTURE ->
                    if (checkSelfPermission(Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
                        needed += Manifest.permission.CAMERA
                    }
                PermissionRequest.RESOURCE_AUDIO_CAPTURE ->
                    if (checkSelfPermission(Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
                        needed += Manifest.permission.RECORD_AUDIO
                    }
            }
        }
        if (needed.isEmpty()) {
            resolveWebPermission()
        } else {
            requestPermissions(needed.distinct().toTypedArray(), REQ_WEB_PERM)
        }
    }

    private fun resolveWebPermission() {
        val request = pendingWebPermission ?: return
        pendingWebPermission = null
        if (!AsraNavigation.isTrustedUrl(request.origin?.toString()) ||
            !AsraNavigation.isTrustedUrl(webView?.url)
        ) {
            request.deny()
            return
        }
        val granted = mutableListOf<String>()
        for (res in request.resources) {
            when (res) {
                PermissionRequest.RESOURCE_VIDEO_CAPTURE ->
                    if (checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) {
                        granted += res
                    }
                PermissionRequest.RESOURCE_AUDIO_CAPTURE ->
                    if (checkSelfPermission(Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) {
                        granted += res
                    }
            }
        }
        if (granted.isEmpty()) request.deny() else request.grant(granted.toTypedArray())
    }

    private fun destroyPopupWebView() {
        val popup = popupWebView ?: return
        popupWebView = null
        try {
            popup.stopLoading()
            popup.webChromeClient = null
            popup.webViewClient = WebViewClient()
            popup.destroy()
        } catch (_: Exception) {}
    }

    private fun hideCustomView() {
        if (customView == null) return
        fullscreenContainer.removeAllViews()
        fullscreenContainer.visibility = View.GONE
        webContainer.visibility = View.VISIBLE
        customView = null
        customViewCallback?.onCustomViewHidden()
        customViewCallback = null
        restoreSystemUi()
    }

    private fun applyFullscreenUi() {
        originalSystemUi = window.decorView.systemUiVisibility
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            window.insetsController?.let { controller ->
                controller.hide(WindowInsets.Type.statusBars() or WindowInsets.Type.navigationBars())
                controller.systemBarsBehavior =
                    WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
            }
        } else {
            @Suppress("DEPRECATION")
            window.decorView.systemUiVisibility = (
                View.SYSTEM_UI_FLAG_FULLSCREEN
                    or View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                    or View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                    or View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                    or View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
                    or View.SYSTEM_UI_FLAG_LAYOUT_STABLE
                )
        }
    }

    private fun restoreSystemUi() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            window.insetsController?.show(WindowInsets.Type.statusBars() or WindowInsets.Type.navigationBars())
        } else {
            @Suppress("DEPRECATION")
            window.decorView.systemUiVisibility = originalSystemUi
        }
    }

    private fun rebuildWebViewAfterCrash() {
        cancelPendingBack()
        val old = webView
        var history: WebBackForwardList? = null
        val state = Bundle()
        try {
            history = old?.saveState(state)
        } catch (_: Exception) {
        }
        if (old != null) {
            try {
                webContainer.removeView(old)
                old.destroy()
            } catch (_: Exception) {
            }
        }
        webView = null
        attachWebView(if (history != null && history.size > 0) state else null)
        if (webView?.url.isNullOrBlank()) {
            webView?.loadUrl(START_URL)
        }
    }

    private inner class AsraClient : WebViewClient() {
        override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
            if (!request.isForMainFrame) return false
            val uri = request.url
            if (AsraNavigation.isTrustedUrl(uri.toString())) return false
            return openExternal(uri)
        }

        @Deprecated("Deprecated in Java")
        override fun shouldOverrideUrlLoading(view: WebView, url: String): Boolean {
            if (AsraNavigation.isTrustedUrl(url)) return false
            return openExternal(Uri.parse(url))
        }

        override fun onPageStarted(view: WebView, url: String?, favicon: Bitmap?) {
            if (view !== webView) return
            cancelPendingBack()
            pageHadError = false
            if (!AsraNavigation.isTrustedUrl(url)) {
                // shouldOverrideUrlLoading atlanabilen ilk yükleme/302 yönlendirmesi.
                // Dış sayfa AsraNative köprüsüne erişmeden durdurulur.
                view.stopLoading()
                pageHadError = true
                showError(getString(R.string.error_message), START_URL)
                return
            }
            if (errorOverlay.visibility == View.VISIBLE && url != lastFailedUrl) hideError()
        }

        override fun onPageFinished(view: WebView, url: String?) {
            if (view !== webView || !AsraNavigation.isTrustedUrl(url)) return
            CookieManager.getInstance().flush()
            AsraSession.persist(this@MainActivity)
            if (!pageHadError) injectBridge(view)
            if (!pageHadError && errorOverlay.visibility == View.VISIBLE) hideError()
            if (progressBar.progress >= 100) progressBar.visibility = View.GONE
        }

        override fun onReceivedHttpError(
            view: WebView,
            request: WebResourceRequest,
            errorResponse: WebResourceResponse
        ) {
            if (view !== webView || !request.isForMainFrame || errorResponse.statusCode < 400) return
            pageHadError = true
            showError(getString(R.string.error_server), request.url?.toString() ?: START_URL)
        }

        override fun onReceivedError(
            view: WebView,
            request: WebResourceRequest,
            error: WebResourceError
        ) {
            if (view !== webView || !request.isForMainFrame) return
            pageHadError = true
            val url = request.url?.toString() ?: view.url ?: START_URL
            val message = if (!hasNetwork()) {
                getString(R.string.error_offline)
            } else {
                getString(R.string.error_message)
            }
            showError(message, url)
        }

        @Deprecated("Deprecated in Java")
        override fun onReceivedError(
            view: WebView,
            errorCode: Int,
            description: String?,
            failingUrl: String?
        ) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M || view !== webView) return
            pageHadError = true
            val message = if (!hasNetwork()) {
                getString(R.string.error_offline)
            } else {
                getString(R.string.error_message)
            }
            showError(message, failingUrl ?: START_URL)
        }

        override fun onReceivedSslError(view: WebView, handler: SslErrorHandler, error: SslError) {
            handler.cancel() // sertifika hatasını ASLA yok sayma
            if (view === webView && AsraNavigation.isTrustedUrl(error.url)) {
                pageHadError = true
                showError(getString(R.string.error_ssl), error.url ?: START_URL)
            }
        }

        override fun onRenderProcessGone(view: WebView, detail: RenderProcessGoneDetail): Boolean {
            if (view == webView) {
                rebuildWebViewAfterCrash()
                return true
            }
            return false
        }
    }

    private inner class AsraChrome : WebChromeClient() {
        override fun onProgressChanged(view: WebView, newProgress: Int) {
            if (errorOverlay.visibility == View.VISIBLE) {
                progressBar.visibility = View.GONE
                return
            }
            progressBar.progress = newProgress
            progressBar.visibility = if (newProgress in 1..99) View.VISIBLE else View.GONE
        }

        override fun onShowFileChooser(
            webView: WebView,
            filePathCallback: ValueCallback<Array<Uri>>,
            fileChooserParams: FileChooserParams
        ): Boolean {
            if (webView !== this@MainActivity.webView || !AsraNavigation.isTrustedUrl(webView.url)) {
                filePathCallback.onReceiveValue(null)
                return true
            }
            cancelFileCallback()
            this@MainActivity.filePathCallback = filePathCallback
            val accept = fileChooserParams.acceptTypes ?: emptyArray()
            val multiple = fileChooserParams.mode == FileChooserParams.MODE_OPEN_MULTIPLE
            launchFileChooser(fileChooserParams.isCaptureEnabled, accept, multiple)
            return true
        }

        override fun onPermissionRequest(request: PermissionRequest) {
            requestWebPermissions(request)
        }

        override fun onPermissionRequestCanceled(request: PermissionRequest) {
            if (pendingWebPermission == request) pendingWebPermission = null
        }

        override fun onGeolocationPermissionsShowPrompt(
            origin: String?,
            callback: GeolocationPermissions.Callback?
        ) {
            callback?.invoke(origin, false, false)
        }

        override fun onCreateWindow(
            view: WebView,
            isDialog: Boolean,
            isUserGesture: Boolean,
            resultMsg: Message
        ): Boolean {
            if (!isUserGesture || view !== webView || !AsraNavigation.isTrustedUrl(view.url)) return false
            val transport = resultMsg.obj as? WebViewTransport ?: return false
            destroyPopupWebView()
            // _blank bağlantısının hedefi onCreateWindow'da henüz bilinmez.
            // Geçici WebView URL'yi yakalar; script/bridge içermez ve hemen
            // yok edilir. İlk yükleme shouldOverride'ı ATLARSA onPageStarted
            // de yakalar (eski sürümde Bilgi Odası bağlantıları boş kalıyordu).
            val host = view
            var forwarded = false
            val popup = WebView(this@MainActivity).apply {
                settings.javaScriptEnabled = false
                settings.allowFileAccess = false
                webViewClient = object : WebViewClient() {
                    private fun forward(v: WebView, raw: String?) {
                        if (forwarded || raw.isNullOrBlank() || raw == "about:blank") return
                        forwarded = true
                        if (AsraNavigation.isTrustedUrl(raw)) host.loadUrl(raw)
                        else openExternal(Uri.parse(raw))
                        mainHandler.post { if (popupWebView === v) destroyPopupWebView() }
                    }

                    override fun shouldOverrideUrlLoading(v: WebView, request: WebResourceRequest): Boolean {
                        if (request.isForMainFrame) forward(v, request.url.toString())
                        return true
                    }

                    override fun onPageStarted(v: WebView, url: String?, favicon: Bitmap?) {
                        forward(v, url)
                    }

                    override fun onRenderProcessGone(v: WebView, detail: RenderProcessGoneDetail): Boolean {
                        if (popupWebView === v) destroyPopupWebView()
                        return true
                    }
                }
            }
            popupWebView = popup
            transport.webView = popup
            resultMsg.sendToTarget()
            mainHandler.postDelayed({ if (popupWebView === popup) destroyPopupWebView() }, 10000)
            return true
        }

        override fun onCloseWindow(window: WebView) {
            if (popupWebView === window) destroyPopupWebView()
        }

        override fun onShowCustomView(view: View, callback: CustomViewCallback) {
            if (customView != null) {
                callback.onCustomViewHidden()
                return
            }
            customView = view
            customViewCallback = callback
            webContainer.visibility = View.GONE
            fullscreenContainer.visibility = View.VISIBLE
            fullscreenContainer.removeAllViews()
            fullscreenContainer.addView(
                view,
                FrameLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    ViewGroup.LayoutParams.MATCH_PARENT
                )
            )
            applyFullscreenUi()
        }

        override fun onHideCustomView() {
            hideCustomView()
        }
    }

    private inner class AsraDownloads : DownloadListener {
        override fun onDownloadStart(
            url: String,
            userAgent: String?,
            contentDisposition: String?,
            mimeType: String?,
            contentLength: Long
        ) {
            if (!AsraNavigation.isTrustedUrl(url)) {
                // Dış indirmeyi tarayıcıya bırak; Asra çerezlerini/kimliğini
                // DownloadManager aracılığıyla başka bir kökene gönderme.
                if (AsraNavigation.canOpenExternally(url)) openExternal(Uri.parse(url))
                else Toast.makeText(this@MainActivity, "Bu indirme desteklenmiyor", Toast.LENGTH_SHORT).show()
                return
            }
            try {
                val request = DownloadManager.Request(Uri.parse(url))
                val resolvedMime = mimeType?.takeIf { it.isNotBlank() } ?: "application/octet-stream"
                request.setMimeType(resolvedMime)
                request.addRequestHeader("User-Agent", userAgent?.takeIf { it.isNotBlank() }
                    ?: webView?.settings?.userAgentString ?: AsraSession.userAgent(this@MainActivity))
                val cookie = CookieManager.getInstance().getCookie(url)
                if (!cookie.isNullOrBlank()) request.addRequestHeader("Cookie", cookie)
                request.setNotificationVisibility(
                    DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED
                )
                val name = URLUtil.guessFileName(url, contentDisposition, resolvedMime)
                request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, name)
                request.setTitle(name)
                val dm = getSystemService(DOWNLOAD_SERVICE) as DownloadManager
                dm.enqueue(request)
                Toast.makeText(this@MainActivity, "İndirme başladı", Toast.LENGTH_SHORT).show()
            } catch (_: Exception) {
                try {
                    startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                } catch (_: Exception) {
                    Toast.makeText(this@MainActivity, "İndirme başlatılamadı", Toast.LENGTH_SHORT).show()
                }
            }
        }
    }

    private data class AcceptSpec(
        val primaryType: String,
        val extraMimes: Array<String>?,
        val images: Boolean,
        val videos: Boolean
    ) {
        companion object {
            fun parse(raw: Array<String>?): AcceptSpec {
                val tokens = raw
                    ?.flatMap { it.split(',', ' ') }
                    ?.map { it.trim() }
                    ?.filter { it.isNotEmpty() }
                    .orEmpty()
                if (tokens.isEmpty()) {
                    return AcceptSpec("*/*", null, images = true, videos = true)
                }
                val mimes = tokens.map { token ->
                    when {
                        token.contains('/') -> token
                        token.startsWith('.') -> {
                            val ext = token.removePrefix(".").lowercase(Locale.US)
                            MimeTypeMap.getSingleton().getMimeTypeFromExtension(ext) ?: "*/*"
                        }
                        else -> token
                    }
                }.distinct()
                val images = mimes.any { it.startsWith("image/") || it == "*/*" }
                val videos = mimes.any { it.startsWith("video/") || it == "*/*" }
                val primary = when {
                    mimes.size == 1 -> mimes[0]
                    mimes.all { it.startsWith("image/") } -> "image/*"
                    mimes.all { it.startsWith("video/") } -> "video/*"
                    else -> "*/*"
                }
                val extras = if (mimes.size > 1) mimes.toTypedArray() else null
                return AcceptSpec(primary, extras, images, videos)
            }
        }
    }

    companion object {
        const val EXTRA_ROOM_ID = "asra_room_id"
        private const val START_URL = "https://asra.42web.io" // cache-bust ile her açılışta güncel sürüm
        private const val REQ_FILE = 4101
        private const val REQ_CAMERA = 4102
        private const val REQ_WEB_PERM = 4103
        private const val BACK_TIMEOUT_MS = 2500L
        private const val WEBVIEW_ID = 0x51A1001
    }
}
