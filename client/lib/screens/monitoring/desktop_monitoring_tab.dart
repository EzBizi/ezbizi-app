import '../../widgets/list_search.dart';
import 'dart:async';
import 'dart:ui' as ui;

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/live_changes.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import 'device_display.dart';
import 'note_card.dart';

/// Desktop Monitoring — one employee's periodic screen captures, in the
/// "hero + filmstrip" layout Jose picked (docs/previews/
/// monitoring-screenshots-layouts.html, "Layout 2"), reached by picking a
/// PERSON from an on-screen list — never a dropdown or a dialog.
///
/// ── Person-first, not device-first ────────────────────────────────
///
/// A browser session can never be screen-captured, so it has no business in
/// this tab's picker at all — `_loadDevices` filters the server's device
/// list down to capture-capable devices before anything else touches it.
/// What is left is grouped by person: each person shows once, defaulting to
/// their own most-recently-seen capture device. A person with more than one
/// such device gets a small inline chooser to switch between them — still no
/// modal, just a row of chips under their name.
///
/// ── The rule this tab exists to keep honest ────────────────────────
///
/// Same guarantee as the reachability column and PC Specs's withheld-grant
/// sentence: nothing here may be shown as though it were more current or
/// more available than it really is.
///
///   * No `monitoring.screenshots.view` → [noPermissionNote], no fetch at
///     all — the server would 403 every read anyway, and asking first only
///     to show the same sentence a beat later teaches nobody anything.
///   * A device that never declared the `screenshot` capability (web,
///     mobile, or a Windows agent that hasn't started capturing) →
///     [noAgentNote]. It CANNOT be screen-captured, which is a different
///     fact from "hasn't captured recently". In the normal flow such a
///     device can never be selected at all (see above) — this branch stays
///     as defence in depth, not the everyday path.
///   * A capable device with zero rows yet → [noCapturesYetNote].
///   * A capable device whose newest capture is older than twice the
///     capture interval, OR whose heartbeat has gone quiet — "capture
///     stopped" — see [_stoppedLabel]. The hero still shows the last frame
///     it has (dimmed), because that is more honest than a blank tile, but
///     the label says plainly that the stream is not live.
///
/// The default hero is always the newest capture (`_shots.first` — the
/// server already sorts newest-first, see ScreenshotsController::index).
/// Tapping a filmstrip thumbnail swaps which frame is the hero; the
/// "capture stopped" fact is about the DEVICE, not the frame on screen, so
/// it stays attached to the newest capture's age regardless of which frame
/// is being looked at — an old frame someone chose to look at is not lying
/// about being current either way.
class DesktopMonitoringTab extends StatefulWidget {
  const DesktopMonitoringTab({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  /// How many recent captures the filmstrip holds.
  static const int filmstripLimit = 12;

  /// [ScreenshotService]'s own default interval (client/lib/core/
  /// screenshot_service.dart) — kept as a literal here rather than an
  /// import, because this tab has no reason to depend on the capture
  /// service itself, only on the number it uses.
  static const Duration captureInterval = Duration(minutes: 5);

  /// Above this width the people list is a left rail beside the capture;
  /// at or below it, a horizontal chip row sits above the capture instead.
  static const double wideAbove = 600;

  static const String noPermissionNote =
      'Your role does not include monitoring.screenshots.view, so desktop '
      'screenshots are not shown here.';

  static const String noAgentNote =
      "No captures — this device can't be screen-captured.";

  static const String noCapturesYetNote =
      'This device can capture screenshots, but none have landed yet.';

  /// The same empty state when the device HAS told us why. Kept separate so
  /// the sentence above never has to imply "give it a minute" about a machine
  /// that has been failing every five minutes since it launched.
  static const String noCapturesFailingNote =
      'No captures have landed. The device reported why above.';

  static const String noDevicesNote =
      'No devices are running the desktop agent yet.';

  @override
  State<DesktopMonitoringTab> createState() => _DesktopMonitoringTabState();
}

class _DesktopMonitoringTabState extends State<DesktopMonitoringTab> {
  bool _loadingDevices = true;
  String? _devicesError;
  List<Map<String, dynamic>> _devices = [];
  String? _selectedDeviceId;

  bool _loadingShots = false;
  String? _shotsError;
  List<Map<String, dynamic>> _shots = [];
  String? _heroId;

  // Keyed by screenshot id, never cleared for the life of this state — so
  // stepping the hero back and forth across the filmstrip never re-downloads
  // a frame it already has.
  final Map<String, Uint8List> _bytes = {};

  /// Bumped every time a shots fetch STARTS — a device switch, a refresh of
  /// the same device, whatever. `_loadShots` captures the value at the top
  /// and re-checks it after every `await`; a response that lands after a
  /// newer fetch has already started is stale and applies nothing.
  ///
  /// ── The bug this guards against ────────────────────────────────
  ///
  /// Two GETs in flight have no ordering guarantee. Switch device A → B
  /// quickly and A's response can resolve AFTER B's — without this guard,
  /// A's `setState` would land last and overwrite `_shots`/`_heroId` with
  /// A's frames while the picker still names B. That is exactly the
  /// wrong-attribution the honesty rule exists to prevent (one device's
  /// screenshots shown as another's), just triggered by switching instead
  /// of elapsed time. See `desktop_monitoring_tab_test.dart`'s device-switch
  /// race test — it fails without this counter and passes with it.
  int _loadGeneration = 0;

  // ── Live View (2026-09-02-monitoring-live-view, Part B) ────────────────
  //
  // Whether the hero is currently showing the live feed for
  // `_selectedDeviceId` rather than the newest 30-day screenshot. `_live`
  // and the timers below are the whole state — there is no generation
  // counter here the way `_loadShots` has one, because every callback below
  // closes over the SAME device id it started with and re-checks
  // `_live && deviceId == _selectedDeviceId` after every await. A late
  // answer for a device this tab has since left (switched away from, or
  // stopped watching) fails that check and paints nothing — the identical
  // guarantee `_loadShots`'s generation counter gives the 30-day path, just
  // expressed against the two facts (still live, still this device) that
  // actually matter here instead of a monotonic counter.
  bool _live = false;
  Uint8List? _liveBytes;

  // The live frame's real aspect ratio (width/height), decoded from the first
  // frame. The hero sizes itself to THIS, not a fixed 16:9, so a click maps
  // onto the same spot on the remote screen no matter its shape — a 16:10
  // laptop or a 4:3 monitor would otherwise land control input offset and
  // leave the cropped strips unreachable. Null until the first frame decodes.
  double? _liveAspect;

  // True until the first frame arrives, and again after two consecutive
  // misses — see `_fetchLiveFrame`. Read by `_LiveHero` to decide between
  // showing `_liveBytes` and the "Waiting for the live feed…" state.
  bool _liveWaiting = true;
  int _liveMisses = 0;

  Timer? _watchTimer;
  Timer? _frameTimer;

  static const Duration _watchInterval = Duration(seconds: 8);
  static const Duration _frameInterval = Duration(milliseconds: 1500);

  // A single miss is tolerated (one slow request is not "the feed stopped")
  // — only back-to-back misses clear the hero to the waiting state, per the
  // "never keep showing the last frame as if current" rule.
  static const int _maxConsecutiveLiveMisses = 2;

  // ── Remote Control (2026-09-02-monitoring-remote-control, Part B) ───────
  //
  // The VIEWER half: while `_control` is true for `_selectedDeviceId`, the
  // manager's pointer and keyboard over the live hero are captured, batched,
  // and POSTed to the agent's input queue. The agent half applies them (see
  // RemoteControlService). The same two-fact identity guard the live path
  // uses — `_control && deviceId == _selectedDeviceId`, re-checked after every
  // await — keeps one device's input from ever being posted under another's.
  bool _control = false;

  Timer? _controlTimer;
  Timer? _flushTimer;

  // Events captured since the last flush. A mouse drag is dozens of moves a
  // second; posting one HTTP request per event would be absurd, so they pool
  // here and go out in batches — on a short debounce, or the moment the buffer
  // is full (see `_queueInput`).
  final List<Map<String, Object?>> _pendingInput = [];

  // Which mouse button a press started with, so the matching release names the
  // same one — a PointerUpEvent's `buttons` is what is STILL held (usually
  // none), which cannot tell us what was let go.
  String _activeButton = 'left';

  // The command modifiers — Ctrl/Alt/Meta(Win) — currently held down, by their
  // Windows VK (VK_CONTROL/VK_MENU/VK_LWIN). This set being non-empty is what
  // puts `_onKey` into command-chord mode, where every key (the letter and
  // Shift included) goes to the remote as a `key` VK event and nothing as
  // `text`, so Ctrl+C drives a real chord instead of typing "c". Shift is
  // deliberately not tracked here: it never defines a chord, it only rides one
  // (see `_onKey`). Cleared on `_stopControl` so a stuck-held modifier can
  // never bleed into the next control session.
  final Set<int> _heldCommandMods = {};

  // Measures the rendered live-image box for coordinate mapping. A GlobalKey +
  // RenderBox rather than a LayoutBuilder because the capture pane sits in a
  // vertically-unbounded SingleChildScrollView, so a LayoutBuilder's max height
  // is infinity there — the RenderBox's actual laid-out size is the only honest
  // denominator.
  final GlobalKey _liveImageKey = GlobalKey();

  static const Duration _controlInterval = Duration(seconds: 8);
  static const Duration _flushDebounce = Duration(milliseconds: 60);

  // Flush without waiting for the debounce once this many events have pooled —
  // so a fast drag becomes a few batched posts rather than one ever-growing
  // buffer that only leaves on the timer.
  static const int _flushThreshold = 20;

  bool get _canView =>
      widget.session.me?.can('monitoring.screenshots.view') ?? false;

  bool get _canControl =>
      widget.session.me?.can('monitoring.control') ?? false;

  @override
  void initState() {
    super.initState();
    if (_canView) {
      _loadDevices();
      // A machine coming online or going quiet, or a new screenshot: the
      // devices and the chosen one's filmstrip re-read quietly; the picked
      // frame and a live view are kept.
      _feed = LiveChanges.of(widget.api).subscribe({'monitoring_devices'}, (_) {
        if (!_loadingDevices && !_loadingShots) return _loadDevices(quiet: true);
      }, active: LiveChanges.visible(this));
    }
  }

  LiveSubscription? _feed;

  @override
  void dispose() {
    _feed?.cancel();
    _watchTimer?.cancel();
    _frameTimer?.cancel();
    _controlTimer?.cancel();
    _flushTimer?.cancel();
    super.dispose();
  }

  /// [quiet]: a live re-read; a failure keeps what is shown.
  Future<void> _loadDevices({bool quiet = false}) async {
    if (!quiet) {
      setState(() {
        _loadingDevices = _devices.isEmpty;
        _devicesError = null;
      });
    }

    final response = await widget.api.get('/monitoring/devices');

    if (!mounted || (quiet && !response.ok)) return;

    if (!response.ok) {
      setState(() {
        _loadingDevices = false;
        _devicesError = response.message;
      });
      return;
    }

    final all = (response.data as List<dynamic>).cast<Map<String, dynamic>>();
    // A browser session can never be screen-captured, so it has no business
    // in this tab's picker at all — filtered here, at the source, rather
    // than left in the list for the selection logic to work around.
    final devices = all.where(_declaresScreenshot).toList();
    final keepSelection = devices.any((d) => d['id'] == _selectedDeviceId);

    setState(() {
      _loadingDevices = false;
      _devices = devices;
      if (!keepSelection) {
        final fallback = mostRecentlySeen(devices);
        _selectedDeviceId = fallback == null ? null : fallback['id'] as String?;
      }
    });

    final deviceId = _selectedDeviceId;
    if (deviceId != null) await _loadShots(deviceId, quiet: quiet);
  }

  void _selectDevice(String id) {
    if (id == _selectedDeviceId) return;

    // Stops both live timers before the id even changes — "switching device
    // ... STOPS both timers immediately" — so nothing already in flight for
    // the OLD device can be mistaken for the new one even before the
    // `deviceId == _selectedDeviceId` guard in each callback gets a chance
    // to run.
    _stopLive();

    setState(() {
      _selectedDeviceId = id;
      _heroId = null;
      _shots = [];
      _shotsError = null;
    });

    _loadShots(id);
  }

  /// Selecting a person selects THEIR default capture device — the one they
  /// were most recently seen on. Goes through [_selectDevice] so the race
  /// guard and the existing fetch path are exactly the same one a direct
  /// device pick uses.
  void _selectPerson(MonitoringPerson person) {
    final target = mostRecentlySeen(person.devices);
    if (target != null) _selectDevice(target['id'] as String);
  }

  /// [quiet]: a live re-read; no spinner, a failure keeps the filmstrip,
  /// and the frame being looked at stays picked while it is still there.
  Future<void> _loadShots(String deviceId, {bool quiet = false}) async {
    // Captured before any `await` — nothing between here and the increment
    // can race it, so every later check in this call is comparing against
    // whichever fetch (this one, or a newer one) is actually current.
    final generation = ++_loadGeneration;

    final device = _deviceById(deviceId);

    // A device that never declared the capability cannot have anything to
    // fetch — asking the server is a round trip to learn a fact this tab
    // already has from the device row itself. In the normal flow every
    // device here already passed that filter in `_loadDevices`; this stays
    // as the same defence-in-depth check regardless.
    if (device == null || !_declaresScreenshot(device)) {
      setState(() {
        _loadingShots = false;
        _shotsError = null;
        _shots = [];
        _heroId = null;
      });
      return;
    }

    if (!quiet) {
      setState(() {
        _loadingShots = true;
        _shotsError = null;
      });
    }

    final response = await widget.api.get('/monitoring/screenshots', {
      'device_id': deviceId,
      'limit': '${DesktopMonitoringTab.filmstripLimit}',
    });

    // A newer fetch (another switch, or a refresh) started while this one
    // was in flight — its answer, whenever it lands, is not allowed to
    // touch state a newer selection already owns.
    if (!mounted || generation != _loadGeneration) return;
    if (quiet && !response.ok) return;

    if (!response.ok) {
      setState(() {
        _loadingShots = false;
        _shotsError = response.message;
      });
      return;
    }

    final shots = (response.data as List<dynamic>).cast<Map<String, dynamic>>();
    final kept = quiet && shots.any((s) => s['id'] == _heroId);

    setState(() {
      _loadingShots = false;
      _shots = shots;
      if (!kept) _heroId = shots.isEmpty ? null : shots.first['id'] as String?;
    });

    await _fetchBytes(shots, generation);
  }

  Future<void> _fetchBytes(List<Map<String, dynamic>> shots, int generation) async {
    for (final shot in shots) {
      // Same guard, per frame — a stale fetch stops downloading the moment
      // a newer one has taken over, rather than continuing to work (and
      // occasionally `setState`) on behalf of a selection nobody sees any
      // more.
      if (!mounted || generation != _loadGeneration) return;

      final id = shot['id'] as String?;
      if (id == null || _bytes.containsKey(id)) continue;

      final bytes = await widget.api.getBytes('/monitoring/screenshots/$id');
      if (!mounted || generation != _loadGeneration || bytes == null) continue;

      setState(() => _bytes[id] = Uint8List.fromList(bytes));
    }
  }

  Map<String, dynamic>? _deviceById(String? id) =>
      _devices.where((d) => d['id'] == id).firstOrNull;

  void _toggleLive(String deviceId) {
    if (_live) {
      _stopLive();
    } else {
      _startLive(deviceId);
    }
  }

  void _startLive(String deviceId) {
    setState(() {
      _live = true;
      _liveBytes = null;
      _liveAspect = null;
      _liveWaiting = true;
      _liveMisses = 0;
    });

    // Fire immediately rather than waiting a full interval for the first
    // tick — a manager who just tapped "Go live" should not stare at
    // "Waiting for the live feed…" for up to 8s/1.5s longer than necessary.
    unawaited(_postWatch(deviceId));
    unawaited(_fetchLiveFrame(deviceId));

    _watchTimer = Timer.periodic(_watchInterval, (_) => unawaited(_postWatch(deviceId)));
    _frameTimer = Timer.periodic(_frameInterval, (_) => unawaited(_fetchLiveFrame(deviceId)));
  }

  void _stopLive() {
    // You cannot drive a machine you can no longer see, so leaving live ends
    // control too — this is the single choke point every "stop watching" path
    // funnels through (Stop button, device switch, dispose), so control can
    // never outlive the feed it rides on.
    _stopControl();

    _watchTimer?.cancel();
    _watchTimer = null;
    _frameTimer?.cancel();
    _frameTimer = null;

    if (!_live) return;

    setState(() {
      _live = false;
      _liveBytes = null;
      _liveAspect = null;
      _liveWaiting = true;
      _liveMisses = 0;
    });
  }

  /// Keeps the server's "somebody is watching this device" interest fresh —
  /// POST /monitoring/devices/{id}/watch, 15s TTL server-side, re-posted well
  /// inside that window every [_watchInterval]. No `setState`: a missed post
  /// here surfaces on its own through `_fetchLiveFrame`'s own miss counter
  /// once the agent stops seeing `wanted: true`, so there is nothing this
  /// needs to paint by itself.
  Future<void> _postWatch(String deviceId) async {
    if (!_live || deviceId != _selectedDeviceId) return;
    await widget.api.post('/monitoring/devices/$deviceId/watch', const {});
  }

  /// GET /monitoring/devices/{id}/live-frame — bytes when the agent's last
  /// upload is still fresh, otherwise a 404 that [ApiClient.getBytes] turns
  /// into `null`. A single `null` is tolerated (one slow tick is not "the
  /// feed stopped"); only [_maxConsecutiveLiveMisses] in a row clears the
  /// hero to the waiting state — see the honesty rule this whole tab already
  /// keeps for the 30-day path: never show a frame as though it were current
  /// once it plainly isn't.
  Future<void> _fetchLiveFrame(String deviceId) async {
    if (!_live || deviceId != _selectedDeviceId) return;

    final bytes = await widget.api.getBytes('/monitoring/devices/$deviceId/live-frame');

    // Re-checked after the await: `_live` may have gone false, or the
    // selection may have moved to a different device, while this request was
    // in flight. Either way this frame belongs to a view nobody is looking
    // at any more and must paint nothing.
    if (!mounted || !_live || deviceId != _selectedDeviceId) return;

    if (bytes == null) {
      _liveMisses++;
      if (_liveMisses >= _maxConsecutiveLiveMisses) {
        setState(() {
          _liveWaiting = true;
          _liveBytes = null;
        });
      }
      return;
    }

    final frame = Uint8List.fromList(bytes);
    _liveMisses = 0;
    setState(() {
      _liveBytes = frame;
      _liveWaiting = false;
    });

    // Learn the remote screen's aspect ratio once, from the first frame, so the
    // hero can match it (see `_liveAspect`). Decoding is off the paint path and
    // one-shot; re-guard the device after the async decode so a late result for
    // a device we've left is dropped.
    if (_liveAspect == null) {
      ui.decodeImageFromList(frame, (image) {
        final aspect = image.height == 0 ? null : image.width / image.height;
        image.dispose();
        if (!mounted || aspect == null) return;
        if (!_live || deviceId != _selectedDeviceId) return;
        setState(() => _liveAspect = aspect);
      });
    }
  }

  // ── Remote Control (Part B) ────────────────────────────────────────────

  void _toggleControl(String deviceId) {
    if (_control) {
      _stopControl();
    } else {
      _startControl(deviceId);
    }
  }

  void _startControl(String deviceId) {
    // Taking control needs the screen you are driving — auto-start live if it
    // is not already running, exactly as the plan requires. `_startLive` is a
    // no-op-safe setState either way; if already live it is not re-entered.
    if (!_live) _startLive(deviceId);

    setState(() => _control = true);

    // Assert interest immediately rather than waiting a full interval — the
    // agent starts speeding its poll up the moment it sees `control_wanted`.
    unawaited(_postControl(deviceId));
    _controlTimer =
        Timer.periodic(_controlInterval, (_) => unawaited(_postControl(deviceId)));
  }

  void _stopControl() {
    _controlTimer?.cancel();
    _controlTimer = null;
    _flushTimer?.cancel();
    _flushTimer = null;
    _pendingInput.clear();
    _activeButton = 'left';
    _heldCommandMods.clear();

    if (!_control) return;
    setState(() => _control = false);
  }

  /// Keeps the server's "somebody wants to drive this device" interest fresh —
  /// POST /monitoring/devices/{id}/control, re-posted every [_controlInterval]
  /// (which also keeps the live feed alive). The same identity guard as the
  /// watch/frame posts: a control post for a device this tab has since left is
  /// dropped rather than refreshing interest on the wrong machine.
  Future<void> _postControl(String deviceId) async {
    if (!_control || deviceId != _selectedDeviceId) return;
    await widget.api.post('/monitoring/devices/$deviceId/control', const {});
  }

  /// Translate a pointer's GLOBAL position to the normalized 0..1 coordinates
  /// the agent applies — `x = dx/width`, `y = dy/height`, clamped — measured
  /// against the live image's own laid-out box (the primary-monitor frame
  /// fills the hero). Null when the box is not laid out yet, so a stray early
  /// event emits nothing rather than dividing by zero.
  Offset? _normalized(Offset globalPosition) {
    final box = _liveImageKey.currentContext?.findRenderObject() as RenderBox?;
    if (box == null || !box.hasSize) return null;

    final size = box.size;
    if (size.width <= 0 || size.height <= 0) return null;

    final local = box.globalToLocal(globalPosition);
    return Offset(
      (local.dx / size.width).clamp(0.0, 1.0),
      (local.dy / size.height).clamp(0.0, 1.0),
    );
  }

  void _onPointerDown(String deviceId, PointerDownEvent event) {
    final point = _normalized(event.position);
    if (point == null) return;

    _activeButton = _mouseButtonName(event.buttons);
    _queueInput(deviceId, {'type': 'mouseMove', 'x': point.dx, 'y': point.dy});
    _queueInput(
      deviceId,
      {'type': 'mouseButton', 'button': _activeButton, 'down': true},
    );
  }

  void _onPointerMove(String deviceId, PointerEvent event) {
    final point = _normalized(event.position);
    if (point == null) return;
    _queueInput(deviceId, {'type': 'mouseMove', 'x': point.dx, 'y': point.dy});
  }

  void _onPointerUp(String deviceId, PointerUpEvent event) {
    final point = _normalized(event.position);
    if (point != null) {
      _queueInput(deviceId, {'type': 'mouseMove', 'x': point.dx, 'y': point.dy});
    }
    _queueInput(
      deviceId,
      {'type': 'mouseButton', 'button': _activeButton, 'down': false},
    );
  }

  void _onPointerSignal(String deviceId, PointerSignalEvent event) {
    if (event is! PointerScrollEvent) return;
    // Flutter's positive scrollDelta.dy means "content moved up" — i.e. the
    // wheel rolled toward the user (down). Negate so `dy` matches a normal
    // Windows wheel notch, where positive is a roll away from the user (up).
    final dy = -event.scrollDelta.dy.round();
    if (dy == 0) return;
    _queueInput(deviceId, {'type': 'scroll', 'dy': dy});
  }

  static String _mouseButtonName(int buttons) {
    if (buttons & kSecondaryButton != 0) return 'right';
    // The middle (wheel) button is Flutter's tertiary button.
    if (buttons & kTertiaryButton != 0) return 'middle';
    return 'left';
  }

  /// The navigation/editing special keys, mapped to Windows virtual-key codes.
  /// These fire as `key` VK events whether or not a modifier is held; the
  /// letter/digit/space/function-key VKs that a command chord additionally
  /// needs live in [_chordVirtualKeyFor], which layers on top of this map.
  static int? _virtualKeyFor(LogicalKeyboardKey key) {
    if (key == LogicalKeyboardKey.enter ||
        key == LogicalKeyboardKey.numpadEnter) {
      return 0x0D;
    }
    if (key == LogicalKeyboardKey.backspace) return 0x08;
    if (key == LogicalKeyboardKey.tab) return 0x09;
    if (key == LogicalKeyboardKey.escape) return 0x1B;
    if (key == LogicalKeyboardKey.delete) return 0x2E;
    if (key == LogicalKeyboardKey.arrowLeft) return 0x25;
    if (key == LogicalKeyboardKey.arrowUp) return 0x26;
    if (key == LogicalKeyboardKey.arrowRight) return 0x27;
    if (key == LogicalKeyboardKey.arrowDown) return 0x28;
    if (key == LogicalKeyboardKey.home) return 0x24;
    if (key == LogicalKeyboardKey.end) return 0x23;
    if (key == LogicalKeyboardKey.pageUp) return 0x21;
    if (key == LogicalKeyboardKey.pageDown) return 0x22;
    return null;
  }

  /// Ctrl/Alt/Meta/Shift (either side) → their Windows modifier VK, else null.
  /// VK_CONTROL=0x11, VK_MENU(Alt)=0x12, VK_LWIN=0x5B, VK_SHIFT=0x10.
  static int? _modifierVkFor(LogicalKeyboardKey key) {
    if (key == LogicalKeyboardKey.control ||
        key == LogicalKeyboardKey.controlLeft ||
        key == LogicalKeyboardKey.controlRight) {
      return 0x11;
    }
    if (key == LogicalKeyboardKey.alt ||
        key == LogicalKeyboardKey.altLeft ||
        key == LogicalKeyboardKey.altRight) {
      return 0x12;
    }
    if (key == LogicalKeyboardKey.meta ||
        key == LogicalKeyboardKey.metaLeft ||
        key == LogicalKeyboardKey.metaRight) {
      return 0x5B;
    }
    if (_isShiftKey(key)) return 0x10;
    return null;
  }

  static bool _isShiftKey(LogicalKeyboardKey key) =>
      key == LogicalKeyboardKey.shift ||
      key == LogicalKeyboardKey.shiftLeft ||
      key == LogicalKeyboardKey.shiftRight;

  /// The VK a command chord resolves a non-modifier key to: the special-key
  /// map ([_virtualKeyFor]), plus letters A–Z, digits 0–9, space, and F1–F12.
  /// Null when there is no known VK, so `_onKey` drops the key rather than
  /// typing a stray character in the middle of a chord.
  static int? _chordVirtualKeyFor(LogicalKeyboardKey key) {
    final special = _virtualKeyFor(key);
    if (special != null) return special;

    final id = key.keyId;
    // A logical key for a printable ASCII character carries that character's
    // code point as its id. Letters a–z (0x61–0x7A) map to VK_A–VK_Z, which is
    // the uppercase code point (0x41–0x5A). Digits 0–9 and space already ARE
    // their VK (VK_0–VK_9 = 0x30–0x39, VK_SPACE = 0x20). Punctuation ids fall
    // outside these ranges and drop through to null on purpose (see below).
    if (id >= 0x61 && id <= 0x7A) return id - 0x20;
    if (id >= 0x30 && id <= 0x39) return id;
    if (id == 0x20) return 0x20;

    // Function keys live in the logical-key plane, not the ASCII range above,
    // and F1–F12 are contiguous there — so F1..F12 → VK_F1(0x70)..VK_F12(0x7B)
    // is one offset. (LogicalKeyboardKey overrides `==`, so it can't key a
    // const map; the id arithmetic here is the same shape as the letters and
    // digits just above.)
    const f1 = 0x00100000801; // LogicalKeyboardKey.f1.keyId
    const f12 = 0x0010000080c; // LogicalKeyboardKey.f12.keyId
    if (id >= f1 && id <= f12) return 0x70 + (id - f1);

    // ponytail: punctuation-with-modifier (Ctrl+`/`, Ctrl+`-`, Ctrl+`=`, …) is
    // the known gap — those logical keys carry layout-dependent OEM VKs. Add
    // the specific ones here if a user asks for them.
    return null;
  }

  KeyEventResult _onKey(String deviceId, KeyEvent event) {
    if (!_control || deviceId != _selectedDeviceId) {
      return KeyEventResult.ignored;
    }

    final key = event.logicalKey;
    // A KeyRepeatEvent is an auto-repeat of a held key — a `down` for both the
    // chord and the special-key paths (never an "up"), which is how the native
    // side's SendInput wants a held key expressed.
    final down = event is! KeyUpEvent;

    // 1. A modifier key itself. Ctrl/Alt/Meta always go through as their VK and
    // maintain the held-set that defines command-chord mode. Shift only goes
    // through WHILE a command modifier is already held: outside a chord the
    // capital arrives via `.character` as text, and bracketing it in a bare
    // Shift VK would double-type it (so a plain Shift+a still yields one "A").
    final modVk = _modifierVkFor(key);
    if (modVk != null) {
      if (_isShiftKey(key)) {
        if (_heldCommandMods.isNotEmpty) {
          _queueInput(deviceId, {'type': 'key', 'code': modVk, 'down': down});
        }
        return KeyEventResult.handled;
      }
      if (down) {
        _heldCommandMods.add(modVk);
      } else {
        _heldCommandMods.remove(modVk);
      }
      _queueInput(deviceId, {'type': 'key', 'code': modVk, 'down': down});
      return KeyEventResult.handled;
    }

    // 2. Command-chord mode — a non-modifier key while any of Ctrl/Alt/Meta is
    // held. Resolve to a VK and emit a `key` event; NEVER a `text` event (the
    // chord is the whole point). A key with no known VK is dropped, not typed
    // as a stray character mid-combo.
    if (_heldCommandMods.isNotEmpty) {
      final vk = _chordVirtualKeyFor(key);
      if (vk != null) {
        _queueInput(deviceId, {'type': 'key', 'code': vk, 'down': down});
      }
      return KeyEventResult.handled;
    }

    // 3. No command modifier held — today's behavior, unchanged. The special
    // navigation/editing keys go as their VK down/up; a printable character
    // types as `text`.
    final vk = _virtualKeyFor(key);
    if (vk != null) {
      _queueInput(deviceId, {'type': 'key', 'code': vk, 'down': down});
      return KeyEventResult.handled;
    }

    if (down) {
      final character = event.character;
      if (character != null && character.isNotEmpty && _isPrintable(character)) {
        _queueInput(deviceId, {'type': 'text', 'text': character});
        return KeyEventResult.handled;
      }
    }

    return KeyEventResult.ignored;
  }

  static bool _isPrintable(String character) {
    final code = character.runes.first;
    // Above the C0 control range and not DEL — a control character arrives with
    // a non-null `character` on some layouts and must not be typed as text.
    return code >= 0x20 && code != 0x7F;
  }

  /// Pool one event and arrange for it to be sent — immediately if the buffer
  /// is full, otherwise on a short debounce so a burst of moves leaves as one
  /// batch. Guards the identity up front so nothing is ever queued for a device
  /// this tab is no longer controlling.
  void _queueInput(String deviceId, Map<String, Object?> event) {
    if (!_control || deviceId != _selectedDeviceId) return;

    _pendingInput.add(event);

    if (_pendingInput.length >= _flushThreshold) {
      unawaited(_flushInput(deviceId));
      return;
    }

    _flushTimer?.cancel();
    _flushTimer = Timer(_flushDebounce, () => unawaited(_flushInput(deviceId)));
  }

  Future<void> _flushInput(String deviceId) async {
    _flushTimer?.cancel();
    _flushTimer = null;

    // Re-checked here, not just at queue time: control may have been released
    // while the debounce was pending. Drop the buffer rather than posting input
    // to a machine nobody is driving any more.
    if (!_control || deviceId != _selectedDeviceId || _pendingInput.isEmpty) {
      _pendingInput.clear();
      return;
    }

    final batch = List<Map<String, Object?>>.from(_pendingInput);
    _pendingInput.clear();

    await widget.api.post('/monitoring/devices/$deviceId/input', {
      'events': batch,
    });
  }

  @override
  Widget build(BuildContext context) {
    if (!_canView) {
      return NoteCard(DesktopMonitoringTab.noPermissionNote);
    }

    return AsyncBody(
      loading: _loadingDevices,
      error: _devicesError,
      onRetry: _loadDevices,
      child: _content(context),
    );
  }

  Widget _content(BuildContext context) {
    if (_devices.isEmpty) {
      return NoteCard(DesktopMonitoringTab.noDevicesNote);
    }

    final people = groupByPerson(_devices);
    final device = _deviceById(_selectedDeviceId) ?? mostRecentlySeen(_devices)!;
    final selectedPersonId = (device['user'] as Map<String, dynamic>?)?['id'] as String?;
    final selectedPerson = _personById(people, selectedPersonId);

    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth > DesktopMonitoringTab.wideAbove;
        final capturePane = _buildCapturePane(device, selectedPerson);

        if (wide) {
          return Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SizedBox(
                  width: 240,
                  child: _PeopleRail(
                    key: const Key('desktop-monitoring-picker'),
                    people: people,
                    selectedPersonId: selectedPersonId,
                    isOnline: isDeviceOnline,
                    onSelect: _selectPerson,
                  ),
                ),
                const SizedBox(width: 16),
                Expanded(child: SingleChildScrollView(child: capturePane)),
              ],
            ),
          );
        }

