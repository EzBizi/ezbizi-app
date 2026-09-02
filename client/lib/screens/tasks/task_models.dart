import 'dart:async';

enum TaskBoardVisibility { scope, private, unknown }

enum TaskStatusCategory {
  notStarted,
  active,
  blocked,
  completed,
  cancelled,
  unknown,
}

enum TaskPriorityRank { low, normal, high, urgent, unknown }

enum TaskColumnKind {
  text,
  number,
  checkbox,
  date,
  people,
  label,
  link,
  file,
  unknown,
}

enum TaskParticipantPurpose {
  taskCreate,
  owner,
  people,
  collaborator,
  mention,
  membership,
}

final class TaskParticipant {
  const TaskParticipant({
    required this.id,
    required this.name,
    required this.email,
  });

  factory TaskParticipant.fromJson(Map<String, Object?> json) =>
      TaskParticipant(
        id: _string(json, 'id'),
        name: _string(json, 'name'),
        email: _string(json, 'email'),
      );

  final String id;
  final String name;
  final String email;
}

final class TaskBulkTarget {
  const TaskBulkTarget({required this.id, required this.expectedVersion});

  Map<String, Object?> toJson() => <String, Object?>{
    'id': id,
    'expected_version': expectedVersion,
  };

  final String id;
  final int expectedVersion;
}

final class TaskColumnValueMutation {
  const TaskColumnValueMutation({
    required this.taskId,
    required this.columnDefinitionId,
    required this.rawType,
    required this.value,
    required this.version,
    required this.detailVersion,
  });

  factory TaskColumnValueMutation.fromJson(Map<String, Object?> json) =>
      TaskColumnValueMutation(
        taskId: _string(json, 'task_id'),
        columnDefinitionId: _string(json, 'column_definition_id'),
        rawType: _string(json, 'type'),
        value: json['value'],
        version: _integer(json, 'version'),
        detailVersion: _integer(json, 'detail_version'),
      );

  final String taskId;
  final String columnDefinitionId;
  final String rawType;
  final Object? value;
  final int version;
  final int detailVersion;
}

enum TaskViewVisibility { personal, shared, unknown }

/// Mirrors `App\Support\Tasks\TaskPresentation` (server, string-backed enum)
/// exactly: `table`, `kanban`, `calendar`, `timeline`, `workload`. There is
/// deliberately no `unknown` case here — see `_presentationFromWire` below.
enum TaskPresentation { table, kanban, calendar, timeline, workload }

extension TaskPresentationWire on TaskPresentation {
  String get wire => switch (this) {
    TaskPresentation.table => 'table',
    TaskPresentation.kanban => 'kanban',
    TaskPresentation.calendar => 'calendar',
    TaskPresentation.timeline => 'timeline',
    TaskPresentation.workload => 'workload',
  };
}

/// THE ONE RULE: an unknown presentation is a server ahead of this client
/// (docs/ACTIVE-CONSTRAINTS.md — a frozen App Store client runs against live
/// production). It degrades to Table rather than throwing or carrying a
/// distinct "unknown" render state this client has no screen for.
/// `TaskViewConfiguration.rawPresentation` still keeps the original wire
/// string so a save-without-touching-presentation round-trips losslessly.
TaskPresentation _presentationFromWire(String raw) => switch (raw) {
  'table' => TaskPresentation.table,
  'kanban' => TaskPresentation.kanban,
  'calendar' => TaskPresentation.calendar,
  'timeline' => TaskPresentation.timeline,
  'workload' => TaskPresentation.workload,
  _ => TaskPresentation.table,
};

/// Mirrors `App\Support\Tasks\TaskWindow` (spec §4 schema:2): the bounded
/// query window a presentation declares, shaped per-presentation and
/// validated server-side. Table's window is currently inert (Phase 1's exact
/// pagination behaviour is untouched — see `TaskListQuery::boardRows`) but is
/// still represented so a saved configuration round-trips exactly.
sealed class TaskWindow {
  const TaskWindow();

  Map<String, Object?> toJson();

  /// Never throws. A window whose shape does not match what this
  /// presentation expects — including every window attached to a
  /// presentation that just degraded to Table above — decodes to `null`
  /// rather than crashing the configuration parse around it.
  static TaskWindow? decode(
    TaskPresentation presentation,
    Map<String, Object?>? raw,
  ) {
    if (raw == null) return null;
    try {
      return switch (presentation) {
        TaskPresentation.table => TaskTableWindow.fromJson(raw),
        TaskPresentation.kanban => TaskKanbanWindow.fromJson(raw),
        TaskPresentation.calendar ||
        TaskPresentation.timeline => TaskDateRangeWindow.fromJson(raw),
        TaskPresentation.workload => TaskWorkloadWindow.fromJson(raw),
      };
    } on FormatException {
      return null;
    }
  }
}

final class TaskTableWindow extends TaskWindow {
  const TaskTableWindow({required this.page, required this.perPage});

  factory TaskTableWindow.fromJson(Map<String, Object?> json) =>
      TaskTableWindow(page: _integer(json, 'page'), perPage: _integer(json, 'per_page'));

  final int page;
  final int perPage;

  @override
  Map<String, Object?> toJson() => <String, Object?>{
    'page': page,
    'per_page': perPage,
  };
}

final class TaskKanbanWindow extends TaskWindow {
  const TaskKanbanWindow({required this.bucketField, required this.buckets});

  factory TaskKanbanWindow.fromJson(Map<String, Object?> json) =>
      TaskKanbanWindow(
        bucketField: _string(json, 'bucket_field'),
        buckets: _strings(json, 'buckets'),
      );

  final String bucketField;
  final List<String> buckets;

  @override
  Map<String, Object?> toJson() => <String, Object?>{
    'bucket_field': bucketField,
    'buckets': List<String>.unmodifiable(buckets),
  };
}

/// Shared by Calendar and Timeline: `{from, to}`, no `unit`.
final class TaskDateRangeWindow extends TaskWindow {
  const TaskDateRangeWindow({required this.from, required this.to});

  factory TaskDateRangeWindow.fromJson(Map<String, Object?> json) =>
      TaskDateRangeWindow(
        from: _parseDateOnly(_string(json, 'from'), 'window.from'),
        to: _parseDateOnly(_string(json, 'to'), 'window.to'),
      );

  final DateTime from;
  final DateTime to;

  @override
  Map<String, Object?> toJson() => <String, Object?>{
    'from': _dateOnly(from),
    'to': _dateOnly(to),
  };
}

enum TaskWorkloadUnit { day, week }

TaskWorkloadUnit _workloadUnitFromWire(String raw) => switch (raw) {
  'day' => TaskWorkloadUnit.day,
  'week' => TaskWorkloadUnit.week,
  _ => throw FormatException('window.unit has an unsupported value: $raw.'),
};

String _workloadUnitWire(TaskWorkloadUnit unit) => switch (unit) {
  TaskWorkloadUnit.day => 'day',
  TaskWorkloadUnit.week => 'week',
};

/// Workload's window: `{from, to, unit}` where `unit` is `day` or `week` only
/// — `TaskWindow::parseRange($withUnit: true)` never accepts `month` here,
/// unlike a recurrence unit.
final class TaskWorkloadWindow extends TaskWindow {
  const TaskWorkloadWindow({
    required this.from,
    required this.to,
    required this.unit,
  });

  factory TaskWorkloadWindow.fromJson(Map<String, Object?> json) =>
      TaskWorkloadWindow(
        from: _parseDateOnly(_string(json, 'from'), 'window.from'),
        to: _parseDateOnly(_string(json, 'to'), 'window.to'),
        unit: _workloadUnitFromWire(_string(json, 'unit')),
      );

  final DateTime from;
  final DateTime to;
  final TaskWorkloadUnit unit;

  @override
  Map<String, Object?> toJson() => <String, Object?>{
    'from': _dateOnly(from),
    'to': _dateOnly(to),
    'unit': _workloadUnitWire(unit),
  };
}

/// Mirrors the `meta.omitted` object every `GET .../rows` response carries
/// (present for every presentation, not only windowed ones — Ruling 17).
final class TaskOmitted {
  const TaskOmitted({
    required this.undated,
    required this.outsideWindow,
    required this.unowned,
    this.capped = 0,
  });

  static const TaskOmitted zero = TaskOmitted(
    undated: 0,
    outsideWindow: 0,
    unowned: 0,
    capped: 0,
  );

  factory TaskOmitted.fromJson(Map<String, Object?> json) => TaskOmitted(
    undated: _integer(json, 'undated'),
    outsideWindow: _integer(json, 'outside_window'),
    unowned: _integer(json, 'unowned'),
    // `capped` is additive (whole-branch review finding 4): `_integer`
    // (used by the other three fields above) THROWS on a missing key, the
    // right choice for a field that has always been present — but this one
    // has not, so a server response that predates it must not fail to
    // parse. `_optionalInteger` tolerates absence; `?? 0` matches every
    // other "nothing was omitted for this reason" default in this class.
    capped: _optionalInteger(json, 'capped') ?? 0,
  );

  final int undated;
  final int outsideWindow;
  final int unowned;

  /// Rows a cross-board Calendar/Timeline window left out purely because
  /// the server-side row cap was hit (`TaskListQuery::CROSS_BOARD_ROW_CAP`)
  /// — never set outside those two cross-board presentations.
  final int capped;
}

enum TaskSortDirection { asc, desc, unknown }

enum TaskViewDensity { compact, comfortable, unknown }

enum TaskConflictKind { task, update, groupOrder, view, structure, unknown }

/// Stable draft identities let convergence wait for every open editor. The
/// Task 17B update composer plugs into the same callback without coupling its
/// UI to the board table.
typedef TaskDraftChanged = void Function(String draftId, bool dirty);

final class TaskBoard {
  const TaskBoard({
    required this.id,
    required this.name,
    required this.description,
    required this.visibility,
    required this.rawVisibility,
    required this.companyId,
    required this.franchiseId,
    required this.folderId,
    required this.structureVersion,
    required this.archived,
    required this.isFavorite,
    required this.lastOpenedAt,
    required this.groups,
    required this.statuses,
    required this.priorities,
    required this.coreColumns,
    required this.customColumns,
    required this.views,
    this.actions = const <String>[],
  });

