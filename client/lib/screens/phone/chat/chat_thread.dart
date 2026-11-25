// One open chat: WhatsApp's header, bubbles, ticks, replies, reactions, the
// composer with emoji, attachments and voice messages, calls, and the
// contact / group info pane.

import 'dart:async';
import 'dart:io';
import 'dart:math' as math;

import 'package:desktop_drop/desktop_drop.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';
import 'package:record/record.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/files.dart';
import '../../../core/phone_call_service.dart';
import '../../../core/session.dart';
import '../../../core/soft_keyboard.dart';
import '../../../widgets/side_panel.dart';
import '../../../core/timestamps.dart';
import '../../../widgets/user_avatar.dart';
import '../recording_player.dart';
import 'chat_store.dart';
import 'chat_tab.dart' show ReceiptTicks, confirmClear, pickMuteHours;

class ChatThread extends StatefulWidget {
  const ChatThread({
    super.key,
    required this.store,
    required this.session,
    required this.chatId,
    required this.phone,
    required this.onBack,
    required this.onOpenChat,
  });

  final ChatStore store;
  final Session session;
  final String chatId;
  final bool phone;
  final VoidCallback onBack;
  final ValueChanged<String> onOpenChat;

  /// A message to scroll to when the thread opens (search, starred).
  static String? jumpTo;

  @override
  State<ChatThread> createState() => _ChatThreadState();
}

class _ChatThreadState extends State<ChatThread> {
  ChatStore get store => widget.store;

  List<Json> _messages = [];
  bool _loading = true;
  bool _hasMore = false;
  bool _loadingOlder = false;
  String? _error;
  int _seenTick = -1;
  int _unreadAtOpen = 0;
  String? _highlight;

  final _scroll = ScrollController();
  final _text = TextEditingController();
  final _focus = FocusNode();
  final _keys = <String, GlobalKey>{};
  bool _showDown = false;

  Json? _replyTo;
  Json? _editing;
  bool _emoji = false;
  bool _info = false;
  bool _searching = false;
  final _search = TextEditingController();
  List<Json> _found = const [];

  // Voice message.
  AudioRecorder? _recorder;
  DateTime? _recordingSince;
  Timer? _recordTicker;
  bool _dropping = false;

  Json? get _chat => store.chat(widget.chatId);

  @override
  void initState() {
    super.initState();
    final c = _chat;
    _unreadAtOpen = (c?['unread'] as int?) ?? 0;
    store.addListener(_storeMoved);
    _scroll.addListener(_scrolled);
    _text.addListener(() => setState(() {}));
    final jump = ChatThread.jumpTo;
    ChatThread.jumpTo = null;
    unawaited(_load(around: jump));
  }

  @override
  void dispose() {
    store.removeListener(_storeMoved);
    _scroll.dispose();
    _text.dispose();
    _focus.dispose();
    _search.dispose();
    _recordTicker?.cancel();
    unawaited(_recorder?.dispose());
    super.dispose();
  }

  void _storeMoved() {
    if (store.tick != _seenTick && !_loading) unawaited(_refresh());
    if (mounted) setState(() {});
  }

  void _scrolled() {
    final down = _scroll.hasClients && _scroll.offset > 400;
    if (down != _showDown) setState(() => _showDown = down);
    if (_scroll.hasClients &&
        _hasMore &&
        !_loadingOlder &&
        _scroll.position.pixels > _scroll.position.maxScrollExtent - 300) {
      unawaited(_older());
    }
  }

  List<Json> _rows(Object? data) => [
    for (final m in (data as List? ?? const []))
      if (m is Map) Json.from(m),
  ];