        return SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _PeopleChipRow(
                key: const Key('desktop-monitoring-picker'),
                people: people,
                selectedPersonId: selectedPersonId,
                isOnline: isDeviceOnline,
                onSelect: _selectPerson,
              ),
              const SizedBox(height: 12),
              capturePane,
            ],
          ),
        );
      },
    );
  }

  Widget _buildCapturePane(Map<String, dynamic> device, MonitoringPerson? person) {
    final canCapture = _declaresScreenshot(device);
    final online = isDeviceOnline(device);

    final newest = _shots.isEmpty ? null : _shots.first;
    final newestAt = newest == null
        ? null
        : DateTime.tryParse(newest['captured_at'] as String? ?? '');

    // "Stopped" is a FAULT: this device is beating right now and still not
    // delivering. Being offline is not that — a laptop that was shut ten
    // minutes ago has a perfectly healthy last capture, and calling it
    // "capture stopped 3m ago" in red reported a bug that did not exist. The
    // two facts are now shown as two facts. (`!online` used to be OR-ed into
    // this, which is why every offline device read as broken.)
    final stopped = canCapture &&
        online &&
        newestAt != null &&
        DateTime.now().toUtc().difference(newestAt.toUtc()) >
            DesktopMonitoringTab.captureInterval * 2;

    final captureError = _captureError(device);

    final hero = _heroId == null
        ? null
        : (_shots.where((s) => s['id'] == _heroId).firstOrNull ?? _shots.firstOrNull);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // More than one recent capture device for the selected person — an
        // inline chooser, not a second modal, right under their selection.
        if (person != null && person.devices.length > 1)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: _DeviceChooser(
              devices: person.devices,
              selectedId: device['id'] as String?,
              onSelect: _selectDevice,
            ),
          ),
        if (canCapture)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _LiveToggle(
                  live: _live,
                  onToggle: () => _toggleLive(device['id'] as String),
                ),
                // "Take control" only when the role holds monitoring.control
                // AND the selected device can actually be captured — you drive
                // over the live feed, so there is nothing to control without
                // one. Taking control auto-starts live (see `_startControl`).
                if (_canControl)
                  _ControlToggle(
                    controlling: _control,
                    onToggle: () => _toggleControl(device['id'] as String),
                  ),
              ],
            ),
          ),
        if (canCapture && newestAt != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 14),
            child: _FreshnessLine(
              stopped: stopped,
              offline: !online,
              ago: relativeAge(newestAt),
            ),
          ),
        // Why the last attempt produced nothing, straight from the device.
        // Shown whenever the machine reported one, including next to a
        // healthy-looking filmstrip — a device that captured fine an hour ago
        // and is failing NOW is exactly the case the old silence hid.
        if (canCapture && captureError != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 14),
            child: NoteCard(captureError),
          ),
        if (!canCapture)
          NoteCard(DesktopMonitoringTab.noAgentNote)
        else if (_live)
          _buildLive(device)
        else if (_loadingShots)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 24),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (_shotsError != null)
          ErrorNote(_shotsError!)
        else if (_shots.isEmpty || hero == null)
          // The reason (when there is one) is already shown above, so this
          // does not repeat it — it just stops claiming nothing is wrong.
          NoteCard(captureError == null
              ? DesktopMonitoringTab.noCapturesYetNote
              : DesktopMonitoringTab.noCapturesFailingNote)
        else ...[
          _Hero(
            shot: hero,
            bytes: _bytes[hero['id']],
            stopped: stopped,
            offline: !online,
            newestAgo: newestAt == null ? '' : relativeAge(newestAt),
          ),
          const SizedBox(height: 12),
          _Filmstrip(
            shots: _shots,
            heroId: _heroId,
            bytes: _bytes,
            onSelect: (id) => setState(() => _heroId = id),
          ),
        ],
      ],
    );
  }

  /// The live hero, plus — while controlling — the pointer/keyboard capture
  /// layer wrapped around it and a hint under it. When not controlling this is
  /// just the plain [_LiveHero], so `Go live` without `Take control` behaves
  /// exactly as it did before Part B.
  Widget _buildLive(Map<String, dynamic> device) {
    final deviceId = device['id'] as String;

    final hero = _LiveHero(
      bytes: _liveBytes,
      waiting: _liveWaiting,
      aspect: _liveAspect,
      controlling: _control,
      imageKey: _liveImageKey,
    );

    if (!_control) return hero;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Focus for keys, Listener for pointer. `autofocus` so keystrokes are
        // captured the instant control starts, without a click to focus first.
        Focus(
          key: const Key('desktop-monitoring-control-surface'),
          autofocus: true,
          onKeyEvent: (_, event) => _onKey(deviceId, event),
          child: Listener(
            onPointerDown: (event) => _onPointerDown(deviceId, event),
            onPointerMove: (event) => _onPointerMove(deviceId, event),
            onPointerHover: (event) => _onPointerMove(deviceId, event),
            onPointerUp: (event) => _onPointerUp(deviceId, event),
            onPointerSignal: (event) => _onPointerSignal(deviceId, event),
            child: hero,
          ),
        ),
        const SizedBox(height: 8),
        _ControlHint(),
      ],
    );
  }
}

