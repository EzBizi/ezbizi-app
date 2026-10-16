import 'dart:async';

import 'package:flutter/foundation.dart' show FlutterError, PlatformDispatcher;
import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';

import 'api_client.dart';

/// The app reporting its own problems: Dart errors, native crashes, freezes and
/// failed server calls (Jose, 2026-10-15: "We should have the app log crashes
/// and issues as well"). POST /client-issues, which works signed out too.
///
/// Reports are queued and sent in batches every [flushEvery], never inline: an
/// app in trouble must not make itself worse by reporting. Nothing here throws.
///
/// Privacy line: only what failed and where — messages are clipped, query
/// strings are cut, and the server scrubs again (ClientIssueScrubber).
class IssueReporter {
  IssueReporter._();

  static final IssueReporter instance = IssueReporter._();

  /// The module on screen, set by MonitoringHeartbeat.enter. Every report
  /// carries it, so "froze in Scheduling" needs no guessing.
  static String? module;

  static const Duration flushEvery = Duration(seconds: 15);
  static const int maxQueued = 50;

  ApiClient? _api;
  Timer? _flushTimer;
  final List<Map<String, Object?>> _queue = [];

  /// What [flush] would send — for tests.
  List<Map<String, Object?>> get queued => List.unmodifiable(_queue);

  /// Set by [install], which only `main()` calls — so widget tests that build
  /// the app never start its timers.
  static bool installed = false;

  /// Catch every Dart error the framework or the zone would otherwise only
  /// print, start the freeze watchdogs, and pick up a native crash marker from
  /// the last run. Call once, from `main()`.
  static void install() {
    installed = true;
    final previous = FlutterError.onError;
    FlutterError.onError = (details) {
      instance.record('dart_error', details.exceptionAsString(),
          detail: details.stack?.toString());
      previous?.call(details);
    };
    PlatformDispatcher.instance.onError = (error, stack) {
      instance.record('dart_error', error.toString(), detail: stack.toString());
      return true;
    };
    instance.startWatchdogs();
    instance.reportLastNativeCrash();
  }

  void attach(ApiClient api) {
    _api = api;
    _flushTimer ??= Timer.periodic(flushEvery, (_) => flush());
  }

  void record(String kind, String message, {String? detail}) {
    // ponytail: a fixed cap, oldest kept. A loop past 50 is one problem the
    // server already has; the server folds repeats anyway.
    if (_queue.length >= maxQueued) return;
    _queue.add({
      'kind': kind,
      'message': _clip(message, 4000),
      if (detail != null && detail.isNotEmpty) 'detail': _clip(detail, 40000),
      if (module != null) 'module': module,
    });
  }

  Future<void> flush() async {
    final api = _api;
    if (api == null || _queue.isEmpty) return;

    final batch = _queue.take(20).toList();
    _queue.removeRange(0, batch.length);

    final res = await api.post(reportPath, {'issues': batch});
    // Offline: keep them for the next try, still under the cap.
    if (res.statusCode == 0) {
      _queue.insertAll(0, batch.take(maxQueued - _queue.length));
    }
  }

  static const String reportPath = '/client-issues';

  // ── Failed server calls, sampled ───────────────────────────────

  final Map<String, DateTime> _lastApiReport = {};

  /// A 5xx or an unreachable server. One report per method+path per 10
  /// minutes: an outage is one problem, not a thousand rows.
  void apiFailure(String method, String path, int status, Duration took) {
    if (path.startsWith(reportPath)) return; // never report the reporter
    final key = '$method $path';
    final now = DateTime.now();
    final last = _lastApiReport[key];
    if (last != null && now.difference(last) < const Duration(minutes: 10)) {
      return;
    }
    _lastApiReport[key] = now;
    record(
      'api_failure',
      status == 0
          ? '$key could not reach the server (${took.inMilliseconds} ms)'
          : '$key answered $status (${took.inMilliseconds} ms)',
    );
  }

  // ── Freezes ────────────────────────────────────────────────────

  /// Our own native calls in flight, by name — what a freeze report names as
  /// "the handler that was running". Wrap a call in [trackNative].
  static final Set<String> inFlight = {};

  static Future<T> trackNative<T>(String name, Future<T> Function() call) async {
    inFlight.add(name);
    try {
      return await call();
    } finally {
      inFlight.remove(name);
    }
  }

  Timer? _uiWatch;
  Timer? _platformWatch;

