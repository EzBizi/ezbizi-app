import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/branch_options.dart';
import '../../core/device_zone.dart';
import '../../core/job_status.dart';
import '../../core/session.dart';
import '../../widgets/async_body.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import '../task_board_columns.dart';
import 'job_detail_panel.dart';
import 'pm_frame.dart';
import 'pm_stages.dart';
import 'project_management_tab.dart' show PmTimeline;

/// Project Management › Assignments (design 143, with Jose's correction):
/// the Task Board cards Action 1 made when a payment arrived, from every
/// branch board in reach, in the group `jobs.pm_task_group` names. Read from
/// GET /jobs/project-management/cards (TasksController's task shape), under
/// the same search as Jobs, and grouped by the card's
/// Technician column; a card with none is "To assign".
///
/// The Technician column groups the cards and is edited in place through
/// the Task Board's own endpoints where the board allows it; it does not
/// assign the job (Jose's call, not made yet). A server without the
/// endpoint gets one sentence.
class ProjectManagementAssignments extends StatefulWidget {
  const ProjectManagementAssignments({
    super.key,
    required this.api,
    required this.session,
    this.areas = const [],
    this.generation = 0,
    this.onOpenSettings,
    this.scope = const PmScope(),
    this.statuses = const [],
    this.now,
    this.zone = deviceZone,
  });

  /// The organization's status words, for the timeline.
  final List<JobStatus> statuses;
  final DateTime Function()? now;
  final String? Function() zone;

  final ApiClient api;
  final Session session;
  final List<PmArea> areas;
  final int generation;
  final VoidCallback? onOpenSettings;
  final PmScope scope;

  /// The one sentence while the server has no cards endpoint.
  static const notYet =
      'Cards from the payment rule appear here once the server sends them.';

  @override
  State<ProjectManagementAssignments> createState() =>
      _ProjectManagementAssignmentsState();
}

/// The column name the cards are grouped by.
const pmTechnicianColumn = 'technician';

/// The group a card with no technician falls in.
const pmToAssign = 'To assign';

/// The cards grouped by their Technician value, "To assign" first, then the
/// technicians by name. [boards] are the boards the cards sit on, by id,
/// where the server sends them beside the tasks.
List<(String, List<Map<String, dynamic>>)> pmGroupCards(
  List<Map<String, dynamic>> tasks, [
  Map<String, Map<String, dynamic>> boards = const {},
]) {
  final groups = <String, List<Map<String, dynamic>>>{};
  for (final t in tasks) {
    final board = t['board'] is Map
        ? (t['board'] as Map).cast<String, dynamic>()
        : boards['${t['board_id']}'];
    String? tech;
    for (final c in boardColumns(board)) {
      if (c.name.trim().toLowerCase() != pmTechnicianColumn) continue;
      final v = columnValue(t, c);
      String? label;
      for (final o in c.options) {
        if (o.id == '$v') label = o.label;
      }
      label ??= v is Map
          ? v['name'] as String?
          : (v == null ? null : pmBoardPeople(board)['$v'] ?? '$v');
      if (label != null && label.trim().isNotEmpty) tech = label.trim();
    }
    (groups[tech ?? pmToAssign] ??= []).add(t);
  }
  final names = groups.keys.where((k) => k != pmToAssign).toList()
    ..sort((a, b) => a.toLowerCase().compareTo(b.toLowerCase()));
  return [
    (pmToAssign, groups[pmToAssign] ?? const []),
    for (final n in names) (n, groups[n]!),
  ];
}

/// A board's people by id, from its `assignable`.
Map<String, String> pmBoardPeople(Map<String, dynamic>? board) => {
  for (final u in (board?['assignable'] as List? ?? const []).whereType<Map>())
    '${u['id']}': '${u['name']}',
};