  factory TaskBoard.fromJson(Map<String, Object?> json) {
    final rawVisibility = _string(json, 'visibility');
    return TaskBoard(
      id: _string(json, 'id'),
      name: _string(json, 'name'),
      description: _nullableString(json, 'description'),
      visibility: _boardVisibility(rawVisibility),
      rawVisibility: rawVisibility,
      companyId: _nullableString(json, 'company_id'),
      franchiseId: _nullableString(json, 'franchise_id'),
      folderId: _nullableString(json, 'folder_id'),
      structureVersion: _integer(json, 'structure_version'),
      archived: _boolean(json, 'archived'),
      isFavorite: _boolean(json, 'is_favorite'),
      lastOpenedAt: _nullableDateTime(json, 'last_opened_at'),
      groups: _objects(
        json,
        'groups',
      ).map(TaskGroup.fromJson).toList(growable: false),
      statuses: _objects(
        json,
        'statuses',
      ).map(TaskStatusOption.fromJson).toList(growable: false),
      priorities: _objects(
        json,
        'priorities',
      ).map(TaskPriorityOption.fromJson).toList(growable: false),
      coreColumns: _objects(
        json,
        'core_columns',
      ).map(TaskCoreColumn.fromJson).toList(growable: false),
      customColumns: _objects(
        json,
        'custom_columns',
      ).map(TaskColumnDefinition.fromJson).toList(growable: false),
      views: _objects(
        json,
        'views',
      ).map(TaskViewSummary.fromJson).toList(growable: false),
      actions: _optionalStrings(json, 'actions'),
    );
  }

  final String id;
  final String name;
  final String? description;
  final TaskBoardVisibility visibility;
  final String rawVisibility;
  final String? companyId;
  final String? franchiseId;
  final String? folderId;
  final int structureVersion;
  final bool archived;
  final bool isFavorite;
  final DateTime? lastOpenedAt;
  final List<TaskGroup> groups;
  final List<TaskStatusOption> statuses;
  final List<TaskPriorityOption> priorities;
  final List<TaskCoreColumn> coreColumns;
  final List<TaskColumnDefinition> customColumns;
  final List<TaskViewSummary> views;
  final List<String> actions;

  bool can(String action) => actions.contains(action);

  TaskColumnDefinition? column(String id) {
    for (final column in customColumns) {
      if (column.id == id) return column;
    }
    return null;
  }
}

final class TaskBoardDirectory {
  const TaskBoardDirectory({required this.boards, required this.actions});

  final List<TaskBoard> boards;
  final List<String> actions;

  bool can(String action) => actions.contains(action);
}

final class TaskFolder {
  const TaskFolder({
    required this.id,
    required this.name,
    required this.companyId,
    required this.franchiseId,
    required this.position,
    required this.archived,
    required this.actions,
  });

  factory TaskFolder.fromJson(Map<String, Object?> json) => TaskFolder(
    id: _string(json, 'id'),
    name: _string(json, 'name'),
    companyId: _nullableString(json, 'company_id'),
    franchiseId: _nullableString(json, 'franchise_id'),
    position: _integer(json, 'position'),
    archived: _boolean(json, 'archived'),
    actions: _optionalStrings(json, 'actions'),
  );

  final String id;
  final String name;
  final String? companyId;
  final String? franchiseId;
  final int position;
  final bool archived;
  final List<String> actions;

  bool can(String action) => actions.contains(action);
}

final class TaskBoardPreference {
  const TaskBoardPreference({
    required this.boardId,
    required this.isFavorite,
    required this.lastOpenedAt,
  });

  factory TaskBoardPreference.fromJson(Map<String, Object?> json) =>
      TaskBoardPreference(
        boardId: _string(json, 'board_id'),
        isFavorite: _boolean(json, 'is_favorite'),
        lastOpenedAt: _nullableDateTime(json, 'last_opened_at'),
      );

  final String boardId;
  final bool isFavorite;
  final DateTime? lastOpenedAt;
}

enum TaskBoardMemberRole { owner, member, unknown }

final class TaskBoardMember {
  const TaskBoardMember({
    required this.id,
    required this.userId,
    required this.name,
    required this.email,
    required this.role,
    required this.rawRole,
    required this.active,
    required this.actions,
  });

  factory TaskBoardMember.fromJson(Map<String, Object?> json) {
    final rawRole = _string(json, 'role');
    return TaskBoardMember(
      id: _string(json, 'id'),
      userId: _string(json, 'user_id'),
      name: _nullableString(json, 'name') ?? '',
      email: _nullableString(json, 'email') ?? '',
      role: switch (rawRole) {
        'owner' => TaskBoardMemberRole.owner,
        'member' => TaskBoardMemberRole.member,
        _ => TaskBoardMemberRole.unknown,
      },
      rawRole: rawRole,
      active: _boolean(json, 'active'),
      actions: _optionalStrings(json, 'actions'),
    );
  }

  final String id;
  final String userId;
  final String name;
  final String email;
  final TaskBoardMemberRole role;
  final String rawRole;
  final bool active;
  final List<String> actions;

  bool can(String action) => actions.contains(action);
}

final class TaskBoardRecovery {
  const TaskBoardRecovery({required this.boardId, required this.ownerUserId});

  factory TaskBoardRecovery.fromJson(Map<String, Object?> json) =>
      TaskBoardRecovery(
        boardId: _string(json, 'board_id'),
        ownerUserId: _string(json, 'owner_user_id'),
      );

  final String boardId;
  final String ownerUserId;
}

/// The deliberately content-blind shape used to recover an orphaned private
/// board. Names and board content must never be added to this presentation.
final class TaskRecoverableBoard {
  const TaskRecoverableBoard({
    required this.id,
    required this.companyId,
    required this.franchiseId,
    required this.actions,
  });

  factory TaskRecoverableBoard.fromJson(Map<String, Object?> json) =>
      TaskRecoverableBoard(
        id: _string(json, 'id'),
        companyId: _nullableString(json, 'company_id'),
        franchiseId: _nullableString(json, 'franchise_id'),
        actions: _optionalStrings(json, 'actions'),
      );

  final String id;
  final String? companyId;
  final String? franchiseId;
  final List<String> actions;

  bool can(String action) => actions.contains(action);
}

final class TaskStructureMutation<T> {
  const TaskStructureMutation({
    required this.data,
    required this.structureVersion,
  });

  final T data;
  final int structureVersion;
}

final class TaskDuplicateSource {
  const TaskDuplicateSource({
    required this.id,
    required this.name,
    required this.structureVersion,
    required this.actions,
    required this.samePlace,
  });

  factory TaskDuplicateSource.fromJson(Map<String, Object?> json) =>
      TaskDuplicateSource(
        id: _string(json, 'id'),
        name: _string(json, 'name'),
        structureVersion: _integer(json, 'structure_version'),
        actions: _optionalStrings(json, 'actions'),
        samePlace: _boolean(json, 'same_place'),
      );

  final String id;
  final String name;
  final int structureVersion;
  final List<String> actions;
  final bool samePlace;

  bool can(String action) => actions.contains(action);
}

enum TaskImportFormat { csv, xlsx, unknown }

enum TaskExportFormat { csv, xlsx, json }

final class TaskImportMapping {
  const TaskImportMapping({
    required this.fields,
    this.custom = const <String, String>{},
    required this.decisions,
  });

  final Map<String, String> fields;
  final Map<String, String> custom;
  final Map<String, Object?> decisions;

  Map<String, Object?> toJson() => <String, Object?>{
    ...fields,
    'custom': custom,
    'decisions': decisions,
  };
}

final class TaskImportCounts {
  const TaskImportCounts({
    required this.rows,
    required this.ready,
    required this.errors,
  });

  factory TaskImportCounts.fromJson(Map<String, Object?> json) =>
      TaskImportCounts(
        rows: _integer(json, 'rows'),
        ready: _integer(json, 'ready'),
        errors: _integer(json, 'errors'),
      );

  final int rows;
  final int ready;
  final int errors;
}

final class TaskImportUnsupportedField {
  const TaskImportUnsupportedField({
    required this.heading,
    required this.reason,
  });

  factory TaskImportUnsupportedField.fromJson(Map<String, Object?> json) =>
      TaskImportUnsupportedField(
        heading: _string(json, 'heading'),
        reason: _string(json, 'reason'),
      );

  final String heading;
  final String reason;
}

final class TaskImportIssue {
  const TaskImportIssue({
    required this.row,
    required this.field,
    required this.code,
    required this.message,
    required this.sourceValue,
  });

  factory TaskImportIssue.fromJson(Map<String, Object?> json) =>
      TaskImportIssue(
        row: _optionalInteger(json, 'row'),
        field: json['field'] is String ? json['field'] as String : null,
        code: json['code'] is String ? json['code'] as String : null,
        message: _string(json, 'message'),
        sourceValue: _nullableString(json, 'source_value'),
      );

  final int? row;
  final String? field;
  final String? code;
  final String message;
  final String? sourceValue;
}

final class TaskImportPreview {
  const TaskImportPreview({
    required this.format,
    required this.rawFormat,
    required this.headings,
    required this.sample,
    required this.unsupportedFields,
    required this.counts,
    required this.reconciliation,
    required this.requiresMapping,
    required this.sessionToken,
    required this.expiresAt,
  });

  factory TaskImportPreview.fromJson(Map<String, Object?> json) {
    final rawFormat = _string(json, 'format');
    return TaskImportPreview(
      format: switch (rawFormat) {
        'csv' => TaskImportFormat.csv,
        'xlsx' => TaskImportFormat.xlsx,
        _ => TaskImportFormat.unknown,
      },
      rawFormat: rawFormat,
      headings: _strings(json, 'headings'),
      sample: _objects(json, 'sample')
          .map((row) {
            if (row.values.any((value) => value is! String)) {
              throw const FormatException('sample values must be strings.');
            }
            return Map<String, String>.unmodifiable(row.cast<String, String>());
          })
          .toList(growable: false),
      unsupportedFields: _objects(
        json,
        'unsupported_fields',
      ).map(TaskImportUnsupportedField.fromJson).toList(growable: false),
      counts: TaskImportCounts.fromJson(_object(json, 'counts')),
      reconciliation: _objects(
        json,
        'reconciliation',
      ).map(TaskImportIssue.fromJson).toList(growable: false),
      requiresMapping: _boolean(json, 'requires_mapping'),
      sessionToken: json['session_token'] is String
          ? json['session_token'] as String
          : null,
      expiresAt: json['expires_at'] is String
          ? _parseDateTimeValue(json['expires_at'], 'expires_at')
          : null,
    );
  }