  /// Watch for the app not answering. Two halves:
  ///  * the UI isolate — a 500 ms timer that fires late by more than
  ///    [freezeAfter] means Dart could not run (a long frame, heavy sync work);
  ///  * the platform thread (desktop) — a ping to native code that takes longer
  ///    than [freezeAfter] to answer means the window's thread was blocked,
  ///    which is what the 10-06 freeze was.
  void startWatchdogs() {
    _last = DateTime.now();
    _life ??= AppLifecycleListener(onStateChange: noteLifecycle);
    _uiWatch ??= Timer.periodic(_tick, (_) {
      final stall = checkTick(DateTime.now(), resumed: _resumed);
      if (stall != null) {
        record(
          'freeze',
          'The app did not respond for ${stall.inMilliseconds} ms',
          detail: _context(),
        );
      }
    });

    _platformWatch ??= Timer.periodic(const Duration(seconds: 5), (_) => _ping());
  }

  // ── Away is not frozen (2026-10-16) ──────────────────────────────
  //
  // The first day of reports was 175 web "freezes" of 3-59 s, 108 of them in
  // Scheduling, and they were background tabs: Chrome throttles a hidden
  // tab's timers to about once a minute, the late tick fired the moment the
  // tab came back, and by then the state read "resumed". Two tabs of one
  // person reported the same freeze in the same second, which no real freeze
  // does. So time spent away (hidden, minimized, unfocused) restarts the
  // clock instead of counting, whatever the state when the tick finally runs.
  AppLifecycleListener? _life;
  DateTime _last = DateTime.now();
  bool _wasAway = false;
  int _awayEpoch = 0;

  /// The lifecycle feed. Public for the test.
  void noteLifecycle(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed) {
      _wasAway = true;
      _awayEpoch++;
    }
  }

  /// One watchdog tick at [now]: the stall to report, or null. Public for the
  /// test.
  Duration? checkTick(DateTime now, {required bool resumed}) {
    if (!resumed || _wasAway) {
      _last = now;
      if (resumed) _wasAway = false;
      return null;
    }
    final stall = uiStall(_last, now);
    _last = now;
    return stall;
  }

  static const Duration _tick = Duration(milliseconds: 500);
  static const Duration freezeAfter = Duration(seconds: 2);

  /// How long the UI isolate was stuck between two ticks, or null if it was
  /// not. Gaps over a minute are a sleeping computer or a suspended app, not a
  /// freeze. Pure, for the test.
  static Duration? uiStall(DateTime previous, DateTime now) {
    final late = now.difference(previous) - _tick;
    if (late < freezeAfter || late > const Duration(minutes: 1)) return null;
    return late;
  }

  static bool get _resumed {
    final state = WidgetsBinding.instance.lifecycleState;
    return state == null || state == AppLifecycleState.resumed;
  }

  static const MethodChannel _watchdog = MethodChannel('com.ezbizi.app/watchdog');
  bool _pingOutstanding = false;
  bool _pingUnsupported = false;

  Future<void> _ping() async {
    if (_pingOutstanding || _pingUnsupported) return;
    _pingOutstanding = true;
    final started = DateTime.now();
    final epoch = _awayEpoch;
    try {
      await _watchdog.invokeMethod<void>('ping');
      final took = DateTime.now().difference(started);
      // Minimized or hidden while waiting: a slow answer is not a freeze.
      if (took >= freezeAfter && _resumed && epoch == _awayEpoch) {
        record(
          'freeze',
          'The window did not respond for ${took.inMilliseconds} ms',
          detail: _context(),
        );
      }
    } on MissingPluginException {
      _pingUnsupported = true; // only the Windows runner answers today
    } catch (_) {
      // A failed ping is not a freeze.
    } finally {
      _pingOutstanding = false;
    }
  }

  String _context() => [
        'module: ${module ?? 'unknown'}',
        'native calls in flight: ${inFlight.isEmpty ? 'none' : inFlight.join(', ')}',
      ].join('\n');

  // ── Native crashes from the last run ───────────────────────────

  /// Read and report the marker a native crash left behind (Windows runner
  /// writes one), then delete it. Missing plugin = this platform has none.
  Future<void> reportLastNativeCrash() async {
    try {
      final crash = await _watchdog.invokeMethod<String>('takeLastCrash');
      if (crash != null && crash.isNotEmpty) {
        record('native_crash', 'The app closed unexpectedly last time', detail: crash);
      }
    } catch (_) {}
  }

  void dispose() {
    _flushTimer?.cancel();
    _uiWatch?.cancel();
    _platformWatch?.cancel();
    _flushTimer = _uiWatch = _platformWatch = null;
    _life?.dispose();
    _life = null;
  }

  static String _clip(String s, int max) => s.length <= max ? s : s.substring(0, max);
}
