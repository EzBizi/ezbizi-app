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

enum TaskViewVisibility { personal, shared, unknown }

enum TaskViewPresentation { table, unknown }

enum TaskSortDirection { asc, desc, unknown }

enum TaskViewDensity { compact, comfortable, unknown }

enum TaskConflictKind { task, update, groupOrder, view, structure, unknown }

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

  TaskColumnDefinition? column(String id) {
    for (final column in customColumns) {
      if (column.id == id) return column;
    }
    return null;
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
  });

  factory TaskGroup.fromJson(Map<String, Object?> json) => TaskGroup(
    id: _string(json, 'id'),
    name: _string(json, 'name'),
    color: _nullableString(json, 'color'),
    position: _optionalInteger(json, 'position') ?? 0,
    orderVersion: _optionalInteger(json, 'order_version') ?? 1,
    archived: _optionalBoolean(json, 'archived') ?? false,
  );

  final String id;
  final String name;
  final String? color;
  final int position;
  final int orderVersion;
  final bool archived;
}

final class TaskStatusOption {
  const TaskStatusOption({
    required this.id,
    required this.name,
    required this.color,
    required this.category,
    required this.rawCategory,
    required this.archived,
  });

  factory TaskStatusOption.fromJson(Map<String, Object?> json) {
    final rawCategory = _string(json, 'category');
    return TaskStatusOption(
      id: _string(json, 'id'),
      name: _string(json, 'name'),
      color: _nullableString(json, 'color'),
      category: _statusCategory(rawCategory),
      rawCategory: rawCategory,
      archived: _boolean(json, 'archived'),
    );
  }

  final String id;
  final String name;
  final String? color;
  final TaskStatusCategory category;
  final String rawCategory;
  final bool archived;
}

final class TaskPriorityOption {
  const TaskPriorityOption({
    required this.id,
    required this.name,
    required this.color,
    required this.rank,
    required this.rawRank,
    required this.archived,
  });

  factory TaskPriorityOption.fromJson(Map<String, Object?> json) {
    final rawRank = _string(json, 'rank');
    return TaskPriorityOption(
      id: _string(json, 'id'),
      name: _string(json, 'name'),
      color: _nullableString(json, 'color'),
      rank: _priorityRank(rawRank),
      rawRank: rawRank,
      archived: _boolean(json, 'archived'),
    );
  }

  final String id;
  final String name;
  final String? color;
  final TaskPriorityRank rank;
  final String rawRank;
  final bool archived;
}

final class TaskCoreColumn {
  const TaskCoreColumn({required this.key, required this.displayLabel});

  factory TaskCoreColumn.fromJson(Map<String, Object?> json) => TaskCoreColumn(
    key: _string(json, 'core_key'),
    displayLabel: _string(json, 'display_label'),
  );

  final String key;
  final String displayLabel;
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
      archived: _boolean(json, 'archived'),
      options: _objects(
        json,
        'options',
      ).map(TaskColumnOption.fromJson).toList(growable: false),
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

  bool get isReadOnly => kind == TaskColumnKind.unknown;
}

final class TaskColumnOption {
  const TaskColumnOption({
    required this.id,
    required this.name,
    required this.color,
    required this.position,
    required this.archived,
  });

  factory TaskColumnOption.fromJson(Map<String, Object?> json) =>
      TaskColumnOption(
        id: _string(json, 'id'),
        name: _string(json, 'name'),
        color: _nullableString(json, 'color'),
        position: _optionalInteger(json, 'position') ?? 0,
        archived: _boolean(json, 'archived'),
      );

  final String id;
  final String name;
  final String? color;
  final int position;
  final bool archived;
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
    required this.total,
    required this.page,
    required this.perPage,
    required this.lastPage,
    required this.groupCounts,
    required this.statusSummaries,
    required this.viewId,
    required this.viewVersion,
    required this.configuration,
  });

  factory TaskRowsPage.fromJson(
    Map<String, Object?> json, {
    required TaskBoard board,
  }) {
    final meta = _object(json, 'meta');
    return TaskRowsPage(
      rows: _objects(json, 'data')
          .map((row) => TaskRow.fromJson(row, board: board))
          .toList(growable: false),
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
      viewId: _string(meta, 'view_id'),
      viewVersion: _integer(meta, 'view_version'),
      configuration: TaskViewConfiguration.fromJson(
        _object(meta, 'configuration'),
      ),
    );
  }

  final List<TaskRow> rows;
  final int total;
  final int page;
  final int perPage;
  final int lastPage;
  final List<TaskGroupCount> groupCounts;
  final List<TaskStatusSummary> statusSummaries;
  final String viewId;
  final int viewVersion;
  final TaskViewConfiguration configuration;
}

