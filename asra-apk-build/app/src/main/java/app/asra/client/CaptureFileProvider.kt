package app.asra.client

import android.content.ContentProvider
import android.content.ContentValues
import android.database.Cursor
import android.database.MatrixCursor
import android.net.Uri
import android.os.ParcelFileDescriptor
import android.provider.OpenableColumns
import android.webkit.MimeTypeMap
import java.io.File

class CaptureFileProvider : ContentProvider() {

    override fun onCreate(): Boolean = true

    override fun query(
        uri: Uri,
        projection: Array<out String>?,
        selection: String?,
        selectionArgs: Array<out String>?,
        sortOrder: String?
    ): Cursor {
        val file = resolve(uri)
        val columns = projection ?: arrayOf(OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE)
        val cursor = MatrixCursor(columns)
        val row = arrayOfNulls<Any>(columns.size)
        columns.forEachIndexed { index, column ->
            row[index] = when (column) {
                OpenableColumns.DISPLAY_NAME -> file.name
                OpenableColumns.SIZE -> if (file.exists()) file.length() else 0L
                else -> null
            }
        }
        cursor.addRow(row)
        return cursor
    }

    override fun getType(uri: Uri): String {
        val ext = resolve(uri).name.substringAfterLast('.', missingDelimiterValue = "")
        return MimeTypeMap.getSingleton().getMimeTypeFromExtension(ext.lowercase())
            ?: "application/octet-stream"
    }

    override fun openFile(uri: Uri, mode: String): ParcelFileDescriptor {
        val file = resolve(uri)
        // "rw" dosyayı KESMEMELİ: kamera yazdıktan sonra yeniden açıp okurken
        // eski uygulama bütün içeriği MODE_TRUNCATE yüzünden sıfırlıyordu.
        val flags = ParcelFileDescriptor.parseMode(mode)
        if (mode.contains("w") || mode.contains("a")) {
            file.parentFile?.mkdirs()
            if (!file.exists()) file.createNewFile()
        }
        return ParcelFileDescriptor.open(file, flags)
    }

    override fun insert(uri: Uri, values: ContentValues?): Uri? = null

    override fun delete(uri: Uri, selection: String?, selectionArgs: Array<out String>?): Int = 0

    override fun update(
        uri: Uri,
        values: ContentValues?,
        selection: String?,
        selectionArgs: Array<out String>?
    ): Int = 0

    private fun resolve(uri: Uri): File {
        val ctx = context ?: throw IllegalStateException("provider not attached")
        val name = uri.lastPathSegment ?: throw IllegalArgumentException("missing name")
        if (!name.matches(Regex("asra_[0-9]{13,}\\.(jpg|mp4)"))) {
            throw SecurityException("invalid capture path")
        }
        return File(captureDir(ctx), name)
    }

    companion object {
        const val AUTHORITY = "app.asra.client.capture"

        fun captureDir(context: android.content.Context): File {
            return File(context.cacheDir, "capture").apply { mkdirs() }
        }

        fun uriFor(fileName: String): Uri {
            return Uri.Builder()
                .scheme("content")
                .authority(AUTHORITY)
                .appendPath(fileName)
                .build()
        }
    }
}