bool _declaresScreenshot(Map<String, dynamic> device) =>
    (device['capabilities'] as List<dynamic>? ?? const [])
        .cast<String>()
        .contains('screenshot');

/// The device's own reason its last capture produced nothing, as a sentence,
/// or null when the last attempt succeeded (or predates this reporting).
///
/// The slug vocabulary is fixed on both sides — the native capture names the
/// failing step (windows/runner/monitoring_screenshot.cpp), the client adds
/// `upload_<status>` for an upload the server refused, and
/// HeartbeatController::CAPTURE_REASON refuses anything outside the set, so
/// this switch is exhaustive over what can actually be stored. An unknown
/// slug still gets a sentence rather than an empty box: a future agent build
/// reporting a reason this client has never heard of must not read as "no
/// problem".
String? _captureError(Map<String, dynamic> device) {
  final capture = device['capture'];
  if (capture is! Map) return null;

  final slug = capture['last_error'] as String?;
  if (slug == null || slug.isEmpty) return null;

  return switch (slug) {
    // Not a fault at all, which is why it does not read like one: a locked
    // machine is a person at lunch, and capturing through the lock screen is
    // not something to fix.
    'screen_locked' =>
      'The screen is locked, so captures are paused until it is unlocked.',
    'jpeg_encoder_missing' =>
      "This copy of Windows has no JPEG encoder, so captures can't be saved. "
          'Installing the Media Feature Pack (needed on Windows N editions) '
          'fixes it.',
    'com_unavailable' =>
      'Windows would not start the imaging service this device needs to save '
          'a capture.',
    'no_screen' => 'No display was found on this device.',
    'gdi_failed' || 'blit_failed' || 'pixels_unreadable' =>
      'Windows refused to hand over the screen contents on this device.',
    'encode_failed' || 'empty_capture' =>
      'The screen was read but could not be saved as an image.',
    'channel_missing' => "This build can't capture the screen.",
    'upload_403' =>
      'The server refused the capture: this device is exempt from screen '
          'monitoring.',
    'upload_404' =>
      'The server does not recognise this device yet. It should right itself '
          'within a minute or two.',
    'upload_413' || 'upload_422' =>
      'The capture was rejected as too large or unreadable.',
    'capture_failed' => 'The capture failed on the device.',
    _ when slug.startsWith('upload_') =>
      'The server refused the capture (status ${slug.substring(7)}).',
    // Deliberately shows the slug: an unrecognised reason is still a reason,
    // and a support conversation can work with the word.
    _ => 'The device could not capture its screen ($slug).',
  };
}

