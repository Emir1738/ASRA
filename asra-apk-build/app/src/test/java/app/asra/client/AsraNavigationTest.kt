package app.asra.client

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class AsraNavigationTest {
    @Test fun exactHttpsOriginOnly() {
        listOf(
            "https://asra.42web.io", "https://asra.42web.io/",
            "https://ASRA.42WEB.IO/auth.php?action=session",
            "https://asra.42web.io:443/index.html#info"
        ).forEach { assertTrue("trusted: $it", AsraNavigation.isTrustedUrl(it)) }
    }

    @Test fun rejectLookalikesDowngradesAndInvalidUrls() {
        listOf(
            "http://asra.42web.io", "https://asra.42web.io:444/",
            "https://asra.42web.io.evil.example/", "https://evil.example/asra.42web.io",
            "https://evil@asra.42web.io/", "https://asra.42web.io@evil.example/",
            "//asra.42web.io/", "javascript:alert(1)", "file:///etc/passwd",
            "data:text/html,hello", "about:blank", "https://asra.42web.io/has space", ""
        ).forEach { assertFalse("blocked: $it", AsraNavigation.isTrustedUrl(it)) }
        assertFalse(AsraNavigation.isTrustedUrl(null))
    }

    @Test fun externalLinksAreLimitedToKnownSchemes() {
        listOf("https://eba.gov.tr/", "http://example.org/", "mailto:test@example.org",
            "tel:+902121234567", "sms:+905001234567", "geo:41.1,29.0"
        ).forEach { assertTrue("external: $it", AsraNavigation.canOpenExternally(it)) }
        listOf("javascript:alert(1)", "intent://example.org/#Intent;end", "file:///sdcard/secret",
            "content://example.org/data", "blob:https://asra.42web.io/id", "https://user@evil.example/",
            "https://", "data:text/html,hello"
        ).forEach { assertFalse("blocked external: $it", AsraNavigation.canOpenExternally(it)) }
    }

    @Test fun dialAndComposeUseTheActionsSupportedByInstalledApps() {
        assertEquals(AsraNavigation.ExternalAction.DIAL, AsraNavigation.externalAction("tel:+902121234567"))
        listOf("mailto:help@example.org", "sms:+905001234567", "smsto:+905001234567").forEach {
            assertEquals("compose: $it", AsraNavigation.ExternalAction.SEND_TO, AsraNavigation.externalAction(it))
        }
        listOf("https://eba.gov.tr/", "geo:41.1,29.0").forEach {
            assertEquals(AsraNavigation.ExternalAction.VIEW, AsraNavigation.externalAction(it))
        }
        assertNull(AsraNavigation.externalAction("javascript:alert(1)"))
    }
}
