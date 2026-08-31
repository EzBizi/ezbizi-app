import 'package:flutter/material.dart';

import '../../core/theme.dart';
import 'task_models.dart';

/// Attempts to move [task]'s due date to [day]. Returns `null` on success, or
/// the server's refusal sentence on failure — THE PHASE 1 DUE-DATE WRITE
/// (`TaskApi.updateTask` with `due_on` and `task.version` as
/// `expected_version`), the same shape `TaskKanbanMove` already reuses for
/// its own field. `TaskCalendarView` adds no write path of its own; it only
/// ever displays what this returns.
typedef TaskCalendarMove = Future<String?> Function(TaskRow task, DateTime day);

/// The Calendar presentation (Task 11): a month grid of tasks bucketed by
/// `due_on` alone — `start_on` plays no part here, that is Timeline's job
/// (Task 12).
///
/// [month] and every date this widget hands back through [onMonthChanged] or
/// [onMove] is a plain calendar date — `DateTime.utc(year, month, day)`,
/// hour always zero. That mirrors exactly how `TaskRow.dueOn` already
/// arrives: `due_on` is a plain `date` column (`TasksController` validates
/// writes as `date_format:Y-m-d` — no time or timezone is ever attached to
/// it), and `TaskModels._parseDateOnly` builds it from the wire's
/// `YYYY-MM-DD` string with `DateTime.utc(y, m, d)`, never `DateTime.parse`
/// and never `.toLocal()`. The value that reaches here is already the
/// correct calendar day; there is no wall-clock time or timezone on it left
/// to resolve. This widget's only obligation is to not invent one: every
/// comparison below reads `.year`/`.month`/`.day` off the UTC value
/// directly. Calling `.toLocal()` anywhere in this file could only corrupt
/// a date-only value — never correct it — by shifting it a day backward for
/// a viewer whose device runs behind UTC; that is the bug this file exists
/// to not have.
class TaskCalendarView extends StatefulWidget {
  const TaskCalendarView({
    super.key,
    required this.month,
    required this.rows,
    required this.omitted,
    this.onMove,
    required this.onMonthChanged,
    this.readOnly = false,
    this.boardNamesByTaskId,
  });

  /// The displayed month. Only `.year`/`.month` are read; the day and any
  /// time component are ignored.
  final DateTime month;
  final List<TaskRow> rows;

  /// `meta.omitted` from the last `GET rows` response (Ruling 17: present
  /// for every presentation). Only `.undated` is shown here — a task with no
  /// due date cannot appear on a month grid, and the count of how many were
  /// left out is the one thing this view is required to say out loud.
  final TaskOmitted omitted;

  /// `null` when [readOnly] is `true` — a cross-board caller (task-14 brief)
  /// never needs it, since [readOnly] also removes every drag surface that
  /// would call it.
  final TaskCalendarMove? onMove;
  final ValueChanged<DateTime> onMonthChanged;

  /// A cross-board Calendar (task-14 brief) is read-only: dragging a card
  /// would need the target board's write authorization resolved per card,
  /// and the honest version of that is opening the board. When `true`, no
  /// `Draggable`/`DragTarget` is built ANYWHERE in this widget's tree — not
  /// a disabled one, none — rather than merely ignoring drops.
  final bool readOnly;

  /// `null` for a single board's own Calendar (unchanged, no extra text on
  /// the card) — the cross-board caller (task-14 brief, fix round 1)
  /// passes a `task.id -> board name` map instead so a card can say which
  /// board it belongs to. Read-only removes the drag path that would
  /// normally answer "which board" by dropping the card there; without a
  /// board name a card on a shared grid gives no way to tell two same-
  /// titled tasks from different boards apart, or where to click through to.
  final Map<String, String>? boardNamesByTaskId;

  @override
  State<TaskCalendarView> createState() => _TaskCalendarViewState();
}

