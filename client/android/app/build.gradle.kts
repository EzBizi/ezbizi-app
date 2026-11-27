import java.util.Properties

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")

    // Reads app/google-services.json and generates the resources the Firebase
    // SDKs read at runtime — the project id, the API key, the sender id FCM
    // needs to address this app.
    //
    // It is here for ONE reason: an incoming Twilio call cannot ring an Android
    // handset without a push. twilio_voice already pulls firebase-messaging; what
    // was missing was a project for it to register against, so the build passed,
    // outgoing calls worked, and nothing ever rang.
    //
    // This plugin and app/google-services.json land together or neither does.
    // Applied without the file it fails at CONFIGURATION time, so a half-done
    // change breaks every Android build rather than degrading quietly.
    id("com.google.gms.google-services")
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

// This file deliberately does NOT read client/VERSION.
//
// It used to, and that was the bug: build-app.sh reads it for the artefact
// filename and Gradle read it again for versionCode, and the two reads are not
// the same instant on a tree with twenty sessions in it. One build shipped
// declaring versionCode 9999 while its filename said 0.0.0184, because the
// deploy desk held VERSION at 0.0.9999 for about fifteen seconds as a sabotage
// control and the two reads landed either side of it.
//
// The filename being wrong is cosmetic. versionCode is not: Play requires it to
// rise forever, so ONE upload at 9999 permanently burns every number beneath it
// and the listing could never ship 185. Android refuses the same thing locally,
// INSTALL_FAILED_VERSION_DOWNGRADE, which is how this was demonstrated.
//
// build-app.sh now reads the file ONCE and passes both values in as
// --build-name and --build-number, which arrive here as flutter.versionName
// and flutter.versionCode. Do not put the read back.
//
// A build made WITHOUT build-app.sh gets pubspec 1.0.0+1 instead. Deliberate:
// useless to upload and harmless, which is the right way round for an artefact
// nobody stamped.

/** EZBIZI_APP=ezchat builds EzChat, the chat companion (see defaultConfig). */
val ezChat = System.getenv("EZBIZI_APP") == "ezchat"

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
        applicationId = if (ezChat) "com.ezbizi.ezchat" else "com.ezbizi.app"

        // EzChat, the chat companion app (Jose 2026-11-25), is this same
        // project built with EZBIZI_APP=ezchat and -t lib/main_ezchat.dart.
        // An environment switch rather than product flavors: flavors would
        // rename every artefact path the release scripts read and make
        // --flavor mandatory on every Android build anybody runs.
        manifestPlaceholders["appLabel"] = if (ezChat) "EzChat" else "EzBizi"
        manifestPlaceholders["appIcon"] =
            if (ezChat && file("src/main/res/mipmap-hdpi/ic_launcher_ezchat.png").exists())
                "@mipmap/ic_launcher_ezchat"
            else
                "@mipmap/ic_launcher"

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
        versionCode = flutter.versionCode
        versionName = flutter.versionName
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

    // Already in this app, and already in the APK - twilio_voice pulls
    // firebase-messaging:24.1.0 and it is measurably in the shipped artefact.
    // It is declared `implementation` there, though, so it is NOT on this
    // module’s compile classpath: measured with a control, twilio_voice and
    // flutter_local_notifications both present in releaseCompileClasspath and
    // firebase-messaging absent from it while present in releaseRuntimeClasspath.
    //
    // So this line buys COMPILE visibility for MainActivity.kt, not a new
    // dependency. Pinned to the exact version twilio_voice resolves so there is
    // never a second one to reconcile; no download size is added.
    implementation("com.google.firebase:firebase-messaging:24.1.0")
}

flutter {
    source = "../.."
}