  final TaskImportFormat format;
  final String rawFormat;
  final List<String> headings;
  final List<Map<String, String>> sample;
  final List<TaskImportUnsupportedField> unsupportedFields;
  final TaskImportCounts counts;
  final List<TaskImportIssue> reconciliation;
  final bool requiresMapping;
  final String? sessionToken;
  final DateTime? expiresAt;

  bool get hasBlockingErrors => counts.errors > 0 || reconciliation.isNotEmpty;
}

final class TaskImportCommit {
  const TaskImportCommit({required this.created, required this.taskIds});

  factory TaskImportCommit.fromJson(Map<String, Object?> json) =>
      TaskImportCommit(
        created: _integer(json, 'created'),
        taskIds: _strings(json, 'task_ids'),
      );

  final int created;
  final List<String> taskIds;
}

final class TaskTransferCancelToken {
  final Completer<void> _cancelled = Completer<void>();

  bool get isCancelled => _cancelled.isCompleted;
  Future<void> get whenCancelled => _cancelled.future;

  void cancel() {
    if (!_cancelled.isCompleted) _cancelled.complete();
  }
}

final class TaskGroup {
  const TaskGroup({
    required this.id,
    required this.name,
    required this.color,
    required this.position,
    required this.orderVersion,
    required this.archived,
    this.actions = const <String>[],
  });

  factory TaskGroup.fromJson(Map<String, Object?> json) => TaskGroup(
    id: _string(json, 'id'),
    name: _string(json, 'name'),
    color: _nullableString(json, 'color'),
    position: _optionalInteger(json, 'position') ?? 0,
    orderVersion: _optionalInteger(json, 'order_version') ?? 1,
    archived: _optionalBoolean(json, 'archived') ?? false,
    actions: _optionalStrings(json, 'actions'),
  );

  final String id;
  final String name;
  final String? color;
  final int position;
  final int orderVersion;
  final bool archived;
  final List<String> actions;

  bool can(String action) => actions.contains(action);
}

final class TaskStatusOption {
  const TaskStatusOption({
    required this.id,
    required this.name,
    required this.color,
    required this.category,
    required this.rawCategory,
    required this.archived,
    this.position = 0,
    this.actions = const <String>[],
  });

  factory TaskStatusOption.fromJson(Map<String, Object?> json) {
    final rawCategory = _string(json, 'category');
    return TaskStatusOption(
      id: _string(json, 'id'),
      name: _string(json, 'name'),
      color: _nullableString(json, 'color'),
      category: _statusCategory(rawCategory),
      rawCategory: rawCategory,
      archived: _optionalBoolean(json, 'archived') ?? false,
      position: _optionalInteger(json, 'position') ?? 0,
      actions: _optionalStrings(json, 'actions'),
    );
  }

  final String id;
  final String name;
  final String? color;
  final TaskStatusCategory category;
  final String rawCategory;
  final bool archived;
  final int position;
  final List<String> actions;

  bool can(String action) => actions.contains(action);
}

final class TaskPriorityOption {
  const TaskPriorityOption({
    required this.id,
    required this.name,
    required this.color,
    required this.rank,
    required this.rawRank,
    required this.archived,
    this.position = 0,
    this.actions = const <String>[],
  });

  factory TaskPriorityOption.fromJson(Map<String, Object?> json) {
    final rawRank = _string(json, 'rank');
    return TaskPriorityOption(
      id: _string(json, 'id'),
      name: _string(json, 'name'),
      color: _nullableString(json, 'color'),
      rank: _priorityRank(rawRank),
      rawRank: rawRank,
      archived: _optionalBoolean(json, 'archived') ?? false,
      position: _optionalInteger(json, 'position') ?? 0,
      actions: _optionalStrings(json, 'actions'),
    );
  }

  final String id;
  final String name;
  final String? color;
  final TaskPriorityRank rank;
  final String rawRank;
  final bool archived;
  final int position;
  final List<String> actions;

  bool can(String action) => actions.contains(action);
}

final class TaskCoreColumn {
  const TaskCoreColumn({
    required this.key,
    required this.displayLabel,
    this.actions = const <String>[],
  });

  factory TaskCoreColumn.fromJson(Map<String, Object?> json) => TaskCoreColumn(
    key: _string(json, 'core_key'),
    displayLabel: _string(json, 'display_label'),
    actions: _optionalStrings(json, 'actions'),
  );

  final String key;
  final String displayLabel;
  final List<String> actions;

  bool can(String action) => actions.contains(action);
}

final class TaskColumnDefinition {
  const TaskColumnDefinition({
    required this.id,
    required this.name,
    required this.kind,
    required this.rawType,
    required this.numberSuffix,
    required this.position,
    required this.archived,
    required this.options,
    this.actions = const <String>[],
  });

  factory TaskColumnDefinition.fromJson(Map<String, Object?> json) {
    final rawType = _string(json, 'type');
    return TaskColumnDefinition(
      id: _string(json, 'id'),
      name: _string(json, 'name'),
      kind: _columnKind(rawType),
      rawType: rawType,
      numberSuffix: _nullableString(json, 'number_suffix'),
      position: _optionalInteger(json, 'position') ?? 0,
      archived: _optionalBoolean(json, 'archived') ?? false,
      options: (_optionalObjects(json, 'options') ?? const [])
          .map(TaskColumnOption.fromJson)
          .toList(growable: false),
      actions: _optionalStrings(json, 'actions'),
    );
  }

  final String id;
  final String name;
  final TaskColumnKind kind;
  final String rawType;
  final String? numberSuffix;
  final int position;
  final bool archived;
  final List<TaskColumnOption> options;
  final List<String> actions;

  bool get isReadOnly => kind == TaskColumnKind.unknown;
  bool can(String action) => actions.contains(action);
}

final class TaskColumnOption {
  const TaskColumnOption({
    required this.id,
    required this.name,
    required this.color,
    required this.position,
    required this.archived,
    this.actions = const <String>[],
  });

  factory TaskColumnOption.fromJson(Map<String, Object?> json) =>
      TaskColumnOption(
        id: _string(json, 'id'),
        name: _string(json, 'name'),
        color: _nullableString(json, 'color'),
        position: _optionalInteger(json, 'position') ?? 0,
        archived: _optionalBoolean(json, 'archived') ?? false,
        actions: _optionalStrings(json, 'actions'),
      );

  final String id;
  final String name;
  final String? color;
  final int position;
  final bool archived;
  final List<String> actions;

  bool can(String action) => actions.contains(action);
}

final class TaskViewSummary {
  const TaskViewSummary({
    required this.id,
    required this.name,
    required this.visibility,
    required this.rawVisibility,
    required this.isLocked,
    required this.isPermanentMain,
  });

  factory TaskViewSummary.fromJson(Map<String, Object?> json) {
    final rawVisibility = _string(json, 'visibility');
    return TaskViewSummary(
      id: _string(json, 'id'),
      name: _string(json, 'name'),
      visibility: _viewVisibility(rawVisibility),
      rawVisibility: rawVisibility,
      isLocked:
          _optionalBoolean(json, 'locked') ??
          _optionalBoolean(json, 'is_locked') ??
          false,
      isPermanentMain:
          _optionalBoolean(json, 'permanent_main') ??
          _optionalBoolean(json, 'is_permanent_main') ??
          false,
    );
  }

  final String id;
  final String name;
  final TaskViewVisibility visibility;
  final String rawVisibility;
  final bool isLocked;
  final bool isPermanentMain;
}

final class TaskRowsPage {
  const TaskRowsPage({
    required this.rows,
    required this.workloadRows,
    required this.total,
    required this.page,
    required this.perPage,
    required this.lastPage,
    required this.groupCounts,
    required this.statusSummaries,
    required this.omitted,
    required this.viewId,
    required this.viewVersion,
    required this.configuration,
    this.etag,
  });

  factory TaskRowsPage.fromJson(
    Map<String, Object?> json, {
    required TaskBoard board,
    String? etag,
  }) {
    final meta = _object(json, 'meta');
    final configuration = TaskViewConfiguration.fromJson(
      _object(meta, 'configuration'),
    );
    // Ruling 2 / FACTS: `data` legitimately diverges by presentation.
    // Workload's `data` holds per-owner unit rows, not task rows — forcing
    // it through `TaskRow.fromJson` (which requires `id`, `title`, etc.)
    // would throw. This is the one presentation-shape branch this contract
    // has to make so a known (not unknown) divergent shape never crashes
    // the parse either.
    final isWorkload = configuration.presentation == TaskPresentation.workload;
    final omittedJson = _optionalObject(meta, 'omitted');
    return TaskRowsPage(
      rows: isWorkload
          ? const <TaskRow>[]
          : _objects(json, 'data')
              .map((row) => TaskRow.fromJson(row, board: board))
              .toList(growable: false),
      workloadRows: isWorkload
          ? _objects(json, 'data')
              .map(TaskWorkloadRow.fromJson)
              .toList(growable: false)
          : const <TaskWorkloadRow>[],
      total: _integer(meta, 'total'),
      page: _integer(meta, 'page'),
      perPage: _integer(meta, 'per_page'),
      lastPage: _integer(meta, 'last_page'),
      groupCounts: _objects(
        meta,
        'group_counts',
      ).map(TaskGroupCount.fromJson).toList(growable: false),
      statusSummaries: _objects(
        meta,
        'status_summaries',
      ).map(TaskStatusSummary.fromJson).toList(growable: false),
      omitted: omittedJson == null
          ? TaskOmitted.zero
          : TaskOmitted.fromJson(omittedJson),
      viewId: _string(meta, 'view_id'),
      viewVersion: _integer(meta, 'view_version'),
      configuration: configuration,
      etag: etag,
    );
  }

  final List<TaskRow> rows;
  final List<TaskWorkloadRow> workloadRows;
  final int total;
  final int page;
  final int perPage;
  final int lastPage;
  final List<TaskGroupCount> groupCounts;
  final List<TaskStatusSummary> statusSummaries;
  final TaskOmitted omitted;
  final String viewId;
  final int viewVersion;
  final TaskViewConfiguration configuration;
  final String? etag;
}

