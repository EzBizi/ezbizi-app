import 'package:flutter/material.dart';

import '../../core/theme.dart';
import 'task_models.dart';

/// The fields Phase 2 lets a board bucket a Kanban by.
///
/// This is deliberately NARROWER than what `TaskViewCodec::isGroupable()`
/// allows server-side for `group_by` (every custom column except
/// People/Label/File). The design spec's §6 prose claims the bucketable set
/// is "status, priority, owner, group, or any Label custom column" — that
/// prose is wrong on the code: `TaskViewCodec` excludes custom Label columns
/// from `group_by`/`bucket_field` (`isGroupable()` rejects
/// `custom && type in [people,label,file]`), and "group" (the board's own
/// `group_id`) is not a `group_by` reference at all — it is only what a null
/// `group_by` falls back to.
///
/// A second, tighter constraint the spec never mentions: `TaskWindow::
/// parseKanban()` requires every entry in `window.buckets` to look like a
/// record UUID. `status`/`priority` option ids and `owner` user ids satisfy
/// that; no custom Text/Number/Checkbox/Date/Link value ever does, and
/// neither does `task` (the title, free text). So even though the codec's
/// `isGroupable()` predicate would allow a custom Text/Number/Checkbox/
/// Date/Link column as `group_by`, no such column can ever carry a
/// populated, working Kanban window — its bucket list would always be
/// rejected or empty. `status`, `priority`, and `owner` are the only three
/// fields a Kanban board can actually be bucketed by today. See
/// task-10-report.md.
enum TaskKanbanField { status, priority, owner }

extension TaskKanbanFieldWire on TaskKanbanField {
  /// Matches `TaskViewCodec::CORE_TYPES` keys exactly.
  String get wire => switch (this) {
    TaskKanbanField.status => 'status',
    TaskKanbanField.priority => 'priority',
    TaskKanbanField.owner => 'owner',
  };

  String get label => switch (this) {
    TaskKanbanField.status => 'Status',
    TaskKanbanField.priority => 'Priority',
    TaskKanbanField.owner => 'Owner',
  };

  /// The `Task` write field the drag issues (`TaskApi.updateTask`'s
  /// `changes` key) — the exact field Phase 1's status/priority/owner write
  /// already uses. Phase 2 adds no new write path for Kanban.
  String get writeField => switch (this) {
    TaskKanbanField.status => 'status_option_id',
    TaskKanbanField.priority => 'priority_option_id',
    TaskKanbanField.owner => 'owner_user_id',
  };
}

/// One rendered column. `id` is always a real record id — a Kanban window
/// can never carry a bucket for "no value" (`TaskWindow::parseKanban`
/// requires every bucket to be UUID-shaped), so there is no null-id column;
/// tasks with no value for the bucket field simply do not match any window
/// bucket and are counted in `meta.omitted.outside_window` server-side.
class TaskKanbanBucket {
  const TaskKanbanBucket({
    required this.id,
    required this.label,
    this.colorHex,
  });

  final String id;
  final String label;
  final String? colorHex;
}

/// Builds the column list for a bucketable field from data the board
/// definition (and, for `owner`, the board's participant list) already
/// carries — archived options are left out, matching every other Phase 1
/// picker over these same lists (`TaskFiltersPanel`, the bulk-edit bar).
class TaskKanbanBuckets {
  const TaskKanbanBuckets._();

  static List<TaskKanbanBucket> forField(
    TaskKanbanField field,
    TaskBoard board,
    List<TaskParticipant> owners,
  ) => switch (field) {
    TaskKanbanField.status => [
      for (final status in board.statuses)
        if (!status.archived)
          TaskKanbanBucket(
            id: status.id,
            label: status.name,
            colorHex: status.color,
          ),
    ],
    TaskKanbanField.priority => [
      for (final priority in board.priorities)
        if (!priority.archived)
          TaskKanbanBucket(
            id: priority.id,
            label: priority.name,
            colorHex: priority.color,
          ),
    ],
    TaskKanbanField.owner => [
      for (final owner in owners)
        TaskKanbanBucket(id: owner.id, label: owner.name),
    ],
  };
}