final class TaskRow {
  const TaskRow({
    required this.id,
    required this.boardId,
    required this.groupId,
    required this.parentTaskId,
    required this.number,
    required this.title,
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
    );
  }

  final String id;
  final String boardId;
  final String? groupId;
  final String? parentTaskId;
  final String number;
  final String title;
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

    final value = switch (kind) {
      TaskColumnKind.text => _expectType<String>(raw, 'custom text value'),
      TaskColumnKind.number => _expectType<num>(raw, 'custom number value'),
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
}

final class TaskViewConfiguration {
  const TaskViewConfiguration({
    required this.schema,
    required this.presentation,
    required this.rawPresentation,
    required this.filters,
    required this.sorts,
    required this.hiddenColumns,
    required this.columnOrder,
    required this.columnWidths,
    required this.groupBy,
    required this.search,
  });

  factory TaskViewConfiguration.fromJson(Map<String, Object?> json) {
    final rawPresentation = _string(json, 'presentation');
    return TaskViewConfiguration(
      schema: _integer(json, 'schema'),
      presentation: rawPresentation == 'table'
          ? TaskViewPresentation.table
          : TaskViewPresentation.unknown,
      rawPresentation: rawPresentation,
      filters: _objects(
        json,
        'filters',
      ).map(TaskFilter.fromJson).toList(growable: false),
      sorts: _objects(
        json,
        'sorts',
      ).map(TaskSort.fromJson).toList(growable: false),
      hiddenColumns: _strings(json, 'hidden_columns'),
      columnOrder: _strings(json, 'column_order'),
      columnWidths: Map.unmodifiable(
        _object(json, 'column_widths').map(
          (key, value) => MapEntry(
            key,
            _expectType<int>(value, 'configuration.column_widths.$key'),
          ),
        ),
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
    'hidden_columns': hiddenColumns,
    'column_order': columnOrder,
    'column_widths': columnWidths,
    'group_by': groupBy,
    'search': search,
  };

  final int schema;
  final TaskViewPresentation presentation;
  final String rawPresentation;
  final List<TaskFilter> filters;
  final List<TaskSort> sorts;
  final List<String> hiddenColumns;
  final List<String> columnOrder;
  final Map<String, int> columnWidths;
  final String? groupBy;
  final String search;
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

final class TaskUpdate {
  const TaskUpdate({
    required this.id,
    required this.authorUserId,
    required this.body,
    required this.bodyPlain,
    required this.version,
    required this.mentionUserIds,
    required this.createdAt,
    required this.updatedAt,
  });

  factory TaskUpdate.fromJson(Map<String, Object?> json) => TaskUpdate(
    id: _string(json, 'id'),
    authorUserId: _string(json, 'author_user_id'),
    body: _string(json, 'body'),
    bodyPlain: _string(json, 'body_plain'),
    version: _integer(json, 'version'),
    mentionUserIds: _strings(json, 'mention_user_ids'),
    createdAt: _dateTime(json, 'created_at'),
    updatedAt: _dateTime(json, 'updated_at'),
  );

  final String id;
  final String authorUserId;
  final String body;
  final String bodyPlain;
  final int version;
  final List<String> mentionUserIds;
  final DateTime createdAt;
  final DateTime updatedAt;
}

final class TaskDetailSnapshot {
  const TaskDetailSnapshot({
    required this.task,
    required this.etag,
    this.recordLinks = const <TaskRecordLink>[],
    this.subtasks = const <TaskSubtaskSummary>[],
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
    );
  }

  final TaskRow task;
  final String? etag;
  final List<TaskRecordLink> recordLinks;
  final List<TaskSubtaskSummary> subtasks;
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
  if (parsed == null)
    throw FormatException('$path must be an ISO-8601 timestamp.');
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
  if (!json.containsKey(key))
    throw FormatException('Missing required field $key.');
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
