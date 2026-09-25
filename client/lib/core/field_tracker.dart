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

import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';

import 'api_client.dart';
import 'session.dart';

class FieldTracker {
  FieldTracker._(this._api, this._session) {
    _session.addListener(_sync);
    _poll = Timer.periodic(const Duration(minutes: 2), (_) => _sync());
    _sync();
  }

  /// Android and iOS only. A desktop or a browser is not in a van.
  static FieldTracker? startIfPhone(ApiClient api, Session session) {
    final phone =
        !kIsWeb &&
        (defaultTargetPlatform == TargetPlatform.android ||
            defaultTargetPlatform == TargetPlatform.iOS);

    return phone ? FieldTracker._(api, session) : null;
  }

  /// Where this device is now, asking for permission if need be. Null when
  /// refused or unavailable. Used by SOS on every platform.
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

  static Future<bool> _permitted() async {
    if (!await Geolocator.isLocationServiceEnabled()) return false;
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
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

  /// Most points a phone holds while offline. A day at 50 m spacing fits.
  static const _cap = 2000;

  Future<void> _sync() async {
    if (_syncing) return;
    _syncing = true;
    try {
      final tracked =
          _session.me?.permissions.contains('field.tracked') ?? false;
      var share = false;
      if (tracked) {
        final response = await _api.get('/field/me');
        share =
            response.ok &&
            (response.body['data'] as Map?)?['should_track'] == true;
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
    if (!await _permitted()) return;

    final settings = switch (defaultTargetPlatform) {
      TargetPlatform.android => AndroidSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 50,
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
    ).listen(_add, onError: (_) => _stop());
    _flush = Timer.periodic(const Duration(seconds: 60), (_) => _send());
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
      final response = await _api.post('/field/locations', {'points': batch});
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

  void dispose() {
    _session.removeListener(_sync);
    _poll.cancel();
    _flush?.cancel();
    _stream?.cancel();
  }
}