  Future<void> _load({String? around}) async {
    _seenTick = store.tick;
    final r = await store.api.get('/chat/chats/${widget.chatId}/messages', {
      'limit': '60',
      'around': ?around,
    });
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (r.ok) {
        _messages = _rows(r.data);
        _hasMore = r.body['has_more'] == true;
        _error = null;
        _highlight = around;
      } else {
        _error = r.reason;
      }
    });
    if (around != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _reveal(around));
    }
  }

  /// Everything since the newest we hold, plus edits, reactions and receipts
  /// on what is on screen: the last page is refetched and merged by id.
  Future<void> _refresh() async {
    _seenTick = store.tick;
    final r = await store.api.get('/chat/chats/${widget.chatId}/messages', {
      'limit': '60',
    });
    if (!mounted || !r.ok) return;
    final fresh = _rows(r.data);
    final byId = {for (final m in fresh) m['id']: m};
    final oldestFresh = fresh.isEmpty
        ? null
        : fresh.first['sent_at'] as String?;
    setState(() {
      _messages = [
        for (final m in _messages)
          if (oldestFresh == null ||
              (m['sent_at'] as String).compareTo(oldestFresh) < 0)
            m,
        ...fresh,
      ];
      // Deleted for me elsewhere (another device) drops out of the merged page.
      _messages = [
        for (final m in _messages)
          if (byId.containsKey(m['id']) ||
              oldestFresh == null ||
              (m['sent_at'] as String).compareTo(oldestFresh) < 0)
            byId[m['id']] ?? m,
      ];
    });
  }

  Future<void> _older() async {
    if (_messages.isEmpty) return;
    _loadingOlder = true;
    final r = await store.api.get('/chat/chats/${widget.chatId}/messages', {
      'limit': '60',
      'before': _messages.first['sent_at'] as String,
    });
    _loadingOlder = false;
    if (!mounted || !r.ok) return;
    setState(() {
      _messages = [..._rows(r.data), ..._messages];
      _hasMore = r.body['has_more'] == true;
    });
  }

  void _reveal(String id) {
    final ctx = _keys[id]?.currentContext;
    if (ctx != null) {
      unawaited(
        Scrollable.ensureVisible(
          ctx,
          alignment: 0.5,
          duration: const Duration(milliseconds: 250),
        ),
      );
    }
    setState(() => _highlight = id);
    Timer(const Duration(seconds: 2), () {
      if (mounted && _highlight == id) setState(() => _highlight = null);
    });
  }

  void _toBottom() {
    if (_scroll.hasClients) {
      unawaited(
        _scroll.animateTo(
          0,
          duration: const Duration(milliseconds: 250),
          curve: Curves.easeOut,
        ),
      );
    }
  }

  String _time(DateTime at) =>
      widget.session.me!.display.formatTime(at.hour, at.minute);

  void _snack(String text) => ScaffoldMessenger.maybeOf(
    context,
  )?.showSnackBar(SnackBar(content: Text(text)));

  // ── sending ────────────────────────────────────────────────────

  bool get _mayWrite {
    final c = _chat;
    return c != null;
  }

  Future<void> _send() async {
    final body = _text.text.trim();
    if (body.isEmpty) return;
    final editing = _editing;
    _text.clear();
    store.typingIn(widget.chatId, state: 'stop');

    if (editing != null) {
      setState(() => _editing = null);
      final r = await store.api.patch('/chat/messages/${editing['id']}', {
        'body': body,
      });
      if (!r.ok) _snack(r.reason);
      await _refresh();
      return;
    }

    final reply = _replyTo;
    final temp = <String, dynamic>{
      'id': 'pending-${DateTime.now().microsecondsSinceEpoch}',
      'kind': 'text',
      'body': body,
      'mine': true,
      'sent_at': DateTime.now().toUtc().toIso8601String(),
      'status': 'pending',
      'reactions': const [],
      'reply_to': reply == null
          ? null
          : {
              'id': reply['id'],
              'sender_name': reply['mine'] == true
                  ? 'You'
                  : reply['sender_name'],
              'kind': reply['kind'],
              'body': reply['body'],
            },
    };
    setState(() {
      _replyTo = null;
      _messages = [..._messages, temp];
    });
    _toBottom();
    final r = await store.api.post('/chat/chats/${widget.chatId}/messages', {
      'body': body,
      'reply_to_id': ?reply?['id'],
    });
    if (!mounted) return;
    setState(() {
      final i = _messages.indexOf(temp);
      if (r.ok && r.data is Map) {
        if (i >= 0) _messages[i] = Json.from(r.data as Map);
      } else {
        temp['status'] = 'failed';
        _snack(r.reason);
      }
    });
  }

  Future<void> _sendFile(
    String name,
    List<int> bytes, {
    String kind = '',
    String caption = '',
    int seconds = 0,
  }) async {
    final r = await store.api.postFile(
      '/chat/chats/${widget.chatId}/messages',
      'file',
      name,
      bytes,
      fields: {
        if (kind.isNotEmpty) 'kind': kind,
        if (caption.isNotEmpty) 'body': caption,
        if (seconds > 0) 'meta[seconds]': '$seconds',
        if (_replyTo != null) 'reply_to_id': _replyTo!['id'] as String,
      },
    );
    if (!mounted) return;
    if (!r.ok) {
      _snack(r.reason);
      return;
    }
    setState(() => _replyTo = null);
    await _refresh();
    _toBottom();
  }

  Future<void> _attach(String what) async {
    final PickedBytes? picked = switch (what) {
      'camera' => await Files.takePhoto(),
      'media' => await Files.pickBytes(accept: 'image/*,video/*'),
      _ => await Files.pickBytes(),
    };
    if (picked == null || !mounted) return;
    await _preview(picked.name, picked.bytes);
  }

  /// WhatsApp's send screen: the picture or file, a caption, Send.
  Future<void> _preview(String name, List<int> bytes) async {
    final caption = TextEditingController();
    final isImage = RegExp(
      r'\.(jpe?g|png|gif|webp|heic|heif)$',
      caseSensitive: false,
    ).hasMatch(name);
    final send = await showSidePanel<bool>(
      context,
      builder: (dialog) => ChatPanel(
        title: Text(name, maxLines: 1, overflow: TextOverflow.ellipsis),
        content: SizedBox(
          width: 420,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (isImage)
                ConstrainedBox(
                  constraints: const BoxConstraints(maxHeight: 320),
                  child: Image.memory(
                    Uint8List.fromList(bytes),
                    fit: BoxFit.contain,
                    errorBuilder: (_, _, _) =>
                        const Icon(Icons.image, size: 64),
                  ),
                )
              else
                ListTile(
                  leading: const Icon(
                    Icons.insert_drive_file_outlined,
                    size: 36,
                  ),
                  title: Text(name),
                  subtitle: Text(_size(bytes.length)),
                ),
              const SizedBox(height: 12),
              TextField(
                key: const ValueKey('chat-caption'),
                controller: caption,
                autofocus: autofocusOnOpen,
                decoration: const InputDecoration(hintText: 'Add a caption'),
                onSubmitted: (_) => Navigator.of(dialog).pop(true),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialog).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton.icon(
            key: const ValueKey('chat-file-send'),
            icon: const Icon(Icons.send),
            label: const Text('Send'),
            onPressed: () => Navigator.of(dialog).pop(true),
          ),
        ],
      ),
    );
    final text = caption.text.trim();
    caption.dispose();
    if (send == true) await _sendFile(name, bytes, caption: text);
  }

  // ── voice messages ─────────────────────────────────────────────

  Future<void> _startRecording() async {
    final recorder = AudioRecorder();
    if (!await recorder.hasPermission()) {
      await recorder.dispose();
      _snack('EzBizi needs the microphone to record a voice message.');
      return;
    }
    final path = kIsWeb
        ? ''
        : '${(await getTemporaryDirectory()).path}/chat-${DateTime.now().millisecondsSinceEpoch}.m4a';
    await recorder.start(
      RecordConfig(
        encoder: kIsWeb ? AudioEncoder.opus : AudioEncoder.aacLc,
        numChannels: 1,
        bitRate: 32000,
      ),
      path: path,
    );
    store.typingIn(widget.chatId, state: 'recording');
    setState(() {
      _recorder = recorder;
      _recordingSince = DateTime.now();
    });
    _recordTicker = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
      store.typingIn(widget.chatId, state: 'recording');
    });
  }

  Future<void> _stopRecording({required bool send}) async {
    final recorder = _recorder;
    final since = _recordingSince;
    _recordTicker?.cancel();
    setState(() {
      _recorder = null;
      _recordingSince = null;
    });
    store.typingIn(widget.chatId, state: 'stop');
    if (recorder == null) return;
    final path = await recorder.stop();
    await recorder.dispose();
    if (!send || path == null || since == null) return;
    final seconds = DateTime.now().difference(since).inSeconds;
    if (seconds < 1) return;

    List<int> bytes;
    if (kIsWeb) {
      bytes = (await http.get(Uri.parse(path))).bodyBytes;
    } else {
      final file = File(path);
      bytes = await file.readAsBytes();
      unawaited(file.delete());
    }
    await _sendFile(
      kIsWeb ? 'voice.webm' : 'voice.m4a',
      bytes,
      kind: 'voice',
      seconds: seconds,
    );
  }

  // ── calls ──────────────────────────────────────────────────────

  Future<void> _call() async {
    final chat = _chat;
    final userId = chat?['user_id'] as String?;
    if (chat == null || userId == null) return;
    final phone = PhoneCallService.instance;
    final callerId = phone.callerId;
    if (!phone.supported || !phone.hasLine || callerId == null) {
      _snack(
        'Calls need a phone line on this device. An administrator assigns '
        'one under Communication › Users.',
      );
      return;
    }
    final started = DateTime.now();
    await phone.connect(
      to: 'client:${userId.replaceAll('-', '')}',
      callerId: callerId,
    );
    final refused = phone.callRefusal;
    if (refused != null) {
      _snack(refused);
      return;
    }
    // Record the call as a line in the chat once it ends.
    void ended() {
      if (phone.onCall) return;
      phone.removeListener(ended);
      final seconds = DateTime.now().difference(started).inSeconds;
      unawaited(
        store.api.post('/chat/chats/${widget.chatId}/messages', {
          'kind': 'call',
          'meta': {'status': 'answered', 'duration': seconds},
        }),
      );
    }

    phone.addListener(ended);
  }

  // ── message actions ────────────────────────────────────────────

  Future<void> _react(Json m, String emoji) async {
    await store.api.post('/chat/messages/${m['id']}/react', {'emoji': emoji});
    await _refresh();
  }

  Future<void> _pickReaction(Json m) async {
    final emoji = await _emojiDialog(context);
    if (emoji != null) await _react(m, emoji);
  }

  Future<void> _messageAction(Json m, String action) async {
    switch (action) {
      case 'reply':
        setState(() {
          _replyTo = m;
          _editing = null;
        });
        _focus.requestFocus();
      case 'copy':
        await Clipboard.setData(ClipboardData(text: '${m['body'] ?? ''}'));
        _snack('Copied');
      case 'star':
        await store.api.post('/chat/messages/${m['id']}/star', {});
        await _refresh();
      case 'edit':
        setState(() {
          _editing = m;
          _replyTo = null;
          _text.text = '${m['body'] ?? ''}';
        });
        _focus.requestFocus();
      case 'forward':
        await _forward(m);
      case 'info':
        await _infoDialog(m);
      case 'react':
        await _pickReaction(m);
      case 'download':
        await _download(m);
      case 'delete':
        await _delete(m);
    }
  }

  List<(String, String, IconData)> _actionsFor(Json m) {
    final deleted = m['deleted'] == true;
    final mine = m['mine'] == true;
    final sentAt = DateTime.tryParse('${m['sent_at']}');
    final editable =
        mine &&
        m['kind'] == 'text' &&
        !deleted &&
        sentAt != null &&
        DateTime.now().toUtc().difference(sentAt).inMinutes < 15;
    if (deleted) return [('delete', 'Delete', Icons.delete_outline)];
    return [
      ('reply', 'Reply', Icons.reply),
      ('react', 'React', Icons.add_reaction_outlined),
      if ((m['body'] ?? '').toString().isNotEmpty) ('copy', 'Copy', Icons.copy),
      if (m['attachment'] != null) ('download', 'Download', Icons.download),
      if (!['call', 'system'].contains(m['kind']))
        ('forward', 'Forward', Icons.shortcut),
      (
        'star',
        m['starred'] == true ? 'Unstar' : 'Star',
        m['starred'] == true ? Icons.star : Icons.star_outline,
      ),
      if (editable) ('edit', 'Edit', Icons.edit_outlined),
      if (mine) ('info', 'Message info', Icons.info_outline),
      ('delete', 'Delete', Icons.delete_outline),
    ];
  }

  Future<void> _menu(Json m, Offset? at) async {
    if ((m['id'] as String).startsWith('pending-') || m['kind'] == 'system') {
      return;
    }
    final actions = _actionsFor(m);
    String? choice;
    if (at != null) {
      choice = await showMenu<String>(
        context: context,
        position: RelativeRect.fromLTRB(at.dx, at.dy, at.dx, at.dy),
        items: [
          PopupMenuItem<String>(
            enabled: false,
            child: _ReactionBar(
              onPick: (e) {
                Navigator.of(context).pop();
                unawaited(_react(m, e));
              },
            ),
          ),
          for (final (v, l, i) in actions)
            PopupMenuItem(
              value: v,
              child: Row(
                children: [
                  Icon(i, size: 20),
                  const SizedBox(width: 12),
                  Text(l),
                ],
              ),
            ),
        ],
      );
    } else {
      choice = await showModalBottomSheet<String>(
        context: context,
        builder: (sheet) => SafeArea(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (m['deleted'] != true)
                Padding(
                  padding: const EdgeInsets.all(12),
                  child: _ReactionBar(
                    onPick: (e) {
                      Navigator.of(sheet).pop();
                      unawaited(_react(m, e));
                    },
                  ),
                ),
              for (final (v, l, i) in actions)
                ListTile(
                  leading: Icon(i),
                  title: Text(l),
                  onTap: () => Navigator.of(sheet).pop(v),
                ),
            ],
          ),
        ),
      );
    }
    if (choice != null && mounted) await _messageAction(m, choice);
  }

  Future<void> _delete(Json m) async {
    final mine = m['mine'] == true;
    final sentAt = DateTime.tryParse('${m['sent_at']}');
    final adminHere = _chat?['type'] == 'group' && _iAmAdmin;
    final forEveryone =
        m['deleted'] != true &&
        (adminHere ||
            (mine &&
                sentAt != null &&
                DateTime.now().toUtc().difference(sentAt).inHours < 48));
    final choice = await showSidePanel<String>(
      context,
      builder: (dialog) => ChatPanel(
        title: const Text('Delete message?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialog).pop(),
            child: const Text('Cancel'),
          ),
          TextButton(
            key: const ValueKey('chat-delete-me'),
            onPressed: () => Navigator.of(dialog).pop('me'),
            child: const Text('Delete for me'),
          ),
          if (forEveryone)
            FilledButton(
              key: const ValueKey('chat-delete-everyone'),
              onPressed: () => Navigator.of(dialog).pop('everyone'),
              child: const Text('Delete for everyone'),
            ),
        ],
      ),
    );
    if (choice == null) return;
    final r = await store.api.delete(
      '/chat/messages/${m['id']}',
      choice == 'everyone' ? {'everyone': '1'} : null,
    );
    if (!r.ok) _snack(r.reason);
    if (choice == 'me') {
      setState(() => _messages.removeWhere((x) => x['id'] == m['id']));
    }
    await _refresh();
  }

  Future<void> _forward(Json m) async {
    final picked = <String>{};
    final go = await showSidePanel<bool>(
      context,
      builder: (dialog) => StatefulBuilder(
        builder: (dialog, set) => ChatPanel(
          title: const Text('Forward message to'),
          content: SizedBox(
            width: 380,
            height: 420,
            child: ListView(
              children: [
                for (final c in store.chats)
                  CheckboxListTile(
                    value: picked.contains(c['id']),
                    title: Text('${c['name']}'),
                    onChanged: picked.length >= 5 && !picked.contains(c['id'])
                        ? null
                        : (_) => set(() {
                            if (!picked.remove(c['id'])) {
                              picked.add(c['id'] as String);
                            }
                          }),
                  ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(dialog).pop(false),
              child: const Text('Cancel'),
            ),
            FilledButton.icon(
              icon: const Icon(Icons.send),
              label: Text('Forward (${picked.length})'),
              onPressed: picked.isEmpty
                  ? null
                  : () => Navigator.of(dialog).pop(true),
            ),
          ],
        ),
      ),
    );
    if (go != true) return;
    final r = await store.api.post('/chat/messages/${m['id']}/forward', {
      'chat_ids': picked.toList(),
    });
    _snack(r.ok ? 'Forwarded' : r.reason);
    await store.load();
  }

  Future<void> _infoDialog(Json m) async {
    final r = await store.api.get('/chat/messages/${m['id']}/info');
    if (!mounted || !r.ok) return;
    final rows = _rows(r.data);
    String when(String? iso) {
      final at = DateTime.tryParse(iso ?? '')?.toLocal();
      return at == null ? '—' : dayWord(at, _time);
    }

    await showSidePanel<void>(
      context,
      builder: (dialog) => ChatPanel(
        title: const Text('Message info'),
        content: SizedBox(
          width: 360,
          child: ListView(
            shrinkWrap: true,
            children: [
              for (final p in rows)
                ListTile(
                  title: Text('${p['name']}'),
                  subtitle: Text(
                    p['read'] == true
                        ? 'Read ${when(p['read_at'] as String?)}'
                        : p['delivered'] == true
                        ? 'Delivered ${when(p['delivered_at'] as String?)}'
                        : 'Not delivered yet',
                  ),
                  trailing: ReceiptTicks(
                    status: p['read'] == true
                        ? 'read'
                        : p['delivered'] == true
                        ? 'delivered'
                        : 'sent',
                  ),
                ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialog).pop(),
            child: const Text('Close'),
          ),
        ],
      ),
    );
  }

  Future<void> _download(Json m) async {
    final a = m['attachment'] as Map?;
    if (a == null) return;
    final bytes = await store.api.getBytes('/attachments/${a['id']}');
    if (bytes == null) {
      _snack('Could not download that file.');
      return;
    }
    Files.download('${a['name']}', bytes);
  }

  // ── in-chat search ─────────────────────────────────────────────

  Future<void> _searchChanged(String q) async {
    if (q.trim().length < 2) {
      setState(() => _found = const []);
      return;
    }
    final r = await store.api.get('/chat/search', {'q': q.trim()});
    if (!mounted || !r.ok) return;
    setState(
      () => _found = _rows(
        r.data,
      ).where((m) => (m['chat'] as Map?)?['id'] == widget.chatId).toList(),
    );
  }

  // ── build ──────────────────────────────────────────────────────

  bool get _iAmAdmin => _infoData?['i_am_admin'] == true;
  Json? _infoData;

  Future<void> _loadInfo() async {
    final r = await store.api.get('/chat/chats/${widget.chatId}');
    if (!mounted || !r.ok || r.data is! Map) return;
    setState(() => _infoData = Json.from(r.data as Map));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (_info) {
      final pane = ChatInfoPane(
        store: store,
        chatId: widget.chatId,
        session: widget.session,
        onClose: () => setState(() => _info = false),
        onOpenChat: widget.onOpenChat,
        onLeft: widget.onBack,
        onSearch: () => setState(() {
          _info = false;
          _searching = true;
        }),
        onCall: _call,
        phone: widget.phone,
        onJump: (id) => setState(() {
          _info = false;
          unawaited(_load(around: id));
        }),
      );
      if (widget.phone) return pane;
      return Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(child: _threadColumn(theme)),
          const VerticalDivider(width: 1),
          SizedBox(width: 380, child: pane),
        ],
      );
    }
    return _threadColumn(theme);
  }

  Widget _threadColumn(ThemeData theme) {
    final chat = _chat;
    final typing = store.typing[widget.chatId] ?? const [];
    final subtitle = presenceLine(chat, typing, _time);
    _infoData ?? (chat?['type'] == 'group' ? unawaited(_loadInfo()) : null);

    final body = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Material(
          color: theme.colorScheme.surfaceContainer,
          child: SizedBox(
            height: 60,
            child: Row(
              children: [
                if (widget.phone)
                  IconButton(
                    tooltip: 'Back',
                    icon: const Icon(Icons.arrow_back),
                    onPressed: widget.onBack,
                  )
                else
                  const SizedBox(width: 12),
                Expanded(
                  child: InkWell(
                    key: const ValueKey('chat-header'),
                    onTap: () => setState(() => _info = true),
                    child: Row(
                      children: [
                        _avatarFor(chat, 20),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '${chat?['name'] ?? ''}',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: theme.textTheme.titleMedium,
                              ),
                              if (subtitle.isNotEmpty)
                                Text(
                                  subtitle,
                                  key: const ValueKey('chat-presence'),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: theme.textTheme.bodySmall?.copyWith(
                                    color: typing.isNotEmpty
                                        ? theme.colorScheme.primary
                                        : theme.colorScheme.onSurfaceVariant,
                                  ),
                                ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                if (chat?['type'] == 'direct')
                  IconButton(
                    key: const ValueKey('chat-call'),
                    tooltip: 'Voice call',
                    icon: const Icon(Icons.call_outlined),
                    onPressed: _call,
                  ),
                IconButton(
                  tooltip: 'Search',
                  icon: const Icon(Icons.search),
                  onPressed: () => setState(() => _searching = !_searching),
                ),
                PopupMenuButton<String>(
                  tooltip: 'Menu',
                  icon: const Icon(Icons.more_vert),
                  onSelected: (v) async {
                    if (v == 'info') setState(() => _info = true);
                    if (v == 'close') widget.onBack();
                    if (v == 'clear' && await confirmClear(context)) {
                      await store.settings(widget.chatId, {'clear': true});
                      await _load();
                    }
                    if (v == 'mute' && mounted) {
                      final h = await pickMuteHours(context);
                      if (h != null) {
                        await store.settings(widget.chatId, {'mute_hours': h});
                      }
                    }
                    if (v == 'unmute') {
                      await store.settings(widget.chatId, {'mute_hours': 0});
                    }
                  },
                  itemBuilder: (_) => [
                    PopupMenuItem(
                      value: 'info',
                      child: Text(
                        chat?['type'] == 'group'
                            ? 'Group info'
                            : 'Contact info',
                      ),
                    ),
                    PopupMenuItem(
                      value: chat?['muted'] == true ? 'unmute' : 'mute',
                      child: Text(
                        chat?['muted'] == true
                            ? 'Unmute notifications'
                            : 'Mute notifications',
                      ),
                    ),
                    const PopupMenuItem(
                      value: 'clear',
                      child: Text('Clear chat'),
                    ),
                    if (!widget.phone)
                      const PopupMenuItem(
                        value: 'close',
                        child: Text('Close chat'),
                      ),
                  ],
                ),
              ],
            ),
          ),
        ),
        if (_searching) _searchBar(theme),
        const Divider(height: 1),
        Expanded(child: _messagesArea(theme)),
        if (_replyTo != null || _editing != null) _replyPreview(theme),
        _composer(theme),
        if (_emoji)
          SizedBox(
            height: 240,
            child: _EmojiGrid(
              onPick: (e) {
                final sel = _text.selection;
                final at = sel.isValid ? sel.start : _text.text.length;
                final end = sel.isValid ? sel.end : _text.text.length;
                _text.value = TextEditingValue(
                  text: _text.text.replaceRange(at, end, e),
                  selection: TextSelection.collapsed(offset: at + e.length),
                );
              },
            ),
          ),
      ],
    );

    if (widget.phone) return body;
    return DropTarget(
      onDragEntered: (_) => setState(() => _dropping = true),
      onDragExited: (_) => setState(() => _dropping = false),
      onDragDone: (details) async {
        setState(() => _dropping = false);
        for (final f in details.files) {
          await _preview(f.name, await f.readAsBytes());
        }
      },
      child: Stack(
        children: [
          Positioned.fill(child: body),
          if (_dropping)
            Positioned.fill(
              child: ColoredBox(
                color: theme.colorScheme.primary.withValues(alpha: 0.12),
                child: Center(
                  child: Text(
                    'Drop to send',
                    style: theme.textTheme.headlineSmall,
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _avatarFor(Json? chat, double radius) {
    if (chat?['type'] == 'group') {
      return CircleAvatar(
        radius: radius,
        child: Icon(Icons.groups, size: radius),
      );
    }
    final id = chat?['user_id'] as String?;
    if (id == null) {
      return CircleAvatar(radius: radius, child: const Icon(Icons.person));
    }
    return UserAvatar(
      api: store.api,
      userId: id,
      name: '${chat?['name']}',
      radius: radius,
    );
  }

  Widget _searchBar(ThemeData theme) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Padding(
        padding: const EdgeInsets.all(8),
        child: TextField(
          key: const ValueKey('chat-thread-search'),
          controller: _search,
          autofocus: autofocusOnOpen,
          onChanged: (q) => unawaited(_searchChanged(q)),
          decoration: InputDecoration(
            isDense: true,
            hintText: 'Search in this chat',
            prefixIcon: const Icon(Icons.search, size: 20),
            suffixIcon: IconButton(
              tooltip: 'Close search',
              icon: const Icon(Icons.close, size: 18),
              onPressed: () => setState(() {
                _searching = false;
                _search.clear();
                _found = const [];
              }),
            ),
          ),
        ),
      ),
      if (_found.isNotEmpty)
        ConstrainedBox(
          constraints: const BoxConstraints(maxHeight: 220),
          child: ListView(
            shrinkWrap: true,
            children: [
              for (final m in _found)
                ListTile(
                  dense: true,
                  title: Text(
                    '${m['body'] ?? ''}',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                  subtitle: Text(
                    '${m['mine'] == true ? 'You' : m['sender_name'] ?? ''} · ${_when(m['sent_at'] as String?)}',
                  ),
                  onTap: () {
                    setState(() => _searching = false);
                    unawaited(_load(around: m['id'] as String));
                  },
                ),
            ],
          ),
        ),
    ],
  );

  String _when(String? iso) {
    final at = DateTime.tryParse(iso ?? '')?.toLocal();
    return at == null ? '' : dayWord(at, _time);
  }

  Widget _messagesArea(ThemeData theme) {
    if (_loading) return const Center(child: Text('Loading messages…'));
    if (_error != null) return Center(child: Text(_error!));

    // Newest first for a reversed list: the bottom is "now", as WhatsApp.
    final items = <Widget>[];
    final firstUnread = _unreadAtOpen > 0
        ? _messages.length - _unreadAtOpen
        : -1;
    for (var i = _messages.length - 1; i >= 0; i--) {
      final m = _messages[i];
      final prev = i > 0 ? _messages[i - 1] : null;
      final sameSender =
          prev != null &&
          prev['sender_id'] == m['sender_id'] &&
          prev['kind'] != 'system' &&
          _sameDay(prev, m);
      items.add(_bubble(theme, m, first: !sameSender));
      if (i == firstUnread && firstUnread > 0) {
        items.add(_unreadDivider(theme, _unreadAtOpen));
      }
      if (prev == null || !_sameDay(prev, m)) {
        items.add(_dayChip(theme, m['sent_at'] as String?));
      }
    }
    if (_messages.isEmpty) {
      items.add(
        Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: _systemChip(
              theme,
              'Messages are only seen by the people in this chat, inside EzBizi.',
            ),
          ),
        ),
      );
    }

    return Stack(
      children: [
        Positioned.fill(
          child: ColoredBox(
            color: theme.colorScheme.surfaceContainerLowest,
            child: ListView.builder(
              key: const ValueKey('chat-messages'),
              controller: _scroll,
              reverse: true,
              padding: const EdgeInsets.symmetric(vertical: 8),
              itemCount: items.length,
              itemBuilder: (_, i) => items[i],
            ),
          ),
        ),
        if (_showDown)
          Positioned(
            right: 16,
            bottom: 12,
            child: FloatingActionButton.small(
              key: const ValueKey('chat-to-bottom'),
              tooltip: 'Scroll to the newest',
              onPressed: _toBottom,
              child: const Icon(Icons.keyboard_double_arrow_down),
            ),
          ),
      ],
    );
  }

  bool _sameDay(Json a, Json b) {
    final x = DateTime.tryParse('${a['sent_at']}')?.toLocal();
    final y = DateTime.tryParse('${b['sent_at']}')?.toLocal();
    return x != null &&
        y != null &&
        x.year == y.year &&
        x.month == y.month &&
        x.day == y.day;
  }

  Widget _dayChip(ThemeData theme, String? iso) {
    final at = DateTime.tryParse(iso ?? '')?.toLocal();
    if (at == null) return const SizedBox.shrink();
    final now = DateTime.now();
    final diff = DateTime(
      now.year,
      now.month,
      now.day,
    ).difference(DateTime(at.year, at.month, at.day)).inDays;
    final label = diff == 0
        ? 'Today'
        : diff == 1
        ? 'Yesterday'
        : widget.session.me!.display.formatDate(at);
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: _systemChip(theme, label),
      ),
    );
  }

  Widget _systemChip(ThemeData theme, String text) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
    decoration: BoxDecoration(
      color: theme.colorScheme.surfaceContainerHigh,
      borderRadius: BorderRadius.circular(8),
    ),
    child: Text(
      text,
      textAlign: TextAlign.center,
      style: theme.textTheme.bodySmall?.copyWith(
        color: theme.colorScheme.onSurfaceVariant,
      ),
    ),
  );

  Widget _unreadDivider(ThemeData theme, int n) => Container(
    margin: const EdgeInsets.symmetric(vertical: 8),
    padding: const EdgeInsets.symmetric(vertical: 4),
    color: theme.colorScheme.surfaceContainerHigh,
    alignment: Alignment.center,
    child: Text(
      '$n unread message${n == 1 ? '' : 's'}',
      style: theme.textTheme.bodySmall,
    ),
  );

  Widget _bubble(ThemeData theme, Json m, {required bool first}) {
    final id = m['id'] as String;
    final key = _keys.putIfAbsent(id, GlobalKey.new);

    if (m['kind'] == 'system') {
      return Center(
        key: key,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 4, horizontal: 24),
          child: _systemChip(theme, _systemText(m)),
        ),
      );
    }

    return _Bubble(
      key: key,
      message: m,
      first: first,
      group: _chat?['type'] == 'group',
      highlighted: _highlight == id,
      phone: widget.phone,
      time: _time,
      store: store,
      onMenu: (at) => _menu(m, at),
      onReply: () => _messageAction(m, 'reply'),
      onReact: (e) => _react(m, e),
      onQuote: (qid) {
        if (_keys[qid]?.currentContext != null) {
          _reveal(qid);
        } else {
          unawaited(_load(around: qid));
        }
      },
      onCallBack: _call,
      onDownload: () => _download(m),
    );
  }

  String _systemText(Json m) {
    final meta = m['meta'] as Map? ?? const {};
    final who = m['mine'] == true ? 'You' : '${m['sender_name'] ?? 'Someone'}';
    final name = meta['user_id'] == widget.session.me!.id
        ? 'you'
        : '${meta['name'] ?? 'someone'}';
    return switch (meta['event']) {
      'created' => '$who created group "${meta['name'] ?? ''}"',
      'renamed' => '$who changed the group name to "${meta['name'] ?? ''}"',
      'described' => '$who changed the group description',
      'added' => '$who added $name',
      'removed' => '$who removed $name',
      'left' => '$who left',
      _ => '',
    };
  }

  Widget _replyPreview(ThemeData theme) {
    final editing = _editing;
    final m = editing ?? _replyTo!;
    return Container(
      color: theme.colorScheme.surfaceContainer,
      padding: const EdgeInsets.fromLTRB(12, 8, 4, 0),
      child: Row(
        children: [
          Expanded(
            child: _QuoteBox(
              name: editing != null
                  ? 'Edit message'
                  : m['mine'] == true
                  ? 'You'
                  : '${m['sender_name'] ?? ''}',
              text: _snippet(m),
            ),
          ),
          IconButton(
            tooltip: 'Cancel',
            icon: const Icon(Icons.close),
            onPressed: () => setState(() {
              if (editing != null) _text.clear();
              _replyTo = null;
              _editing = null;
            }),
          ),
        ],
      ),
    );
  }

  Widget _composer(ThemeData theme) {
    final chat = _chat;
    final onlyAdmins = _infoData?['only_admins_send'] == true && !_iAmAdmin;
    if (chat == null || onlyAdmins) {
      return Container(
        color: theme.colorScheme.surfaceContainer,
        padding: const EdgeInsets.all(16),
        alignment: Alignment.center,
        child: Text(
          chat == null
              ? 'You are no longer in this chat.'
              : 'Only admins can send messages',
          style: theme.textTheme.bodyMedium?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
      );
    }

    final since = _recordingSince;
    if (since != null) {
      final d = DateTime.now().difference(since);
      return Container(
        color: theme.colorScheme.surfaceContainer,
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
        child: SafeArea(
          top: false,
          child: Row(
            children: [
              IconButton(
                key: const ValueKey('chat-voice-cancel'),
                tooltip: 'Delete recording',
                icon: Icon(
                  Icons.delete_outline,
                  color: theme.colorScheme.error,
                ),
                onPressed: () => _stopRecording(send: false),
              ),
              Icon(
                Icons.fiber_manual_record,
                color: theme.colorScheme.error,
                size: 14,
              ),
              const SizedBox(width: 8),
              Text(formatClock(d), style: theme.textTheme.titleMedium),
              const Spacer(),
              IconButton.filled(
                key: const ValueKey('chat-voice-send'),
                tooltip: 'Send voice message',
                icon: const Icon(Icons.send),
                onPressed: () => _stopRecording(send: true),
              ),
            ],
          ),
        ),
      );
    }

    final hasText = _text.text.trim().isNotEmpty;
    return Container(
      color: theme.colorScheme.surfaceContainer,
      padding: const EdgeInsets.fromLTRB(4, 6, 6, 6),
      child: SafeArea(
        top: false,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            IconButton(
              key: const ValueKey('chat-emoji'),
              tooltip: 'Emoji',
              icon: Icon(
                _emoji ? Icons.keyboard : Icons.emoji_emotions_outlined,
              ),
              onPressed: () => setState(() => _emoji = !_emoji),
            ),
            PopupMenuButton<String>(
              key: const ValueKey('chat-attach'),
              tooltip: 'Attach',
              icon: const Icon(Icons.add),
              enabled: _mayWrite,
              onSelected: (v) => unawaited(_attach(v)),
              itemBuilder: (_) => [
                const PopupMenuItem(
                  value: 'file',
                  child: ListTile(
                    leading: Icon(Icons.insert_drive_file_outlined),
                    title: Text('Document'),
                  ),
                ),
                const PopupMenuItem(
                  value: 'media',
                  child: ListTile(
                    leading: Icon(Icons.photo_library_outlined),
                    title: Text('Photos & videos'),
                  ),
                ),
                if (Files.canTakePhoto)
                  const PopupMenuItem(
                    value: 'camera',
                    child: ListTile(
                      leading: Icon(Icons.photo_camera_outlined),
                      title: Text('Camera'),
                    ),
                  ),
              ],
            ),
            Expanded(
              child: CallbackShortcuts(
                bindings: {
                  // Enter sends on a keyboard; Shift+Enter is a new line.
                  if (!widget.phone)
                    const SingleActivator(LogicalKeyboardKey.enter): () =>
                        unawaited(_send()),
                  const SingleActivator(LogicalKeyboardKey.escape): () =>
                      setState(() {
                        _replyTo = null;
                        if (_editing != null) _text.clear();
                        _editing = null;
                      }),
                },
                child: TextField(
                  key: const ValueKey('chat-input'),
                  controller: _text,
                  focusNode: _focus,
                  minLines: 1,
                  maxLines: 6,
                  textCapitalization: TextCapitalization.sentences,
                  keyboardType: TextInputType.multiline,
                  onChanged: (v) {
                    if (v.isNotEmpty) store.typingIn(widget.chatId);
                  },
                  onTap: () => setState(() => _emoji = false),
                  decoration: InputDecoration(
                    isDense: true,
                    hintText: 'Type a message',
                    filled: true,
                    fillColor: theme.colorScheme.surface,
                    contentPadding: const EdgeInsets.symmetric(
                      horizontal: 14,
                      vertical: 10,
                    ),
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(22),
                      borderSide: BorderSide.none,
                    ),
                  ),
                ),
              ),
            ),
            const SizedBox(width: 6),
            if (hasText || _editing != null)
              IconButton.filled(
                key: const ValueKey('chat-send'),
                tooltip: _editing != null ? 'Save' : 'Send',
                icon: Icon(_editing != null ? Icons.check : Icons.send),
                onPressed: () => unawaited(_send()),
              )
            else
              IconButton.filled(
                key: const ValueKey('chat-mic'),
                tooltip: 'Record a voice message',
                icon: const Icon(Icons.mic),
                onPressed: () => unawaited(_startRecording()),
              ),
          ],
        ),
      ),
    );
  }
}

