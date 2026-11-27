import 'dart:io' show File, Platform;

/// Platform identity everywhere that is not a browser. See client_platform.dart.
///
/// `Platform.operatingSystem` returns exactly the words the server keys its
/// minimum versions on — `windows`, `macos`, `linux`, `android`, `ios` — so this
/// hands them over unchanged rather than mapping them onto a second vocabulary
/// that would then have to be kept in step with the first.
///
/// The try/catch is not defensive habit. This value ends up in a header on every
/// request, and if reading it ever threw, every request in the app would fail —
/// turning "we could not work out which platform this is", a triviality, into a
/// client that cannot talk to the server at all. An empty string is dropped from
/// the headers by ApiClient, the server then has no platform to check a minimum
/// against, and the gate lets the request through. That is the same fail-open
/// conclusion the server reaches for a platform it does not recognise.
String platformName() {
  try {
    return Platform.operatingSystem;
  } catch (_) {
    return '';
  }
}

/// An asset's real path on disk. See client_platform.dart.
///
/// `data/flutter_assets` beside the executable is the Windows and Linux layout,
/// and the only one this needs: the single caller is the Windows toast icon.
/// macOS buries the same folder inside the .app bundle and Android and iOS do
/// not expose assets as files at all, so on those the lookup simply finds
/// nothing — which is why this ends in `existsSync` rather than in a switch on
/// the operating system. A layout that changes under us then degrades to null
/// instead of handing Windows a path to a file that is not there, and a
/// registry entry pointing at nothing is exactly the bug this is fixing.
String? installedAssetPath(String assetKey) {
  try {
    final separator = Platform.pathSeparator;
    final directory = File(Platform.resolvedExecutable).parent.path;
    final file = File(
      [
        directory,
        'data',
        'flutter_assets',
        ...assetKey.split('/'),
      ].join(separator),
    );

    return file.existsSync() ? file.path : null;
  } catch (_) {
    return null;
  }
}

/// Nothing to reload off the web: an installed app cannot re-fetch itself from
/// the server. Reporting false rather than pretending is what lets the update
/// screen offer a download instead of a button that would appear to do nothing.
bool reload() => false;

/// The phone's operating system and version, for the Field technician bar.
/// See client_platform.dart's [fieldOsVersion].
String? osVersion() {
  final name = Platform.isIOS
      ? 'iOS'
      : Platform.isAndroid
      ? 'Android'
      : null;
  return name == null ? null : '$name ${Platform.operatingSystemVersion}';
}
