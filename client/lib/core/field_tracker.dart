// Field: the phone half of GPS tracking.
// docs/superpowers/specs/2026-09-25-field-module-design.md
//
// The phone is told, never trusted. It asks /field/me whether to share, and
// the server refuses any point outside the tracking window whatever this
// file does. Everything that turns points into facts (arrived, stopped,
// lost signal) is on the server, so this stays small.
//
// Background: Android runs the stream as a foreground service with a
// visible notification; iOS keeps updating in the background with the blue
// indicator showing. Both need only "while using the app" permission,
// because the stream is always STARTED while the app is open.

import 'dart:async';

import 'package:battery_plus/battery_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:geolocator/geolocator.dart';

import 'api_client.dart';
import 'build_info.dart';
import 'client_platform.dart';
import 'field_os.dart';
import 'field_recorder.dart';
import 'session.dart';

class FieldTracker {
  FieldTracker._(this._api, this._session) : recorder = FieldRecorder(_api) {
    current = this;
    unawaited(recorder.recover());
    _session.addListener(_sync);
    _resumed = AppLifecycleListener(onResume: _sync);
    _poll = Timer.periodic(const Duration(minutes: 2), (_) => _sync());
    _sync();
  }

  /// Android and iOS only. A desktop or a browser is not in a van, and the
  /// macOS review notes promise that app never asks for location.
  static bool get onPhone =>
      !kIsWeb &&
      (defaultTargetPlatform == TargetPlatform.android ||
          defaultTargetPlatform == TargetPlatform.iOS);

  /// The running tracker on a phone, for the screens that show its state.
  static FieldTracker? current;

  /// Safety recordings (field.record), on the same phone as the tracker.
  final FieldRecorder recorder;

  /// The last /field/me answer: whether sharing is on, and the job the phone
  /// is standing at, which the record control offers.
  final me = ValueNotifier<Map<String, dynamic>?>(null);

  static FieldTracker? startIfPhone(ApiClient api, Session session) =>
      onPhone ? FieldTracker._(api, session) : null;