class _TaskCalendarViewState extends State<TaskCalendarView> {
  static const _narrowBreakpoint = 700.0;
  static const _weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  static const _monthNames = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
  ];

  String? _dragError;
  String? _movingTaskId;

  /// Tasks with a due date, bucketed by the UTC calendar day of [TaskRow.dueOn].
  /// Undated tasks are never placed here — they are never shown on the grid,
  /// only counted in [TaskCalendarView.omitted].
  Map<DateTime, List<TaskRow>> get _byDay {
    final map = <DateTime, List<TaskRow>>{};
    for (final row in widget.rows) {
      final due = row.dueOn;
      if (due == null) continue;
      final key = DateTime.utc(due.year, due.month, due.day);
      (map[key] ??= <TaskRow>[]).add(row);
    }
    return map;
  }

  /// One cell per weekday slot, `null` for the padding before day 1 and
  /// after the month's last day — always a multiple of 7 so full weeks
  /// render both as grid rows and as week-list sections.
  List<DateTime?> get _cells {
    final first = DateTime.utc(widget.month.year, widget.month.month, 1);
    final daysInMonth =
        DateTime.utc(widget.month.year, widget.month.month + 1, 0).day;
    final leading = first.weekday % 7; // Sunday = 0
    final cells = <DateTime?>[
      for (var i = 0; i < leading; i++) null,
      for (var day = 1; day <= daysInMonth; day++)
        DateTime.utc(first.year, first.month, day),
    ];
    while (cells.length % 7 != 0) {
      cells.add(null);
    }
    return cells;
  }

  Future<void> _handleDrop(TaskRow task, DateTime day) async {
    final onMove = widget.onMove;
    if (onMove == null) return;
    final due = task.dueOn;
    if (due != null &&
        due.year == day.year &&
        due.month == day.month &&
        due.day == day.day) {
      return;
    }
    setState(() {
      _movingTaskId = task.id;
      _dragError = null;
    });
    final message = await onMove(task, day);
    if (!mounted) return;
    setState(() {
      _movingTaskId = null;
      _dragError = message;
    });
  }

  void _changeMonth(int delta) {
    final month = widget.month.month + delta;
    widget.onMonthChanged(
      DateTime.utc(widget.month.year, month, 1),
    );
  }

  @override
  Widget build(BuildContext context) {
    final byDay = _byDay;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _header(context),
        if (_dragError case final message?) _errorBanner(context, message),
        _omittedBanner(context),
        Expanded(
          child: LayoutBuilder(
            builder: (context, constraints) =>
                constraints.maxWidth < _narrowBreakpoint
                ? _weekList(context, byDay)
                : _monthGrid(context, byDay),
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

  /// Always renders, including at zero — silence here would read as
  /// "nothing is undated", which is exactly wrong when a board has undated
  /// tasks this grid cannot show.
  Widget _omittedBanner(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final count = widget.omitted.undated;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      child: Text(
        key: const ValueKey('calendar-undated-count'),
        count == 1
            ? '1 task has no due date and is not shown here.'
            : '$count tasks have no due date and are not shown here.',
        style: TextStyle(color: scheme.onSurfaceVariant, fontSize: 12),
      ),
    );
  }

  // Wide: a 7-column month grid, weeks stacked vertically. Nothing here
  // scrolls horizontally — the grid always fits the available width.
  Widget _monthGrid(BuildContext context, Map<DateTime, List<TaskRow>> byDay) {
    final cells = _cells;
    final scheme = Theme.of(context).colorScheme;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            for (final label in _weekdayLabels)
              Expanded(
                child: Center(
                  child: Text(
                    label,
                    style: TextStyle(
                      color: scheme.onSurfaceVariant,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ),
          ],
        ),
        Expanded(
          child: Column(
            children: [
              for (var week = 0; week < cells.length; week += 7)
                Expanded(
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      for (final day in cells.sublist(week, week + 7))
                        Expanded(
                          child: day == null
                              ? const SizedBox.shrink()
                              : _DayCell(
                                  key: ValueKey('calendar-day-${_keyDate(day)}'),
                                  day: day,
                                  tasks: byDay[day] ?? const [],
                                  movingTaskId: _movingTaskId,
                                  onDropped: widget.readOnly
                                      ? null
                                      : (task) => _handleDrop(task, day),
                                  readOnly: widget.readOnly,
                                  boardNamesByTaskId: widget.boardNamesByTaskId,
                                ),
                        ),
                    ],
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }

  // Narrow: the month becomes a vertical, week-grouped list — the page body
  // scrolls only vertically at any width, 375px included.
  Widget _weekList(BuildContext context, Map<DateTime, List<TaskRow>> byDay) {
    final cells = _cells;
    final weeks = <List<DateTime?>>[
      for (var i = 0; i < cells.length; i += 7) cells.sublist(i, i + 7),
    ];
    return ListView.builder(
      key: const ValueKey('calendar-week-list'),
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      itemCount: weeks.length,
      itemBuilder: (context, index) {
        final week = weeks[index].whereType<DateTime>().toList(growable: false);
        if (week.isEmpty) return const SizedBox.shrink();
        return Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Text(
                  'Week of ${_monthNames[week.first.month - 1]} ${week.first.day}',
                  style: Theme.of(context).textTheme.labelLarge,
                ),
              ),
              for (final day in week)
                Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: _DayRow(
                    key: ValueKey('calendar-day-${_keyDate(day)}'),
                    day: day,
                    tasks: byDay[day] ?? const [],
                    movingTaskId: _movingTaskId,
                    onDropped: widget.readOnly
                        ? null
                        : (task) => _handleDrop(task, day),
                    readOnly: widget.readOnly,
                    boardNamesByTaskId: widget.boardNamesByTaskId,
                  ),
                ),
            ],
          ),
        );
      },
    );
  }
}

class _DayCell extends StatelessWidget {
  const _DayCell({
    super.key,
    required this.day,
    required this.tasks,
    required this.movingTaskId,
    required this.onDropped,
    this.readOnly = false,
    this.boardNamesByTaskId,
  });

