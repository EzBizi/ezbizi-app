import '../widgets/list_search.dart';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/device_key_store_io.dart'
    if (dart.library.js_interop) '../core/device_key_store_web.dart'
    as store;
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
import 'task_board_files.dart';
import 'task_board_gallery.dart';
import '../widgets/list_filters.dart';
import '../widgets/list_toolbar.dart';
import '../widgets/mention_field.dart';
import 'task_board_move.dart';
import 'task_board_people.dart';
import 'task_board_plan_views.dart';
import 'task_board_sidebar.dart';

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

/// What a write sends: `status_id` to a board with its own statuses. A
/// rolled-up row lives on another board, so it goes by the status's name.
_Json _statusBody(_Json board, String id, [_Json? task]) =>
    task?['rolled_up_from'] != null && _statusFor(board, id) != null
    ? {'status': _statusFor(board, id)!.name}
    : (board['statuses'] as List? ?? const []).any(
        (s) => s is Map && s.containsKey('id'),
      )
    ? {'status_id': id}
    : {'status': id};

/// A rolled-up row carries its own board's status id; this board's status of
/// the same name stands in for it, so the cell shows a chip, not an id.
_Json _adopt(_Json board, _Json task) {
  if (task['rolled_up_from'] == null ||
      _statusFor(board, _statusOf(task)) != null) {
    return task;
  }
  final name = (task['status_name'] as String?)?.trim().toLowerCase();
  for (final s in _statuses(board)) {
    if (s.name.trim().toLowerCase() == name) {
      return {...task, 'status_id': s.id};
    }
  }
  return task;
}

/// Every rolled-up row on [board], adopted.
_Json _adoptAll(_Json board) => {
  ...board,
  'tasks': [
    for (final t in (board['tasks'] as List? ?? const []).cast<_Json>())
      _adopt(board, t),
  ],
};

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
String _dueLabel(Object? due, DisplayFormats formats) =>
    _dayLabel(due is String ? DateTime.tryParse(due) : null, formats);

/// The day a task was made, in the same words as Due: a stamp, read as the
/// day it was here.
String _createdLabel(Object? at, DisplayFormats formats) {
  final stamp = at is String ? DateTime.tryParse(at)?.toLocal() : null;
  return _dayLabel(
    stamp == null ? null : DateTime(stamp.year, stamp.month, stamp.day),
    formats,
  );
}

/// The built-in columns in the table's order, with the names a server from
/// before `builtin_columns` showed (Jose, 2026-10-20).
///
/// `creator` (who made the task) is drawn only when the board lists it, so a
/// server from before it keeps its headings; its name is the board's.
const _builtinDefaults = {
  'creator': 'Owner',
  'owner': 'Owner',
  'status': 'Status',
  'created': 'Created',
  'due': 'Due',
  'about': 'About',
  'updates': 'Updates',
};

/// A built-in column's name on this board: its own, or today's.
String _builtinLabel(_Json? board, String key) {
  final row = (board?['builtin_columns'] as Map?)?[key];
  final label = row is Map ? row['label'] : null;
  return label is String && label.trim().isNotEmpty
      ? label
      : _builtinDefaults[key]!;
}

/// Whether the board shows a built-in column. Created starts hidden, as the
/// server has it; a server without the field shows what it always did.
bool _builtinShown(_Json? board, String key) {
  final row = (board?['builtin_columns'] as Map?)?[key];
  final visible = row is Map ? row['visible'] : null;
  if (key == 'creator' && row is! Map) return false;
  return visible is bool ? visible : key != 'created';
}

/// Every owner's name, the primary first: `owners`, or the one `owner`.
List<String> _ownerNames(_Json task) {
  final owners = [
    for (final o in (task['owners'] as List? ?? const []))
      if (o is Map && o['name'] is String) o['name'] as String,
  ];
  if (owners.isNotEmpty) return owners;
  final one = (task['owner'] as Map?)?['name'];
  return one is String ? [one] : const [];
}

