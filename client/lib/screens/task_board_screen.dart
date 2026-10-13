import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../core/timestamps.dart';
import '../widgets/async_body.dart';
import '../widgets/calendar_day_field.dart';
import '../widgets/colour_field.dart';
import '../widgets/search_picker.dart';
import '../widgets/side_panel.dart';
import '../widgets/status_pill.dart';
import '../widgets/tab_bar_like.dart';

typedef _Json = Map<String, dynamic>;

/// Task Board (UI queue 80; Jose: "as you recommend" — 1 and 4).
///
/// The boards in the top-bar view are a row of tabs, My board first, each with
/// how many open tasks are yours. A board is its coloured, foldable groups,
/// as a table or as a kanban by status, and a task opens in the side panel.
/// Below [tableAbove] each task is a card with its status down its edge.
///
/// The board tabs are data, not TabDefinitions: which boards exist depends on
/// the view, and there is nothing in them for Roles & permissions to grant.
class TaskBoardScreen extends StatefulWidget {
  const TaskBoardScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  /// Under this the table's fixed columns leave the task's name no room.
  static const double tableAbove = 760;

  @override
  State<TaskBoardScreen> createState() => _TaskBoardScreenState();
}

/// Status in the colour of what it means, never colour alone: every place
/// that shows one also says it in words.
Tone _tone(Object? status) => switch (status) {
  'done' => Tone.ok,
  'working_on_it' => Tone.caution,
  'stuck' => Tone.bad,
  _ => Tone.neutral,
};

String _initials(String name) {
  final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty);
  return parts.take(2).map((p) => p[0].toUpperCase()).join();
}

Widget _avatar(BuildContext context, String name) {
  final scheme = Theme.of(context).colorScheme;
  return CircleAvatar(
    radius: 11,
    backgroundColor: scheme.secondaryContainer,
    child: Text(
      _initials(name),
      style: TextStyle(
        fontSize: 9.5,
        fontWeight: FontWeight.w700,
        color: scheme.onSecondaryContainer,
      ),
    ),
  );
}

/// The board's own word for a status, or its key from a newer server.
String _statusLabel(_Json board, Object? key) {
  for (final s in (board['statuses'] as List? ?? const []).cast<_Json>()) {
    if (s['key'] == key) return s['label'] as String;
  }
  return '$key';
}

bool _late(_Json task) {
  final due = task['due_on'] as String?;
  return due != null &&
      task['status'] != 'done' &&
      due.compareTo(formatCalendarDay(DateTime.now())) < 0;
}

/// Today, Tomorrow, Yesterday, or the day in the organization's format.
String _dueLabel(Object? due, DisplayFormats formats) {
  final day = due is String ? DateTime.tryParse(due) : null;
  if (day == null) return '';
  final now = DateTime.now();
  final today = DateTime(now.year, now.month, now.day);
  return switch (day.difference(today).inDays) {
    0 => 'Today',
    1 => 'Tomorrow',
    -1 => 'Yesterday',
    _ => formats.formatDate(day),
  };
}

class _TaskBoardScreenState extends State<TaskBoardScreen> {
  List<_Json> _boards = const [];
  int _selected = 0;
  _Json? _board;
  bool _loading = true;
  String? _error;

  bool _kanban = false;
  String _query = '';
  final Set<String?> _folded = {};

