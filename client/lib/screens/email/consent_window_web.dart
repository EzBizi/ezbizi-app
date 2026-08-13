import 'package:web/web.dart' as web;

import 'consent_window.dart';

/// The browser's own new window, waiting to be pointed at the sign-in page.
///
/// ── Opened blank, inside the click ───────────────────────────────
///
/// `window.open` is allowed while the browser is handling a gesture and blocked
/// afterwards. The sign-in address comes from our server, which is an `await`,
/// and a window opened after that await is a window the pop-up blocker eats. So
/// this opens an empty one immediately and [ConsentWindow.goTo] points it at the
/// address when it arrives.
///
/// ── `noopener` is deliberately NOT set, and that is a trade ──────
///
/// With it, `window.open` returns null: there is no handle, so nothing can ask
/// whether the window is still open and nothing can close it when the server
/// says the mailbox is connected. Without it, the page in that window can read
/// `window.opener` and, cross-origin, do exactly one thing with it — navigate
/// this tab somewhere else (reverse tabnabbing).
///
/// The address opened here is not somebody's link. It is minted by our own
/// server in answer to `oauth/start` and points at the mail service's own
/// authorization endpoint, so the party in a position to abuse the opener is the
/// mail service — which is already the party being handed the sign-in.
ConsentWindow? open() {
  // Named rather than `_blank`, so a flow started twice reuses one browsing
  // context instead of leaving two consent screens open. The button is disabled
  // while a flow is in flight as well; this is the second lock, for a flow that
  // was abandoned and then started again.
  final opened = web.window.open('', 'ezbizi_mail_consent');

  return opened == null ? null : _BrowserConsentWindow(opened);
}

class _BrowserConsentWindow implements ConsentWindow {
  _BrowserConsentWindow(this._window);

  final web.Window _window;

  @override
  void goTo(String url) => _window.location.href = url;

  @override
  bool get closed {
    try {
      return _window.closed;
    } catch (_) {
      // `closed` is cross-origin readable everywhere that matters, and an
      // embedded webview refusing it should not take the connect panel down.
      // Unknown reads as still open, so the wait ends on the server's answer
      // rather than on a guess about a window nobody here can see.
      return false;
    }
  }

  @override
  void close() {
    try {
      _window.close();
    } catch (_) {
      // A window we opened is one we are allowed to close. This is tidying up
      // after a connection that has already succeeded, and it is not worth an
      // exception on the success path.
    }
  }
}
