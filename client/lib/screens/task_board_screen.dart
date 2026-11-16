import 'dart:convert';
import 'dart:async';
import '../widgets/list_search.dart';
import 'dart:math' as math;

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api_client.dart';
import '../widgets/user_avatar.dart';
import '../core/device_key_store_io.dart'
    if (dart.library.js_interop) '../core/device_key_store_web.dart'
    as store;
import '../core/deep_links.dart';
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
import 'task_board_live.dart';
import '../widgets/list_filters.dart';
import '../widgets/list_toolbar.dart';
import '../widgets/mention_field.dart';
import 'task_board_move.dart';
import 'task_board_people.dart';
import 'task_board_person_card.dart';
import 'task_board_plan_views.dart';
import 'task_board_sidebar.dart';
import 'task_board_sensitive.dart';

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

/// The person's photo when [api] and [userId] are known (Jose, 2026-11-13:
/// "the pictures do not display"), else their initials.
Widget _avatar(
  BuildContext context,
  String name, {
  ApiClient? api,
  Object? userId,
}) {
  if (api != null && userId != null) {
    return UserAvatar(api: api, userId: '$userId', name: name, radius: 11);
  }
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

/// Parsed once per statuses list: every row asks for them (freeze 11-16).
final _statusMemo = Expando<List<_Status>>('statuses');

List<_Status> _statuses(_Json? board) {
  final raw = board?['statuses'];
  if (raw is! List) return const [];
  return _statusMemo[raw] ??= _parseStatuses(raw);
}

List<_Status> _parseStatuses(List raw) => [
  for (final s in raw.whereType<Map>())
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

/// What each built-in column is for, at the top of its heading's menu.
const _builtinHelp = {
  'creator': 'Built in: who made the task.',
  'owner': 'Built in: who the task is assigned to.',
  'status': 'Built in: where the task stands. Its labels are edited here.',
  'created': 'Built in: the day the task was made.',
  'due': 'Built in: the day it is due. Late tasks show in red.',
  'about':
      'Built in: the customer, job or lead the task is about. Set it in the task.',
  'updates': 'Built in: how many notes and replies the task has.',
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

/// The first owner's user id, matching the first of [_ownerNames].
Object? _ownerId(_Json task) {
  final owners = task['owners'] as List? ?? const [];
  if (owners.isNotEmpty) return (owners.first as Map?)?['id'];
  return (task['owner'] as Map?)?['id'];
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

class _TaskBoardScreenState extends State<TaskBoardScreen>
    with WidgetsBindingObserver {
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
    DeepLinks.pending.addListener(_takeLink);
    WidgetsBinding.instance.addObserver(this);
    _live = Timer.periodic(liveEvery, (_) => _catchUp());
    _load();
  }

  // ── Live: the open board follows changes made elsewhere ──────────

  Timer? _live;

  /// Where the board's changes feed picks up; null until a board has come.
  String? _cursor;
  bool _catching = false;
  bool _inFront = true;

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _inFront = state == AppLifecycleState.resumed;
    if (_inFront) _catchUp();
  }

  /// One read of the board's changes, folded into the board in place.
  Future<void> _catchUp() async {
    final board = _board;
    final since = _cursor;
    if (_catching || !mounted || !_inFront || board == null || since == null) {
      return;
    }
    // A tab out of view (an Offstage shell) has its tickers off: no reads.
    if (!TickerMode.valuesOf(context).enabled) return;
    _catching = true;
    try {
      final res = await widget.api.get('/task-boards/${board['id']}/changes', {
        'since': since,
      });
      if (!mounted || !res.ok || _board?['id'] != board['id']) return;
      if (_cursor != since) return; // the board was opened again meanwhile
      final data = res.body['data'];
      if (data is! _Json) return;
      if (data['reload'] == true) {
        await _open(_selected);
        return;
      }
      final merged = mergeBoardChanges(
        _board!,
        data,
        since,
        adopt: _adopt,
        hold: InlineCellEditor.editing.value > 0,
      );
      if (!merged.held) _cursor = data['cursor'] as String? ?? since;
      if (merged.board != null) setState(() => _board = merged.board);
    } finally {
      _catching = false;
    }
  }

  @override
  void dispose() {
    _live?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    DeepLinks.pending.removeListener(_takeLink);
    _rangeFocus.dispose();
    _tableScroll.dispose();
    _columnSearch.dispose();
    HardwareKeyboard.instance.removeHandler(_dragKey);
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
    await _takeLink();
  }

  /// /tasks/{id} (the panel's Copy link): its board, then the task.
  Future<void> _takeLink() async {
    if (!mounted || DeepLinks.pending.value?.module != 'tasks') return;
    if (_boards.isEmpty) return; // _load takes it once the boards are here.
    final link = DeepLinks.take('tasks')!;
    final res = await widget.api.get('/tasks/${link.id}');
    if (!mounted || !res.ok) return;
    final boardId = (res.body['data'] as Map?)?['board_id'];
    final at = _boards.indexWhere((b) => b['id'] == boardId);
    if (at >= 0 && _board?['id'] != boardId) await _open(at);
    if (mounted) _openTaskById(link.id);
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
    // 50 per group: a 24,713-task board was a 20 MB reply (11-16).
    final res = await widget.api.get('/task-boards/$id', {
      'per_group': '$_perGroup',
    });
    if (!mounted || _boards[_selected]['id'] != id) return;
    setState(() {
      _loading = false;
      if (!res.ok) {
        _error = res.reason;
        return;
      }
      final first = _board == null;
      _board = _adoptAll(res.body['data'] as _Json);
      _cursor = _board!['cursor'] as String?;
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

  static const _perGroup = 50;

  /// The server's count, page and footers for group [id] (null: no group),
  /// or null when the board came whole.
  Map? _totalsOf(Object? id) =>
      (_board?['group_totals'] as Map?)?[id == null ? '' : '$id'] as Map?;

  /// Show more: the group's next page, added to the board.
  Future<void> _showMore(String? groupId) async {
    final board = _board;
    final at = _totalsOf(groupId);
    if (board == null || at == null) return;
    final res = await widget.api.get(
      '/task-boards/${board['id']}/group-tasks',
      {
        'group_id': groupId ?? '',
        'offset': '${at['shown']}',
        'limit': '$_perGroup',
      },
    );
    if (!mounted || !identical(_board, board)) return;
    if (!res.ok) return _say(res.reason);
    final have = {for (final t in _tasks) t['id']};
    setState(() {
      _board = {
        ...board,
        'tasks': [
          ..._tasks,
          for (final t in (res.body['data'] as List).cast<_Json>())
            if (!have.contains(t['id'])) _adopt(board, t),
        ],
        'group_totals': {
          ...(board['group_totals'] as Map),
          groupId ?? '': res.body['meta'],
        },
      };
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

  // ── Group by (Monday parity, Jose 11-13) ─────────────────────────

  /// The field the table is grouped by instead of the board's groups; null
  /// shows the board's own groups.
  String? _groupBy;

  /// One value of [_groupBy] for [t]: its first id, or its text or day.
  String _groupValue(_Json t) => _valueOf(_groupBy, t);

  /// One value of field [key] for [t], as Group by and Filter… read it.
  String _valueOf(String? key, _Json t) {
    final f = _filterFields[key];
    if (f == null) return '';
    final v = f.read(t);
    if (v is List) return v.isEmpty ? '' : '${v.first}';
    if (v is bool) return v ? 'true' : '';
    if (v == null) return '';
    final text = '$v'.trim();
    return f.kind == 'date' && text.length > 10 ? text.substring(0, 10) : text;
  }

  /// The groups the table draws: the board's own, or one per value of
  /// [_groupBy] (ids `by:<value>`, which nothing can write to).
  List<_Json> _shownGroups() {
    final f = _filterFields[_groupBy];
    if (f == null) return _groups(_board!);
    final names = {for (final (id, label) in f.options) id: label};
    final values = <String>{
      for (final t in _tasks)
        if (t['parent_task_id'] == null) _groupValue(t),
    };
    final ordered = [
      for (final (id, _) in f.options)
        if (values.contains(id)) id,
      ...(values.where((v) => v.isNotEmpty && !names.containsKey(v)).toList()
        ..sort()),
    ];
    return [
      for (final v in ordered)
        {
          'id': 'by:$v',
          'name': f.kind == 'checkbox' ? 'Ticked' : (names[v] ?? v),
          'colour': null,
          'virtual': true,
        },
      if (values.contains(''))
        {'id': 'by:', 'name': 'No ${f.label}', 'colour': null, 'virtual': true},
    ];
  }

  /// A group's tasks, worked out once per board, filter and Group by: the
  /// table asks on every build (freeze 11-16). Callers copy before editing.
  List<_Json> _tasksIn(Object? groupId) {
    if (!identical(_inFor.$1, _board) ||
        !identical(_inFor.$2, _filter) ||
        _inFor.$3 != _groupBy) {
      _inFor = (_board, _filter, _groupBy);
      _inMemo.clear();
    }
    return _inMemo[groupId] ??= List.unmodifiable(_tasksInNow(groupId));
  }

  (Object?, Object?, String?) _inFor = (null, null, null);
  final _inMemo = <Object?, List<_Json>>{};

  List<_Json> _tasksInNow(Object? groupId) {
    if (groupId is String && groupId.startsWith('by:')) {
      final v = groupId.substring(3);
      return _filter.sorted([
        for (final t in _tasks)
          if (t['parent_task_id'] == null && _groupValue(t) == v) t,
      ], _filterFields);
    }
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
      'config': _filter.copyWith(layout: _view).toJson(),
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
    // A saved view brings its layout (Monday's Kanban / Calendar views).
    onChanged: (q) => setState(() {
      if (q.layout != null && q.layout != _filter.layout) _view = q.layout!;
      _filter = q;
    }),
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
  /// A picked row carries every picked row with it, in board order.
  Future<void> _moveTo(String id, Object? groupId, int? position) async {
    if (groupId == null) return;
    final ids = _picked.contains(id) && _picked.length > 1
        ? _inBoardOrder(_picked)
        : [id];
    String? failed;
    for (final (n, one) in ids.indexed) {
      final res = await widget.api.patch('/tasks/$one/move', {
        'group_id': groupId,
        if (position != null) 'position': position + n,
      });
      if (!res.ok) failed ??= res.reason;
    }
    if (!mounted) return;
    if (ids.length > 1) setState(_picked.clear);
    if (failed != null) _say(failed);
    await _load();
  }

  /// A task dropped on another board in the tree: the end of its first
  /// group, with its subtasks, updates, files and activity (they hang off
  /// the task, so the server's move takes them along).
  Future<void> _dropOnBoard(int index, String id) async {
    final res = await widget.api.get('/task-boards/${_boards[index]['id']}', {
      'per_group': '1',
    });
    if (!mounted) return;
    if (!res.ok) return _say(res.reason);
    final groups = ((res.body['data'] as Map?)?['groups'] as List?) ?? const [];
    if (groups.isEmpty) return _say('That board has no group to put it in.');
    await _moveTo(id, (groups.first as Map)['id'], null);
    if (mounted) _say('Moved to ${_boards[index]['name'] ?? 'that board'}');
  }

  /// [ids] in the order the board lists them, group by group.
  List<String> _inBoardOrder(Iterable<String> ids) {
    final want = ids.toSet();
    return [
      for (final g in _groups(_board!))
        for (final t in _tasksIn(g['id']).where(_matches))
          if (want.contains(t['id'])) t['id'] as String,
    ];
  }

  /// Shift-click picks every row between the last pick and this one.
  String? _lastPick;

  void _pick(String id) {
    final shift = HardwareKeyboard.instance.isShiftPressed;
    setState(() {
      final last = _lastPick;
      if (shift && last != null && last != id) {
        final order = _inBoardOrder({
          for (final t in _tasks)
            if (t['parent_task_id'] == null) t['id'] as String,
        });
        final a = order.indexOf(last), b = order.indexOf(id);
        if (a >= 0 && b >= 0) {
          _picked.addAll(order.sublist(a < b ? a : b, (a < b ? b : a) + 1));
          _lastPick = id;
          return;
        }
      }
      _picked.remove(id) ? null : _picked.add(id);
      _lastPick = id;
    });
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
      case 'sensitive':
        await _toggleSensitive(t);
    }
  }

  /// Mark: anyone who can edit it. Unmark opens everything, so the server
  /// wants a fresh authenticator code first (Jose, 2026-11-12).
  Future<void> _toggleSensitive(_Json t) async {
    final id = t['id'] as String;
    final on = !taskIsSensitive(t);
    if (!on) {
      final open = await unlockSensitiveTask(
        context,
        api: widget.api,
        taskId: id,
      );
      if (open == null || !mounted) return;
    }
    final res = await widget.api.patch('/tasks/$id', {'sensitive': on});
    if (!mounted) return;
    if (!res.ok) return _say(res.reason);
    _say(on ? 'Marked sensitive' : 'No longer sensitive');
    await _load();
  }

  /// Right-click on a row: Monday's fast actions at the pointer. On a picked
  /// row with others picked, the bulk bar's actions, for them all.
  Future<void> _rowRightClick(_Json t, Offset at) async {
    final many = _picked.contains(t['id']) && _picked.length > 1;
    final box = Overlay.of(context).context.findRenderObject() as RenderBox;
    final where = RelativeRect.fromRect(
      at & const Size(1, 1),
      Offset.zero & box.size,
    );
    PopupMenuItem<String> item(String value, String label, IconData icon) =>
        PopupMenuItem(
          key: ValueKey('task-rc-$value'),
          value: value,
          height: 36,
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 18),
              const SizedBox(width: 10),
              Flexible(child: Text(label, softWrap: false)),
            ],
          ),
        );
    final action = await showMenu<String>(
      context: context,
      position: where,
      items: many
          ? [
              PopupMenuItem(
                enabled: false,
                height: 30,
                child: Text('${_picked.length} tasks selected'),
              ),
              if (_canEdit) ...[
                item('move', 'Move to…', Icons.drive_file_move_outline),
                item('duplicate', 'Duplicate', Icons.copy_all_outlined),
                item('status', 'Set status…', Icons.flag_outlined),
                item('assign', 'Assign…', Icons.person_add_alt),
                item('delete', 'Delete', Icons.delete_outline),
              ],
              item('clear', 'Clear selection', Icons.close),
            ]
          : [
              item('open', 'Open task', Icons.open_in_new),
              item('copy-name', 'Copy name', Icons.content_copy),
              item('copy-link', 'Copy task link', Icons.link),
              if (_canEdit) ...[
                const PopupMenuDivider(),
                item('move', 'Move to…', Icons.drive_file_move_outline),
                item('duplicate', 'Duplicate', Icons.copy_all_outlined),
                item('below', 'Create new task below', Icons.add),
                item('subtask', 'Add subtask', Icons.subdirectory_arrow_right),
                const PopupMenuDivider(),
                item('delete', 'Delete', Icons.delete_outline),
              ],
            ],
    );
    if (action == null || !mounted) return;
    if (many) {
      if (action == 'clear') return setState(_picked.clear);
      return _bulk(action);
    }
    final id = t['id'] as String;
    switch (action) {
      case 'copy-name':
        await Clipboard.setData(ClipboardData(text: taskTitle(t)));
        if (mounted) _say('Name copied');
      case 'copy-link':
        await Clipboard.setData(
          ClipboardData(text: '${Uri.base.origin}/tasks/$id'),
        );
        if (mounted) _say('Link copied');
      case 'below':
      case 'subtask':
        final res = await widget.api.post('/tasks', {
          'board_id': _board!['id'],
          'title': action == 'subtask' ? 'New subtask' : 'New task',
          'group_id': ?t['group_id'],
          if (action == 'subtask') 'parent_task_id': id,
        });
        if (!mounted) return;
        if (!res.ok) return _say(res.reason);
        final made = res.body['data'] as _Json;
        if (action == 'below') {
          final order = _tasksIn(t['group_id']).where(_matches).toList();
          final at = order.indexWhere((x) => x['id'] == id);
          await widget.api.patch('/tasks/${made['id']}/move', {
            'group_id': t['group_id'],
            'position': at + 1,
          });
          if (!mounted) return;
        }
        await _load();
        if (mounted) await _openTask(made);
      default:
        await _rowAction(t, action);
    }
  }

  /// A section title inside a menu.
  Widget _menuHeading(BuildContext context, String text) => Padding(
    padding: const EdgeInsets.fromLTRB(12, 10, 12, 4),
    child: Text(
      text.toUpperCase(),
      style: Theme.of(context).textTheme.labelSmall?.copyWith(
        color: Theme.of(context).colorScheme.onSurfaceVariant,
        fontWeight: FontWeight.w700,
        letterSpacing: 0.6,
      ),
    ),
  );

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
      if (_canEdit)
        PopupMenuItem(
          value: 'sensitive',
          child: Text(
            taskIsSensitive(t) ? 'Unmark sensitive' : 'Mark sensitive',
          ),
        ),
      if (_canEdit) const PopupMenuItem(value: 'delete', child: Text('Delete')),
    ],
  );

  Future<void> _bulk(String op) async {
    final ids = _picked.toList();
    Map<String, Object?>? extra;
    switch (op) {
      case 'delete':
        return _delete(ids);
      case 'duplicate':
        for (final id in ids) {
          await widget.api.post('/tasks/$id/copy', const {});
        }
        if (!mounted) return;
        setState(_picked.clear);
        return _load();
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
            ('duplicate', 'Duplicate'),
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
  List<_Json> _subtasksOf(_Json t) {
    final raw = _board?['tasks'];
    if (raw is! List) return const [];
    // Indexed once per task list: a scan per row was rows × tasks per build.
    final byParent = _childMemo[raw] ??= () {
      final m = <Object, List<_Json>>{};
      for (final x in raw.cast<_Json>()) {
        final p = x['parent_task_id'];
        if (p != null) (m[p] ??= []).add(x);
      }
      return m;
    }();
    return byParent[t['id']] ?? const [];
  }

  static final _childMemo = Expando<Map<Object, List<_Json>>>('subtasks');

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
            'person' || 'people' => 'people',
            'checkbox' => 'checkbox',
            _ => 'text',
          },
          options: c.type == 'person' || c.type == 'people'
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
  /// The first owner's face, with Monday's hover card on it.
  Widget _ownerFace(BuildContext context, _Json t, String owner) {
    final id = _ownerIds(t).firstOrNull;
    final face = _avatar(context, owner, api: widget.api, userId: _ownerId(t));
    if (id == null || _board == null) return face;
    return PersonHoverCard(
      api: widget.api,
      boardId: '${_board!['id']}',
      userId: id,
      child: face,
    );
  }

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

  // ── Header sort (Monday parity, Jose 11-13) ─────────────────────

  /// The direction [field] is sorted in: true descending, false ascending,
  /// null not sorted.
  bool? _sortDir(String field) {
    for (final r in _filter.sort) {
      if (r.field == field) return r.desc;
    }
    return null;
  }

  /// Sorts by [field] alone; null clears the sort.
  void _sortBy(String field, bool? desc) => setState(
    () => _filter = _filter.copyWith(
      sort: desc == null ? const [] : [(field: field, desc: desc)],
    ),
  );

  Widget _sortable(String field, Widget label) {
    if (!_filterFields.containsKey(field)) return label;
    final dir = _sortDir(field);
    // The arrow sits beside the heading, outside the tap that opens its menu.
    return Row(
      children: [
        Expanded(child: label),
        SortArrow(
          key: ValueKey('sort-$field'),
          dir: dir,
          // Ascending, descending, off.
          onTap: () => _sortBy(field, switch (dir) {
            null => false,
            false => true,
            true => null,
          }),
        ),
      ],
    );
  }

  /// The values [field] holds on this board, with their labels, for the
  /// header's Filter… menu.
  List<(String, String)> _valuesIn(String field) {
    final f = _filterFields[field];
    if (f == null) return const [];
    final names = {for (final (id, label) in f.options) id: label};
    final seen = <String>{
      for (final t in _tasks)
        if (t['parent_task_id'] == null) _valueOf(field, t),
    }..remove('');
    return [
      for (final (id, label) in f.options)
        if (seen.contains(id)) (id, label),
      for (final v
          in (seen.where((v) => !names.containsKey(v)).toList()..sort()))
        (v, f.kind == 'checkbox' ? 'Ticked' : v),
    ].take(30).toList();
  }

  /// The rule on [field] made from the header or a cell, in place of any
  /// earlier one on it.
  void _filterTo(String field, String value) => setState(
    () => _filter = _filter.copyWith(
      filters: [
        for (final r in _filter.filters)
          if (r.field != field) r,
        (
          field: field,
          op: 'is',
          value: switch (_filterFields[field]?.kind) {
            'options' || 'people' => [value],
            'checkbox' => value == 'true',
            _ => value,
          },
        ),
      ],
    ),
  );

  bool _filteredOn(String field) =>
      _filter.filters.any((r) => r.field == field);

  void _clearFilterOn(String field) => setState(
    () => _filter = _filter.copyWith(
      filters: [
        for (final r in _filter.filters)
          if (r.field != field) r,
      ],
    ),
  );

  List<Widget> _sortItems(String field) => [
    if (_filterFields.containsKey(field)) ...[
      SubmenuButton(
        key: ValueKey('header-filter-$field'),
        leadingIcon: const Icon(Icons.filter_list, size: 18),
        menuChildren: [
          for (final (v, label) in _valuesIn(field))
            MenuItemButton(
              key: ValueKey('header-filter-$field-$v'),
              onPressed: () => _filterTo(field, v),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 240),
                child: Text(label, overflow: TextOverflow.ellipsis),
              ),
            ),
          if (_valuesIn(field).isEmpty)
            const MenuItemButton(onPressed: null, child: Text('No values yet')),
          if (_filteredOn(field)) ...[
            const Divider(height: 8),
            MenuItemButton(
              key: ValueKey('header-filter-clear-$field'),
              onPressed: () => _clearFilterOn(field),
              child: const Text('Clear filter'),
            ),
          ],
        ],
        child: const Text('Filter…'),
      ),
      MenuItemButton(
        key: ValueKey('sort-asc-$field'),
        leadingIcon: const Icon(Icons.arrow_upward, size: 18),
        onPressed: () => _sortBy(field, false),
        child: const Text('Sort ascending'),
      ),
      MenuItemButton(
        key: ValueKey('sort-desc-$field'),
        leadingIcon: const Icon(Icons.arrow_downward, size: 18),
        onPressed: () => _sortBy(field, true),
        child: const Text('Sort descending'),
      ),
      if (_sortDir(field) != null)
        MenuItemButton(
          onPressed: () => _sortBy(field, null),
          child: const Text('Clear sort'),
        ),
      const Divider(height: 8),
    ],
  ];

  /// A built-in column's heading: its name, and on a board you may edit a
  /// menu to rename or hide it (Jose, 2026-10-20).
  Widget _builtinHead(String key, Object? group, Widget label) {
    if (!_canEdit) return _sortable(key, label);
    return _sortable(
      key,
      MenuAnchor(
        menuChildren: [
          ..._sortItems(key),
          // What a built-in column is for (Jose, 2026-11-12: "not sure where
          // those are managed").
          MenuItemButton(
            key: ValueKey('builtin-about-$key'),
            onPressed: null,
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 240),
              child: Text(_builtinHelp[key]!, softWrap: true),
            ),
          ),
          const Divider(height: 8),
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
          if (key == 'status')
            MenuItemButton(
              key: const Key('builtin-status-labels'),
              leadingIcon: const Icon(Icons.label_outline, size: 18),
              onPressed: _editStatuses,
              child: const Text('Edit labels…'),
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
    final hiddenOwn = [
      for (final c
          in (_board?['columns'] as List? ?? const []).whereType<Map>())
        if (c['hidden'] == true) c,
    ];
    final count = hidden.length + hiddenOwn.length;
    return MenuAnchor(
      menuChildren: [
        for (final key in hidden)
          MenuItemButton(
            key: ValueKey('builtin-show-$key'),
            leadingIcon: const Icon(Icons.visibility_outlined, size: 18),
            onPressed: () => _showBuiltin(key, true),
            child: Text('Show ${_builtinLabel(_board, key)}'),
          ),
        for (final c in hiddenOwn)
          MenuItemButton(
            key: ValueKey('column-show-${c['id']}'),
            leadingIcon: const Icon(Icons.visibility_outlined, size: 18),
            onPressed: () async {
              final r = await widget.api.patch(
                columnsPath('${_board!['id']}', '${c['id']}'),
                {'hidden': false},
              );
              if (!mounted) return;
              if (!r.ok) {
                ScaffoldMessenger.of(
                  context,
                ).showSnackBar(SnackBar(content: Text(r.reason)));
              }
              await _load();
            },
            child: Text('Show ${c['name'] ?? 'column'}'),
          ),
        if (count == 0)
          const MenuItemButton(
            onPressed: null,
            child: Text('Every column is showing'),
          ),
      ],
      builder: (context, menu, _) => OutlinedButton.icon(
        key: const Key('tasks-columns'),
        onPressed: () => menu.isOpen ? menu.close() : menu.open(),
        icon: const Icon(Icons.view_column_outlined, size: 18),
        label: Text(count == 0 ? 'Columns' : 'Columns · $count hidden'),
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

    return _sortable(
      c.id,
      MenuAnchor(
        menuChildren: [
          ..._sortItems(c.id),
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
            key: ValueKey('column-menu-remove-${c.id}'),
            leadingIcon: const Icon(Icons.delete_outline, size: 18),
            onPressed: () => _editColumn(c),
            child: const Text('Remove column…'),
          ),
        ],
        builder: (context, menu, _) => InkWell(
          key: ValueKey('column-head-${c.id}'),
          onTap: _canEdit
              ? () => menu.isOpen ? menu.close() : menu.open()
              : null,
          child: Align(
            alignment: Alignment.centerLeft,
            child: Tooltip(message: c.name, child: title),
          ),
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
        subtasks: _subtasksOf(task),
      ),
    );
    if (mounted) await _load();
  }

  @override
  Widget build(BuildContext context) {
    // A status menu is open: dim the board so the menu stands apart
    // (Jose, 2026-11-12). The menu itself sits in the overlay, above this.
    return Stack(
      children: [
        _screen(context),
        if (_statusMenuOpen)
          Positioned.fill(
            child: IgnorePointer(
              child: ColoredBox(
                key: const Key('tasks-status-scrim'),
                color: Theme.of(
                  context,
                ).colorScheme.scrim.withValues(alpha: 0.18),
              ),
            ),
          ),
      ],
    );
  }

  bool _statusMenuOpen = false;
  void _statusMenu(bool open) {
    // Later, not now: a menu closes while its cell is rebuilt or disposed.
    Future.microtask(() {
      if (mounted && open != _statusMenuOpen) {
        setState(() => _statusMenuOpen = open);
      }
    });
  }

  Widget _screen(BuildContext context) {
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
    onDropTask: _canEdit ? _dropOnBoard : null,
    api: widget.api,
    canArrange: widget.session.me?.can('tasks.create_boards') ?? false,
    onArranged: _load,
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

  static const _viewChoices = [
    ('table', 'Table', Icons.table_rows_outlined),
    ('kanban', 'Kanban', Icons.view_kanban_outlined),
    ('calendar', 'Calendar', Icons.calendar_month_outlined),
    ('timeline', 'Timeline', Icons.view_timeline_outlined),
    ('gallery', 'Gallery', Icons.photo_library_outlined),
  ];

  Widget _toolbar(BuildContext context, {required bool phone}) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 2),
      child: Wrap(
        spacing: 10,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          if (phone)
            // One dropdown on a phone: the segments broke "Kanb/an" and
            // "Calen/dar" mid-word (phone review 2026-11-13, item 7).
            SizedBox(
              width: 170,
              child: SearchPickerField<String>(
                key: const Key('tasks-view-picker'),
                value: _view,
                label: 'View',
                isDense: true,
                searchFrom: 99,
                options: [
                  for (final (v, label, icon) in _viewChoices)
                    SearchOption<String>(
                      value: v,
                      label: label,
                      child: Row(
                        children: [
                          Icon(icon, size: 18),
                          const SizedBox(width: 10),
                          Text(label),
                        ],
                      ),
                    ),
                ],
                onChanged: (v) {
                  if (v == null) return;
                  setState(() {
                    _view = v;
                    _filter = _filter.copyWith(layout: v);
                  });
                },
              ),
            )
          else
            SegmentedButton<String>(
              key: const Key('tasks-view'),
              showSelectedIcon: false,
              segments: [
                for (final (v, label, icon) in _viewChoices)
                  ButtonSegment(
                    value: v,
                    label: Text(label),
                    icon: Icon(icon, size: 18),
                  ),
              ],
              selected: {_view},
              onSelectionChanged: (v) => setState(() {
                _view = v.first;
                _filter = _filter.copyWith(layout: v.first);
              }),
            ),
          if (_canEdit && _view == 'table') _newTaskButton(),
          if (_view == 'table' && _board != null) _groupByButton(),
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

  /// Monday's "Group by": the board's groups, or one group per value of a
  /// column.
  Widget _groupByButton() {
    final fields = _filterFields.values.where((f) => f.key != 'title').toList();
    final on = _filterFields[_groupBy];
    return MenuAnchor(
      menuChildren: [
        MenuItemButton(
          key: const Key('group-by-none'),
          leadingIcon: Icon(on == null ? Icons.check : null, size: 18),
          onPressed: () => setState(() => _groupBy = null),
          child: const Text('Board groups'),
        ),
        const Divider(height: 8),
        for (final f in fields)
          MenuItemButton(
            key: ValueKey('group-by-${f.key}'),
            leadingIcon: Icon(_groupBy == f.key ? Icons.check : null, size: 18),
            onPressed: () => setState(() => _groupBy = f.key),
            child: Text(f.label),
          ),
      ],
      builder: (context, menu, _) => OutlinedButton.icon(
        key: const Key('tasks-group-by'),
        onPressed: () => menu.isOpen ? menu.close() : menu.open(),
        icon: const Icon(Icons.view_agenda_outlined, size: 18),
        label: Text(on == null ? 'Group by' : 'Group by: ${on.label}'),
      ),
    );
  }

  /// Import: tasks pasted one per line (a spreadsheet's first column comes
  /// across the same way), each added to the board's first group.
  Future<void> _import(Object? groupId) async {
    final box = TextEditingController();
    final lines = await showSidePanel<List<String>>(
      context,
      builder: (panel) => SidePanel(
        title: 'Import tasks',
        actions: [
          TextButton(
            onPressed: () => Navigator.of(panel).pop(),
            child: const Text('Cancel'),
          ),
          FilledButton(
            key: const Key('tasks-import-go'),
            onPressed: () => Navigator.of(panel).pop(
              box.text
                  .split('\n')
                  .map((l) => l.split('\t').first.split(',').first.trim())
                  .where((t) => t.isNotEmpty)
                  .toList(),
            ),
            child: const Text('Import'),
          ),
        ],
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Paste one task per line, or copy a column from a spreadsheet. '
              'Each line becomes a task in the first group.',
            ),
            const SizedBox(height: 12),
            TextField(
              key: const Key('tasks-import-text'),
              controller: box,
              autofocus: true,
              minLines: 8,
              maxLines: 20,
              decoration: const InputDecoration(border: OutlineInputBorder()),
            ),
          ],
        ),
      ),
    );
    if (lines == null || lines.isEmpty || !mounted) return;
    var made = 0;
    String? why;
    for (final title in lines) {
      final r = await widget.api.post('/tasks', {
        'board_id': _board!['id'],
        'title': title,
        'group_id': ?groupId,
      });
      if (!r.ok) {
        why = r.reason;
        break;
      }
      made++;
    }
    if (!mounted) return;
    _say(
      why == null
          ? (made == 1 ? '1 task imported.' : '$made tasks imported.')
          : '$made imported, then: $why',
    );
    await _open(_selected);
  }

  /// Folded away on a phone until asked for.
  bool _toolsOpen = false;

  /// Monday's "New task" split button: the task opens its add box in the
  /// first group; the arrow offers a new group (Jose 11-13).
  Widget _newTaskButton() {
    final first = _groups(_board!).where((g) => g['id'] != null).firstOrNull;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        FilledButton.icon(
          key: const Key('tasks-new-task'),
          style: FilledButton.styleFrom(
            shape: const RoundedRectangleBorder(
              borderRadius: BorderRadius.horizontal(left: Radius.circular(8)),
            ),
          ),
          onPressed: first == null
              ? null
              : () => setState(() {
                  _adding = '${first['id']}';
                  _addError = null;
                  _newTitle.clear();
                }),
          icon: const Icon(Icons.add, size: 18),
          label: const Text('New task'),
        ),
        const SizedBox(width: 1),
        MenuAnchor(
          menuChildren: [
            MenuItemButton(
              key: const Key('tasks-new-group'),
              leadingIcon: const Icon(Icons.playlist_add, size: 18),
              onPressed: () => setState(() {
                _naming = 'new';
                _groupName.clear();
              }),
              child: const Text('New group'),
            ),
            if (first != null)
              MenuItemButton(
                key: const Key('tasks-import'),
                leadingIcon: const Icon(Icons.upload_file_outlined, size: 18),
                onPressed: () => _import(first['id']),
                child: const Text('Import'),
              ),
          ],
          builder: (context, menu, _) => FilledButton(
            key: const Key('tasks-new-more'),
            style: FilledButton.styleFrom(
              padding: const EdgeInsets.symmetric(horizontal: 6),
              minimumSize: const Size(32, 40),
              shape: const RoundedRectangleBorder(
                borderRadius: BorderRadius.horizontal(
                  right: Radius.circular(8),
                ),
              ),
            ),
            onPressed: () => menu.isOpen ? menu.close() : menu.open(),
            child: const Icon(Icons.arrow_drop_down, size: 20),
          ),
        ),
      ],
    );
  }

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
        label: Text('Invite · ${_board!['members_count'] ?? 0}'),
      ),
      if (custom)
        StatusPill(
          boardVisibilityLabel(_board!['visibility']),
          tone: private ? Tone.caution : Tone.neutral,
        ),
      // Monday's Integrate: EzBizi boards connect to jobs, leads and
      // messages through task rules, so it opens the same panel.
      if (automate && !phone)
        OutlinedButton.icon(
          key: const Key('tasks-integrate'),
          onPressed: _automate,
          icon: const Icon(Icons.hub_outlined, size: 18),
          label: const Text('Integrate'),
        ),
      if (automate && !phone)
        OutlinedButton.icon(
          key: const Key('tasks-automate'),
          onPressed: _automate,
          icon: const Icon(Icons.bolt, size: 18),
          label: Text(switch (_board!['automations_count']) {
            final num n when n > 0 => 'Automate · $n',
            _ => 'Automate',
          }),
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
        // 32: page padding; 2: the group box's border.
        final need = 34 + _fixedWidth;
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
      key: _tableKey,
      controller: _tableScroll,
      slivers: [
        SliverPadding(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 24),
          sliver: SliverMainAxisGroup(
            slivers: [
              if (_picked.isNotEmpty && !cards) box(_bulkBar(context)),
              for (final (i, g) in _shownGroups().indexed)
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
    // A Group by group draws its tasks but takes no adds, moves or renames.
    final virtual = group['virtual'] == true;
    final id = virtual ? null : group['id'] as String?;
    final fold = group['id'] as String?;

    final header = _dropOn(
      id,
      null,
      InkWell(
        key: ValueKey('task-group-$fold'),
        onTap: () =>
            setState(() => folded ? _folded.remove(fold) : _folded.add(fold)),
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
                      switch ((_totalsOf(id)?['count'] as num?)?.toInt() ??
                          all.length) {
                        1 => '1 task',
                        final n => '$n tasks',
                      },
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
      // Built as they scroll into view, as the table's rows are.
      final lines = <Widget Function()>[
        () => header,
        if (!folded) ...[
          for (final t in shown) ...[
            () => _card(context, t),
            if (_expanded.contains(t['id'])) ...[
              for (final sub in _subtasksOf(t))
                () => Padding(
                  padding: const EdgeInsets.only(left: 20),
                  child: _card(context, sub, sub: true),
                ),
              if (_canEdit && id != null)
                () => Padding(
                  padding: const EdgeInsets.only(left: 20),
                  child: _addRow(context, id, parent: t['id'] as String),
                ),
            ],
          ],
          if (_canEdit && id != null) () => _addRow(context, id),
        ],
      ];
      return SliverPadding(
        padding: const EdgeInsets.only(bottom: 14),
        sliver: SliverList.builder(
          itemCount: lines.length,
          itemBuilder: (context, i) => lines[i](),
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
              key: ValueKey('task-group-top-$fold'),
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
          if (!folded) _rowsSliver(context, shown, id, colour),
        ],
      ),
    );
  }

  /// A group's rows, built only as they scroll into view: a Column of every
  /// row froze the desktop app on a 169-task board (Jose, 2026-11-16).
  Widget _rowsSliver(
    BuildContext context,
    List<_Json> shown,
    String? id,
    Color colour,
  ) {
    final theme = Theme.of(context);
    final edge = BorderSide(color: theme.dividerColor);
    Widget addLine({String? parent}) => _cellBorder(
      context,
      Row(
        children: [
          Container(width: 6, height: 38, color: colour.withValues(alpha: 0.3)),
          if (parent != null) const SizedBox(width: 28),
          Expanded(child: _addRow(context, id!, parent: parent)),
        ],
      ),
    );
    // One entry per line: a row, a subtask, or an add line under them.
    final lines = <Widget Function()>[
      for (final (at, t) in shown.indexed) ...[
        () => _dropOn(id, at, _row(context, t, colour)),
        if (_expanded.contains(t['id'])) ...[
          for (final sub in _subtasksOf(t))
            () => _row(context, sub, colour, sub: true),
          if (_canEdit && id != null) () => addLine(parent: t['id'] as String),
        ],
      ],
      if (_totalsOf(id) case {'has_more': true, 'count': num n, 'shown': num s})
        () => _cellBorder(
          context,
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton(
              key: ValueKey('group-more-$id'),
              onPressed: () => _showMore(id),
              child: Text('Show more (${n - s})'),
            ),
          ),
        ),
      if (_footer(context, shown, groupId: id) case final f?) () => f,
      if (_canEdit && id != null) () => addLine(),
    ];
    if (lines.isEmpty) {
      return SliverToBoxAdapter(
        child: Container(
          height: 1,
          decoration: BoxDecoration(
            border: Border(left: edge, right: edge, bottom: edge),
          ),
        ),
      );
    }
    final last = lines.length - 1;
    return SliverList.builder(
      itemCount: lines.length,
      itemBuilder: (context, i) => DecoratedBox(
        decoration: BoxDecoration(
          color: theme.colorScheme.surface,
          border: Border(
            left: edge,
            right: edge,
            bottom: i == last ? edge : BorderSide.none,
          ),
          borderRadius: i == last
              ? const BorderRadius.vertical(bottom: Radius.circular(12))
              : null,
        ),
        child: lines[i](),
      ),
    );
  }

  static const _widths = (add: 40.0);
  static const _baseWidths = {
    'task': 320.0,
    'creator': 130.0,
    'owner': 130.0,
    'status': 130.0,
    'due': 104.0,
    'created': 104.0,
    'about': 170.0,
    'updates': 84.0,
  };

  /// A width being dragged, before it is saved.
  final Map<String, double> _dragWidths = {};

  /// This board's slot in `lists.column_widths`: built-ins 0–6, the board's
  /// own columns 10 and up.
  // ponytail: custom slots follow column order, so a moved column takes the
  // slot's width; key by column id if that bothers anyone.
  String get _widthList => 'task-board-${_board?['id'] ?? ''}'
      .toLowerCase()
      .replaceAll(RegExp(r'[^a-z0-9_.-]'), '');

  /// This person's saved widths for this board, read once per change of
  /// preferences or board: every cell asks (freeze 11-16).
  Map<int, double> _savedWidths() {
    final prefs = widget.session.me?.preferences;
    final board = _board?['id'];
    if (!identical(prefs, _widthsFrom) || board != _widthsBoard) {
      _widthsFrom = prefs;
      _widthsBoard = board;
      _widthsSaved = widget.session.me?.listColumnWidths(_widthList) ?? {};
    }
    return _widthsSaved;
  }

  Object? _widthsFrom;
  Object? _widthsBoard;
  Map<int, double> _widthsSaved = const {};

  int _widthSlot(String key) => key == 'task'
      ? 7
      : key.startsWith('c:')
      ? 10 + boardColumns(_board).indexWhere((c) => 'c:${c.id}' == key)
      : _builtinDefaults.keys.toList().indexOf(key);

  /// A column's width: being dragged, saved by this person, or the default.
  double _w(String key) =>
      _dragWidths[key] ??
      _savedWidths()[_widthSlot(key)] ??
      _baseWidths[key] ??
      120.0;

  /// A heading with a drag handle on its right edge (Jose, 2026-11-12).
  Widget _resizable(String key, Widget heading) => Stack(
    children: [
      heading,
      Positioned(
        right: 0,
        top: 0,
        bottom: 0,
        width: 6,
        child: MouseRegion(
          cursor: SystemMouseCursors.resizeColumn,
          child: GestureDetector(
            key: ValueKey('column-resize-$key'),
            behavior: HitTestBehavior.opaque,
            onHorizontalDragUpdate: (d) => setState(
              () => _dragWidths[key] = (_w(key) + d.delta.dx).clamp(
                key == 'task' ? 200 : 60,
                900,
              ),
            ),
            onHorizontalDragEnd: (_) {
              final width = _dragWidths.remove(key);
              final slot = _widthSlot(key);
              if (width == null || slot < 0 || slot > 99) return;
              widget.session.setPreference('lists.column_widths', {
                if (widget.session.me?.preferences['lists.column_widths']
                    case final Map saved)
                  ...saved,
                '$_widthList:$slot': width.roundToDouble(),
              });
              setState(() {});
            },
          ),
        ),
      ),
    ],
  );

  /// Everything but the task's own column, so a board with many columns
  /// scrolls sideways instead of squeezing the task to nothing.
  double get _fixedWidth =>
      6 +
      // The task column holds its drag handle, tick and ⋯ menu.
      _w('task') +
      (_builtinShown(_board, 'creator') ? _w('creator') : 0) +
      (_builtinShown(_board, 'owner') ? _w('owner') : 0) +
      (_builtinShown(_board, 'status') ? _w('status') : 0) +
      (_builtinShown(_board, 'due') ? _w('due') : 0) +
      (_builtinShown(_board, 'created') ? _w('created') : 0) +
      (_builtinShown(_board, 'about') ? _w('about') : 0) +
      (_builtinShown(_board, 'updates') ? _w('updates') : 0) +
      _widths.add +
      boardColumns(_board).fold(0.0, (sum, c) => sum + _w('c:${c.id}'));

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
  Widget? _footer(BuildContext context, List<_Json> tasks, {String? groupId}) {
    // The server's sums over every task, when only a page is here and
    // nothing narrows the rows.
    final server = _filter.isEmpty && _query.trim().isEmpty && _groupBy == null
        ? (_totalsOf(groupId)?['footers'] as Map?)
        : null;
    final columns = boardColumns(_board);
    if (tasks.isEmpty) return null;
    final theme = Theme.of(context);
    final style = theme.textTheme.bodySmall?.copyWith(
      fontWeight: FontWeight.w700,
      color: theme.colorScheme.onSurfaceVariant,
      fontFeatures: const [FontFeature.tabularFigures()],
    );
    bool shown(String key) => _builtinShown(_board, key);
    Widget blank(double w) => SizedBox(width: w);
    Widget text(double w, String? s, {Key? key, bool right = false}) =>
        SizedBox(
          width: w,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 8),
            child: Text(
              s ?? '',
              key: key,
              textAlign: right ? TextAlign.right : TextAlign.center,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: style,
            ),
          ),
        );
    String? total(BoardColumn c) {
      if (c.footer == 'none' || (c.type != 'number' && c.type != 'formula')) {
        return null;
      }
      if (server?[c.id] case {'sum': num sum, 'average': num avg}) {
        return c.footer == 'average'
            ? 'avg ${formatColumnNumber(avg, c)}'
            : formatColumnNumber(sum, c);
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

    // Monday's group summary: each label column's spread as a stacked bar,
    // and the span of dates under a date or timeline (Jose, 2026-11-13).
    Widget statusBar() {
      final counts = <String?, int>{};
      for (final t in tasks) {
        counts.update(_statusOf(t), (n) => n + 1, ifAbsent: () => 1);
      }
      return LabelBar(
        key: const Key('status-footer'),
        counts: [
          for (final e in counts.entries)
            (
              _statusFor(_board, e.key) == null
                  ? null
                  : _statusColours(context, _statusFor(_board, e.key)).fill,
              e.value,
            ),
        ],
      );
    }

    Widget columnFooter(BoardColumn c) {
      final w = _w('c:${c.id}');
      if (c.type == 'status') {
        final counts = <String?, int>{};
        for (final t in tasks) {
          counts.update(
            columnValue(t, c)?.toString(),
            (n) => n + 1,
            ifAbsent: () => 1,
          );
        }
        return SizedBox(
          width: w,
          height: 30,
          child: LabelBar(
            key: ValueKey('column-footer-${c.id}'),
            counts: [
              for (final e in counts.entries)
                (
                  c.options.where((o) => o.id == e.key).firstOrNull?.colour,
                  e.value,
                ),
            ],
          ),
        );
      }
      if (c.type == 'date' || c.type == 'timeline') {
        return text(
          w,
          dateRangeLabel([
            for (final t in tasks) ...daysOf(columnValue(t, c)),
          ], _formats),
          key: ValueKey('column-footer-${c.id}'),
        );
      }
      return text(
        w,
        total(c),
        key: ValueKey('column-footer-${c.id}'),
        right: true,
      );
    }

    return _cellBorder(
      context,
      SizedBox(
        height: 30,
        child: Row(
          children: [
            const SizedBox(width: 6),
            SizedBox(
              width: _w('task'),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 10),
                child: columns.any((c) => total(c) != null)
                    ? Text('Total', style: style)
                    : const SizedBox.shrink(),
              ),
            ),
            if (shown('creator')) blank(_w('creator')),
            if (shown('owner')) blank(_w('owner')),
            if (shown('status'))
              SizedBox(width: _w('status'), height: 30, child: statusBar()),
            if (shown('created')) blank(_w('created')),
            if (shown('due'))
              text(
                _w('due'),
                dateRangeLabel([
                  for (final t in tasks) ...daysOf(t['due_on']),
                ], _formats),
                key: const Key('due-footer'),
              ),
            for (final c in columns) columnFooter(c),
            if (shown('about')) blank(_w('about')),
            if (shown('updates')) blank(_w('updates')),
            if (_canEdit) blank(_widths.add),
            const Spacer(),
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
    Widget b(String key, double width) => _resizable(
      key,
      _cell(
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
            _resizable(
              'task',
              SizedBox(
                width: _w('task'),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 10),
                  child: Align(
                    alignment: Alignment.centerLeft,
                    child: h('Task'),
                  ),
                ),
              ),
            ),
            if (shown('creator')) b('creator', _w('creator')),
            if (shown('owner')) b('owner', _w('owner')),
            if (shown('status')) b('status', _w('status')),
            if (shown('created')) b('created', _w('created')),
            if (shown('due')) b('due', _w('due')),
            for (final c in boardColumns(_board))
              _resizable(
                'c:${c.id}',
                _cell(context, _w('c:${c.id}'), _columnHeader(c, h(c.name))),
              ),
            if (shown('about')) b('about', _w('about')),
            if (shown('updates')) b('updates', _w('updates')),
            if (_canEdit)
              SizedBox(
                width: _widths.add,
                child: MenuAnchor(
                  menuChildren: _addColumnItems(),
                  builder: (context, menu, _) => IconButton(
                    key: const Key('column-add'),
                    tooltip: 'Add a column',
                    iconSize: 18,
                    padding: EdgeInsets.zero,
                    onPressed: () {
                      if (menu.isOpen) return menu.close();
                      _columnSearch.clear();
                      setState(() {});
                      menu.open();
                    },
                    icon: const Icon(Icons.add),
                  ),
                ),
              ),
            const Spacer(),
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
        onSecondaryTapDown: sub
            ? null
            : (d) => _rowRightClick(t, d.globalPosition),
        child: SizedBox(
          height: 38,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Container(
                width: 6,
                color: sub ? colour.withValues(alpha: 0.4) : colour,
              ),
              SizedBox(
                width: _w('task'),
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
                            onPressed: () => _pick(t['id'] as String),
                          ),
                        ),
                      ],
                      if (!sub) SizedBox(width: 32, child: _rowMenu(t)),
                      if (!sub) _subtaskToggle(t),
                      Flexible(
                        child: Text(
                          taskTitle(t),
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
                _cell(context, _w('creator'), _creatorCell(context, t)),
              if (shown('owner'))
                _cell(
                  context,
                  _w('owner'),
                  _canEdit
                      ? _ownerMenu(
                          t,
                          owner == null
                              ? _automationOwner(t)
                              : Row(
                                  children: [
                                    _ownerFace(context, t, owner),
                                    const SizedBox(width: 6),
                                    Flexible(
                                      child: Text(
                                        owner.split(' ').first,
                                        overflow: TextOverflow.ellipsis,
                                      ),
                                    ),
                                    if (owners.length > 1)
                                      _moreOwners(t, owners),
                                  ],
                                ),
                        )
                      : owner == null
                      ? _automationOwner(t)
                      : Row(
                          children: [
                            _ownerFace(context, t, owner),
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
                SizedBox(width: _w('status'), child: _statusCell(context, t)),
              if (shown('created'))
                _cell(
                  context,
                  _w('created'),
                  Text(
                    _createdLabel(t['created_at'], _formats),
                    key: ValueKey('task-created-${t['id']}'),
                    maxLines: 1,
                  ),
                ),
              if (shown('due'))
                _cell(
                  context,
                  _w('due'),
                  _dueCell(
                    t,
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
                ),
              for (final c in boardColumns(_board))
                c.type == 'status'
                    ? _rangeCell(
                        t,
                        c,
                        SizedBox(
                          width: _w('c:${c.id}'),
                          child: ColumnCell(
                            column: c,
                            value: columnValue(t, c),
                            taskDone: _isDone(_board, t),
                            people: _people,
                            formats: _formats,
                            onChanged: _canEdit
                                ? (v) => _setValue(t, c.id, v)
                                : null,
                            onAddOption: _canEdit
                                ? (label) => _addOption(c, label)
                                : null,
                            onEditOptions: _canEdit
                                ? () => _editColumn(c)
                                : null,
                            onMenu: _statusMenu,
                            api: widget.api,
                            onAutoAssign: _canEdit && c.type == 'status'
                                ? () => _suggestLabel(t, c.id)
                                : null,
                          ),
                        ),
                      )
                    : _rangeCell(
                        t,
                        c,
                        _cell(
                          context,
                          _w('c:${c.id}'),
                          ColumnCell(
                            column: c,
                            value: columnValue(t, c),
                            taskDone: _isDone(_board, t),
                            people: _people,
                            formats: _formats,
                            onChanged: _canEdit
                                ? (v) => _setValue(t, c.id, v)
                                : null,
                            onAddOption: _canEdit
                                ? (label) => _addOption(c, label)
                                : null,
                            onEditOptions: _canEdit
                                ? () => _editColumn(c)
                                : null,
                            onMenu: _statusMenu,
                            api: widget.api,
                            boardId: '${_board!['id']}',
                            onFilter: () => _filterTo(c.id, _valueOf(c.id, t)),
                            // Dependency: this board's other tasks; Connect
                            // starts from them too.
                            tasks: c.type == 'connect' || c.type == 'dependency'
                                ? [
                                    for (final o in _tasks)
                                      if (o['id'] != t['id'])
                                        ('${o['id']}', taskTitle(o)),
                                  ]
                                : null,
                            onAddFile: _canEdit && c.type == 'files'
                                ? () => _addFileTo(t, c)
                                : null,
                            onAutoAssign: _canEdit && c.type == 'status'
                                ? () => _suggestLabel(t, c.id)
                                : null,
                          ),
                        ),
                      ),
              if (shown('about'))
                _cell(
                  context,
                  _w('about'),
                  InkWell(
                    key: ValueKey('task-about-${t['id']}'),
                    onTap: _canEdit ? () => _editAbout(t) : null,
                    child: Align(
                      alignment: Alignment.centerLeft,
                      child: Text(
                        about ?? '—',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: about == null
                            ? TextStyle(
                                color: theme.colorScheme.onSurfaceVariant,
                              )
                            : TextStyle(
                                color: theme.colorScheme.primary,
                                fontWeight: FontWeight.w700,
                              ),
                      ),
                    ),
                  ),
                ),
              if (shown('updates'))
                _cell(
                  context,
                  _w('updates'),
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
              const Spacer(),
            ],
          ),
        ),
      ),
    );
  }

  /// Due, edited where it is like any date column.
  Widget _dueCell(_Json t, Widget label) => !_canEdit
      ? label
      : InlineCellEditor(
          column: (
            id: 'due-${t['id']}',
            name: _builtinLabel(_board, 'due'),
            type: 'date',
            unit: null,
            max: null,
            options: const [],
            format: 'plain',
            footer: 'none',
            formula: null,
          ),
          value: t['due_on'],
          people: _people,
          formats: _formats,
          onChanged: (v) => _patchTask(t, {'due_on': v}),
          child: label,
        );

  // ── Cell range: right-click filter, Shift/Ctrl select, copy/paste ──

  /// The column a range of cells is picked in, and the tasks picked.
  String? _rangeColumn;
  final List<String> _range = [];
  String? _rangeAnchor;
  final _rangeFocus = FocusNode(debugLabel: 'task-board-range');

  /// Shift-click extends the range from the last cell clicked down or up the
  /// column; Ctrl (or Cmd) toggles one cell. A plain click clears it.
  void _pickCell(_Json t, BoardColumn c) {
    final keys = HardwareKeyboard.instance;
    final id = t['id'] as String;
    final shift = keys.isShiftPressed;
    final toggle = keys.isControlPressed || keys.isMetaPressed;
    if (!shift && !toggle) {
      if (_range.isNotEmpty) setState(_clearRange);
      _rangeAnchor = id;
      _rangeColumn = c.id;
      return;
    }
    setState(() {
      if (_rangeColumn != c.id) {
        _range.clear();
        _rangeColumn = c.id;
      }
      if (toggle) {
        _range.contains(id) ? _range.remove(id) : _range.add(id);
      } else {
        final order = [for (final x in _shownTasks) x['id'] as String];
        final from = order.indexOf(_rangeAnchor ?? id);
        final to = order.indexOf(id);
        _range
          ..clear()
          ..addAll(
            from < 0
                ? [id]
                : order.sublist(math.min(from, to), math.max(from, to) + 1),
          );
      }
      _rangeAnchor ??= id;
    });
    _rangeFocus.requestFocus();
  }

  void _clearRange() {
    _range.clear();
    _rangeColumn = null;
  }

  /// Ctrl+C copies the range's first value; Ctrl+V writes the clipboard to
  /// every cell in it; Esc lets go.
  KeyEventResult _rangeKey(FocusNode _, KeyEvent e) {
    if (e is! KeyDownEvent || _range.isEmpty) return KeyEventResult.ignored;
    final keys = HardwareKeyboard.instance;
    final mod = keys.isControlPressed || keys.isMetaPressed;
    if (e.logicalKey == LogicalKeyboardKey.escape) {
      setState(_clearRange);
      return KeyEventResult.handled;
    }
    if (mod && e.logicalKey == LogicalKeyboardKey.keyC) {
      _copyRange();
      return KeyEventResult.handled;
    }
    if (mod && e.logicalKey == LogicalKeyboardKey.keyV && _canEdit) {
      _pasteRange();
      return KeyEventResult.handled;
    }
    return KeyEventResult.ignored;
  }

  BoardColumn? get _rangeCol =>
      boardColumns(_board).where((c) => c.id == _rangeColumn).firstOrNull;

  Future<void> _copyRange() async {
    final c = _rangeCol;
    final t = _tasks.where((x) => x['id'] == _range.first).firstOrNull;
    if (c == null || t == null) return;
    final v = columnValue(t, c);
    await Clipboard.setData(
      ClipboardData(text: v == null ? '' : (v is String ? v : jsonEncode(v))),
    );
    if (mounted) _say(_range.length == 1 ? 'Copied' : 'Copied the first cell');
  }

  Future<void> _pasteRange() async {
    final c = _rangeCol;
    final text = (await Clipboard.getData(Clipboard.kTextPlain))?.text;
    if (c == null || text == null || !mounted) return;
    Object? value = text.trim().isEmpty ? null : text;
    if (text.startsWith('[') || text.startsWith('{')) {
      try {
        value = jsonDecode(text);
      } on FormatException {
        // Plain text that only looks like JSON.
      }
    } else if (c.type == 'number' || c.type == 'currency') {
      value = num.tryParse(text.trim());
    } else if (c.type == 'checkbox') {
      value = text.trim() == 'true';
    }
    for (final id in List.of(_range)) {
      final t = _tasks.where((x) => x['id'] == id).firstOrNull;
      if (t != null) await _setValue(t, c.id, value);
      if (!mounted) return;
    }
    _say(
      _range.length == 1 ? '1 cell pasted' : '${_range.length} cells pasted',
    );
  }

  /// A board column's cell: picked into a range, and on right-click
  /// Monday's "Filter by this value".
  Widget _rangeCell(_Json t, BoardColumn c, Widget child) {
    final picked = _rangeColumn == c.id && _range.contains(t['id']);
    final scheme = Theme.of(context).colorScheme;
    final value = _valueOf(c.id, t);
    final menu = MenuController();
    return MenuAnchor(
      controller: menu,
      menuChildren: [
        MenuItemButton(
          key: const Key('cell-filter-value'),
          leadingIcon: const Icon(Icons.filter_list, size: 18),
          onPressed: value.isEmpty ? null : () => _filterTo(c.id, value),
          child: const Text('Filter by this value'),
        ),
        if (_filteredOn(c.id))
          MenuItemButton(
            onPressed: () => _clearFilterOn(c.id),
            child: const Text('Clear filter'),
          ),
      ],
      // A Listener for the pick: the cell's own editor wins the tap arena.
      child: Listener(
        key: ValueKey('range-cell-${c.id}-${t['id']}'),
        onPointerDown: (e) {
          if (e.buttons == kPrimaryButton) _pickCell(t, c);
        },
        // A gesture for the menu, so it wins over the row's right-click; an
        // in-place cell has its own menu, with Filter by this value.
        child: GestureDetector(
          onSecondaryTapUp: ColumnCell.editsInPlace(c, editable: _canEdit)
              ? null
              : (d) => menu.open(position: d.localPosition),
          child: Focus(
            focusNode: picked && _range.first == t['id'] ? _rangeFocus : null,
            onKeyEvent: _rangeKey,
            child: DecoratedBox(
              position: DecorationPosition.foreground,
              decoration: BoxDecoration(
                color: picked ? scheme.primary.withValues(alpha: 0.10) : null,
                border: picked ? Border.all(color: scheme.primary) : null,
              ),
              child: child,
            ),
          ),
        ),
      ),
    );
  }

  /// A Files cell's "+": the file goes up as an update on the task (the
  /// same upload as the sidebar's), then joins the cell.
  Future<void> _addFileTo(_Json t, BoardColumn c) async {
    final picked = await pickTaskUpdateFile();
    if (picked == null || !mounted) return;
    final refused = taskUpdateFileRefusal(picked.name, picked.bytes.length, 0);
    if (refused != null) return _say(refused);
    final res = await widget.api.postFiles(
      '/tasks/${t['id']}/updates',
      'files',
      [(name: picked.name, bytes: picked.bytes)],
    );
    if (!mounted) return;
    if (!res.ok) return _say(res.reason);
    final added = [
      for (final a
          in ((res.body['data'] as Map?)?['attachments'] as List?) ?? const [])
        if (a is Map && a['id'] != null) '${a['id']}',
    ];
    if (added.isEmpty) return;
    final had = [
      for (final id in (columnValue(t, c) as List?) ?? const []) '$id',
    ];
    await _setValue(t, c.id, [...had, ...added]);
  }

  /// The "+" menu's search box (Monday's add-column search).
  final _columnSearch = TextEditingController();

  /// The "+" menu: a search on top; with nothing typed, Monday's sections
  /// and More; with a word typed, every type whose name has it.
  List<Widget> _addColumnItems() {
    final q = _columnSearch.text.trim().toLowerCase();
    MenuItemButton item(String type, String label, IconData icon) =>
        MenuItemButton(
          key: ValueKey('column-add-$type'),
          leadingIcon: Icon(icon, size: 18),
          onPressed: () => _addColumn(type),
          child: Text(label),
        );
    final found = [
      for (final (type, label, icon) in columnTypes)
        if (label.toLowerCase().contains(q) || type.contains(q))
          item(type, label, icon),
    ];
    return [
      Padding(
        padding: const EdgeInsets.fromLTRB(8, 6, 8, 4),
        child: SizedBox(
          width: 220,
          child: TextField(
            key: const Key('column-add-search'),
            controller: _columnSearch,
            autofocus: true,
            onChanged: (_) => setState(() {}),
            decoration: const InputDecoration(
              isDense: true,
              hintText: 'Find a column type',
              prefixIcon: Icon(Icons.search, size: 18),
              border: OutlineInputBorder(),
            ),
          ),
        ),
      ),
      if (q.isNotEmpty) ...[
        ...found,
        if (found.isEmpty)
          const MenuItemButton(onPressed: null, child: Text('No column type')),
      ] else ...[
        for (final (title, types) in columnTypeSections) ...[
          _menuHeading(context, title),
          for (final (type, label, icon) in columnTypes)
            if (types.contains(type)) item(type, label, icon),
        ],
        SubmenuButton(
          key: const Key('column-add-more'),
          leadingIcon: const Icon(Icons.more_horiz, size: 18),
          menuChildren: [
            for (final (type, label, icon) in columnTypes)
              if (!columnTypeSections.any((s) => s.$2.contains(type)))
                item(type, label, icon),
          ],
          child: const Text('More columns'),
        ),
      ],
    ];
  }

  // ── Row drag: Esc cancels, the list scrolls near its edges ─────────

  final _tableScroll = ScrollController();
  final _tableKey = GlobalKey(debugLabel: 'task-table');

  /// Esc was pressed during this drag: nothing it is dropped on moves.
  bool _dragCancelled = false;

  bool _dragKey(KeyEvent e) {
    if (e is! KeyDownEvent || e.logicalKey != LogicalKeyboardKey.escape) {
      return false;
    }
    setState(() => _dragCancelled = true);
    return true;
  }

  /// Within [_edge] of the list's top or bottom, the list scrolls towards
  /// it, faster the closer the pointer is.
  static const _edge = 56.0;
  void _autoScroll(Offset at) {
    final box = _tableKey.currentContext?.findRenderObject() as RenderBox?;
    if (box == null || !_tableScroll.hasClients || _dragCancelled) return;
    final y = box.globalToLocal(at).dy;
    final h = box.size.height;
    final step = y < _edge
        ? -(_edge - y)
        : y > h - _edge
        ? y - (h - _edge)
        : 0.0;
    if (step == 0) return;
    final p = _tableScroll.position;
    _tableScroll.jumpTo(
      (p.pixels + step / 3).clamp(p.minScrollExtent, p.maxScrollExtent),
    );
  }

  Widget _dragHandle(BuildContext context, _Json t) {
    final theme = Theme.of(context);
    return Draggable<String>(
      key: ValueKey('task-drag-${t['id']}'),
      data: t['id'] as String,
      onDragStarted: () {
        _dragCancelled = false;
        HardwareKeyboard.instance.addHandler(_dragKey);
      },
      onDragUpdate: (d) => _autoScroll(d.globalPosition),
      onDragEnd: (_) {
        HardwareKeyboard.instance.removeHandler(_dragKey);
        if (_dragCancelled) setState(() {});
      },
      feedback: Material(
        elevation: 4,
        borderRadius: BorderRadius.circular(8),
        color: theme.colorScheme.surface,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Text(
            _picked.contains(t['id']) && _picked.length > 1
                ? '${_picked.length} tasks'
                : taskTitle(t),
          ),
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
      onWillAcceptWithDetails: (d) => !_dragCancelled,
      onAcceptWithDetails: (d) {
        if (!_dragCancelled) _moveTo(d.data, groupId, position);
      },
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
            _avatar(context, label, api: widget.api, userId: madeBy['id']),
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
        color: labelInk(colours.fill),
        fontWeight: FontWeight.w700,
        fontSize: 12,
      ),
    );

    final editable = (_board?['statuses'] as List? ?? const []).any(
      (s) => s is Map && s.containsKey('id'),
    );
    return MenuAnchor(
      menuChildren: [
        Builder(
          builder: (context) => LabelBlocks(
            keyPrefix: 'task-status-${t['id']}',
            labels: [
              for (final s in _statuses(_board))
                (
                  id: s.id,
                  label: s.name,
                  colour: _statusColours(context, s).fill,
                ),
            ],
            current: _statusOf(t),
            onPick: (id) {
              MenuController.maybeOf(context)?.close();
              if (id != null) _setStatus(t, id);
            },
            onEdit: editable
                ? () {
                    MenuController.maybeOf(context)?.close();
                    _editStatuses();
                  }
                : null,
            onAutoAssign: editable
                ? () async {
                    MenuController.maybeOf(context)?.close();
                    final id = await _suggestLabel(t, null);
                    if (id != null) _setStatus(t, id);
                  }
                : null,
          ),
        ),
      ],
      onOpen: () => _statusMenu(true),
      onClose: () => _statusMenu(false),
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

  /// Owner edits where it is: a list of the board's people (Jose 11-13:
  /// only the name opens the task).
  Widget _ownerMenu(_Json t, Widget shown) => MenuAnchor(
    menuChildren: [
      MenuItemButton(
        onPressed: () => _patchTask(t, {'assignee_user_id': null}),
        child: const Text('Nobody'),
      ),
      for (final p in _people.entries)
        MenuItemButton(
          key: ValueKey('task-owner-${t['id']}-${p.key}'),
          onPressed: () => _patchTask(t, {'assignee_user_id': p.key}),
          child: Text(p.value),
        ),
    ],
    builder: (context, menu, _) => InkWell(
      key: ValueKey('task-owner-${t['id']}'),
      onTap: () => menu.isOpen ? menu.close() : menu.open(),
      child: Align(alignment: Alignment.centerLeft, child: shown),
    ),
  );

  /// About: straight to the link search, not the whole task.
  Future<void> _editAbout(_Json t) async {
    await showSidePanel<void>(
      context,
      builder: (_) => TaskLinkPanel(api: widget.api, task: t),
    );
    if (mounted) await _load();
  }

  /// Monday's "Auto-assign labels": the AI's pick for [t] in a status
  /// column (or the board's own status when [columnId] is null).
  Future<String?> _suggestLabel(_Json t, String? columnId) async {
    final res = await widget.api.post('/tasks/${t['id']}/suggest-label', {
      'column_id': ?columnId,
    });
    final id = res.ok ? ((res.data as Map?)?['id'] as String?) : null;
    if (mounted && id == null) {
      _say(res.ok ? 'No label clearly fits this task.' : res.reason);
    }
    return id;
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
                                taskTitle(t),
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
                              _ownerFace(context, t, owner),
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
                                            taskDone: _isDone(_board, t),
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
      isDone: (t) => _isDone(_board, t),
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
    // A fixed width at every size, scrolling sideways: Expanded columns
    // squeezed eleven statuses to ~30 px at 1440 (desktop review 2026-11-13).
    return ListView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 16),
      children: [
        for (final c in columns)
          Padding(
            padding: const EdgeInsets.only(right: 10),
            child: SizedBox(width: narrow ? 260 : 280, child: c),
          ),
      ],
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
    this.subtasks = const [],
  });

  /// Its subtasks as the board has them, for the panel's checklist.
  final List<_Json> subtasks;

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

  /// Locks an unlocked sensitive task again when its 5 minutes are up.
  Timer? _relock;

  bool get _canEdit => widget.board['can_edit'] == true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _relock?.cancel();
    _title.dispose();
    _due.dispose();
    _update.dispose();
    super.dispose();
  }

  /// An open sensitive task locks itself again at `unlocked_until`.
  void _armRelock(_Json task) {
    _relock?.cancel();
    if (!taskIsSensitive(task) || taskIsLocked(task)) return;
    final until = DateTime.tryParse('${task['unlocked_until'] ?? ''}');
    final left = until == null
        ? sensitiveUnlockFor
        : until.difference(DateTime.now());
    _relock = Timer(left.isNegative ? Duration.zero : left, () {
      if (!mounted || _task == null) return;
      setState(() {
        _task = {
          ..._task!,
          'locked': true,
          'values': <String, dynamic>{},
          'updates': const <dynamic>[],
        };
        _activity = null;
      });
    });
  }

  Future<void> _unlock() async {
    final open = await unlockSensitiveTask(
      context,
      api: widget.api,
      taskId: widget.taskId,
    );
    if (open == null || !mounted) return;
    setState(() {
      _task = open;
      _activity = null;
    });
    _armRelock(open);
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
      _armRelock(_task!);
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
      // The answer has no updates in it; keep the ones on screen (and when
      // the lock lapses it says so, with none).
      final next = res.body['data'] as _Json;
      _task = {
        ...next,
        'unlocked_until': _task!['unlocked_until'],
        'updates': taskIsLocked(next) ? const <dynamic>[] : _task!['updates'],
      };
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
        TextButton.icon(
          key: const Key('task-copy-link'),
          onPressed: () async {
            await Clipboard.setData(
              ClipboardData(text: '${Uri.base.origin}/tasks/${widget.taskId}'),
            );
            if (context.mounted) {
              ScaffoldMessenger.of(
                context,
              ).showSnackBar(const SnackBar(content: Text('Link copied')));
            }
          },
          icon: const Icon(Icons.link, size: 18),
          label: const Text('Copy link'),
        ),
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

  /// The status as one chip; it opens the same menu as the table's cell.
  Widget _statusChip(BuildContext context, _Json task) {
    final colours = _statusColours(
      context,
      _statusFor(widget.board, _statusOf(task)),
    );
    return MenuAnchor(
      menuChildren: [
        for (final s in _statuses(widget.board))
          MenuItemButton(
            key: ValueKey('task-panel-status-${s.id}'),
            leadingIcon: _statusDot(context, s),
            onPressed: _statusOf(task) == s.id
                ? null
                : () => _patch(_statusBody(widget.board, s.id, task)),
            child: Text(s.name),
          ),
      ],
      builder: (context, menu, _) => ActionChip(
        key: const Key('task-panel-status'),
        backgroundColor: colours.fill,
        side: BorderSide.none,
        label: Text(
          '${_statusLabel(widget.board, _statusOf(task))} ▾',
          style: TextStyle(color: colours.ink, fontWeight: FontWeight.w700),
        ),
        onPressed: _canEdit
            ? () => menu.isOpen ? menu.close() : menu.open()
            : null,
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
          decoration: InputDecoration(
            border: InputBorder.none,
            hintText: 'Name the task',
            prefixIcon: taskIsSensitive(task)
                ? Icon(
                    taskIsLocked(task) ? Icons.lock_outline : Icons.lock_open,
                    key: const Key('task-panel-lock'),
                    size: 20,
                  )
                : null,
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
        // Option A (Jose, 2026-11-12): one row with the status, the people
        // and the due day, then the board's fields in its order.
        Wrap(
          spacing: 8,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            _statusChip(context, task),
            SizedBox(
              width: 220,
              child: SearchPickerField<String>(
                key: const Key('task-panel-owner'),
                value: owner?['id'] as String?,
                isDense: true,
                hint: _builtinLabel(widget.board, 'owner'),
                options: [
                  const SearchOption(value: null, label: 'Nobody'),
                  for (final p in people.entries)
                    SearchOption(value: p.key, label: p.value),
                ],
                onChanged: _canEdit
                    ? (id) {
                        if (id != owner?['id']) {
                          _patch({'assignee_user_id': id});
                        }
                      }
                    : null,
              ),
            ),
            SizedBox(
              width: 210,
              child: CalendarDayField(
                key: const Key('task-panel-due'),
                controller: _due,
                formats: widget.formats,
                label: _builtinLabel(widget.board, 'due'),
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
            if (_late(task, widget.board))
              const StatusPill('Late', tone: Tone.bad),
          ],
        ),
        const SizedBox(height: 10),
        if (owner != null && task['owners'] is List)
          fact('', _moreOwnersEditor(task, people)),
        if (taskIsLocked(task)) ...[
          const SizedBox(height: 8),
          SensitiveLockedNotice(onUnlock: _unlock),
        ] else ...[
          if (taskIsSensitive(task))
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Text(
                'Unlocked for 5 minutes. It locks again by itself.',
                key: const Key('task-unlocked-note'),
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
          if ((task['about'] as Map?)?['label'] case final String about)
            fact(
              'Linked to',
              Text(
                about,
                key: const Key('task-panel-about'),
                style: TextStyle(
                  color: theme.colorScheme.primary,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          if (widget.subtasks.isNotEmpty) ...[
            const SizedBox(height: 6),
            Text(
              'Subtasks · ${widget.subtasks.where((x) => _isDone(widget.board, x)).length} of ${widget.subtasks.length}',
              key: const Key('task-panel-subtasks'),
              style: theme.textTheme.titleSmall?.copyWith(
                fontWeight: FontWeight.w700,
              ),
            ),
            for (final sub in widget.subtasks)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 3),
                child: Row(
                  children: [
                    Icon(
                      _isDone(widget.board, sub)
                          ? Icons.check_box
                          : Icons.check_box_outline_blank,
                      size: 18,
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                    const SizedBox(width: 8),
                    Expanded(child: Text(taskTitle(sub))),
                  ],
                ),
              ),
          ],
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
                  _avatar(
                    context,
                    '${(u['author'] as Map?)?['name'] ?? '?'}',
                    api: widget.api,
                    userId: (u['author'] as Map?)?['id'],
                  ),
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
                        if ('${u['body'] ?? ''}'.isNotEmpty)
                          Text('${u['body']}'),
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
          const Divider(height: 24),
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
          if (madeBy != null || task['created_at'] != null)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                [
                  if (madeBy != null)
                    madeBy['type'] == 'automation'
                        ? 'Automation "${madeBy['label']}"'
                        : 'Made by ${madeBy['label']}',
                  if (task['created_at'] != null)
                    formatStamp(
                      task['created_at'],
                      '',
                      formats: widget.formats,
                    ),
                ].join(' · '),
                key: const Key('task-panel-made-by'),
                style: muted,
              ),
            ),
        ],
      ],
    );
  }
}

/// A column heading's sort arrow: shown while sorted, and faintly on hover
/// so the heading stays quiet otherwise (Monday parity, Jose 11-13).
class SortArrow extends StatefulWidget {
  const SortArrow({super.key, required this.dir, required this.onTap});

  /// true descending, false ascending, null unsorted.
  final bool? dir;
  final VoidCallback onTap;

  @override
  State<SortArrow> createState() => _SortArrowState();
}

class _SortArrowState extends State<SortArrow> {
  bool _hover = false;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final on = widget.dir != null;
    return MouseRegion(
      onEnter: (_) => setState(() => _hover = true),
      onExit: (_) => setState(() => _hover = false),
      child: Tooltip(
        message: switch (widget.dir) {
          null => 'Sort ascending',
          false => 'Sort descending',
          true => 'Clear sort',
        },
        child: InkWell(
          onTap: widget.onTap,
          borderRadius: BorderRadius.circular(4),
          child: Padding(
            padding: const EdgeInsets.all(2),
            child: Opacity(
              opacity: on ? 1 : (_hover ? 0.6 : 0.15),
              child: Icon(
                widget.dir == true ? Icons.arrow_downward : Icons.arrow_upward,
                size: 14,
                color: on ? scheme.primary : scheme.onSurfaceVariant,
              ),
            ),
          ),
        ),
      ),
    );
  }
}
