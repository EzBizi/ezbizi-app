import 'package:flutter/material.dart';

import '../../core/theme.dart';
import 'task_models.dart';

/// Attempts to write new dates for [task]. Returns `null` on success, or the
/// server's refusal sentence on failure — THE PHASE 1 CORE-FIELD WRITE
/// (`TaskApi.updateTask` with `task.version` as `expected_version`), the
/// same shape `TaskCalendarMove`/`TaskKanbanMove` already reuse.
/// `TaskTimelineView` adds no write path of its own; it only ever displays
/// what this returns — including a `start_on` that lands after `due_on`,
/// which the server refuses and this widget never swaps or reorders on its
/// own.
///
/// Exactly one command, at one version, per drag:
///  * dragging one edge of a bar passes only that field ([startOn] XOR
///    [dueOn] is non-null) — the other date is left untouched;
///  * dragging the whole bar (or a single-date marker) passes both fields
///    together in the SAME call — for a marker, the untouched field is
///    always `null` because a marker has only one date to move.
typedef TaskTimelineMove =
    Future<String?> Function(
      TaskRow task, {
      DateTime? startOn,
      DateTime? dueOn,
    });

enum _TimelineDragKind { start, due, whole }

class _TimelineDrag {
  const _TimelineDrag(this.task, this.kind);
  final TaskRow task;
  final _TimelineDragKind kind;
}

/// The Timeline presentation (Task 12): a month-bounded Gantt — one row per
/// task with a `start_on` and/or `due_on`, drawn against a day axis, with
/// "blocks" edges (task-4 brief) drawn as lines between bars.
///
/// [month] and every date this widget hands back through [onMonthChanged] or
/// [onMove] is a plain calendar date — `DateTime.utc(year, month, day)`,
/// hour always zero — for exactly the reasons `TaskCalendarView`'s docblock
/// gives: `start_on`/`due_on` are plain `date` columns, `TaskRow.startOn`/
/// `.dueOn` already arrive as `DateTime.utc(y, m, d)`, and calling
/// `.toLocal()` anywhere in this file could only shift a date-only value
/// backward for a viewer behind UTC. Every comparison below reads
/// `.year`/`.month`/`.day` off the UTC value directly.
///
/// The server's window intersects rather than contains (task-12 brief): a
/// row can carry a `start_on` before [month]'s first day or a `due_on`
/// after its last, when the other end of its span still falls inside the
/// window. Rendering clamps such a bar's off-window edge to the grid
/// boundary rather than dropping it — the same "still show it, pinned"
/// choice a calendar or scheduling tool commonly makes for a span that runs
/// off either side of the visible range.
class TaskTimelineView extends StatefulWidget {
  const TaskTimelineView({
    super.key,
    required this.month,
    required this.rows,
    required this.omitted,
    required this.onMove,
    required this.onMonthChanged,
  });

  /// The displayed month. Only `.year`/`.month` are read; the day and any
  /// time component are ignored.
  final DateTime month;
  final List<TaskRow> rows;

  /// `meta.omitted` from the last `GET rows` response. Only `.undated` is
  /// shown here — for Timeline that means "carries neither `start_on` nor
  /// `due_on`" (task-12 brief), the one thing a task with neither date
  /// cannot be placed on this grid to show for itself.
  final TaskOmitted omitted;
  final TaskTimelineMove onMove;
  final ValueChanged<DateTime> onMonthChanged;

  @override
  State<TaskTimelineView> createState() => _TaskTimelineViewState();
}