String _freshLabel(String ago) => 'last capture $ago';

/// The neutral sentence for a device that is simply not on right now. This is
/// NOT [_stoppedLabel] — nothing is wrong with a machine that was shut down,
/// and saying "capture stopped" about one manufactured bug reports.
String _offlineLabel(String ago) => 'last capture $ago · device offline';

/// The one sentence for "the stream is not live right now" — shared between
/// the freshness line and the hero's own badge so the two can never say it
/// two different ways.
String _stoppedLabel(String ago) => 'capture stopped $ago';

String _clockTime(DateTime at) {
  final local = at.toLocal();
  final hour24 = local.hour;
  final hour12 = hour24 % 12 == 0 ? 12 : hour24 % 12;
  final minute = local.minute.toString().padLeft(2, '0');
  final meridiem = hour24 < 12 ? 'AM' : 'PM';
  return '$hour12:$minute $meridiem';
}

MonitoringPerson? _personById(List<MonitoringPerson> people, String? id) {
  if (id == null) return null;
  for (final person in people) {
    if (person.id == id) return person;
  }
  return null;
}

/// The wide layout's people picker: a search field above a scrollable list —
/// always on screen, never a dropdown or a dialog. Owns its own search text
/// (rather than lifting it to the tab's state) so switching the selected
/// device — a rebuild from above — never clears what somebody typed.
class _PeopleRail extends StatefulWidget {
  const _PeopleRail({
    super.key,
    required this.people,
    required this.selectedPersonId,
    required this.isOnline,
    required this.onSelect,
  });

