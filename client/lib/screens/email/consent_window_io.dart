import 'package:url_launcher/url_launcher.dart';

import 'consent_window.dart';

/// Off the web the sign-in happens in the person's own browser.
///
/// This file used to answer null, and it said why: "signing in to a mail service
/// from a desktop app is a real thing to build — the system browser, and a
/// loopback listener for the redirect back — and it is not built."
///
/// Half of that turned out to be wrong, which is why it is built now. **The
/// loopback listener is not needed.** The client never receives the callback on
/// any platform, web included: the consent window becomes the provider's page
/// and then our own server's callback route, and what the client does is poll
/// `oauth/status` until the SERVER says the account is connected. The server is
/// the only party that knows, and it already knows it here. So the whole desktop
/// job is opening the address in the system browser and letting the poll that
/// already exists do the rest.
///
/// Until this existed, every caller turned the null into one sentence about the
/// browser blocking pop-ups — advice with nothing to act on, in an application
/// with no browser — and it blocked connecting anything at all from the desktop
/// build: Google Ads, Business Profile, email, and every social network.
///
/// The stub reason for this file still holds. `package:web` imports
/// `dart:js_interop`, which is unavailable on the VM, so the web implementation
/// cannot simply be imported everywhere or `flutter test` would not compile —
/// the same reason core/token_store_io.dart and core/files_io.dart exist.
/// Mobile is not claimed here. url_launcher covers Android and iOS too, but
/// coming back to the app after consent behaves differently on a phone and that
/// has not been tested.
typedef LaunchConsentUrl = Future<bool> Function(Uri url);

/// The seam a test replaces so nothing opens a real browser.
LaunchConsentUrl launchConsentUrl =
    (uri) => launchUrl(uri, mode: LaunchMode.externalApplication);

ConsentWindow? open() => SystemBrowserConsent();

/// The person's own browser, standing in for a window this app cannot own.
///
/// It answers the [ConsentWindow] contract honestly rather than conveniently:
/// two of the three members are things a desktop app genuinely cannot do, and
/// pretending otherwise would be worse than the null this replaces.
class SystemBrowserConsent implements ConsentWindow {
  @override
  void goTo(String url) {
    // Fire and forget, because the interface is void and the answer does not
    // change what happens next. A launch that fails leaves the poll running
    // until it times out and says the sign-in did not finish, which is true.
    launchConsentUrl(Uri.parse(url));
  }

  /// Always false, and that is a limitation rather than an answer.
  ///
  /// There is no way to see whether somebody shut a tab in their own browser.
  /// The mail panel uses this to offer an early "the window was closed before
  /// the sign-in finished" notice; on desktop that notice cannot appear, and
  /// the existing five-minute timeout is what ends the wait instead.
  ///
  /// False rather than true deliberately: answering true would fire that notice
  /// on the first poll and abandon a sign-in the person is still in the middle
  /// of.
  @override
  bool get closed => false;

  /// Nothing to close. The tab belongs to the person, not to this application.
  @override
  void close() {}
}
