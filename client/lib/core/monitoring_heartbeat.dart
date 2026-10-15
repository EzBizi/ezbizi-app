import 'dart:async';

import 'api_client.dart';
import 'client_platform.dart';
import 'device_key.dart';
import 'issue_reporter.dart';
import 'screenshot_capability.dart';
import 'specs_reader.dart';

typedef SpecsRead = Future<Map<String, dynamic>?> Function();

/// A device reporting its own presence — POST /monitoring/heartbeat, the
/// endpoint HeartbeatController::store() validates (2026-08-28-monitoring-pc-
/// specs). See app/Http/Controllers/Api/Monitoring/HeartbeatController.php
/// for the body this has to match exactly:
///
///   device_key      required, string, max 64
///   platform         required, one of web/android/ios/windows/macos/linux
///   capabilities     required, array of a fixed enum
///   current_module   optional, string
///   segment          optional: {module_key, dwell_seconds (int, >= 0)}
///
/// ── What a "beat" is, and when one goes out ───────────────────────
///
/// [enter] is called every time somebody switches to a different module. It
/// does two things in one request: it reports the module just OPENED as
/// `current_module`, and — if a module was already open — it closes that
/// module's segment, reporting how long it was open as `dwell_seconds`. The
/// segment names the module being LEFT, never the one being entered; get that
/// backwards and every dwell reading in the roster describes the wrong tab
/// while still passing every validation rule on the server, which is why it
/// is asserted explicitly in the test rather than left to fall out of the
/// server accepting the body.
///
/// Separately, a 60-second [Timer.periodic] posts a keep-alive beat carrying
/// whatever `current_module` is current — no segment, because nothing was
/// closed. Its only job is to keep `last_seen_at` fresh for a person who has
/// been sitting on the same screen for minutes, who [enter] alone would never
/// hear from again.
///
/// ── Why a [Stopwatch] and not two [DateTime.now()] calls ──────────
///
/// A stopwatch is the built-in tool for "how long since I started timing",
/// and reads the monotonic clock rather than the wall clock — immune to a
/// system clock adjustment (NTP sync, a manual change, a DST transition)
/// producing a negative or wildly wrong dwell time mid-session.
///
/// ── Why `device_key` comes from [DeviceKey] rather than living here ───────
///
/// [DeviceKey.value] reads it from local storage (or generates it, on the
/// very first call anything in this process makes) and caches it — see that
/// class for why the cache lives there, shared, rather than as a field on
/// this class: [ScreenshotService] (2026-08-31-monitoring-screenshots, Task
/// 6) posts to a DIFFERENT endpoint that must resolve to the exact same
/// `monitored_devices` row this beat creates, which only holds if both read
/// the identical cached value rather than each generating their own.
///
/// ── Why `'screenshot'` is declared conditionally ──────────────────
///
/// [screenshotCaptureSupported] is the same Windows-only, native-channel
/// check [ScreenshotService] gates its own capture loop on (see that file).
/// Declaring it here from anything else — a hardcoded platform list, a
/// separate assumption about what this build can do — would let the two
/// drift: a beat that claims `'screenshot'` while the service that would
/// actually produce one never runs, or the reverse.
///
/// The SECURITY exemption (the Lead Developer's own device, passed in as
/// [screenMonitoringExempt]) narrows this further: an exempt device declares no
/// `'screenshot'` capability even where the build could capture, because
/// main.dart also declines to construct [ScreenshotService] for it — so the
/// two still agree, they just both stand down.
/// The `specs` keys that are screen CONTENT rather than facts about the
/// machine. Mirrors ScreenMonitoringExemption::SCREEN_CONTENT_SPEC_KEYS on
/// the server, which strips the same keys on ingest and gates them on read —
/// this copy exists so an exempt agent never puts them on the wire at all.
const List<String> screenContentSpecKeys = <String>[
  'active_window',
  'browser_tabs',
];

class MonitoringHeartbeat {
  MonitoringHeartbeat(
    this._api, {
    Duration interval = const Duration(seconds: 60),
    SpecsRead? readSpecs,
    bool screenMonitoringExempt = false,
  }) : _interval = interval,
       _readSpecs = readSpecs ?? const SpecsReader().read,
       _screenMonitoringExempt = screenMonitoringExempt {
    // Started immediately, not on the first [enter]. A person who opens the
    // app and never touches the nav (the default screen is exactly where they
    // wanted to be) must still show up as present — the keep-alive is not
    // conditional on ever having changed modules.
    _timer = Timer.periodic(_interval, (_) => unawaited(_post()));
    unawaited(refreshSpecs()); // first read, non-blocking
    // Every minute, alongside the beat. It used to be every fifteen, which
    // was fine while specs meant "how much RAM does this machine have" and is
    // wrong now that they carry live load: a CPU figure is measured BETWEEN
    // reads (see CpuPercentSinceLastCall in monitoring_specs.cpp), so a
    // fifteen-minute gap makes "busy" mean the last quarter of an hour, and a
    // roster showing that as a live meter would be quietly lying.
    _specsTimer = Timer.periodic(
      const Duration(seconds: 60),
      (_) => unawaited(refreshSpecs()),
    );
  }

  final ApiClient _api;
  final Duration _interval;
  final SpecsRead _readSpecs;

  /// SECURITY exemption: the Lead Developer's own device does not declare the
  /// `screenshot` capability even where the build could capture, so the roster
  /// never lists it as capturable. The server strips it regardless (defence in
  /// depth); this just keeps the agent from advertising what it will never do.
  final bool _screenMonitoringExempt;

  Timer? _timer;
  Timer? _specsTimer;
  String? _currentModule;
  final Stopwatch _dwell = Stopwatch();
  Map<String, dynamic>? _lastSpecs;