String _snippet(Json m) {
  if (m['deleted'] == true) return 'This message was deleted';
  return switch (m['kind']) {
    'image' =>
      '📷 ${(m['body'] ?? '').toString().isEmpty ? 'Photo' : m['body']}',
    'video' =>
      '🎥 ${(m['body'] ?? '').toString().isEmpty ? 'Video' : m['body']}',
    'voice' => '🎤 Voice message',
    'audio' => '🎵 Audio',
    'file' => '📄 ${(m['attachment'] as Map?)?['name'] ?? 'Document'}',
    'call' => '📞 Voice call',
    _ => '${m['body'] ?? ''}',
  };
}

String _size(int bytes) {
  if (bytes < 1024) return '$bytes B';
  if (bytes < 1024 * 1024) return '${(bytes / 1024).toStringAsFixed(0)} kB';
  return '${(bytes / 1024 / 1024).toStringAsFixed(1)} MB';
}

// ── one bubble ─────────────────────────────────────────────────────

class _Bubble extends StatefulWidget {
  const _Bubble({
    super.key,
    required this.message,
    required this.first,
    required this.group,
    required this.highlighted,
    required this.phone,
    required this.time,
    required this.store,
    required this.onMenu,
    required this.onReply,
    required this.onReact,
    required this.onQuote,
    required this.onCallBack,
    required this.onDownload,
  });

