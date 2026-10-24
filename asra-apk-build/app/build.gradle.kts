import java.util.Properties

// İmza bilgileri depodaki keystore ile uyumludur; böylece yeni APK mevcut
// kurulumun ÜZERİNE kurulabilir (aynı sertifika: 3527c221...). Ortam
// değişkeniyle geçersiz kılınabilir.
val keystoreFile = rootProject.file("asra-release.jks")
val signingProps = Properties().apply {
    val override = rootProject.file("signing.properties")
    if (override.exists()) override.inputStream().use { load(it) }
}
// Yalnızca ortam değişkeni veya git dışı signing.properties; repoda parola yok.
fun signProp(key: String, envName: String): String =
    signingProps.getProperty(key) ?: System.getenv(envName).orEmpty()

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "app.asra.client"
    compileSdk = 34

    defaultConfig {
        applicationId = "app.asra.client"
        minSdk = 24
        targetSdk = 34
        versionCode = 30
        versionName = "1.7.3"
    }

    signingConfigs {
        create("release") {
            // Önceki sürümle aynı imza düzeni (v2 + v3) korunur; böylece
            // eski sürümün (1.5.7 ve sonrası) üzerine güncelleme sorunsuz kurulur.
            enableV1Signing = false
            enableV2Signing = true
            enableV3Signing = true
            enableV4Signing = false
            storeFile = keystoreFile
            storePassword = signProp("storePassword", "ASRA_SIGN_STORE_PASSWORD")
            keyAlias = signingProps.getProperty("keyAlias") ?: System.getenv("ASRA_SIGN_KEY_ALIAS") ?: "asra"
            keyPassword = signProp("keyPassword", "ASRA_SIGN_KEY_PASSWORD")
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            isShrinkResources = false
            signingConfig = if (keystoreFile.exists() &&
                signProp("storePassword", "ASRA_SIGN_STORE_PASSWORD").isNotEmpty() &&
                signProp("keyPassword", "ASRA_SIGN_KEY_PASSWORD").isNotEmpty()
            ) signingConfigs.getByName("release") else null
        }
        debug {
            isMinifyEnabled = false
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    buildFeatures {
        buildConfig = true // UA + önbellek sürümü tek kaynak: defaultConfig.versionName
        viewBinding = false
        compose = false
    }

    packaging {
        resources {
            excludes += setOf(
                "META-INF/LICENSE",
                "META-INF/NOTICE",
                "META-INF/DEPENDENCIES",
                "META-INF/*.kotlin_module"
            )
        }
    }
}

dependencies {
    // Bildirim/push kütüphanesi YOK. JUnit yalnızca JVM testlerine girer,
    // dağıtım APK'sına veya kullanıcı izinlerine eklenmez.
    testImplementation("junit:junit:4.13.2")
}