/// Attempts to move [task] to [bucketId] for [field]. Returns `null` on
/// success, or the server's refusal sentence on failure — the same message
/// `TaskApiFailure`/`TaskApiConflictResult` already carries from the Phase 1
/// write this reuses. `TaskKanbanView` never invents a second copy of that
/// reason; it only ever displays what this returns.
typedef TaskKanbanMove =
    Future<String?> Function(
      TaskRow task,
      TaskKanbanField field,
      String bucketId,
    );

/// Cards in columns, bucketed by [field]. Empty buckets render — a Kanban
/// that hides its empty columns hides the fact that nothing is in review.
/// Dragging a card performs the write [field] already has via [onMove];
/// this widget adds no write path of its own.
class TaskKanbanView extends StatefulWidget {
  const TaskKanbanView({
    super.key,
    required this.board,
    required this.field,
    required this.buckets,
    required this.rows,
    required this.groupCounts,
    required this.onMove,
    this.owners = const <TaskParticipant>[],
    this.hiddenColumns = const <String>[],
    this.onOpen,
  });

  final TaskBoard board;
  final TaskKanbanField field;
  final List<TaskKanbanBucket> buckets;
  final List<TaskRow> rows;

  /// `meta.group_counts` from the last `GET rows` response — the complete
  /// accessible total per bucket, computed server-side before the window
  /// narrows what `rows` actually carries. Column headers read counts from
  /// here, never from `rows.length`, so a column never under-reports.
  final List<TaskGroupCount> groupCounts;
  final TaskKanbanMove onMove;
  final List<TaskParticipant> owners;
  final List<String> hiddenColumns;

  /// Opens a task's detail panel (whole-branch review finding 2) — before
  /// this, a Kanban card could not be opened at all. `null` leaves cards
  /// non-interactive beyond dragging.
  final ValueChanged<TaskRow>? onOpen;

  @override
  State<TaskKanbanView> createState() => _TaskKanbanViewState();
}

class _TaskKanbanViewState extends State<TaskKanbanView> {
  static const _narrowBreakpoint = 700.0;

  int _mobileIndex = 0;
  String? _dragError;
  String? _movingTaskId;

  String? _valueOf(TaskRow row) => switch (widget.field) {
    TaskKanbanField.status => row.statusOptionId,
    TaskKanbanField.priority => row.priorityOptionId,
    TaskKanbanField.owner => row.ownerUserId,
  };

  List<TaskRow> _cardsFor(TaskKanbanBucket bucket) => widget.rows
      .where((row) => _valueOf(row) == bucket.id)
      .toList(growable: false);

  /// The complete accessible total for [bucket] — from `meta.group_counts`,
  /// never from how many cards this run drew. A bucket absent from
  /// `groupCounts` genuinely holds nothing accessible; that is a true zero,
  /// not an omission.
  int _countFor(TaskKanbanBucket bucket) => widget.groupCounts
      .where((count) => count.value == bucket.id)
      .fold(0, (sum, count) => sum + count.count);

  Future<void> _handleDrop(TaskRow task, TaskKanbanBucket bucket) async {
    if (_valueOf(task) == bucket.id) return;
    setState(() {
      _movingTaskId = task.id;
      _dragError = null;
    });
    final message = await widget.onMove(task, widget.field, bucket.id);
    if (!mounted) return;
    setState(() {
      _movingTaskId = null;
      _dragError = message;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (widget.buckets.isEmpty) {
      return const Center(
        child: Text('No columns are available for this field.'),
      );
    }
    final index = _mobileIndex.clamp(0, widget.buckets.length - 1);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_dragError case final message?) _errorBanner(context, message),
        Expanded(
          child: LayoutBuilder(
            builder: (context, constraints) =>
                constraints.maxWidth < _narrowBreakpoint
                ? _narrow(context, index)
                : _wide(context),
          ),
        ),
      ],
    );
  }

  Widget _errorBanner(BuildContext context, String message) {
    final scheme = Theme.of(context).colorScheme;
    return Material(
      color: scheme.errorContainer,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        child: Row(
          children: [
            Expanded(
              child: Text(
                message,
                style: TextStyle(color: scheme.onErrorContainer),
              ),
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

  // Wide: every column side by side, the strip itself scrolling
  // horizontally in its own container — never the page body.
  Widget _wide(BuildContext context) => SingleChildScrollView(
    scrollDirection: Axis.horizontal,
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final bucket in widget.buckets)
          SizedBox(
            width: 280,
            child: _KanbanColumn(
              key: ValueKey('kanban-column-${bucket.id}'),
              bucket: bucket,
              cards: _cardsFor(bucket),
              count: _countFor(bucket),
              board: widget.board,
              owners: widget.owners,
              hiddenColumns: widget.hiddenColumns,
              movingTaskId: _movingTaskId,
              onDropped: (task) => _handleDrop(task, bucket),
              onOpen: widget.onOpen,
            ),
          ),
      ],
    ),
  );

  // Narrow: one column at a time, chosen with a wrapping chip selector
  // (never a horizontally-scrolling one) — the page body stays vertical
  // only, at any width.
  Widget _narrow(BuildContext context, int index) {
    final bucket = widget.buckets[index];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (var i = 0; i < widget.buckets.length; i++)
                ChoiceChip(
                  label: Text(
                    '${widget.buckets[i].label} · ${_countFor(widget.buckets[i])}',
                  ),
                  selected: i == index,
                  onSelected: (_) => setState(() => _mobileIndex = i),
                ),
            ],
          ),
        ),
        Expanded(
          child: _KanbanColumn(
            key: ValueKey('kanban-column-${bucket.id}'),
            bucket: bucket,
            cards: _cardsFor(bucket),
            count: _countFor(bucket),
            board: widget.board,
            owners: widget.owners,
            hiddenColumns: widget.hiddenColumns,
            movingTaskId: _movingTaskId,
            onDropped: (task) => _handleDrop(task, bucket),
            onOpen: widget.onOpen,
          ),
        ),
      ],
    );
  }
}

