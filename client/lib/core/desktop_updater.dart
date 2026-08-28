import 'dart:async';

import 'package:flutter/foundation.dart';

import 'desktop_update.dart';

/// Keeps the installed desktop app current on its own.
///
/// [DesktopUpdate] knows how to ask what the newest build is and how to run an
/// installer. This decides WHEN — on start, then once an hour, for as long as
/// the app is open — and holds the one piece of state the UI reads.
///
/// ── One of these, at the root ───────────────────────────────────
///
/// A singleton rather than something owned by a screen, because the check has
/// to keep running whether anybody is signed in or not. It used to live in
/// HomeShell, which meant it ran once, only after sign-in, and stopped
/// mattering the moment somebody left the app sitting on the login screen —
/// which is exactly the machine that goes a month without an update.
///
/// ── Why it does not just install the moment it finds one ────────
///
/// It very nearly does. But the installer's whole job is to CLOSE this app and
/// replace it, and "always current" is not worth a half-typed job note. So a
/// found update waits [grace] before it goes ahead, and [postpone] pushes it to
/// the next hourly sweep. Nobody has to do anything for the update to happen —
/// leaving the dialog alone installs it — and nobody loses work to it either.
///
/// On the sign-in screen there is nothing to lose, so it goes immediately.
class DesktopUpdater extends ChangeNotifier {
  DesktopUpdater._();

  static final DesktopUpdater instance = DesktopUpdater._();

  /// How often to look. An hour is often enough that a build published in the
  /// morning is installed by lunch, and rare enough that it is never the reason
  /// the site sees traffic.
  static const Duration interval = Duration(hours: 1);

  /// How long a found update waits before installing itself.
  static const Duration grace = Duration(seconds: 60);

  Timer? _sweeps;
  Timer? _countdown;
  bool _running = false;

  /// The newest build, once a sweep has found one. Null means up to date, or
  /// not a platform that can install anything.
  AvailableUpdate? available;

  /// Set while the installer is downloading, and to the reason if it failed.
  bool busy = false;
  String? error;

  /// True while a manual check is in flight, so the button can say so.
  bool checking = false;

  /// Seconds left before [available] installs itself. Null when nothing is
  /// pending — either because none was found or because it was postponed.
  int? secondsLeft;

  /// Whether anybody is signed in. An update found while the sign-in screen is
  /// showing has nothing to interrupt, so it does not wait.
  bool signedIn = false;

  bool get supported => DesktopUpdate.supported;

  /// Begin. Safe to call more than once; only the first call does anything.
  void start() {
    if (_running || !DesktopUpdate.supported) return;
    _running = true;

    // Not awaited. An update check is never a reason to delay drawing the app,
    // and this one returns null on the web, on Android, and on any failure.
    unawaited(_sweep());
    _sweeps = Timer.periodic(interval, (_) => unawaited(_sweep()));
  }

  /// What the update button does: look now, and say what happened.
  Future<void> checkNow() async {
    if (checking || busy) return;

    checking = true;
    error = null;
    notifyListeners();

    await _sweep(force: true);

    checking = false;
    notifyListeners();
  }

  /// Not this hour. The next sweep will find it again and start over.
  void postpone() {
    _countdown?.cancel();
    _countdown = null;
    secondsLeft = null;
    notifyListeners();
  }

  Future<void> _sweep({bool force = false}) async {
    if (busy) return;
    // A countdown already running is an update already found; looking again
    // would only restart the clock somebody is watching.
    if (!force && secondsLeft != null) return;

    final found = await DesktopUpdate.check();

    if (found == null) {
      available = null;
      notifyListeners();
      return;
    }

    available = found;
    notifyListeners();

    if (!signedIn) {
      await install();
      return;
    }

    _beginCountdown();
  }

  void _beginCountdown() {
    _countdown?.cancel();
    secondsLeft = grace.inSeconds;
    notifyListeners();

    _countdown = Timer.periodic(const Duration(seconds: 1), (t) {
      final left = (secondsLeft ?? 0) - 1;

      if (left > 0) {
        secondsLeft = left;
        notifyListeners();
        return;
      }

      t.cancel();
      _countdown = null;
      secondsLeft = null;
      unawaited(install());
    });
  }

  /// Fetch it and hand it to the installer.
  ///
  /// Returns having changed nothing visible on success: the installer closes
  /// this process, so there is nobody left to draw for. Only failure comes
  /// back here with somebody still looking at the screen.
  Future<void> install() async {
    final update = available;

    if (update == null || busy) return;

    _countdown?.cancel();
    _countdown = null;
    secondsLeft = null;
    busy = true;
    error = null;
    notifyListeners();

    final failure = await DesktopUpdate.install(update);

    busy = false;
    error = failure;
    notifyListeners();
  }

  /// Stop sweeping.
  ///
  /// Called when the root widget goes away, which in a running app means the
  /// window is closing. It matters in tests too: a periodic timer that outlives
  /// the widget tree fails every widget test in the suite with "a Timer is
  /// still pending", and a service that cannot be switched off is a service
  /// that cannot be tested around.
  void stop() {
    _sweeps?.cancel();
    _sweeps = null;
    _countdown?.cancel();
    _countdown = null;
    secondsLeft = null;
    _running = false;
  }

  @override
  void dispose() {
    stop();
    super.dispose();
  }
}
