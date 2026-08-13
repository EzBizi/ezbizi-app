import 'consent_window_io.dart'
    if (dart.library.js_interop) 'consent_window_web.dart' as impl;

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
