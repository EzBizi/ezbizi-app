import 'package:flutter/foundation.dart' show TargetPlatform, defaultTargetPlatform, kIsWeb;

/// Whether this build can capture the screen at all — Windows desktop only,
/// via the native `com.ezbizi.app/monitoring_screenshot` channel registered
/// in windows/runner/flutter_window.cpp (Task 5).
///
/// Shared between [ScreenshotService], which is the only thing that actually
/// invokes the channel, and [MonitoringHeartbeat], which declares the
/// `'screenshot'` capability from this same check — so the two can never
/// disagree about whether this device is capture-capable. Neither side
/// probes the channel itself: `kIsWeb`/`defaultTargetPlatform` is the same
/// cheap, synchronous native-availability check the rest of this codebase
/// uses (see client_platform.dart), and it is what the Windows runner build
/// actually corresponds to — every Windows build registers the channel,
/// every other build has no runner code for it at all.
///
/// `flutter test` runs with `defaultTargetPlatform` forced to
/// [TargetPlatform.android] by the test binding regardless of the host OS,
/// so this is false in the test suite even when the suite itself runs on a
/// Windows machine — tests that want the Windows behavior inject their own
/// fake rather than relying on the host's real platform.
bool get screenshotCaptureSupported =>
    !kIsWeb && defaultTargetPlatform == TargetPlatform.windows;
