import 'dart:async';

import '../../core/api_client.dart';
import 'consent_window_io.dart'
    if (dart.library.js_interop) 'consent_window_web.dart'
    as impl;

/// The mail service's own sign-in screen, opened in a window of its own.
///
/// ── This is not the pop-up the house rule forbids ────────────────
///
/// The rule is about EzBizi's own interface: nothing this application draws
/// covers the thing it is about, which is why widgets/side_panel.dart exists and
/// why there is not one AlertDialog in the app. A consent screen is not
/// something this application draws. It is the mail service's page, on the mail
/// service's origin, showing which account is signed in and what is being asked
/// for — and the whole security value of the flow is that the password is typed
/// into their window rather than into ours. There is no version of this that
/// renders that screen here, and there should not be.
///
/// ── Why a separate window and not this one ───────────────────────
///
/// Because of where somebody has to end up afterwards. Sending THIS window to
/// the provider tears the app down; the return trip lands on a fresh boot of the
/// client, which has no router — main.dart picks a destination from
/// `/me.modules` and falls back to the first one — so the person would come back
/// to the Dashboard with no way of telling whether the thing they just did
/// worked. A second window leaves the Settings tab exactly as it was, with the
/// panel still open on the address being connected, and the answer appears in
/// the panel that asked the question.
///
/// ── Opened EMPTY first, and that ordering is the whole trick ─────
///
/// A browser only allows a window to be opened while it is handling a click.
/// Asking the server for the sign-in address first — one `await` — ends that
/// permission, and the window is then blocked in every browser that ships a
/// pop-up blocker, which is all of them. So [OpenConsentWindow] takes no URL:
/// the window is opened blank inside the click, the address is fetched, and
/// [ConsentWindow.goTo] then points the window that already exists at it.
///
/// ── What this deliberately does NOT know ─────────────────────────
///
/// How the flow ends. That window becomes the provider's page and then, briefly,
/// our own server's callback route, and neither is scripted from here: the
/// callback may close the window, show a page, or sit there. So the only thing
/// read back is [closed] — one of the few properties a browser lets an opener
/// read across origins — and it is a hint rather than an answer. Whether the
/// mailbox was actually connected is asked of the SERVER, which is the only
/// party that knows.
abstract class ConsentWindow {
  /// Send the window to the address the server minted.
  ///
  /// Safe to call once and only once: the window is ours and blank until this
  /// runs, and after it the window belongs to the provider.
  void goTo(String url);

  /// Whether the person — or the callback — has closed it.
  ///
  /// A window that has closed may have closed because the sign-in finished or
  /// because somebody gave up, and this cannot tell those apart. The server is
  /// asked which.
  bool get closed;

  /// Close it from here, once the server says the mailbox is connected and the
  /// window is still sitting on a finished callback page.
  void close();
}

/// Open a blank window now, or return null when this build cannot.
///
/// Null is a real answer and not a fault: the browser blocked it, or this is the
/// desktop or mobile build, where there is no window to open (R7 — web first,
/// and the other two are coming). The caller says which in words rather than
/// leaving a button that appeared to do nothing.
typedef OpenConsentWindow = ConsentWindow? Function();

ConsentWindow? openConsentWindow() => impl.open();

/// The OAuth round trip behind a Connect button, shared by Marketing's connect
/// panel and `ConnectAccount`: open the window, ask [startPath] for the sign-in
/// address, send the window there, then poll the matching `/status` until the
/// callback lands.
///
/// Returns the poll for the caller's State to cancel in dispose(), or null when
/// none started. [onStart] and [onEnd] carry the caller's busy state; [onEnd]
/// gets the sentence to show, or null when there is none.
Future<Timer?> runConsentFlow({
  required ApiClient api,
  required OpenConsentWindow openConsent,
  required String startPath,
  required String label,
  required String refusedFallback,
  required bool Function() isMounted,
  required void Function() onStart,
  required void Function(String? error) onEnd,
  required Future<void> Function() onConnected,
  void Function(String url)? onUrl,
  // What to ask for, e.g. `{products: [...]}` (UI queue 183). Empty asks for
  // everything, as every caller before it did.
  Map<String, dynamic> body = const {},
}) async {
  // Opened BEFORE the first await, deliberately. A window opened after an async
  // gap is not attributable to the click any more, and every browser blocks it.
  final window = openConsent();

  if (window == null) {
    onEnd(
      'EzBizi could not open the $label sign-in window. On the web that is '
      'usually the browser blocking new windows for this site — allow them and '
      'press the button again.',
    );
    return null;
  }

  onStart();

  final response = await api.post(startPath, body);

  if (!isMounted()) {
    window.close();
    return null;
  }

  if (!response.ok) {
    window.close();
    // `reason` rather than `message`: a 501 here carries the driver's sentence
    // about what EzBizi has not set up yet, which is the only part somebody
    // stuck can act on.
    onEnd(response.reason);
    return null;
  }

  final data = response.data;
  final url = data is Map<String, dynamic> ? data['authorization_url'] : null;
  final flow = data is Map<String, dynamic> ? data['flow'] : null;

  if (url is! String || flow is! String) {
    window.close();
    onEnd('$label did not hand back a sign-in address.');
    return null;
  }

  window.goTo(url);
  onUrl?.call(url);

  // Five minutes at two-second intervals. Somebody choosing between several
  // Google accounts, or being asked for a second factor, is slower than a
  // timeout tuned to the happy path.
  var asks = 150;
  final statusPath = startPath.replaceFirst('/start', '/status');

  return Timer.periodic(const Duration(seconds: 2), (timer) async {
    asks -= 1;

    if (asks <= 0 || !isMounted()) {
      timer.cancel();
      if (isMounted()) onEnd(null);
      return;
    }

    final status = await api.get(statusPath, {'flow': flow});

    if (!isMounted()) {
      timer.cancel();
      return;
    }

    final data = status.data;
    final state = data is Map<String, dynamic> ? data['state'] : null;
    final message = data is Map<String, dynamic> ? data['message'] : null;

    if (state == 'connected') {
      timer.cancel();
      window.close();
      onEnd(null);
      await onConnected();
      return;
    }

    if (state == 'refused') {
      timer.cancel();
      window.close();
      // The server's own sentence about why, not "something went wrong".
      onEnd(
        message is String && message.isNotEmpty ? message : refusedFallback,
      );
    }
  });
}
