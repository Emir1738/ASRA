package app.asra.client

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class AsraSessionPolicyTest {
    @Test fun restoreBothSessionAndRememberAsDistinctSecureCookies() {
        val raw = "ASRA_APP_DEVICE=fake123; ASRA_SID=abc123; ASRA_REMEMBER=beef:feed; mode=dark"
        val headers = AsraSessionCookies.restoreHeaders(raw)
        assertEquals(2, headers.size)
        assertEquals("ASRA_SID=abc123; Path=/; Secure; HttpOnly; SameSite=Strict", headers[0])
        assertEquals("ASRA_REMEMBER=beef:feed; Path=/; Secure; HttpOnly; SameSite=Strict", headers[1])
        assertEquals("ASRA_SID=abc123; ASRA_REMEMBER=beef:feed", AsraSessionCookies.header(raw))
        assertTrue(AsraSessionCookies.hasSession(raw))
    }

    @Test fun noResurrectionFromBlankOrLookalikeCookies() {
        listOf("", "ASRA_SID=; ASRA_REMEMBER=", "XASRA_SID=abc", "ASRA_REMEMBER2=beef:feed",
            "ASRA_SID=x\r\nSet-Cookie:y", "ASRA_SID=non ascii", "mode=dark"
        ).forEach { raw ->
            assertFalse("invalid: $raw", AsraSessionCookies.hasSession(raw))
            assertTrue(AsraSessionCookies.restoreHeaders(raw).isEmpty())
        }
    }

    @Test fun migrationDoesNotOverwriteAnAlreadyValidCredentialStorageSession() {
        assertEquals("ASRA_SID=new-user", AsraSessionCookies.migrationCandidate(
            "ASRA_SID=new-user", "ASRA_SID=old-user"))
        assertEquals("ASRA_SID=old-user", AsraSessionCookies.migrationCandidate(
            "other=invalid", "ASRA_SID=old-user"))
        assertEquals("", AsraSessionCookies.migrationCandidate("", ""))
    }

    @Test fun userAgentHasExactlyOneCurrentVersion() {
        val version = BuildConfig.VERSION_NAME
        assertEquals("Mozilla/5.0 AsraApp/$version", AsraUserAgent.current(
            "Mozilla/5.0 AsraApp/1.5.7", version))
        assertEquals("AsraApp/$version", AsraUserAgent.current("", version))
        val current = AsraUserAgent.current("Mozilla/5.0 AsraApp/1.5.7", version)
        assertEquals(current, AsraUserAgent.current(current, version))
    }
}
