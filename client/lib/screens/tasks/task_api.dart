import 'dart:convert';

import '../../core/api_client.dart';
import 'task_models.dart';

sealed class TaskApiResult<T> {
  const TaskApiResult({required this.message, required this.correlationId});

  final String? message;
  final String? correlationId;

  T? get value => null;
  TaskApiConflict? get conflict => null;
}

final class TaskApiSuccess<T> extends TaskApiResult<T> {
  const TaskApiSuccess(this.data, {super.correlationId}) : super(message: null);

  final T data;

  @override
  T get value => data;
}

final class TaskApiNotModified<T> extends TaskApiResult<T> {
  const TaskApiNotModified({required this.etag, super.correlationId})
    : super(message: null);

  final String? etag;
}

final class TaskApiViewConflict<T> extends TaskApiResult<T> {
  const TaskApiViewConflict({
    required this.currentView,
    required this.currentVersion,
    required super.message,
    super.correlationId,
  });

  final TaskViewDefinition currentView;
  final int currentVersion;
}

final class TaskApiConflictResult<T> extends TaskApiResult<T> {
  TaskApiConflictResult(this.details, {super.correlationId})
    : super(message: details.message);

  final TaskApiConflict details;

  @override
  TaskApiConflict get conflict => details;
}

final class TaskApiUnavailable<T> extends TaskApiResult<T> {
  const TaskApiUnavailable({required super.message, super.correlationId});
}

final class TaskApiFailure<T> extends TaskApiResult<T> {
  const TaskApiFailure({
    required this.statusCode,
    required super.message,
    super.correlationId,
  });

  final int statusCode;
}

final class TaskApi {
  TaskApi(this._api);

  final ApiClient _api;
  final Map<String, TaskBoard> _boards = <String, TaskBoard>{};
  int _cacheEpoch = 0;

  void clearCache() {
    _cacheEpoch++;
    _boards.clear();
  }

  Future<TaskApiResult<List<TaskBoard>>> boardDirectory() async {
    final cacheEpoch = _cacheEpoch;
    final response = await _api.get('/tasks/boards');
    return _decode(response, (data) {
      if (data is! List) throw const FormatException('data must be a list.');
      final boards = data
          .map((entry) => TaskBoard.fromJson(_map(entry, 'data[]')))
          .toList(growable: false);
      if (cacheEpoch == _cacheEpoch) {
        for (final board in boards) {
          _boards[board.id] = board;
        }
      }
      return boards;
    });
  }

  Future<TaskApiResult<TaskBoard>> board(String boardId) async {
    final cacheEpoch = _cacheEpoch;
    final response = await _api.get('/tasks/boards/$boardId');
    return _decode(response, (data) {
      final board = TaskBoard.fromJson(_map(data, 'data'));
      if (cacheEpoch == _cacheEpoch) _boards[board.id] = board;
      return board;
    });
  }

  /// The server derives the active filing view. No company or franchise ID is
  /// accepted here, so a board can never be created into another reachable
  /// sibling from this client.
  Future<TaskApiResult<TaskBoard>> createBoard({
    required String name,
    required TaskBoardVisibility visibility,
    String? description,
  }) async {
    final cacheEpoch = _cacheEpoch;
    final response = await _api.post('/tasks/boards', <String, dynamic>{
      'name': name,
      'visibility': visibility == TaskBoardVisibility.private
          ? 'private'
          : 'scope',
      'description': ?description,
    });
    return _decode(response, (data) {
      final board = TaskBoard.fromJson(_map(data, 'data'));
      if (cacheEpoch == _cacheEpoch) _boards[board.id] = board;
      return board;
    });
  }