/// One per-owner row of `presentation=workload`'s `data` (spec §5,
/// `TaskWorkloadQuery::rows`). `ownerId: null` is the explicit Unassigned
/// row — surfaced, never dropped.
final class TaskWorkloadRow {
  const TaskWorkloadRow({
    required this.ownerId,
    required this.ownerName,
    required this.units,
  });

  factory TaskWorkloadRow.fromJson(Map<String, Object?> json) =>
      TaskWorkloadRow(
        ownerId: _nullableString(json, 'owner_id'),
        ownerName: _nullableString(json, 'owner_name'),
        units: _objects(
          json,
          'units',
        ).map(TaskWorkloadUnitCount.fromJson).toList(growable: false),
      );

  final String? ownerId;
  final String? ownerName;
  final List<TaskWorkloadUnitCount> units;
}

/// A task count on one day or week bucket. COUNTS TASKS, never hours —
/// there is no effort field and this contract does not invent one.
final class TaskWorkloadUnitCount {
  const TaskWorkloadUnitCount({required this.date, required this.count});

  factory TaskWorkloadUnitCount.fromJson(Map<String, Object?> json) =>
      TaskWorkloadUnitCount(
        date: _parseDateOnly(_string(json, 'date'), 'units[].date'),
        count: _integer(json, 'count'),
      );

  final DateTime date;
  final int count;
}

final class TaskRow {
  const TaskRow({
    required this.id,
    required this.boardId,
    required this.groupId,
    required this.parentTaskId,
    required this.number,
    required this.title,
    this.description,
    this.descriptionPlain,
    required this.ownerUserId,
    required this.collaboratorUserIds,
    required this.statusOptionId,
    required this.priorityOptionId,
    required this.startOn,
    required this.dueOn,
    required this.position,
    required this.version,
    required this.detailVersion,
    required this.values,
    required this.groupByValue,
    required this.subtasks,
    this.blockedBy,
  });

  factory TaskRow.fromJson(Map<String, Object?> json, {TaskBoard? board}) {
    final rawValues =
        _optionalObject(json, 'custom_values') ?? const <String, Object?>{};
    final values = <String, TaskCellValue>{};
    for (final entry in rawValues.entries) {
      values[entry.key] = TaskCellValue.fromJson(
        entry.value,
        definition: board?.column(entry.key),
      );
    }

    return TaskRow(
      id: _string(json, 'id'),
      boardId: _string(json, 'board_id'),
      groupId: _nullableString(json, 'group_id'),
      parentTaskId: _nullableString(json, 'parent_task_id'),
      number: _string(json, 'number'),
      title: _string(json, 'title'),
      description: json.containsKey('description')
          ? _nullableString(json, 'description')
          : null,
      descriptionPlain: json.containsKey('description_plain')
          ? _nullableString(json, 'description_plain')
          : null,
      ownerUserId: _nullableString(json, 'owner_user_id'),
      collaboratorUserIds: _optionalStrings(json, 'collaborator_user_ids'),
      statusOptionId: _nullableString(json, 'status_option_id'),
      priorityOptionId: _nullableString(json, 'priority_option_id'),
      startOn: _nullableDateOnly(json, 'start_on'),
      dueOn: _nullableDateOnly(json, 'due_on'),
      position: _integer(json, 'position'),
      version: _integer(json, 'version'),
      detailVersion: _integer(json, 'detail_version'),
      values: Map.unmodifiable(values),
      groupByValue: json['group_by_value'],
      subtasks: (_optionalObjects(json, 'subtasks') ?? const [])
          .map((row) => TaskRow.fromJson(row, board: board))
          .toList(growable: false),
      // `blocked_by` rides on the row only for the Timeline presentation and
      // on the task detail response (task-4 brief) — deliberately absent
      // everywhere else. `null` here means "this response never carried the
      // key"; an empty list means "carried, and this task blocks on
      // nothing" — the two are not the same thing.
      blockedBy: json.containsKey('blocked_by')
          ? _objects(json, 'blocked_by')
              .map(TaskBlockedByRef.fromJson)
              .toList(growable: false)
          : null,
    );
  }

  final String id;
  final String boardId;
  final String? groupId;
  final String? parentTaskId;
  final String number;
  final String title;
  final String? description;
  final String? descriptionPlain;
  final String? ownerUserId;
  final List<String> collaboratorUserIds;
  final String? statusOptionId;
  final String? priorityOptionId;
  final DateTime? startOn;
  final DateTime? dueOn;
  final int position;
  final int version;
  final int detailVersion;
  final Map<String, TaskCellValue> values;
  final Object? groupByValue;
  final List<TaskRow> subtasks;
  final List<TaskBlockedByRef>? blockedBy;
}

/// One blocking edge, as it appears in `blocked_by` — the blocker's identity
/// only, not the edge's own concurrency version (dependencies carry no
/// version; §4 task-4 brief).
final class TaskBlockedByRef {
  const TaskBlockedByRef({
    required this.id,
    required this.taskId,
    required this.number,
    required this.title,
  });

  factory TaskBlockedByRef.fromJson(Map<String, Object?> json) =>
      TaskBlockedByRef(
        id: _string(json, 'id'),
        taskId: _string(json, 'task_id'),
        number: _nullableString(json, 'number'),
        title: _nullableString(json, 'title'),
      );

  final String id;
  final String taskId;
  final String? number;
  final String? title;
}

final class TaskSubtaskSummary {
  const TaskSubtaskSummary({
    required this.id,
    required this.title,
    required this.statusOptionId,
    required this.version,
    required this.archived,
  });

  factory TaskSubtaskSummary.fromJson(Map<String, Object?> json) =>
      TaskSubtaskSummary(
        id: _string(json, 'id'),
        title: _string(json, 'title'),
        statusOptionId: _nullableString(json, 'status_option_id'),
        version: _integer(json, 'version'),
        archived: _optionalBoolean(json, 'archived') ?? false,
      );

  final String id;
  final String title;
  final String? statusOptionId;
  final int version;
  final bool archived;
}

final class TaskCellValue {
  const TaskCellValue({
    required this.kind,
    required this.value,
    required this.rawValue,
    required this.isReadOnly,
  });

  factory TaskCellValue.fromJson(
    Object? raw, {
    TaskColumnDefinition? definition,
  }) {
    final kind = definition?.kind ?? TaskColumnKind.unknown;
    if (kind == TaskColumnKind.unknown) {
      return TaskCellValue(
        kind: kind,
        value: raw,
        rawValue: raw,
        isReadOnly: true,
      );
    }
    if (raw == null) {
      return TaskCellValue(
        kind: kind,
        value: null,
        rawValue: null,
        isReadOnly: false,
      );
    }

    Object? value;
    try {
      value = switch (kind) {
        TaskColumnKind.text => _expectType<String>(raw, 'custom text value'),
        TaskColumnKind.number => _numberValue(raw),
        TaskColumnKind.checkbox => _expectType<bool>(
          raw,
          'custom checkbox value',
        ),
        TaskColumnKind.date => _parseDateOnlyValue(raw, 'custom date value'),
        TaskColumnKind.people ||
        TaskColumnKind.label ||
        TaskColumnKind.file => _stringList(raw, 'custom list value'),
        TaskColumnKind.link => TaskLinkValue.fromJson(
          _expectMap(raw, 'custom link value'),
        ),
        TaskColumnKind.unknown => raw,
      };
    } on FormatException {
      return TaskCellValue(
        kind: kind,
        value: raw,
        rawValue: raw,
        isReadOnly: true,
      );
    }
    return TaskCellValue(
      kind: kind,
      value: value,
      rawValue: raw,
      isReadOnly: false,
    );
  }

  final TaskColumnKind kind;
  final Object? value;
  final Object? rawValue;
  final bool isReadOnly;
}

final class TaskLinkValue {
  const TaskLinkValue({required this.url, required this.label});

  factory TaskLinkValue.fromJson(Map<String, Object?> json) => TaskLinkValue(
    url: _string(json, 'url'),
    label: _nullableString(json, 'label'),
  );

  final String url;
  final String? label;

  @override
  bool operator ==(Object other) =>
      other is TaskLinkValue && other.url == url && other.label == label;

  @override
  int get hashCode => Object.hash(url, label);
}

final class TaskGroupCount {
  const TaskGroupCount({
    required this.groupId,
    required this.value,
    required this.count,
  });

  factory TaskGroupCount.fromJson(Map<String, Object?> json) => TaskGroupCount(
    groupId: _nullableString(json, 'group_id'),
    value: json['group_by_value'],
    count: _integer(json, 'count'),
  );

  final String? groupId;
  final Object? value;
  final int count;
}

final class TaskStatusSummary {
  const TaskStatusSummary({required this.statusOptionId, required this.count});

  factory TaskStatusSummary.fromJson(Map<String, Object?> json) =>
      TaskStatusSummary(
        statusOptionId: _nullableString(json, 'status_option_id'),
        count: _integer(json, 'count'),
      );

  final String? statusOptionId;
  final int count;
}

final class TaskViewDefinition {
  const TaskViewDefinition({
    required this.id,
    required this.boardId,
    required this.ownerUserId,
    required this.visibility,
    required this.rawVisibility,
    required this.name,
    required this.isLocked,
    required this.isPermanentMain,
    required this.configuration,
    required this.version,
    required this.position,
    required this.preference,
    required this.actions,
  });

  factory TaskViewDefinition.fromJson(Map<String, Object?> json) {
    final rawVisibility = _string(json, 'visibility');
    final preference = json['preference'];
    return TaskViewDefinition(
      id: _string(json, 'id'),
      boardId: _string(json, 'board_id'),
      ownerUserId: _nullableString(json, 'owner_user_id'),
      visibility: _viewVisibility(rawVisibility),
      rawVisibility: rawVisibility,
      name: _string(json, 'name'),
      isLocked: _boolean(json, 'is_locked'),
      isPermanentMain: _boolean(json, 'is_permanent_main'),
      configuration: TaskViewConfiguration.fromJson(
        _object(json, 'configuration'),
      ),
      version: _integer(json, 'version'),
      position: _integer(json, 'position'),
      preference: preference == null
          ? null
          : TaskViewPreference.fromJson(_expectMap(preference, 'preference')),
      actions: _optionalStrings(json, 'actions'),
    );
  }

