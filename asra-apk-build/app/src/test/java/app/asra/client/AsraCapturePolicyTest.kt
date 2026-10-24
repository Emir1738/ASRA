package app.asra.client

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class AsraCapturePolicyTest {
    @Test fun ordinaryImageOrVideoPickerDoesNotPromptForCamera() {
        assertFalse(AsraCapturePolicy.needsCameraPrompt(false, true, false, true, false))
        assertFalse(AsraCapturePolicy.needsCameraPrompt(false, true, false, false, true))
        assertEquals(false to false, AsraCapturePolicy.cameraOptions(false, true, true))
    }

    @Test fun explicitCapturePromptsOnlyForAvailableRelevantCamera() {
        assertTrue(AsraCapturePolicy.needsCameraPrompt(true, true, false, true, false))
        assertTrue(AsraCapturePolicy.needsCameraPrompt(true, true, false, false, true))
        assertFalse(AsraCapturePolicy.needsCameraPrompt(true, false, false, true, false))
        assertFalse(AsraCapturePolicy.needsCameraPrompt(true, true, false, false, false))
        assertFalse(AsraCapturePolicy.needsCameraPrompt(true, true, true, true, false))
    }

    @Test fun deniedPermissionFallsBackToPickerAndCaptureMatchesMime() {
        assertEquals(false to false, AsraCapturePolicy.cameraOptions(false, true, false))
        assertEquals(false to true, AsraCapturePolicy.cameraOptions(true, false, true))
        assertEquals(true to false, AsraCapturePolicy.cameraOptions(true, true, false))
        assertEquals(false to false, AsraCapturePolicy.cameraOptions(true, false, false))
    }

    @Test fun cameraOffersChoiceForBothPhotoAndVideo() {
        assertTrue(AsraCapturePolicy.directCapture(true, 1))
        assertFalse(AsraCapturePolicy.directCapture(true, 2))
        assertFalse(AsraCapturePolicy.directCapture(true, 0))
        assertFalse(AsraCapturePolicy.directCapture(false, 1))
    }

    @Test fun fullSizeCaptureWinsAndSingleFileFormsNeverReceiveMultipleUris() {
        val thumbnail = listOf("thumbnail")
        assertEquals(listOf("full-size"), AsraCapturePolicy.selectedUris(listOf("full-size"), thumbnail, false))
        assertEquals(listOf("first"), AsraCapturePolicy.selectedUris(emptyList(), listOf("first", "second"), false))
        assertEquals(listOf("first", "second"),
            AsraCapturePolicy.selectedUris(emptyList(), listOf("first", "first", "second"), true))
        assertEquals(emptyList<String>(), AsraCapturePolicy.selectedUris(emptyList<String>(), emptyList(), true))
    }
}
