// Chat: EzBizi's own WhatsApp between EzBizi accounts (Jose, 2026-11-25).
//
// The store every Chat pane reads: the chat list, colleagues, typing and the
// pulse loop. There is no socket in this app, so GET /chat/pulse is polled
// (3 s while Chat is on screen, 20 s behind) and the list and open thread
// refetch only when its `rev` moves.

import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../../core/api_client.dart';
import '../../../widgets/side_panel.dart';

typedef Json = Map<String, dynamic>;

class ChatStore extends ChangeNotifier with WidgetsBindingObserver {
  ChatStore(this.api, this.meId) {
    WidgetsBinding.instance.addObserver(this);
  }

  final ApiClient api;
  final String meId;

  List<Json> chats = const [];
  List<Json> contacts = const [];
  bool loaded = false;
  String? error;

  /// chat id -> who is typing or recording there now.
  Map<String, List<Json>> typing = const {};

  /// Bumped whenever the server's revision moved; threads refetch on it.
  int tick = 0;

  String _rev = '';
  Timer? _timer;
  bool _visible = false;
  bool _foreground = true;
  bool _pulsing = false;
  bool _disposed = false;

  /// Picture bytes by attachment id, so scrolling back does not refetch.
  /// ponytail: unbounded for the session; an LRU if memory ever shows it.
  static final Map<String, Uint8List> pictures = {};

  set visible(bool value) {
    if (_visible == value) return;
    _visible = value;
    _schedule();
    if (value) unawaited(pulse());
  }

  void start() {
    unawaited(load());
    unawaited(loadContacts());
    _schedule();
  }

  void _schedule() {
    _timer?.cancel();
    if (_disposed || !_foreground) return;
    _timer = Timer.periodic(
      Duration(seconds: _visible ? 3 : 20),
      (_) => unawaited(pulse()),
    );
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _foreground = state == AppLifecycleState.resumed;
    _schedule();
    if (_foreground) unawaited(pulse());
  }

  Future<void> pulse() async {
    if (_pulsing || _disposed) return;
    _pulsing = true;
    try {
      final r = await api.get('/chat/pulse');
      if (_disposed || !r.ok || r.data is! Map) return;
      final data = r.data as Map;
      final rev = (data['rev'] ?? '').toString();
      final raw = data['typing'];
      final nextTyping = <String, List<Json>>{
        if (raw is Map)
          for (final e in raw.entries)
            e.key.toString(): [
              for (final t in (e.value as List? ?? const []))
                if (t is Map) Json.from(t),
            ],
      };
      final typingChanged = nextTyping.toString() != typing.toString();
      typing = nextTyping;
      if (rev != _rev) {
        tick++;
        // The rev is spent only once the list really reloaded; a failed read
        // tries again on the next beat instead of leaving the list stale.
        if (await load()) _rev = rev;
      } else if (typingChanged) {
        notifyListeners();
      }
    } finally {
      _pulsing = false;
    }
  }

  Future<bool> load() async {
    final r = await api.get('/chat/chats');
    if (_disposed) return false;
    loaded = true;
    if (r.ok && r.data is List) {
      chats = [
        for (final c in r.data as List)
          if (c is Map) Json.from(c),
      ];
      error = null;
    } else {
      error = r.reason;
    }
    notifyListeners();
    return r.ok;
  }

  /// "Mark all read": one request; the list reloads with the counts cleared.
  Future<void> readAll() async {
    final r = await api.post('/chat/read-all', {});
    if (r.ok && !_disposed) await load();
  }

  Future<void> loadContacts() async {
    final r = await api.get('/chat/contacts');
    if (_disposed || !r.ok || r.data is! List) return;
    contacts = [
      for (final c in r.data as List)
        if (c is Map) Json.from(c),
    ];
    notifyListeners();
  }

  Json? chat(String? id) {
    for (final c in chats) {
      if (c['id'] == id) return c;
    }
    return null;
  }

  /// Open (or find) the direct chat with a colleague; returns its id.
  Future<String?> openDirect(String userId) async {
    final r = await api.post('/chat/chats', {'user_id': userId});
    if (!r.ok || r.data is! Map) return null;
    await load();
    return (r.data as Map)['id'] as String?;
  }

  Future<String?> settings(String chatId, Json changes) async {
    final r = await api.patch('/chat/chats/$chatId/settings', changes);
    await load();
    return r.ok ? null : r.reason;
  }