  Future<TaskApiResult<TaskRowsPage>> boardRows(
    String boardId, {
    String? viewId,
    TaskViewConfiguration? configuration,
    int page = 1,
    int perPage = 50,
    String? etag,
  }) async {
    final definition = _boards[boardId];
    if (definition == null) {
      return const TaskApiFailure<TaskRowsPage>(
        statusCode: 0,
        message: 'Load the board definition before loading its rows.',
      );
    }
    final response = await _api
        .get('/tasks/boards/$boardId/rows', <String, String>{
          'view_id': ?viewId,
          if (configuration case final configuration?)
            'configuration': jsonEncode(configuration.toJson()),
          'page': '$page',
          'per_page': '$perPage',
        }, _etagHeader(etag));
    if (response.statusCode == 304) {
      return TaskApiNotModified<TaskRowsPage>(
        etag: response.headers['etag'] ?? etag,
        correlationId: response.correlationId,
      );
    }
    return _decode(
      response,
      (_) => TaskRowsPage.fromJson(
        Map<String, Object?>.from(response.body),
        board: definition,
        etag: response.headers['etag'],
      ),
    );
  }

  Future<TaskApiResult<TaskDetailSnapshot>> taskDetail(
    String boardId,
    String taskId, {
    String? etag,
  }) async {
    final response = await _api.get(
      '/tasks/boards/$boardId/tasks/$taskId',
      null,
      _etagHeader(etag),
    );
    if (response.statusCode == 304) {
      return TaskApiNotModified<TaskDetailSnapshot>(
        etag: response.headers['etag'] ?? etag,
        correlationId: response.correlationId,
      );
    }
    return _decode(
      response,
      (data) => TaskDetailSnapshot.fromJson(
        _map(data, 'data'),
        board: _boards[boardId],
        etag: response.headers['etag'],
      ),
    );
  }

  Future<TaskApiResult<TaskViewsSnapshot>> views(String boardId) async {
    final response = await _api.get('/tasks/boards/$boardId/views');
    return _decode(response, (data) {
      if (data is! List) throw const FormatException('data must be a list.');
      final meta = _map(response.body['meta'], 'meta');
      final capabilities = _map(meta['capabilities'], 'meta.capabilities');
      return TaskViewsSnapshot(
        views: data
            .map((entry) => TaskViewDefinition.fromJson(_map(entry, 'data[]')))
            .toList(growable: false),
        canCreatePersonal: _requiredBoolean(capabilities, 'create_personal'),
        canCreateShared: _requiredBoolean(capabilities, 'create_shared'),
      );
    });
  }

  Future<TaskApiResult<TaskViewMutationResult>> createView(
    String boardId, {
    required String name,
    required TaskViewVisibility visibility,
    required TaskViewConfiguration configuration,
    bool isLocked = false,
    int? expectedStructureVersion,
  }) async {
    final response = await _api.post('/tasks/boards/$boardId/views', {
      'name': name,
      'visibility': visibility == TaskViewVisibility.shared
          ? 'shared'
          : 'personal',
      'is_locked': isLocked,
      'configuration': configuration.toJson(),
      'expected_structure_version': ?expectedStructureVersion,
    });
    return _decode(
      response,
      (data) => TaskViewMutationResult(
        definition: TaskViewDefinition.fromJson(_map(data, 'data')),
        structureVersion: _optionalMetaInteger(response, 'structure_version'),
      ),
    );
  }

  Future<TaskApiResult<List<TaskRecordLinkType>>> recordLinkTypes(
    String taskId,
  ) async {
    final response = await _api.get('/tasks/$taskId/record-links/types');
    return _decode(response, (data) {
      if (data is! List) throw const FormatException('data must be a list.');
      return data
          .map((entry) => TaskRecordLinkType.fromJson(_map(entry, 'data[]')))
          .toList(growable: false);
    });
  }

  /// Search is intentionally type-specific. The server applies target policy,
  /// current-view scope, and board-place intersection before returning names.
  Future<TaskApiResult<List<TaskRecordLinkCandidate>>> searchRecordLinks(
    String taskId, {
    required String type,
    required String query,
  }) async {
    final response = await _api.get('/tasks/$taskId/record-links/search', {
      'type': type,
      'q': query,
    });
    return _decode(response, (data) {
      if (data is! List) throw const FormatException('data must be a list.');
      return data
          .map(
            (entry) => TaskRecordLinkCandidate.fromJson(_map(entry, 'data[]')),
          )
          .toList(growable: false);
    });
  }

