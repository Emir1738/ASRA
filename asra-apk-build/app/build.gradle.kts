import java.util.Properties

// İmza bilgileri depodaki keystore ile uyumludur; böylece yeni APK mevcut
// kurulumun ÜZERİNE kurulabilir (aynı sertifika: 3527c221...). Ortam
// değişkeniyle geçersiz kılınabilir.
val keystoreFile = rootProject.file("asra-release.jks")
val signingProps = Properties().apply {
    val override = rootProject.file("signing.properties")
    if (override.exists()) override.inputStream().use { load(it) }
}
fun signProp(key: String, fallback: String): String =
    signingProps.getProperty(key) ?: fallback

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
        versionCode = 28
        versionName = "1.7.1"
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
            storePassword = signProp("storePassword", "asra-release-2026")
            keyAlias = signProp("keyAlias", "asra")
            keyPassword = signProp("keyPassword", "asra-release-2026")
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            isShrinkResources = false
            signingConfig = if (keystoreFile.exists()) signingConfigs.getByName("release") else null
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
        buildConfig = false
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
    // v1.7.0: Bildirim sistemi KALDIRILDI. Firebase/FCM bağımlılığı YOKTUR;
    // uygulama hiçbir üçüncü taraf push servisine bağlanmaz ve bildirim izni
    // istemez. Bu blok bilinçli olarak boştur.
}
