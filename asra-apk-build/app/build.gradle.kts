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
        versionCode = 24
        versionName = "1.5.7"
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            isShrinkResources = false
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
            excludes += setOf("META-INF/LICENSE", "META-INF/NOTICE")
        }
    }
}

dependencies {
    // Android SDK + Kotlin stdlib only. No third-party analytics or trackers.
}