  final String id;
  final String boardId;
  final String? ownerUserId;
  final TaskViewVisibility visibility;
  final String rawVisibility;
  final String name;
  final bool isLocked;
  final bool isPermanentMain;
  final TaskViewConfiguration configuration;
  final int version;
  final int position;
  final TaskViewPreference? preference;
  final List<String> actions;

  bool can(String action) => actions.contains(action);
}

final class TaskViewsSnapshot {
  const TaskViewsSnapshot({
    required this.views,
    required this.canCreatePersonal,
    required this.canCreateShared,
  });

  final List<TaskViewDefinition> views;
  final bool canCreatePersonal;
  final bool canCreateShared;
}

final class TaskViewConfiguration {
  const TaskViewConfiguration({
    required this.schema,
    required this.presentation,
    required this.rawPresentation,
    this.window,
    required this.filters,
    required this.sorts,
    required this.hiddenColumns,
    required this.columnOrder,
    required this.columnWidths,
    required this.groupBy,
    required this.search,
  });

  static const empty = TaskViewConfiguration(
    schema: 1,
    presentation: TaskPresentation.table,
    rawPresentation: 'table',
    window: null,
    filters: <TaskFilter>[],
    sorts: <TaskSort>[],
    hiddenColumns: <String>[],
    columnOrder: <String>['owner', 'status', 'priority', 'start_on', 'due_on'],
    columnWidths: <String, int>{},
    groupBy: null,
    search: '',
  );

  factory TaskViewConfiguration.fromJson(Map<String, Object?> json) {
    final rawPresentation = _string(json, 'presentation');
    final presentation = _presentationFromWire(rawPresentation);
    final schema = _integer(json, 'schema');
    // `window` is a schema:2-only field (TaskViewCodec::SCHEMA_ONE_FIELDS
    // excludes it entirely) — a schema:1 row, including every board's
    // seeded default Main view, carries no window key at all.
    final rawWindow = schema == 2 ? _optionalObject(json, 'window') : null;
    return TaskViewConfiguration(
      schema: schema,
      presentation: presentation,
      rawPresentation: rawPresentation,
      window: TaskWindow.decode(presentation, rawWindow),
      filters: _objects(
        json,
        'filters',
      ).map(TaskFilter.fromJson).toList(growable: false),
      sorts: _objects(
        json,
        'sorts',
      ).map(TaskSort.fromJson).toList(growable: false),
      // Task is the permanent pinned identity column and never participates in
      // scrollable layout mutations, including legacy saved configurations.
      hiddenColumns: _strings(
        json,
        'hidden_columns',
      ).where((key) => key != 'task').toList(growable: false),
      columnOrder: _strings(
        json,
        'column_order',
      ).where((key) => key != 'task').toList(growable: false),
      columnWidths: Map.unmodifiable(
        _object(json, 'column_widths').map(
          (key, value) => MapEntry(
            key,
            _expectType<int>(value, 'configuration.column_widths.$key'),
          ),
        )..remove('task'),
      ),
      groupBy: _nullableString(json, 'group_by'),
      search: _string(json, 'search'),
    );
  }

  Map<String, Object?> toJson() => <String, Object?>{
    'schema': schema,
    'presentation': rawPresentation,
    'filters': filters.map((filter) => filter.toJson()).toList(growable: false),
    'sorts': sorts.map((sort) => sort.toJson()).toList(growable: false),
    'hidden_columns': hiddenColumns.where((key) => key != 'task').toList(),
    'column_order': columnOrder.where((key) => key != 'task').toList(),
    'column_widths': Map<String, int>.from(columnWidths)..remove('task'),
    'group_by': groupBy,
    'search': search,
    // A schema:1 row must never carry a `window` key at all — the server
    // rejects any key outside TaskViewCodec::SCHEMA_ONE_FIELDS.
    if (schema == 2) 'window': window?.toJson(),
  };

  final int schema;
  final TaskPresentation presentation;
  final String rawPresentation;
  final TaskWindow? window;
  final List<TaskFilter> filters;
  final List<TaskSort> sorts;
  final List<String> hiddenColumns;
  final List<String> columnOrder;
  final Map<String, int> columnWidths;
  final String? groupBy;
  final String search;

  TaskViewConfiguration copyWith({
    TaskPresentation? presentation,
    Object? window = _taskAbsent,
    List<TaskFilter>? filters,
    List<TaskSort>? sorts,
    List<String>? hiddenColumns,
    List<String>? columnOrder,
    Map<String, int>? columnWidths,
    Object? groupBy = _taskAbsent,
    String? search,
  }) {
    final resolvedPresentation = presentation ?? this.presentation;
    final resolvedWindow = identical(window, _taskAbsent)
        ? this.window
        : window as TaskWindow?;
    // Only Table with no window can still be schema:1 — everything else
    // needs the schema:2 field set (TaskViewCodec::decode requires exactly
    // schema 2's fields whenever `window` is present or presentation is not
    // table).
    final resolvedSchema =
        resolvedPresentation == TaskPresentation.table && resolvedWindow == null
        ? schema
        : 2;
    return TaskViewConfiguration(
      schema: resolvedSchema,
      presentation: resolvedPresentation,
      rawPresentation: presentation == null
          ? rawPresentation
          : resolvedPresentation.wire,
      window: resolvedWindow,
      filters: filters ?? this.filters,
      sorts: sorts ?? this.sorts,
      hiddenColumns: hiddenColumns ?? this.hiddenColumns,
      columnOrder: columnOrder ?? this.columnOrder,
      columnWidths: columnWidths ?? this.columnWidths,
      groupBy: identical(groupBy, _taskAbsent)
          ? this.groupBy
          : groupBy as String?,
      search: search ?? this.search,
    );
  }
}

const Object _taskAbsent = Object();

final class TaskViewMutationResult {
  const TaskViewMutationResult({
    required this.definition,
    required this.structureVersion,
  });

  final TaskViewDefinition definition;
  final int? structureVersion;
}

final class TaskViewPreferenceMutation {
  const TaskViewPreferenceMutation({
    required this.collapsedGroupIds,
    required this.density,
    required this.isLastActive,
    required this.scrollLeft,
    required this.scrollTop,
  });

  Map<String, Object?> toJson() => <String, Object?>{
    'collapsed_group_ids': collapsedGroupIds,
    'density': density == TaskViewDensity.comfortable
        ? 'comfortable'
        : 'compact',
    'is_last_active': isLastActive,
    'scroll_left': scrollLeft,
    'scroll_top': scrollTop,
  };

  final List<String> collapsedGroupIds;
  final TaskViewDensity density;
  final bool isLastActive;
  final int scrollLeft;
  final int scrollTop;
}

final class TaskMyTask {
  const TaskMyTask({
    required this.task,
    required this.boardId,
    required this.boardName,
  });

  factory TaskMyTask.fromJson(Map<String, Object?> json) {
    final board = _object(json, 'board');
    return TaskMyTask(
      task: TaskRow.fromJson(json),
      boardId: _string(board, 'id'),
      boardName: _string(board, 'name'),
    );
  }

  final TaskRow task;
  final String boardId;
  final String boardName;
}

final class TaskMyTasksPage {
  const TaskMyTasksPage({
    required this.items,
    required this.total,
    required this.page,
    required this.perPage,
    required this.lastPage,
    required this.etag,
  });

  factory TaskMyTasksPage.fromJson(
    Map<String, Object?> json, {
    required String? etag,
  }) {
    final meta = _object(json, 'meta');
    return TaskMyTasksPage(
      items: _objects(
        json,
        'data',
      ).map(TaskMyTask.fromJson).toList(growable: false),
      total: _integer(meta, 'total'),
      page: _integer(meta, 'page'),
      perPage: _integer(meta, 'per_page'),
      lastPage: _integer(meta, 'last_page'),
      etag: etag,
    );
  }

  final List<TaskMyTask> items;
  final int total;
  final int page;
  final int perPage;
  final int lastPage;
  final String? etag;
}

/// `GET /tasks/my` with a non-Table `configuration.presentation` — Task 14's
/// cross-board Calendar, Timeline or Workload, reachable the way My Tasks
/// already is rather than opening one board. Every row here already carries
/// which board it came from (`TaskMyTask.boardName`), the same shape
/// `TaskMyTask.fromJson` already builds for Table's `myTasks()` — this
/// contract reuses that parse rather than a second one.
///
/// `isWorkload` picks between the two shapes exactly the way
/// `TaskRowsPage.fromJson` already does: Workload's `data` holds per-owner
/// unit rows, not task rows, so forcing it through `TaskMyTask.fromJson`
/// (which requires `id`, `title`, etc.) would throw.
final class TaskCrossBoardPage {
  const TaskCrossBoardPage({
    required this.presentation,
    required this.items,
    required this.workloadRows,
    required this.total,
    required this.page,
    required this.perPage,
    required this.lastPage,
    required this.omitted,
    required this.window,
    this.etag,
  });

  factory TaskCrossBoardPage.fromJson(
    Map<String, Object?> json, {
    required TaskPresentation presentation,
    String? etag,
  }) {
    final meta = _object(json, 'meta');
    final isWorkload = presentation == TaskPresentation.workload;
    final omittedJson = _optionalObject(meta, 'omitted');
    return TaskCrossBoardPage(
      presentation: presentation,
      items: isWorkload
          ? const <TaskMyTask>[]
          : _objects(json, 'data').map(TaskMyTask.fromJson).toList(growable: false),
      workloadRows: isWorkload
          ? _objects(json, 'data').map(TaskWorkloadRow.fromJson).toList(growable: false)
          : const <TaskWorkloadRow>[],
      total: _integer(meta, 'total'),
      page: _integer(meta, 'page'),
      perPage: _integer(meta, 'per_page'),
      lastPage: _integer(meta, 'last_page'),
      omitted: omittedJson == null ? TaskOmitted.zero : TaskOmitted.fromJson(omittedJson),
      window: TaskWindow.decode(presentation, _optionalObject(meta, 'window')),
      etag: etag,
    );
  }

  final TaskPresentation presentation;
  final List<TaskMyTask> items;
  final List<TaskWorkloadRow> workloadRows;
  final int total;
  final int page;
  final int perPage;
  final int lastPage;
  final TaskOmitted omitted;
  final TaskWindow? window;
  final String? etag;
}