  /// The group whose "Add task" is open as a box, and why the last add failed.
  String? _adding;
  String? _addError;
  final _newTitle = TextEditingController();

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  bool get _canEdit => _board?['can_edit'] == true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _newTitle.dispose();
    super.dispose();
  }

  /// The tabs, then the board open in them. Also the refresh after a task
  /// closes, so the counts on the tabs follow what changed.
  Future<void> _load() async {
    setState(() {
      _loading = _board == null;
      _error = null;
    });
    final res = await widget.api.get('/task-boards');
    if (!mounted) return;
    if (!res.ok) {
      setState(() {
        _loading = false;
        _error = res.reason;
      });
      return;
    }
    _boards = (res.body['data'] as List).cast<_Json>();
    if (_boards.isEmpty) {
      setState(() => _loading = false);
      return;
    }
    await _open(_selected.clamp(0, _boards.length - 1));
  }

  Future<void> _open(int index) async {
    final id = _boards[index]['id'];
    setState(() {
      _selected = index;
      _error = null;
      _adding = null;
      if (_board?['id'] != id) {
        _board = null;
        _loading = true;
      }
    });
    final res = await widget.api.get('/task-boards/$id');
    if (!mounted || _boards[_selected]['id'] != id) return;
    setState(() {
      _loading = false;
      if (!res.ok) {
        _error = res.reason;
        return;
      }
      final first = _board == null;
      _board = res.body['data'] as _Json;
      // A group that is all done opens folded, as Monday's Done group does.
      if (first) {
        for (final g in _groups(_board!)) {
          final tasks = _tasksIn(g['id']);
          if (tasks.isNotEmpty && tasks.every((t) => t['status'] == 'done')) {
            _folded.add(g['id'] as String?);
          }
        }
      }
    });
  }

  List<_Json> get _tasks =>
      (_board?['tasks'] as List? ?? const []).cast<_Json>();

  /// The board's groups in order, and a last one for tasks in none of them
  /// (an automation may make one without a group).
  List<_Json> _groups(_Json board) {
    final groups = (board['groups'] as List? ?? const []).cast<_Json>().toList()
      ..sort(
        (a, b) => ((a['position'] as num?) ?? 0).compareTo(
          (b['position'] as num?) ?? 0,
        ),
      );
    final ids = groups.map((g) => g['id']).toSet();
    if (_tasks.any((t) => !ids.contains(t['group_id']))) {
      groups.add({'id': null, 'name': 'Not in a group', 'colour': null});
    }
    return groups;
  }

  List<_Json> _tasksIn(Object? groupId) {
    final ids = (_board?['groups'] as List? ?? const [])
        .map((g) => (g as Map)['id'])
        .toSet();
    return [
      for (final t in _tasks)
        if (groupId == null
            ? !ids.contains(t['group_id'])
            : t['group_id'] == groupId)
          t,
    ];
  }

  /// Search reads the task, its owner, its status and what it is about.
  bool _matches(_Json t) {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return true;
    return [
      t['title'],
      (t['owner'] as Map?)?['name'],
      _statusLabel(_board!, t['status']),
      (t['about'] as Map?)?['label'],
    ].any((v) => v is String && v.toLowerCase().contains(q));
  }

  Color _groupColour(BuildContext context, _Json group) =>
      ColourField.parse(group['colour'] as String? ?? '') ??
      Theme.of(context).colorScheme.outline;

  void _replace(_Json task) => setState(() {
    final tasks = _tasks.toList();
    final at = tasks.indexWhere((t) => t['id'] == task['id']);
    at < 0 ? tasks.add(task) : tasks[at] = task;
    _board = {..._board!, 'tasks': tasks};
  });

  Future<void> _setStatus(_Json task, String status) async {
    final res = await widget.api.patch('/tasks/${task['id']}', {
      'status': status,
    });
    if (!mounted) return;
    if (res.ok) {
      _replace(res.body['data'] as _Json);
    } else {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(res.reason)));
    }
  }

  Future<void> _add(Object? groupId) async {
    final title = _newTitle.text.trim();
    if (title.isEmpty) return;
    final res = await widget.api.post('/tasks', {
      'board_id': _board!['id'],
      'title': title,
      'group_id': ?groupId,
      // A task on your own board is yours until somebody gives it away.
      if (_board!['kind'] == 'user') 'assignee_user_id': widget.session.me?.id,
    });
    if (!mounted) return;
    if (!res.ok) {
      setState(() => _addError = res.reason);
      return;
    }
    _newTitle.clear();
    _addError = null;
    // The box stays open for the next one, as Monday's does.
    _replace(res.body['data'] as _Json);
  }

  Future<void> _openTask(_Json task) async {
    // The board says who may own its tasks; a server from before it did
    // offers you and the people already owning one here.
    final assignable = _board!['assignable'];
    final people = <String, String>{
      if (assignable is List)
        for (final p in assignable.cast<Map>())
          p['id'] as String: p['name'] as String
      else ...{
        if (widget.session.me != null)
          widget.session.me!.id: widget.session.me!.name,
        for (final t in _tasks)
          if (t['owner'] is Map)
            (t['owner'] as Map)['id'] as String:
                (t['owner'] as Map)['name'] as String,
      },
    };
    final groups = _groups(_board!);
    final group = groups.firstWhere(
      (g) => g['id'] == task['group_id'],
      orElse: () => groups.last,
    );
    await showSidePanel<void>(
      context,
      builder: (_) => _TaskPanel(
        api: widget.api,
        formats: _formats,
        taskId: task['id'] as String,
        board: _board!,
        where: '${group['name']}',
        people: people,
      ),
    );
    if (mounted) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return LayoutBuilder(
      builder: (context, constraints) {
        final cards = constraints.maxWidth < TaskBoardScreen.tableAbove;

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_boards.isNotEmpty)
              Material(
                key: const ValueKey('tasks-boards'),
                color: theme.colorScheme.surface,
                child: TabBarLike(
                  level: TabLevel.secondary,
                  labels: [
                    for (final b in _boards)
                      (
                        '${b['kind'] == 'user' ? 'My board' : b['name']} · '
                            '${b['open_for_me'] ?? 0}',
                        switch (b['kind']) {
                          'user' => Icons.person_outline,
                          'franchise' => Icons.storefront_outlined,
                          _ => Icons.business_outlined,
                        },
                      ),
                  ],
                  selected: _selected,
                  onSelected: _open,
                ),
              ),
            Expanded(
              child: AsyncBody(
                loading: _loading,
                error: _error,
                onRetry: _load,
                child: _board == null
                    ? const SizedBox.shrink()
                    : Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          _toolbar(context, phone: cards),
                          Expanded(
                            child: _kanban
                                ? _kanbanView(context, narrow: cards)
                                : _tableView(context, cards: cards),
                          ),
                        ],
                      ),
              ),
            ),
          ],
        );
      },
    );
  }

  Widget _toolbar(BuildContext context, {required bool phone}) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 2),
      child: Wrap(
        spacing: 10,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          SegmentedButton<bool>(
            key: const Key('tasks-view'),
            showSelectedIcon: false,
            segments: const [
              ButtonSegment(
                value: false,
                label: Text('Table'),
                icon: Icon(Icons.table_rows_outlined, size: 18),
              ),
              ButtonSegment(
                value: true,
                label: Text('Kanban'),
                icon: Icon(Icons.view_kanban_outlined, size: 18),
              ),
            ],
            selected: {_kanban},
            onSelectionChanged: (v) => setState(() => _kanban = v.first),
          ),
          SizedBox(
            width: phone ? 200 : 240,
            child: TextField(
              key: const Key('tasks-search'),
              decoration: const InputDecoration(
                isDense: true,
                prefixIcon: Icon(Icons.search, size: 18),
                hintText: 'Find a task',
                border: OutlineInputBorder(),
              ),
              onChanged: (v) => setState(() => _query = v),
            ),
          ),
          if (!_canEdit)
            Text(
              'View only',
              key: const Key('tasks-view-only'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
        ],
      ),
    );
  }

  // ── Table (and cards on a phone) ───────────────────────────────

  Widget _tableView(BuildContext context, {required bool cards}) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 24),
      children: [
        for (final (i, g) in _groups(_board!).indexed)
          // The walkthrough's last step points at the first group.
          i == 0
              ? KeyedSubtree(
                  key: const ValueKey('tasks-groups'),
                  child: _group(context, g, cards: cards),
                )
              : _group(context, g, cards: cards),
      ],
    );
  }

  Widget _group(BuildContext context, _Json group, {required bool cards}) {
    final theme = Theme.of(context);
    final colour = _groupColour(context, group);
    final all = _tasksIn(group['id']);
    final shown = all.where(_matches).toList();
    final folded = _folded.contains(group['id']);
    final id = group['id'] as String?;

    final header = InkWell(
      key: ValueKey('task-group-$id'),
      onTap: () =>
          setState(() => folded ? _folded.remove(id) : _folded.add(id)),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
        child: Row(
          children: [
            Icon(
              folded ? Icons.chevron_right : Icons.expand_more,
              color: colour,
              size: 20,
            ),
            const SizedBox(width: 4),
            Flexible(
              child: Text(
                '${group['name']}',
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.titleSmall?.copyWith(
                  color: colour,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
            const SizedBox(width: 8),
            Text(
              all.length == 1 ? '1 task' : '${all.length} tasks',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
      ),
    );

    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: cards
          ? Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                header,
                if (!folded) ...[
                  for (final t in shown) _card(context, t),
                  if (_canEdit && id != null) _addRow(context, id),
                ],
              ],
            )
          : DecoratedBox(
              decoration: BoxDecoration(
                color: theme.colorScheme.surface,
                border: Border.all(color: theme.dividerColor),
                borderRadius: BorderRadius.circular(12),
              ),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    header,
                    if (!folded) ...[
                      _headings(context),
                      for (final t in shown) _row(context, t, colour),
                      if (_canEdit && id != null)
                        _cellBorder(
                          context,
                          Row(
                            children: [
                              Container(
                                width: 6,
                                height: 38,
                                color: colour.withValues(alpha: 0.3),
                              ),
                              Expanded(child: _addRow(context, id)),
                            ],
                          ),
                        ),
                    ],
                  ],
                ),
              ),
            ),
    );
  }

  static const _widths = (
    owner: 130.0,
    status: 130.0,
    due: 104.0,
    about: 170.0,
    updates: 84.0,
  );

  Widget _cellBorder(BuildContext context, Widget child) => DecoratedBox(
    decoration: BoxDecoration(
      border: Border(top: BorderSide(color: Theme.of(context).dividerColor)),
    ),
    child: child,
  );

  Widget _cell(BuildContext context, double width, Widget child) => Container(
    width: width,
    padding: const EdgeInsets.symmetric(horizontal: 10),
    alignment: Alignment.centerLeft,
    decoration: BoxDecoration(
      border: Border(left: BorderSide(color: Theme.of(context).dividerColor)),
    ),
    child: child,
  );

  Widget _headings(BuildContext context) {
    final theme = Theme.of(context);
    final style = theme.textTheme.bodySmall?.copyWith(
      fontSize: 10.5,
      fontWeight: FontWeight.w800,
      letterSpacing: 0.6,
      color: theme.colorScheme.onSurfaceVariant,
    );
    Text h(String s) => Text(s.toUpperCase(), style: style, maxLines: 1);
    return _cellBorder(
      context,
      SizedBox(
        height: 28,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const SizedBox(width: 6),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 10),
                child: Align(alignment: Alignment.centerLeft, child: h('Task')),
              ),
            ),
            _cell(context, _widths.owner, h('Owner')),
            _cell(context, _widths.status, h('Status')),
            _cell(context, _widths.due, h('Due')),
            _cell(context, _widths.about, h('About')),
            _cell(context, _widths.updates, h('Updates')),
          ],
        ),
      ),
    );
  }

  Widget _row(BuildContext context, _Json t, Color colour) {
    final theme = Theme.of(context);
    final owner = (t['owner'] as Map?)?['name'] as String?;
    final about = (t['about'] as Map?)?['label'] as String?;
    final updates = (t['updates_count'] as num?) ?? 0;
    final late = _late(t);

    return _cellBorder(
      context,
      InkWell(
        key: ValueKey('task-${t['id']}'),
        onTap: () => _openTask(t),
        child: SizedBox(
          height: 38,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Container(width: 6, color: colour),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 10),
                  child: Align(
                    alignment: Alignment.centerLeft,
                    child: Text(
                      '${t['title']}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                ),
              ),
              _cell(
                context,
                _widths.owner,
                owner == null
                    ? const SizedBox.shrink()
                    : Row(
                        children: [
                          _avatar(context, owner),
                          const SizedBox(width: 6),
                          Flexible(
                            child: Text(
                              owner.split(' ').first,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ],
                      ),
              ),
              SizedBox(width: _widths.status, child: _statusCell(context, t)),
              _cell(
                context,
                _widths.due,
                Text(
                  _dueLabel(t['due_on'], _formats),
                  maxLines: 1,
                  style: late
                      ? TextStyle(
                          color: theme.colorScheme.error,
                          fontWeight: FontWeight.w700,
                        )
                      : null,
                ),
              ),
              _cell(
                context,
                _widths.about,
                Text(
                  about ?? '—',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: about == null
                      ? TextStyle(color: theme.colorScheme.onSurfaceVariant)
                      : TextStyle(
                          color: theme.colorScheme.primary,
                          fontWeight: FontWeight.w700,
                        ),
                ),
              ),
              _cell(
                context,
                _widths.updates,
                updates == 0
                    ? const SizedBox.shrink()
                    : Row(
                        children: [
                          Icon(
                            Icons.chat_bubble_outline,
                            size: 14,
                            color: theme.colorScheme.onSurfaceVariant,
                          ),
                          const SizedBox(width: 4),
                          Text('$updates'),
                        ],
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// Monday's coloured status cell, in the pills' colours: the word on its
  /// colour, and a click
  /// changes it without opening the task.
  Widget _statusCell(BuildContext context, _Json t) {
    final colours = ToneColours.of(context, _tone(t['status']));
    final label = Text(
      _statusLabel(_board!, t['status']),
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: TextStyle(
        color: colours.ink,
        fontWeight: FontWeight.w700,
        fontSize: 12,
      ),
    );

    return MenuAnchor(
      menuChildren: [
        for (final s in (_board!['statuses'] as List).cast<_Json>())
          MenuItemButton(
            key: ValueKey('task-status-${t['id']}-${s['key']}'),
            leadingIcon: ToneDot(_tone(s['key'])),
            onPressed: () => _setStatus(t, s['key'] as String),
            child: Text(s['label'] as String),
          ),
      ],
      builder: (context, menu, _) => Material(
        color: colours.fill,
        child: InkWell(
          key: ValueKey('task-status-${t['id']}'),
          onTap: _canEdit
              ? () => menu.isOpen ? menu.close() : menu.open()
              : null,
          child: Center(child: label),
        ),
      ),
    );
  }

  Widget _card(BuildContext context, _Json t) {
    final theme = Theme.of(context);
    final tone = _tone(t['status']);
    final owner = (t['owner'] as Map?)?['name'] as String?;
    final due = _dueLabel(t['due_on'], _formats);

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: theme.colorScheme.surface,
        shape: RoundedRectangleBorder(
          side: BorderSide(color: theme.dividerColor),
          borderRadius: BorderRadius.circular(10),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          key: ValueKey('task-${t['id']}'),
          onTap: () => _openTask(t),
          child: IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Container(width: 5, color: ToneColours.of(context, tone).ink),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(10, 9, 10, 9),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${t['title']}',
                          style: theme.textTheme.bodyMedium?.copyWith(
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        const SizedBox(height: 6),
                        Wrap(
                          spacing: 8,
                          runSpacing: 4,
                          crossAxisAlignment: WrapCrossAlignment.center,
                          children: [
                            StatusPill(
                              _statusLabel(_board!, t['status']),
                              tone: tone,
                            ),
                            if (owner != null) ...[_avatar(context, owner)],
                            if (due.isNotEmpty)
                              Text(
                                due,
                                style: theme.textTheme.bodySmall?.copyWith(
                                  color: _late(t)
                                      ? theme.colorScheme.error
                                      : theme.colorScheme.onSurfaceVariant,
                                  fontWeight: _late(t) ? FontWeight.w700 : null,
                                ),
                              ),
                            if ((t['about'] as Map?)?['label']
                                case final String about)
                              Text(
                                about,
                                style: theme.textTheme.bodySmall?.copyWith(
                                  color: theme.colorScheme.primary,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                          ],
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _addRow(BuildContext context, String groupId) {
    if (_adding != groupId) {
      return Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
          key: ValueKey('task-add-$groupId'),
          onPressed: () => setState(() {
            _adding = groupId;
            _addError = null;
            _newTitle.clear();
          }),
          icon: const Icon(Icons.add, size: 18),
          label: const Text('Add task'),
        ),
      );
    }
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      child: TextField(
        key: const Key('task-new-title'),
        controller: _newTitle,
        autofocus: true,
        decoration: InputDecoration(
          isDense: true,
          hintText: 'Name the task, then press Enter',
          errorText: _addError,
          suffixIcon: IconButton(
            tooltip: 'Cancel',
            icon: const Icon(Icons.close, size: 18),
            onPressed: () => setState(() => _adding = null),
          ),
        ),
        onSubmitted: (_) => _add(groupId),
      ),
    );
  }

  // ── Kanban ─────────────────────────────────────────────────────

  Widget _kanbanView(BuildContext context, {required bool narrow}) {
    final statuses = (_board!['statuses'] as List).cast<_Json>();
    final columns = [
      for (final s in statuses)
        _kanbanColumn(
          context,
          s,
          _tasks.where((t) => t['status'] == s['key'] && _matches(t)).toList(),
        ),
    ];

    // ponytail: no drag between columns; the card's status menu moves it.
    if (narrow) {
      return ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.fromLTRB(16, 10, 16, 16),
        children: [
          for (final c in columns)
            Padding(
              padding: const EdgeInsets.only(right: 10),
              child: SizedBox(width: 260, child: c),
            ),
        ],
      );
    }
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 16),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (var i = 0; i < columns.length; i++) ...[
            if (i > 0) const SizedBox(width: 10),
            Expanded(child: columns[i]),
          ],
        ],
      ),
    );
  }

  Widget _kanbanColumn(BuildContext context, _Json status, List<_Json> tasks) {
    final theme = Theme.of(context);
    return DecoratedBox(
      key: ValueKey('kanban-${status['key']}'),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 6),
            child: Row(
              children: [
                ToneDot(_tone(status['key'])),
                const SizedBox(width: 6),
                Text(
                  '${status['label']} · ${tasks.length}',
                  style: theme.textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ],
            ),
          ),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(8, 0, 8, 8),
              children: [for (final t in tasks) _card(context, t)],
            ),
          ),
        ],
      ),
    );
  }
}