String _dayLabel(DateTime? day, DisplayFormats formats) {
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

  /// A phone showing the boards tree in place of the board (UI queue 152, 4).
  bool _choosing = false;

  /// Administrators' view of the personal boards of people who left
  /// (Jose, 2026-11-09), and how many there are.
  bool _archived = false;
  int _archivedCount = 0;

  Future<void> _toggleArchived() async {
    setState(() {
      _archived = !_archived;
      _board = null;
      _selected = 0;
    });
    await _load();
  }

  /// table, kanban, calendar or timeline (design 178).
  String _view = 'table';
  String _query = '';
  final Set<String?> _folded = {};

  /// Filter, sort and person filter in force (Jose, 2026-11-09).
  ListQuery _filter = const ListQuery();

  /// Tasks with their subtasks open under them (UI queue 84).
  final Set<String> _expanded = {};

  /// The group being renamed, or "new" while one is being added.
  String? _naming;
  final _groupName = TextEditingController();

  /// The group whose "Add task" is open as a box, and why the last add failed.
  String? _adding;
  String? _addError;
  final _newTitle = TextEditingController();

  /// The built-in column being renamed, and the group whose headings hold
  /// the box; why the last rename was refused, said under it.
  ({String key, Object? group})? _renaming;
  String? _renameError;
  final _columnName = TextEditingController();

  DisplayFormats get _formats =>
      widget.session.me?.display ?? DisplayFormats.fallback;

  bool get _canEdit => _board?['can_edit'] == true;

  /// Rows ticked for the bulk bar (Jose, 2026-10-28).
  final Set<String> _picked = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _newTitle.dispose();
    _groupName.dispose();
    _columnName.dispose();
    super.dispose();
  }

  /// The tabs, then the board open in them. Also the refresh after a task
  /// closes, so the counts on the tabs follow what changed.
  Future<void> _load() async {
    setState(() {
      _loading = _board == null;
      _error = null;
    });
    final res = await widget.api.get(
      '/task-boards',
      _archived ? {'archived': '1'} : null,
    );
    if (!mounted) return;
    if (!res.ok) {
      setState(() {
        _loading = false;
        _error = res.reason;
      });
      return;
    }
    _boards = [
      for (final b in (res.body['data'] as List).cast<_Json>())
        // An archived board says what still needs handing over.
        if (b['archived'] == true && (b['open_tasks'] as int? ?? 0) > 0)
          {...b, 'name': '${b['name']} · ${b['open_tasks']} open to reassign'}
        else
          b,
    ];
    if (!_archived) {
      _archivedCount =
          ((res.body['meta'] as Map?)?['archived_count'] as int?) ?? 0;
    }
    if (_boards.isEmpty) {
      setState(() => _loading = false);
      return;
    }
    await _open(_startIndex());
  }

  /// Where the last board opened is kept: per person, per place in view.
  String get _lastKey {
    final me = widget.session.me;
    final scope = me?.scope;
    return 'task_board_last_${me?.id ?? ''}_${scope?.type ?? ''}_${scope?.id ?? ''}';
  }

  /// A personal board someone else owns: never opened unasked.
  bool _othersPersonal(_Json b) {
    if (b['kind'] != 'user') return false;
    final owner = (b['owner'] as Map?)?['id'];
    if (owner != null) return owner != widget.session.me?.id;
    return b['section'] != null && b['section'] != 'mine';
  }

  /// The board in view on a refresh; else the one last opened here, if it is
  /// still listed; else My board. The server sorts personal boards by name,
  /// so the first one listed can be another person's (it opened Adeline's).
  int _startIndex() {
    int find(Object? id) =>
        id == null ? -1 : _boards.indexWhere((b) => b['id'] == id);
    for (final id in [_board?['id'], store.readItem(_lastKey)]) {
      final i = find(id);
      if (i >= 0) return i;
    }
    final mine = _boards.indexWhere(
      (b) => b['kind'] == 'user' && !_othersPersonal(b),
    );
    if (mine >= 0) return mine;
    final other = _boards.indexWhere((b) => !_othersPersonal(b));
    return other >= 0 ? other : 0;
  }

  Future<void> _open(int index) async {
    final id = _boards[index]['id'];
    if (id is String) store.writeItem(_lastKey, id, persist: true);
    setState(() {
      _selected = index;
      _error = null;
      _adding = null;
      if (_board?['id'] != id) {
        _board = null;
        _loading = true;
        _filter = const ListQuery();
      }
    });
    // A deleted board cannot be fetched: shown empty, its menu offers Restore.
    if (_boards[index]['deleted'] == true) {
      setState(() {
        _loading = false;
        _board = {
          ..._boards[index],
          'tasks': const [],
          'groups': const [],
          'columns': const [],
          'statuses': const [],
          'views': const [],
          'can_edit': false,
        };
      });
      return;
    }
    final res = await widget.api.get('/task-boards/$id');
    if (!mounted || _boards[_selected]['id'] != id) return;
    setState(() {
      _loading = false;
      if (!res.ok) {
        _error = res.reason;
        return;
      }
      final first = _board == null;
      _board = _adoptAll(res.body['data'] as _Json);
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
    if (_tasks.any((t) => !ids.contains(_groupOf(t)))) {
      groups.add({'id': null, 'name': 'Not in a group', 'colour': null});
    }
    return groups;
  }

  List<_Json> _tasksIn(Object? groupId) {
    final ids = (_board?['groups'] as List? ?? const [])
        .map((g) => (g as Map)['id'])
        .toSet();
    return _filter.sorted([
      for (final t in _tasks)
        if (t['parent_task_id'] == null &&
            (groupId == null
                ? !ids.contains(_groupOf(t))
                : _groupOf(t) == groupId))
          t,
    ], _filterFields);
  }

  // ── Filter, sort, person and saved views (Jose, 2026-11-09) ──────────

  List<_Json> get _views =>
      (_board?['views'] as List? ?? const []).whereType<_Json>().toList();

  /// Saves the current filter, sort and person filter as [name]: shared with
  /// the board when you can edit it, else your own.
  Future<void> _saveView(String name) async {
    final r = await widget.api.post('/task-boards/${_board!['id']}/views', {
      'name': name,
      'shared': _canEdit,
      'config': _filter.toJson(),
    });
    if (!mounted) return;
    if (!r.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(r.reason)));
      return;
    }
    await _open(_selected);
  }

  Future<void> _deleteView(String id) async {
    final r = await widget.api.delete(
      '/task-boards/${_board!['id']}/views/$id',
    );
    if (!mounted) return;
    if (!r.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(r.reason)));
      return;
    }
    await _open(_selected);
  }

  /// UI-UX's shared toolbar (Search · Person · Filter · Sort · Hide · Views).
  Widget _listToolbar() => ListToolbar(
    key: const Key('tasks-toolbar'),
    fields: _filterFields.values.toList(),
    query: _filter,
    onChanged: (q) => setState(() => _filter = q),
    views: [
      for (final v in _views)
        (
          id: '${v['id']}',
          name: '${v['name']}',
          query: ListQuery.fromJson(v['config'] as Map?),
        ),
    ],
    onSaveView: _saveView,
    onDeleteView: _deleteView,
  );

  /// The group a row sits in here. A branch task shown on its company board
  /// (rolled_up_from) sits in this board's group of the same name.
  Object? _groupOf(_Json t) {
    final from = t['rolled_up_from'];
    if (from is! Map) return t['group_id'];
    final name = '${from['group_name']}'.trim().toLowerCase();
    for (final g in (_board?['groups'] as List? ?? const []).cast<Map>()) {
      if ('${g['name']}'.trim().toLowerCase() == name) return g['id'];
    }
    return t['group_id'];
  }

  void _say(String text, {SnackBarAction? action}) => ScaffoldMessenger.of(
    context,
  ).showSnackBar(SnackBar(content: Text(text), action: action));

  /// Drag and drop: [id] lands in [groupId] at [position] (null = the end).
  Future<void> _moveTo(String id, Object? groupId, int? position) async {
    if (groupId == null) return;
    final res = await widget.api.patch('/tasks/$id/move', {
      'group_id': groupId,
      'position': ?position,
    });
    if (!mounted) return;
    res.ok ? await _load() : _say(res.reason);
  }

  Future<String?> _destination(String title, String action) =>
      showSidePanel<String>(
        context,
        builder: (_) => TaskDestinationPanel(
          api: widget.api,
          title: title,
          action: action,
          boardId: _board?['id'] as String?,
        ),
      );

  /// Delete, then a snackbar that can put them back.
  Future<void> _delete(List<String> ids) async {
    final res = ids.length == 1
        ? await widget.api.delete('/tasks/${ids.single}')
        : await widget.api.post('/tasks/bulk', {'ids': ids, 'op': 'delete'});
    if (!mounted) return;
    if (!res.ok) return _say(res.reason);
    final gone = ids.length == 1
        ? ids
        : ((res.body['data'] as Map)['done'] as List).cast<String>();
    setState(() {
      _picked.removeAll(gone);
      _board = {
        ..._board!,
        'tasks': _tasks.where((t) => !gone.contains(t['id'])).toList(),
      };
    });
    _say(
      gone.length == 1 ? 'Task deleted' : '${gone.length} tasks deleted',
      action: SnackBarAction(
        key: const Key('task-undo'),
        label: 'Undo',
        onPressed: () async {
          for (final id in gone) {
            await widget.api.post('/tasks/$id/restore', const {});
          }
          if (mounted) await _load();
        },
      ),
    );
  }

  Future<void> _rowAction(_Json t, String action) async {
    final id = t['id'] as String;
    switch (action) {
      case 'open':
        await _openTask(t);
      case 'move':
        final group = await _destination('Move to', 'Move');
        if (group != null) await _moveTo(id, group, null);
      case 'copy':
      case 'duplicate':
        final group = action == 'copy'
            ? await _destination('Copy to', 'Copy')
            : t['group_id'] as String?;
        if (action == 'copy' && group == null) return;
        final res = await widget.api.post('/tasks/$id/copy', {
          'group_id': ?group,
        });
        if (!mounted) return;
        res.ok ? await _load() : _say(res.reason);
      case 'link':
        await showSidePanel<void>(
          context,
          builder: (_) => TaskLinkPanel(api: widget.api, task: t),
        );
        if (mounted) await _load();
      case 'delete':
        await _delete([id]);
    }
  }

  Widget _rowMenu(_Json t) => PopupMenuButton<String>(
    key: ValueKey('task-menu-${t['id']}'),
    tooltip: 'More',
    padding: EdgeInsets.zero,
    iconSize: 18,
    icon: const Icon(Icons.more_horiz),
    onSelected: (a) => _rowAction(t, a),
    itemBuilder: (_) => [
      const PopupMenuItem(value: 'open', child: Text('Open')),
      if (_canEdit) ...[
        const PopupMenuItem(value: 'move', child: Text('Move to…')),
        const PopupMenuItem(value: 'copy', child: Text('Copy to…')),
        const PopupMenuItem(value: 'duplicate', child: Text('Duplicate')),
      ],
      const PopupMenuItem(value: 'link', child: Text('Link…')),
      if (_canEdit) const PopupMenuItem(value: 'delete', child: Text('Delete')),
    ],
  );

  Future<void> _bulk(String op) async {
    final ids = _picked.toList();
    Map<String, Object?>? extra;
    switch (op) {
      case 'delete':
        return _delete(ids);
      case 'move':
      case 'copy':
        final group = await _destination(
          op == 'move' ? 'Move to' : 'Copy to',
          op == 'move' ? 'Move' : 'Copy',
        );
        if (group == null) return;
        extra = {'group_id': group};
      case 'status':
        final status = await showSidePanel<String>(
          context,
          builder: (_) => TaskChoicePanel(
            title: 'Set status',
            label: 'Status',
            options: {
              for (final s
                  in (_board?['statuses'] as List? ?? const []).cast<Map>())
                '${s['key'] ?? s['id']}': '${s['name'] ?? s['label']}',
            },
          ),
        );
        if (status == null) return;
        extra = {'status': status};
      case 'assign':
        final who = await showSidePanel<String>(
          context,
          builder: (_) => TaskChoicePanel(
            title: 'Assign',
            label: 'Person',
            options: _people,
            none: 'Nobody',
          ),
        );
        if (who == null) return;
        extra = {'assignee_user_id': who.isEmpty ? null : who};
    }
    final res = await widget.api.post('/tasks/bulk', {
      'ids': ids,
      'op': op,
      ...?extra,
    });
    if (!mounted) return;
    if (!res.ok) return _say(res.reason);
    final refused = ((res.body['data'] as Map)['refused'] as List? ?? const []);
    setState(_picked.clear);
    if (refused.isNotEmpty) {
      _say(
        refused.length == 1
            ? '1 task was not changed: ${(refused.first as Map)['reason']}'
            : '${refused.length} tasks were not changed.',
      );
    }
    await _load();
  }

  Widget _bulkBar(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      key: const Key('task-bulk-bar'),
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: BoxDecoration(
        color: theme.colorScheme.secondaryContainer,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Wrap(
        spacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Text(
            '${_picked.length} selected',
            style: theme.textTheme.titleSmall?.copyWith(
              color: theme.colorScheme.onSecondaryContainer,
            ),
          ),
          for (final (op, label) in const [
            ('move', 'Move'),
            ('copy', 'Copy'),
            ('status', 'Status'),
            ('assign', 'Assign'),
            ('delete', 'Delete'),
          ])
            TextButton(
              key: ValueKey('task-bulk-$op'),
              onPressed: () => _bulk(op),
              child: Text(label),
            ),
          TextButton(
            key: const Key('task-bulk-clear'),
            onPressed: () => setState(_picked.clear),
            child: const Text('Clear'),
          ),
        ],
      ),
    );
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

  /// What the filter and sort can read: the built-in columns, then the
  /// board's own.
  Map<String, FilterField> get _filterFields {
    final board = _board;
    if (board == null) return const {};
    final people = [for (final e in _people.entries) (e.key, e.value)];
    final out = <FilterField>[
      (
        key: 'title',
        label: 'Task',
        kind: 'text',
        options: const [],
        read: (t) => t['title'],
      ),
      (
        key: 'status',
        label: _builtinLabel(board, 'status'),
        kind: 'options',
        options: [for (final s in _statuses(board)) (s.id, s.name)],
        read: (t) => _statusOf(t),
      ),
      (
        key: 'owner',
        label: _builtinLabel(board, 'owner'),
        kind: 'people',
        options: people,
        read: (t) => _ownerIds(t),
      ),
      (
        key: 'due',
        label: _builtinLabel(board, 'due'),
        kind: 'date',
        options: const [],
        read: (t) => t['due_on'],
      ),
      (
        key: 'created',
        label: _builtinLabel(board, 'created'),
        kind: 'date',
        options: const [],
        read: (t) => t['created_at'],
      ),
      for (final c in boardColumns(board))
        (
          key: c.id,
          label: c.name,
          kind: switch (c.type) {
            'number' || 'currency' || 'formula' => 'number',
            'date' || 'datetime' || 'timeline' => 'date',
            'status' || 'dropdown' || 'tags' => 'options',
            'person' => 'people',
            'checkbox' => 'checkbox',
            _ => 'text',
          },
          options: c.type == 'person'
              ? people
              : [for (final o in c.options) (o.id, o.label)],
          read: (t) {
            final v = columnValue(t, c);
            return switch (c.type) {
              'timeline' => (v as Map?)?['from'],
              'link' => (v as Map?)?['label'],
              _ => v,
            };
          },
        ),
    ];
    return {for (final f in out) f.key: f};
  }

  /// Every owner's id, for the person filter.
  List<String> _ownerIds(_Json t) {
    final ids = [
      for (final o in (t['owners'] as List? ?? const []))
        if (o is Map && o['id'] != null) '${o['id']}',
    ];
    final one = (t['owner'] as Map?)?['id'];
    return ids.isNotEmpty ? ids : [if (one != null) '$one'];
  }

  /// Search reads the task, its owner, its status and what it is about; the
  /// filter and person filter go first.
  bool _matches(_Json t) {
    if (!_filter.isEmpty && !_filter.matches(t, _filterFields, _ownerIds(t))) {
      return false;
    }
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return true;
    return [
      t['title'],
      ..._ownerNames(t),
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
    // A write's reply does not say the row is rolled up; the old row does.
    final from = at < 0 ? null : tasks[at]['rolled_up_from'];
    if (from != null) task = _adopt(_board!, {...task, 'rolled_up_from': from});
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

  /// One built-in column's name or visibility, only that key sent; the
  /// server merges it into the rest. Null when it took, else why not.
  Future<String?> _setBuiltin(String key, _Json change) async {
    final res = await widget.api.patch('/task-boards/${_board!['id']}', {
      'builtin_columns': {key: change},
    });
    if (!mounted) return null;
    if (!res.ok) return res.reason;
    final saved = (res.body['data'] as Map?)?['builtin_columns'];
    setState(() {
      final now = {...(_board!['builtin_columns'] as Map? ?? const {})};
      now[key] = {...(now[key] as Map? ?? const {}), ...change};
      _board = {..._board!, 'builtin_columns': saved is Map ? saved : now};
    });
    return null;
  }

  Future<void> _showBuiltin(String key, bool visible) async {
    final why = await _setBuiltin(key, {'visible': visible});
    if (why != null && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(why)));
    }
  }

  Future<void> _saveColumnName() async {
    final r = _renaming;
    if (r == null) return;
    final name = _columnName.text.trim();
    if (name.isEmpty || name == _builtinLabel(_board, r.key)) {
      return setState(() => _renaming = null);
    }
    final why = await _setBuiltin(r.key, {'label': name});
    if (!mounted) return;
    setState(() {
      _renameError = why;
      if (why == null) _renaming = null;
    });
  }

  /// A built-in column's heading: its name, and on a board you may edit a
  /// menu to rename or hide it (Jose, 2026-10-20).
  Widget _builtinHead(String key, Object? group, Widget label) {
    if (!_canEdit) return label;
    return MenuAnchor(
      menuChildren: [
        MenuItemButton(
          key: ValueKey('builtin-rename-$key'),
          leadingIcon: const Icon(Icons.edit_outlined, size: 18),
          onPressed: () => setState(() {
            _renaming = (key: key, group: group);
            _renameError = null;
            _columnName.text = _builtinLabel(_board, key);
          }),
          child: const Text('Rename'),
        ),
        MenuItemButton(
          key: ValueKey('builtin-hide-$key'),
          leadingIcon: const Icon(Icons.visibility_off_outlined, size: 18),
          onPressed: () => _showBuiltin(key, false),
          child: const Text('Hide column'),
        ),
      ],
      builder: (context, menu, _) => InkWell(
        key: ValueKey('builtin-head-$key'),
        onTap: () => menu.isOpen ? menu.close() : menu.open(),
        child: Align(alignment: Alignment.centerLeft, child: label),
      ),
    );
  }

  /// The box a built-in column's new name is typed into, under the
  /// headings it was asked from.
  Widget _columnNameField(String key) => _cellBorder(
    context,
    Padding(
      padding: const EdgeInsets.fromLTRB(16, 6, 16, 6),
      child: Align(
        alignment: Alignment.centerLeft,
        child: SizedBox(
          width: 320,
          child: TextField(
            key: const Key('builtin-name'),
            controller: _columnName,
            autofocus: true,
            maxLength: 60,
            decoration: InputDecoration(
              isDense: true,
              labelText: 'Rename "${_builtinLabel(_board, key)}"',
              hintText: 'Name the column, then press Enter',
              counterText: '',
              errorText: _renameError,
              errorMaxLines: 3,
              suffixIcon: IconButton(
                key: const Key('builtin-name-cancel'),
                tooltip: 'Cancel',
                icon: const Icon(Icons.close, size: 18),
                onPressed: () => setState(() => _renaming = null),
              ),
            ),
            onSubmitted: (_) => _saveColumnName(),
          ),
        ),
      ),
    ),
  );

  /// Columns: the built-in columns this board hides, to show again.
  Widget _columnsControl() {
    final hidden = [
      for (final key in _builtinDefaults.keys)
        if (!_builtinShown(_board, key) &&
            (key != 'creator' ||
                (_board?['builtin_columns'] as Map?)?['creator'] is Map))
          key,
    ];
    return MenuAnchor(
      menuChildren: [
        for (final key in hidden)
          MenuItemButton(
            key: ValueKey('builtin-show-$key'),
            leadingIcon: const Icon(Icons.visibility_outlined, size: 18),
            onPressed: () => _showBuiltin(key, true),
            child: Text('Show ${_builtinLabel(_board, key)}'),
          ),
        if (hidden.isEmpty)
          const MenuItemButton(
            onPressed: null,
            child: Text('Every column is showing'),
          ),
      ],
      builder: (context, menu, _) => OutlinedButton.icon(
        key: const Key('tasks-columns'),
        onPressed: () => menu.isOpen ? menu.close() : menu.open(),
        icon: const Icon(Icons.view_column_outlined, size: 18),
        label: Text(
          hidden.isEmpty ? 'Columns' : 'Columns · ${hidden.length} hidden',
        ),
      ),
    );
  }

  /// Add a column of [type] (UI queue 84). The board reloads after.
  Future<void> _addColumn(String type) async {
    await showSidePanel<bool>(
      context,
      builder: (_) => ColumnPanel(
        api: widget.api,
        boardId: '${_board!['id']}',
        type: type,
        others: boardColumns(_board),
      ),
    );
    if (mounted) await _load();
  }

  /// A column's header menu (Jose, 2026-11-09, Monday-style): edit it,
  /// change its type, move, hide or delete it, all from the header.
  Widget _columnHeader(BoardColumn c, Widget title) {
    final raw = (_board!['columns'] as List? ?? const [])
        .whereType<Map>()
        .firstWhere((x) => '${x['id']}' == c.id, orElse: () => const {});
    final at = (raw['position'] as num?)?.toInt() ?? 0;
    Future<void> patch(Map<String, dynamic> body) async {
      final r = await widget.api.patch(
        columnsPath('${_board!['id']}', c.id),
        body,
      );
      if (!mounted) return;
      if (!r.ok) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(r.reason)));
      }
      await _load();
    }

    return MenuAnchor(
      menuChildren: [
        MenuItemButton(
          key: ValueKey('column-menu-edit-${c.id}'),
          leadingIcon: const Icon(Icons.edit_outlined, size: 18),
          onPressed: () => _editColumn(c),
          child: Text(
            const {'status', 'dropdown', 'tags'}.contains(c.type)
                ? 'Rename, labels and colours…'
                : 'Rename and settings…',
          ),
        ),
        SubmenuButton(
          key: ValueKey('column-menu-type-${c.id}'),
          leadingIcon: const Icon(Icons.swap_horiz, size: 18),
          menuChildren: [
            for (final (type, label, icon) in columnTypes)
              if (type != c.type)
                MenuItemButton(
                  key: ValueKey('column-menu-type-${c.id}-$type'),
                  leadingIcon: Icon(icon, size: 18),
                  onPressed: () => _editColumn(c, type: type),
                  child: Text(label),
                ),
          ],
          child: Text('Type: ${columnTypeLabel(c.type)}'),
        ),
        MenuItemButton(
          leadingIcon: const Icon(Icons.arrow_back, size: 18),
          onPressed: () => patch({'position': (at - 1).clamp(0, 1000)}),
          child: const Text('Move left'),
        ),
        MenuItemButton(
          leadingIcon: const Icon(Icons.arrow_forward, size: 18),
          onPressed: () => patch({'position': at + 1}),
          child: const Text('Move right'),
        ),
        MenuItemButton(
          key: ValueKey('column-menu-hide-${c.id}'),
          leadingIcon: const Icon(Icons.visibility_off_outlined, size: 18),
          onPressed: () => patch({'hidden': true}),
          child: const Text('Hide'),
        ),
        MenuItemButton(
          leadingIcon: const Icon(Icons.delete_outline, size: 18),
          onPressed: () => _editColumn(c),
          child: const Text('Delete…'),
        ),
      ],
      builder: (context, menu, _) => InkWell(
        key: ValueKey('column-head-${c.id}'),
        onTap: _canEdit ? () => menu.isOpen ? menu.close() : menu.open() : null,
        child: Align(
          alignment: Alignment.centerLeft,
          child: Tooltip(message: c.name, child: title),
        ),
      ),
    );
  }

  /// "+ Add tag" from a cell: the option joins the column, and its id comes
  /// back so the cell can pick it.
  Future<String?> _addOption(BoardColumn c, String label) async {
    final raw = (_board!['columns'] as List? ?? const [])
        .whereType<Map>()
        .firstWhere((x) => '${x['id']}' == c.id, orElse: () => const {});
    final settings = {...((raw['settings'] as Map?) ?? const {})};
    final options = [...((settings['options'] as List?) ?? const [])];
    final existing = options.whereType<Map>().where(
      (o) => '${o['label']}'.toLowerCase() == label.toLowerCase(),
    );
    if (existing.isNotEmpty) return '${existing.first['id']}';
    options.add({
      'label': label,
      'colour': swatches[options.length % swatches.length],
    });
    final r = await widget.api.patch(columnsPath('${_board!['id']}', c.id), {
      'settings': {...settings, 'options': options},
    });
    if (!mounted) return null;
    if (!r.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(r.reason)));
      return null;
    }
    await _load();
    final saved = (((r.data as Map?)?['settings'] as Map?)?['options'] as List?)
        ?.whereType<Map>()
        .where((o) => '${o['label']}' == label)
        .firstOrNull;
    return saved == null ? null : '${saved['id']}';
  }

  Future<void> _editColumn(BoardColumn c, {String? type}) async {
    final raw = (_board!['columns'] as List? ?? const [])
        .whereType<Map>()
        .firstWhere((x) => '${x['id']}' == c.id, orElse: () => const {});
    await showSidePanel<bool>(
      context,
      builder: (_) => ColumnPanel(
        api: widget.api,
        boardId: '${_board!['id']}',
        type: c.type,
        newType: type,
        column: c,
        raw: raw.cast<String, dynamic>(),
        others: boardColumns(_board),
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
      _statusBody(_board!, status, task),
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

  /// The Gallery's "Go to task": false when this board's list lacks it.
  bool _openTaskById(String id) {
    for (final t in _tasks) {
      if (t['id'] == id) {
        _openTask(t);
        return true;
      }
    }
    return false;
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
        unreadNotes: (task['unread_notes'] as num? ?? 0).toInt(),
      ),
    );
    if (mounted) await _load();
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final cards = constraints.maxWidth < TaskBoardScreen.tableAbove;

        final board = Column(
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
                            child: switch (_view) {
                              'kanban' => _kanbanView(context, narrow: cards),
                              'calendar' => _calendarView(phone: cards),
                              'timeline' => _timelineView(phone: cards),
                              'gallery' => TaskBoardGallery(
                                key: ValueKey('gallery-${_board!['id']}'),
                                api: widget.api,
                                boardId: '${_board!['id']}',
                                formats: _formats,
                                onOpenTask: _openTaskById,
                              ),
                              _ => _tableView(context, cards: cards),
                            },
                          ),
                        ],
                      ),
              ),
            ),
          ],
        );
        if (_boards.isEmpty) return board;
        if (cards) return _choosing ? _choosingPane(context) : board;
        return Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _tree(docked: true),
            Expanded(child: board),
          ],
        );
      },
    );
  }

  /// Which boards tree is kept on this device: per person.
  String get _treeKey => 'task_board_tree_${widget.session.me?.id ?? ''}';

  /// The boards tree (UI queue 152, 1): down the left beside the board, or
  /// full screen on a phone once the title is tapped (152, 4).
  Widget _tree({required bool docked}) => BoardTree(
    boards: _boards,
    selected: _selected,
    storeKey: _treeKey,
    docked: docked,
    onOpen: (i) {
      setState(() => _choosing = false);
      if (i != _selected) _open(i);
    },
    onNewBoard: (widget.session.me?.can('tasks.create_boards') ?? false)
        ? () {
            setState(() => _choosing = false);
            _newBoard();
          }
        : null,
  );

  /// The phone's boards tree in place of the board, with its own way back.
  Widget _choosingPane(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(4, 4, 16, 0),
        child: Row(
          children: [
            IconButton(
              key: const Key('tasks-boards-back'),
              tooltip: 'Back to the board',
              icon: const Icon(Icons.arrow_back),
              onPressed: () => setState(() => _choosing = false),
            ),
            Text('Boards', style: Theme.of(context).textTheme.titleMedium),
          ],
        ),
      ),
      Expanded(child: _tree(docked: false)),
    ],
  );

  /// [My Board] [<Company> Board] (Jose, 2026-10-26), lit when theirs is
  /// open. Every other board is in the tree; on a phone the board's title ▾
  /// opens it.
  Widget _picker(BuildContext context, {required bool phone}) {
    final mine = _myBoardIndex;
    final company = _companyBoardIndex;
    final quick = {?mine, ?company};

    Widget label(String text) => phone
        ? ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 130),
            child: Text(text, maxLines: 1, overflow: TextOverflow.ellipsis),
          )
        : Text(text);
    final buttons = quick.isEmpty
        ? null
        : SegmentedButton<int>(
            key: const Key('tasks-quick-boards'),
            showSelectedIcon: false,
            emptySelectionAllowed: true,
            segments: [
              if (mine != null)
                ButtonSegment(
                  value: mine,
                  label: label('My Board'),
                  icon: const Icon(Icons.person_outline, size: 18),
                ),
              if (company != null)
                ButtonSegment(
                  value: company,
                  label: label(_companyBoardLabel(company)),
                  icon: const Icon(Icons.business_outlined, size: 18),
                ),
            ],
            selected: {if (quick.contains(_selected)) _selected},
            onSelectionChanged: (s) {
              if (s.isNotEmpty && s.first != _selected) _open(s.first);
            },
          );

    final b = _boards[_selected];
    final title = phone
        ? ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 358),
            child: TextButton.icon(
              key: const ValueKey('tasks-boards'),
              iconAlignment: IconAlignment.end,
              icon: const Icon(Icons.arrow_drop_down),
              onPressed: () => setState(() => _choosing = true),
              label: Text(
                b['kind'] == 'user' &&
                        b['section'] != 'shared' &&
                        b['section'] != 'everyone'
                    ? 'My board'
                    : '${b['kind'] == 'user' ? "${(b['owner'] as Map?)?['name'] ?? 'Someone'}'s board" : b['name']}',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleMedium,
              ),
            ),
          )
        : null;
    if (buttons == null && title == null) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 0),
      child: Wrap(
        spacing: 10,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [?title, ?buttons],
      ),
    );
  }

  /// This person's own board: the section "mine" (or, from an older
  /// server, the personal board they own).
  int? get _myBoardIndex {
    final i = _boards.indexWhere(
      (b) =>
          b['kind'] == 'user' &&
          (b['section'] == null ? !_othersPersonal(b) : b['section'] == 'mine'),
    );
    return i < 0 ? null : i;
  }

  /// The board of the place this person is in: at a company or a branch,
  /// that company's board; at the organization, its board if it keeps one.
  /// Null hides the button.
  int? get _companyBoardIndex {
    final me = widget.session.me;
    if (me == null) return null;
    final i = me.scope.type == 'organization'
        ? _boards.indexWhere((b) => b['kind'] == 'organization')
        : switch (me.filingCompanyId) {
            null => -1,
            final id => _boards.indexWhere(
              (b) => b['kind'] == 'company' && b['company_id'] == id,
            ),
          };
    return i < 0 ? null : i;
  }

  /// "A-1 Chimney Board": the company's name from the board, else from /me.
  String _companyBoardLabel(int i) {
    final b = _boards[i];
    final me = widget.session.me;
    final id = b['company_id'];
    final name =
        b['company_name'] ??
        me?.companies.where((c) => c.id == id).firstOrNull?.name ??
        (b['kind'] == 'organization' ? me?.organization.name : null) ??
        b['name'];
    return '$name Board';
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
          SegmentedButton<String>(
            key: const Key('tasks-view'),
            showSelectedIcon: false,
            segments: [
              for (final (v, label, icon) in const [
                ('table', 'Table', Icons.table_rows_outlined),
                ('kanban', 'Kanban', Icons.view_kanban_outlined),
                ('calendar', 'Calendar', Icons.calendar_month_outlined),
                ('timeline', 'Timeline', Icons.view_timeline_outlined),
                ('gallery', 'Gallery', Icons.photo_library_outlined),
              ])
                ButtonSegment(
                  value: v,
                  label: Text(label),
                  icon: phone ? null : Icon(icon, size: 18),
                ),
            ],
            selected: {_view},
            onSelectionChanged: (v) => setState(() => _view = v.first),
          ),
          SizedBox(
            width: phone ? 200 : 240,
            child: ListSearch(
              key: const Key('tasks-search'),
              hint: 'Find a task',
              onChanged: (v) => setState(() => _query = v),
            ),
          ),
          if (_board != null) _listToolbar(),
          // On a phone the rest of the toolbar folds behind one button: all
          // pinned, it left about 300 px for tasks (phone audit 2026-11-04,
          // Section 4 #4).
          if (phone)
            IconButton(
              key: const Key('tasks-more-tools'),
              tooltip: _toolsOpen ? 'Fewer controls' : 'More controls',
              isSelected: _toolsOpen,
              icon: const Icon(Icons.tune),
              onPressed: () => setState(() => _toolsOpen = !_toolsOpen),
            ),
          if (!phone || _toolsOpen) ..._moreTools(phone: phone),
          // People stays a chip on a phone (UI queue 95, phone 7).
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

  /// Folded away on a phone until asked for.
  bool _toolsOpen = false;

  /// Archive, delete and restore (Jose, 2026-11-09: "I can't delete boards").
  Widget _boardMenu() {
    final board = _board!;
    final deleted = board['deleted'] == true;
    return MenuAnchor(
      menuChildren: [
        if (deleted)
          MenuItemButton(
            key: const Key('board-restore'),
            leadingIcon: const Icon(Icons.restore, size: 18),
            onPressed: () => _boardAction('restore'),
            child: Text('Restore (until ${board['restore_until'] ?? ''})'),
          )
        else ...[
          MenuItemButton(
            key: const Key('board-archive'),
            leadingIcon: Icon(
              board['archived'] == true
                  ? Icons.unarchive_outlined
                  : Icons.archive_outlined,
              size: 18,
            ),
            onPressed: () => _boardAction(
              board['archived'] == true ? 'unarchive' : 'archive',
            ),
            child: Text(
              board['archived'] == true ? 'Unarchive board' : 'Archive board',
            ),
          ),
          if (board['can_delete'] == true)
            MenuItemButton(
              key: const Key('board-delete'),
              leadingIcon: const Icon(Icons.delete_outline, size: 18),
              onPressed: _deleteBoard,
              child: const Text('Delete board…'),
            ),
        ],
      ],
      builder: (context, menu, _) => IconButton(
        key: const Key('board-menu'),
        tooltip: 'Board',
        icon: const Icon(Icons.more_horiz),
        onPressed: () => menu.isOpen ? menu.close() : menu.open(),
      ),
    );
  }

  Future<void> _boardAction(String action) async {
    final id = _board!['id'];
    // Literal paths, so the route guard can match each one.
    final path = switch (action) {
      'archive' => '/task-boards/$id/archive',
      'unarchive' => '/task-boards/$id/unarchive',
      _ => '/task-boards/$id/restore',
    };
    final r = await widget.api.post(path, const {});
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          !r.ok
              ? r.reason
              : switch (action) {
                  'archive' => 'Board archived. Find it under Archived boards.',
                  'unarchive' => 'Board is back in the list.',
                  _ => 'Board restored.',
                },
        ),
      ),
    );
    if (!r.ok) return;
    setState(() {
      _archived = false;
      _board = null;
      _selected = 0;
    });
    await _load();
  }

  /// Two steps, as a job's delete: what goes, then the name typed back.
  Future<void> _deleteBoard() async {
    final name = '${_board!['name']}';
    final typed = TextEditingController();
    final ok = await showSidePanel<bool>(
      context,
      builder: (panel) => StatefulBuilder(
        builder: (panel, set) => SidePanel(
          title: 'Delete $name?',
          actions: [
            TextButton(
              onPressed: () => Navigator.of(panel).pop(false),
              child: const Text('Keep it'),
            ),
            FilledButton(
              key: const Key('board-delete-confirm'),
              style: FilledButton.styleFrom(
                backgroundColor: Theme.of(panel).colorScheme.error,
              ),
              onPressed: typed.text.trim() == name.trim()
                  ? () => Navigator.of(panel).pop(true)
                  : null,
              child: const Text('Delete board'),
            ),
          ],
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                'The board and its tasks leave every list. You can restore it '
                'from Archived boards for 30 days; after that it is gone.',
              ),
              const SizedBox(height: 12),
              TextField(
                key: const Key('board-delete-name'),
                controller: typed,
                autofocus: true,
                onChanged: (_) => set(() {}),
                decoration: InputDecoration(
                  labelText: 'Type $name to delete it',
                  border: const OutlineInputBorder(),
                ),
              ),
            ],
          ),
        ),
      ),
    );
    if (ok != true || !mounted) return;
    final r = await widget.api.delete('/task-boards/${_board!['id']}', {
      'confirm': name,
    });
    if (!mounted) return;
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(r.ok ? 'Board deleted.' : r.reason)));
    if (!r.ok) return;
    setState(() {
      _board = null;
      _selected = 0;
    });
    await _load();
  }

  List<Widget> _moreTools({required bool phone}) => [
    if (_board?['can_set_edit_mode'] == true || _board?['deleted'] == true)
      _boardMenu(),
    if (_archived || _archivedCount > 0)
      OutlinedButton.icon(
        key: const Key('tasks-archived'),
        onPressed: _toggleArchived,
        icon: Icon(
          _archived ? Icons.arrow_back : Icons.inventory_2_outlined,
          size: 18,
        ),
        label: Text(
          _archived ? 'Back to boards' : 'Archived boards · $_archivedCount',
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
    if (_canEdit && _board?['builtin_columns'] is Map) _columnsControl(),
    // Assignee mode (Jose, 2026-11-09, monday's NW board).
    if (_board?['can_set_edit_mode'] == true)
      FilterChip(
        key: const Key('tasks-edit-mode'),
        avatar: const Icon(Icons.lock_person_outlined, size: 18),
        showCheckmark: false,
        label: const Text('Only assignees edit'),
        tooltip:
            'On: each person changes only the tasks assigned to them or that '
            'they made. Owners and Administrators still change everything.',
        selected: _board?['edit_mode'] == 'assignee',
        onSelected: (on) => _setEditMode(on ? 'assignee' : 'everyone'),
      ),
  ];

  Future<void> _setEditMode(String mode) async {
    final r = await widget.api.patch('/task-boards/${_board!['id']}', {
      'edit_mode': mode,
    });
    if (!mounted) return;
    if (!r.ok) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(r.reason)));
      return;
    }
    await _open(_selected);
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
    Widget box(Widget w) => SliverToBoxAdapter(child: w);
    return CustomScrollView(
      slivers: [
        SliverPadding(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 24),
          sliver: SliverMainAxisGroup(
            slivers: [
              if (_picked.isNotEmpty && !cards) box(_bulkBar(context)),
              for (final (i, g) in _groups(_board!).indexed)
                // The walkthrough's last step points at the first group.
                i == 0
                    ? KeyedSubtree(
                        key: const ValueKey('tasks-groups'),
                        child: _group(context, g, cards: cards),
                      )
                    : _group(context, g, cards: cards),
              if (_canEdit)
                box(
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
                ),
            ],
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

    final header = _dropOn(
      id,
      null,
      InkWell(
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
      ),
    );

    if (cards) {
      return SliverToBoxAdapter(
        child: Padding(
          padding: const EdgeInsets.only(bottom: 14),
          child: Column(
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
                        child: _addRow(context, id, parent: t['id'] as String),
                      ),
                  ],
                ],
                if (_canEdit && id != null) _addRow(context, id),
              ],
            ],
          ),
        ),
      );
    }

    // The group's name and column headings stay pinned while its rows scroll
    // under them (Jose, 2026-11-02: 347 rows and no telling the columns apart).
    final edge = BorderSide(color: theme.dividerColor);
    return SliverPadding(
      padding: const EdgeInsets.only(bottom: 14),
      sliver: SliverMainAxisGroup(
        slivers: [
          PinnedHeaderSliver(
            child: Container(
              key: ValueKey('task-group-top-$id'),
              decoration: BoxDecoration(
                color: theme.colorScheme.surface,
                border: folded
                    ? Border.fromBorderSide(edge)
                    : Border(top: edge, left: edge, right: edge),
                borderRadius: folded
                    ? BorderRadius.circular(12)
                    : const BorderRadius.vertical(top: Radius.circular(12)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  header,
                  if (!folded) ...[
                    // An empty group drew a full column-header row for nothing.
                    if (shown.isNotEmpty) _headings(context, id),
                    if (_renaming case final r? when r.group == id)
                      _columnNameField(r.key),
                  ],
                ],
              ),
            ),
          ),
          if (!folded)
            SliverToBoxAdapter(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  color: theme.colorScheme.surface,
                  border: Border(left: edge, right: edge, bottom: edge),
                  borderRadius: const BorderRadius.vertical(
                    bottom: Radius.circular(12),
                  ),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    for (final (at, t) in shown.indexed) ...[
                      _dropOn(id, at, _row(context, t, colour)),
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
                    ?_footer(context, shown),
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
                ),
              ),
            ),
        ],
      ),
    );
  }

  static const _widths = (
    creator: 130.0,
    owner: 130.0,
    status: 130.0,
    due: 104.0,
    created: 104.0,
    about: 170.0,
    updates: 84.0,
    custom: 120.0,
    add: 40.0,
  );

  /// Everything but the task's own column, so a board with many columns
  /// scrolls sideways instead of squeezing the task to nothing.
  double get _fixedWidth =>
      6 +
      // The row's ⋯ menu, and its drag handle and tick where it can change.
      (_canEdit ? 82 : 32) +
      (_builtinShown(_board, 'creator') ? _widths.creator : 0) +
      (_builtinShown(_board, 'owner') ? _widths.owner : 0) +
      (_builtinShown(_board, 'status') ? _widths.status : 0) +
      (_builtinShown(_board, 'due') ? _widths.due : 0) +
      (_builtinShown(_board, 'created') ? _widths.created : 0) +
      (_builtinShown(_board, 'about') ? _widths.about : 0) +
      (_builtinShown(_board, 'updates') ? _widths.updates : 0) +
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

  /// Under a group's rows: each number or formula column's sum or average,
  /// as its settings say (design 178). Null when no column asks for one.
  Widget? _footer(BuildContext context, List<_Json> tasks) {
    final columns = boardColumns(_board);
    if (tasks.isEmpty || columns.every((c) => c.footer == 'none')) return null;
    final theme = Theme.of(context);
    final style = theme.textTheme.bodySmall?.copyWith(
      fontWeight: FontWeight.w700,
      color: theme.colorScheme.onSurfaceVariant,
      fontFeatures: const [FontFeature.tabularFigures()],
    );
    bool shown(String key) => _builtinShown(_board, key);
    Widget blank(double w) => SizedBox(width: w);
    String? total(BoardColumn c) {
      if (c.footer == 'none' || (c.type != 'number' && c.type != 'formula')) {
        return null;
      }
      final values = [
        for (final t in tasks)
          if (columnValue(t, c) case final num n) n,
      ];
      if (values.isEmpty) return null;
      final sum = values.fold<num>(0, (a, b) => a + b);
      return c.footer == 'average'
          ? 'avg ${formatColumnNumber(sum / values.length, c)}'
          : formatColumnNumber(sum, c);
    }

    return _cellBorder(
      context,
      SizedBox(
        height: 30,
        child: Row(
          children: [
            const SizedBox(width: 6),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 10),
                child: Text('Total', style: style),
              ),
            ),
            if (shown('creator')) blank(_widths.creator),
            if (shown('owner')) blank(_widths.owner),
            if (shown('status')) blank(_widths.status),
            if (shown('created')) blank(_widths.created),
            if (shown('due')) blank(_widths.due),
            for (final c in columns)
              SizedBox(
                width: _widths.custom,
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 8),
                  child: Text(
                    total(c) ?? '',
                    key: ValueKey('column-footer-${c.id}'),
                    textAlign: TextAlign.right,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: style,
                  ),
                ),
              ),
            if (shown('about')) blank(_widths.about),
            if (shown('updates')) blank(_widths.updates),
            if (_canEdit) blank(_widths.add),
          ],
        ),
      ),
    );
  }

  Widget _headings(BuildContext context, Object? group) {
    final theme = Theme.of(context);
    final style = theme.textTheme.bodySmall?.copyWith(
      fontSize: 10.5,
      fontWeight: FontWeight.w800,
      letterSpacing: 0.6,
      color: theme.colorScheme.onSurfaceVariant,
    );
    Text h(String s) => Text(s.toUpperCase(), style: style, maxLines: 1);
    Widget b(String key, double width) => _cell(
      context,
      width,
      _builtinHead(
        key,
        group,
        Tooltip(
          message: _builtinLabel(_board, key),
          child: h(_builtinLabel(_board, key)),
        ),
      ),
    );
    bool shown(String key) => _builtinShown(_board, key);
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
            if (shown('creator')) b('creator', _widths.creator),
            if (shown('owner')) b('owner', _widths.owner),
            if (shown('status')) b('status', _widths.status),
            if (shown('created')) b('created', _widths.created),
            if (shown('due')) b('due', _widths.due),
            for (final c in boardColumns(_board))
              _cell(context, _widths.custom, _columnHeader(c, h(c.name))),
            if (shown('about')) b('about', _widths.about),
            if (shown('updates')) b('updates', _widths.updates),
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
    final owners = _ownerNames(t);
    final owner = owners.firstOrNull;
    final about = (t['about'] as Map?)?['label'] as String?;
    final updates = (t['updates_count'] as num?) ?? 0;
    final late = _late(t, _board);
    bool shown(String key) => _builtinShown(_board, key);

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
                      if (!sub && _canEdit) ...[
                        SizedBox(width: 18, child: _dragHandle(context, t)),
                        SizedBox(
                          width: 32,
                          // A toggle, not a Checkbox: the board's own
                          // checkbox columns are the Checkboxes on a row.
                          child: IconButton(
                            key: ValueKey('task-pick-${t['id']}'),
                            tooltip: 'Select',
                            padding: EdgeInsets.zero,
                            iconSize: 18,
                            isSelected: _picked.contains(t['id']),
                            icon: const Icon(Icons.check_box_outline_blank),
                            selectedIcon: const Icon(Icons.check_box),
                            onPressed: () => setState(
                              () => _picked.remove(t['id'])
                                  ? null
                                  : _picked.add(t['id'] as String),
                            ),
                          ),
                        ),
                      ],
                      if (!sub) SizedBox(width: 32, child: _rowMenu(t)),
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
                      if (t['rolled_up_from'] case final Map from)
                        Padding(
                          padding: const EdgeInsets.only(left: 6),
                          child: Text(
                            '${from['board_name']}',
                            key: ValueKey('task-from-${t['id']}'),
                            style: theme.textTheme.labelSmall?.copyWith(
                              color: theme.colorScheme.onSurfaceVariant,
                            ),
                          ),
                        ),
                      if (!sub) _subtaskCountChip(context, t),
                    ],
                  ),
                ),
              ),
              if (shown('creator'))
                _cell(context, _widths.creator, _creatorCell(context, t)),
              if (shown('owner'))
                _cell(
                  context,
                  _widths.owner,
                  owner == null
                      ? _automationOwner(t)
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
                            if (owners.length > 1) _moreOwners(t, owners),
                          ],
                        ),
                ),
              if (shown('status'))
                SizedBox(width: _widths.status, child: _statusCell(context, t)),
              if (shown('created'))
                _cell(
                  context,
                  _widths.created,
                  Text(
                    _createdLabel(t['created_at'], _formats),
                    key: ValueKey('task-created-${t['id']}'),
                    maxLines: 1,
                  ),
                ),
              if (shown('due'))
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
                          onAddOption: _canEdit
                              ? (label) => _addOption(c, label)
                              : null,
                          onEditOptions: _canEdit ? () => _editColumn(c) : null,
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
                          onAddOption: _canEdit
                              ? (label) => _addOption(c, label)
                              : null,
                          onEditOptions: _canEdit ? () => _editColumn(c) : null,
                        ),
                      ),
              if (shown('about'))
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
              if (shown('updates'))
                _cell(
                  context,
                  _widths.updates,
                  updates == 0
                      ? const SizedBox.shrink()
                      : Row(
                          children: [
                            unreadNotesBadge(
                              Icon(
                                Icons.chat_bubble_outline,
                                size: 14,
                                color: theme.colorScheme.onSurfaceVariant,
                              ),
                              t['unread_notes'],
                              key: ValueKey('task-unread-${t['id']}'),
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

  Widget _dragHandle(BuildContext context, _Json t) {
    final theme = Theme.of(context);
    return Draggable<String>(
      key: ValueKey('task-drag-${t['id']}'),
      data: t['id'] as String,
      feedback: Material(
        elevation: 4,
        borderRadius: BorderRadius.circular(8),
        color: theme.colorScheme.surface,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Text('${t['title']}'),
        ),
      ),
      child: MouseRegion(
        cursor: SystemMouseCursors.grab,
        child: Icon(
          Icons.drag_indicator,
          size: 18,
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }

  /// A row (or a group's header) a dragged task can be dropped on: it takes
  /// that row's place in the group, or the end of the group.
  Widget _dropOn(String? groupId, int? position, Widget child) {
    if (!_canEdit || groupId == null) return child;
    return DragTarget<String>(
      onWillAcceptWithDetails: (d) => true,
      onAcceptWithDetails: (d) => _moveTo(d.data, groupId, position),
      builder: (context, hovering, _) => hovering.isEmpty
          ? child
          : DecoratedBox(
              decoration: BoxDecoration(
                border: Border(
                  top: BorderSide(
                    color: Theme.of(context).colorScheme.primary,
                    width: 2,
                  ),
                ),
              ),
              child: child,
            ),
    );
  }

  /// Who made the task: a person's avatar and name, or a rule's or the
  /// system's label as the server gives it.
  Widget _creatorCell(BuildContext context, _Json t) {
    final madeBy = t['created_by'];
    if (madeBy is! Map || madeBy['label'] is! String) {
      return const SizedBox.shrink();
    }
    final label = madeBy['label'] as String;
    return Tooltip(
      key: ValueKey('task-creator-${t['id']}'),
      message: label,
      child: Row(
        children: [
          if (madeBy['type'] == 'user') ...[
            _avatar(context, label),
            const SizedBox(width: 6),
          ],
          Flexible(child: Text(label, overflow: TextOverflow.ellipsis)),
        ],
      ),
    );
  }

  /// "+2" after a task's primary owner, every name on hover.
  /// A card a rule made with nobody on it is EzBizi's: the mark and the
  /// name, with the rule's name on hover (Jose, 2026-10-22).
  Widget _automationOwner(_Json t) {
    final madeBy = t['created_by'];
    if (madeBy is! Map || madeBy['type'] != 'automation') {
      return const SizedBox.shrink();
    }
    return Tooltip(
      key: ValueKey('task-owner-ezbizi-${t['id']}'),
      message: '${madeBy['label'] ?? ''}',
      child: Row(
        children: [
          Image.asset(
            'assets/logo/app-icon-120.png',
            width: 18,
            height: 18,
            errorBuilder: (_, _, _) => const SizedBox.shrink(),
          ),
          const SizedBox(width: 6),
          const Flexible(
            child: Text('EzBizi', overflow: TextOverflow.ellipsis),
          ),
        ],
      ),
    );
  }

  Widget _moreOwners(_Json t, List<String> owners) => Tooltip(
    message: owners.join(', '),
    child: Padding(
      padding: const EdgeInsets.only(left: 4),
      child: Text(
        '+${owners.length - 1}',
        key: ValueKey('task-owners-more-${t['id']}'),
        style: Theme.of(context).textTheme.bodySmall?.copyWith(
          fontWeight: FontWeight.w700,
          color: Theme.of(context).colorScheme.onSurfaceVariant,
        ),
      ),
    ),
  );

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
    final owners = _ownerNames(t);
    final owner = owners.firstOrNull;
    final due = _dueLabel(t['due_on'], _formats);
    final created = _builtinShown(_board, 'created')
        ? _createdLabel(t['created_at'], _formats)
        : '';
    bool shown(String key) => _builtinShown(_board, key);

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
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                '${t['title']}',
                                style: theme.textTheme.bodyMedium?.copyWith(
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ),
                            if (!sub) _rowMenu(t),
                          ],
                        ),
                        const SizedBox(height: 6),
                        Wrap(
                          spacing: 8,
                          runSpacing: 4,
                          crossAxisAlignment: WrapCrossAlignment.center,
                          children: [
                            if (shown('status'))
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
                            if (owner != null && shown('owner')) ...[
                              _avatar(context, owner),
                              if (owners.length > 1) _moreOwners(t, owners),
                            ],
                            if (!sub) ...[
                              _subtaskCountChip(context, t),
                              _subtaskToggle(t),
                            ],
                            if (due.isNotEmpty && shown('due'))
                              Text(
                                due,
                                style: theme.textTheme.bodySmall?.copyWith(
                                  color: late
                                      ? theme.colorScheme.error
                                      : theme.colorScheme.onSurfaceVariant,
                                  fontWeight: late ? FontWeight.w700 : null,
                                ),
                              ),
                            if (created.isNotEmpty)
                              Text(
                                '${_builtinLabel(_board, 'created')} $created',
                                key: ValueKey('task-created-${t['id']}'),
                                style: theme.textTheme.bodySmall?.copyWith(
                                  color: theme.colorScheme.onSurfaceVariant,
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
                                case final String about when shown('about'))
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

  /// Every task the table would show, in its groups' order.
  List<_Json> get _shownTasks => [
    for (final g in _groups(_board!)) ..._tasksIn(g['id']).where(_matches),
  ];

  Color _planColour(_Json t) =>
      _statusColours(context, _statusFor(_board, _statusOf(t))).fill;

  Future<void> _patchTask(_Json task, _Json body) async {
    final res = await widget.api.patch('/tasks/${task['id']}', body);
    if (!mounted) return;
    if (res.ok) {
      _replace(res.body['data'] as _Json);
    } else {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(res.reason)));
    }
  }

  Widget _calendarView({required bool phone}) => TaskBoardCalendar(
    tasks: _shownTasks,
    canEdit: _canEdit,
    formats: _formats,
    compact: phone,
    colourOf: _planColour,
    onOpen: _openTask,
    onMoveDate: (t, day) => _patchTask(t, {'due_on': day}),
  );

  Widget _timelineView({required bool phone}) {
    final column = (_board!['columns'] as List? ?? const [])
        .whereType<Map>()
        .where((c) => c['type'] == 'timeline')
        .firstOrNull;
    return TaskBoardTimeline(
      groups: _groups(_board!),
      tasksIn: (id) => _tasksIn(id).where(_matches).toList(),
      columnId: column?['id'] as String?,
      canEdit: _canEdit,
      compact: phone,
      colourOf: _planColour,
      onOpen: _openTask,
      onSetRange: (t, from, to) => _patchTask(t, {
        'values': {
          column!['id']: {'from': from, 'to': to},
        },
      }),
      onAddColumn: () async {
        final res = await widget.api.post(
          '/task-boards/${_board!['id']}/columns',
          {'name': 'Timeline', 'type': 'timeline'},
        );
        if (!mounted) return;
        res.ok
            ? await _load()
            : ScaffoldMessenger.of(
                context,
              ).showSnackBar(SnackBar(content: Text(res.reason)));
      },
    );
  }

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
                Flexible(
                  child: Text(
                    '${status.name} · ${tasks.length}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleSmall?.copyWith(
                      fontWeight: FontWeight.w800,
                    ),
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
    this.unreadNotes = 0,
  });

  final ApiClient api;
  final DisplayFormats formats;
  final String taskId;

  /// Updates by others it has not read; opening it reads them.
  final int unreadNotes;
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
  String? _postError;

  /// Files chosen for the next update, not sent yet.
  final _files = <({String name, List<int> bytes})>[];

  final _title = TextEditingController();
  final _due = TextEditingController();
  final _update = TextEditingController();
  final _mentions = MentionController();

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
      // Its updates are read now (unread notes, monday port 2026-11-09).
      if (widget.unreadNotes > 0) {
        widget.api.post('/note-reads', {'type': 'task', 'id': widget.taskId});
      }
      _due.text = _task!['due_on'] as String? ?? '';
    });
  }

  /// The task's Activity log, newest first; null until asked for.
  List<_Json>? _activity;

  Future<void> _loadActivity() async {
    final r = await widget.api.get('/tasks/${widget.taskId}/activity');
    if (!mounted) return;
    setState(
      () => _activity = r.ok && r.data is List
          ? (r.data as List).whereType<_Json>().toList()
          : const [],
    );
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
    // An open Activity log shows the change just made.
    if (res.ok && _activity != null) await _loadActivity();
  }

  Future<void> _attach() async {
    final picked = await pickTaskUpdateFile();
    if (picked == null || !mounted) return;
    setState(() {
      _postError = taskUpdateFileRefusal(
        picked.name,
        picked.bytes.length,
        _files.length,
      );
      if (_postError == null) {
        _files.add((name: picked.name, bytes: picked.bytes));
      }
    });
  }

  /// JSON with words only, as before; multipart once a file is attached,
  /// `files[]` and the body only if there is one.
  Future<void> _post() async {
    final body = _update.text.trim();
    if (body.isEmpty && _files.isEmpty) return;
    setState(() {
      _posting = true;
      _postError = null;
    });
    final path = '/tasks/${widget.taskId}/updates';
    final mentions = _mentions.ids(body);
    final res = _files.isEmpty
        ? await widget.api.post(path, {
            'body': body,
            if (mentions.isNotEmpty) 'mentions': mentions,
          })
        : await widget.api.postFiles(
            path,
            'files',
            List.of(_files),
            fields: {
              if (body.isNotEmpty) 'body': body,
              for (final (i, m) in mentions.indexed) 'mentions[$i]': m,
            },
          );
    if (!mounted) return;
    setState(() {
      _posting = false;
      if (!res.ok) {
        _postError = res.reason;
        return;
      }
      _update.clear();
      _mentions.clear();
      _files.clear();
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

  /// The owners after the primary, each removable, and a picker to add
  /// another: every owner sent, primary first (`assignee_user_ids`).
  Widget _moreOwnersEditor(_Json task, Map<String, String> people) {
    final names = {
      for (final o in task['owners'] as List)
        if (o is Map && o['id'] is String) o['id'] as String: '${o['name']}',
    };
    final primary = (task['owner'] as Map)['id'] as String;
    final ids = [primary, ...names.keys.where((id) => id != primary)];
    final others = ids.skip(1).toList();
    if (others.isEmpty && !_canEdit) return const SizedBox.shrink();
    return Wrap(
      spacing: 6,
      runSpacing: 6,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        for (final id in others)
          InputChip(
            key: ValueKey('task-panel-owner-$id'),
            label: Text(names[id] ?? people[id] ?? 'Someone'),
            onDeleted: _canEdit
                ? () => _patch({
                    'assignee_user_ids': [
                      for (final x in ids)
                        if (x != id) x,
                    ],
                  })
                : null,
          ),
        if (_canEdit)
          SizedBox(
            width: 200,
            child: SearchPickerField<String>(
              key: const Key('task-panel-owner-add'),
              value: null,
              isDense: true,
              hint: 'Add an owner',
              options: [
                for (final p in people.entries)
                  if (!ids.contains(p.key))
                    SearchOption(value: p.key, label: p.value),
              ],
              onChanged: (id) {
                if (id != null) {
                  _patch({
                    'assignee_user_ids': [...ids, id],
                  });
                }
              },
            ),
          ),
      ],
    );
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
              // Red, the colour of what it does (phone audit 2026-11-04).
              style: TextButton.styleFrom(
                foregroundColor: Theme.of(context).colorScheme.error,
              ),
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
          _builtinLabel(widget.board, 'status'),
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
                            _patch(_statusBody(widget.board, s.id, task));
                          }
                        }
                      : null,
                ),
            ],
          ),
        ),
        fact(
          _builtinLabel(widget.board, 'owner'),
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
        if (owner != null && task['owners'] is List)
          fact('', _moreOwnersEditor(task, people)),
        fact(
          _builtinLabel(widget.board, 'due'),
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
          _builtinLabel(widget.board, 'about'),
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
            (widget.board['builtin_columns'] as Map?)?['creator'] is Map
                ? _builtinLabel(widget.board, 'creator')
                : 'Made by',
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
              api: widget.api,
              onChanged: _canEdit
                  ? (v) => _patch({
                      'values': {c.id: v},
                    })
                  : null,
            ),
          ),
        // Activity log (Jose, 2026-11-09, monday's): loaded when opened.
        ExpansionTile(
          key: const Key('task-activity'),
          tilePadding: EdgeInsets.zero,
          childrenPadding: EdgeInsets.zero,
          title: Text(
            'Activity',
            style: theme.textTheme.titleSmall?.copyWith(
              fontWeight: FontWeight.w700,
            ),
          ),
          onExpansionChanged: (open) {
            if (open && _activity == null) _loadActivity();
          },
          children: [
            if (_activity == null)
              const LinearProgressIndicator()
            else if (_activity!.isEmpty)
              Align(
                alignment: Alignment.centerLeft,
                child: Text('No changes yet.', style: muted),
              )
            else
              for (final a in _activity!)
                Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Text.rich(
                    TextSpan(
                      children: [
                        TextSpan(
                          text: '${a['who'] ?? 'An automation'}  ',
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                        TextSpan(
                          text:
                              '${a['label']}: ${a['from'] ?? '—'} → ${a['to'] ?? '—'}  ',
                        ),
                        TextSpan(
                          text: formatStamp(
                            a['at'],
                            '',
                            formats: widget.formats,
                          ),
                          style: muted,
                        ),
                      ],
                    ),
                  ),
                ),
          ],
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
                      if ('${u['body'] ?? ''}'.isNotEmpty) Text('${u['body']}'),
                      TaskAttachments(
                        api: widget.api,
                        attachments: [
                          for (final a
                              in (u['attachments'] as List? ?? const []))
                            if (a is Map) Map<String, dynamic>.from(a),
                        ],
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        if (_canEdit) ...[
          const SizedBox(height: 4),
          MentionField(
            api: widget.api,
            type: 'task',
            id: widget.taskId,
            controller: _update,
            mentions: _mentions,
            child: TextField(
              key: const Key('task-update-body'),
              controller: _update,
              minLines: 2,
              maxLines: 6,
              decoration: const InputDecoration(
                hintText: 'Write an update… (@ to mention)',
                border: OutlineInputBorder(),
              ),
            ),
          ),
          if (_files.isNotEmpty) ...[
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final (i, f) in _files.indexed)
                  TaskFileChip(
                    key: ValueKey('task-update-file-$i'),
                    name: f.name,
                    size: f.bytes.length,
                    onDeleted: _posting
                        ? null
                        : () => setState(() => _files.removeAt(i)),
                  ),
              ],
            ),
          ],
          if (_postError != null) ...[
            const SizedBox(height: 6),
            Text(
              _postError!,
              key: const Key('task-update-error'),
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ],
          const SizedBox(height: 8),
          Wrap(
            alignment: WrapAlignment.spaceBetween,
            crossAxisAlignment: WrapCrossAlignment.center,
            spacing: 8,
            runSpacing: 6,
            children: [
              TextButton.icon(
                key: const Key('task-update-attach'),
                onPressed: _posting || _files.length >= taskUpdateMaxFiles
                    ? null
                    : _attach,
                icon: const Icon(Icons.attach_file, size: 18),
                label: const Text('Attach'),
              ),
              FilledButton(
                key: const Key('task-update-post'),
                onPressed: _posting ? null : _post,
                child: Text(_posting ? 'Posting…' : 'Post update'),
              ),
            ],
          ),
        ],
      ],
    );
  }
}
