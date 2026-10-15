import 'dart:async';

import 'package:flutter/foundation.dart'
    show ValueNotifier, debugPrint, kDebugMode;
import 'package:flutter/services.dart' show MethodChannel;

import 'api_client.dart';
import 'device_key.dart';
import 'screenshot_capability.dart';
import 'issue_reporter.dart';

/// Remote Control — the AGENT half (2026-09-02-monitoring-remote-control, Part
/// A). The viewer half lives in desktop_monitoring_tab.dart; the backend
/// contract both sides implement: `GET
/// /monitoring/input-pending?device_key={key}` returns AND clears this
/// device's queue as `{"events":[...], "control_wanted":bool}`, and every
/// returned event is applied via the native `injectInput` channel
/// ([_defaultInject], Win32 SendInput).
///
/// ── One adaptive poll, cheap when idle and quick when driven ─────────
///
/// This is [LiveViewService]'s loop with a different question and an adaptive
/// cadence. It cannot be a flat [Timer.periodic]: a manager typing needs input
/// applied within a frame or two, but polling every 300ms forever — for every
/// agent, all shift, whether or not anybody ever takes control — is the exact
/// idle cost Live View was careful to avoid. So one self-rescheduling [Timer]
/// runs at [_slow] (~1s) baseline, and the moment a poll comes back with
/// `control_wanted == true` OR any events to apply it drops to [_fast]
/// (~300ms) for responsiveness. When it has seen nothing wanted and nothing
/// queued for [_idleTicksBeforeSlow] ticks running, it falls back to [_slow].
///
/// // ponytail: adaptive HTTP polling is the v1 ceiling. Even at [_fast] it is
/// // a round trip of latency per event batch, and a websocket would replace
/// // this whole loop for true low-latency control — that is the step to take
/// // if 300ms polling ever feels laggy in practice, not a knob to shrink here.
///
/// ── Windows-only, and never on web ──────────────────────────────────
///
/// Same guard as [LiveViewService] and [ScreenshotService], for the same
/// reason: only the Windows runner registers the native input channel, so
/// starting this timer anywhere else would poll `input-pending` forever for a
/// device that has no way to apply a single event it returns. An explicitly
/// injected [inject] bypasses the guard on purpose — always a test.
class RemoteControlService {
  RemoteControlService(
    this._api, {
    Duration fast = const Duration(milliseconds: 300),
    Duration slow = const Duration(seconds: 1),
    int idleTicksBeforeSlow = 3,
    Future<void> Function(List<Map<String, Object?>> events)? inject,
    Future<void> Function(bool active)? indicate,
  })  : _fast = fast,
        _slow = slow,
        _idleTicksBeforeSlow = idleTicksBeforeSlow,
        _inject = inject ?? _defaultInject,
        _indicate = indicate ?? _defaultIndicate {
    if (inject == null && !screenshotCaptureSupported) return;

    _interval = slow;
    _scheduleNext();
  }

  final ApiClient _api;
  final Duration _fast;
  final Duration _slow;
  final int _idleTicksBeforeSlow;
  final Future<void> Function(List<Map<String, Object?>> events) _inject;

  /// Raises or clears the native "being controlled" notice. Injected in tests;
  /// [_defaultIndicate] otherwise reaches the real tray.
  final Future<void> Function(bool active) _indicate;

  Timer? _timer;
  bool _disposed = false;

  /// The last notice state actually pushed to native, so the channel is called
  /// only on a TRANSITION (false→true, true→false) and not once per poll. The
  /// org's opt-in `indicator` flag ANDed with `control_wanted` is the truth it
  /// tracks: a machine shows the notice only while it is genuinely being driven
  /// AND the organization asked for one.
  bool _indicating = false;

  /// The same fact as [_indicating], published so the app itself can say it.
  ///
  /// ── Why the tray alone was not enough ────────────────────────────
  ///
  /// The notice existed only as a Shell_NotifyIcon tooltip and a one-off
  /// balloon. A balloon is a BEST-EFFORT OS notification: Windows suppresses
  /// it under Focus Assist, a full-screen app, or notification settings the
  /// person or their IT policy set years ago — and Shell_NotifyIcon returns
  /// success either way, so nothing can tell that it was swallowed. For a
  /// consent notice that is the wrong kind of surface: the one case where it
  /// must not fail silently is the only case it cannot report.
  ///
  /// So the app draws its own banner from this, which no OS setting can turn
  /// off. Both surfaces are driven by the SAME opt-in — this is only ever true
  /// when the organization enabled the notice AND the machine is genuinely
  /// being driven, so a business that chose a silent agent still gets one.
  final ValueNotifier<bool> controlled = ValueNotifier<bool>(false);