  final List<MonitoringPerson> people;
  final String? selectedPersonId;
  final bool Function(Map<String, dynamic>) isOnline;
  final ValueChanged<MonitoringPerson> onSelect;

  @override
  State<_PeopleRail> createState() => _PeopleRailState();
}

class _PeopleRailState extends State<_PeopleRail> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final query = _query.trim().toLowerCase();
    final filtered = query.isEmpty
        ? widget.people
        : widget.people.where((p) => p.name.toLowerCase().contains(query)).toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ListSearch(
          key: const Key('desktop-monitoring-people-search'),
          hint: 'Search people',
          onChanged: (value) => setState(() => _query = value),
        ),
        const SizedBox(height: 8),
        Expanded(
          child: filtered.isEmpty
              ? Center(
                  child: Text('No matches.', style: theme.textTheme.bodyMedium),
                )
              : ListView(
                  children: [
                    for (final person in filtered)
                      _PersonListTile(
                        person: person,
                        device: mostRecentlySeen(person.devices)!,
                        selected: person.id == widget.selectedPersonId,
                        isOnline: widget.isOnline,
                        onTap: () => widget.onSelect(person),
                      ),
                  ],
                ),
        ),
      ],
    );
  }
}

class _PersonListTile extends StatelessWidget {
  const _PersonListTile({
    required this.person,
    required this.device,
    required this.selected,
    required this.isOnline,
    required this.onTap,
  });