  Future<TaskApiResult<TaskRecordLink>> linkRecord(
    String taskId, {
    required int expectedTaskVersion,
    required String recordType,
    required String recordId,
  }) async {
    final response = await _api.post('/tasks/$taskId/record-links', {
      'expected_task_version': expectedTaskVersion,
      'record_type': recordType,
      'record_id': recordId,
    });
    return _decode(
      response,
      (data) => TaskRecordLink.fromJson(_map(data, 'data')),
    );
  }

  Future<TaskApiResult<bool>> unlinkRecord(
    String taskId,
    String linkId, {
    required int expectedTaskVersion,
  }) async {
    final response = await _api.deleteWithBody(
      '/tasks/$taskId/record-links/$linkId',
      {'expected_task_version': expectedTaskVersion},
    );
    return _decode(response, (data) {
      final value = _map(data, 'data')['removed'];
      if (value is! bool) {
        throw const FormatException('data.removed must be a bool.');
      }
      return value;
    });
  }

  Future<TaskApiResult<TaskRow>> updateTask(
    String taskId, {
    required int expectedTaskVersion,
    required Map<String, Object?> changes,
  }) async {
    final response = await _api.patch(
      '/tasks/$taskId',
      _ownedMutation(
        changes,
        ownedKey: 'expected_version',
        version: expectedTaskVersion,
      ),
    );
    return _decode(response, (data) => TaskRow.fromJson(_map(data, 'data')));
  }

  /// Candidate eligibility is intentionally server-scoped. The client never
  /// rebuilds it from reach or permission data it happens to have cached.
  Future<TaskApiResult<List<TaskParticipant>>> participants(
    String boardId, {
    required TaskParticipantPurpose purpose,
    String? search,
  }) async {
    final response = await _api.get(
      '/tasks/boards/$boardId/participants',
      <String, String>{
        'purpose': _participantPurpose(purpose),
        if (search != null && search.trim().isNotEmpty) 'search': search.trim(),
      },
    );
    return _decode(response, (data) {
      if (data is! List) throw const FormatException('data must be a list.');
      return data
          .map((entry) => TaskParticipant.fromJson(_map(entry, 'data[]')))
          .toList(growable: false);
    });
  }

  Future<TaskApiResult<TaskColumnValueMutation>> updateColumnValue(
    String taskId,
    String columnId, {
    required int expectedTaskVersion,
    required Object? value,
  }) async {
    final response = await _api.put(
      '/tasks/$taskId/columns/$columnId',
      <String, Object?>{
        'expected_version': expectedTaskVersion,
        'value': value,
      },
    );
    return _decode(
      response,
      (data) => TaskColumnValueMutation.fromJson(_map(data, 'data')),
    );
  }

  Future<TaskApiResult<List<TaskRow>>> bulkUpdate(
    String boardId, {
    required List<TaskBulkTarget> targets,
    required Map<String, Object?> changes,
  }) async {
    final response = await _api
        .post('/tasks/boards/$boardId/bulk', <String, Object?>{
          'tasks': targets
              .map((target) => target.toJson())
              .toList(growable: false),
          'changes': changes,
        });
    return _decode(response, (data) {
      if (data is! List) throw const FormatException('data must be a list.');
      return data
          .map(
            (entry) => TaskRow.fromJson(
              _map(entry, 'data[]'),
              board: _boards[boardId],
            ),
          )
          .toList(growable: false);
    });
  }

  Future<TaskApiResult<TaskUpdate>> updateTaskUpdate(
    String taskId,
    String updateId, {
    required int expectedUpdateVersion,
    required String body,
    required List<String> mentionUserIds,
  }) async {
    final response = await _api
        .patch('/tasks/$taskId/updates/$updateId', <String, Object?>{
          'expected_update_version': expectedUpdateVersion,
          'body': body,
          'mention_user_ids': List<String>.unmodifiable(mentionUserIds),
        });
    return _decode(response, (data) => TaskUpdate.fromJson(_map(data, 'data')));
  }