final class TaskQuickSearchResult {
  const TaskQuickSearchResult({
    required this.boardId,
    required this.boardName,
    required this.taskId,
    required this.number,
    required this.title,
    required this.deepLink,
  });

  factory TaskQuickSearchResult.fromJson(Map<String, Object?> json) =>
      TaskQuickSearchResult(
        boardId: _string(json, 'board_id'),
        boardName: _string(json, 'board_name'),
        taskId: _string(json, 'task_id'),
        number: _string(json, 'number'),
        title: _string(json, 'title'),
        deepLink: _string(json, 'deep_link'),
      );

  final String boardId;
  final String boardName;
  final String taskId;
  final String number;
  final String title;
  final String deepLink;
}

final class TaskFilter {
  const TaskFilter({
    required this.field,
    required this.operator,
    required this.value,
  });

  factory TaskFilter.fromJson(Map<String, Object?> json) => TaskFilter(
    field: _string(json, 'field'),
    operator: _string(json, 'operator'),
    value: json['value'],
  );

  Map<String, Object?> toJson() => <String, Object?>{
    'field': field,
    'operator': operator,
    'value': value,
  };

  final String field;
  final String operator;
  final Object? value;
}

final class TaskSort {
  const TaskSort({
    required this.field,
    required this.direction,
    required this.rawDirection,
  });

  factory TaskSort.fromJson(Map<String, Object?> json) {
    final rawDirection = _string(json, 'direction');
    return TaskSort(
      field: _string(json, 'field'),
      direction: switch (rawDirection) {
        'asc' => TaskSortDirection.asc,
        'desc' => TaskSortDirection.desc,
        _ => TaskSortDirection.unknown,
      },
      rawDirection: rawDirection,
    );
  }

  Map<String, Object?> toJson() => <String, Object?>{
    'field': field,
    'direction': rawDirection,
  };

  final String field;
  final TaskSortDirection direction;
  final String rawDirection;
}

final class TaskViewPreference {
  const TaskViewPreference({
    required this.viewId,
    required this.collapsedGroupIds,
    required this.density,
    required this.rawDensity,
    required this.isLastActive,
    required this.scrollLeft,
    required this.scrollTop,
  });

  factory TaskViewPreference.fromJson(Map<String, Object?> json) {
    final rawDensity = _string(json, 'density');
    return TaskViewPreference(
      viewId: _string(json, 'view_id'),
      collapsedGroupIds: _strings(json, 'collapsed_group_ids'),
      density: switch (rawDensity) {
        'compact' => TaskViewDensity.compact,
        'comfortable' => TaskViewDensity.comfortable,
        _ => TaskViewDensity.unknown,
      },
      rawDensity: rawDensity,
      isLastActive: _boolean(json, 'is_last_active'),
      scrollLeft: _integer(json, 'scroll_left'),
      scrollTop: _integer(json, 'scroll_top'),
    );
  }

  final String viewId;
  final List<String> collapsedGroupIds;
  final TaskViewDensity density;
  final String rawDensity;
  final bool isLastActive;
  final int scrollLeft;
  final int scrollTop;
}

final class TaskRecordLink {
  const TaskRecordLink({
    required this.linkId,
    required this.available,
    required this.typeLabel,
    required this.recordId,
    required this.label,
    required this.subtitle,
    required this.route,
  });

  factory TaskRecordLink.fromJson(Map<String, Object?> json) {
    final available = _boolean(json, 'available');
    if (!available) {
      final allowed = <String>{'link_id', 'available', 'type'};
      if (json.keys.any((key) => !allowed.contains(key))) {
        throw const FormatException(
          'An unavailable task record link must not include target identity.',
        );
      }
      return TaskRecordLink(
        linkId: _string(json, 'link_id'),
        available: false,
        typeLabel: _string(json, 'type'),
        recordId: null,
        label: null,
        subtitle: null,
        route: null,
      );
    }

    return TaskRecordLink(
      linkId: _string(json, 'link_id'),
      available: true,
      typeLabel: _string(json, 'type'),
      recordId: _string(json, 'id'),
      label: _string(json, 'label'),
      subtitle: _nullableString(json, 'subtitle'),
      route: _nullableString(json, 'route'),
    );
  }

  final String linkId;
  final bool available;
  final String typeLabel;
  final String? recordId;
  final String? label;
  final String? subtitle;
  final String? route;
}

/// A type the server has explicitly made available for governed link search.
/// The key is opaque client input; its label is server-owned presentation.
final class TaskRecordLinkType {
  const TaskRecordLinkType({required this.key, required this.label});

  factory TaskRecordLinkType.fromJson(Map<String, Object?> json) =>
      TaskRecordLinkType(
        key: _string(json, 'key'),
        label: _string(json, 'label'),
      );

  final String key;
  final String label;
}

/// A record the server has both authorized and scoped to this board/view.
/// Route is deliberately parsed as server metadata, never derived from type/ID.
final class TaskRecordLinkCandidate {
  const TaskRecordLinkCandidate({
    required this.id,
    required this.type,
    required this.label,
    required this.subtitle,
    required this.route,
  });

  factory TaskRecordLinkCandidate.fromJson(Map<String, Object?> json) =>
      TaskRecordLinkCandidate(
        id: _string(json, 'id'),
        type: _string(json, 'type'),
        label: _string(json, 'label'),
        subtitle: _nullableString(json, 'subtitle'),
        route: _nullableString(json, 'route'),
      );

  final String id;
  final String type;
  final String label;
  final String? subtitle;
  final String? route;
}

/// The response shape from `POST tasks/{task}/dependencies` (task-4 brief) —
/// a same-board blocking edge, display-only: it enforces nothing server-side
/// and carries no concurrency version of its own.
final class TaskDependency {
  const TaskDependency({
    required this.id,
    required this.boardId,
    required this.blockerTaskId,
    required this.blockedTaskId,
  });

  factory TaskDependency.fromJson(Map<String, Object?> json) => TaskDependency(
    id: _string(json, 'id'),
    boardId: _string(json, 'board_id'),
    blockerTaskId: _string(json, 'blocker_task_id'),
    blockedTaskId: _string(json, 'blocked_task_id'),
  );

  final String id;
  final String boardId;
  final String blockerTaskId;
  final String blockedTaskId;
}

final class TaskUpdate {
  const TaskUpdate({
    required this.id,
    required this.authorUserId,
    required this.body,
    required this.bodyPlain,
    required this.version,
    required this.mentionUserIds,
    this.attachments = const <TaskAttachment>[],
    this.versions = const <TaskUpdateVersion>[],
    this.actions = const <String>[],
    required this.createdAt,
    required this.updatedAt,
  });

  factory TaskUpdate.fromJson(Map<String, Object?> json) => TaskUpdate(
    id: _string(json, 'id'),
    authorUserId: _string(json, 'author_user_id'),
    body: _nullableString(json, 'body'),
    bodyPlain: _nullableString(json, 'body_plain'),
    version: _integer(json, 'version'),
    mentionUserIds: _strings(json, 'mention_user_ids'),
    attachments: (_optionalObjects(json, 'attachments') ?? const [])
        .map(TaskAttachment.fromJson)
        .toList(growable: false),
    versions: (_optionalObjects(json, 'versions') ?? const [])
        .map(TaskUpdateVersion.fromJson)
        .toList(growable: false),
    actions: _optionalStrings(json, 'actions'),
    createdAt: _dateTime(json, 'created_at'),
    updatedAt: _dateTime(json, 'updated_at'),
  );

  final String id;
  final String authorUserId;
  final String? body;
  final String? bodyPlain;
  final int version;
  final List<String> mentionUserIds;
  final List<TaskAttachment> attachments;
  final List<TaskUpdateVersion> versions;
  final List<String> actions;
  final DateTime createdAt;
  final DateTime updatedAt;

  bool can(String action) => actions.contains(action);
}

final class TaskAttachment {
  const TaskAttachment({
    required this.id,
    required this.originalName,
    required this.mime,
    required this.sizeBytes,
    required this.checksum,
    required this.uploadedByUserId,
    required this.uploadedByName,
    required this.uploadedAt,
  });

  factory TaskAttachment.fromJson(Map<String, Object?> json) {
    final uploader = _optionalObject(json, 'uploaded_by');
    return TaskAttachment(
      id: _string(json, 'id'),
      originalName: _string(json, 'original_name'),
      mime: _string(json, 'mime'),
      sizeBytes: _integer(json, 'size_bytes'),
      checksum: _string(json, 'checksum'),
      uploadedByUserId: uploader == null
          ? null
          : _nullableString(uploader, 'id'),
      uploadedByName: uploader == null
          ? null
          : _nullableString(uploader, 'name'),
      uploadedAt: _nullableDateTime(json, 'uploaded_at'),
    );
  }

  final String id;
  final String originalName;
  final String mime;
  final int sizeBytes;
  final String checksum;
  final String? uploadedByUserId;
  final String? uploadedByName;
  final DateTime? uploadedAt;
}

final class TaskAttachmentCandidate {
  const TaskAttachmentCandidate({
    required this.id,
    required this.originalName,
    required this.mime,
    required this.sizeBytes,
    required this.checksum,
    required this.uploadedAt,
    required this.expiresAt,
  });

  factory TaskAttachmentCandidate.fromJson(Map<String, Object?> json) =>
      TaskAttachmentCandidate(
        id: _string(json, 'id'),
        originalName: _string(json, 'original_name'),
        mime: _string(json, 'mime'),
        sizeBytes: _integer(json, 'size_bytes'),
        checksum: _string(json, 'checksum'),
        uploadedAt: _nullableDateTime(json, 'uploaded_at'),
        expiresAt: _nullableDateTime(json, 'expires_at'),
      );

  final String id;
  final String originalName;
  final String mime;
  final int sizeBytes;
  final String checksum;
  final DateTime? uploadedAt;
  final DateTime? expiresAt;
}

final class TaskUpdateVersion {
  const TaskUpdateVersion({
    required this.version,
    required this.body,
    required this.bodyPlain,
    required this.editedBy,
    required this.createdAt,
  });

