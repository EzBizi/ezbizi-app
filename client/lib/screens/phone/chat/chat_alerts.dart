// Chat's own OS notifications (Jose, 2026-11-27: "push notifications, as well
// as desktop notifications ... just like whatsapp").
//
// Phones get the server's push when the app is closed. This covers the rest:
// the desktop and web apps, and a phone app that is open on another screen,
// where the server sees the person online and sends no push. App-wide (mounted
// with the call layer), in EzBizi and in EzChat alike: a new message in a chat
// that is not the one on screen shows a native notification with sound, and
// clicking it opens that chat (`chat:{id}`, the push's own payload).

import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';

import '../../../core/api_client.dart';
import '../../../core/os_notifications.dart';

class ChatAlerts with WidgetsBindingObserver {
  ChatAlerts({this.every = const Duration(seconds: 15)});

  static final ChatAlerts instance = ChatAlerts();

  /// The chat on screen right now, set by the open thread; its messages make
  /// no notification while the app is in front.
  static String? visibleChat;

  /// The chat on screen while the app is in front, or null.
  static String? get viewing =>
      instance._state == AppLifecycleState.resumed ? visibleChat : null;

  final Duration every;
  ApiClient? _api;
  Timer? _timer;
  String? _rev;
  bool _busy = false;
  bool _primed = false;
  final Map<String, String?> _lastSeen = {};
  AppLifecycleState _state = AppLifecycleState.resumed;

  bool get started => _timer != null;

  void start(ApiClient api) {
    if (started) return;
    _api = api;
    WidgetsBinding.instance.addObserver(this);
    _timer = Timer.periodic(every, (_) => unawaited(poll()));
    unawaited(poll());
  }

  void stop() {
    // Signed out: the dock forgets their count.
    if (_shownBadge != null && _shownBadge != 0) unawaited(_badge(0));
    _timer?.cancel();
    _timer = null;
    WidgetsBinding.instance.removeObserver(this);
    _rev = null;
    _primed = false;
    _lastSeen.clear();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) => _state = state;

  /// One beat: the pulse, and only when it moved, the chat list.
  Future<void> poll() async {
    final api = _api;
    if (api == null || _busy) return;
    _busy = true;
    try {
      final p = await api.get('/chat/pulse');
      final rev = p.ok && p.data is Map
          ? (p.data as Map)['rev']?.toString()
          : null;
      if (rev == null || rev == _rev) return;
      final r = await api.get('/chat/chats');
      if (!r.ok || r.data is! List) return;
      _rev = rev;
      var unread = 0;
      for (final c in (r.data as List).whereType<Map>()) {
        _consider(Map<String, dynamic>.from(c));
        unread += ((c['unread'] as num?) ?? 0).toInt();
      }
      unawaited(_badge(unread));
      // The first read only learns what is already there: no burst at sign-in.
      _primed = true;
    } finally {
      _busy = false;
    }
  }

  /// The Mac's dock count and the Windows taskbar button's (11-27); phones
  /// take theirs from the push.
  static const _dock = MethodChannel('ezbizi/badge');
  int? _shownBadge;

  Future<void> _badge(int unread) async {
    if (kIsWeb ||
        (defaultTargetPlatform != TargetPlatform.macOS &&
            defaultTargetPlatform != TargetPlatform.windows)) {
      return;
    }
    if (unread == _shownBadge) return;
    _shownBadge = unread;
    try {
      await _dock.invokeMethod('set', unread);
    } on PlatformException catch (_) {
    } on MissingPluginException catch (_) {}
  }

  void _consider(Map<String, dynamic> chat) {
    final id = chat['id']?.toString();
    if (id == null) return;
    final last = chat['last'] is Map
        ? Map<String, dynamic>.from(chat['last'] as Map)
        : null;
    final lastId = last?['id']?.toString();
    final seen = _lastSeen[id];
    _lastSeen[id] = lastId;
    if (!_primed || last == null || lastId == null || lastId == seen) return;
    final quiet =
        last['mine'] == true ||
        last['kind'] == 'system' ||
        chat['muted'] == true;
    if (quiet) return;
    if (((chat['unread'] as num?) ?? 0) <= 0) return;
    final inFront = _state == AppLifecycleState.resumed;
    // On screen and in front: they are reading it.
    if (inFront && id == visibleChat) return;
    // A ringing call has its own ring while the app is in front.
    if (inFront && last['kind'] == 'call') return;

    final group = chat['type'] == 'group';
    final from = last['sender_name']?.toString() ?? '';
    final text = last['text']?.toString() ?? '';
    unawaited(
      OsNotifications.show(
        id: id.hashCode & 0x7fffffff,
        title: group ? (chat['name']?.toString() ?? 'Group') : from,
        body: group && from.isNotEmpty ? '$from: $text' : text,
        payload: 'chat:$id',
      ),
    );
  }
}
