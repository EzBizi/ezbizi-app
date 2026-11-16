import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';

import '../core/timestamps.dart';
import '../widgets/calendar_day_field.dart';
import 'task_board_sensitive.dart';

/// Design 178 (Jose, 2026-11-03): the board's Calendar and Timeline views.
/// Both draw the tasks they are given; every write goes back through the
/// callbacks, so the screen keeps one copy of the board.

typedef PlanTask = Map<String, dynamic>;

DateTime? planDay(Object? v) => v is String && v.length >= 10
    ? DateTime.tryParse(v.substring(0, 10))
    : null;

DateTime _today() {
  final n = DateTime.now();
  return DateTime(n.year, n.month, n.day);
}

String _short(DateTime d) =>
    '${longMonths[d.month - 1].substring(0, 3)} ${d.day}';

/// A task as a small coloured chip; it carries its status's colour.
class _Chip extends StatelessWidget {
  const _Chip({required this.task, required this.colour, required this.onTap});

  final PlanTask task;
  final Color colour;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Material(
      color: colour.withValues(alpha: 0.16),
      borderRadius: BorderRadius.circular(6),
      child: InkWell(
        key: ValueKey('plan-task-${task['id']}'),
        borderRadius: BorderRadius.circular(6),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
          decoration: BoxDecoration(
            border: Border(left: BorderSide(color: colour, width: 3)),
          ),
          child: Text(
            taskTitle(task),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.bodySmall,
          ),
        ),
      ),
    );
  }
}

/// Tasks on their due date, Month | Week. Drag a task to another day to move
/// it; tasks with no date sit in the "No date" tray. [compact] (a phone) is
/// an agenda list with "Move date" on each task.
class TaskBoardCalendar extends StatefulWidget {
  const TaskBoardCalendar({
    super.key,
    required this.tasks,
    required this.canEdit,
    required this.colourOf,
    required this.onOpen,
    required this.onMoveDate,
    required this.formats,
    this.compact = false,
  });

  final DisplayFormats formats;
  final List<PlanTask> tasks;
  final bool canEdit;
  final Color Function(PlanTask) colourOf;
  final void Function(PlanTask) onOpen;
  final Future<void> Function(PlanTask, String) onMoveDate;
  final bool compact;

  @override
  State<TaskBoardCalendar> createState() => _TaskBoardCalendarState();
}

class _TaskBoardCalendarState extends State<TaskBoardCalendar> {
  bool _week = false;
  late DateTime _anchor = _today();
  final Set<DateTime> _opened = {};

  Map<DateTime, List<PlanTask>> get _byDay {
    final out = <DateTime, List<PlanTask>>{};
    for (final t in widget.tasks) {
      final d = planDay(t['due_on']);
      if (d != null) (out[d] ??= []).add(t);
    }
    return out;
  }

  List<PlanTask> get _undated => [
    for (final t in widget.tasks)
      if (planDay(t['due_on']) == null) t,
  ];

  /// The task whose "Move date" field is open on a phone, and its field.
  String? _moving;
  final _movingDay = TextEditingController();

  @override
  void dispose() {
    _movingDay.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) =>
      widget.compact ? _agenda(context) : _grid(context);