  final Json message;
  final bool first;
  final bool group;
  final bool highlighted;
  final bool phone;
  final String Function(DateTime) time;
  final ChatStore store;
  final void Function(Offset? at) onMenu;
  final VoidCallback onReply;
  final ValueChanged<String> onReact;
  final ValueChanged<String> onQuote;
  final VoidCallback onCallBack;
  final VoidCallback onDownload;

  @override
  State<_Bubble> createState() => _BubbleState();
}

class _BubbleState extends State<_Bubble> {
  bool _hover = false;
  double _drag = 0;

  Json get m => widget.message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final mine = m['mine'] == true;
    final deleted = m['deleted'] == true;
    final fill = mine
        ? Color.alphaBlend(
            scheme.primary.withValues(alpha: 0.20),
            scheme.surface,
          )
        : scheme.surfaceContainerHigh;
    final width = MediaQuery.sizeOf(context).width;
    final maxW = math.min(widget.phone ? width * 0.8 : 560.0, 560.0);
    final sent = DateTime.tryParse('${m['sent_at']}')?.toLocal();
    final reactions = (m['reactions'] as List? ?? const []).cast<Map>();

    final meta = Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (m['starred'] == true)
          Padding(
            padding: const EdgeInsets.only(right: 3),
            child: Icon(Icons.star, size: 12, color: scheme.onSurfaceVariant),
          ),
        if (m['edited'] == true)
          Text(
            'Edited ',
            style: theme.textTheme.labelSmall?.copyWith(
              color: scheme.onSurfaceVariant,
            ),
          ),
        Text(
          sent == null ? '' : widget.time(sent),
          style: theme.textTheme.labelSmall?.copyWith(
            color: scheme.onSurfaceVariant,
          ),
        ),
        if (mine && m['status'] != null) ...[
          const SizedBox(width: 3),
          if (m['status'] == 'pending')
            Icon(Icons.schedule, size: 13, color: scheme.onSurfaceVariant)
          else if (m['status'] == 'failed')
            Icon(Icons.error_outline, size: 14, color: scheme.error)
          else
            ReceiptTicks(status: m['status'] as String),
        ],
      ],
    );

    final content = <Widget>[
      if (widget.group && !mine && widget.first)
        Padding(
          padding: const EdgeInsets.only(bottom: 2),
          child: Text(
            '${m['sender_name'] ?? ''}',
            style: theme.textTheme.labelLarge?.copyWith(
              color: senderColor('${m['sender_name']}', theme.brightness),
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
      if (m['forwarded'] == true && !deleted)
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.shortcut, size: 14, color: scheme.onSurfaceVariant),
            const SizedBox(width: 3),
            Text(
              'Forwarded',
              style: theme.textTheme.labelSmall?.copyWith(
                color: scheme.onSurfaceVariant,
                fontStyle: FontStyle.italic,
              ),
            ),
          ],
        ),
      if (m['reply_to'] case final Map q when !deleted)
        Padding(
          padding: const EdgeInsets.only(bottom: 4),
          child: InkWell(
            onTap: () => widget.onQuote('${q['id']}'),
            child: _QuoteBox(
              name: '${q['sender_name'] ?? ''}',
              text: q['body'] == null && q['kind'] == 'text'
                  ? 'This message was deleted'
                  : _snippet(Json.from(q)),
            ),
          ),
        ),
      ..._payload(theme),
    ];

    Widget bubble = Container(
      constraints: BoxConstraints(maxWidth: maxW),
      margin: EdgeInsets.only(
        left: mine ? 48 : 10,
        right: mine ? 10 : 48,
        top: widget.first ? 6 : 1,
      ),
      padding: const EdgeInsets.fromLTRB(9, 6, 9, 5),
      decoration: BoxDecoration(
        color: widget.highlighted
            ? Color.alphaBlend(scheme.tertiary.withValues(alpha: 0.25), fill)
            : fill,
        borderRadius: BorderRadius.only(
          topLeft: Radius.circular(!mine && widget.first ? 2 : 10),
          topRight: Radius.circular(mine && widget.first ? 2 : 10),
          bottomLeft: const Radius.circular(10),
          bottomRight: const Radius.circular(10),
        ),
        boxShadow: [
          BoxShadow(
            color: scheme.shadow.withValues(alpha: 0.06),
            blurRadius: 1,
            offset: const Offset(0, 1),
          ),
        ],
      ),
      // As wide as its words, not the column: the time sits under the end.
      child: IntrinsicWidth(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            ...content,
            Align(alignment: Alignment.bottomRight, child: meta),
          ],
        ),
      ),
    );

    if (reactions.isNotEmpty) {
      bubble = Column(
        crossAxisAlignment: mine
            ? CrossAxisAlignment.end
            : CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          bubble,
          Transform.translate(
            offset: const Offset(0, -4),
            child: Padding(
              padding: EdgeInsets.only(
                left: mine ? 0 : 20,
                right: mine ? 20 : 0,
              ),
              child: Wrap(
                spacing: 4,
                children: [
                  for (final r in reactions)
                    InkWell(
                      key: ValueKey('chat-reaction-${r['emoji']}'),
                      borderRadius: BorderRadius.circular(12),
                      onTap: () => widget.onReact('${r['emoji']}'),
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 6,
                          vertical: 1,
                        ),
                        decoration: BoxDecoration(
                          color: r['mine'] == true
                              ? scheme.primaryContainer
                              : scheme.surfaceContainerHighest,
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(color: scheme.surface, width: 1.5),
                        ),
                        child: Text(
                          '${r['emoji']}${(r['count'] as int? ?? 1) > 1 ? ' ${r['count']}' : ''}',
                          style: theme.textTheme.labelMedium,
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ),
        ],
      );
    }

    // Hover on a keyboard: a chevron for the menu and a quick react, as WhatsApp desktop.
    final hoverTools = !widget.phone && _hover && !deleted
        ? Padding(
            padding: const EdgeInsets.symmetric(horizontal: 2),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                IconButton(
                  tooltip: 'React',
                  visualDensity: VisualDensity.compact,
                  iconSize: 18,
                  icon: const Icon(Icons.add_reaction_outlined),
                  onPressed: () async {
                    final e = await _emojiDialog(context, quickFirst: true);
                    if (e != null) widget.onReact(e);
                  },
                ),
                Builder(
                  builder: (b) => IconButton(
                    key: ValueKey('chat-msg-menu-${m['id']}'),
                    tooltip: 'Message options',
                    visualDensity: VisualDensity.compact,
                    iconSize: 18,
                    icon: const Icon(Icons.expand_more),
                    onPressed: () {
                      final box = b.findRenderObject() as RenderBox?;
                      widget.onMenu(box?.localToGlobal(Offset.zero));
                    },
                  ),
                ),
              ],
            ),
          )
        : const SizedBox(width: 0);

    return MouseRegion(
      onEnter: (_) => setState(() => _hover = true),
      onExit: (_) => setState(() => _hover = false),
      child: GestureDetector(
        behavior: HitTestBehavior.translucent,
        onLongPress: () => widget.onMenu(null),
        onSecondaryTapDown: (d) => widget.onMenu(d.globalPosition),
        onDoubleTap: widget.phone ? null : widget.onReply,
        // Swipe right to reply, as WhatsApp on a phone.
        onHorizontalDragUpdate: widget.phone
            ? (d) => setState(() => _drag = (_drag + d.delta.dx).clamp(0, 80))
            : null,
        onHorizontalDragEnd: widget.phone
            ? (_) {
                if (_drag > 56) widget.onReply();
                setState(() => _drag = 0);
              }
            : null,
        child: Transform.translate(
          offset: Offset(_drag, 0),
          child: Row(
            mainAxisAlignment: mine
                ? MainAxisAlignment.end
                : MainAxisAlignment.start,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (mine) hoverTools,
              Flexible(child: bubble),
              if (!mine) hoverTools,
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _payload(ThemeData theme) {
    final scheme = theme.colorScheme;
    if (m['deleted'] == true) {
      return [
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.block, size: 16, color: scheme.onSurfaceVariant),
            const SizedBox(width: 6),
            Text(
              m['mine'] == true
                  ? 'You deleted this message'
                  : 'This message was deleted',
              style: theme.textTheme.bodyMedium?.copyWith(
                fontStyle: FontStyle.italic,
                color: scheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
      ];
    }
    final a = m['attachment'] as Map?;
    final caption = (m['body'] ?? '').toString();
    final parts = <Widget>[];
    switch (m['kind']) {
      case 'image':
        if (a != null) {
          parts.add(_Picture(store: widget.store, attachment: Json.from(a)));
        }
      case 'voice' || 'audio':
        if (a != null) {
          parts.add(
            _VoicePlayer(
              store: widget.store,
              attachmentId: '${a['id']}',
              seconds: ((m['meta'] as Map?)?['seconds'] as int?) ?? 0,
              voice: m['kind'] == 'voice',
            ),
          );
        }
      case 'video' || 'file':
        if (a != null) {
          parts.add(
            InkWell(
              onTap: widget.onDownload,
              child: Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: scheme.surface.withValues(alpha: 0.5),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(
                      m['kind'] == 'video'
                          ? Icons.play_circle_outline
                          : Icons.insert_drive_file_outlined,
                      size: 32,
                      color: scheme.primary,
                    ),
                    const SizedBox(width: 8),
                    Flexible(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            '${a['name']}',
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                          Text(
                            '${_size(a['size'] as int? ?? 0)} · ${'${a['name']}'.split('.').last.toUpperCase()}',
                            style: theme.textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    Icon(Icons.download, color: scheme.onSurfaceVariant),
                  ],
                ),
              ),
            ),
          );
        }
      case 'call':
        final meta = m['meta'] as Map? ?? const {};
        final missed = meta['status'] == 'missed';
        final secs = meta['duration'] as int? ?? 0;
        parts.add(
          InkWell(
            onTap: widget.onCallBack,
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                CircleAvatar(
                  radius: 16,
                  backgroundColor: missed
                      ? scheme.errorContainer
                      : scheme.primaryContainer,
                  child: Icon(
                    m['mine'] == true ? Icons.call_made : Icons.call_received,
                    size: 18,
                    color: missed ? scheme.error : scheme.primary,
                  ),
                ),
                const SizedBox(width: 10),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(missed ? 'Missed voice call' : 'Voice call'),
                    if (!missed)
                      Text(
                        secs > 0
                            ? formatClock(Duration(seconds: secs))
                            : 'Tap to call back',
                        style: theme.textTheme.bodySmall,
                      ),
                  ],
                ),
              ],
            ),
          ),
        );
    }
    if (caption.isNotEmpty && m['kind'] != 'call') {
      if (parts.isNotEmpty) parts.add(const SizedBox(height: 4));
      parts.add(
        Text.rich(
          TextSpan(children: richText(caption, theme)),
          style: theme.textTheme.bodyLarge,
        ),
      );
    }
    return parts;
  }
}