  final DateTime day;
  final List<TaskRow> tasks;
  final String? movingTaskId;
  final ValueChanged<TaskRow>? onDropped;
  final bool readOnly;
  final Map<String, String>? boardNamesByTaskId;

  Widget _body(BuildContext context, {required bool highlighted}) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      margin: const EdgeInsets.all(2),
      decoration: BoxDecoration(
        color: highlighted
            ? scheme.primaryContainer.withValues(alpha: 0.35)
            : scheme.surfaceContainerLow,
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
            child: Text('${day.day}', style: TextStyle(color: scheme.onSurfaceVariant)),
          ),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.symmetric(horizontal: 4),
              children: [
                for (final task in tasks)
                  _CalendarCard(
                    key: ValueKey('calendar-card-${task.id}'),
                    task: task,
                    dragging: movingTaskId == task.id,
                    readOnly: readOnly,
                    boardName: boardNamesByTaskId?[task.id],
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (readOnly) return _body(context, highlighted: false);
    return DragTarget<TaskRow>(
      onWillAcceptWithDetails: (_) => true,
      onAcceptWithDetails: (details) => onDropped!(details.data),
      builder: (context, candidates, rejected) =>
          _body(context, highlighted: candidates.isNotEmpty),
    );
  }
}

class _DayRow extends StatelessWidget {
  const _DayRow({
    super.key,
    required this.day,
    required this.tasks,
    required this.movingTaskId,
    required this.onDropped,
    this.readOnly = false,
    this.boardNamesByTaskId,
  });

  final DateTime day;
  final List<TaskRow> tasks;
  final String? movingTaskId;
  final ValueChanged<TaskRow>? onDropped;
  final bool readOnly;
  final Map<String, String>? boardNamesByTaskId;

  static const _weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

  Widget _body(BuildContext context, {required bool highlighted}) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.all(6),
      decoration: BoxDecoration(
        color: highlighted
            ? scheme.primaryContainer.withValues(alpha: 0.35)
            : scheme.surfaceContainerLow,
        border: Border.all(color: EzBiziTheme.edge(context)),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            '${_weekdayLabels[day.weekday % 7]} ${day.day}',
            style: TextStyle(
              color: scheme.onSurfaceVariant,
              fontWeight: FontWeight.w600,
            ),
          ),
          if (tasks.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Text(
                'No tasks',
                style: TextStyle(color: scheme.onSurfaceVariant, fontSize: 12),
              ),
            )
          else
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final task in tasks)
                  _CalendarCard(
                    key: ValueKey('calendar-card-${task.id}'),
                    task: task,
                    dragging: movingTaskId == task.id,
                    readOnly: readOnly,
                    boardName: boardNamesByTaskId?[task.id],
                  ),
              ],
            ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (readOnly) return _body(context, highlighted: false);
    return DragTarget<TaskRow>(
      onWillAcceptWithDetails: (_) => true,
      onAcceptWithDetails: (details) => onDropped!(details.data),
      builder: (context, candidates, rejected) =>
          _body(context, highlighted: candidates.isNotEmpty),
    );
  }
}

class _CalendarCard extends StatelessWidget {
  const _CalendarCard({
    super.key,
    required this.task,
    required this.dragging,
    this.readOnly = false,
    this.boardName,
  });

  final TaskRow task;
  final bool dragging;
  final bool readOnly;

  /// `null` on a single board's own Calendar — only the cross-board caller
  /// (task-14 brief, fix round 1) supplies this, so a single-board card's
  /// look is byte-identical to before.
  final String? boardName;

  @override
  Widget build(BuildContext context) {
    final body = _body(context);
    // A cross-board card (task-14 brief) is never wrapped in `Draggable`
    // here — not disabled, absent — because dragging it would need the
    // target board's write authorization resolved per card.
    if (readOnly) return body;
    return Draggable<TaskRow>(
      data: task,
      feedback: Material(
        elevation: 4,
        borderRadius: BorderRadius.circular(8),
        child: SizedBox(width: 200, child: body),
      ),
      childWhenDragging: Opacity(opacity: 0.35, child: body),
      child: Opacity(opacity: dragging ? 0.5 : 1, child: body),
    );
  }

  Widget _body(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      margin: const EdgeInsets.symmetric(vertical: 2),
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
      decoration: BoxDecoration(
        color: scheme.surface,
        borderRadius: BorderRadius.circular(6),
        border: Border.all(color: EzBiziTheme.edge(context)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            task.title,
            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
            overflow: TextOverflow.ellipsis,
          ),
          if (boardName case final name?)
            Text(
              name,
              style: TextStyle(fontSize: 10, color: scheme.onSurfaceVariant),
              overflow: TextOverflow.ellipsis,
            ),
        ],
      ),
    );
  }
}

String _keyDate(DateTime date) =>
    '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';