/// The board's Technician column, found by name, or null.
BoardColumn? pmTechnicianOf(Map<String, dynamic>? board) {
  for (final c in boardColumns(board)) {
    if (c.name.trim().toLowerCase() == pmTechnicianColumn) return c;
  }
  return null;
}

class _ProjectManagementAssignmentsState
    extends State<ProjectManagementAssignments> {
  static const _phoneBelow = 600.0;
  static const _columnWidth = 280.0;

  PmFilters _filters = const PmFilters();
  bool _loading = true;
  String? _error;

  /// False when the server has no cards endpoint yet.
  bool _served = true;
  List<Map<String, dynamic>> _tasks = const [];
  Map<String, Map<String, dynamic>> _boards = const {};
  int _request = 0;
  String? _phoneColumn;

  final _search = TextEditingController();
  Timer? _typing;

  Me get _me => widget.session.me!;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(ProjectManagementAssignments old) {
    super.didUpdateWidget(old);
    if (old.generation != widget.generation) _load();
  }

  @override
  void dispose() {
    _typing?.cancel();
    _search.dispose();
    super.dispose();
  }

  void _typed(String text) {
    _typing?.cancel();
    _typing = Timer(const Duration(milliseconds: 400), () {
      _refilter(_filters.copyWith(q: text));
    });
  }

  void _refilter(PmFilters next) {
    if (next == _filters) return;
    setState(() => _filters = next);
    _load();
  }

  Future<void> _load() async {
    final request = ++_request;
    setState(() {
      _loading = true;
      _error = null;
    });
    final response = await widget.api.get(
      '/jobs/project-management/cards',
      // The endpoint takes q (and franchise_id; the top-bar place already
      // scopes it). Service areas and flags are job fields cards lack.
      {if (_filters.q.trim().isNotEmpty) 'q': _filters.q.trim()},
    );
    if (!mounted || request != _request) return;
    setState(() {
      _loading = false;
      if (response.statusCode == 404 || response.statusCode == 405) {
        _served = false;
        return;
      }
      if (!response.ok) {
        _error = response.message;
        return;
      }
      _served = true;
      final data = response.data;
      final tasks = data is Map ? data['tasks'] : null;
      _tasks = [
        if (tasks is List)
          for (final t in tasks.whereType<Map>()) t.cast<String, dynamic>(),
      ];
      final boards = data is Map ? data['boards'] : null;
      _boards = {
        if (boards is List)
          for (final b in boards.whereType<Map>())
            '${b['id']}': b.cast<String, dynamic>(),
      };
    });
  }

  /// A card's Technician changed in place, through the Task Board's own
  /// endpoint. It moves at once and comes back if the server refuses.
  Future<void> _setTechnician(
    Map<String, dynamic> task,
    BoardColumn column,
    Object? value,
  ) async {
    final id = '${task['id']}';
    final before = _tasks;
    void put(Map<String, dynamic> t) => setState(() {
      _tasks = [for (final x in _tasks) '${x['id']}' == id ? t : x];
    });
    put({
      ...task,
      'values': {...?(task['values'] as Map?), column.id: value},
    });
    final res = await widget.api.patch('/tasks/$id', {
      'values': {column.id: value},
    });
    if (!mounted) return;
    if (!res.ok) {
      setState(() {
        _tasks = before;
        _cardError = (id, res.reason);
      });
      return;
    }
    final data = res.data;
    if (data is Map) put({...task, ...data.cast<String, dynamic>()});
    setState(() => _cardError = null);
    // The card's Technician and its job's sync both ways (Jose): the open
    // timeline is read again so it shows the job's new technician.
    final job = task['job'] is Map ? '${(task['job'] as Map)['id']}' : null;
    if (job != null && job == _openId) _openJob(job);
  }

  /// The job whose timeline is open, and its Project Management row.
  String? _openId;
  Map<String, dynamic>? _openRow;
  String? _openError;
  int _openRequest = 0;

  /// The job's row, which carries its timeline (as Logs reads it).
  Future<void> _openJob(String id) async {
    final request = ++_openRequest;
    setState(() {
      _openId = id;
      _openRow = null;
      _openError = null;
    });
    final response = await widget.api.get('/jobs/project-management', {
      'ids[]': [id],
      'timezone': ?widget.zone(),
    });
    if (!mounted || request != _openRequest) return;
    setState(() {
      final data = response.data;
      final row = response.ok && data is List
          ? data.whereType<Map>().where((r) => '${r['id']}' == id).firstOrNull
          : null;
      if (row == null) {
        _openError = response.ok
            ? 'This job is no longer in your view.'
            : response.message;
        return;
      }
      _openRow = row.cast<String, dynamic>();
    });
  }

  void _close() => setState(() {
    _openId = null;
    _openRow = null;
    _openError = null;
    _openRequest++;
  });

  String _status(Object? value) {
    if (value == null) return '—';
    for (final s in widget.statuses) {
      if (s.value == '$value') return s.label;
    }
    return jobStatusLabel(null, '$value');
  }

  Widget _pane({required bool back}) {
    final row = _openRow;
    if (row != null) {
      return PmTimeline(
        key: const ValueKey('pm-timeline'),
        back: back,
        row: row,
        now: (widget.now ?? DateTime.now)(),
        me: _me,
        status: _status,
        onClose: _close,
        onOpenJob: () => showSidePanel<bool>(
          context,
          builder: (_) => JobDetailPanel(
            api: widget.api,
            session: widget.session,
            id: '${row['id']}',
          ),
        ),
      );
    }
    return ColoredBox(
      key: const ValueKey('pm-assign-pane'),
      color: Theme.of(context).colorScheme.surface,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Align(
            alignment: back ? Alignment.centerLeft : Alignment.centerRight,
            child: IconButton(
              key: const ValueKey('pm-timeline-close'),
              tooltip: back ? 'Back' : 'Close',
              onPressed: _close,
              icon: Icon(back ? Icons.arrow_back : Icons.close),
            ),
          ),
          Expanded(
            child: AsyncBody(
              loading: _openError == null,
              error: _openError,
              onRetry: () => _openJob(_openId!),
              child: const SizedBox.shrink(),
            ),
          ),
        ],
      ),
    );
  }

  /// The last refused edit: the card and the server's sentence.
  (String, String)? _cardError;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final phone = constraints.maxWidth < _phoneBelow;
        final pad = PmFrame.gutter(constraints.maxWidth);
        if (phone && _openId != null) return _pane(back: true);
        final board = PmFrame(
          tab: 'assignments',
          purpose:
              'Who works each paid job: the Task Board cards a payment rule '
              'makes, by the technician on the card.',
          scope: widget.scope,
          search: KeyedSubtree(
            key: const ValueKey('tour-pm-assign-filters'),
            child: _searchField(),
          ),
          child: Padding(
            key: const ValueKey('tour-pm-assign-board'),
            padding: EdgeInsets.symmetric(horizontal: pad),
            child: AsyncBody(
              loading: _loading && _tasks.isEmpty,
              error: _error,
              onRetry: _load,
              child: _body(context, phone: phone),
            ),
          ),
        );
        if (_openId == null) return board;
        return Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(child: board),
            const VerticalDivider(width: 1),
            SizedBox(width: 360, child: _pane(back: false)),
          ],
        );
      },
    );
  }

  /// The cards are searched by their title, or by their job's own search.
  Widget _searchField() => TextField(
    key: const ValueKey('pm-assign-search'),
    controller: _search,
    onChanged: _typed,
    decoration: const InputDecoration(
      isDense: true,
      prefixIcon: Icon(Icons.search, size: 18),
      hintText: 'Card title, or its job, customer, address or phone',
    ),
  );

  Widget _sentence(BuildContext context, String text, Key key) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 24),
      child: Text(
        text,
        key: key,
        textAlign: TextAlign.center,
        style: theme.textTheme.bodyMedium?.copyWith(
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );
  }

  Widget _body(BuildContext context, {required bool phone}) {
    if (!_served) {
      return Align(
        alignment: Alignment.topCenter,
        child: _sentence(
          context,
          ProjectManagementAssignments.notYet,
          const ValueKey('pm-assign-not-yet'),
        ),
      );
    }
    if (_tasks.isEmpty) {
      return Align(
        alignment: Alignment.topCenter,
        child: _sentence(
          context,
          _filters.q.trim().isEmpty
              ? 'No cards are waiting.'
              : 'No cards match “${_filters.q.trim()}”.',
          const ValueKey('pm-assign-empty'),
        ),
      );
    }
    final groups = pmGroupCards(_tasks, _boards);

    if (phone) {
      final names = [for (final g in groups) g.$1];
      final shown = names.contains(_phoneColumn) ? _phoneColumn! : names.first;
      final group = groups.firstWhere((g) => g.$1 == shown);
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SearchPickerField<String>(
            key: const ValueKey('pm-assign-column-picker'),
            value: shown,
            label: 'Technician',
            isDense: true,
            options: [
              for (final g in groups)
                SearchOption(value: g.$1, label: '${g.$1} · ${g.$2.length}'),
            ],
            onChanged: (v) => setState(() => _phoneColumn = v),
          ),
          const SizedBox(height: 8),
          Expanded(child: _column(context, group.$1, group.$2)),
        ],
      );
    }

    return ListView(
      key: const ValueKey('tour-pm-assign-columns'),
      scrollDirection: Axis.horizontal,
      children: [
        for (final (name, cards) in groups)
          Padding(
            padding: const EdgeInsets.only(right: 12),
            child: SizedBox(
              width: _columnWidth,
              child: _column(context, name, cards),
            ),
          ),
      ],
    );
  }

  Widget _column(
    BuildContext context,
    String name,
    List<Map<String, dynamic>> cards,
  ) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return DragTarget<Map<String, dynamic>>(
      onWillAcceptWithDetails: (d) => _groupOf(d.data) != name,
      onAcceptWithDetails: (d) => _drop(d.data, name),
      builder: (context, hovering, _) => DecoratedBox(
        key: ValueKey('pm-assign-col-$name'),
        decoration: BoxDecoration(
          color: hovering.isEmpty
              ? scheme.surfaceContainerLow
              : scheme.primaryContainer,
          border: Border.all(
            color: hovering.isEmpty ? scheme.outlineVariant : scheme.primary,
          ),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 10, 12, 6),
              child: Text(
                '$name · ${cards.length}',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.titleSmall?.copyWith(
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(8, 0, 8, 8),
                children: [for (final c in cards) _draggable(context, c)],
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// The column a card is in now.
  String _groupOf(Map<String, dynamic> task) {
    for (final (name, cards) in pmGroupCards([task], _boards)) {
      if (cards.isNotEmpty) return name;
    }
    return pmToAssign;
  }

  /// A card dropped on a technician's column: the Technician value on the
  /// card's own board that names them, through the same PATCH as the field.
  void _drop(Map<String, dynamic> task, String name) {
    final board = _boards['${task['board_id']}'];
    final column = pmTechnicianOf(board);
    if (column == null || board?['can_edit'] != true) return;
    Object? value;
    var found = name == pmToAssign;
    if (!found) {
      for (final o in column.options) {
        if (o.label.trim() == name) {
          value = o.id;
          found = true;
        }
      }
      for (final e in pmBoardPeople(board).entries) {
        if (!found && e.value.trim() == name) {
          value = e.key;
          found = true;
        }
      }
    }
    if (!found) {
      setState(
        () => _cardError = (
          '${task['id']}',
          '$name is not a Technician choice on this board.',
        ),
      );
      return;
    }
    _setTechnician(task, column, value);
  }

  /// A card that can be dragged to another column where its board allows
  /// edits; the in-card field stays for keyboards and phones.
  Widget _draggable(BuildContext context, Map<String, dynamic> task) {
    final card = _card(context, task);
    final board = _boards['${task['board_id']}'];
    if (board?['can_edit'] != true || pmTechnicianOf(board) == null) {
      return card;
    }
    return Draggable<Map<String, dynamic>>(
      key: ValueKey('pm-assign-drag-${task['id']}'),
      data: task,
      feedback: Material(
        elevation: 6,
        borderRadius: BorderRadius.circular(8),
        child: SizedBox(width: _columnWidth - 16, child: card),
      ),
      childWhenDragging: Opacity(opacity: 0.4, child: card),
      child: card,
    );
  }

  Widget _card(BuildContext context, Map<String, dynamic> task) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: scheme.onSurfaceVariant,
    );
    final id = '${task['id']}';
    final board = _boards['${task['board_id']}'];
    String? status;
    for (final st
        in (board?['statuses'] as List? ?? const []).whereType<Map>()) {
      if ('${st['id'] ?? st['key']}' ==
          '${task['status_id'] ?? task['status']}') {
        status = '${st['name'] ?? st['label']}';
      }
    }
    // "Company · Branch" (branch_options.dart): the card's own branch.
    final franchise = task['franchise'] is Map
        ? task['franchise'] as Map
        : {'id': board?['franchise_id'], 'name': board?['name']};
    final branch = franchise['id'] == null
        ? ''
        : branchLabelOf(
            _me,
            '${franchise['id']}',
            '${franchise['name'] ?? ''}',
          );
    final job = task['job'] is Map ? task['job'] as Map : null;
    final about = task['about'] is Map ? (task['about'] as Map)['label'] : null;
    final tech = pmTechnicianOf(board);
    final error = _cardError?.$1 == id ? _cardError!.$2 : null;

    return Card(
      key: ValueKey('pm-assign-card-$id'),
      margin: const EdgeInsets.only(bottom: 8),
      elevation: 0,
      shape: RoundedRectangleBorder(
        side: BorderSide(color: scheme.outlineVariant),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Padding(
        padding: const EdgeInsets.all(10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              '${task['title'] ?? task['name'] ?? '—'}',
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodyMedium?.copyWith(
                fontWeight: FontWeight.w700,
              ),
            ),
            if (job != null && job['id'] != null)
              Semantics(
                link: true,
                child: InkWell(
                  key: ValueKey('pm-assign-job-$id'),
                  borderRadius: BorderRadius.circular(4),
                  onTap: () => _openJob('${job['id']}'),
                  child: Text(
                    'Job #${job['number'] ?? '—'}',
                    style: muted?.copyWith(
                      color: scheme.primary,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
              ),
            if (about is String && about.isNotEmpty)
              Text(
                about,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: muted,
              ),
            if (branch.isNotEmpty)
              Text(
                branch,
                key: ValueKey('pm-assign-branch-$id'),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: muted,
              ),
            if (status != null) ...[
              const SizedBox(height: 6),
              StatusPill(status),
            ],
            if (tech != null) ...[
              const SizedBox(height: 6),
              Row(
                children: [
                  Text('${tech.name}: ', style: muted),
                  Expanded(
                    child: KeyedSubtree(
                      key: ValueKey('pm-assign-tech-$id'),
                      child: ColumnCell(
                        column: tech,
                        value: columnValue(task, tech),
                        people: pmBoardPeople(board),
                        formats: _me.display,
                        onChanged: board?['can_edit'] == true
                            ? (v) => _setTechnician(task, tech, v)
                            : null,
                      ),
                    ),
                  ),
                ],
              ),
            ],
            if (error != null)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  error,
                  key: ValueKey('pm-assign-error-$id'),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.error,
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