  factory TaskUpdateVersion.fromJson(Map<String, Object?> json) =>
      TaskUpdateVersion(
        version: _integer(json, 'version'),
        body: _string(json, 'body'),
        bodyPlain: _string(json, 'body_plain'),
        editedBy: _nullableString(json, 'edited_by'),
        createdAt: _nullableDateTime(json, 'created_at'),
      );

  final int version;
  final String body;
  final String bodyPlain;
  final String? editedBy;
  final DateTime? createdAt;
}

enum TaskWatchChoice { automatic, watched, muted }

final class TaskWatchState {
  const TaskWatchState({
    required this.preference,
    required this.mandatory,
    required this.watched,
  });

  factory TaskWatchState.fromJson(Map<String, Object?> json) => TaskWatchState(
    preference: _watchChoice(_nullableString(json, 'preference')),
    mandatory: _boolean(json, 'mandatory'),
    watched: _boolean(json, 'watched'),
  );

  static const none = TaskWatchState(
    preference: TaskWatchChoice.automatic,
    mandatory: false,
    watched: false,
  );

  final TaskWatchChoice preference;
  final bool mandatory;
  final bool watched;
}

final class TaskWatchMutation {
  const TaskWatchMutation({required this.taskId, required this.preference});

  factory TaskWatchMutation.fromJson(Map<String, Object?> json) =>
      TaskWatchMutation(
        taskId: _string(json, 'task_id'),
        preference: _watchChoice(_nullableString(json, 'preference')),
      );

  final String taskId;
  final TaskWatchChoice preference;
}

final class TaskActivity {
  const TaskActivity({
    required this.id,
    required this.actorUserId,
    required this.kind,
    required this.summary,
    required this.data,
    required this.createdAt,
  });

  factory TaskActivity.fromJson(Map<String, Object?> json) => TaskActivity(
    id: _string(json, 'id'),
    actorUserId: _nullableString(json, 'actor_user_id'),
    kind: _string(json, 'kind'),
    summary: _string(json, 'summary'),
    data: _optionalObject(json, 'data') ?? const <String, Object?>{},
    createdAt: _nullableDateTime(json, 'created_at'),
  );

  final String id;
  final String? actorUserId;
  final String kind;
  final String summary;
  final Map<String, Object?> data;
  final DateTime? createdAt;
}

/// Mirrors `App\Support\Tasks\TaskRecurrenceCodec`'s `{mode, every, unit,
/// until, count}` exactly (task-5 brief). Read and written by key, matching
/// the server codec — this is used both to decode a task detail's
/// declaration and to encode one for `TaskApi.setRecurrence`.
enum TaskRecurrenceMode { afterCompletion, onSchedule }

enum TaskRecurrenceUnit { day, week, month }

TaskRecurrenceMode _recurrenceModeFromWire(String raw) => switch (raw) {
  'after_completion' => TaskRecurrenceMode.afterCompletion,
  'on_schedule' => TaskRecurrenceMode.onSchedule,
  _ => throw FormatException('recurrence.mode has an unsupported value: $raw.'),
};

String _recurrenceModeWire(TaskRecurrenceMode mode) => switch (mode) {
  TaskRecurrenceMode.afterCompletion => 'after_completion',
  TaskRecurrenceMode.onSchedule => 'on_schedule',
};

TaskRecurrenceUnit _recurrenceUnitFromWire(String raw) => switch (raw) {
  'day' => TaskRecurrenceUnit.day,
  'week' => TaskRecurrenceUnit.week,
  'month' => TaskRecurrenceUnit.month,
  _ => throw FormatException('recurrence.unit has an unsupported value: $raw.'),
};

String _recurrenceUnitWire(TaskRecurrenceUnit unit) => switch (unit) {
  TaskRecurrenceUnit.day => 'day',
  TaskRecurrenceUnit.week => 'week',
  TaskRecurrenceUnit.month => 'month',
};

final class TaskRecurrence {
  const TaskRecurrence({
    required this.mode,
    required this.every,
    required this.unit,
    this.until,
    this.count,
  });

  factory TaskRecurrence.fromJson(Map<String, Object?> json) => TaskRecurrence(
    mode: _recurrenceModeFromWire(_string(json, 'mode')),
    every: _integer(json, 'every'),
    unit: _recurrenceUnitFromWire(_string(json, 'unit')),
    until: _nullableDateOnly(json, 'until'),
    count: _optionalInteger(json, 'count'),
  );

  Map<String, Object?> toJson() => <String, Object?>{
    'mode': _recurrenceModeWire(mode),
    'every': every,
    'unit': _recurrenceUnitWire(unit),
    'until': until == null ? null : _dateOnly(until!),
    'count': count,
  };

  final TaskRecurrenceMode mode;
  final int every;
  final TaskRecurrenceUnit unit;
  final DateTime? until;
  final int? count;
}

final class TaskDetailSnapshot {
  const TaskDetailSnapshot({
    required this.task,
    required this.etag,
    this.recordLinks = const <TaskRecordLink>[],
    this.subtasks = const <TaskSubtaskSummary>[],
    this.updates = const <TaskUpdate>[],
    this.watch = TaskWatchState.none,
    this.activities = const <TaskActivity>[],
    this.actions = const <String>[],
    this.recurrence,
  });

  factory TaskDetailSnapshot.fromJson(
    Map<String, Object?> json, {
    required String? etag,
    TaskBoard? board,
  }) {
    final rowJson = Map<String, Object?>.from(json)..['subtasks'] = <Object?>[];
    return TaskDetailSnapshot(
      task: TaskRow.fromJson(rowJson, board: board),
      etag: etag,
      recordLinks: _objects(
        json,
        'record_links',
      ).map(TaskRecordLink.fromJson).toList(growable: false),
      subtasks: _objects(
        json,
        'subtasks',
      ).map(TaskSubtaskSummary.fromJson).toList(growable: false),
      updates: (_optionalObjects(json, 'updates') ?? const [])
          .map(TaskUpdate.fromJson)
          .toList(growable: false),
      watch: json['watch'] == null
          ? TaskWatchState.none
          : TaskWatchState.fromJson(_object(json, 'watch')),
      activities: (_optionalObjects(json, 'activities') ?? const [])
          .map(TaskActivity.fromJson)
          .toList(growable: false),
      actions: _optionalStrings(json, 'actions'),
      recurrence: _optionalObject(json, 'recurrence') == null
          ? null
          : TaskRecurrence.fromJson(_object(json, 'recurrence')),
    );
  }

  final TaskRow task;
  final String? etag;
  final List<TaskRecordLink> recordLinks;
  final List<TaskSubtaskSummary> subtasks;
  final List<TaskUpdate> updates;
  final TaskWatchState watch;
  final List<TaskActivity> activities;
  final List<String> actions;
  final TaskRecurrence? recurrence;

  bool can(String action) => actions.contains(action);

  TaskDetailSnapshot copyWith({
    TaskRow? task,
    String? etag,
    List<TaskUpdate>? updates,
    TaskWatchState? watch,
    List<TaskActivity>? activities,
    Object? recurrence = _taskAbsent,
  }) => TaskDetailSnapshot(
    task: task ?? this.task,
    etag: etag ?? this.etag,
    recordLinks: recordLinks,
    subtasks: subtasks,
    updates: updates ?? this.updates,
    watch: watch ?? this.watch,
    activities: activities ?? this.activities,
    actions: actions,
    recurrence: identical(recurrence, _taskAbsent)
        ? this.recurrence
        : recurrence as TaskRecurrence?,
  );
}

final class TaskMoveMutation {
  const TaskMoveMutation({
    required this.boardId,
    required this.taskId,
    required this.destinationGroupId,
    required this.expectedTaskVersion,
    required this.expectedSourceGroupOrderVersion,
    required this.expectedDestinationGroupOrderVersion,
    this.beforeTaskId,
    this.afterTaskId,
  }) : assert(beforeTaskId == null || afterTaskId == null);

  Map<String, Object?> toJson() => <String, Object?>{
    'task_id': taskId,
    'destination_group_id': destinationGroupId,
    if (beforeTaskId != null) 'before_task_id': beforeTaskId,
    if (afterTaskId != null) 'after_task_id': afterTaskId,
    'expected_task_version': expectedTaskVersion,
    'expected_source_order_version': expectedSourceGroupOrderVersion,
    'expected_destination_order_version': expectedDestinationGroupOrderVersion,
  };

  final String boardId;
  final String taskId;
  final String destinationGroupId;
  final String? beforeTaskId;
  final String? afterTaskId;
  final int expectedTaskVersion;
  final int expectedSourceGroupOrderVersion;
  final int expectedDestinationGroupOrderVersion;
}

final class TaskApiConflict {
  const TaskApiConflict({
    required this.kind,
    required this.message,
    required this.currentVersion,
    required this.currentGroupVersions,
    required this.conflictingFields,
    required this.currentTask,
  });

  factory TaskApiConflict.fromResponse({
    required int statusCode,
    required Map<String, Object?> body,
  }) {
    if (statusCode != 409) {
      throw FormatException(
        'Expected a 409 conflict response, received $statusCode.',
      );
    }
    final meta = _object(body, 'meta');
    final currentVersionEntries = <TaskConflictKind, Object?>{
      TaskConflictKind.task:
          meta['current_version'] ?? meta['current_task_version'],
      TaskConflictKind.update: meta['current_update_version'],
      TaskConflictKind.view: meta['current_view_version'],
      TaskConflictKind.structure: meta['current_structure_version'],
    };
    var kind = TaskConflictKind.unknown;
    int? currentVersion;
    for (final entry in currentVersionEntries.entries) {
      if (entry.value != null) {
        kind = entry.key;
        currentVersion = _expectType<int>(
          entry.value,
          'conflict current version',
        );
        break;
      }
    }
    final groupVersions = meta['current_group_versions'];
    Map<String, int> currentGroupVersions = const {};
    if (groupVersions != null) {
      kind = TaskConflictKind.groupOrder;
      currentGroupVersions = Map.unmodifiable(
        _expectMap(groupVersions, 'meta.current_group_versions').map(
          (key, value) => MapEntry(
            key,
            _expectType<int>(value, 'meta.current_group_versions.$key'),
          ),
        ),
      );
    }
    TaskConflictTaskValues? currentTask;
    final data = body['data'];
    if (data is Map) {
      try {
        currentTask = TaskConflictTaskValues.fromJson(
          Map<String, Object?>.from(data),
        );
      } on FormatException {
        currentTask = null;
      }
    }
    return TaskApiConflict(
      kind: kind,
      message: _string(body, 'message'),
      currentVersion: currentVersion,
      currentGroupVersions: currentGroupVersions,
      conflictingFields: _optionalStrings(meta, 'conflicting_fields'),
      currentTask: currentTask,
    );
  }

