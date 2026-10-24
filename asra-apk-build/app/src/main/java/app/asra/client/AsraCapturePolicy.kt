package app.asra.client

/** Galeri/belge seçimi izinsiz çalışır; kamera yalnızca açıkça yakalama istendiğinde sorulur. */
internal object AsraCapturePolicy {
    fun needsCameraPrompt(
        captureOnly: Boolean,
        hasCamera: Boolean,
        cameraGranted: Boolean,
        acceptsImage: Boolean,
        acceptsVideo: Boolean
    ): Boolean = captureOnly && hasCamera && !cameraGranted && (acceptsImage || acceptsVideo)

    fun cameraOptions(cameraGranted: Boolean, acceptsImage: Boolean, acceptsVideo: Boolean): Pair<Boolean, Boolean> =
        if (cameraGranted) acceptsImage to acceptsVideo else false to false

    /** Doğrudan kamera yalnızca tek tür için uygundur; foto+video seçimi gerekiyorsa kamera seçicisi açılır. */
    fun directCapture(captureOnly: Boolean, cameraOptionCount: Int): Boolean =
        captureOnly && cameraOptionCount == 1

    /** Kamera uygulaması hem çıktı dosyası hem thumbnail URI döndürürse tam boyutlu çıktıyı tercih et. */
    fun <T> selectedUris(captured: List<T>, picked: List<T>, allowMultiple: Boolean): List<T> {
        val unique = (if (captured.isNotEmpty()) captured else picked).distinct()
        return if (captured.isNotEmpty() || !allowMultiple) unique.take(1) else unique
    }
}