/// WhatsApp formatting: *bold*, _italic_, ~strike~, ```mono```, and links.
List<InlineSpan> richText(String text, ThemeData theme) {
  final pattern = RegExp(
    // Markers only at word edges, as WhatsApp: Estimate_Elm_St stays plain.
    r'(```[\s\S]+?```)|(?<!\w)(\*[^*\n]+\*)(?!\w)|(?<!\w)(_[^_\n]+_)(?!\w)'
    r'|(?<!\w)(~[^~\n]+~)(?!\w)|(https?://[^\s]+)',
  );
  final spans = <InlineSpan>[];
  var at = 0;
  for (final match in pattern.allMatches(text)) {
    if (match.start > at) {
      spans.add(TextSpan(text: text.substring(at, match.start)));
    }
    final s = match.group(0)!;
    if (match.group(1) != null) {
      spans.add(
        TextSpan(
          text: s.substring(3, s.length - 3),
          style: const TextStyle(fontFamily: 'monospace'),
        ),
      );
    } else if (match.group(2) != null) {
      spans.add(
        TextSpan(
          text: s.substring(1, s.length - 1),
          style: const TextStyle(fontWeight: FontWeight.w700),
        ),
      );
    } else if (match.group(3) != null) {
      spans.add(
        TextSpan(
          text: s.substring(1, s.length - 1),
          style: const TextStyle(fontStyle: FontStyle.italic),
        ),
      );
    } else if (match.group(4) != null) {
      spans.add(
        TextSpan(
          text: s.substring(1, s.length - 1),
          style: const TextStyle(decoration: TextDecoration.lineThrough),
        ),
      );
    } else {
      spans.add(
        TextSpan(
          text: s,
          style: TextStyle(
            color: theme.colorScheme.primary,
            decoration: TextDecoration.underline,
          ),
          recognizer: TapGestureRecognizer()
            ..onTap = () => unawaited(
              launchUrl(Uri.parse(s), mode: LaunchMode.externalApplication),
            ),
        ),
      );
    }
    at = match.end;
  }
  if (at < text.length) spans.add(TextSpan(text: text.substring(at)));
  return spans;
}