  final MonitoringPerson person;
  final Map<String, dynamic> device;
  final bool selected;
  final bool Function(Map<String, dynamic>) isOnline;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(8),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        decoration: BoxDecoration(
          color: selected ? scheme.primaryContainer : null,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    person.name,
                    style: theme.textTheme.titleSmall,
                    overflow: TextOverflow.ellipsis,
                  ),
                  Text(
                    friendlyDeviceName(device),
                    style: theme.textTheme.bodySmall
                        ?.copyWith(color: scheme.onSurfaceVariant),
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            StatusDot(online: isOnline(device)),
          ],
        ),
      ),
    );
  }
}

/// The narrow layout's people picker — a horizontal, scrollable chip row
/// above the capture, never a dropdown or a menu. Scrolls sideways rather
/// than wrapping, same reasoning as [_Filmstrip]: frames (here, names) stay a
/// legible size at 375px instead of shrinking to fit.
class _PeopleChipRow extends StatelessWidget {
  const _PeopleChipRow({
    super.key,
    required this.people,
    required this.selectedPersonId,
    required this.isOnline,
    required this.onSelect,
  });

  final List<MonitoringPerson> people;
  final String? selectedPersonId;
  final bool Function(Map<String, dynamic>) isOnline;
  final ValueChanged<MonitoringPerson> onSelect;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 40,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        itemCount: people.length,
        separatorBuilder: (_, _) => const SizedBox(width: 8),
        itemBuilder: (context, i) {
          final person = people[i];
          return ChoiceChip(
            label: Text(person.name),
            selected: person.id == selectedPersonId,
            onSelected: (_) => onSelect(person),
          );
        },
      ),
    );
  }
}

