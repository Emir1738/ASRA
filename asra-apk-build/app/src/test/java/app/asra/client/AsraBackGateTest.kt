package app.asra.client

import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class AsraBackGateTest {
    @Test fun oneRequestAtATimeAndTimeoutOrCallbackCompletesIt() {
        val gate = AsraBackGate()
        val token = gate.begin()!!
        assertNull(gate.begin())
        assertTrue(gate.complete(token))
        assertFalse(gate.complete(token)) // geç gelen zamanlayıcı çıkış yapmamalı
        assertNotNull(gate.begin())
    }

    @Test fun navigationOrRendererCrashInvalidatesOldCallbacks() {
        val gate = AsraBackGate()
        val old = gate.begin()!!
        gate.cancel()
        val current = gate.begin()!!
        assertFalse(gate.complete(old))
        assertTrue(gate.complete(current))
    }
}
