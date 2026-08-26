import java.util.Properties

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// The upload key, if this machine has one.
//
// key.properties and the keystore it points at are gitignored and live beside
// the project, never in it — a keystore in history is burned, and Play then has
// to be asked for a key reset. Absent, the release build falls back to the debug
// key: `flutter run --release` keeps working, and Play rejects the artefact,
// which is the right way round. scripts/release.sh checks for this file before
// it builds anything it would ask you to upload.
val keyProperties = Properties().apply {
    val file = rootProject.file("key.properties")
    if (file.exists()) {
        file.inputStream().use { load(it) }
    }
}
val hasUploadKey = keyProperties.getProperty("storeFile") != null

// One number governs every platform. client/VERSION is the same file the web
// build and build-app.sh read, so a build's version string is the same wherever
// it is reported. Play needs versionCode to rise on every upload and never
// repeat — 0.0.0097 becomes 97, which does exactly that for as long as VERSION
// is bumped, and it is bumped for every release anyway.
val ezbiziVersion = rootProject.file("../VERSION").takeIf { it.exists() }
    ?.readText()?.trim()
val ezbiziVersionCode = ezbiziVersion?.replace(".", "")?.trimStart('0')?.toIntOrNull()

android {
    namespace = "com.ezbizi.ezbizi_client"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_17.toString()
    }

    defaultConfig {
        // Locked by Google Play at first publish and unchangeable afterwards —
        // a rename means a new listing and no install base. It must also match
        // the package name on the Play Console exactly.
        applicationId = "com.ezbizi.app"
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = ezbiziVersionCode ?: flutter.versionCode
        versionName = ezbiziVersion ?: flutter.versionName
    }

    signingConfigs {
        if (hasUploadKey) {
            create("upload") {
                storeFile = file(keyProperties.getProperty("storeFile"))
                storePassword = keyProperties.getProperty("storePassword")
                keyAlias = keyProperties.getProperty("keyAlias")
                keyPassword = keyProperties.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            signingConfig = if (hasUploadKey) {
                signingConfigs.getByName("upload")
            } else {
                // No key on this machine. Still builds, so `flutter run
                // --release` works — but Play refuses a debug-signed artefact,
                // so this can never be mistaken for a shippable one.
                signingConfigs.getByName("debug")
            }
        }
    }
}

flutter {
    source = "../.."
}