class _QuoteBox extends StatelessWidget {
  const _QuoteBox({required this.name, required this.text});

  final String name;
  final String text;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final color = name == 'You' || name == 'Edit message'
        ? theme.colorScheme.primary
        : senderColor(name, theme.brightness);
    return Container(
      decoration: BoxDecoration(
        color: theme.colorScheme.onSurface.withValues(alpha: 0.05),
        borderRadius: BorderRadius.circular(6),
        border: Border(left: BorderSide(color: color, width: 4)),
      ),
      padding: const EdgeInsets.fromLTRB(8, 5, 8, 5),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            name,
            style: theme.textTheme.labelLarge?.copyWith(
              color: color,
              fontWeight: FontWeight.w600,
            ),
          ),
          Text(
            text,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}

class _Picture extends StatefulWidget {
  const _Picture({required this.store, required this.attachment});

  final ChatStore store;
  final Json attachment;

  @override
  State<_Picture> createState() => _PictureState();
}

class _PictureState extends State<_Picture> {
  Uint8List? _bytes;
  bool _failed = false;

  String get _id => '${widget.attachment['id']}';

  @override
  void initState() {
    super.initState();
    _bytes = ChatStore.pictures[_id];
    if (_bytes == null) unawaited(_fetch());
  }

  Future<void> _fetch() async {
    final bytes = await widget.store.api.getBytes('/attachments/$_id', {
      'inline': '1',
    });
    if (!mounted) return;
    setState(() {
      if (bytes == null) {
        _failed = true;
      } else {
        _bytes = ChatStore.pictures[_id] = Uint8List.fromList(bytes);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final bytes = _bytes;
    final theme = Theme.of(context);
    final box = Container(
      width: 260,
      height: 200,
      color: theme.colorScheme.surfaceContainerHighest,
      alignment: Alignment.center,
      child: Icon(_failed ? Icons.broken_image_outlined : Icons.image_outlined),
    );
    if (bytes == null) return box;
    return InkWell(
      onTap: () => showSidePanel<void>(
        context,
        builder: (dialog) => ChatPanel(
          title: Text('${widget.attachment['name']}'),
          width: 760,
          content: SizedBox(
            height: 560,
            child: InteractiveViewer(child: Center(child: Image.memory(bytes))),
          ),
          actions: [
            TextButton(
              onPressed: () =>
                  Files.download('${widget.attachment['name']}', bytes),
              child: const Text('Download'),
            ),
          ],
        ),
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(6),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 300, maxHeight: 320),
          child: Image.memory(
            bytes,
            fit: BoxFit.cover,
            errorBuilder: (_, _, _) => box,
          ),
        ),
      ),
    );
  }
}

class _VoicePlayer extends StatefulWidget {
  const _VoicePlayer({
    required this.store,
    required this.attachmentId,
    required this.seconds,
    required this.voice,
  });

  final ChatStore store;
  final String attachmentId;
  final int seconds;
  final bool voice;

  @override
  State<_VoicePlayer> createState() => _VoicePlayerState();
}

class _VoicePlayerState extends State<_VoicePlayer> {
  RecordingPlayer? _player;
  StreamSubscription<RecordingPlayback>? _sub;
  RecordingPlayback _state = const RecordingPlayback();
  bool _busy = false;

  @override
  void dispose() {
    unawaited(_sub?.cancel());
    _player?.dispose();
    super.dispose();
  }

