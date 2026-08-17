import 'package:flutter/foundation.dart';
import 'api_client.dart';

/// Whether the server has told this app it is too old to talk to it.
///
/// ── Why this is not a field on Session ───────────────────────────
///
/// Being out of date has nothing to do with being signed in. It happens to
/// somebody halfway through a job just as readily as to somebody looking at the
/// sign-in form, and it happens on the control plane as well as in the customer
/// app — two faces, two [ApiClient]s, two sessions, one fact about the binary
/// they are all running inside. Hanging it off [Session] would leave the sign-in
/// screen unable to explain itself and the staff face unable to see it at all.
///
/// So it sits beside them and listens to every client in the process, however
/// many there turn out to be. See main.dart, where it is installed once and then
/// consulted before anything else is drawn.
///
/// ── Why the app stops rather than carries on ─────────────────────
///
/// The alternative is letting each screen report its own 426 in its own words,
/// which is exactly the shape of the bug that produced
/// [ApiClient.onUnauthorized]: the shell stays up, the sidebar still looks live,
/// every tab fails differently, and nothing anywhere says the one true thing.
/// A server that has refused one request for this reason will refuse the next
/// one for the same reason, so there is nothing left to do in the app and every
/// reason to say so once, plainly, in a whole screen.
class VersionGate extends ChangeNotifier {
  UpgradeRequired? _notice;

  /// What the server said, or null while nothing has been refused.
  UpgradeRequired? get notice => _notice;

  bool get blocked => _notice != null;

  /// Start listening. Called once, from main, for the whole application.
  ///
  /// ── Why this is not "watch this client" any more ─────────────────
  ///
  /// It used to be `watch(ApiClient)`, called for each client the app owned,
  /// and the comment here said that forgetting one would be a silent failure
  /// "which nobody would think to test for". That is exactly what happened. The
  /// Development section builds its own [ApiClient] to hold the staff token,
  /// and staff enrolment builds another for a fifteen-minute one; neither was
  /// ever passed to this, so a 426 on either was dropped without a sound. The
  /// Development tab reported it as an expired control-plane session — a
  /// confident wrong answer, with the one screen that would have explained it
  /// never shown.
  ///
  /// Registering per instance cannot be made safe by remembering harder,
  /// because the thing being remembered is invisible when forgotten. So this
  /// registers ONCE, on [ApiClient] itself, and covers every client in the
  /// process including ones built inside a screen that has not been written yet.
  ///
  /// That is sound because the fact is process-wide: this is a statement about
  /// the binary, not about a session. See [ApiClient.onUpgradeRequiredAnywhere]
  /// for why the neighbouring 401 handling is deliberately the opposite.
  void install() => ApiClient.onUpgradeRequiredAnywhere = raise;

  /// Record a refusal. The FIRST one wins.
  ///
  /// A screen typically has several requests in flight — a list, a count, a
  /// permission set — and when the server starts refusing this build, every one
  /// of them comes back 426 within milliseconds of the others. Without this
  /// latch each would notify, and the app would rebuild three or four times at
  /// the moment it has the least to gain from doing so. The same reasoning, and
  /// the same shape, as Session.sessionExpired.
  ///
  /// First rather than last also keeps the sentence stable: swapping the message
  /// underneath somebody who has started reading it is a small cruelty for no
  /// benefit, since every one of those answers says the same thing anyway.
  void raise(UpgradeRequired notice) {
    if (_notice != null) return;

    _notice = notice;
    notifyListeners();
  }

  /// Let the app try again.
  ///
  /// ── Why there is a way out of a screen that means "stop" ─────────
  ///
  /// Because the gate is a conclusion drawn from one answer, and the answer can
  /// stop being true without this app learning of it: somebody installs the
  /// newer build over the top and reopens the same window; a staff member
  /// realises the minimum was raised too far and lowers it again; the 426 came
  /// from a proxy or a half-finished deploy rather than from the gate at all.
  /// In every one of those the app is fine and the screen is wrong, and an
  /// update screen with no exit turns a temporary mistake into a reinstall.
  ///
  /// It costs nothing to be wrong about: if the client really is still too old,
  /// the very next request comes back 426 and the screen returns. What it buys
  /// is that nobody is ever permanently trapped by a state this client inferred.
  void clear() {
    if (_notice == null) return;

    _notice = null;
    notifyListeners();
  }
}
