import 'dart:async';

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../core/theme.dart';
import '../../widgets/async_body.dart';
import '../../widgets/error_note.dart';
import 'device_display.dart';

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

  /// A device silent longer than this draws as offline — same threshold and
  /// same reasoning as PcSpecsTab.onlineWithin (this tab has its own copy
  /// rather than importing that one, for the same reason PcSpecsTab's own
  /// `_seenAgo` is not shared: independent knobs that happen to agree today).
  static const Duration onlineWithin = Duration(minutes: 5);

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
    if (_canView) _loadDevices();
  }

  @override
  void dispose() {
    _watchTimer?.cancel();
    _frameTimer?.cancel();
    _controlTimer?.cancel();
    _flushTimer?.cancel();
    super.dispose();
  }

  Future<void> _loadDevices() async {
    setState(() {
      _loadingDevices = _devices.isEmpty;
      _devicesError = null;
    });

    final response = await widget.api.get('/monitoring/devices');

    if (!mounted) return;

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
        final fallback = _mostRecentCaptureDevice(devices);
        _selectedDeviceId = fallback == null ? null : fallback['id'] as String?;
      }
    });

    final deviceId = _selectedDeviceId;
    if (deviceId != null) await _loadShots(deviceId);
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
  void _selectPerson(_Person person) {
    final target = _mostRecentCaptureDevice(person.devices);
    if (target != null) _selectDevice(target['id'] as String);
  }

  Future<void> _loadShots(String deviceId) async {
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

    setState(() {
      _loadingShots = true;
      _shotsError = null;
    });

    final response = await widget.api.get('/monitoring/screenshots', {
      'device_id': deviceId,
      'limit': '${DesktopMonitoringTab.filmstripLimit}',
    });

    // A newer fetch (another switch, or a refresh) started while this one
    // was in flight — its answer, whenever it lands, is not allowed to
    // touch state a newer selection already owns.
    if (!mounted || generation != _loadGeneration) return;

    if (!response.ok) {
      setState(() {
        _loadingShots = false;
        _shotsError = response.message;
      });
      return;
    }

    final shots = (response.data as List<dynamic>).cast<Map<String, dynamic>>();

    setState(() {
      _loadingShots = false;
      _shots = shots;
      _heroId = shots.isEmpty ? null : shots.first['id'] as String?;
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

    _liveMisses = 0;
    setState(() {
      _liveBytes = Uint8List.fromList(bytes);
      _liveWaiting = false;
    });
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

  /// A subset of keys, mapped to Windows virtual-key codes. Modifiers and
  /// hotkeys (Ctrl/Alt combos) are deliberately out of v1 scope — see
  /// `_onKey`'s ponytail note.
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

  KeyEventResult _onKey(String deviceId, KeyEvent event) {
    if (!_control || deviceId != _selectedDeviceId) {
      return KeyEventResult.ignored;
    }

    // Special keys → VK down/up events. A repeat counts as a press held.
    final vk = _virtualKeyFor(event.logicalKey);
    if (vk != null) {
      if (event is KeyUpEvent) {
        _queueInput(deviceId, {'type': 'key', 'code': vk, 'down': false});
      } else {
        _queueInput(deviceId, {'type': 'key', 'code': vk, 'down': true});
      }
      return KeyEventResult.handled;
    }

    // ponytail: v1 covers plain typing and the common navigation/editing keys.
    // Full modifier and hotkey support — Ctrl+C, Alt+Tab, chorded shortcuts —
    // is a v2: it needs the agent to hold modifier state across events, not
    // just replay a character, so it is a native-side change too, not a knob
    // here. Printable characters go through as a `text` event.
    if (event is KeyDownEvent || event is KeyRepeatEvent) {
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
      return _NoteCard(DesktopMonitoringTab.noPermissionNote);
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
      return _NoteCard(DesktopMonitoringTab.noDevicesNote);
    }

    final people = _groupByPerson(_devices);
    final device = _deviceById(_selectedDeviceId) ?? _mostRecentCaptureDevice(_devices)!;
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
                    isOnline: _isOnline,
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
                isOnline: _isOnline,
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

  Widget _buildCapturePane(Map<String, dynamic> device, _Person? person) {
    final canCapture = _declaresScreenshot(device);
    final online = _isOnline(device);

    final newest = _shots.isEmpty ? null : _shots.first;
    final newestAt = newest == null
        ? null
        : DateTime.tryParse(newest['captured_at'] as String? ?? '');

    final stopped = canCapture &&
        newestAt != null &&
        (DateTime.now().toUtc().difference(newestAt.toUtc()) >
                DesktopMonitoringTab.captureInterval * 2 ||
            !online);

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
            child: _FreshnessLine(stopped: stopped, ago: _relativeAge(newestAt)),
          ),
        if (!canCapture)
          _NoteCard(DesktopMonitoringTab.noAgentNote)
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
          _NoteCard(DesktopMonitoringTab.noCapturesYetNote)
        else ...[
          _Hero(
            shot: hero,
            bytes: _bytes[hero['id']],
            stopped: stopped,
            stoppedAgo: newestAt == null ? '' : _relativeAge(newestAt),
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

bool _isOnline(Map<String, dynamic> device) {
  final seenRaw = device['last_seen_at'] as String?;
  if (seenRaw == null) return false;
  final seen = DateTime.tryParse(seenRaw);
  if (seen == null) return false;
  return DateTime.now().toUtc().difference(seen.toUtc()) <=
      DesktopMonitoringTab.onlineWithin;
}

/// "just now" / "3m ago" / "2h ago" / "yesterday" / "5d ago" — this tab's own
/// copy of the same relative-time shape PcSpecsTab's `_seenAgo` and
/// leads_screen.dart each carry independently (see PcSpecsTab's doc comment
/// on why no shared helper exists for this).
String _relativeAge(DateTime at) {
  final ago = DateTime.now().difference(at.toLocal());
  if (ago.isNegative || ago.inMinutes < 1) return 'just now';
  if (ago.inMinutes < 60) return '${ago.inMinutes}m ago';
  if (ago.inHours < 24) return '${ago.inHours}h ago';
  if (ago.inHours < 48) return 'yesterday';
  return '${ago.inDays}d ago';
}

String _freshLabel(String ago) => 'last capture $ago';

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

/// One person and the capture-capable devices they are signed in from, in
/// the order the server returned them (most recently seen first — see
/// DevicesController::index).
class _Person {
  _Person(this.id, this.name);

  final String id;
  final String name;
  final List<Map<String, dynamic>> devices = [];
}

List<_Person> _groupByPerson(List<Map<String, dynamic>> devices) {
  final byId = <String, _Person>{};
  final order = <String>[];

  for (final device in devices) {
    final user = device['user'] as Map<String, dynamic>? ?? const {};
    final id = user['id'] as String? ?? 'unknown';
    final name = user['name'] as String? ?? 'Unknown';

    final person = byId.putIfAbsent(id, () {
      order.add(id);
      return _Person(id, name);
    });
    person.devices.add(device);
  }

  return [for (final id in order) byId[id]!];
}

_Person? _personById(List<_Person> people, String? id) {
  if (id == null) return null;
  for (final person in people) {
    if (person.id == id) return person;
  }
  return null;
}

/// The device to default to for a person or for the whole list — max by
/// `last_seen_at`. A missing or unparseable stamp sorts as the oldest
/// possible.
Map<String, dynamic>? _mostRecentCaptureDevice(List<Map<String, dynamic>> devices) {
  if (devices.isEmpty) return null;

  var best = devices.first;
  var bestSeen = DateTime.tryParse(best['last_seen_at'] as String? ?? '') ??
      DateTime.fromMillisecondsSinceEpoch(0);

  for (final device in devices.skip(1)) {
    final seen = DateTime.tryParse(device['last_seen_at'] as String? ?? '') ??
        DateTime.fromMillisecondsSinceEpoch(0);
    if (seen.isAfter(bestSeen)) {
      best = device;
      bestSeen = seen;
    }
  }

  return best;
}

class _NoteCard extends StatelessWidget {
  const _NoteCard(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Text(text, style: Theme.of(context).textTheme.bodyMedium),
      ),
    );
  }
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

  final List<_Person> people;
  final String? selectedPersonId;
  final bool Function(Map<String, dynamic>) isOnline;
  final ValueChanged<_Person> onSelect;

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
        TextField(
          decoration: const InputDecoration(
            hintText: 'Search people',
            prefixIcon: Icon(Icons.search),
          ),
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
                        device: _mostRecentCaptureDevice(person.devices)!,
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

  final _Person person;
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
            _StatusDot(online: isOnline(device)),
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

  final List<_Person> people;
  final String? selectedPersonId;
  final bool Function(Map<String, dynamic>) isOnline;
  final ValueChanged<_Person> onSelect;

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

class _StatusDot extends StatelessWidget {
  const _StatusDot({required this.online});

  final bool online;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 9,
      height: 9,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: online
            ? EzBiziTheme.clockIn(context)
            : Theme.of(context).colorScheme.outlineVariant,
      ),
    );
  }
}

class _FreshnessLine extends StatelessWidget {
  const _FreshnessLine({required this.stopped, required this.ago});

  final bool stopped;
  final String ago;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final text = stopped ? _stoppedLabel(ago) : _freshLabel(ago);

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
    required this.stoppedAgo,
  });

  final Map<String, dynamic> shot;
  final Uint8List? bytes;

  /// Whether the DEVICE's stream has stopped — not whether this particular
  /// frame is old. See the class doc comment on [DesktopMonitoringTab].
  final bool stopped;

  /// The newest capture's age, used only when [stopped] — the sentence is
  /// about the device going quiet, not about how old this exact frame is.
  final String stoppedAgo;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    final capturedAt = DateTime.tryParse(
      shot['captured_at'] as String? ?? '',
    )?.toLocal();
    final ownAgo = capturedAt == null ? '' : _relativeAge(capturedAt);
    final timeText = capturedAt == null ? '' : _clockTime(capturedAt);

    final badgeText = stopped
        ? _stoppedLabel(stoppedAgo)
        : (timeText.isEmpty ? ownAgo : '$timeText · $ownAgo');

    Widget image = bytes == null
        ? Center(
            child: Icon(Icons.image_outlined, color: scheme.onSurfaceVariant),
          )
        : Image.memory(bytes!, fit: BoxFit.cover);

    // "Greyed", per the honesty rule — a dimmed frame reads as not-current
    // at a glance, before anybody has to read the label to know it.
    if (stopped && bytes != null) {
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
    this.controlling = false,
    this.imageKey,
  });

  final Uint8List? bytes;

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
          aspectRatio: 16 / 9,
          child: ClipRRect(
            borderRadius: BorderRadius.circular(10),
            child: Stack(
              fit: StackFit.expand,
              children: [
                DecoratedBox(
                  decoration: BoxDecoration(color: scheme.surfaceContainerHighest),
                ),
                if (showFrame)
                  Image.memory(bytes!, fit: BoxFit.cover, gaplessPlayback: true)
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
