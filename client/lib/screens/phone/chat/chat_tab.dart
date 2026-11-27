// Chat: EzBizi's own WhatsApp between EzBizi accounts (Jose, 2026-11-25).
//
// Desktop draws WhatsApp's two panes: the chat list on the left (its width is
// a preference, dragged on the divider) and the open chat on the right. A
// phone draws one at a time, in place, with a ← back (containment: no route
// is pushed, so the shell and its sidebar stay).

import 'dart:async';

import 'package:flutter/material.dart';

import '../../../core/api_client.dart';
import '../../../core/session.dart';
import '../../../core/soft_keyboard.dart';
import '../../../widgets/side_panel.dart';
import '../../../widgets/user_avatar.dart';
import 'chat_store.dart';
import 'chat_thread.dart';

class ChatTab extends StatefulWidget {
  const ChatTab({
    super.key,
    required this.api,
    required this.session,
    this.onOpenChanged,
  });

  /// A chat opened (true) or closed (false); EzChat hides its bottom bar
  /// while one is open, as WhatsApp does.
  final ValueChanged<bool>? onOpenChanged;

  final ApiClient api;
  final Session session;

  @override
  State<ChatTab> createState() => _ChatTabState();
}

enum _Side { chats, archived, newChat, newGroup, starred }

class _ChatTabState extends State<ChatTab> {
  late final ChatStore _store = ChatStore(widget.api, widget.session.me!.id)
    ..start();

  String? _open;
  bool _toldOpen = false;
  _Side _side = _Side.chats;
  String _filter = 'all';
  final _search = TextEditingController();
  List<Json> _found = const [];
  int _searchSeq = 0;
  Timer? _searchDebounce;
  double? _dragWidth;

  // New group: who, then what it is called.
  final Set<String> _picked = {};
  bool _creating = false;
  bool _naming = false;
  final _groupName = TextEditingController();
  String? _groupError;

  List<Json> _starred = const [];

  @override
  void dispose() {
    _store.dispose();
    _search.dispose();
    _groupName.dispose();
    _searchDebounce?.cancel();
    super.dispose();
  }

  String _time(DateTime at) =>
      widget.session.me!.display.formatTime(at.hour, at.minute);

  void _openChat(String id) => setState(() {
    _open = id;
    _side = _Side.chats;
  });

  Future<void> _startWith(String userId) async {
    final id = await _store.openDirect(userId);
    if (id != null && mounted) _openChat(id);
  }

  void _queryChanged(String q) {
    _searchDebounce?.cancel();
    setState(() {});
    if (q.trim().length < 2) {
      setState(() => _found = const []);
      return;
    }
    _searchDebounce = Timer(const Duration(milliseconds: 300), () async {
      final seq = ++_searchSeq;
      final r = await widget.api.get('/chat/search', {'q': q.trim()});
      if (!mounted || seq != _searchSeq) return;
      setState(
        () => _found = r.ok && r.data is List
            ? [
                for (final m in r.data as List)
                  if (m is Map) Json.from(m),
              ]
            : const [],
      );
    });
  }

  Future<void> _loadStarred() async {
    final r = await widget.api.get('/chat/starred');
    if (!mounted) return;
    setState(
      () => _starred = r.ok && r.data is List
          ? [
              for (final m in r.data as List)
                if (m is Map) Json.from(m),
            ]
          : const [],
    );
  }

  @override
  Widget build(BuildContext context) {
    if ((_open != null) != _toldOpen) {
      _toldOpen = _open != null;
      final open = _toldOpen;
      WidgetsBinding.instance.addPostFrameCallback(
        (_) => widget.onOpenChanged?.call(open),
      );
    }
    // Offstage when another Communication tab is showing: pulse slowly then.
    _store.visible = TickerMode.valuesOf(context).enabled;

    return ListenableBuilder(
      listenable: _store,
      builder: (context, _) => LayoutBuilder(
        builder: (context, box) {
          if (box.maxWidth < 760) return _phone(context);
          return _desk(context, box.maxWidth);
        },
      ),
    );
  }

