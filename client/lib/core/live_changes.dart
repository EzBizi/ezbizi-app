/// The app-wide live feed (Jose, 2026-11-16: "The tab needs to be live
/// basically. This should go tbh for every module in the app").
///
/// One loop for the whole app asks `GET changes?since=` every [every] while
/// the app is in front and at least one screen listens. A screen subscribes
/// with the record types it shows and gets the ids that changed; it then
/// re-reads only those (or quietly reloads its page), so scroll, open panels
/// and edits stay put. A screen that is out of view (a kept tab) is not
/// called: its changes wait and arrive together when it is shown again.
library;

import 'dart:async';

import 'package:flutter/widgets.dart';

import 'api_client.dart';

/// What changed for one subscriber since it was last told.
class LiveBatch {
  const LiveBatch({
    this.changed = const {},
    this.deleted = const {},
    this.reload = false,
  });

  /// type → ids saved (created or edited).
  final Map<String, Set<String>> changed;

  /// type → ids deleted.
  final Map<String, Set<String>> deleted;

  /// Too much changed to list: re-read the screen.
  final bool reload;

  bool get isEmpty =>
      !reload &&
      changed.values.every((s) => s.isEmpty) &&
      deleted.values.every((s) => s.isEmpty);

  Set<String> changedOf(String type) => changed[type] ?? const {};
  Set<String> deletedOf(String type) => deleted[type] ?? const {};

  /// Every id of [type] that moved either way.
  Set<String> touched(String type) => {...changedOf(type), ...deletedOf(type)};
}

typedef LiveListener = FutureOr<void> Function(LiveBatch batch);

class LiveSubscription {
  LiveSubscription._(this._owner, this.types, this._listener, this._active);

  final LiveChanges _owner;
  final Set<String> types;
  final LiveListener _listener;
  final bool Function() _active;

  final Map<String, Set<String>> _changed = {};
  final Map<String, Set<String>> _deleted = {};
  bool _reload = false;
  bool _running = false;

  void cancel() => _owner._remove(this);

  void _add(String type, String id, bool deleted) {
    if (!types.contains(type)) return;
    final into = deleted ? _deleted : _changed;
    final other = deleted ? _changed : _deleted;
    other[type]?.remove(id);
    (into[type] ??= {}).add(id);
    if ((_changed[type]?.length ?? 0) + (_deleted[type]?.length ?? 0) > 500) {
      _reload = true;
    }
  }

  Future<void> _deliver() async {
    if (_running) return;
    bool active;
    try {
      active = _active();
    } catch (_) {
      active = false;
    }
    if (!active) return;
    final batch = LiveBatch(
      changed: {
        for (final e in _changed.entries) e.key: {...e.value},
      },
      deleted: {
        for (final e in _deleted.entries) e.key: {...e.value},
      },
      reload: _reload,
    );
    if (batch.isEmpty) return;
    _changed.clear();
    _deleted.clear();
    _reload = false;
    _running = true;
    try {
      await _listener(batch);
    } catch (_) {
      // A failed re-read is the screen's to show; the feed carries on.
    } finally {
      _running = false;
    }
  }
}

class LiveChanges with WidgetsBindingObserver {
  LiveChanges._(this.api);

  static final Expando<LiveChanges> _byApi = Expando('LiveChanges');

  /// The one feed for [api] (one per signed-in client).
  static LiveChanges of(ApiClient api) => _byApi[api] ??= LiveChanges._(api);

  /// How often the feed asks. Tests set it shorter.
  static Duration every = const Duration(seconds: 10);

  final ApiClient api;
  final List<LiveSubscription> _subs = [];
  Timer? _timer;
  int? _cursor;
  bool _inFront = true;
  bool _polling = false;
  bool _observing = false;

  /// Listen for [types] (`jobs`, `leads`, `customers`, `tasks`, `inventory`).
  /// [active] says whether the screen is in view; a screen out of view is
  /// told later, all at once. Cancel the subscription in dispose.
  LiveSubscription subscribe(
    Set<String> types,
    LiveListener listener, {
    bool Function()? active,
  }) {
    final sub = LiveSubscription._(this, types, listener, active ?? () => true);
    _subs.add(sub);
    _start();
    return sub;
  }

  /// A State's own "am I on screen": mounted, and its tickers on (a kept tab
  /// behind another is an Offstage with tickers off).
  static bool Function() visible(State state) =>
      () => state.mounted && TickerMode.valuesOf(state.context).enabled;

  void _remove(LiveSubscription sub) {
    _subs.remove(sub);
    if (_subs.isEmpty) _stop();
  }

  void _start() {
    if (!_observing) {
      _observing = true;
      WidgetsBinding.instance.addObserver(this);
    }
    _timer ??= Timer.periodic(every, (_) => poll());
    if (_cursor == null) unawaited(poll());
  }

  void _stop() {
    _timer?.cancel();
    _timer = null;
    if (_observing) {
      _observing = false;
      WidgetsBinding.instance.removeObserver(this);
    }
    // Next subscriber starts from "now" again.
    _cursor = null;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _inFront = state == AppLifecycleState.resumed;
    if (_inFront) unawaited(poll());
  }

  /// One read of the feed, handed to every subscriber. Public for tests.
  Future<void> poll() async {
    if (_polling || _subs.isEmpty) return;
    if (!_inFront) {
      return;
    }
    _polling = true;
    try {
      final types = {for (final s in _subs) ...s.types};
      final since = _cursor;
      final res = await api.get('/changes', {
        'types': (types.toList()..sort()).join(','),
        'since': ?since?.toString(),
      });
      if (!res.ok) return;
      final data = res.body['data'];
      if (data is! Map) return;
      final next = (data['cursor'] as num?)?.toInt();
      if (since != null) {
        if (data['reload'] == true) {
          for (final s in _subs) {
            s._reload = true;
          }
        }
        for (final c in (data['changes'] as List? ?? const [])) {
          if (c is! Map) continue;
          final type = '${c['type']}', id = '${c['id']}';
          for (final s in _subs) {
            s._add(type, id, c['deleted'] == true);
          }
        }
      }
      if (next != null) _cursor = next;
    } finally {
      _polling = false;
    }
    await Future.wait([
      for (final s in [..._subs]) s._deliver(),
    ]);
  }
}