  /// The cadence the NEXT tick is scheduled at — starts at [_slow], and each
  /// poll may move it (see [_tick]). One field, because the timer is one-shot
  /// and reschedules itself; there is no `Timer.periodic` whose period is
  /// fixed at construction to fight with.
  late Duration _interval;
  int _idleTicks = 0;

  /// Only windows/runner registers these two channels: `injectInput` applies
  /// input via Win32 SendInput, `setControlled` swaps the tray icon's tooltip
  /// and shows a balloon. Nothing else has a handler, hence the Windows-only
  /// `screenshotCaptureSupported` gate in the constructor.
  static const _input = MethodChannel('com.ezbizi.app/monitoring_input');
  static const _indicator = MethodChannel(
    'com.ezbizi.app/monitoring_indicator',
  );

  /// Never throws, like [_defaultIndicate]: a `Timer` callback must never be
  /// at risk of an uncaught error from the native side.
  static Future<void> _defaultInject(List<Map<String, Object?>> events) async {
    try {
      await IssueReporter.trackNative(
        'injectInput',
        () => _input.invokeMethod<void>('injectInput', events),
      );
    } catch (e) {
      if (kDebugMode) {
        debugPrint('RemoteControlService: native input injection failed: $e');
      }
    }
  }

  static Future<void> _defaultIndicate(bool active) async {
    try {
      await IssueReporter.trackNative(
        'setControlled',
        () => _indicator.invokeMethod<void>('setControlled', active),
      );
    } catch (e) {
      if (kDebugMode) {
        debugPrint('RemoteControlService: native control indicator failed: $e');
      }
    }
  }

  void _scheduleNext() {
    if (_disposed) return;
    _timer = Timer(_interval, () => unawaited(_tick()));
  }

  Future<void> _tick() async {
    try {
      final deviceKey = await DeviceKey.value();

      final response = await _api.get('/monitoring/input-pending', {
        'device_key': deviceKey,
      });

      // Not ok covers a 404 (this device has never heartbeat-registered) and
      // any transient failure alike — neither is a reason to apply anything,
      // and neither should knock the cadence off its baseline, so leave the
      // idle bookkeeping untouched and just try again next tick.
      if (response.ok) {
        final events = _eventsFrom(response.body['events']);
        final controlWanted = response.body['control_wanted'] == true;

        // Only touch the input channel when there is something to apply — an
        // empty queue means the manager sent nothing this tick, not that a
        // batch of zero events should be handed to native code.
        if (events.isNotEmpty) await _inject(events);

        // The opt-in notice: show it only while this machine is BOTH being
        // driven and its org turned the indicator on. Pushed to native only on
        // a change, so a silent org (indicator false, the default) never calls
        // the channel at all, and an active session does not re-fire it every
        // poll. A transient non-ok response leaves the state untouched above —
        // a dropped poll must not flicker the notice off.
        final indicator = response.body['indicator'] == true;
        await _setIndicating(controlWanted && indicator);

        // Speed up while a manager is present OR events are actually flowing;
        // only sustained quiet (both false, several ticks running) earns the
        // fall back to the baseline cadence.
        if (controlWanted || events.isNotEmpty) {
          _idleTicks = 0;
          _interval = _fast;
        } else if (++_idleTicks >= _idleTicksBeforeSlow) {
          _interval = _slow;
        }
      }
    } catch (e) {
      if (kDebugMode) {
        debugPrint('RemoteControlService: poll/inject failed: $e');
      }
    } finally {
      _scheduleNext();
    }
  }

  /// Push a new notice state to native, but only when it actually changed —
  /// the transition guard that keeps `setControlled` off the per-poll path.
  Future<void> _setIndicating(bool active) async {
    if (active == _indicating) return;
    _indicating = active;
    controlled.value = active;
    await _indicate(active);
  }

  /// The server sends a JSON array of event maps; the native channel wants a
  /// `List<Map<String, Object?>>`. Rebuilt rather than cast so a malformed
  /// entry is dropped instead of throwing the whole tick into the catch above.
  static List<Map<String, Object?>> _eventsFrom(Object? raw) => [
        if (raw is List)
          for (final event in raw)
            if (event is Map) Map<String, Object?>.from(event),
      ];

  /// Stops the poll loop. Call this when whatever owns this instance goes away
  /// — same reasoning as [LiveViewService.dispose]. Sets [_disposed] so a
  /// tick already in flight does not reschedule itself after the cancel.
  void dispose() {
    _disposed = true;
    _timer?.cancel();
    _timer = null;

    // Best-effort clear so a notice never outlives the loop that raised it —
    // if this instance goes away mid-control, the machine should not be left
    // saying it is being controlled. Fire-and-forget: [_defaultIndicate] never
    // throws, and dispose does not await.
    if (_indicating) {
      _indicating = false;
      controlled.value = false;
      unawaited(_indicate(false));
    }

    controlled.dispose();
  }
}
