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

        // Required by flutter_local_notifications, which schedules against
        // java.time on API levels that predate it. Without this the Android
        // build does not merely warn — it FAILS, at the AAR metadata check,
        // with a message that names the dependency but not the two lines that
        // fix it. Web, Windows and iOS are all unaffected, so the first sign of
        // it is a release build that stops working on one platform only.
        isCoreLibraryDesugaringEnabled = true
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_17.toString()
    }

    defaultConfig {
        // Locked by Google Play at first publish and unchangeable afterwards —
        // a rename means a new listing and no install base. It must also match
        // the package name on the Play Console exactly.
        applicationId = "com.ezbizi.app"

        // 26 (Android 8.0), not flutter.minSdkVersion, which is 24.
        //
        // twilio_voice requires 26, and the manifest merger refuses outright:
        // "minSdkVersion 24 cannot be smaller than version 26 declared in
        // library [:twilio_voice]". That failed the v0.0.0134 Android build
        // the first time calling shipped on mobile.
        //
        // The merger offers tools:overrideLibrary as an escape and it is the
        // wrong one — it forces the build through and warns of runtime
        // failures, which here means the app installs on Android 7 and dies
        // the moment somebody takes a call. Raising the floor drops Android 7
        // and below; that is the honest cost of shipping calling at all, and
        // it is visible here rather than discovered on a customer's phone.
        minSdk = 26
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

dependencies {
    // The other half of isCoreLibraryDesugaringEnabled above: the flag turns
    // desugaring on, this supplies the backported library it desugars against.
    // Neither does anything without the other.
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
}

flutter {
    source = "../.."
}
