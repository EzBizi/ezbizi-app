import 'dart:async';
import 'dart:math';

import 'package:flutter/foundation.dart';

import '../api_client.dart';
import '../device_key.dart';
import 'bridge_api.dart';
import 'bridge_store.dart';
import 'discovery.dart';
import 'engine.dart';

/// What the bridge-PC panel shows.
enum BridgeState { unpaired, running, trouble }

/// The camera bridge on a site's always-on PC (camera bridge, phase 1).
///
/// Runs on the site key alone, signed in or not: checks in every 60 s with
/// what it found and how each camera is doing, and long-polls for the cameras
/// somebody is watching, handing exactly those to the [Engine].
class CameraBridgeService {
  CameraBridgeService({
    required this.api,
    required this.store,
    required this.engine,
    Future<List<FoundDevice>> Function()? discover,
    Future<void> Function(Duration)? sleep,
  }) : _discover = discover ?? discoverOnvif,
       _sleep = sleep ?? Future<void>.delayed;

  final BridgeApi api;
  final BridgeStore store;
  final Engine engine;
  final Future<List<FoundDevice>> Function() _discover;
  final Future<void> Function(Duration) _sleep;

  /// For the bridge-PC panel (UI/UX).
  final ValueNotifier<BridgeState> state = ValueNotifier(BridgeState.running);

  bool _running = false;
  Timer? _checkins;
  List<FoundDevice> _found = const [];
  int _beats = 0;

  /// Pair this PC with a site: [code] from Monitoring › Settings › Camera
  /// sites, entered here by a manager who is signed in. Stores the key.
  static Future<String?> pair(
    ApiClient signedIn,
    BridgeStore store,
    String code,
  ) async {
    final r = await signedIn.post('/monitoring/bridge/pair', {
      'code': code.trim(),
      'device_key': await DeviceKey.value(),
    });
    final key = (r.body['data'] as Map?)?['site_key'] as String?;
    if (!r.ok || key == null) return r.reason;
    await store.saveSiteKey(key);
    return null;
  }

  Future<void> start() async {
    if (_running) return;
    _running = true;
    state.value = BridgeState.running;
    unawaited(checkin());
    _checkins = Timer.periodic(const Duration(seconds: 60), (_) => checkin());
    unawaited(_workLoop());
  }

  void stop() {
    _running = false;
    _checkins?.cancel();
    engine.stop();
  }

  /// One check-in. Re-scans the network on the first and every 10th.
  Future<void> checkin() async {
    try {
      if (_beats++ % 10 == 0) {
        try {
          _found = await _discover();
        } catch (_) {}
      }
      await api.checkin({
        'engine_version': engine.version,
        'found': [for (final f in _found) f.toJson()],
        'cameras': await engine.health(),
      });
      if (_running) state.value = BridgeState.running;
    } on BridgeUnpaired {
      await _unpair();
    } catch (_) {
      if (_running) state.value = BridgeState.trouble;
    }
  }

  Future<void> _workLoop() async {
    var since = -1;
    var delay = const Duration(seconds: 1);
    while (_running) {
      try {
        final w = await api.work(since);
        since = w.version;
        await engine.apply(w.wanted, await store.recorder());
        delay = const Duration(seconds: 1);
      } on BridgeUnpaired {
        await _unpair();
        return;
      } catch (_) {
        if (!_running) return;
        state.value = BridgeState.trouble;
        await _sleep(delay); // never spin on a dead network
        delay = Duration(seconds: min(delay.inSeconds * 2, 30));
      }
    }
  }

  Future<void> _unpair() async {
    stop();
    await store.clearSiteKey();
    state.value = BridgeState.unpaired;
  }
}
