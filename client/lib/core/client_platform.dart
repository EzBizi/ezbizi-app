import 'client_platform_io.dart'
    if (dart.library.js_interop) 'client_platform_web.dart' as impl;

/// Which build of the client this actually is, and what it can do about being
/// out of date.
///
/// ── Why this needs a conditional import at all ───────────────────
///
/// The obvious answer is `dart:io`'s `Platform.operatingSystem`. It cannot be
/// used directly: `dart:io` does not exist on the web, so a single import of it
/// anywhere in the tree takes the web build down — and the web build is the one
/// that ships today. `kIsWeb` would answer "web or not" but nothing more, and
/// the whole point here is telling Windows from macOS from Android, because the
/// server keeps a separate minimum version for each.
///
/// So it follows the shape this codebase already uses twice, for exactly this
/// reason: `files.dart` / `files_web.dart` and `token_store.dart` /
/// `token_store_web.dart`. One facade, two implementations, and the compiler
/// picks. See token_store_io.dart for the first time it was needed.
///
/// ── Why the identifiers are these words ──────────────────────────
///
/// `web`, `windows`, `macos`, `linux`, `android`, `ios` — the six the server
/// stores minimums against. They are Dart's own `Platform.operatingSystem`
/// strings, unchanged, so there is no translation table on this side that could
/// drift from the one on the other side. A build on a platform nobody has
/// thought about yet (Fuchsia, or whatever comes next) sends its real name
/// rather than a guess; the server has no minimum for it and therefore lets it
/// through, which is the correct outcome and the one the gate is designed for.
class ClientPlatform {
  /// Read once. It cannot change while the process is running, and it is put in
  /// the headers of every single request — so computing it per request would be
  /// a platform-channel-free but pointless repetition.
  static final String name = impl.platformName();

  /// Whether this is a browser, which decides what somebody is told to DO about
  /// an out-of-date client.
  ///
  /// A web client is served fresh on every load, so it is only ever stale
  /// because a tab has been sitting open — the remedy is a reload and takes
  /// seconds. An installed app has to be replaced on the machine. Telling a
  /// browser user to "download the newest version" sends them looking for an
  /// installer that does not exist, so this distinction is not cosmetic.
  static bool get isWeb => name == 'web';

  /// Reload the app from the server. Web only.
  ///
  /// Returns whether it could — false everywhere except a browser, so a caller
  /// can say something honest rather than offering a button that does nothing.
  /// There is no equivalent off the web: an installed app cannot re-fetch
  /// itself, which is the entire reason the two remedies differ.
  static bool reload() => impl.reload();
}