class _TaskTimelineViewState extends State<TaskTimelineView> {
  static const _dayWidth = 32.0;
  static const _rowHeight = 44.0;
  static const _labelWidth = 200.0;
  static const _headerHeight = 26.0;
  static const _monthNames = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
  ];

  String? _dragError;
  String? _movingTaskId;

  /// Rows with neither date are never placed on the grid — only counted in
  /// [TaskTimelineView.omitted].
  List<TaskRow> get _visibleRows => widget.rows
      .where((row) => row.startOn != null || row.dueOn != null)
      .toList(growable: false);

  DateTime get _first => DateTime.utc(widget.month.year, widget.month.month, 1);

  List<DateTime> get _days {
    final daysInMonth =
        DateTime.utc(widget.month.year, widget.month.month + 1, 0).day;
    final first = _first;
    return [
      for (var d = 0; d < daysInMonth; d++)
        DateTime.utc(first.year, first.month, first.day + d),
    ];
  }

  /// The left edge x of the day cell [day] falls in, clamped into the
  /// visible grid — a `start_on` before [month]'s first day pins to 0.
  double _dayStartX(DateTime day, int dayCount) {
    final index = day.difference(_first).inDays.clamp(0, dayCount - 1);
    return index * _dayWidth;
  }

  /// The right edge x of the day cell [day] falls in (i.e. that cell's
  /// exclusive end), clamped into the visible grid — a `due_on` after
  /// [month]'s last day pins to the grid's right edge.
  double _dayEndX(DateTime day, int dayCount) {
    final index = (day.difference(_first).inDays + 1).clamp(0, dayCount);
    return index * _dayWidth;
  }

  bool _sameDay(DateTime a, DateTime b) =>
      a.year == b.year && a.month == b.month && a.day == b.day;

  (DateTime?, DateTime?) _wholeBarShift(TaskRow task, DateTime day) {
    final start = task.startOn;
    final due = task.dueOn;
    if (start == null || due == null) {
      // Unreachable: a "whole bar" drag is only ever attached to a bar,
      // which by definition carries both dates.
      return (null, null);
    }
    if (_sameDay(start, day)) return (null, null);
    final span = due.difference(start).inDays;
    return (day, DateTime.utc(day.year, day.month, day.day + span));
  }

  Future<void> _handleDrop(_TimelineDrag drag, DateTime day) async {
    final task = drag.task;
    final (DateTime? newStart, DateTime? newDue) = switch (drag.kind) {
      _TimelineDragKind.start =>
        task.startOn != null && _sameDay(task.startOn!, day)
            ? (null, null)
            : (day, null),
      _TimelineDragKind.due =>
        task.dueOn != null && _sameDay(task.dueOn!, day)
            ? (null, null)
            : (null, day),
      _TimelineDragKind.whole => _wholeBarShift(task, day),
    };
    if (newStart == null && newDue == null) return;
    setState(() {
      _movingTaskId = task.id;
      _dragError = null;
    });
    final message = await widget.onMove(task, startOn: newStart, dueOn: newDue);
    if (!mounted) return;
    setState(() {
      _movingTaskId = null;
      _dragError = message;
    });
  }

  void _changeMonth(int delta) {
    final month = widget.month.month + delta;
    widget.onMonthChanged(DateTime.utc(widget.month.year, month, 1));
  }

  @override
  Widget build(BuildContext context) {
    final rows = _visibleRows;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _header(context),
        if (_dragError case final message?) _errorBanner(context, message),
        _omittedBanner(context),
        Expanded(
          child: rows.isEmpty
              ? const Center(
                  child: Text('No tasks with a start or due date in this range.'),
                )
              : SingleChildScrollView(
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _labelColumn(context, rows),
                      Expanded(
                        child: SingleChildScrollView(
                          scrollDirection: Axis.horizontal,
                          child: _grid(context, rows),
                        ),
                      ),
                    ],
                  ),
                ),
        ),
      ],
    );
  }

  Widget _header(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
    child: Row(
      children: [
        IconButton(
          tooltip: 'Previous month',
          icon: const Icon(Icons.chevron_left),
          onPressed: () => _changeMonth(-1),
        ),
        Expanded(
          child: Text(
            '${_monthNames[widget.month.month - 1]} ${widget.month.year}',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.titleMedium,
          ),
        ),
        IconButton(
          tooltip: 'Next month',
          icon: const Icon(Icons.chevron_right),
          onPressed: () => _changeMonth(1),
        ),
      ],
    ),
  );

  Widget _errorBanner(BuildContext context, String message) {
    final scheme = Theme.of(context).colorScheme;
    return Material(
      color: scheme.errorContainer,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        child: Row(
          children: [
            Expanded(
              child: Text(message, style: TextStyle(color: scheme.onErrorContainer)),
            ),
            IconButton(
              tooltip: 'Dismiss',
              icon: Icon(Icons.close, color: scheme.onErrorContainer),
              onPressed: () => setState(() => _dragError = null),
            ),
          ],
        ),
      ),
    );
  }

  /// Always renders, including at zero — matches `TaskCalendarView`'s
  /// `_omittedBanner`: silence here would read as "nothing is undated",
  /// exactly wrong when the board has tasks with neither date.
  Widget _omittedBanner(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final count = widget.omitted.undated;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      child: Text(
        key: const ValueKey('timeline-undated-count'),
        count == 1
            ? '1 task has no start or due date and is not shown here.'
            : '$count tasks have no start or due date and are not shown here.',
        style: TextStyle(color: scheme.onSurfaceVariant, fontSize: 12),
      ),
    );
  }

  Widget _labelColumn(BuildContext context, List<TaskRow> rows) {
    final scheme = Theme.of(context).colorScheme;
    return SizedBox(
      width: _labelWidth,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Aligns with the day-header row on the grid side.
          const SizedBox(height: _headerHeight),
          for (final row in rows)
            Container(
              key: ValueKey('timeline-label-${row.id}'),
              height: _rowHeight,
              alignment: Alignment.centerLeft,
              padding: const EdgeInsets.symmetric(horizontal: 8),
              decoration: BoxDecoration(
                border: Border(bottom: BorderSide(color: EzBiziTheme.edge(context))),
              ),
              child: Text(
                '${row.number} ${row.title}',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(fontSize: 12, color: scheme.onSurface),
              ),
            ),
        ],
      ),
    );
  }

  Widget _grid(BuildContext context, List<TaskRow> rows) {
    final days = _days;
    final width = days.length * _dayWidth;
    final indexById = <String, int>{
      for (var i = 0; i < rows.length; i++) rows[i].id: i,
    };
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _dayHeaderRow(context, days, width),
        SizedBox(
          width: width,
          height: rows.length * _rowHeight,
          child: Stack(
            children: [
              for (var i = 0; i < rows.length; i++)
                Positioned(
                  top: i * _rowHeight,
                  left: 0,
                  child: _dayTrackRow(rows[i], days),
                ),
              for (final line in _dependencyLines(context, rows, indexById, days))
                line,
              for (var i = 0; i < rows.length; i++)
                _barOrMarker(rows[i], i, days),
            ],
          ),
        ),
      ],
    );
  }

  Widget _dayHeaderRow(BuildContext context, List<DateTime> days, double width) {
    final scheme = Theme.of(context).colorScheme;
    return SizedBox(
      height: _headerHeight,
      width: width,
      child: Row(
        children: [
          for (final day in days)
            SizedBox(
              width: _dayWidth,
              child: Center(
                child: Text(
                  '${day.day}',
                  style: TextStyle(fontSize: 10, color: scheme.onSurfaceVariant),
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _dayTrackRow(TaskRow task, List<DateTime> days) => Row(
    children: [
      for (final day in days)
        _DayCell(
          key: ValueKey('timeline-day-${task.id}-${_keyDate(day)}'),
          width: _dayWidth,
          height: _rowHeight,
          onDropped: (drag) => _handleDrop(drag, day),
        ),
    ],
  );

  Widget _barOrMarker(TaskRow task, int rowIndex, List<DateTime> days) {
    final top = rowIndex * _rowHeight;
    final dragging = _movingTaskId == task.id;
    if (task.startOn != null && task.dueOn != null) {
      final left = _dayStartX(task.startOn!, days.length);
      final right = _dayEndX(task.dueOn!, days.length);
      final width = right - left < _dayWidth ? _dayWidth : right - left;
      return Positioned(
        top: top + 4,
        left: left,
        width: width,
        height: _rowHeight - 8,
        child: TaskTimelineBar(
          key: ValueKey('timeline-bar-${task.id}'),
          task: task,
          dragging: dragging,
        ),
      );
    }
    final isDue = task.dueOn != null;
    final date = task.dueOn ?? task.startOn!;
    final left = _dayStartX(date, days.length);
    return Positioned(
      top: top + 4,
      left: left,
      width: _dayWidth,
      height: _rowHeight - 8,
      child: TaskTimelineMarker(
        key: ValueKey('timeline-marker-${task.id}'),
        task: task,
        isDue: isDue,
        dragging: dragging,
      ),
    );
  }

  /// One line per blocking edge whose blocker is ALSO visible in the
  /// current window — a blocker outside [month]'s range has nowhere on
  /// this grid to anchor a line, so that edge draws nothing rather than
  /// guessing a position for it.
  List<Widget> _dependencyLines(
    BuildContext context,
    List<TaskRow> rows,
    Map<String, int> indexById,
    List<DateTime> days,
  ) {
    final color = Theme.of(context).colorScheme.outline;
    final lines = <Widget>[];
    for (var i = 0; i < rows.length; i++) {
      final task = rows[i];
      for (final ref in task.blockedBy ?? const <TaskBlockedByRef>[]) {
        final blockerIndex = indexById[ref.taskId];
        if (blockerIndex == null) continue;
        final blocker = rows[blockerIndex];
        final blockerEnd = blocker.dueOn ?? blocker.startOn!;
        final blockedStart = task.startOn ?? task.dueOn!;
        final x1 = _dayEndX(blockerEnd, days.length);
        final y1 = blockerIndex * _rowHeight + _rowHeight / 2;
        final x2 = _dayStartX(blockedStart, days.length);
        final y2 = i * _rowHeight + _rowHeight / 2;
        lines.add(
          TaskTimelineDependencyLine(
            key: ValueKey('timeline-dependency-${ref.taskId}-${task.id}'),
            from: Offset(x1, y1),
            to: Offset(x2, y2),
            color: color,
          ),
        );
      }
    }
    return lines;
  }
}

class _DayCell extends StatelessWidget {
  const _DayCell({
    super.key,
    required this.width,
    required this.height,
    required this.onDropped,
  });

  final double width;
  final double height;
  final ValueChanged<_TimelineDrag> onDropped;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return DragTarget<_TimelineDrag>(
      onWillAcceptWithDetails: (_) => true,
      onAcceptWithDetails: (details) => onDropped(details.data),
      builder: (context, candidates, rejected) => Container(
        width: width,
        height: height,
        decoration: BoxDecoration(
          color: candidates.isNotEmpty
              ? scheme.primaryContainer.withValues(alpha: 0.35)
              : Colors.transparent,
          border: Border(
            right: BorderSide(color: EzBiziTheme.edge(context)),
            bottom: BorderSide(color: EzBiziTheme.edge(context)),
          ),
        ),
      ),
    );
  }
}

/// Both dates present: a BAR from `start_on` to `due_on`. A distinct widget
/// TYPE from [TaskTimelineMarker] — a bar and a marker never render as the
/// same shape in the tree, only differently keyed instances of one.
///
/// Three independent drag handles live inside: the left strip moves only
/// `start_on`, the right strip moves only `due_on`, and the body moves both
/// together (preserving the span) in one command.
class TaskTimelineBar extends StatelessWidget {
  const TaskTimelineBar({super.key, required this.task, required this.dragging});

  final TaskRow task;
  final bool dragging;

  // The bar itself, always at full opacity — the label lives inside it, and
  // transparency must never be how a state is shown on the element carrying
  // text (see the class-level rationale below). "In motion" is signalled by
  // a 2px outline plus a touch of elevation instead of a faded fill; at 50%
  // alpha the fill used to composite to roughly #7FD6F7, putting white text
  // on it at ~1.6:1 contrast right when a user most needed to read it.
  //
  // The outline is `scheme.surface`, not `scheme.primary` — a border drawn
  // in the same colour as the fill it sits on paints over itself and reads
  // as no border at all, the identical mistake as the alpha fade this
  // replaces, just one layer down: an indicator with zero contrast against
  // its own background. `TaskTimelineMarker` below already gets this right
  // (`Border.all(color: scheme.surface, width: 2)` against a coloured
  // fill); this follows the same pattern.
  Widget _bar(ColorScheme scheme, {required bool marked}) => Material(
    color: Colors.transparent,
    elevation: marked ? 3 : 0,
    borderRadius: BorderRadius.circular(6),
    child: Container(
      decoration: BoxDecoration(
        color: scheme.primary,
        borderRadius: BorderRadius.circular(6),
        border: marked ? Border.all(color: scheme.surface, width: 2) : null,
      ),
      alignment: Alignment.centerLeft,
      padding: const EdgeInsets.symmetric(horizontal: 6),
      child: Text(
        task.title,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: TextStyle(
          color: scheme.onPrimary,
          fontSize: 11,
          fontWeight: FontWeight.w600,
        ),
      ),
    ),
  );

  // The placeholder left in the bar's own slot while a floating copy
  // follows the pointer. It carries no text — the floating copy is what
  // tells the user which task they are holding — so dimming this one costs
  // nothing.
  Widget _ghost(ColorScheme scheme) => Opacity(
    opacity: 0.3,
    child: Container(
      decoration: BoxDecoration(
        color: scheme.primary,
        borderRadius: BorderRadius.circular(6),
      ),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final body = _bar(scheme, marked: dragging);
    return Stack(
      clipBehavior: Clip.none,
      children: [
        // `Positioned.fill` — without it a bare `Draggable` here shrink-wraps
        // to the title text's own width (a `Stack`'s non-positioned children
        // get loose constraints), leaving it narrower than the bar and, for
        // a short title, potentially only as wide as the edge handles
        // themselves. Filling the bar guarantees the body drag's hit area
        // is the whole span between the two edge handles, not whatever the
        // text happened to measure.
        Positioned.fill(
          child: Draggable<_TimelineDrag>(
            key: ValueKey('timeline-bar-body-${task.id}'),
            data: _TimelineDrag(task, _TimelineDragKind.whole),
            feedback: Material(
              color: Colors.transparent,
              child: SizedBox(
                width: 140,
                child: _bar(scheme, marked: true),
              ),
            ),
            childWhenDragging: _ghost(scheme),
            child: body,
          ),
        ),
        Positioned(
          left: 0,
          top: 0,
          bottom: 0,
          width: 10,
          child: Draggable<_TimelineDrag>(
            key: ValueKey('timeline-bar-start-handle-${task.id}'),
            data: _TimelineDrag(task, _TimelineDragKind.start),
            feedback: const SizedBox(width: 4, height: 20),
            childWhenDragging: const SizedBox.shrink(),
            child: MouseRegion(
              cursor: SystemMouseCursors.resizeLeftRight,
              child: Container(color: Colors.transparent),
            ),
          ),
        ),
        Positioned(
          right: 0,
          top: 0,
          bottom: 0,
          width: 10,
          child: Draggable<_TimelineDrag>(
            key: ValueKey('timeline-bar-due-handle-${task.id}'),
            data: _TimelineDrag(task, _TimelineDragKind.due),
            feedback: const SizedBox(width: 4, height: 20),
            childWhenDragging: const SizedBox.shrink(),
            child: MouseRegion(
              cursor: SystemMouseCursors.resizeLeftRight,
              child: Container(color: Colors.transparent),
            ),
          ),
        ),
      ],
    );
  }
}

/// Exactly one date present: a MARKER on that date. Distinct widget TYPE
/// from [TaskTimelineBar]. [isDue] selects which single field a drag on
/// this marker writes — a marker never has a second date to also move.
class TaskTimelineMarker extends StatelessWidget {
  const TaskTimelineMarker({
    super.key,
    required this.task,
    required this.isDue,
    required this.dragging,
  });

  final TaskRow task;
  final bool isDue;
  final bool dragging;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final color = isDue ? scheme.tertiary : scheme.secondary;
    final body = Center(
      child: Container(
        width: 14,
        height: 14,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: color.withValues(alpha: dragging ? 0.5 : 1),
          border: Border.all(color: scheme.surface, width: 2),
        ),
      ),
    );
    return Draggable<_TimelineDrag>(
      data: _TimelineDrag(task, isDue ? _TimelineDragKind.due : _TimelineDragKind.start),
      feedback: Material(
        color: Colors.transparent,
        child: SizedBox(width: 20, height: 20, child: body),
      ),
      childWhenDragging: Opacity(opacity: 0.3, child: body),
      child: Tooltip(message: task.title, child: body),
    );
  }
}

/// A "blocks" edge (task-4 brief), drawn as a line between the blocker's
/// bar/marker and the blocked task's — display only: [TaskTimelineView]
/// never lets one of these affect a date. Wrapped in [IgnorePointer] so it
/// never shadows a day-cell drop target it happens to be painted over.
class TaskTimelineDependencyLine extends StatelessWidget {
  const TaskTimelineDependencyLine({
    super.key,
    required this.from,
    required this.to,
    required this.color,
  });

  final Offset from;
  final Offset to;
  final Color color;

  static const _pad = 6.0;

  @override
  Widget build(BuildContext context) {
    final left = (from.dx < to.dx ? from.dx : to.dx) - _pad;
    final top = (from.dy < to.dy ? from.dy : to.dy) - _pad;
    final width = (to.dx - from.dx).abs() + _pad * 2;
    final height = (to.dy - from.dy).abs() + _pad * 2;
    return Positioned(
      left: left,
      top: top,
      width: width,
      height: height,
      child: IgnorePointer(
        child: CustomPaint(
          painter: _DependencyLinePainter(
            from: Offset(from.dx - left, from.dy - top),
            to: Offset(to.dx - left, to.dy - top),
            color: color,
          ),
        ),
      ),
    );
  }
}

class _DependencyLinePainter extends CustomPainter {
  const _DependencyLinePainter({
    required this.from,
    required this.to,
    required this.color,
  });

  final Offset from;
  final Offset to;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..strokeWidth = 1.5
      ..style = PaintingStyle.stroke;
    canvas.drawLine(from, to, paint);
    final direction = (to - from).direction;
    const arrow = 5.0;
    canvas.drawLine(to, to - Offset.fromDirection(direction - 0.5, arrow), paint);
    canvas.drawLine(to, to - Offset.fromDirection(direction + 0.5, arrow), paint);
  }

  @override
  bool shouldRepaint(covariant _DependencyLinePainter oldDelegate) =>
      oldDelegate.from != from || oldDelegate.to != to || oldDelegate.color != color;
}

String _keyDate(DateTime date) =>
    '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';