  Widget _phone(BuildContext context) {
    final open = _open;
    if (open != null) {
      return ChatThread(
        key: ValueKey('thread-$open'),
        store: _store,
        session: widget.session,
        chatId: open,
        phone: true,
        onBack: () => setState(() => _open = null),
        onOpenChat: _openChat,
      );
    }
    return Stack(
      children: [
        Positioned.fill(child: _sidePane(context)),
        if (_side == _Side.chats)
          Positioned(
            right: 16,
            bottom: 16,
            child: FloatingActionButton(
              key: const ValueKey('chat-new'),
              tooltip: 'New chat',
              onPressed: () => setState(() => _side = _Side.newChat),
              child: const Icon(Icons.add_comment_outlined),
            ),
          ),
      ],
    );
  }

  Widget _desk(BuildContext context, double width) {
    final listWidth = (_dragWidth ?? widget.session.me!.chatListWidth).clamp(
      300.0,
      (width - 360).clamp(300.0, 640.0),
    );
    final open = _open;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SizedBox(width: listWidth, child: _sidePane(context)),
        MouseRegion(
          cursor: SystemMouseCursors.resizeLeftRight,
          child: GestureDetector(
            key: const ValueKey('chat-pane-divider'),
            behavior: HitTestBehavior.opaque,
            onHorizontalDragUpdate: (d) =>
                setState(() => _dragWidth = listWidth + d.delta.dx),
            onHorizontalDragEnd: (_) => widget.session.setPreference(
              'chat.list_width',
              listWidth.round(),
            ),
            child: const SizedBox(
              width: 8,
              child: Center(child: VerticalDivider(width: 1)),
            ),
          ),
        ),
        Expanded(
          child: open == null
              ? _welcome(context)
              : ChatThread(
                  key: ValueKey('thread-$open'),
                  store: _store,
                  session: widget.session,
                  chatId: open,
                  phone: false,
                  onBack: () => setState(() => _open = null),
                  onOpenChat: _openChat,
                ),
        ),
      ],
    );
  }

  Widget _welcome(BuildContext context) {
    final theme = Theme.of(context);
    return ColoredBox(
      color: theme.colorScheme.surfaceContainerLowest,
      child: Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.forum_outlined,
                size: 72,
                color: theme.colorScheme.primary,
              ),
              const SizedBox(height: 16),
              Text('EzBizi Chat', style: theme.textTheme.headlineSmall),
              const SizedBox(height: 8),
              Text(
                'Message and call your colleagues. Photos, documents and '
                'voice messages included.',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 24),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(
                    Icons.lock_outline,
                    size: 14,
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                  const SizedBox(width: 6),
                  Text(
                    'Messages stay inside your organization.',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  // ── the left pane ──────────────────────────────────────────────

  Widget _sidePane(BuildContext context) => switch (_side) {
    _Side.chats => _chatsPane(context, archived: false),
    _Side.archived => _chatsPane(context, archived: true),
    _Side.newChat => _newChatPane(context),
    _Side.newGroup => _newGroupPane(context),
    _Side.starred => _starredPane(context),
  };

  Widget _paneHeader(
    BuildContext context,
    String title, {
    VoidCallback? back,
    List<Widget> actions = const [],
  }) {
    final theme = Theme.of(context);
    return SizedBox(
      height: 56,
      child: Row(
        children: [
          if (back != null)
            IconButton(
              tooltip: 'Back',
              icon: const Icon(Icons.arrow_back),
              onPressed: back,
            )
          else
            const SizedBox(width: 16),
          Expanded(
            child: Text(
              title,
              style: theme.textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w600,
              ),
              overflow: TextOverflow.ellipsis,
            ),
          ),
          ...actions,
          const SizedBox(width: 4),
        ],
      ),
    );
  }

  Widget _searchField(String hint, {Key key = const ValueKey('chat-search')}) =>
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
        child: TextField(
          key: key,
          controller: _search,
          onChanged: _queryChanged,
          decoration: InputDecoration(
            isDense: true,
            hintText: hint,
            prefixIcon: const Icon(Icons.search, size: 20),
            suffixIcon: _search.text.isEmpty
                ? null
                : IconButton(
                    tooltip: 'Clear search',
                    icon: const Icon(Icons.close, size: 18),
                    onPressed: () {
                      _search.clear();
                      _queryChanged('');
                    },
                  ),
            filled: true,
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(24),
              borderSide: BorderSide.none,
            ),
          ),
        ),
      );

  Widget _chatsPane(BuildContext context, {required bool archived}) {
    final theme = Theme.of(context);
    final q = _search.text.trim().toLowerCase();
    final archivedCount = _store.chats
        .where((c) => c['archived'] == true)
        .length;

    var rows = _store.chats
        .where((c) => (c['archived'] == true) == archived)
        .where((c) => q.isEmpty || '${c['name']}'.toLowerCase().contains(q))
        .where(
          (c) => switch (_filter) {
            'unread' =>
              (c['unread'] as int? ?? 0) > 0 || c['marked_unread'] == true,
            'groups' => c['type'] == 'group',
            // Chats tied to a job (design 213 B1; Chat 9820ac23a).
            'jobs' => c['job'] is Map,
            _ => true,
          },
        )
        .toList();
    // Pinned first (newest pin on top), then by activity, as WhatsApp.
    rows = [
      ...rows.where((c) => c['pinned'] == true),
      ...rows.where((c) => c['pinned'] != true),
    ];
    // Colleagues matching the search who have no chat yet.
    final people = q.length < 2
        ? const <Json>[]
        : _store.contacts
              .where((p) => '${p['name']}'.toLowerCase().contains(q))
              .where(
                (p) => !_store.chats.any(
                  (c) => c['type'] == 'direct' && c['user_id'] == p['id'],
                ),
              )
              .toList();

    return Material(
      color: theme.colorScheme.surface,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _paneHeader(
            context,
            archived ? 'Archived' : 'Chats',
            back: archived ? () => setState(() => _side = _Side.chats) : null,
            actions: archived
                ? const []
                : [
                    IconButton(
                      key: const ValueKey('chat-new-desk'),
                      tooltip: 'New chat',
                      icon: const Icon(Icons.add_comment_outlined),
                      onPressed: () => setState(() => _side = _Side.newChat),
                    ),
                    PopupMenuButton<String>(
                      tooltip: 'Menu',
                      icon: const Icon(Icons.more_vert),
                      onSelected: (v) {
                        if (v == 'group') {
                          setState(() {
                            _picked.clear();
                            _naming = false;
                            _side = _Side.newGroup;
                          });
                        } else if (v == 'starred') {
                          setState(() => _side = _Side.starred);
                          unawaited(_loadStarred());
                        } else if (v == 'archived') {
                          setState(() => _side = _Side.archived);
                        } else if (v == 'read') {
                          unawaited(_store.readAll());
                        }
                      },
                      itemBuilder: (_) => const [
                        PopupMenuItem(value: 'group', child: Text('New group')),
                        PopupMenuItem(
                          value: 'starred',
                          child: Text('Starred messages'),
                        ),
                        PopupMenuItem(
                          value: 'archived',
                          child: Text('Archived'),
                        ),
                        PopupMenuItem(
                          value: 'read',
                          child: Text('Mark all read'),
                        ),
                      ],
                    ),
                  ],
          ),
          _searchField('Search or start a new chat'),
          if (!archived)
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 6),
              child: Wrap(
                key: const ValueKey('chat-filters'),
                spacing: 8,
                children: [
                  for (final (key, label) in const [
                    ('all', 'All'),
                    ('unread', 'Unread'),
                    ('jobs', 'Jobs'),
                    ('groups', 'Groups'),
                  ])
                    ChoiceChip(
                      key: ValueKey('chat-filter-$key'),
                      label: Text(label),
                      selected: _filter == key,
                      showCheckmark: false,
                      onSelected: (_) => setState(() => _filter = key),
                    ),
                ],
              ),
            ),
          const Divider(height: 1),
          Expanded(
            child: !_store.loaded
                ? const Center(child: Text('Loading your chats…'))
                : ListView(
                    padding: const EdgeInsets.only(bottom: 88),
                    children: [
                      if (!archived && archivedCount > 0 && q.isEmpty)
                        ListTile(
                          key: const ValueKey('chat-archived-row'),
                          leading: const SizedBox(
                            width: 48,
                            child: Icon(Icons.archive_outlined),
                          ),
                          title: const Text('Archived'),
                          trailing: Text('$archivedCount'),
                          onTap: () => setState(() => _side = _Side.archived),
                        ),
                      if (_store.error != null)
                        Padding(
                          padding: const EdgeInsets.all(16),
                          child: Text(_store.error!),
                        ),
                      for (final c in rows) _chatRow(context, c),
                      if (rows.isEmpty && q.isEmpty && _store.error == null)
                        Padding(
                          padding: const EdgeInsets.all(24),
                          child: Text(
                            archived
                                ? 'No archived chats.'
                                : _filter == 'all'
                                ? 'No chats yet. Start one with the new chat button.'
                                : 'Nothing here.',
                            textAlign: TextAlign.center,
                            style: theme.textTheme.bodyMedium?.copyWith(
                              color: theme.colorScheme.onSurfaceVariant,
                            ),
                          ),
                        ),
                      if (people.isNotEmpty) ...[
                        _section(context, 'Contacts'),
                        for (final p in people) _personRow(context, p),
                      ],
                      if (_found.isNotEmpty) ...[
                        _section(context, 'Messages'),
                        for (final m in _found) _foundRow(context, m),
                      ],
                    ],
                  ),
          ),
        ],
      ),
    );
  }

  Widget _section(BuildContext context, String title) => Padding(
    padding: const EdgeInsets.fromLTRB(16, 16, 16, 6),
    child: Text(
      title.toUpperCase(),
      style: Theme.of(context).textTheme.labelMedium?.copyWith(
        color: Theme.of(context).colorScheme.primary,
        letterSpacing: 0.8,
      ),
    ),
  );

  Widget _avatar(Json chat, {double radius = 24}) {
    if (chat['type'] == 'group') {
      return CircleAvatar(
        radius: radius,
        child: Icon(Icons.groups, size: radius),
      );
    }
    final id = chat['user_id'] as String?;
    if (id == null) {
      return CircleAvatar(radius: radius, child: const Icon(Icons.person));
    }
    return UserAvatar(
      api: widget.api,
      userId: id,
      name: '${chat['name']}',
      radius: radius,
    );
  }

  String _stamp(String? iso) {
    final at = DateTime.tryParse(iso ?? '')?.toLocal();
    if (at == null) return '';
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final day = DateTime(at.year, at.month, at.day);
    final diff = today.difference(day).inDays;
    if (diff == 0) return _time(at);
    if (diff == 1) return 'Yesterday';
    if (diff < 7) {
      return const [
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
        'Sunday',
      ][at.weekday - 1];
    }
    return widget.session.me!.display.formatDate(at);
  }

  Widget _chatRow(BuildContext context, Json c) {
    final theme = Theme.of(context);
    final id = c['id'] as String;
    final last = c['last'] as Map?;
    final unread = c['unread'] as int? ?? 0;
    final showUnread = unread > 0 || c['marked_unread'] == true;
    final typing = _store.typing[id] ?? const [];
    final muted = c['muted'] == true;
    final selected = _open == id;

    Widget preview;
    if (typing.isNotEmpty) {
      preview = Text(
        presenceLine(c, typing, _time),
        style: TextStyle(color: theme.colorScheme.primary),
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      );
    } else {
      final status = last?['status'] as String?;
      final who =
          c['type'] == 'group' && last != null && last['kind'] != 'system'
          ? '${last['mine'] == true ? 'You' : '${last['sender_name'] ?? ''}'.split(' ').first}: '
          : '';
      preview = Row(
        children: [
          if (status != null) ...[
            ReceiptTicks(status: status, size: 16),
            const SizedBox(width: 3),
          ],
          Expanded(
            child: Text(
              '$who${last?['text'] ?? ''}',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
                fontStyle: last?['deleted'] == true ? FontStyle.italic : null,
              ),
            ),
          ),
        ],
      );
    }

    return Material(
      color: selected
          ? theme.colorScheme.secondaryContainer.withValues(alpha: 0.6)
          : theme.colorScheme.surface.withValues(alpha: 0),
      child: InkWell(
        key: ValueKey('chat-row-$id'),
        onTap: () {
          _openChat(id);
          if (c['marked_unread'] == true) {
            unawaited(_store.settings(id, {'marked_unread': false}));
          }
        },
        onLongPress: () => _rowMenu(context, c, null),
        onSecondaryTapDown: (d) => _rowMenu(context, c, d.globalPosition),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
          child: Row(
            children: [
              _avatar(c),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            '${c['name']}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.titleMedium,
                          ),
                        ),
                        Text(
                          _stamp(last?['sent_at'] as String?),
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: showUnread && !muted
                                ? theme.colorScheme.primary
                                : theme.colorScheme.onSurfaceVariant,
                          ),
                        ),
                      ],
                    ),
                    // The job a chat is about: "JOB-05112 · Hartford".
                    if (c['job'] case final Map job)
                      Text(
                        [
                          '${job['number'] ?? ''}',
                          '${job['city'] ?? ''}',
                        ].where((s) => s.isNotEmpty).join(' · '),
                        key: ValueKey('chat-job-$id'),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.labelMedium?.copyWith(
                          color: theme.colorScheme.primary,
                        ),
                      ),
                    const SizedBox(height: 2),
                    Row(
                      children: [
                        Expanded(child: preview),
                        if (muted)
                          Padding(
                            padding: const EdgeInsets.only(left: 4),
                            child: Icon(
                              Icons.volume_off,
                              size: 16,
                              color: theme.colorScheme.onSurfaceVariant,
                            ),
                          ),
                        if (c['pinned'] == true)
                          Padding(
                            padding: const EdgeInsets.only(left: 4),
                            child: Icon(
                              Icons.push_pin,
                              size: 16,
                              color: theme.colorScheme.onSurfaceVariant,
                            ),
                          ),
                        if (showUnread)
                          Container(
                            key: ValueKey('chat-unread-$id'),
                            margin: const EdgeInsets.only(left: 6),
                            padding: const EdgeInsets.symmetric(horizontal: 6),
                            constraints: const BoxConstraints(
                              minWidth: 20,
                              minHeight: 20,
                            ),
                            alignment: Alignment.center,
                            decoration: BoxDecoration(
                              color: muted
                                  ? theme.colorScheme.outline
                                  : theme.colorScheme.primary,
                              borderRadius: BorderRadius.circular(10),
                            ),
                            child: Text(
                              unread > 0 ? '$unread' : '',
                              style: theme.textTheme.labelSmall?.copyWith(
                                color: theme.colorScheme.onPrimary,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _rowMenu(BuildContext context, Json c, Offset? at) async {
    final id = c['id'] as String;
    final items = <(String, String)>[
      (
        c['archived'] == true ? 'unarchive' : 'archive',
        c['archived'] == true ? 'Unarchive chat' : 'Archive chat',
      ),
      (
        c['muted'] == true ? 'unmute' : 'mute',
        c['muted'] == true ? 'Unmute notifications' : 'Mute notifications',
      ),
      (
        c['pinned'] == true ? 'unpin' : 'pin',
        c['pinned'] == true ? 'Unpin chat' : 'Pin chat',
      ),
      (
        (c['unread'] as int? ?? 0) > 0 || c['marked_unread'] == true
            ? 'read'
            : 'unread',
        (c['unread'] as int? ?? 0) > 0 || c['marked_unread'] == true
            ? 'Mark as read'
            : 'Mark as unread',
      ),
      ('clear', 'Clear chat'),
    ];
    final String? choice;
    if (at != null) {
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
    if (choice == null || !context.mounted) return;
    final Json change = switch (choice) {
      'archive' => {'archived': true},
      'unarchive' => {'archived': false},
      'unmute' => {'mute_hours': 0},
      'pin' => {'pinned': true},
      'unpin' => {'pinned': false},
      'read' => {'marked_unread': false},
      'unread' => {'marked_unread': true},
      'clear' => {'clear': true},
      _ => <String, dynamic>{},
    };
    if (choice == 'mute') {
      final hours = await pickMuteHours(context);
      if (hours == null) return;
      change['mute_hours'] = hours;
    }
    if (choice == 'clear') {
      if (!context.mounted || !await confirmClear(context)) return;
    }
    final refused = await _store.settings(id, change);
    if (refused != null && context.mounted) {
      ScaffoldMessenger.maybeOf(
        context,
      )?.showSnackBar(SnackBar(content: Text(refused)));
    }
  }

  Widget _personRow(
    BuildContext context,
    Json p, {
    Widget? trailing,
    VoidCallback? onTap,
  }) {
    final theme = Theme.of(context);
    return ListTile(
      key: ValueKey('chat-person-${p['id']}'),
      leading: Stack(
        children: [
          UserAvatar(
            api: widget.api,
            userId: p['id'] as String,
            name: '${p['name']}',
            radius: 22,
          ),
          if (p['online'] == true)
            Positioned(
              right: 0,
              bottom: 0,
              child: Container(
                width: 12,
                height: 12,
                decoration: BoxDecoration(
                  color: theme.colorScheme.primary,
                  shape: BoxShape.circle,
                  border: Border.all(
                    color: theme.colorScheme.surface,
                    width: 2,
                  ),
                ),
              ),
            ),
        ],
      ),
      title: Text('${p['name']}'),
      subtitle: Text(
        p['online'] == true ? 'online' : '${p['email'] ?? ''}',
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
      ),
      trailing: trailing,
      onTap: onTap ?? () => _startWith(p['id'] as String),
    );
  }

  Widget _foundRow(BuildContext context, Json m) {
    final theme = Theme.of(context);
    final chat = m['chat'] as Map? ?? const {};
    return ListTile(
      title: Text('${chat['name'] ?? ''}'),
      subtitle: Text(
        '${m['mine'] == true ? 'You' : m['sender_name'] ?? ''}: ${m['body'] ?? ''}',
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
      ),
      trailing: Text(
        _stamp(m['sent_at'] as String?),
        style: theme.textTheme.bodySmall,
      ),
      onTap: () => setState(() {
        _open = chat['id'] as String?;
        ChatThread.jumpTo = m['id'] as String?;
      }),
    );
  }

  Widget _newChatPane(BuildContext context) {
    final q = _search.text.trim().toLowerCase();
    final people = _store.contacts
        .where(
          (p) =>
              q.isEmpty ||
              '${p['name']} ${p['email']}'.toLowerCase().contains(q),
        )
        .toList();
    return Material(
      color: Theme.of(context).colorScheme.surface,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _paneHeader(
            context,
            'New chat',
            back: () => setState(() => _side = _Side.chats),
          ),
          _searchField(
            'Search name or email',
            key: const ValueKey('chat-contacts-search'),
          ),
          Expanded(
            child: ListView(
              children: [
                ListTile(
                  key: const ValueKey('chat-new-group'),
                  leading: const CircleAvatar(child: Icon(Icons.group_add)),
                  title: const Text('New group'),
                  onTap: () => setState(() {
                    _picked.clear();
                    _naming = false;
                    _side = _Side.newGroup;
                  }),
                ),
                _section(context, 'Colleagues on EzBizi'),
                for (final p in people) _personRow(context, p),
                if (people.isEmpty)
                  const Padding(
                    padding: EdgeInsets.all(24),
                    child: Text('Nobody matches.', textAlign: TextAlign.center),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _newGroupPane(BuildContext context) {
    final theme = Theme.of(context);
    final q = _search.text.trim().toLowerCase();

    if (_naming) {
      return Material(
        color: theme.colorScheme.surface,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _paneHeader(
              context,
              'New group',
              back: () => setState(() => _naming = false),
            ),
            Padding(
              padding: const EdgeInsets.all(16),
              child: TextField(
                key: const ValueKey('chat-group-name'),
                controller: _groupName,
                autofocus: autofocusOnOpen,
                maxLength: 100,
                decoration: InputDecoration(
                  labelText: 'Group name',
                  errorText: _groupError,
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: Text(
                '${_picked.length + 1} members',
                style: theme.textTheme.bodySmall,
              ),
            ),
            const Spacer(),
            Padding(
              padding: const EdgeInsets.all(16),
              child: FilledButton.icon(
                key: const ValueKey('chat-group-create'),
                icon: const Icon(Icons.check),
                label: const Text('Create group'),
                // One group per tap: a double tap made two (audit 11-27).
                onPressed: _creating
                    ? null
                    : () async {
                        setState(() => _creating = true);
                        try {
                          final r = await widget.api.post('/chat/chats', {
                            'name': _groupName.text.trim(),
                            'user_ids': _picked.toList(),
                          });
                          if (!mounted) return;
                          if (!r.ok || r.data is! Map) {
                            setState(() => _groupError = r.reason);
                            return;
                          }
                          _groupName.clear();
                          _groupError = null;
                          await _store.load();
                          _openChat((r.data as Map)['id'] as String);
                        } finally {
                          if (mounted) setState(() => _creating = false);
                        }
                      },
              ),
            ),
          ],
        ),
      );
    }

    final people = _store.contacts
        .where((p) => q.isEmpty || '${p['name']}'.toLowerCase().contains(q))
        .toList();
    return Material(
      color: theme.colorScheme.surface,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _paneHeader(
            context,
            'Add group members',
            back: () => setState(() => _side = _Side.newChat),
          ),
          if (_picked.isNotEmpty)
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
              child: Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  for (final id in _picked)
                    InputChip(
                      label: Text(
                        '${_store.contacts.firstWhere((p) => p['id'] == id, orElse: () => {'name': ''})['name']}',
                      ),
                      onDeleted: () => setState(() => _picked.remove(id)),
                    ),
                ],
              ),
            ),
          _searchField('Search name', key: const ValueKey('chat-group-search')),
          Expanded(
            child: ListView(
              children: [
                for (final p in people)
                  _personRow(
                    context,
                    p,
                    trailing: Checkbox(
                      value: _picked.contains(p['id']),
                      onChanged: (_) => setState(() {
                        if (!_picked.remove(p['id'])) {
                          _picked.add(p['id'] as String);
                        }
                      }),
                    ),
                    onTap: () => setState(() {
                      if (!_picked.remove(p['id'])) {
                        _picked.add(p['id'] as String);
                      }
                    }),
                  ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: FilledButton.icon(
              key: const ValueKey('chat-group-next'),
              icon: const Icon(Icons.arrow_forward),
              label: const Text('Next'),
              onPressed: _picked.isEmpty
                  ? null
                  : () => setState(() => _naming = true),
            ),
          ),
        ],
      ),
    );
  }

  Widget _starredPane(BuildContext context) {
    final q = _search.text.trim().toLowerCase();
    final rows = _starred
        .where(
          (m) => q.isEmpty || '${m['body'] ?? ''}'.toLowerCase().contains(q),
        )
        .toList();
    return Material(
      color: Theme.of(context).colorScheme.surface,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _paneHeader(
            context,
            'Starred messages',
            back: () => setState(() => _side = _Side.chats),
          ),
          _searchField(
            'Search starred',
            key: const ValueKey('chat-starred-search'),
          ),
          Expanded(
            child: rows.isEmpty
                ? const Center(
                    child: Padding(
                      padding: EdgeInsets.all(24),
                      child: Text(
                        'No starred messages. Star a message to find it here later.',
                        textAlign: TextAlign.center,
                      ),
                    ),
                  )
                : ListView(
                    children: [for (final m in rows) _foundRow(context, m)],
                  ),
          ),
        ],
      ),
    );
  }
}

/// ✓ sent, ✓✓ delivered, ✓✓ in the accent colour read.
class ReceiptTicks extends StatelessWidget {
  const ReceiptTicks({super.key, required this.status, this.size = 15});

  final String status;
  final double size;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Icon(
      status == 'sent' ? Icons.done : Icons.done_all,
      key: ValueKey('ticks-$status'),
      size: size,
      color: status == 'read' ? scheme.primary : scheme.onSurfaceVariant,
      semanticLabel: status,
    );
  }
}

/// 8 hours, 1 week or always — WhatsApp's three.
Future<int?> pickMuteHours(BuildContext context) => showSidePanel<int>(
  context,
  builder: (dialog) => ChatPanel(
    title: const Text('Mute notifications'),
    content: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (final (h, l) in const [
          (8, '8 hours'),
          (168, '1 week'),
          (-1, 'Always'),
        ])
          ListTile(
            key: ValueKey('chat-mute-$h'),
            title: Text(l),
            onTap: () => Navigator.of(dialog).pop(h),
          ),
      ],
    ),
  ),
);

Future<bool> confirmClear(BuildContext context) async =>
    await showSidePanel<bool>(
      context,
      builder: (dialog) => ChatPanel(
        title: const Text('Clear this chat?'),
        content: const Text(
          'Messages are removed for you only. Everyone else keeps them.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialog).pop(false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(dialog).pop(true),
            child: const Text('Clear chat'),
          ),
        ],
      ),
    ) ??
    false;