/// A person with more than one recent capture device — pick among them right
/// here, inline, as chips. Never a modal.
class _DeviceChooser extends StatelessWidget {
  const _DeviceChooser({
    required this.devices,
    required this.selectedId,
    required this.onSelect,
  });

  final List<Map<String, dynamic>> devices;
  final String? selectedId;
  final ValueChanged<String> onSelect;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 8,
      runSpacing: 4,
      children: [
        for (final device in devices)
          ChoiceChip(
            label: Text(friendlyDeviceName(device)),
            selected: device['id'] == selectedId,
            onSelected: (_) => onSelect(device['id'] as String),
          ),
      ],
    );
  }
}

class _FreshnessLine extends StatelessWidget {
  const _FreshnessLine({
    required this.stopped,
    required this.offline,
    required this.ago,
  });

  /// A real fault: beating, capture-capable, and still not delivering.
  final bool stopped;

  /// Merely not on. Rendered plainly — see [_offlineLabel].
  final bool offline;
  final String ago;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final text = stopped
        ? _stoppedLabel(ago)
        : offline
            ? _offlineLabel(ago)
            : _freshLabel(ago);

    return Text(
      text,
      style: theme.textTheme.bodyMedium?.copyWith(
        color: stopped ? theme.colorScheme.error : null,
        fontWeight: stopped ? FontWeight.w600 : null,
      ),
    );
  }
}

/// "Go live" / "Stop" — shown above the hero for any capture-capable
/// selected device (2026-09-02-monitoring-live-view, Part B). A plain button,
/// never a modal, matching every other control in this tab.
class _LiveToggle extends StatelessWidget {
  const _LiveToggle({required this.live, required this.onToggle});

  final bool live;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context) {
    return OutlinedButton.icon(
      key: const Key('desktop-monitoring-live-toggle'),
      onPressed: onToggle,
      icon: Icon(live ? Icons.stop_circle_outlined : Icons.sensors),
      label: Text(live ? 'Stop' : 'Go live'),
    );
  }
}

/// "Take control" / "Release" (2026-09-02-monitoring-remote-control, Part B).
/// Shown beside [_LiveToggle] only when the role holds monitoring.control and
/// the selected device is capture-capable. A filled button rather than an
/// outlined one, so driving a remote machine never reads as an idle secondary
/// action sitting next to "Go live".
class _ControlToggle extends StatelessWidget {
  const _ControlToggle({required this.controlling, required this.onToggle});

  final bool controlling;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context) {
    return FilledButton.icon(
      key: const Key('desktop-monitoring-control-toggle'),
      onPressed: onToggle,
      icon: Icon(controlling ? Icons.pan_tool_alt : Icons.mouse),
      label: Text(controlling ? 'Release' : 'Take control'),
    );
  }
}

/// The plain-language warning under the hero while controlling — the badge on
/// the frame says the state, this says what it MEANS, so nobody mistakes a
/// live view they can click into for a passive one.
class _ControlHint extends StatelessWidget {
  const _ControlHint();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Text(
      key: const Key('desktop-monitoring-control-hint'),
      'You are driving this machine — your mouse and keyboard are going to the '
      'remote screen.',
      style: theme.textTheme.bodySmall
          ?.copyWith(color: theme.colorScheme.primary),
    );
  }
}

/// The latest (or tapped) capture, large — never a modal, just the top of
/// this scrolling tab.
class _Hero extends StatelessWidget {
  const _Hero({
    required this.shot,
    required this.bytes,
    required this.stopped,
    required this.offline,
    required this.newestAgo,
  });

  final Map<String, dynamic> shot;
  final Uint8List? bytes;

  /// Whether the DEVICE is at fault — beating, capture-capable, and silent
  /// past twice the capture interval. Not "is this frame old".
  final bool stopped;

  /// Whether the device is simply not on. Kept SEPARATE from [stopped]
  /// because they need different words and different colours but the SAME
  /// badge: the honesty rule is about never passing an old frame off as
  /// current, and a frame from a machine that has been off for forty minutes
  /// is exactly as not-current as one from a machine that is broken. Tying
  /// the badge to [stopped] alone (which an earlier version of this did)
  /// silently removed the label from every offline device.
  final bool offline;