  /// "typing…" for the others, at most every four seconds.
  DateTime _lastTyping = DateTime(2000);
  void typingIn(String chatId, {String state = 'typing'}) {
    final now = DateTime.now();
    if (state == 'typing' && now.difference(_lastTyping).inSeconds < 4) return;
    _lastTyping = state == 'stop' ? DateTime(2000) : now;
    unawaited(api.post('/chat/chats/$chatId/typing', {'state': state}));
  }

  @override
  void dispose() {
    _disposed = true;
    _timer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }
}

/// "online", "typing…", "last seen today at 10:02".
String presenceLine(
  Json? chat,
  List<Json> typing,
  String Function(DateTime) time,
) {
  if (typing.isNotEmpty) {
    final t = typing.first;
    final verb = t['state'] == 'recording' ? 'recording audio…' : 'typing…';
    if (chat?['type'] == 'group') {
      return '${(t['name'] ?? '').toString().split(' ').first} is $verb';
    }
    return verb;
  }
  if (chat == null) return '';
  if (chat['type'] == 'group') {
    final names = (chat['member_names'] as List? ?? const []).join(', ');
    return names;
  }
  if (chat['online'] == true) return 'online';
  final seen = DateTime.tryParse(
    (chat['last_seen'] ?? '').toString(),
  )?.toLocal();
  if (seen == null) return '';
  return 'last seen ${dayWord(seen, time)}';
}

/// "today at 10:02", "yesterday at 9:15", "12/11/2026".
String dayWord(DateTime at, String Function(DateTime) time) {
  final now = DateTime.now();
  final today = DateTime(now.year, now.month, now.day);
  final day = DateTime(at.year, at.month, at.day);
  final diff = today.difference(day).inDays;
  if (diff == 0) return 'today at ${time(at)}';
  if (diff == 1) return 'yesterday at ${time(at)}';
  return '${at.month}/${at.day}/${at.year}';
}

/// The colours WhatsApp gives group senders, by name.
const senderHues = <double>[200, 340, 25, 140, 270, 50, 180, 300, 95, 10];

Color senderColor(String name, Brightness b) {
  final hue = senderHues[name.hashCode.abs() % senderHues.length];
  return HSLColor.fromAHSL(
    1,
    hue,
    0.6,
    b == Brightness.dark ? 0.68 : 0.38,
  ).toColor();
}

/// The emoji the composer and reactions offer. No package: a list is enough.
const quickReactions = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

const emojiSet = [
  '😀', '😃', '😄', '😁', '😆', '😅', '🤣', '😂', '🙂', '🙃', '😉', '😊', //
  '😇', '🥰', '😍', '🤩', '😘', '😗', '😚', '😋', '😛', '😜', '🤪', '😝',
  '🤑', '🤗', '🤭', '🤫', '🤔', '🤐', '🤨', '😐', '😑', '😶', '😏', '😒',
  '🙄', '😬', '😌', '😔', '😪', '🤤', '😴', '😷', '🤒', '🤕', '🤢', '🤮',
  '🥵', '🥶', '😵', '🤯', '🤠', '🥳', '😎', '🤓', '🧐', '😕', '😟', '🙁',
  '😮', '😯', '😲', '😳', '🥺', '😦', '😧', '😨', '😰', '😥', '😢', '😭',
  '😱', '😖', '😣', '😞', '😓', '😩', '😫', '🥱', '😤', '😡', '😠', '🤬',
  '👍', '👎', '👌', '✌️', '🤞', '🤝', '🙏', '👏', '🙌', '💪', '👋', '🤙',
  '👀', '🧠', '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '💯', '🔥', '✨',
  '⭐', '🎉', '🎊', '✅', '❌', '⚠️', '❓', '❗', '💤', '💬', '📞', '📷',
  '🚗', '🚚', '🏠', '🔧', '🔨', '🪜', '🧰', '🧾', '💵', '📅', '⏰', '📍',
  '☕', '🍕', '🍔', '🌮', '🍺', '🎂', '☀️', '🌧️', '❄️', '🌙', '🐶', '🐱',
];

/// Chat's confirmations and small forms, as a side panel (house rule: no
/// modal dialogs). Shaped like the dialog it replaces: a title, the content
/// and the buttons; `Navigator.pop` answers it the same way.
class ChatPanel extends StatelessWidget {
  const ChatPanel({
    super.key,
    required this.title,
    this.content = const SizedBox.shrink(),
    this.actions = const [],
    this.width = 440,
  });

  /// The header reads this Text's words.
  final Text title;
  final Widget content;
  final List<Widget> actions;
  final double width;

  @override
  Widget build(BuildContext context) => SidePanel(
    title: title.data ?? '',
    width: width,
    actions: actions,
    child: content,
  );
}