/// One task, in the side panel: everything about it, editable where the
/// board allows, with its updates under it.
class _TaskPanel extends StatefulWidget {
  const _TaskPanel({
    required this.api,
    required this.formats,
    required this.taskId,
    required this.board,
    required this.where,
    required this.people,
  });

  final ApiClient api;
  final DisplayFormats formats;
  final String taskId;
  final _Json board;

  /// The group, under the board's name.
  final String where;

  /// Who it can be given to, by id: the board's `assignable`.
  final Map<String, String> people;

  @override
  State<_TaskPanel> createState() => _TaskPanelState();
}

class _TaskPanelState extends State<_TaskPanel> {
  _Json? _task;
  bool _loading = true;
  String? _error;
  String? _saveError;
  bool _confirmDelete = false;
  bool _posting = false;

  final _title = TextEditingController();
  final _due = TextEditingController();
  final _update = TextEditingController();

  bool get _canEdit => widget.board['can_edit'] == true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _title.dispose();
    _due.dispose();
    _update.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final res = await widget.api.get('/tasks/${widget.taskId}');
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (!res.ok) {
        _error = res.reason;
        return;
      }
      _task = res.body['data'] as _Json;
      _title.text = '${_task!['title']}';
      _due.text = _task!['due_on'] as String? ?? '';
    });
  }

  Future<void> _patch(_Json body) async {
    final res = await widget.api.patch('/tasks/${widget.taskId}', body);
    if (!mounted) return;
    setState(() {
      if (!res.ok) {
        _saveError = res.reason;
        return;
      }
      _saveError = null;
      // The answer has no updates in it; keep the ones on screen.
      _task = {...res.body['data'] as _Json, 'updates': _task!['updates']};
    });
  }

  Future<void> _post() async {
    final body = _update.text.trim();
    if (body.isEmpty) return;
    setState(() => _posting = true);
    final res = await widget.api.post('/tasks/${widget.taskId}/updates', {
      'body': body,
    });
    if (!mounted) return;
    setState(() {
      _posting = false;
      if (!res.ok) {
        _saveError = res.reason;
        return;
      }
      _update.clear();
      _task = {
        ..._task!,
        'updates': [
          ...(_task!['updates'] as List? ?? const []),
          res.body['data'],
        ],
      };
    });
  }

  Future<void> _delete() async {
    final res = await widget.api.delete('/tasks/${widget.taskId}');
    if (!mounted) return;
    if (res.ok) {
      Navigator.of(context).pop();
    } else {
      setState(() => _saveError = res.reason);
    }
  }

  @override
  Widget build(BuildContext context) {
    return SidePanel(
      title:
          '${widget.board['kind'] == 'user' ? 'My board' : widget.board['name']}',
      subtitle: widget.where,
      actions: [
        if (_canEdit && _task != null)
          if (_confirmDelete) ...[
            TextButton(
              onPressed: () => setState(() => _confirmDelete = false),
              child: const Text('Keep it'),
            ),
            FilledButton(
              key: const Key('task-delete-confirm'),
              style: FilledButton.styleFrom(
                backgroundColor: Theme.of(context).colorScheme.error,
                foregroundColor: Theme.of(context).colorScheme.onError,
              ),
              onPressed: _delete,
              child: const Text('Delete task'),
            ),
          ] else
            TextButton.icon(
              key: const Key('task-delete'),
              onPressed: () => setState(() => _confirmDelete = true),
              icon: const Icon(Icons.delete_outline, size: 18),
              label: const Text('Delete…'),
            ),
      ],
      child: AsyncBody(
        loading: _loading,
        error: _error,
        onRetry: _load,
        child: _task == null ? const SizedBox.shrink() : _body(context),
      ),
    );
  }

  Widget _body(BuildContext context) {
    final theme = Theme.of(context);
    final task = _task!;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final owner = task['owner'] as Map?;
    final people = {
      ...widget.people,
      if (owner != null) owner['id'] as String: owner['name'] as String,
    };
    final madeBy = task['created_by'] as Map?;
    final updates = (task['updates'] as List? ?? const []).cast<_Json>();

    Widget fact(String label, Widget value, {Widget? under}) => Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          SizedBox(
            width: 86,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label, style: muted),
                if (under != null) ...[const SizedBox(height: 4), under],
              ],
            ),
          ),
          Expanded(child: value),
        ],
      ),
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const Key('task-panel-title'),
          controller: _title,
          enabled: _canEdit,
          maxLines: null,
          style: theme.textTheme.titleMedium,
          decoration: const InputDecoration(
            border: InputBorder.none,
            hintText: 'Name the task',
          ),
          onSubmitted: (v) {
            if (v.trim().isNotEmpty && v.trim() != task['title']) {
              _patch({'title': v.trim()});
            }
          },
        ),
        if (_saveError != null) ...[
          Text(
            _saveError!,
            key: const Key('task-panel-error'),
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.error,
            ),
          ),
          const SizedBox(height: 8),
        ],
        const SizedBox(height: 8),
        fact(
          'Status',
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final s in (widget.board['statuses'] as List).cast<_Json>())
                ChoiceChip(
                  key: ValueKey('task-panel-status-${s['key']}'),
                  showCheckmark: false,
                  avatar: ToneDot(_tone(s['key'])),
                  label: Text(s['label'] as String),
                  selected: task['status'] == s['key'],
                  // Never null on the chosen one: a disabled chip greys out
                  // the very status it is showing.
                  onSelected: _canEdit
                      ? (_) {
                          if (task['status'] != s['key']) {
                            _patch({'status': s['key']});
                          }
                        }
                      : null,
                ),
            ],
          ),
        ),
        fact(
          'Owner',
          SearchPickerField<String>(
            key: const Key('task-panel-owner'),
            value: owner?['id'] as String?,
            isDense: true,
            hint: 'Nobody',
            options: [
              const SearchOption(value: null, label: 'Nobody'),
              for (final p in people.entries)
                SearchOption(value: p.key, label: p.value),
            ],
            onChanged: _canEdit
                ? (id) {
                    if (id != owner?['id']) _patch({'assignee_user_id': id});
                  }
                : null,
          ),
        ),
        fact(
          'Due',
          // The day boxes need the whole width, so Late sits under the label.
          under: _late(task) ? const StatusPill('Late', tone: Tone.bad) : null,
          CalendarDayField(
            key: const Key('task-panel-due'),
            controller: _due,
            formats: widget.formats,
            dense: true,
            enabled: _canEdit,
            onChanged: (v) {
              final day = v.isEmpty ? null : v;
              if (day != task['due_on'] &&
                  (day == null ||
                      RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(day))) {
                _patch({'due_on': day});
              }
            },
          ),
        ),
        fact(
          'About',
          Text(
            (task['about'] as Map?)?['label'] as String? ?? '—',
            style: TextStyle(
              color: task['about'] == null
                  ? theme.colorScheme.onSurfaceVariant
                  : theme.colorScheme.primary,
              fontWeight: FontWeight.w700,
            ),
          ),
        ),
        if (madeBy != null)
          fact(
            'Made by',
            Text(
              madeBy['type'] == 'automation'
                  ? 'Automation "${madeBy['label']}"'
                  : '${madeBy['label']}',
              key: const Key('task-panel-made-by'),
              style: muted,
            ),
          ),
        const Divider(height: 24),
        Text(
          updates.isEmpty ? 'Updates' : 'Updates · ${updates.length}',
          style: theme.textTheme.titleSmall?.copyWith(
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 8),
        if (updates.isEmpty) Text('Nothing written here yet.', style: muted),
        for (final u in updates)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _avatar(context, '${(u['author'] as Map?)?['name'] ?? '?'}'),
                const SizedBox(width: 8),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text.rich(
                        TextSpan(
                          children: [
                            TextSpan(
                              text:
                                  '${(u['author'] as Map?)?['name'] ?? 'Somebody'}  ',
                              style: const TextStyle(
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            TextSpan(
                              text: formatStamp(
                                u['created_at'],
                                '',
                                formats: widget.formats,
                              ),
                              style: muted,
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text('${u['body']}'),
                    ],
                  ),
                ),
              ],
            ),
          ),
        if (_canEdit) ...[
          const SizedBox(height: 4),
          TextField(
            key: const Key('task-update-body'),
            controller: _update,
            minLines: 2,
            maxLines: 6,
            decoration: const InputDecoration(
              hintText: 'Write an update…',
              border: OutlineInputBorder(),
            ),
          ),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton(
              key: const Key('task-update-post'),
              onPressed: _posting ? null : _post,
              child: Text(_posting ? 'Posting…' : 'Post update'),
            ),
          ),
        ],
      ],
    );
  }
}
