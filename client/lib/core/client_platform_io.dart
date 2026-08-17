import 'dart:io' show Platform;

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

/// Nothing to reload off the web: an installed app cannot re-fetch itself from
/// the server. Reporting false rather than pretending is what lets the update
/// screen offer a download instead of a button that would appear to do nothing.
bool reload() => false;
