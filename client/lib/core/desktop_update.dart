import 'dart:convert';

import 'package:http/http.dart' as http;

import 'build_info.dart';
import 'client_platform.dart';
import 'desktop_update_io.dart'
    if (dart.library.js_interop) 'desktop_update_web.dart'
    as impl;

/// A newer installed build than the one running, and where to get it.
class AvailableUpdate {
  const AvailableUpdate({
    required this.version,
    required this.url,
    this.notes = '',
  });

  final String version;
  final String url;
  final String notes;
}

/// Keeping the installed desktop app current.
///
/// ── Why this exists at all, when there is already a version gate ─
///
/// The version gate is a different job wearing a similar name. It is the server
/// REFUSING a client that is too old: a 426, and the whole app replaced by a
/// screen telling you to go and download something. That is a wall, and it
/// arrives at the worst possible moment — mid-job, because that is when the
/// request happened to be made.
///
/// This is the opposite end. It offers a newer build while the current one
/// still works, so the wall is never reached. The two are complementary and
/// both should exist: the gate is the floor nobody may be below, this is the
/// nudge that keeps people off it. The web needs neither, because a browser
/// re-fetches the app every load.
///
/// ── Why a static file and not an API endpoint ────────────────────
///
/// The manifest is a small JSON on the marketing site, written by
/// `scripts/release.sh` in the same step that uploads the installer. It could
/// have been a Laravel route reading `platform_minimum_client_versions`, and
/// that would have been more code for less: an endpoint needs auth decisions,
/// the table needs a row maintained by hand, and the answer would still be a
/// version and a URL. A file that the release script writes cannot drift from
/// the installer the release script just built, because the same run produces
/// both.
///
/// ── Why there is no checksum ─────────────────────────────────────
///
/// Deliberate, not forgotten. The manifest and the installer are served from
/// the same host over TLS, so anybody able to swap the binary can swap the
/// hash beside it — the check would be theatre. TLS is the integrity guarantee
/// here. If the installer ever moves to a CDN or a mirror while the manifest
/// stays on our own host, a checksum stops being theatre and should be added;
/// release.sh already knows how to compute one.
class DesktopUpdate {
  DesktopUpdate._();

  /// Where release.sh publishes. One file per platform.
  static const String base = 'https://www.ezbizi.com/download';

  /// Only installed builds can be updated this way.
  ///
  /// The web reloads and Android updates through Play, and offering either of
  /// them a downloadable installer would be offering something that cannot
  /// work. `ClientPlatform.name` is the same value the API sends as its platform
  /// header, so this asks the question the same way the rest of the app does.
  static bool get supported {
    const installed = {'windows', 'macos', 'linux'};

    return installed.contains(ClientPlatform.name) && impl.canInstall;
  }

  /// The newest published build, or null when this one is already it.
  ///
  /// Never throws. An update check that breaks the app it is trying to keep
  /// current is worse than one that quietly does nothing — offline, behind a
  /// proxy, or with the site down, the honest outcome is "no update today".
  static Future<AvailableUpdate?> check({http.Client? client}) async {
    if (!supported) return null;

    final own = BuildInfo.version;

    // An unstamped build was made by hand and has no release to compare
    // against; telling somebody in that state to reinstall is noise.
    if (own == 'dev') return null;

    try {
      final http.Client c = client ?? http.Client();
      final res = await c
          .get(Uri.parse('$base/${ClientPlatform.name}.json'))
          .timeout(const Duration(seconds: 8));

      if (res.statusCode != 200) return null;

      final body = jsonDecode(res.body);

      if (body is! Map) return null;

      final version = (body['version'] ?? '').toString();
      final url = (body['url'] ?? '').toString();

      if (version.isEmpty || url.isEmpty) return null;
      if (!isNewer(version, own)) return null;

      return AvailableUpdate(
        version: version,
        url: url,
        notes: (body['notes'] ?? '').toString(),
      );
    } catch (_) {
      return null;
    }
  }

  /// Whether [candidate] is a later build than [own].
  ///
  /// Segments compared LEFT TO RIGHT as numbers, which is the same rule
  /// PlatformMinimumClientVersion applies on the server and for the same
  /// reason: "0.0.0100" is newer than "0.0.0099" but sorts earlier as text.
  /// Comparing these as strings is the classic way to conclude that version 9
  /// is newer than version 10.
  static bool isNewer(String candidate, String own) {
    final a = candidate.split('.');
    final b = own.split('.');

    for (var i = 0; i < (a.length > b.length ? a.length : b.length); i++) {
      final x = i < a.length ? int.tryParse(a[i]) : 0;
      final y = i < b.length ? int.tryParse(b[i]) : 0;

      // A segment that is not a number makes the whole comparison meaningless,
      // and guessing at it would be how somebody gets offered a downgrade.
      if (x == null || y == null) return false;
      if (x != y) return x > y;
    }

    return false;
  }

  /// Download it and hand it to the installer.
  ///
  /// Returns null on success — at which point this process is being closed by
  /// the installer and there is nobody left to tell. A string is the reason it
  /// did not happen, for the banner to show.
  static Future<String?> install(AvailableUpdate update) =>
      impl.download(update.url, update.version);
}