  Future<TaskApiResult<TaskUpdate>> createTaskUpdate(
    String taskId, {
    required String body,
    required List<String> mentionUserIds,
    required List<String> attachmentCandidateIds,
    required String idempotencyKey,
  }) async {
    final response = await _api
        .post('/tasks/$taskId/updates', <String, Object?>{
          if (body.trim().isNotEmpty) 'body': body,
          'mention_user_ids': List<String>.unmodifiable(mentionUserIds),
          'attachment_candidate_ids': List<String>.unmodifiable(
            attachmentCandidateIds,
          ),
          'idempotency_key': idempotencyKey,
        });
    return _decode(response, (data) => TaskUpdate.fromJson(_map(data, 'data')));
  }

  Future<TaskApiResult<TaskUpdate>> archiveTaskUpdate(
    String taskId,
    String updateId, {
    required int expectedUpdateVersion,
  }) async {
    final response = await _api.post(
      '/tasks/$taskId/updates/$updateId/archive',
      <String, Object?>{'expected_update_version': expectedUpdateVersion},
    );
    return _decode(response, (data) => TaskUpdate.fromJson(_map(data, 'data')));
  }

  Future<TaskApiResult<TaskAttachmentCandidate>> uploadUpdateAttachment(
    String taskId, {
    required String filename,
    required List<int> bytes,
  }) async {
    final response = await _api.postFile(
      '/tasks/$taskId/update-attachments',
      'file',
      filename,
      bytes,
    );
    return _decode(
      response,
      (data) => TaskAttachmentCandidate.fromJson(_map(data, 'data')),
    );
  }

  Future<TaskApiResult<List<int>>> downloadAttachment(
    String attachmentId,
  ) async {
    final response = await _api.getBytesResponse('/attachments/$attachmentId');
    if (response.statusCode == 403 || response.statusCode == 404) {
      return TaskApiUnavailable<List<int>>(
        message: response.message,
        correlationId: response.correlationId,
      );
    }
    if (!response.ok || response.bytes == null) {
      return TaskApiFailure<List<int>>(
        statusCode: response.statusCode,
        message: response.message,
        correlationId: response.correlationId,
      );
    }
    return TaskApiSuccess<List<int>>(
      response.bytes!,
      correlationId: response.correlationId,
    );
  }

  Future<TaskApiResult<TaskRow>> updateCollaborators(
    String taskId, {
    required int expectedTaskVersion,
    required List<String> userIds,
  }) async {
    final response = await _api
        .put('/tasks/$taskId/collaborators', <String, Object?>{
          'expected_version': expectedTaskVersion,
          'user_ids': List<String>.unmodifiable(userIds),
        });
    return _decode(response, (data) => TaskRow.fromJson(_map(data, 'data')));
  }

  Future<TaskApiResult<TaskWatchMutation>> setWatchPreference(
    String taskId,
    TaskWatchChoice preference,
  ) async {
    final response = await _api.put('/tasks/$taskId/watch', <String, Object?>{
      'preference': switch (preference) {
        TaskWatchChoice.automatic => null,
        TaskWatchChoice.watched => 'watched',
        TaskWatchChoice.muted => 'muted',
      },
    });
    return _decode(
      response,
      (data) => TaskWatchMutation.fromJson(_map(data, 'data')),
    );
  }

