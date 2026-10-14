import 'dart:math' as math;

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
import 'task_board_automate.dart';
import 'task_board_columns.dart';
import 'task_board_people.dart';

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

/// One of a board's statuses. Each board has its own (UI queue 84), sent as
/// `{id, name, colour, is_done}`; the fixed four came as `{key, label}` and
/// still read here, coloured by [_tone].
typedef _Status = ({String id, String name, Color? colour, bool done});

List<_Status> _statuses(_Json? board) => [
  for (final s in (board?['statuses'] as List? ?? const []).whereType<Map>())
    (
      id: '${s['id'] ?? s['key']}',
      name: '${s['name'] ?? s['label'] ?? s['key']}',
      colour: s['colour'] is String
          ? ColourField.parse(s['colour'] as String)
          : null,
      done:
          s['is_done'] == true || (s['is_done'] == null && s['key'] == 'done'),
    ),
];

/// A task's status, under whichever name the server sends it.
String? _statusOf(_Json task) =>
    (task['status_id'] ?? task['status'])?.toString();

/// What a write sends: `status_id` to a board with its own statuses.
_Json _statusBody(_Json board, String id) =>
    (board['statuses'] as List? ?? const []).any(
      (s) => s is Map && s.containsKey('id'),
    )
    ? {'status_id': id}
    : {'status': id};

_Status? _statusFor(_Json? board, Object? id) {
  for (final s in _statuses(board)) {
    if (s.id == id) return s;
  }
  return null;
}

/// The board's own word for a status, or its id from a newer server.
String _statusLabel(_Json board, Object? id) =>
    _statusFor(board, id)?.name ?? '$id';

bool _isDone(_Json? board, _Json task) =>
    _statusFor(board, _statusOf(task))?.done ?? _statusOf(task) == 'done';

/// A status's fill, and whichever of the page's two text colours reads
/// better on it: the board's own colour where it set one, otherwise the tone
/// of the fixed four.
({Color fill, Color ink}) _statusColours(BuildContext context, _Status? s) {
  final c = s?.colour;
  if (c == null) {
    final t = ToneColours.of(context, _tone(s?.id));
    return (fill: t.fill, ink: t.ink);
  }
  final scheme = Theme.of(context).colorScheme;
  double contrast(Color a, Color b) {
    final la = a.computeLuminance(), lb = b.computeLuminance();
    return (math.max(la, lb) + 0.05) / (math.min(la, lb) + 0.05);
  }

  return (
    fill: c,
    ink: contrast(c, scheme.surface) >= contrast(c, scheme.onSurface)
        ? scheme.surface
        : scheme.onSurface,
  );
}

/// The small dot a status wears in menus, chips and the kanban's headings.
Widget _statusDot(BuildContext context, _Status s) => Container(
  width: 8,
  height: 8,
  decoration: BoxDecoration(
    color: s.colour ?? ToneColours.of(context, _tone(s.id)).ink,
    shape: BoxShape.circle,
  ),
);