  Widget _agenda(BuildContext context) {
    final theme = Theme.of(context);
    final byDay = _byDay;
    final days = byDay.keys.where((d) => !d.isBefore(_today())).toList()
      ..sort();
    final earlier = byDay.keys.where((d) => d.isBefore(_today())).toList()
      ..sort();
    Widget row(PlanTask t) => Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _agendaTile(t),
        if (_moving == t['id'])
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: CalendarDayField(
              key: ValueKey('plan-move-day-${t['id']}'),
              controller: _movingDay,
              formats: widget.formats,
              dense: true,
              onChanged: (v) {
                if (RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(v)) {
                  setState(() => _moving = null);
                  widget.onMoveDate(t, v);
                }
              },
            ),
          ),
      ],
    );
    Widget heading(String s) => Padding(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 4),
      child: Text(s, style: theme.textTheme.titleSmall),
    );
    String name(DateTime d) => d == _today()
        ? 'Today · ${shortWeekdays[d.weekday - 1]} ${_short(d)}'
        : '${shortWeekdays[d.weekday - 1]} ${_short(d)}';
    return ListView(
      key: const Key('plan-agenda'),
      children: [
        if (earlier.isNotEmpty) ...[
          heading('Earlier'),
          for (final d in earlier) ...[for (final t in byDay[d]!) row(t)],
        ],
        for (final d in days) ...[
          heading(name(d)),
          for (final t in byDay[d]!) row(t),
        ],
        if (_undated.isNotEmpty) ...[
          heading('No date (${_undated.length})'),
          for (final t in _undated) row(t),
        ],
        if (widget.tasks.isEmpty)
          const Padding(
            padding: EdgeInsets.all(24),
            child: Text('No tasks on this board yet.'),
          ),
      ],
    );
  }

  Widget _agendaTile(PlanTask t) => ListTile(
    key: ValueKey('plan-row-${t['id']}'),
    dense: true,
    leading: Container(width: 4, height: 28, color: widget.colourOf(t)),
    title: Text(taskTitle(t), maxLines: 1, overflow: TextOverflow.ellipsis),
    onTap: () => widget.onOpen(t),
    trailing: widget.canEdit
        ? TextButton(
            key: ValueKey('plan-move-${t['id']}'),
            onPressed: () => setState(() {
              _moving = _moving == t['id'] ? null : t['id'] as String;
              _movingDay.text = '${t['due_on'] ?? ''}';
            }),
            child: const Text('Move date'),
          )
        : null,
  );

  Widget _grid(BuildContext context) {
    final theme = Theme.of(context);
    final byDay = _byDay;
    final DateTime start;
    final int count;
    if (_week) {
      start = _anchor.subtract(Duration(days: _anchor.weekday - 1));
      count = 7;
    } else {
      final first = DateTime(_anchor.year, _anchor.month);
      start = first.subtract(Duration(days: first.weekday - 1));
      final last = DateTime(_anchor.year, _anchor.month + 1, 0);
      final end = last.add(Duration(days: 7 - last.weekday));
      count = end.difference(start).inDays + 1;
    }
    void step(int dir) => setState(() {
      _anchor = _week
          ? _anchor.add(Duration(days: 7 * dir))
          : DateTime(_anchor.year, _anchor.month + dir);
    });

    Widget day(DateTime d) {
      final tasks = byDay[d] ?? const <PlanTask>[];
      final open = _opened.contains(d);
      final shown = open || _week ? tasks : tasks.take(3).toList();
      final outside = !_week && d.month != _anchor.month;
      final today = d == _today();
      return DragTarget<PlanTask>(
        key: ValueKey('plan-day-${formatCalendarDay(d)}'),
        onWillAcceptWithDetails: (_) => widget.canEdit,
        onAcceptWithDetails: (det) {
          if (planDay(det.data['due_on']) != d) {
            widget.onMoveDate(det.data, formatCalendarDay(d));
          }
        },
        builder: (context, hover, _) => Container(
          padding: const EdgeInsets.all(4),
          decoration: BoxDecoration(
            color: hover.isNotEmpty
                ? theme.colorScheme.primary.withValues(alpha: 0.08)
                : outside
                ? theme.colorScheme.surfaceContainerHighest.withValues(
                    alpha: 0.4,
                  )
                : theme.colorScheme.surface,
            border: Border.all(color: theme.dividerColor, width: 0.5),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                today ? '${d.day} · today' : '${d.day}',
                style: theme.textTheme.labelSmall?.copyWith(
                  fontWeight: today ? FontWeight.w800 : null,
                  color: today ? theme.colorScheme.primary : null,
                ),
              ),
              const SizedBox(height: 2),
              Expanded(
                child: ListView(
                  padding: EdgeInsets.zero,
                  children: [
                    for (final t in shown)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 2),
                        child: _draggable(t),
                      ),
                    if (shown.length < tasks.length)
                      InkWell(
                        key: ValueKey('plan-more-${formatCalendarDay(d)}'),
                        onTap: () => setState(() => _opened.add(d)),
                        child: Text(
                          '+${tasks.length - shown.length} more',
                          style: theme.textTheme.labelSmall,
                        ),
                      ),
                  ],
                ),
              ),
            ],
          ),
        ),
      );
    }

    final undated = _undated;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              SegmentedButton<bool>(
                key: const Key('plan-calendar-scale'),
                showSelectedIcon: false,
                segments: const [
                  ButtonSegment(value: false, label: Text('Month')),
                  ButtonSegment(value: true, label: Text('Week')),
                ],
                selected: {_week},
                onSelectionChanged: (v) => setState(() => _week = v.first),
              ),
              const SizedBox(width: 8),
              MenuAnchor(
                menuChildren: [
                  for (final t in undated)
                    MenuItemButton(
                      onPressed: () => widget.onOpen(t),
                      child: Text(taskTitle(t)),
                    ),
                ],
                builder: (context, c, _) => OutlinedButton(
                  key: const Key('plan-undated'),
                  onPressed: undated.isEmpty
                      ? null
                      : () => c.isOpen ? c.close() : c.open(),
                  child: Text('No date (${undated.length})'),
                ),
              ),
              const Spacer(),
              IconButton(
                key: const Key('plan-prev'),
                tooltip: 'Earlier',
                onPressed: () => step(-1),
                icon: const Icon(Icons.chevron_left),
              ),
              Text(
                _week
                    ? 'Week of ${_short(start)}'
                    : '${longMonths[_anchor.month - 1]} ${_anchor.year}',
                key: const Key('plan-period'),
                style: theme.textTheme.titleSmall,
              ),
              IconButton(
                key: const Key('plan-next'),
                tooltip: 'Later',
                onPressed: () => step(1),
                icon: const Icon(Icons.chevron_right),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Row(
            children: [
              for (final w in shortWeekdays)
                Expanded(
                  child: Text(
                    w,
                    textAlign: TextAlign.center,
                    style: theme.textTheme.labelSmall,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 4),
          Expanded(
            child: Column(
              children: [
                for (var r = 0; r < count ~/ 7; r++)
                  Expanded(
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        for (var c = 0; c < 7; c++)
                          Expanded(
                            child: day(
                              DateTime(
                                start.year,
                                start.month,
                                start.day + r * 7 + c,
                              ),
                            ),
                          ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _draggable(PlanTask t) {
    final chip = _Chip(
      task: t,
      colour: widget.colourOf(t),
      onTap: () => widget.onOpen(t),
    );
    if (!widget.canEdit) return chip;
    return Draggable<PlanTask>(
      data: t,
      feedback: Material(
        color: Colors.transparent,
        child: SizedBox(width: 160, child: chip),
      ),
      childWhenDragging: Opacity(opacity: 0.4, child: chip),
      child: chip,
    );
  }
}

/// Each task's range from the board's Timeline column, a row per task under
/// its group, a today line, Week | Month | Quarter. Bars drag to move and
/// their right edge drags to resize; [compact] scrolls sideways, no drag.
class TaskBoardTimeline extends StatefulWidget {
  const TaskBoardTimeline({
    super.key,
    required this.groups,
    required this.tasksIn,
    required this.columnId,
    required this.canEdit,
    required this.colourOf,
    required this.onOpen,
    required this.onSetRange,
    this.onAddColumn,
    this.compact = false,
  });

  final List<Map<String, dynamic>> groups;
  final List<PlanTask> Function(Object? groupId) tasksIn;

  /// The board's first Timeline column; null shows how to add one.
  final String? columnId;
  final bool canEdit;
  final Color Function(PlanTask) colourOf;
  final void Function(PlanTask) onOpen;
  final Future<void> Function(PlanTask, String from, String to) onSetRange;
  final VoidCallback? onAddColumn;
  final bool compact;

  @override
  State<TaskBoardTimeline> createState() => _TaskBoardTimelineState();
}

class _TaskBoardTimelineState extends State<TaskBoardTimeline> {
  static const _scales = {'Week': 40.0, 'Month': 14.0, 'Quarter': 5.0};
  String _scale = 'Month';

  /// A bar being dragged: its task id and the days moved / added so far.
  ({String id, int move, int grow})? _drag;

  (DateTime, DateTime)? _range(PlanTask t) {
    final v = (t['values'] as Map?)?[widget.columnId];
    if (v is! Map) return null;
    final from = planDay(v['from']), to = planDay(v['to']);
    return from == null || to == null ? null : (from, to);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (widget.columnId == null) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'Add a Timeline column to plan tasks from one date to another.',
            ),
            if (widget.canEdit && widget.onAddColumn != null) ...[
              const SizedBox(height: 10),
              FilledButton.icon(
                key: const Key('plan-add-timeline'),
                onPressed: widget.onAddColumn,
                icon: const Icon(Icons.add, size: 18),
                label: const Text('Add Timeline column'),
              ),
            ],
          ],
        ),
      );
    }

    final dayW = _scales[_scale]!;
    final today = _today();
    var first = today.subtract(const Duration(days: 7));
    var last = today.add(const Duration(days: 60));
    for (final g in widget.groups) {
      for (final t in widget.tasksIn(g['id'])) {
        final r = _range(t);
        if (r == null) continue;
        if (r.$1.isBefore(first)) first = r.$1;
        if (r.$2.isAfter(last)) last = r.$2;
      }
    }
    final days = last.difference(first).inDays + 1;
    final width = days * dayW;
    const nameW = 200.0, rowH = 34.0;
    double x(DateTime d) => d.difference(first).inDays * dayW;

    Widget bar(PlanTask t) {
      final r = _range(t);
      if (r == null) return const SizedBox(height: rowH);
      final d = _drag?.id == t['id'] ? _drag! : null;
      final from = r.$1.add(Duration(days: d?.move ?? 0));
      var to = r.$2.add(Duration(days: (d?.move ?? 0) + (d?.grow ?? 0)));
      if (to.isBefore(from)) to = from;
      final colour = widget.colourOf(t);
      // A board picks its own status colours: dark ink on a light bar.
      final ink =
          ThemeData.estimateBrightnessForColor(colour) == Brightness.dark
          ? Colors.white
          : const Color(0xFF1F2328);
      final drag = widget.canEdit && !widget.compact;
      void commit() {
        final cur = _drag;
        setState(() => _drag = null);
        if (cur != null && (cur.move != 0 || cur.grow != 0)) {
          widget.onSetRange(t, formatCalendarDay(from), formatCalendarDay(to));
        }
      }

      final barW = (to.difference(from).inDays + 1) * dayW;
      final range = from == to ? _short(from) : '${_short(from)}–${_short(to)}';
      // A short bar wore a clipped "Nov" (hands-on QA 11-16): its dates go
      // beside it instead, as Monday does.
      final outside = barW < 96;
      return SizedBox(
        height: rowH,
        child: Stack(
          clipBehavior: Clip.none,
          children: [
            if (outside)
              Positioned(
                left: x(from) + barW + 6,
                top: 0,
                bottom: 0,
                child: Center(
                  child: Text(
                    range,
                    key: ValueKey('plan-bar-label-${t['id']}'),
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: theme.colorScheme.onSurfaceVariant,
                    ),
                  ),
                ),
              ),
            Positioned(
              left: x(from),
              top: 6,
              width: barW,
              height: rowH - 12,
              child: GestureDetector(
                key: ValueKey('plan-bar-${t['id']}'),
                // Counts from the press, so a bar moves exactly as far as the pointer.
                dragStartBehavior: DragStartBehavior.down,
                onTap: () => widget.onOpen(t),
                onHorizontalDragUpdate: drag
                    ? (e) => setState(() {
                        _px += e.delta.dx;
                        _drag = (
                          id: t['id'] as String,
                          move: (_px / dayW).round(),
                          grow: 0,
                        );
                      })
                    : null,
                onHorizontalDragStart: drag ? (_) => _px = 0 : null,
                onHorizontalDragEnd: drag ? (_) => commit() : null,
                child: Container(
                  decoration: BoxDecoration(
                    color: colour,
                    borderRadius: BorderRadius.circular(6),
                  ),
                  alignment: Alignment.centerLeft,
                  padding: const EdgeInsets.symmetric(horizontal: 6),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          outside ? '' : range,
                          maxLines: 1,
                          overflow: TextOverflow.clip,
                          style: theme.textTheme.labelSmall?.copyWith(
                            color: ink,
                          ),
                        ),
                      ),
                      if (drag)
                        GestureDetector(
                          key: ValueKey('plan-bar-end-${t['id']}'),
                          dragStartBehavior: DragStartBehavior.down,
                          behavior: HitTestBehavior.opaque,
                          onHorizontalDragStart: (_) => _px = 0,
                          onHorizontalDragUpdate: (e) => setState(() {
                            _px += e.delta.dx;
                            _drag = (
                              id: t['id'] as String,
                              move: 0,
                              grow: (_px / dayW).round(),
                            );
                          }),
                          onHorizontalDragEnd: (_) => commit(),
                          child: MouseRegion(
                            cursor: SystemMouseCursors.resizeLeftRight,
                            child: SizedBox(
                              width: 10,
                              height: 22,
                              child: Icon(
                                Icons.drag_indicator,
                                size: 12,
                                color: ink,
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      );
    }

    final rows = <(Widget, Widget)>[];
    for (final g in widget.groups) {
      final tasks = widget.tasksIn(g['id']);
      if (tasks.isEmpty) continue;
      rows.add((
        Padding(
          padding: const EdgeInsets.fromLTRB(8, 10, 8, 4),
          child: Text('${g['name']}', style: theme.textTheme.titleSmall),
        ),
        const SizedBox(height: 38),
      ));
      for (final t in tasks) {
        rows.add((
          SizedBox(
            height: rowH,
            child: InkWell(
              onTap: () => widget.onOpen(t),
              child: Align(
                alignment: Alignment.centerLeft,
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 8),
                  child: Text(
                    taskTitle(t),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ),
            ),
          ),
          bar(t),
        ));
      }
    }

    // Month labels along the top.
    final labels = <Widget>[];
    for (
      var d = DateTime(first.year, first.month);
      !d.isAfter(last);
      d = DateTime(d.year, d.month + 1)
    ) {
      final at = d.isBefore(first) ? first : d;
      // A part month at the left edge is labelled only if its label fits
      // before the next month's; otherwise the two drew on top of each other.
      if (d.isBefore(first) &&
          x(DateTime(d.year, d.month + 1)) - x(first) < 110) {
        continue;
      }
      labels.add(
        Positioned(
          left: x(at) + 4,
          top: 4,
          child: Text(
            '${longMonths[d.month - 1]} ${d.year}',
            style: theme.textTheme.labelSmall,
          ),
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 6),
          child: Align(
            alignment: Alignment.centerLeft,
            child: SegmentedButton<String>(
              key: const Key('plan-timeline-scale'),
              showSelectedIcon: false,
              segments: [
                for (final s in _scales.keys)
                  ButtonSegment(value: s, label: Text(s)),
              ],
              selected: {_scale},
              onSelectionChanged: (v) => setState(() => _scale = v.first),
            ),
          ),
        ),
        Expanded(
          child: SingleChildScrollView(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SizedBox(
                  width: widget.compact ? 130 : nameW,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const SizedBox(height: 24),
                      for (final r in rows) r.$1,
                    ],
                  ),
                ),
                Expanded(
                  child: SingleChildScrollView(
                    key: const Key('plan-timeline-scroll'),
                    scrollDirection: Axis.horizontal,
                    child: SizedBox(
                      width: width,
                      child: Stack(
                        children: [
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              const SizedBox(height: 24),
                              for (final r in rows) r.$2,
                            ],
                          ),
                          ...labels,
                          Positioned(
                            key: const Key('plan-today'),
                            left: x(today) + dayW / 2,
                            top: 0,
                            bottom: 0,
                            child: Container(
                              width: 2,
                              color: theme.colorScheme.primary,
                            ),
                          ),
                        ],
                      ),
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

  double _px = 0;
}