  Future<void> _toggle() async {
    final player = _player;
    if (player != null) {
      if (_state.playing) {
        await player.pause();
      } else {
        await player.resume();
      }
      return;
    }
    setState(() => _busy = true);
    final bytes = await widget.store.api.getBytes(
      '/attachments/${widget.attachmentId}',
    );
    if (!mounted) return;
    setState(() => _busy = false);
    if (bytes == null) return;
    final p = _player = createRecordingPlayer();
    _sub = p.changes.listen((s) {
      if (mounted) setState(() => _state = s);
    });
    try {
      await p.play(bytes);
    } on RecordingPlaybackFailure catch (e) {
      if (mounted) {
        ScaffoldMessenger.maybeOf(
          context,
        )?.showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final total = _state.loaded
        ? _state.duration
        : Duration(seconds: widget.seconds);
    final speed = _player?.speed ?? 1;
    return SizedBox(
      width: 260,
      child: Row(
        children: [
          if (widget.voice)
            Icon(Icons.mic, size: 18, color: theme.colorScheme.primary),
          IconButton(
            key: ValueKey('chat-voice-play-${widget.attachmentId}'),
            tooltip: _state.playing ? 'Pause' : 'Play',
            icon: _busy
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: Icon(Icons.hourglass_empty, size: 18),
                  )
                : Icon(_state.playing ? Icons.pause : Icons.play_arrow),
            onPressed: _busy ? null : () => unawaited(_toggle()),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SliderTheme(
                  data: SliderTheme.of(context).copyWith(
                    trackHeight: 3,
                    thumbShape: const RoundSliderThumbShape(
                      enabledThumbRadius: 6,
                    ),
                    overlayShape: SliderComponentShape.noOverlay,
                  ),
                  child: Slider(
                    value: total.inMilliseconds == 0
                        ? 0
                        : (_state.position.inMilliseconds /
                                  total.inMilliseconds)
                              .clamp(0.0, 1.0),
                    onChanged: _state.loaded
                        ? (v) => unawaited(_player?.seek(total * v))
                        : null,
                  ),
                ),
                Text(
                  formatClock(
                    _state.playing || _state.position > Duration.zero
                        ? _state.position
                        : total,
                  ),
                  style: theme.textTheme.labelSmall,
                ),
              ],
            ),
          ),
          if (_player != null)
            TextButton(
              onPressed: () async {
                final next = speed >= 2 ? 1.0 : speed + 0.5;
                await _player?.setSpeed(next);
                if (mounted) setState(() {});
              },
              child: Text(
                '${speed == speed.roundToDouble() ? speed.toInt() : speed}×',
              ),
            ),
        ],
      ),
    );
  }
}

class _ReactionBar extends StatelessWidget {
  const _ReactionBar({required this.onPick});

  final ValueChanged<String> onPick;

  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      for (final e in quickReactions)
        InkWell(
          key: ValueKey('chat-quick-$e'),
          borderRadius: BorderRadius.circular(20),
          onTap: () => onPick(e),
          child: Padding(
            padding: const EdgeInsets.all(6),
            child: Text(e, style: const TextStyle(fontSize: 24)),
          ),
        ),
    ],
  );
}

class _EmojiGrid extends StatelessWidget {
  const _EmojiGrid({required this.onPick});

  final ValueChanged<String> onPick;

  @override
  Widget build(BuildContext context) => GridView.extent(
    maxCrossAxisExtent: 44,
    padding: const EdgeInsets.all(6),
    children: [
      for (final e in emojiSet)
        InkWell(
          borderRadius: BorderRadius.circular(8),
          onTap: () => onPick(e),
          child: Center(child: Text(e, style: const TextStyle(fontSize: 24))),
        ),
    ],
  );
}

Future<String?> _emojiDialog(BuildContext context, {bool quickFirst = false}) =>
    showSidePanel<String>(
      context,
      builder: (dialog) => ChatPanel(
        title: const Text('React'),
        content: SizedBox(
          width: 340,
          height: 320,
          child: Column(
            children: [
              _ReactionBar(onPick: (e) => Navigator.of(dialog).pop(e)),
              const Divider(),
              Expanded(
                child: _EmojiGrid(onPick: (e) => Navigator.of(dialog).pop(e)),
              ),
            ],
          ),
        ),
      ),
    );

// ── contact / group info ───────────────────────────────────────────

class ChatInfoPane extends StatefulWidget {
  const ChatInfoPane({
    super.key,
    required this.store,
    required this.chatId,
    required this.session,
    required this.onClose,
    required this.onOpenChat,
    required this.onLeft,
    required this.onSearch,
    required this.onCall,
    required this.onJump,
    this.phone = false,
  });

  /// A phone draws ← (Android Back finds it); a desktop pane closes with ×.
  final bool phone;
  final ChatStore store;
  final String chatId;
  final Session session;
  final VoidCallback onClose;
  final ValueChanged<String> onOpenChat;
  final VoidCallback onLeft;
  final VoidCallback onSearch;
  final VoidCallback onCall;
  final ValueChanged<String> onJump;

  @override
  State<ChatInfoPane> createState() => _ChatInfoPaneState();
}

class _ChatInfoPaneState extends State<ChatInfoPane> {
  Json? _info;
  String? _error;
  String? _gallery; // media | docs | starred
  List<Json> _galleryRows = const [];
  final _memberSearch = TextEditingController();
  final _memberKeys = <Object?, GlobalKey>{};

  /// Where a member's row is drawn, for the desktop menu.
  Offset? _rowAt(Object? id) {
    final box =
        _memberKeys[id]?.currentContext?.findRenderObject() as RenderBox?;
    return box?.localToGlobal(
      Offset(box.size.width - 220, box.size.height / 2),
    );
  }

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  @override
  void dispose() {
    _memberSearch.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final r = await widget.store.api.get('/chat/chats/${widget.chatId}');
    if (!mounted) return;
    setState(() {
      if (r.ok && r.data is Map) {
        _info = Json.from(r.data as Map);
        _error = null;
      } else {
        _error = r.reason;
      }
    });
  }

  Future<void> _openGallery(String which) async {
    setState(() {
      _gallery = which;
      _galleryRows = const [];
    });
    final r = which == 'starred'
        ? await widget.store.api.get('/chat/starred')
        : await widget.store.api.get('/chat/chats/${widget.chatId}/media', {
            'kind': which,
          });
    if (!mounted || !r.ok) return;
    setState(() {
      _galleryRows = [
        for (final m in (r.data as List? ?? const []))
          if (m is Map &&
              (which != 'starred' ||
                  (m['chat'] as Map?)?['id'] == widget.chatId))
            Json.from(m),
      ];
    });
  }

  void _snack(String text) => ScaffoldMessenger.maybeOf(
    context,
  )?.showSnackBar(SnackBar(content: Text(text)));

  Future<void> _patch(Json changes) async {
    final r = await widget.store.api.patch(
      '/chat/chats/${widget.chatId}',
      changes,
    );
    if (!r.ok) _snack(r.reason);
    await _load();
    await widget.store.load();
  }