  /// The newest capture's age — the sentence is about the device, not about
  /// how old this exact frame is.
  final String newestAgo;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    final capturedAt = DateTime.tryParse(
      shot['captured_at'] as String? ?? '',
    )?.toLocal();
    final ownAgo = capturedAt == null ? '' : relativeAge(capturedAt);
    final timeText = capturedAt == null ? '' : _clockTime(capturedAt);

    final badgeText = stopped
        ? _stoppedLabel(newestAgo)
        : offline
            ? _offlineLabel(newestAgo)
            : (timeText.isEmpty ? ownAgo : '$timeText · $ownAgo');

    Widget image = bytes == null
        ? Center(
            child: Icon(Icons.image_outlined, color: scheme.onSurfaceVariant),
          )
        : Image.memory(bytes!, fit: BoxFit.cover);

    // "Greyed", per the honesty rule — a dimmed frame reads as not-current
    // at a glance, before anybody has to read the label to know it.
    if ((stopped || offline) && bytes != null) {
      image = Opacity(opacity: 0.5, child: image);
    }

    return Column(
      key: const Key('desktop-monitoring-hero'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        AspectRatio(
          aspectRatio: 16 / 9,
          child: ClipRRect(
            borderRadius: BorderRadius.circular(10),
            child: Stack(
              fit: StackFit.expand,
              children: [
                DecoratedBox(
                  decoration: BoxDecoration(color: scheme.surfaceContainerHighest),
                ),
                image,
                Positioned(
                  left: 8,
                  bottom: 8,
                  child: _Badge(
                    text: badgeText,
                    background: stopped ? scheme.errorContainer : scheme.inverseSurface,
                    foreground: stopped ? scheme.onErrorContainer : scheme.onInverseSurface,
                    // ponytail: offline deliberately shares the neutral pair —
                    // it is a fact, not a fault.
                  ),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 6),
        Text(
          '${shot['width']}×${shot['height']} · '
          '${((shot['bytes'] as num?) ?? 0) ~/ 1024} KB',
          style: theme.textTheme.bodyMedium,
        ),
      ],
    );
  }
}

/// The hero, while a live view is open — replaces [_Hero] entirely, per the
/// class doc comment on [DesktopMonitoringTab]'s honesty rule extended to
/// live frames: this must never keep showing an old frame as though it were
/// current. Shares the same [Key] as [_Hero] — the two are mutually
/// exclusive in `_buildCapturePane`, so "the hero" is one concept whichever
/// of the two is actually on screen.
class _LiveHero extends StatelessWidget {
  const _LiveHero({
    required this.bytes,
    required this.waiting,
    this.aspect,
    this.controlling = false,
    this.imageKey,
  });

  final Uint8List? bytes;

  /// The live frame's real width/height, once known — the hero matches it so
  /// pointer coordinates map straight onto the remote screen. Null (→ 16:9)
  /// until the first frame has been decoded.
  final double? aspect;

  /// True before the first frame has ever arrived, and again after two
  /// consecutive misses fetching `live-frame` — see `_fetchLiveFrame`.
  final bool waiting;

  /// Whether the manager is currently driving this machine — draws the
  /// CONTROLLING badge, so nobody forgets that their pointer and keyboard are
  /// landing on the remote screen.
  final bool controlling;

  /// Keys the actual image box so the viewer can measure it (RenderBox) for
  /// coordinate mapping — see `_normalized`. Null when not controlling, where
  /// there is nothing to measure.
  final GlobalKey? imageKey;

  static const String waitingText = 'Waiting for the live feed…';

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    final showFrame = !waiting && bytes != null;

    return Column(
      key: const Key('desktop-monitoring-hero'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        AspectRatio(
          key: imageKey,
          aspectRatio: aspect ?? 16 / 9,
          child: ClipRRect(
            borderRadius: BorderRadius.circular(10),
            child: Stack(
              fit: StackFit.expand,
              children: [
                DecoratedBox(
                  decoration: BoxDecoration(color: scheme.surfaceContainerHighest),
                ),
                if (showFrame)
                  Image.memory(bytes!, fit: BoxFit.contain, gaplessPlayback: true)
                else
                  Center(
                    child: Text(
                      waitingText,
                      style: theme.textTheme.bodyMedium
                          ?.copyWith(color: scheme.onSurfaceVariant),
                      textAlign: TextAlign.center,
                    ),
                  ),
                if (showFrame)
                  Positioned(
                    left: 8,
                    bottom: 8,
                    child: _Badge(
                      key: const Key('desktop-monitoring-live-badge'),
                      text: 'LIVE',
                      background: scheme.error,
                      foreground: scheme.onError,
                    ),
                  ),
                if (controlling)
                  Positioned(
                    left: 8,
                    top: 8,
                    child: _Badge(
                      key: const Key('desktop-monitoring-controlling-badge'),
                      text: 'CONTROLLING',
                      background: scheme.primary,
                      foreground: scheme.onPrimary,
                    ),
                  ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _Badge extends StatelessWidget {
  const _Badge({
    super.key,
    required this.text,
    required this.background,
    required this.foreground,
  });

  final String text;
  final Color background;
  final Color foreground;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: background.withValues(alpha: 0.85),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        text,
        style: Theme.of(context).textTheme.labelSmall?.copyWith(color: foreground),
      ),
    );
  }
}

/// A horizontal, scrollable strip of recent captures — tapping one makes it
/// the hero. Scrolls sideways rather than wrapping, so it works unchanged at
/// 375px (R7): the frames stay a legible size and the strip scrolls instead
/// of shrinking them to fit a phone's width.
class _Filmstrip extends StatelessWidget {
  const _Filmstrip({
    required this.shots,
    required this.heroId,
    required this.bytes,
    required this.onSelect,
  });

  final List<Map<String, dynamic>> shots;
  final String? heroId;
  final Map<String, Uint8List> bytes;
  final ValueChanged<String> onSelect;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return SizedBox(
      key: const Key('desktop-monitoring-filmstrip'),
      height: 84,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        itemCount: shots.length,
        separatorBuilder: (_, _) => const SizedBox(width: 8),
        itemBuilder: (context, i) {
          final shot = shots[i];
          final id = shot['id'] as String;
          final selected = id == heroId;
          final thumb = bytes[id];

          return GestureDetector(
            key: ValueKey('desktop-monitoring-thumb-$id'),
            onTap: () => onSelect(id),
            child: Container(
              width: 120,
              clipBehavior: Clip.antiAlias,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(8),
                border: Border.all(
                  color: selected ? scheme.primary : scheme.outlineVariant,
                  width: selected ? 2 : 1,
                ),
              ),
              child: thumb == null
                  ? DecoratedBox(
                      decoration: BoxDecoration(color: scheme.surfaceContainerHighest),
                      child: Center(
                        child: Icon(Icons.image_outlined, color: scheme.onSurfaceVariant),
                      ),
                    )
                  : Image.memory(thumb, fit: BoxFit.cover),
            ),
          );
        },
      ),
    );
  }
}