  Future<TaskApiResult<TaskViewMutationResult>> updateView(
    String boardId,
    String viewId, {
    required int expectedViewVersion,
    int? expectedStructureVersion,
    required Map<String, Object?> changes,
  }) async {
    final mutation = _ownedMutation(
      changes,
      ownedKey: 'expected_view_version',
      version: expectedViewVersion,
    );
    if (expectedStructureVersion != null) {
      mutation['expected_structure_version'] = expectedStructureVersion;
    }
    final response = await _api.patch(
      '/tasks/boards/$boardId/views/$viewId',
      mutation,
    );
    if (response.statusCode == 409) {
      try {
        final meta = _map(response.body['meta'], 'meta');
        final version = meta['current_view_version'];
        if (version is! int) {
          throw const FormatException(
            'meta.current_view_version must be an int.',
          );
        }
        return TaskApiViewConflict<TaskViewMutationResult>(
          currentView: TaskViewDefinition.fromJson(
            _map(response.body['data'], 'data'),
          ),
          currentVersion: version,
          message: response.message,
          correlationId: response.correlationId,
        );
      } on FormatException catch (error) {
        return TaskApiFailure<TaskViewMutationResult>(
          statusCode: response.statusCode,
          message: error.message.toString(),
          correlationId: response.correlationId,
        );
      }
    }
    return _decode(
      response,
      (data) => TaskViewMutationResult(
        definition: TaskViewDefinition.fromJson(_map(data, 'data')),
        structureVersion: _optionalMetaInteger(response, 'structure_version'),
      ),
    );
  }

  Future<TaskApiResult<TaskViewDefinition>> duplicateView(
    String boardId,
    String viewId, {
    required String name,
    required int expectedViewVersion,
  }) async {
    final response = await _api.post(
      '/tasks/boards/$boardId/views/$viewId/duplicate',
      {'name': name, 'expected_view_version': expectedViewVersion},
    );
    return _decode(
      response,
      (data) => TaskViewDefinition.fromJson(_map(data, 'data')),
    );
  }

  Future<TaskApiResult<TaskViewPreference>> saveViewPreference(
    String boardId,
    String viewId,
    TaskViewPreferenceMutation mutation,
  ) async {
    final response = await _api.put(
      '/tasks/boards/$boardId/views/$viewId/preference',
      mutation.toJson(),
    );
    return _decode(
      response,
      (data) => TaskViewPreference.fromJson(_map(data, 'data')),
    );
  }

  Future<TaskApiResult<bool>> deleteView(
    String boardId,
    String viewId, {
    int? expectedViewVersion,
    int? expectedStructureVersion,
  }) async {
    final response = await _api
        .deleteWithBody('/tasks/boards/$boardId/views/$viewId', {
          'expected_view_version': ?expectedViewVersion,
          'expected_structure_version': ?expectedStructureVersion,
        });
    if (response.statusCode == 204) {
      return TaskApiSuccess<bool>(true, correlationId: response.correlationId);
    }
    return _decode(response, (_) => false);
  }

  Future<TaskApiResult<TaskMyTasksPage>> myTasks({
    int page = 1,
    int perPage = 50,
    String? etag,
  }) async {
    final response = await _api.get('/tasks/my', {
      'page': '$page',
      'per_page': '$perPage',
    }, _etagHeader(etag));
    if (response.statusCode == 304) {
      return TaskApiNotModified<TaskMyTasksPage>(
        etag: response.headers['etag'] ?? etag,
        correlationId: response.correlationId,
      );
    }
    return _decode(
      response,
      (_) => TaskMyTasksPage.fromJson(
        Map<String, Object?>.from(response.body),
        etag: response.headers['etag'],
      ),
    );
  }

  Future<TaskApiResult<List<TaskQuickSearchResult>>> quickSearch(
    String query,
  ) async {
    final response = await _api.get('/tasks/search', {'q': query.trim()});
    return _decode(response, (data) {
      if (data is! List) throw const FormatException('data must be a list.');
      return data
          .map((entry) => TaskQuickSearchResult.fromJson(_map(entry, 'data[]')))
          .toList(growable: false);
    });
  }