class _KanbanColumn extends StatelessWidget {
  const _KanbanColumn({
    super.key,
    required this.bucket,
    required this.cards,
    required this.count,
    required this.board,
    required this.owners,
    required this.hiddenColumns,
    required this.movingTaskId,
    required this.onDropped,
    this.onOpen,
  });

  final TaskKanbanBucket bucket;
  final List<TaskRow> cards;
  final int count;
  final TaskBoard board;
  final List<TaskParticipant> owners;
  final List<String> hiddenColumns;
  final String? movingTaskId;
  final ValueChanged<TaskRow> onDropped;
  final ValueChanged<TaskRow>? onOpen;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return DragTarget<TaskRow>(
      onWillAcceptWithDetails: (_) => true,
      onAcceptWithDetails: (details) => onDropped(details.data),
      builder: (context, candidates, rejected) => Container(
        margin: const EdgeInsets.symmetric(horizontal: 6, vertical: 6),
        decoration: BoxDecoration(
          color: candidates.isNotEmpty
              ? scheme.primaryContainer.withValues(alpha: 0.35)
              : scheme.surfaceContainerLow,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: EzBiziTheme.edge(context)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              child: Row(
                children: [
                  if (bucket.colorHex != null) ...[
                    _ColorDot(hex: bucket.colorHex, fallback: scheme.primary),
                    const SizedBox(width: 6),
                  ],
                  Expanded(
                    child: Text(
                      bucket.label,
                      key: ValueKey('kanban-header-${bucket.id}'),
                      style: Theme.of(context).textTheme.titleMedium,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  Text(
                    '$count',
                    key: ValueKey('kanban-count-${bucket.id}'),
                    style: TextStyle(
                      color: scheme.onSurfaceVariant,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              ),
            ),
            Divider(height: 1, color: EzBiziTheme.edge(context)),
            Expanded(
              child: cards.isEmpty
                  ? Center(
                      child: Text(
                        'No tasks',
                        style: TextStyle(color: scheme.onSurfaceVariant),
                      ),
                    )
                  : ListView(
                      padding: const EdgeInsets.all(8),
                      children: [
                        for (final task in cards)
                          _KanbanCard(
                            key: ValueKey('kanban-card-${task.id}'),
                            task: task,
                            board: board,
                            owners: owners,
                            hiddenColumns: hiddenColumns,
                            dragging: movingTaskId == task.id,
                            onOpen: onOpen,
                          ),
                      ],
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ColorDot extends StatelessWidget {
  const _ColorDot({required this.hex, required this.fallback});
  final String? hex;
  final Color fallback;

  @override
  Widget build(BuildContext context) => Container(
    width: 10,
    height: 10,
    decoration: BoxDecoration(
      shape: BoxShape.circle,
      color: _parseHexColor(hex, fallback),
    ),
  );
}

class _KanbanCard extends StatelessWidget {
  const _KanbanCard({
    super.key,
    required this.task,
    required this.board,
    required this.owners,
    required this.hiddenColumns,
    required this.dragging,
    this.onOpen,
  });

  final TaskRow task;
  final TaskBoard board;
  final List<TaskParticipant> owners;
  final List<String> hiddenColumns;
  final bool dragging;

  /// Opens this task's detail panel (whole-branch review finding 2).
  final ValueChanged<TaskRow>? onOpen;

  @override
  Widget build(BuildContext context) {
    final onOpen = this.onOpen;
    final body = onOpen == null
        ? _body(context)
        : InkWell(
            key: ValueKey('kanban-card-open-${task.id}'),
            onTap: () => onOpen(task),
            child: _body(context),
          );
    return Draggable<TaskRow>(
      data: task,
      feedback: Material(
        elevation: 4,
        borderRadius: BorderRadius.circular(10),
        child: SizedBox(width: 260, child: body),
      ),
      childWhenDragging: Opacity(opacity: 0.35, child: body),
      child: Opacity(opacity: dragging ? 0.5 : 1, child: body),
    );
  }

  Widget _body(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final owner = owners.where((person) => person.id == task.ownerUserId);
    final status = board.statuses.where((option) => option.id == task.statusOptionId);
    final custom = _visibleCustomColumns(board, hiddenColumns).take(3);
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              task.title,
              style: const TextStyle(fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 6),
            Wrap(
              spacing: 10,
              runSpacing: 4,
              children: [
                _CardMeta(
                  icon: Icons.person_outline,
                  text: owner.isEmpty ? 'Unassigned' : owner.first.name,
                ),
                _CardMeta(
                  icon: Icons.flag_outlined,
                  text: status.isEmpty ? 'No status' : status.first.name,
                ),
                _CardMeta(
                  icon: Icons.event_outlined,
                  text: task.dueOn == null
                      ? 'No due date'
                      : _dateLabel(task.dueOn!),
                ),
              ],
            ),
            for (final column in custom)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  '${column.name}: ${_customValueText(column, task.values[column.id], owners)}',
                  style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _CardMeta extends StatelessWidget {
  const _CardMeta({required this.icon, required this.text});
  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 14, color: scheme.onSurfaceVariant),
        const SizedBox(width: 3),
        Text(text, style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant)),
      ],
    );
  }
}

List<TaskColumnDefinition> _visibleCustomColumns(
  TaskBoard board,
  List<String> hiddenColumns,
) {
  final visible = board.customColumns
      .where(
        (column) =>
            !column.archived && !hiddenColumns.contains('custom:${column.id}'),
      )
      .toList()
    ..sort((a, b) => a.position.compareTo(b.position));
  return visible;
}

String _customValueText(
  TaskColumnDefinition column,
  TaskCellValue? cell,
  List<TaskParticipant> owners,
) {
  final value = cell?.value;
  if (value == null) return '—';
  return switch (cell!.kind) {
    TaskColumnKind.text => value as String,
    TaskColumnKind.number => '$value${column.numberSuffix ?? ''}',
    TaskColumnKind.checkbox => (value as bool) ? 'Yes' : 'No',
    TaskColumnKind.date => _dateLabel(value as DateTime),
    TaskColumnKind.link =>
      (value as TaskLinkValue).label ?? (value).url,
    TaskColumnKind.people => (value as List<String>)
        .map((id) => _nameFor(id, owners))
        .join(', '),
    TaskColumnKind.label => (value as List<String>)
        .map(
          (id) => column.options
              .where((option) => option.id == id)
              .map((option) => option.name)
              .followedBy([id])
              .first,
        )
        .join(', '),
    TaskColumnKind.file => '${(value as List<String>).length} file(s)',
    TaskColumnKind.unknown => '—',
  };
}

String _nameFor(String id, List<TaskParticipant> owners) {
  for (final owner in owners) {
    if (owner.id == id) return owner.name;
  }
  return id;
}

String _dateLabel(DateTime date) =>
    '${date.year}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';

Color _parseHexColor(String? raw, Color fallback) {
  if (raw == null) return fallback;
  final hex = raw.replaceFirst('#', '');
  if (!RegExp(r'^[0-9a-fA-F]{6}$').hasMatch(hex)) return fallback;
  return Color(int.parse('FF$hex', radix: 16));
}