  Future<void> _editText(String field, String title, {int max = 100}) async {
    final c = TextEditingController(text: '${_info?[field] ?? ''}');
    final ok = await showSidePanel<bool>(
      context,
      builder: (dialog) => ChatPanel(
        title: Text(title),
        content: TextField(
          controller: c,
          autofocus: autofocusOnOpen,
          maxLength: max,
          maxLines: field == 'description' ? 4 : 1,
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialog).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(dialog).pop(true),
            child: const Text('Save'),
          ),
        ],
      ),
    );
    final value = c.text.trim();
    c.dispose();
    if (ok == true) await _patch({field: value});
  }

  Future<void> _addMembers() async {
    final inChat = {
      for (final m in (_info?['members'] as List? ?? const []))
        (m as Map)['id'],
    };
    final candidates = widget.store.contacts
        .where((p) => !inChat.contains(p['id']))
        .toList();
    final picked = <String>{};
    final go = await showSidePanel<bool>(
      context,
      builder: (dialog) => StatefulBuilder(
        builder: (dialog, set) => ChatPanel(
          title: const Text('Add members'),
          content: SizedBox(
            width: 380,
            height: 420,
            child: ListView(
              children: [
                for (final p in candidates)
                  CheckboxListTile(
                    value: picked.contains(p['id']),
                    title: Text('${p['name']}'),
                    onChanged: (_) => set(() {
                      if (!picked.remove(p['id'])) {
                        picked.add(p['id'] as String);
                      }
                    }),
                  ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(dialog).pop(false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: picked.isEmpty
                  ? null
                  : () => Navigator.of(dialog).pop(true),
              child: const Text('Add'),
            ),
          ],
        ),
      ),
    );
    if (go != true) return;
    final r = await widget.store.api.post(
      '/chat/chats/${widget.chatId}/members',
      {'user_ids': picked.toList()},
    );
    if (!r.ok) _snack(r.reason);
    await _load();
    await widget.store.load();
  }

  /// A menu at the row on a desktop, a sheet from the bottom on a phone
  /// (UI/UX 11-25), like the message and chat-row menus.
  Future<void> _memberMenu(Json member, [Offset? at]) async {
    final me = widget.session.me!.id;
    if (member['id'] == me) return;
    final admin = _info?['i_am_admin'] == true;
    final items = <(String, String)>[
      ('message', 'Message ${member['name']}'),
      if (admin)
        (
          'admin',
          member['is_admin'] == true ? 'Dismiss as admin' : 'Make group admin',
        ),
      if (admin) ('remove', 'Remove ${member['name']}'),
    ];
    final String? choice;
    if (!widget.phone && at != null) {
      choice = await showMenu<String>(
        context: context,
        position: RelativeRect.fromLTRB(at.dx, at.dy, at.dx, at.dy),
        items: [
          for (final (v, l) in items) PopupMenuItem(value: v, child: Text(l)),
        ],
      );
    } else {
      choice = await showModalBottomSheet<String>(
        context: context,
        builder: (sheet) => SafeArea(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              for (final (v, l) in items)
                ListTile(
                  title: Text(l),
                  onTap: () => Navigator.of(sheet).pop(v),
                ),
            ],
          ),
        ),
      );
    }
    if (choice == null) return;
    if (choice == 'message') {
      final id = await widget.store.openDirect(member['id'] as String);
      if (id != null) widget.onOpenChat(id);
      return;
    }
    final r = choice == 'admin'
        ? await widget.store.api.patch(
            '/chat/chats/${widget.chatId}/members/${member['id']}',
            {'is_admin': member['is_admin'] != true},
          )
        : await widget.store.api.delete(
            '/chat/chats/${widget.chatId}/members/${member['id']}',
          );
    if (!r.ok) _snack(r.reason);
    await _load();
  }

  Future<void> _leave() async {
    final ok = await showSidePanel<bool>(
      context,
      builder: (dialog) => ChatPanel(
        title: Text('Exit "${_info?['name']}"?'),
        content: const Text('You will stop receiving its messages.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialog).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(dialog).pop(true),
            child: const Text('Exit group'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    final me = widget.session.me!.id;
    final r = await widget.store.api.delete(
      '/chat/chats/${widget.chatId}/members/$me',
    );
    if (!r.ok) {
      _snack(r.reason);
      return;
    }
    await widget.store.load();
    widget.onLeft();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final info = _info;
    final header = SizedBox(
      height: 60,
      child: Row(
        children: [
          IconButton(
            tooltip: 'Close',
            icon: Icon(
              _gallery != null || widget.phone ? Icons.arrow_back : Icons.close,
            ),
            onPressed: () {
              if (_gallery != null) {
                setState(() => _gallery = null);
              } else {
                widget.onClose();
              }
            },
          ),
          Text(switch (_gallery) {
            'media' => 'Media',
            'docs' => 'Docs',
            'starred' => 'Starred messages',
            _ => info?['type'] == 'group' ? 'Group info' : 'Contact info',
          }, style: theme.textTheme.titleMedium),
        ],
      ),
    );

    if (_error != null) {
      return Material(
        child: Column(
          children: [
            header,
            Expanded(child: Center(child: Text(_error!))),
          ],
        ),
      );
    }
    if (info == null) {
      return Material(
        child: Column(
          children: [
            header,
            const Expanded(child: Center(child: Text('Loading…'))),
          ],
        ),
      );
    }
    if (_gallery != null) {
      return Material(
        child: Column(
          children: [
            header,
            Expanded(child: _galleryBody(theme)),
          ],
        ),
      );
    }

    final group = info['type'] == 'group';
    final admin = info['i_am_admin'] == true;
    final members = [
      for (final m in (info['members'] as List? ?? const []))
        if (m is Map) Json.from(m),
    ];
    final q = _memberSearch.text.trim().toLowerCase();
    final shownMembers = members
        .where((m) => q.isEmpty || '${m['name']}'.toLowerCase().contains(q))
        .toList();
    final me = widget.session.me!.id;

    Widget card(List<Widget> children) => Container(
      margin: const EdgeInsets.only(bottom: 10),
      color: theme.colorScheme.surface,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: children,
      ),
    );

    return Material(
      color: theme.colorScheme.surfaceContainerLowest,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Material(color: theme.colorScheme.surface, child: header),
          Expanded(
            child: ListView(
              children: [
                card([
                  const SizedBox(height: 20),
                  Center(
                    child: group
                        ? const CircleAvatar(
                            radius: 56,
                            child: Icon(Icons.groups, size: 56),
                          )
                        : UserAvatar(
                            api: widget.store.api,
                            userId: '${info['user_id']}',
                            name: '${info['name']}',
                            radius: 56,
                          ),
                  ),
                  const SizedBox(height: 12),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Flexible(
                        child: Text(
                          '${info['name']}',
                          textAlign: TextAlign.center,
                          style: theme.textTheme.headlineSmall,
                        ),
                      ),
                      if (group && admin)
                        IconButton(
                          tooltip: 'Edit group name',
                          icon: const Icon(Icons.edit_outlined, size: 18),
                          onPressed: () => _editText('name', 'Group name'),
                        ),
                    ],
                  ),
                  Text(
                    group
                        ? 'Group · ${members.length} members'
                        : '${members.firstWhere((m) => m['id'] != me, orElse: () => {})['email'] ?? ''}',
                    textAlign: TextAlign.center,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                  const SizedBox(height: 12),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      if (!group)
                        _action(
                          theme,
                          Icons.call_outlined,
                          'Voice',
                          widget.onCall,
                        ),
                      _action(theme, Icons.search, 'Search', widget.onSearch),
                    ],
                  ),
                  const SizedBox(height: 12),
                ]),
                if (group)
                  card([
                    ListTile(
                      title: Text(
                        (info['description'] ?? '').toString().isEmpty
                            ? (admin
                                  ? 'Add group description'
                                  : 'No description')
                            : '${info['description']}',
                      ),
                      trailing: admin
                          ? const Icon(Icons.edit_outlined, size: 18)
                          : null,
                      onTap: admin
                          ? () => _editText(
                              'description',
                              'Group description',
                              max: 500,
                            )
                          : null,
                    ),
                  ]),
                card([
                  ListTile(
                    leading: const Icon(Icons.photo_library_outlined),
                    title: const Text('Media'),
                    trailing: Text('${info['media_count'] ?? 0}'),
                    onTap: () => unawaited(_openGallery('media')),
                  ),
                  ListTile(
                    leading: const Icon(Icons.description_outlined),
                    title: const Text('Docs'),
                    onTap: () => unawaited(_openGallery('docs')),
                  ),
                  ListTile(
                    leading: const Icon(Icons.star_outline),
                    title: const Text('Starred messages'),
                    trailing: Text('${info['starred_count'] ?? 0}'),
                    onTap: () => unawaited(_openGallery('starred')),
                  ),
                ]),
                card([
                  SwitchListTile(
                    key: const ValueKey('chat-info-mute'),
                    secondary: const Icon(Icons.notifications_off_outlined),
                    title: const Text('Mute notifications'),
                    value: info['muted'] == true,
                    onChanged: (on) async {
                      final hours = on ? await pickMuteHours(context) : 0;
                      if (hours == null) return;
                      await widget.store.settings(widget.chatId, {
                        'mute_hours': hours,
                      });
                      await _load();
                    },
                  ),
                  if (group && admin)
                    SwitchListTile(
                      secondary: const Icon(Icons.campaign_outlined),
                      title: const Text('Only admins can send messages'),
                      value: info['only_admins_send'] == true,
                      onChanged: (on) => _patch({'only_admins_send': on}),
                    ),
                ]),
                if (group)
                  card([
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
                      child: Text(
                        '${members.length} members',
                        style: theme.textTheme.titleSmall,
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                      child: TextField(
                        key: const ValueKey('chat-members-search'),
                        controller: _memberSearch,
                        onChanged: (_) => setState(() {}),
                        decoration: const InputDecoration(
                          isDense: true,
                          hintText: 'Search members',
                          prefixIcon: Icon(Icons.search, size: 20),
                        ),
                      ),
                    ),
                    if (admin)
                      ListTile(
                        key: const ValueKey('chat-add-members'),
                        leading: const CircleAvatar(
                          child: Icon(Icons.person_add_alt),
                        ),
                        title: const Text('Add members'),
                        onTap: () => unawaited(_addMembers()),
                      ),
                    for (final m in shownMembers)
                      ListTile(
                        key: _memberKeys.putIfAbsent(m['id'], GlobalKey.new),
                        leading: UserAvatar(
                          api: widget.store.api,
                          userId: '${m['id']}',
                          name: '${m['name']}',
                          radius: 20,
                        ),
                        title: Text(m['id'] == me ? 'You' : '${m['name']}'),
                        subtitle: Text(
                          m['online'] == true
                              ? 'online'
                              : '${m['email'] ?? ''}',
                        ),
                        trailing: m['is_admin'] == true
                            ? Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 6,
                                  vertical: 2,
                                ),
                                decoration: BoxDecoration(
                                  color: theme.colorScheme.primaryContainer,
                                  borderRadius: BorderRadius.circular(6),
                                ),
                                child: Text(
                                  'Group admin',
                                  style: theme.textTheme.labelSmall,
                                ),
                              )
                            : null,
                        onTap: () => unawaited(_memberMenu(m, _rowAt(m['id']))),
                      ),
                  ]),
                card([
                  ListTile(
                    leading: Icon(
                      Icons.cleaning_services_outlined,
                      color: theme.colorScheme.error,
                    ),
                    title: Text(
                      'Clear chat',
                      style: TextStyle(color: theme.colorScheme.error),
                    ),
                    onTap: () async {
                      if (!await confirmClear(context)) return;
                      await widget.store.settings(widget.chatId, {
                        'clear': true,
                      });
                      widget.onClose();
                    },
                  ),
                  if (group)
                    ListTile(
                      key: const ValueKey('chat-exit-group'),
                      leading: Icon(
                        Icons.logout,
                        color: theme.colorScheme.error,
                      ),
                      title: Text(
                        'Exit group',
                        style: TextStyle(color: theme.colorScheme.error),
                      ),
                      onTap: () => unawaited(_leave()),
                    ),
                ]),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _action(
    ThemeData theme,
    IconData icon,
    String label,
    VoidCallback onTap,
  ) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 6),
    child: OutlinedButton(
      onPressed: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [Icon(icon), const SizedBox(height: 4), Text(label)],
        ),
      ),
    ),
  );

  Widget _galleryBody(ThemeData theme) {
    if (_galleryRows.isEmpty) {
      return const Center(child: Text('Nothing here yet.'));
    }
    if (_gallery == 'media') {
      return GridView.extent(
        maxCrossAxisExtent: 120,
        padding: const EdgeInsets.all(4),
        mainAxisSpacing: 4,
        crossAxisSpacing: 4,
        children: [
          for (final m in _galleryRows)
            if (m['attachment'] case final Map a)
              m['kind'] == 'image'
                  ? FittedBox(
                      fit: BoxFit.cover,
                      clipBehavior: Clip.hardEdge,
                      child: _Picture(
                        store: widget.store,
                        attachment: Json.from(a),
                      ),
                    )
                  : InkWell(
                      onTap: () => widget.onJump('${m['id']}'),
                      child: Container(
                        color: theme.colorScheme.surfaceContainerHighest,
                        child: const Icon(Icons.play_circle_outline, size: 40),
                      ),
                    ),
        ],
      );
    }
    return ListView(
      children: [
        for (final m in _galleryRows)
          ListTile(
            leading: Icon(
              _gallery == 'starred'
                  ? Icons.star
                  : Icons.insert_drive_file_outlined,
            ),
            title: Text(
              _gallery == 'starred'
                  ? _snippet(m)
                  : '${(m['attachment'] as Map?)?['name'] ?? ''}',
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
            subtitle: Text(
              '${m['mine'] == true ? 'You' : m['sender_name'] ?? ''}',
            ),
            onTap: () => widget.onJump('${m['id']}'),
          ),
      ],
    );
  }
}
