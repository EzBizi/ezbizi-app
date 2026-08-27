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

  Future<TaskApiResult<List<TaskBoard>>> boardDirectory() async {
    final response = await _api.get('/tasks/boards');
    return _decode(response, (data) {
      if (data is! List) throw const FormatException('data must be a list.');
      final boards = data
          .map((entry) => TaskBoard.fromJson(_map(entry, 'data[]')))
          .toList(growable: false);
      for (final board in boards) {
        _boards[board.id] = board;
      }
      return boards;
    });
  }

  Future<TaskApiResult<TaskBoard>> board(String boardId) async {
    final response = await _api.get('/tasks/boards/$boardId');
    return _decode(response, (data) {
      final board = TaskBoard.fromJson(_map(data, 'data'));
      _boards[board.id] = board;
      return board;
    });
  }

  Future<TaskApiResult<TaskRowsPage>> boardRows(
    String boardId, {
    String? viewId,
    TaskViewConfiguration? configuration,
    int page = 1,
    int perPage = 50,
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
          if (viewId != null) 'view_id': viewId,
          if (configuration != null)
            'configuration': jsonEncode(configuration.toJson()),
          'page': '$page',
          'per_page': '$perPage',
        });
    return _decode(
      response,
      (_) => TaskRowsPage.fromJson(
        Map<String, Object?>.from(response.body),
        board: definition,
      ),
    );
  }

  Future<TaskApiResult<TaskDetailSnapshot>> taskDetail(
    String boardId,
    String taskId,
  ) async {
    final response = await _api.get('/tasks/boards/$boardId/tasks/$taskId');
    return _decode(
      response,
      (data) => TaskDetailSnapshot.fromJson(
        _map(data, 'data'),
        board: _boards[boardId],
        etag: response.headers['etag'],
      ),
    );
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

  Future<TaskApiResult<TaskViewDefinition>> updateView(
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
    return _decode(
      response,
      (data) => TaskViewDefinition.fromJson(_map(data, 'data')),
    );
  }

  Future<TaskApiResult<TaskBoard>> updateBoard(
    String boardId, {
    required int expectedStructureVersion,
    required Map<String, Object?> changes,
  }) async {
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
      _boards[board.id] = board;
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