  Future<TaskApiResult<TaskBoard>> updateBoard(
    String boardId, {
    required int expectedStructureVersion,
    required Map<String, Object?> changes,
  }) async {
    final cacheEpoch = _cacheEpoch;
    final response = await _api.patch(
      '/tasks/boards/$boardId',
      _ownedMutation(
        changes,
        ownedKey: 'expected_structure_version',
        version: expectedStructureVersion,
      ),
    );
    return _decode(response, (data) {
      final board = TaskBoard.fromJson(_map(data, 'data'));
      if (cacheEpoch == _cacheEpoch) _boards[board.id] = board;
      return board;
    });
  }

  Future<TaskApiResult<TaskRow>> moveTask(TaskMoveMutation mutation) async {
    final response = await _api.post(
      '/tasks/boards/${mutation.boardId}/order',
      mutation.toJson(),
    );
    return _decode(response, (data) => TaskRow.fromJson(_map(data, 'data')));
  }

  TaskApiResult<T> _decode<T>(
    ApiResponse response,
    T Function(Object? data) parse,
  ) {
    // Access loss is intentionally handled before looking at data. Even a
    // malformed or over-generous refusal body cannot repopulate cached task
    // values through this result.
    if (response.statusCode == 403 || response.statusCode == 404) {
      return TaskApiUnavailable<T>(
        message: response.message,
        correlationId: response.correlationId,
      );
    }
    if (response.statusCode == 409) {
      try {
        return TaskApiConflictResult<T>(
          TaskApiConflict.fromResponse(
            statusCode: response.statusCode,
            body: Map<String, Object?>.from(response.body),
          ),
          correlationId: response.correlationId,
        );
      } on FormatException catch (error) {
        return TaskApiFailure<T>(
          statusCode: response.statusCode,
          message: error.message.toString(),
          correlationId: response.correlationId,
        );
      }
    }
    if (!response.ok) {
      return TaskApiFailure<T>(
        statusCode: response.statusCode,
        message: response.reason,
        correlationId: response.correlationId,
      );
    }
    try {
      return TaskApiSuccess<T>(
        parse(response.data),
        correlationId: response.correlationId,
      );
    } on FormatException catch (error) {
      return TaskApiFailure<T>(
        statusCode: response.statusCode,
        message:
            'The server returned an invalid Tasks response: ${error.message}',
        correlationId: response.correlationId,
      );
    }
  }
}

String _participantPurpose(TaskParticipantPurpose purpose) => switch (purpose) {
  TaskParticipantPurpose.taskCreate => 'task_create',
  TaskParticipantPurpose.owner => 'owner',
  TaskParticipantPurpose.people => 'people',
  TaskParticipantPurpose.collaborator => 'collaborator',
  TaskParticipantPurpose.mention => 'mention',
};

Map<String, String>? _etagHeader(String? etag) =>
    etag == null ? null : <String, String>{'If-None-Match': etag};

int? _optionalMetaInteger(ApiResponse response, String key) {
  final meta = response.body['meta'];
  if (meta is! Map) return null;
  final value = meta[key];
  return value is int ? value : null;
}

bool _requiredBoolean(Map<String, Object?> value, String key) {
  final result = value[key];
  if (result is bool) return result;
  throw FormatException('$key must be a bool.');
}

Map<String, Object?> _ownedMutation(
  Map<String, Object?> changes, {
  required String ownedKey,
  required int version,
}) {
  const concurrencyKeys = <String>{
    'expected_version',
    'expected_task_version',
    'expected_update_version',
    'expected_source_order_version',
    'expected_destination_order_version',
    'expected_view_version',
    'expected_structure_version',
  };
  if (changes.keys.any(concurrencyKeys.contains)) {
    throw ArgumentError(
      'Concurrency versions belong to the typed mutation arguments, not changes.',
    );
  }
  return <String, Object?>{ownedKey: version, ...changes};
}

Map<String, Object?> _map(Object? value, String path) {
  if (value is! Map) throw FormatException('$path must be an object.');
  try {
    return Map<String, Object?>.from(value);
  } on TypeError {
    throw FormatException('$path must use string keys.');
  }
}