  final TaskConflictKind kind;
  final String message;
  final int? currentVersion;
  final Map<String, int> currentGroupVersions;
  final List<String> conflictingFields;
  final TaskConflictTaskValues? currentTask;
}

enum TaskConflictFieldKind {
  title,
  description,
  groupId,
  ownerUserId,
  collaboratorUserIds,
  statusOptionId,
  priorityOptionId,
  startOn,
  dueOn,
  archivedAt,
  columnDefinitionId,
  customValue,
  unknown,
}

final class TaskConflictFieldValue {
  const TaskConflictFieldValue({
    required this.name,
    required this.kind,
    required this.value,
  });

  final String name;
  final TaskConflictFieldKind kind;
  final Object? value;
}

final class TaskConflictTaskValues {
  const TaskConflictTaskValues({
    required this.id,
    required this.version,
    required this.fields,
  });

  factory TaskConflictTaskValues.fromJson(Map<String, Object?> json) {
    final id = _string(json, 'id');
    final version = _integer(json, 'version');
    final fields = <String, TaskConflictFieldValue>{};
    for (final entry in json.entries) {
      if (entry.key == 'id' || entry.key == 'version') continue;
      fields[entry.key] = TaskConflictFieldValue(
        name: entry.key,
        kind: _conflictFieldKind(entry.key),
        value: _parseConflictValue(entry.key, entry.value),
      );
    }
    return TaskConflictTaskValues(
      id: id,
      version: version,
      fields: Map<String, TaskConflictFieldValue>.unmodifiable(fields),
    );
  }

  final String id;
  final int version;
  final Map<String, TaskConflictFieldValue> fields;

  TaskConflictFieldValue? field(String name) => fields[name];
}

TaskBoardVisibility _boardVisibility(String raw) => switch (raw) {
  'scope' => TaskBoardVisibility.scope,
  'private' => TaskBoardVisibility.private,
  _ => TaskBoardVisibility.unknown,
};

TaskStatusCategory _statusCategory(String raw) => switch (raw) {
  'not_started' => TaskStatusCategory.notStarted,
  'active' => TaskStatusCategory.active,
  'blocked' => TaskStatusCategory.blocked,
  'completed' => TaskStatusCategory.completed,
  'cancelled' => TaskStatusCategory.cancelled,
  _ => TaskStatusCategory.unknown,
};

TaskPriorityRank _priorityRank(String raw) => switch (raw) {
  'low' => TaskPriorityRank.low,
  'normal' => TaskPriorityRank.normal,
  'high' => TaskPriorityRank.high,
  'urgent' => TaskPriorityRank.urgent,
  _ => TaskPriorityRank.unknown,
};

TaskWatchChoice _watchChoice(String? raw) => switch (raw) {
  'watched' => TaskWatchChoice.watched,
  'muted' => TaskWatchChoice.muted,
  null => TaskWatchChoice.automatic,
  _ => throw FormatException('preference has an unsupported value.'),
};

TaskColumnKind _columnKind(String raw) => switch (raw) {
  'text' => TaskColumnKind.text,
  'number' => TaskColumnKind.number,
  'checkbox' => TaskColumnKind.checkbox,
  'date' => TaskColumnKind.date,
  'people' => TaskColumnKind.people,
  'label' => TaskColumnKind.label,
  'link' => TaskColumnKind.link,
  'file' => TaskColumnKind.file,
  _ => TaskColumnKind.unknown,
};

num _numberValue(Object? raw) {
  if (raw is num) return raw;
  if (raw is String && RegExp(r'^-?\d+(?:\.\d+)?$').hasMatch(raw)) {
    final value = num.tryParse(raw);
    if (value != null) return value;
  }
  throw const FormatException('custom number value must be a number.');
}

TaskViewVisibility _viewVisibility(String raw) => switch (raw) {
  'personal' => TaskViewVisibility.personal,
  'shared' => TaskViewVisibility.shared,
  _ => TaskViewVisibility.unknown,
};

TaskConflictFieldKind _conflictFieldKind(String name) => switch (name) {
  'title' => TaskConflictFieldKind.title,
  'description' => TaskConflictFieldKind.description,
  'group_id' => TaskConflictFieldKind.groupId,
  'owner_user_id' => TaskConflictFieldKind.ownerUserId,
  'collaborator_user_ids' => TaskConflictFieldKind.collaboratorUserIds,
  'status_option_id' => TaskConflictFieldKind.statusOptionId,
  'priority_option_id' => TaskConflictFieldKind.priorityOptionId,
  'start_on' => TaskConflictFieldKind.startOn,
  'due_on' => TaskConflictFieldKind.dueOn,
  'archived_at' => TaskConflictFieldKind.archivedAt,
  'column_definition_id' => TaskConflictFieldKind.columnDefinitionId,
  'value' => TaskConflictFieldKind.customValue,
  _ => TaskConflictFieldKind.unknown,
};

Object? _parseConflictValue(String name, Object? value) => switch (name) {
  'title' => _expectType<String>(value, 'data.title'),
  'description' ||
  'group_id' ||
  'owner_user_id' ||
  'status_option_id' ||
  'priority_option_id' ||
  'column_definition_id' =>
    value == null ? null : _expectType<String>(value, 'data.$name'),
  'collaborator_user_ids' => _stringList(value, 'data.collaborator_user_ids'),
  'start_on' || 'due_on' =>
    value == null
        ? null
        : _parseDateOnly(
            _expectType<String>(value, 'data.$name'),
            'data.$name',
          ),
  'archived_at' =>
    value == null ? null : _parseDateTimeValue(value, 'data.archived_at'),
  // A custom value's concrete type lives in its board definition, which the
  // compact 409 payload does not repeat. Preserve that JSON losslessly.
  'value' => value,
  _ => value,
};

String _string(Map<String, Object?> json, String key) =>
    _expectType<String>(_required(json, key), key);

String? _nullableString(Map<String, Object?> json, String key) {
  final value = _required(json, key);
  return value == null ? null : _expectType<String>(value, key);
}

int _integer(Map<String, Object?> json, String key) =>
    _expectType<int>(_required(json, key), key);

int? _optionalInteger(Map<String, Object?> json, String key) {
  final value = json[key];
  return value == null ? null : _expectType<int>(value, key);
}

bool _boolean(Map<String, Object?> json, String key) =>
    _expectType<bool>(_required(json, key), key);

bool? _optionalBoolean(Map<String, Object?> json, String key) {
  final value = json[key];
  return value == null ? null : _expectType<bool>(value, key);
}

DateTime _dateTime(Map<String, Object?> json, String key) {
  return _parseDateTimeValue(_string(json, key), key);
}

DateTime? _nullableDateTime(Map<String, Object?> json, String key) {
  final raw = _nullableString(json, key);
  if (raw == null) return null;
  return _parseDateTimeValue(raw, key);
}

DateTime _parseDateTimeValue(Object? value, String path) {
  final raw = _expectType<String>(value, path);
  final parsed = DateTime.tryParse(raw);
  if (parsed == null) {
    throw FormatException('$path must be an ISO-8601 timestamp.');
  }
  return parsed;
}

DateTime? _nullableDateOnly(Map<String, Object?> json, String key) {
  final raw = _nullableString(json, key);
  return raw == null ? null : _parseDateOnly(raw, key);
}

DateTime _parseDateOnlyValue(Object? value, String path) =>
    _parseDateOnly(_expectType<String>(value, path), path);

DateTime _parseDateOnly(String raw, String path) {
  final match = RegExp(r'^(\d{4})-(\d{2})-(\d{2})$').firstMatch(raw);
  if (match == null) throw FormatException('$path must be a YYYY-MM-DD date.');
  final result = DateTime.utc(
    int.parse(match.group(1)!),
    int.parse(match.group(2)!),
    int.parse(match.group(3)!),
  );
  if ('${result.year.toString().padLeft(4, '0')}-${result.month.toString().padLeft(2, '0')}-${result.day.toString().padLeft(2, '0')}' !=
      raw) {
    throw FormatException('$path must be a real YYYY-MM-DD date.');
  }
  return result;
}

String _dateOnly(DateTime date) =>
    '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';

Map<String, Object?> _object(Map<String, Object?> json, String key) =>
    _expectMap(_required(json, key), key);

Map<String, Object?>? _optionalObject(Map<String, Object?> json, String key) {
  final value = json[key];
  return value == null ? null : _expectMap(value, key);
}

List<Map<String, Object?>> _objects(Map<String, Object?> json, String key) =>
    _objectList(_required(json, key), key);

List<Map<String, Object?>>? _optionalObjects(
  Map<String, Object?> json,
  String key,
) {
  final value = json[key];
  return value == null ? null : _objectList(value, key);
}

List<String> _strings(Map<String, Object?> json, String key) =>
    _stringList(_required(json, key), key);

List<String> _optionalStrings(Map<String, Object?> json, String key) {
  final value = json[key];
  return value == null ? const [] : _stringList(value, key);
}

Object? _required(Map<String, Object?> json, String key) {
  if (!json.containsKey(key)) {
    throw FormatException('Missing required field $key.');
  }
  return json[key];
}

T _expectType<T>(Object? value, String path) {
  if (value is! T) throw FormatException('$path has the wrong type.');
  return value;
}

Map<String, Object?> _expectMap(Object? value, String path) {
  if (value is! Map) throw FormatException('$path must be an object.');
  try {
    return Map<String, Object?>.from(value);
  } on TypeError {
    throw FormatException('$path must use string keys.');
  }
}

List<Map<String, Object?>> _objectList(Object? value, String path) {
  if (value is! List) throw FormatException('$path must be a list.');
  return value
      .map((entry) => _expectMap(entry, '$path[]'))
      .toList(growable: false);
}

List<String> _stringList(Object? value, String path) {
  if (value is! List) throw FormatException('$path must be a list.');
  return value
      .map((entry) => _expectType<String>(entry, '$path[]'))
      .toList(growable: false);
}