  /// Where this phone is now, asking for permission if need be. Null when
  /// refused or unavailable. Phones only: see [onPhone].
  static Future<Position?> currentPosition() async {
    try {
      if (!await _permitted()) return null;

      return await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 20),
        ),
      );
    } catch (_) {
      return null;
    }
  }

  static bool _asked = false;

  /// Asks once per run, and only on screen: a prompt raised from the
  /// background brings the app to the front over the browser and strands an
  /// OAuth sign-in (Android, 2026-10-09). A refusal means no tracking.
  static Future<bool> _permitted() async {
    if (!await Geolocator.isLocationServiceEnabled()) return false;
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied &&
        !_asked &&
        WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed) {
      _asked = true;
      permission = await Geolocator.requestPermission();
    }

    return permission == LocationPermission.whileInUse ||
        permission == LocationPermission.always;
  }

  final ApiClient _api;
  final Session _session;
  late final Timer _poll;

  StreamSubscription<Position>? _stream;
  Timer? _flush;
  DateTime? _lastPointAt;
  final List<Map<String, dynamic>> _buffer = [];
  bool _syncing = false;
  bool _sending = false;

  /// Android only: Play services' fused provider refuses to start while the
  /// phone's "Google Location Accuracy" is off, with no dialog in a
  /// foreground service. Measured by the Android session on 0.0.0290: the
  /// stream died 30 ms after starting, forever. The platform LocationManager
  /// has no such check, so a failed start retries on it.
  bool _platformProvider = false;

  /// What doesn't change while the app runs: model and OS, read once.
  Map<String, Object?>? _phone;

  /// The phone describing itself with each upload, for the technician bar on
  /// the map (Jose, 2026-09-30). Anything a platform will not say is left
  /// out, never guessed.
  Future<Map<String, Object?>> _device() async {
    _phone ??= await _describePhone();
    final device = <String, Object?>{
      ...?_phone,
      'platform': ClientPlatform.name,
      'app_version': BuildInfo.version,
    };
    try {
      final battery = Battery();
      device['battery_pct'] = await battery.batteryLevel;
      final state = await battery.batteryState;
      if (state != BatteryState.unknown) {
        device['charging'] =
            state == BatteryState.charging || state == BatteryState.full;
      }
    } catch (_) {
      // No battery reading on this phone; the bar shows a dash.
    }
    return device;
  }

  static Future<Map<String, Object?>> _describePhone() async => {
    'os_version': fieldOsVersion(),
  };

  /// Most points a phone holds while offline. A day at 50 m spacing fits.
  static const _cap = 2000;

  Future<void> _sync() async {
    if (_syncing) return;
    _syncing = true;
    try {
      final grants = _session.me?.permissions ?? const <String>{};
      final tracked = grants.contains('field.tracked');
      var share = false;
      if (tracked || grants.contains('field.record')) {
        final response = await _api.get('/field/me');
        final data = response.ok
            ? response.body['data'] as Map<String, dynamic>?
            : null;
        if (data != null) me.value = data;
        share = tracked && data?['should_track'] == true;
        // Leaving the job a recording was started at stops it.
        final recordingAt = recorder.state.value.jobId;
        if (data != null &&
            recorder.active &&
            recordingAt != null &&
            (data['at_job'] as Map?)?['id'] != recordingAt) {
          await recorder.stop('left_job');
        }
      }
      share ? await _start() : await _stop();
    } finally {
      _syncing = false;
    }
  }

  Future<void> _start() async {
    if (_stream != null) {
      // Standing still sends nothing through a distance filter, and the
      // server reads silence as lost signal. Two minutes still = one point.
      final last = _lastPointAt;
      if (last == null || DateTime.now().difference(last).inSeconds > 110) {
        final here = await currentPosition();
        if (here != null) _add(here);
      }
      return;
    }
    // Android 12+ refuses to start a foreground service from the
    // background (prod, 0.0.0461: "startForeground() not allowed"). Start
    // only on screen; coming back to the app runs this again.
    if (defaultTargetPlatform == TargetPlatform.android &&
        WidgetsBinding.instance.lifecycleState != AppLifecycleState.resumed) {
      return;
    }
    if (!await _permitted()) return;

    final settings = switch (defaultTargetPlatform) {
      TargetPlatform.android => AndroidSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 50,
        forceLocationManager: _platformProvider,
        foregroundNotificationConfig: const ForegroundNotificationConfig(
          notificationTitle: 'EzBizi is sharing your location',
          notificationText:
              'Your organization sees where you are while you are on the clock.',
          enableWakeLock: true,
          setOngoing: true,
        ),
      ),
      _ => AppleSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 50,
        activityType: ActivityType.automotiveNavigation,
        allowBackgroundLocationUpdates: true,
        showBackgroundLocationIndicator: true,
        pauseLocationUpdatesAutomatically: false,
      ),
    };

    _stream = Geolocator.getPositionStream(
      locationSettings: settings,
    ).listen(_add, onError: _failed);
    _flush = Timer.periodic(const Duration(seconds: 60), (_) => _send());
  }

  Future<void> _failed(Object error) async {
    debugPrint('FieldTracker: position stream failed: $error');
    await _stream?.cancel();
    _stream = null;
    _flush?.cancel();
    _flush = null;
    // Refused because the app was not on screen: not a provider problem.
    // Leave it; the next resume starts it.
    if ('$error'.contains('startForeground')) return;
    if (defaultTargetPlatform == TargetPlatform.android && !_platformProvider) {
      _platformProvider = true;
      await _start();
      return;
    }
    await _send();
  }

  Future<void> _stop() async {
    await _stream?.cancel();
    _stream = null;
    _flush?.cancel();
    _flush = null;
    await _send();
  }

  void _add(Position p) {
    _lastPointAt = DateTime.now();
    _buffer.add({
      'lat': p.latitude,
      'lng': p.longitude,
      'recorded_at': p.timestamp.toUtc().toIso8601String(),
      'accuracy_m': p.accuracy.isFinite ? p.accuracy.round() : null,
      'speed_mps': p.speed.isFinite && p.speed >= 0 ? p.speed : null,
      'heading': p.heading.isFinite && p.heading >= 0
          ? p.heading.round() % 361
          : null,
    });
    if (_buffer.length > _cap) _buffer.removeRange(0, _buffer.length - _cap);
    if (_buffer.length >= 20) _send();
  }

  Future<void> _send() async {
    // One upload at a time, or two callers post the same batch.
    if (_sending) return;
    _sending = true;
    try {
      await _drain();
    } finally {
      _sending = false;
    }
  }

  Future<void> _drain() async {
    while (_buffer.isNotEmpty) {
      final batch = _buffer.take(100).toList();
      final response = await _api.post('/field/locations', {
        'points': batch,
        'device': await _device(),
      });
      // Anything but a network failure is final: a 422 on a stale point
      // would otherwise be retried for ever.
      if (response.statusCode == 0 || response.statusCode >= 500) return;
      _buffer.removeRange(0, batch.length);
      final status = (response.body['data'] as Map?)?['status'] as Map?;
      if (status != null && status['should_track'] != true) {
        // Clocked out, or the window closed: stop now, not at the next poll.
        await _stream?.cancel();
        _stream = null;
        _flush?.cancel();
        _flush = null;
      }
    }
  }

  late final AppLifecycleListener _resumed;

  void dispose() {
    _resumed.dispose();
    // Signing out never leaves a microphone running.
    if (recorder.active) unawaited(recorder.stop('done'));
    recorder.dispose();
    if (current == this) current = null;
    _session.removeListener(_sync);
    _poll.cancel();
    _flush?.cancel();
    _stream?.cancel();
  }
}