bool _late(_Json task, [_Json? board]) {
  final due = task['due_on'] as String?;
  return due != null &&
      !_isDone(board, task) &&
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

  /// Tasks with their subtasks open under them (UI queue 84).
  final Set<String> _expanded = {};

  /// The group being renamed, or "new" while one is being added.
  String? _naming;
  final _groupName = TextEditingController();

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
    _groupName.dispose();
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
          if (tasks.isNotEmpty && tasks.every((t) => _isDone(_board, t))) {
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
        if (t['parent_task_id'] == null &&
            (groupId == null
                ? !ids.contains(t['group_id'])
                : t['group_id'] == groupId))
          t,
    ];
  }

  /// A task's subtasks: rows of the board that name it as their parent.
  List<_Json> _subtasksOf(_Json t) => [
    for (final x in _tasks)
      if (x['parent_task_id'] != null && x['parent_task_id'] == t['id']) x,
  ];

  /// "2 / 4": the count the server sends, or the one on screen.
  (int, int) _subtaskCount(_Json t) {
    final subs = _subtasksOf(t);
    if (subs.isNotEmpty) {
      return (subs.where((x) => _isDone(_board, x)).length, subs.length);
    }
    return (
      (t['subtasks_done'] as num?)?.toInt() ?? 0,
      (t['subtasks_count'] as num?)?.toInt() ?? 0,
    );
  }

  /// Search reads the task, its owner, its status and what it is about.
  bool _matches(_Json t) {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return true;
    return [
      t['title'],
      (t['owner'] as Map?)?['name'],
      _statusLabel(_board!, _statusOf(t)),
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

  /// Who a task can be given to, by id. The board says, where it does; a
  /// server from before it did offers you and the people already owning one.
  Map<String, String> get _people {
    final assignable = _board?['assignable'];
    return {
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
  }

  String get _groupsPath => '/task-boards/${_board!['id']}/groups';

  /// Add a group, or rename [id], from the name in the box.
  Future<void> _saveGroup(String? id) async {
    final name = _groupName.text.trim();
    if (name.isEmpty) return setState(() => _naming = null);
    final res = id == null
        ? await widget.api.post(_groupsPath, {'name': name})
        : await widget.api.patch('$_groupsPath/$id', {'name': name});
    if (!mounted) return;
    if (!res.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(res.reason)));
      return;
    }
    setState(() => _naming = null);
    await _load();
  }

  /// A group with tasks names the group they move to.
  Future<void> _deleteGroup(String id, {String? moveTo}) async {
    final res = await widget.api.delete('$_groupsPath/$id', {
      'move_to': ?moveTo,
    });
    if (!mounted) return;
    if (!res.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(res.reason)));
      return;
    }
    await _load();
  }

  /// Rename and delete, on a group's heading.
  Widget _groupMenu(_Json group, int tasks) {
    final id = group['id'] as String;
    final others = [
      for (final g in _groups(_board!))
        if (g['id'] != null && g['id'] != id) g,
    ];
    return MenuAnchor(
      menuChildren: [
        MenuItemButton(
          key: ValueKey('group-rename-$id'),
          leadingIcon: const Icon(Icons.edit_outlined, size: 18),
          onPressed: () => setState(() {
            _naming = id;
            _groupName.text = '${group['name']}';
          }),
          child: const Text('Rename'),
        ),
        if (tasks == 0)
          MenuItemButton(
            key: ValueKey('group-delete-$id'),
            leadingIcon: const Icon(Icons.delete_outline, size: 18),
            onPressed: () => _deleteGroup(id),
            child: const Text('Delete group'),
          )
        else if (others.isNotEmpty)
          SubmenuButton(
            key: ValueKey('group-delete-$id'),
            leadingIcon: const Icon(Icons.delete_outline, size: 18),
            menuChildren: [
              for (final g in others)
                MenuItemButton(
                  key: ValueKey('group-delete-$id-to-${g['id']}'),
                  onPressed: () => _deleteGroup(id, moveTo: g['id'] as String),
                  child: Text('${g['name']}'),
                ),
            ],
            child: Text(
              'Delete, moving its ${tasks == 1 ? 'task' : '$tasks tasks'} to',
            ),
          ),
      ],
      builder: (context, menu, _) => IconButton(
        key: ValueKey('group-menu-$id'),
        tooltip: 'Group',
        visualDensity: VisualDensity.compact,
        iconSize: 18,
        onPressed: () => menu.isOpen ? menu.close() : menu.open(),
        icon: const Icon(Icons.more_horiz),
      ),
    );
  }

  /// The box a group's name is typed into, for a new one or a rename.
  Widget _groupNameField(String? id) => SizedBox(
    width: 260,
    child: TextField(
      key: const Key('group-name'),
      controller: _groupName,
      autofocus: true,
      decoration: InputDecoration(
        isDense: true,
        hintText: 'Name the group, then press Enter',
        suffixIcon: IconButton(
          tooltip: 'Cancel',
          icon: const Icon(Icons.close, size: 18),
          onPressed: () => setState(() => _naming = null),
        ),
      ),
      onSubmitted: (_) => _saveGroup(id),
    ),
  );

  /// Add a column of [type] (UI queue 84). The board reloads after.
  Future<void> _addColumn(String type) async {
    await showSidePanel<bool>(
      context,
      builder: (_) =>
          ColumnPanel(api: widget.api, boardId: '${_board!['id']}', type: type),
    );
    if (mounted) await _load();
  }

  Future<void> _editColumn(BoardColumn c) async {
    final raw = (_board!['columns'] as List? ?? const [])
        .whereType<Map>()
        .firstWhere((x) => '${x['id']}' == c.id, orElse: () => const {});
    await showSidePanel<bool>(
      context,
      builder: (_) => ColumnPanel(
        api: widget.api,
        boardId: '${_board!['id']}',
        type: c.type,
        column: c,
        raw: raw.cast<String, dynamic>(),
      ),
    );
    if (mounted) await _load();
  }

  /// Who is on a board you made, and who can find it (UI queue 89).
  Future<void> _editPeople() async {
    await showSidePanel<void>(
      context,
      builder: (_) => BoardPeoplePanel(api: widget.api, board: _board!),
    );
    if (mounted) await _load();
  }

  /// The board's own rules, which are Automations rules (UI queue 89).
  Future<void> _automate() => showSidePanel<void>(
    context,
    builder: (_) => BoardAutomatePanel(
      api: widget.api,
      session: widget.session,
      board: _board!,
    ),
  );

  /// A new board, filed where the view is, then open on it.
  Future<void> _newBoard() async {
    final scope = widget.session.me?.scope;
    final id = await showSidePanel<String>(
      context,
      builder: (_) => NewBoardPanel(
        api: widget.api,
        companyId: scope?.type == 'company' ? scope?.id : null,
        franchiseId: scope?.type == 'franchise' ? scope?.id : null,
      ),
    );
    if (id == null || !mounted) return;
    final res = await widget.api.get('/task-boards');
    if (!mounted || !res.ok) return;
    _boards = (res.body['data'] as List).cast<_Json>();
    final at = _boards.indexWhere((b) => b['id'] == id);
    await _open(at < 0 ? 0 : at);
  }

  Future<void> _editStatuses() async {
    await showSidePanel<void>(
      context,
      builder: (_) => StatusesPanel(api: widget.api, board: _board!),
    );
    if (mounted) await _load();
  }

  /// One custom column's value, changed in place (UI queue 84).
  Future<void> _setValue(_Json task, String column, Object? value) async {
    final res = await widget.api.patch('/tasks/${task['id']}', {
      'values': {column: value},
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

  Future<void> _setStatus(_Json task, String status) async {
    final res = await widget.api.patch(
      '/tasks/${task['id']}',
      _statusBody(_board!, status),
    );
    if (!mounted) return;
    if (res.ok) {
      _replace(res.body['data'] as _Json);
    } else {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(res.reason)));
    }
  }

  Future<void> _add(Object? groupId, {String? parent}) async {
    final title = _newTitle.text.trim();
    if (title.isEmpty) return;
    final res = await widget.api.post('/tasks', {
      'board_id': _board!['id'],
      'title': title,
      'group_id': ?groupId,
      'parent_task_id': ?parent,
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
    final people = _people;
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
    return LayoutBuilder(
      builder: (context, constraints) {
        final cards = constraints.maxWidth < TaskBoardScreen.tableAbove;

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_boards.isNotEmpty) _picker(context, phone: cards),
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

  /// The boards as one picker, grouped company › franchise (UI queue 84;
  /// Jose: "Love it go with ur recommendations"). In the organization view
  /// the tabs it replaces wrapped into four rows of about eighty, many
  /// reading "Main · 0", because franchise names repeat across companies.
  /// Under its company a repeated name is never ambiguous.
  Widget _picker(BuildContext context, {required bool phone}) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    // Someone else's personal board says whose it is.
    String name(_Json b) => b['kind'] != 'user'
        ? '${b['name']}'
        : b['section'] == 'everyone' || b['section'] == 'shared'
        ? "${(b['owner'] as Map?)?['name'] ?? 'Someone'}'s board"
        : 'My board';

    Widget row(int i, {bool indent = false, String? from}) {
      final b = _boards[i];
      return Padding(
        padding: EdgeInsets.only(left: indent ? 20 : 0),
        child: Row(
          children: [
            Icon(switch (b['kind']) {
              'user' => Icons.person_outline,
              'franchise' => Icons.storefront_outlined,
              'custom' when b['visibility'] == 'private' => Icons.lock_outline,
              'custom' => Icons.dashboard_customize_outlined,
              _ => Icons.business_outlined,
            }, size: 18),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                from == null ? name(b) : '${name(b)} · from $from',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
            Text('${b['open_for_me'] ?? 0} open', style: muted),
          ],
        ),
      );
    }

    SearchOption<int> header(String text) => SearchOption(
      value: null,
      enabled: false,
      label: text,
      child: Text(
        text.toUpperCase(),
        style: muted?.copyWith(fontWeight: FontWeight.w800, letterSpacing: 0.6),
      ),
    );
    String? ownerOf(_Json b) => (b['owner'] as Map?)?['name'] as String?;

    // The place boards, each company with its own board and its franchises'
    // under it (UI queue 84).
    List<SearchOption<int>> places(Iterable<int> indexes) {
      final byCompany = <String, List<int>>{};
      for (final i in indexes) {
        byCompany
            .putIfAbsent('${_boards[i]['company_name'] ?? ''}', () => [])
            .add(i);
      }
      final companies = byCompany.keys.toList()
        ..sort((a, b) => a.isEmpty ? 1 : (b.isEmpty ? -1 : a.compareTo(b)));
      return [
        for (final company in companies) ...[
          header(company.isEmpty ? 'Other boards' : company),
          for (final i
              in byCompany[company]!..sort((a, b) {
                final ka = _boards[a]['kind'] == 'company' ? 0 : 1;
                final kb = _boards[b]['kind'] == 'company' ? 0 : 1;
                return ka != kb
                    ? ka - kb
                    : name(_boards[a]).compareTo(name(_boards[b]));
              }))
            SearchOption(
              value: i,
              label: name(_boards[i]),
              // Searched as well, so "Main" finds every company's Main.
              detail: company.isEmpty ? null : company,
              child: row(i, indent: _boards[i]['kind'] != 'company'),
            ),
        ],
      ];
    }

    // Why each board is here (Task Board's `section`): yours, shared with
    // you, your places', or everyone's for See every board (UI queue 95, 1).
    // A server without sections gets the grouping it always had.
    final sectioned = _boards.any((b) => b['section'] != null);
    Iterable<int> inSection(String key) => [
      for (final (i, b) in _boards.indexed)
        if (b['section'] == key) i,
    ];
    final everyone = <String, List<int>>{};
    for (final i in inSection('everyone')) {
      everyone.putIfAbsent(ownerOf(_boards[i]) ?? 'Someone', () => []).add(i);
    }
    final people = everyone.keys.toList()..sort();

    final options = <SearchOption<int>>[
      if (!sectioned) ...[
        for (final (i, b) in _boards.indexed)
          if (b['kind'] == 'user')
            SearchOption(value: i, label: 'My board', child: row(i)),
        ...places([
          for (final (i, b) in _boards.indexed)
            if (b['kind'] != 'user') i,
        ]),
      ] else ...[
        if (inSection('mine').isNotEmpty) header('My boards'),
        for (final i in inSection('mine'))
          SearchOption(value: i, label: name(_boards[i]), child: row(i)),
        if (inSection('shared').isNotEmpty) header('Shared with me'),
        for (final i in inSection('shared'))
          SearchOption(
            value: i,
            label: name(_boards[i]),
            detail: ownerOf(_boards[i]),
            child: row(i, from: ownerOf(_boards[i])),
          ),
        ...places(inSection('place')),
        if (people.isNotEmpty) header("Everyone's boards"),
        for (final person in people) ...[
          SearchOption(
            value: null,
            enabled: false,
            label: person,
            child: Text(
              '$person · ${everyone[person]!.length} '
              '${everyone[person]!.length == 1 ? 'board' : 'boards'}',
              style: theme.textTheme.labelLarge,
            ),
          ),
          for (final i in everyone[person]!)
            SearchOption(
              value: i,
              label: name(_boards[i]),
              // A person's name finds their boards.
              detail: person,
              child: row(i, indent: true),
            ),
        ],
      ],
      // monday's "+ Add" at the foot of the board list, for those who may
      // create boards (Task Board's tasks.create_boards).
      if (widget.session.me?.can('tasks.create_boards') ?? false)
        SearchOption(
          value: -1,
          label: 'New board',
          child: Row(
            key: const Key('tasks-new-board'),
            children: [
              Icon(Icons.add, size: 18, color: theme.colorScheme.primary),
              const SizedBox(width: 8),
              Text(
                'New board',
                style: TextStyle(
                  color: theme.colorScheme.primary,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ),
        ),
    ];

    final field = SearchPickerField<int>(
      key: const ValueKey('tasks-boards'),
      label: 'Board',
      isDense: true,
      value: _selected,
      options: options,
      searchFrom: 6,
      onChanged: (i) {
        if (i == -1) {
          _newBoard();
        } else if (i != null && i != _selected) {
          _open(i);
        }
      },
    );

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 0),
      child: Align(
        alignment: Alignment.centerLeft,
        child: phone ? field : SizedBox(width: 340, child: field),
      ),
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
          if (_canEdit &&
              (_board?['statuses'] as List? ?? const []).any(
                (s) => s is Map && s.containsKey('id'),
              ))
            OutlinedButton.icon(
              key: const Key('tasks-statuses'),
              onPressed: _editStatuses,
              icon: const Icon(Icons.label_outline, size: 18),
              label: const Text('Statuses…'),
            ),
          ..._boardFaces(phone: phone),
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

  /// Who is on the board, on EVERY board (Jose could not find People: it
  /// showed on boards made with New board only), with its faces when the
  /// server sends them; Open or Private on a board you made; and its own
  /// automations. On a phone People stays a chip, Automate goes in ⋯ (UI
  /// queue 95, desktop 4 and phone 7).
  List<Widget> _boardFaces({required bool phone}) {
    final custom = _board!['kind'] == 'custom';
    // Its owner, as the Automate panel (not can_manage: adding people).
    final automate =
        _board!['my_role'] == 'owner' ||
        (widget.session.me?.can('automations.view') ?? false);
    final private = _board!['visibility'] == 'private';
    final scheme = Theme.of(context).colorScheme;
    final faces = [
      for (final m in (_board!['members_preview'] as List? ?? const []))
        if (m is Map) '${m['name'] ?? ''}',
    ];

    return [
      if (faces.isNotEmpty)
        Row(
          key: const Key('tasks-people-faces'),
          mainAxisSize: MainAxisSize.min,
          children: [
            for (final (i, f) in faces.take(4).indexed)
              Transform.translate(
                offset: Offset(-6.0 * i, 0),
                child: CircleAvatar(
                  radius: 12,
                  backgroundColor: scheme.primaryContainer,
                  child: Text(
                    f.trim().isEmpty ? '?' : f.trim()[0].toUpperCase(),
                    style: TextStyle(
                      fontSize: 10,
                      fontWeight: FontWeight.w800,
                      color: scheme.onPrimaryContainer,
                    ),
                  ),
                ),
              ),
          ],
        ),
      OutlinedButton.icon(
        key: const Key('tasks-people'),
        onPressed: _editPeople,
        icon: const Icon(Icons.group_outlined, size: 18),
        label: Text('People · ${_board!['members_count'] ?? 0}'),
      ),
      if (custom)
        StatusPill(
          boardVisibilityLabel(_board!['visibility']),
          tone: private ? Tone.caution : Tone.neutral,
        ),
      if (automate && !phone)
        OutlinedButton.icon(
          key: const Key('tasks-automate'),
          onPressed: _automate,
          icon: const Icon(Icons.bolt, size: 18),
          label: const Text('Automate'),
        ),
      if (automate && phone)
        PopupMenuButton<String>(
          key: const Key('tasks-board-menu'),
          tooltip: 'This board',
          icon: const Icon(Icons.more_horiz),
          onSelected: (_) => _automate(),
          itemBuilder: (_) => const [
            PopupMenuItem(value: 'automate', child: Text('Automate')),
          ],
        ),
    ];
  }

  // ── Table (and cards on a phone) ───────────────────────────────

  Widget _tableView(BuildContext context, {required bool cards}) {
    final list = _tableList(context, cards: cards);
    if (cards) return list;
    return LayoutBuilder(
      builder: (context, box) {
        final need = 32 + 240 + _fixedWidth;
        if (box.maxWidth >= need) return list;
        return SingleChildScrollView(
          key: const Key('tasks-table-scroll'),
          scrollDirection: Axis.horizontal,
          child: SizedBox(width: need, height: box.maxHeight, child: list),
        );
      },
    );
  }

  Widget _tableList(BuildContext context, {required bool cards}) {
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
        if (_canEdit)
          Align(
            alignment: Alignment.centerLeft,
            child: _naming == 'new'
                ? _groupNameField(null)
                : TextButton.icon(
                    key: const Key('group-add'),
                    onPressed: () => setState(() {
                      _naming = 'new';
                      _groupName.clear();
                    }),
                    icon: const Icon(Icons.add, size: 18),
                    label: const Text('Add group'),
                  ),
          ),
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
            // The name and count take the row; the menu sits at its end.
            Expanded(
              child: Row(
                children: [
                  Icon(
                    folded ? Icons.chevron_right : Icons.expand_more,
                    color: colour,
                    size: 20,
                  ),
                  const SizedBox(width: 4),
                  if (id != null && _naming == id)
                    _groupNameField(id)
                  else
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
            if (_canEdit && id != null) _groupMenu(group, all.length),
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
                  for (final t in shown) ...[
                    _card(context, t),
                    if (_expanded.contains(t['id'])) ...[
                      for (final sub in _subtasksOf(t))
                        Padding(
                          padding: const EdgeInsets.only(left: 20),
                          child: _card(context, sub, sub: true),
                        ),
                      if (_canEdit && id != null)
                        Padding(
                          padding: const EdgeInsets.only(left: 20),
                          child: _addRow(
                            context,
                            id,
                            parent: t['id'] as String,
                          ),
                        ),
                    ],
                  ],
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
                      for (final t in shown) ...[
                        _row(context, t, colour),
                        if (_expanded.contains(t['id'])) ...[
                          for (final sub in _subtasksOf(t))
                            _row(context, sub, colour, sub: true),
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
                                  const SizedBox(width: 28),
                                  Expanded(
                                    child: _addRow(
                                      context,
                                      id,
                                      parent: t['id'] as String,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                        ],
                      ],
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
    custom: 120.0,
    add: 40.0,
  );

  /// Everything but the task's own column, so a board with many columns
  /// scrolls sideways instead of squeezing the task to nothing.
  double get _fixedWidth =>
      6 +
      _widths.owner +
      _widths.status +
      _widths.due +
      _widths.about +
      _widths.updates +
      _widths.add +
      _widths.custom * boardColumns(_board).length;

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
            for (final c in boardColumns(_board))
              _cell(
                context,
                _widths.custom,
                InkWell(
                  key: ValueKey('column-head-${c.id}'),
                  onTap: _canEdit ? () => _editColumn(c) : null,
                  child: Align(
                    alignment: Alignment.centerLeft,
                    child: Tooltip(message: c.name, child: h(c.name)),
                  ),
                ),
              ),
            _cell(context, _widths.about, h('About')),
            _cell(context, _widths.updates, h('Updates')),
            if (_canEdit)
              SizedBox(
                width: _widths.add,
                child: MenuAnchor(
                  menuChildren: [
                    for (final (type, label, icon) in columnTypes)
                      MenuItemButton(
                        key: ValueKey('column-add-$type'),
                        leadingIcon: Icon(icon, size: 18),
                        onPressed: () => _addColumn(type),
                        child: Text(label),
                      ),
                  ],
                  builder: (context, menu, _) => IconButton(
                    key: const Key('column-add'),
                    tooltip: 'Add a column',
                    iconSize: 18,
                    padding: EdgeInsets.zero,
                    onPressed: () => menu.isOpen ? menu.close() : menu.open(),
                    icon: const Icon(Icons.add),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _row(BuildContext context, _Json t, Color colour, {bool sub = false}) {
    final theme = Theme.of(context);
    final owner = (t['owner'] as Map?)?['name'] as String?;
    final about = (t['about'] as Map?)?['label'] as String?;
    final updates = (t['updates_count'] as num?) ?? 0;
    final late = _late(t, _board);

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
              Container(
                width: 6,
                color: sub ? colour.withValues(alpha: 0.4) : colour,
              ),
              Expanded(
                child: Padding(
                  padding: EdgeInsets.only(left: sub ? 34 : 2, right: 10),
                  child: Row(
                    children: [
                      if (!sub) _subtaskToggle(t),
                      Flexible(
                        child: Text(
                          '${t['title']}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodyMedium?.copyWith(
                            fontWeight: sub ? FontWeight.w500 : FontWeight.w600,
                          ),
                        ),
                      ),
                      if (!sub) _subtaskCountChip(context, t),
                    ],
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
              for (final c in boardColumns(_board))
                c.type == 'status'
                    ? SizedBox(
                        width: _widths.custom,
                        child: ColumnCell(
                          column: c,
                          value: columnValue(t, c),
                          people: _people,
                          formats: _formats,
                          onChanged: _canEdit
                              ? (v) => _setValue(t, c.id, v)
                              : null,
                        ),
                      )
                    : _cell(
                        context,
                        _widths.custom,
                        ColumnCell(
                          column: c,
                          value: columnValue(t, c),
                          people: _people,
                          formats: _formats,
                          onChanged: _canEdit
                              ? (v) => _setValue(t, c.id, v)
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
              // Under the headings' +, so every column lines up.
              if (_canEdit) SizedBox(width: _widths.add),
            ],
          ),
        ),
      ),
    );
  }

  /// ▸ / ▾ before a task: its subtasks under it, or a place to add the first.
  Widget _subtaskToggle(_Json t) {
    final open = _expanded.contains(t['id']);
    final (_, total) = _subtaskCount(t);
    if (total == 0 && !_canEdit) return const SizedBox(width: 8);
    return IconButton(
      key: ValueKey('task-subtasks-${t['id']}'),
      tooltip: open ? 'Hide subtasks' : 'Show subtasks',
      visualDensity: VisualDensity.compact,
      iconSize: 18,
      onPressed: () => setState(
        () =>
            open ? _expanded.remove(t['id']) : _expanded.add(t['id'] as String),
      ),
      icon: Icon(open ? Icons.expand_more : Icons.chevron_right),
    );
  }

  Widget _subtaskCountChip(BuildContext context, _Json t) {
    final (done, total) = _subtaskCount(t);
    if (total == 0) return const SizedBox.shrink();
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(left: 8),
      child: Container(
        key: ValueKey('task-subtask-count-${t['id']}'),
        padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 1),
        decoration: BoxDecoration(
          color: theme.colorScheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(999),
        ),
        child: Text(
          '$done / $total',
          style: theme.textTheme.bodySmall?.copyWith(
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
    );
  }

  /// Monday's coloured status cell, in the pills' colours: the word on its
  /// colour, and a click
  /// changes it without opening the task.
  Widget _statusCell(BuildContext context, _Json t) {
    final colours = _statusColours(context, _statusFor(_board, _statusOf(t)));
    final label = Text(
      _statusLabel(_board!, _statusOf(t)),
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
        for (final s in _statuses(_board))
          MenuItemButton(
            key: ValueKey('task-status-${t['id']}-${s.id}'),
            leadingIcon: _statusDot(context, s),
            onPressed: () => _setStatus(t, s.id),
            child: Text(s.name),
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

  Widget _card(BuildContext context, _Json t, {bool sub = false}) {
    final theme = Theme.of(context);
    final colours = _statusColours(context, _statusFor(_board, _statusOf(t)));
    final late = _late(t, _board);
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
                Container(
                  width: 5,
                  color:
                      _statusFor(_board, _statusOf(t))?.colour ?? colours.ink,
                ),
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
                            Container(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 9,
                                vertical: 2,
                              ),
                              decoration: BoxDecoration(
                                color: colours.fill,
                                borderRadius: BorderRadius.circular(999),
                              ),
                              child: Text(
                                _statusLabel(_board!, _statusOf(t)),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: TextStyle(
                                  fontSize: 11.5,
                                  fontWeight: FontWeight.w700,
                                  color: colours.ink,
                                ),
                              ),
                            ),
                            if (owner != null) ...[_avatar(context, owner)],
                            if (!sub) ...[
                              _subtaskCountChip(context, t),
                              _subtaskToggle(t),
                            ],
                            if (due.isNotEmpty)
                              Text(
                                due,
                                style: theme.textTheme.bodySmall?.copyWith(
                                  color: late
                                      ? theme.colorScheme.error
                                      : theme.colorScheme.onSurfaceVariant,
                                  fontWeight: late ? FontWeight.w700 : null,
                                ),
                              ),
                            for (final c in boardColumns(_board))
                              if (columnValue(t, c) != null)
                                Text.rich(
                                  TextSpan(
                                    text: '${c.name}: ',
                                    style: theme.textTheme.bodySmall?.copyWith(
                                      color: theme.colorScheme.onSurfaceVariant,
                                    ),
                                    children: [
                                      WidgetSpan(
                                        alignment: PlaceholderAlignment.middle,
                                        child: ConstrainedBox(
                                          constraints: const BoxConstraints(
                                            maxWidth: 160,
                                            maxHeight: 24,
                                          ),
                                          child: ColumnCell(
                                            column: c,
                                            value: columnValue(t, c),
                                            people: _people,
                                            formats: _formats,
                                          ),
                                        ),
                                      ),
                                    ],
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

  Widget _addRow(BuildContext context, String groupId, {String? parent}) {
    final slot = parent == null ? groupId : 'sub:$parent';
    if (_adding != slot) {
      return Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
          key: ValueKey(
            parent == null ? 'task-add-$groupId' : 'task-add-sub-$parent',
          ),
          onPressed: () => setState(() {
            _adding = slot;
            _addError = null;
            _newTitle.clear();
          }),
          icon: const Icon(Icons.add, size: 18),
          label: Text(parent == null ? 'Add task' : 'Add subtask'),
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
          hintText: parent == null
              ? 'Name the task, then press Enter'
              : 'Name the subtask, then press Enter',
          errorText: _addError,
          suffixIcon: IconButton(
            tooltip: 'Cancel',
            icon: const Icon(Icons.close, size: 18),
            onPressed: () => setState(() => _adding = null),
          ),
        ),
        onSubmitted: (_) => _add(groupId, parent: parent),
      ),
    );
  }

  // ── Kanban ─────────────────────────────────────────────────────

  Widget _kanbanView(BuildContext context, {required bool narrow}) {
    final columns = [
      for (final s in _statuses(_board))
        _kanbanColumn(
          context,
          s,
          _tasks
              .where(
                (t) =>
                    t['parent_task_id'] == null &&
                    _statusOf(t) == s.id &&
                    _matches(t),
              )
              .toList(),
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

  Widget _kanbanColumn(
    BuildContext context,
    _Status status,
    List<_Json> tasks,
  ) {
    final theme = Theme.of(context);
    return DecoratedBox(
      key: ValueKey('kanban-${status.id}'),
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
                _statusDot(context, status),
                const SizedBox(width: 6),
                Text(
                  '${status.name} · ${tasks.length}',
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
              for (final s in _statuses(widget.board))
                ChoiceChip(
                  key: ValueKey('task-panel-status-${s.id}'),
                  showCheckmark: false,
                  avatar: _statusDot(context, s),
                  label: Text(s.name),
                  selected: _statusOf(task) == s.id,
                  // Never null on the chosen one: a disabled chip greys out
                  // the very status it is showing.
                  onSelected: _canEdit
                      ? (_) {
                          if (_statusOf(task) != s.id) {
                            _patch(_statusBody(widget.board, s.id));
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
          under: _late(task, widget.board)
              ? const StatusPill('Late', tone: Tone.bad)
              : null,
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
        // The board's own columns, each in its type's editor (UI queue 84).
        for (final c in boardColumns(widget.board))
          fact(
            c.name,
            ColumnEditor(
              key: ValueKey('${c.id}:${columnValue(task, c)}'),
              column: c,
              value: columnValue(task, c),
              people: people,
              formats: widget.formats,
              onChanged: _canEdit
                  ? (v) => _patch({
                      'values': {c.id: v},
                    })
                  : null,
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