  /// The most recent capture attempt's outcome, or null if this device has
  /// not attempted one since launch. Sent with every beat until replaced —
  /// deliberately NOT cleared once sent, so the roster keeps showing the last
  /// known answer instead of blanking between capture ticks (five minutes of
  /// "we don't know" every five minutes would be worse than a stale truth).
  Map<String, Object?>? _lastCapture;

  /// Read specs now and hold the result. Keeps the previous value on a null
  /// read (a transient failure should not blank a device that was reporting
  /// specs).
  Future<void> refreshSpecs() async {
    final specs = await _readSpecs();
    if (specs == null) return;

    // SECURITY exemption: some of what the agent reads is screen CONTENT
    // rather than anything about the machine — the title of the window in
    // front, and the browser tabs, can each name a customer, a subject line,
    // a document. An exempt device does not report those at all, the same way
    // it does not capture. Everything else in specs describes the hardware
    // and still goes: an exempt machine is still online and still shows its
    // RAM and its disks. The server strips them too (HeartbeatController), so
    // a tampered client gains nothing by keeping them.
    if (_screenMonitoringExempt) {
      for (final key in screenContentSpecKeys) {
        specs.remove(key);
      }
    }

    _lastSpecs = specs;
  }

  /// Record how the last screen capture went, for the next beat to carry.
  ///
  /// Called by [ScreenshotService] (wired in main.dart) after every attempt.
  /// The device is the only thing that knows why a capture did not happen,
  /// and until this existed it never told anyone: a machine that could not
  /// capture at all looked exactly like one that had not tried yet. [reason]
  /// is a short slug from a fixed vocabulary, never free text and never
  /// anything read off the screen — see monitoring_capture_channel.dart.
  void reportCapture({required bool ok, String? reason, int? captureMs}) {
    _lastCapture = {
      'ok': ok,
      'reason': ?reason,
      'capture_ms': ?captureMs,
      'at': DateTime.now().toUtc().toIso8601String(),
    };
  }

  /// Somebody switched to [moduleKey]. Closes the previous module's segment
  /// (if there was one) and posts a beat naming the new module as
  /// `current_module`. The very first call in a session has no previous
  /// module to close, so it posts with no `segment` at all.
  Future<void> enter(String moduleKey) async {
    final segment = _closeSegment();

    _currentModule = moduleKey;
    IssueReporter.module = moduleKey;
    _dwell
      ..reset()
      ..start();

    await _post(segment: segment);
  }

  /// The segment for the module being left, or null if this is the first
  /// module entered this session. Read the dwell BEFORE [_dwell] is reset for
  /// the module being entered — the caller does that reset itself, after this
  /// returns, precisely so this method never has to guess which stopwatch
  /// reading belongs to which module.
  Map<String, Object?>? _closeSegment() {
    final previous = _currentModule;
    if (previous == null) return null;

    return {
      'module_key': previous,
      'dwell_seconds': _dwell.elapsed.inSeconds,
    };
  }

  Future<void> _post({Map<String, Object?>? segment}) async {
    final specs = _lastSpecs;
    final body = <String, Object?>{
      'device_key': await DeviceKey.value(),
      'platform': ClientPlatform.name,
      // 'activity' always (any beating device reports activity); 'screenshot'
      // only when this build can actually capture one (same check the capture
      // service gates on); 'specs' only when a specs read has succeeded.
      'capabilities': [
        'activity',
        if (screenshotCaptureSupported && !_screenMonitoringExempt) 'screenshot',
        if (specs != null) 'specs',
      ],
      if (_currentModule != null) 'current_module': _currentModule,
      'segment': ?segment,
      'specs': ?specs,
      'capture': ?_lastCapture,
    };

    await _api.post('/monitoring/heartbeat', body);

    // Somebody else wanted a minute to have passed.
    //
    // This object already owns the only periodic timer the shell runs, and a
    // second one costs more than the tick it produces: every `Timer.periodic`
    // alive in a widget test makes `pumpAndSettle` wait for ever, so a new one
    // has to be defused in every test that renders the app bar. Handing out
    // the beat that already happens costs nothing and adds no timer.
    //
    // Deliberately carries NOTHING about what beat or why. This is a clock,
    // not a channel — the listener is expected to go and ask its own question.
    _tick();
  }

  /// Called after each keep-alive beat. See [_post].
  void Function()? onBeat;

  /// Rings [onBeat] without letting it become the heartbeat's problem.
  ///
  /// The try/catch is not defensive habit, it is the difference between a
  /// listener's bug and a broken app. `_post` is awaited by [enter], so an
  /// exception thrown by somebody else's callback propagates out of "the
  /// person switched module" — a failed presence fetch would have taken the
  /// nav rail with it. From the timer it is milder but still wrong: an
  /// unhandled async error every 60 seconds, for ever.
  ///
  /// Same reasoning, same shape, as ScreenshotService._report, which guards
  /// its own outcome callback for exactly this reason. The rule for this class
  /// is that nothing a caller hands it can stop it beating.
  void _tick() {
    try {
      onBeat?.call();
    } catch (_) {
      // Deliberately swallowed: see above. A listener that throws is a bug in
      // the listener, and it is the listener's job to notice it — silently
      // ending the keep-alive would take presence down for everybody instead.
    }
  }

  /// Stops the keep-alive timer. Call this when whatever owns this instance
  /// goes away — otherwise the timer keeps this object, and everything it
  /// closes over, alive and posting for the lifetime of the process.
  void dispose() {
    _timer?.cancel();
    _timer = null;
    _specsTimer?.cancel();
    _specsTimer = null;
  }
}
