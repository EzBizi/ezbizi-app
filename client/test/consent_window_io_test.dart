import 'package:flutter_test/flutter_test.dart';

import 'package:ezbizi_client/screens/email/consent_window.dart';
import 'package:ezbizi_client/screens/email/consent_window_io.dart';

/// Connecting an account from the desktop app.
///
/// Off the web this used to answer null, and every caller turned that into one
/// sentence: "EzBizi could not open the sign-in window. On the web that is
/// usually the browser blocking new windows — allow them and press the button
/// again." On a desktop build there is no browser to allow anything in, so the
/// only instruction on screen was one nobody could follow, for every provider
/// the app connects: Google Ads, Business Profile, email and every social
/// network.
///
/// The file's own note said desktop needed "the system browser, and a loopback
/// listener for the redirect back". The listener is not needed — the client
/// never receives the callback. It polls the SERVER at oauth/status, which is
/// the only party that knows. So the system browser is the whole job.
void main() {
  setUp(() => launchConsentUrl = (uri) async => true);

  test('off the web there is a window to open, so the button is not dead', () {
    expect(
      openConsentWindow(),
      isNotNull,
      reason: 'Returning null here is what put popup-blocker advice in front '
          'of a desktop user who has no browser to allow anything in.',
    );
  });

  test('going to the sign-in address hands it to the system browser', () {
    final launched = <Uri>[];

    launchConsentUrl = (uri) async {
      launched.add(uri);

      return true;
    };

    openConsentWindow()!.goTo('https://accounts.google.com/o/oauth2/v2/auth?x=1');

    expect(launched.single.host, 'accounts.google.com');
    expect(launched.single.queryParameters['x'], '1');
  });

  test('a system browser tab cannot be watched, and says so rather than lying', () {
    final window = openConsentWindow()!;

    // False, not true. `closed` drives an early "you closed the window before
    // finishing" notice in the mail panel; answering true would fire it the
    // moment polling starts and abandon a sign-in that is still going. Waiting
    // out the existing timeout is worse than the web and much better than that.
    expect(window.closed, isFalse);

    // A no-op rather than an error: the callers close the window once the
    // server says connected, and on desktop that tab is the person's own.
    expect(window.close, returnsNormally);
  });
}
