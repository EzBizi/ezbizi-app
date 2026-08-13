import 'consent_window.dart';

/// Off the web there is no window to open, and this says so by answering null.
///
/// Two reasons it exists rather than the web file being imported everywhere.
/// `package:web` imports `dart:js_interop`, which is unavailable on the VM, so
/// without this stub `flutter test` would not compile at all — the same reason
/// core/token_store_io.dart and core/files_io.dart exist.
///
/// And it is honest about the desktop and mobile builds that are coming (R7).
/// Signing in to a mail service from a desktop app is a real thing to build —
/// the system browser, and a loopback listener for the redirect back — and it is
/// not built. Answering null puts a sentence in front of somebody saying so,
/// rather than a button that appears to work and does nothing.
ConsentWindow? open() => null;
