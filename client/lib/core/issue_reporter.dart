import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart' show FlutterError, PlatformDispatcher;
import 'package:flutter/gestures.dart' show GestureBinding, PointerDownEvent;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'api_client.dart';
import 'client_platform.dart';
import 'issue_reporter_env_io.dart'
    if (dart.library.js_interop) 'issue_reporter_env_web.dart' as env;

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

  /// The module on screen, set by MonitoringHeartbeat.enter and by the shell
  /// for the first screen after launch. Every report carries it, so "froze in
  /// Scheduling" needs no guessing. A change is a breadcrumb.
  static String? get module => _module;
  static set module(String? value) {
    if (value != null && value != _module) breadcrumb('screen', value);
    _module = value;
  }

  static String? _module;

  /// The address on screen (`/scheduling`), set by the shell.
  static String? route;

  // ── Context on every report (2026-10-27) ──────────────────────
  //
  // A week of reports could not say which browser, renderer, screen or how
  // long after launch. Every report now ends with one `context:` line of
  // compact JSON. Module keys and actions only: never what was typed or whose
  // record it was.

  static final Stopwatch _uptime = Stopwatch()..start();
  static final List<String> _breadcrumbs = [];
  static const int maxBreadcrumbs = 10;

  /// The last [maxBreadcrumbs] screen changes and taps — for tests.
  static List<String> get breadcrumbs => List.unmodifiable(_breadcrumbs);

  /// Note what just happened, by module key: `screen scheduling`, `tap leads`.
  static void breadcrumb(String action, [String? where]) {
    _breadcrumbs.add('$action ${where ?? _module ?? 'unknown'}');
    if (_breadcrumbs.length > maxBreadcrumbs) _breadcrumbs.removeAt(0);
  }

  static Map<String, Object?> context() {
    final memory = env.memoryBytes();
    final ua = env.userAgent();
    return {
      'route': ?route,
      'uptime_s': _uptime.elapsed.inSeconds,
      'platform': env.platformLabel(),
      'os': env.osVersion(),
      if (ua != null) 'browser': shortAgent(ua),
      'renderer': env.renderer(),
      // MB, not bytes: the server redacts long digit runs.
      if (memory != null) 'memory_mb': memory ~/ (1024 * 1024),
      'breadcrumbs': List<String>.of(_breadcrumbs),
    };
  }

  /// `Chrome/130 Safari/537 (Windows NT 10.0)` out of a full user agent:
  /// product and major version only, so the server's digit-run redaction
  /// leaves it alone.
  static String shortAgent(String ua) {
    final os = RegExp(r'\(([^;)]+)').firstMatch(ua)?.group(1);
    final products =
        RegExp(r'\b(Edg|OPR|Chrome|CriOS|Firefox|FxiOS|Version|Safari)/(\d+)')
            .allMatches(ua)
            .map((m) => '${m[1]}/${m[2]}')
            .join(' ');
    return [products, if (os != null) '($os)'].join(' ').trim();
  }

  // ── A dead renderer asks for a reload (2026-10-27) ────────────
  //
  // Once CanvasKit aborts, every frame throws `RuntimeError: Aborted()` until
  // the page is reloaded: 1,952 reports from 8 people, capped by the server.
  // Report it once per page load and ask the person to reload — never reload
  // for them, they may have typed something they would lose.

  /// True once the page's renderer is dead. [PageProblemBanner] listens.
  static final ValueNotifier<bool> pageBroken = ValueNotifier(false);
  bool _abortReported = false;

  static bool _isAbort(String message) => message.contains('Aborted()');

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
    GestureBinding.instance.pointerRouter.addGlobalRoute((event) {
      if (event is PointerDownEvent) breadcrumb('tap');
    });
    env.onPageHidden(instance.notePageHidden);
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
    if (_isAbort(message)) {
      if (_abortReported) return;
      _abortReported = true;
      pageBroken.value = true;
    }
    if (_queue.length >= maxQueued) return;
    _queue.add({
      'kind': kind,
      'message': _clip(message, 4000),
      'detail': withContext(detail),
      if (module != null) 'module': module,
    });
  }

  /// [detail] with the `context:` line last. The server fingerprints the first
  /// four lines and keeps 8,000 characters, so the detail is padded to four
  /// lines (varying breadcrumbs must not split one problem into many rows)
  /// and clipped to leave room for the context.
  static String withContext(String? detail) {
    // ponytail: the context rides in `detail` because the server keeps only
    // the fields it validates and client_issues has no context column.
    final lines = (detail == null || detail.isEmpty)
        ? <String>[]
        : _clip(detail, 6500).split('\n');
    while (lines.length < 4) {
      lines.add('');
    }
    return [...lines, 'context: ${jsonEncode(context())}'].join('\n');
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
      final stall = checkTick(
        DateTime.now(),
        resumed: _resumed,
        visible: env.pageVisible(),
      );
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

  /// The web tab went hidden or lost focus between two ticks (2026-10-27:
  /// the lifecycle stays `resumed` while Chrome throttles it).
  void notePageHidden() {
    _wasAway = true;
    _awayEpoch++;
  }

  // ── The throttle signature (2026-10-27) ─────────────────────────
  //
  // 2,129 web "freezes" from 13 people arrived once a minute for hours,
  // overnight too: Chrome's intensive throttling of a page it treats as in
  // the background. A real freeze does not recur on a one-minute beat, and a
  // gap near a minute is the throttle's own size.
  DateTime? _lastStallAt;
  static const Duration _throttleGap = Duration(seconds: 50);

  static bool _onThrottleBeat(Duration sinceLast) =>
      sinceLast >= const Duration(seconds: 50) &&
      sinceLast <= const Duration(seconds: 70);

  /// One watchdog tick at [now]: the stall to report, or null. [visible] is
  /// the page's own visibility and focus (always true off the web). Public
  /// for the test.
  Duration? checkTick(
    DateTime now, {
    required bool resumed,
    bool visible = true,
  }) {
    if (!resumed || !visible || _wasAway) {
      _last = now;
      if (resumed && visible) _wasAway = false;
      return null;
    }
    final stall = uiStall(_last, now);
    _last = now;
    if (stall == null) return null;

    final previous = _lastStallAt;
    _lastStallAt = now;
    if (stall >= _throttleGap) return null;
    if (previous != null && _onThrottleBeat(now.difference(previous))) {
      return null;
    }
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
    _lastStallAt = null;
    _wasAway = false;
    _life?.dispose();
    _life = null;
  }

  static String _clip(String s, int max) => s.length <= max ? s : s.substring(0, max);
}

/// "The page hit a problem and needs to reload · Reload", over every screen,
/// once [IssueReporter.pageBroken] is set. The person reloads; nothing does it
/// for them.
class PageProblemBanner extends StatelessWidget {
  const PageProblemBanner({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) => ValueListenableBuilder<bool>(
    valueListenable: IssueReporter.pageBroken,
    builder: (context, broken, child) => Stack(
      children: [
        ?child,
        if (broken)
          Positioned(
            left: 0,
            right: 0,
            top: 0,
            child: Material(
              color: Theme.of(context).colorScheme.errorContainer,
              child: SafeArea(
                bottom: false,
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          'The page hit a problem and needs to reload',
                          style: TextStyle(
                            color: Theme.of(context).colorScheme.onErrorContainer,
                          ),
                        ),
                      ),
                      TextButton(
                        onPressed: ClientPlatform.reload,
                        child: const Text('Reload'),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
      ],
    ),
    child: child,
  );
}
