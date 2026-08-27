import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/address.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/tab_bar_like.dart';
import 'tasks/board_directory.dart';
import 'tasks/task_api.dart';
import 'tasks/task_detail_panel.dart';
import 'tasks/task_mobile_list.dart';
import 'tasks/task_models.dart';
import 'tasks/task_table.dart';

/// Dense Grid Tasks shell. Address IDs are held as opaque intents until their
/// own authorized board/task reads complete.
class TasksScreen extends StatefulWidget {
  const TasksScreen({
    super.key,
    required this.api,
    required this.session,
    this.pendingIntent,
    this.onIntentConsumed,
  });
  final ApiClient api;
  final Session session;
  final TaskAddressIntent? pendingIntent;
  final VoidCallback? onIntentConsumed;
  @override
  State<TasksScreen> createState() => _TasksScreenState();
}

class _TasksScreenState extends State<TasksScreen> {
  late final TaskApi _tasks = TaskApi(widget.api);
  bool _loading = true;
  String? _error;
  List<TaskBoard> _boards = const [];
  TaskBoard? _board;
  List<TaskRow> _rows = const [];
  TaskDetailSnapshot? _detail;
  String? _selectedTaskId;
  double _detailWidth = 390;
  bool _detailOpen = true;
  bool _creating = false;
  bool _showDirectory = false;
  bool _unavailable = false;
  bool _conflict = false;
  String? _announcement;
  List<TaskParticipant> _owners = const [];
  List<TaskParticipant> _people = const [];
  int _intentGeneration = 0;
  int _requestGeneration = 0;
  int _page = 1;
  int _lastPage = 1;

  @override
  void initState() {
    super.initState();
    _loadDirectory();
  }

  @override
  void didUpdateWidget(covariant TasksScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.pendingIntent != oldWidget.pendingIntent) {
      _intentGeneration++;
      _requestGeneration++;
    }
    if (widget.pendingIntent != oldWidget.pendingIntent &&
        widget.pendingIntent != null) {
      _consumeIntent(widget.pendingIntent!, _intentGeneration);
    }
  }

  Future<void> _loadDirectory() async {
    setState(() {
      _loading = _boards.isEmpty;
      _error = null;
      _unavailable = false;
      _conflict = false;
    });
    final result = await _tasks.boardDirectory();
    if (!mounted) return;
    if (result is TaskApiSuccess<List<TaskBoard>>) {
      setState(() {
        _loading = false;
        _boards = result.data;
      });
      if (widget.pendingIntent case final intent?)
        await _consumeIntent(intent, _intentGeneration);
    } else if (result is TaskApiUnavailable<List<TaskBoard>>) {
      _evict(result.message);
    } else {
      setState(() {
        _loading = false;
        _error = result.message;
      });
    }
  }

  Future<void> _consumeIntent(TaskAddressIntent intent, int generation) async {
    final result = await _tasks.board(intent.boardId);
    if (!_isCurrentIntent(intent, generation)) return;
    if (result is TaskApiUnavailable<TaskBoard>) {
      _evict(result.message);
      widget.onIntentConsumed?.call();
      return;
    }
    if (result is! TaskApiSuccess<TaskBoard>) {
      setState(() {
        _loading = false;
        _error = result.message;
      });
      return;
    }
    await _loadBoard(
      result.data,
      selectedTaskId: intent.taskId,
      consumeIntent: true,
      intent: intent,
      generation: generation,
    );
  }

  Future<void> _loadBoard(
    TaskBoard board, {
    String? selectedTaskId,
    bool consumeIntent = false,
    TaskAddressIntent? intent,
    int? generation,
  }) async {
    final request = ++_requestGeneration;
    setState(() {
      _loading = true;
      _error = null;
      _board = board;
      _rows = const [];
      _detail = null;
      _selectedTaskId = null;
      _page = 1;
    });
    final result = await _tasks.boardRows(board.id, page: _page);
    if (!mounted || request != _requestGeneration) return;
    if (result is TaskApiUnavailable<TaskRowsPage>) {
      _evict(result.message);
      if (consumeIntent && _isCurrentIntent(intent!, generation!))
        widget.onIntentConsumed?.call();
      return;
    }
    if (result is! TaskApiSuccess<TaskRowsPage>) {
      setState(() {
        _loading = false;
        _error = result.message;
      });
      return;
    }
    setState(() {
      _loading = false;
      _rows = result.data.rows;
      _page = result.data.page;
      _lastPage = result.data.lastPage;
    });
    _loadParticipants(board.id);
    if (selectedTaskId != null) {
      await _selectTaskId(
        selectedTaskId,
        consumeIntent: consumeIntent,
        intent: intent,
        generation: generation,
      );
    }
  }

  Future<void> _loadParticipants(String boardId) async {
    final owners = await _tasks.participants(
      boardId,
      purpose: TaskParticipantPurpose.owner,
    );
    final people = await _tasks.participants(
      boardId,
      purpose: TaskParticipantPurpose.people,
    );
    if (!mounted || _board?.id != boardId) return;
    if (owners is TaskApiSuccess<List<TaskParticipant>>) {
      setState(() => _owners = owners.data);
    }
    if (people is TaskApiSuccess<List<TaskParticipant>>) {
      setState(() => _people = people.data);
    }
  }

  Future<void> _selectTask(TaskRow task) => _selectTaskId(task.id);
  Future<void> _selectTaskId(
    String taskId, {
    bool consumeIntent = false,
    TaskAddressIntent? intent,
    int? generation,
  }) async {
    final request = ++_requestGeneration;
    final board = _board;
    if (board == null) return;
    setState(() {
      _selectedTaskId = taskId;
      _detail = null;
    });
    final result = await _tasks.taskDetail(board.id, taskId);
    if (consumeIntent
        ? (!_isCurrentIntent(intent!, generation!) ||
              request != _requestGeneration)
        : (!mounted || request != _requestGeneration))
      return;
    if (result is TaskApiUnavailable<TaskDetailSnapshot>) {
      _evict(result.message);
      if (consumeIntent) widget.onIntentConsumed?.call();
      return;
    }
    if (result is! TaskApiSuccess<TaskDetailSnapshot>) {
      setState(() {
        _error = result.message;
        _selectedTaskId = null;
      });
      return;
    }
    setState(() {
      _detail = result.data;
      _detailOpen = true;
    });
    if (consumeIntent) widget.onIntentConsumed?.call();
    if (MediaQuery.sizeOf(context).width < 700) {
      await Navigator.of(context).push<void>(
        MaterialPageRoute<void>(
          builder: (_) => Scaffold(
            body: TaskDetailPanel(
              task: result.data,
              fullScreen: true,
              onClose: () => Navigator.of(context).pop(),
            ),
          ),
        ),
      );
    }
  }

  bool _isCurrentIntent(TaskAddressIntent intent, int generation) =>
      mounted &&
      widget.pendingIntent == intent &&
      _intentGeneration == generation;

  Future<void> _goToPage(int page) async {
    final board = _board;
    if (board == null || page < 1 || page > _lastPage) return;
    final result = await _tasks.boardRows(board.id, page: page);
    if (!mounted) return;
    if (result is TaskApiUnavailable<TaskRowsPage>) {
      _evict(result.message);
      return;
    }
    if (result is TaskApiSuccess<TaskRowsPage>)
      setState(() {
        _rows = result.data.rows;
        _page = result.data.page;
        _lastPage = result.data.lastPage;
      });
  }

  Future<void> _rename(TaskRow task, String title) async {
    final trimmed = title.trim();
    if (trimmed.isEmpty || trimmed == task.title) return;
    final result = await _tasks.updateTask(
      task.id,
      expectedTaskVersion: task.version,
      changes: <String, Object?>{'title': trimmed},
    );
    if (!mounted) return;
    if (result is TaskApiUnavailable<TaskRow>) {
      _evict(result.message);
      return;
    }
    if (result is TaskApiConflictResult<TaskRow>) {
      setState(() => _conflict = true);
      return;
    }
    if (result is TaskApiSuccess<TaskRow>) {
      setState(
        () => _rows = _rows
            .map((row) => row.id == result.data.id ? result.data : row)
            .toList(growable: false),
      );
    }
  }

  void _announce(String message) {
    if (!mounted) return;
    setState(() => _announcement = message);
  }

  TaskRow _replaceRow(
    TaskRow source, {
    String? title,
    String? ownerUserId,
    String? statusOptionId,
    String? priorityOptionId,
    DateTime? dueOn,
    bool clearOwner = false,
    bool clearStatus = false,
    bool clearPriority = false,
    bool clearDue = false,
    Map<String, TaskCellValue>? values,
    int? version,
    int? detailVersion,
  }) => TaskRow(
    id: source.id,
    boardId: source.boardId,
    groupId: source.groupId,
    parentTaskId: source.parentTaskId,
    number: source.number,
    title: title ?? source.title,
    ownerUserId: clearOwner ? null : ownerUserId ?? source.ownerUserId,
    collaboratorUserIds: source.collaboratorUserIds,
    statusOptionId: clearStatus
        ? null
        : statusOptionId ?? source.statusOptionId,
    priorityOptionId: clearPriority
        ? null
        : priorityOptionId ?? source.priorityOptionId,
    startOn: source.startOn,
    dueOn: clearDue ? null : dueOn ?? source.dueOn,
    position: source.position,
    version: version ?? source.version,
    detailVersion: detailVersion ?? source.detailVersion,
    values: values ?? source.values,
    groupByValue: source.groupByValue,
    subtasks: source.subtasks,
  );

  void _putRow(TaskRow row) => setState(
    () => _rows = _rows
        .map((current) => current.id == row.id ? row : current)
        .toList(growable: false),
  );

  Future<void> _saveCore(TaskRow task, String field, Object? value) async {
    final optimistic = switch (field) {
      'title' => _replaceRow(task, title: value as String),
      'owner_user_id' => _replaceRow(
        task,
        ownerUserId: value as String?,
        clearOwner: value == null,
      ),
      'status_option_id' => _replaceRow(
        task,
        statusOptionId: value as String?,
        clearStatus: value == null,
      ),
      'priority_option_id' => _replaceRow(
        task,
        priorityOptionId: value as String?,
        clearPriority: value == null,
      ),
      'due_on' => _replaceRow(
        task,
        dueOn: value == null ? null : DateTime.tryParse(value as String),
        clearDue: value == null,
      ),
      _ => task,
    };
    _putRow(optimistic);
    _announce('Saving ${field.replaceAll('_', ' ')}.');
    final result = await _tasks.updateTask(
      task.id,
      expectedTaskVersion: task.version,
      changes: <String, Object?>{field: value},
    );
    if (!mounted) return;
    if (result is TaskApiSuccess<TaskRow>) {
      _putRow(result.data);
      _announce('${field.replaceAll('_', ' ')} saved.');
    } else if (result is TaskApiUnavailable<TaskRow>) {
      _evict(result.message);
    } else {
      _putRow(task);
      if (result is TaskApiConflictResult<TaskRow>) _conflict = true;
      _announce(result.message ?? 'The change could not be saved.');
      setState(() {});
    }
  }

  Future<void> _saveCustom(
    TaskRow task,
    TaskColumnDefinition column,
    Object? value,
  ) async {
    _announce('Saving ${column.name}.');
    final result = await _tasks.updateColumnValue(
      task.id,
      column.id,
      expectedTaskVersion: task.version,
      value: value,
    );
    if (!mounted) return;
    if (result is TaskApiSuccess<TaskColumnValueMutation>) {
      final values = Map<String, TaskCellValue>.from(task.values);
      values[column.id] = TaskCellValue.fromJson(
        result.data.value,
        definition: column,
      );
      _putRow(
        _replaceRow(
          task,
          values: Map.unmodifiable(values),
          version: result.data.version,
          detailVersion: result.data.detailVersion,
        ),
      );
      _announce('${column.name} saved.');
    } else if (result is TaskApiUnavailable<TaskColumnValueMutation>) {
      _evict(result.message);
    } else {
      if (result is TaskApiConflictResult<TaskColumnValueMutation>)
        _conflict = true;
      _announce(result.message ?? 'The change could not be saved.');
      setState(() {});
    }
  }

  Future<void> _bulk(List<TaskRow> tasks, Map<String, Object?> changes) async {
    final board = _board;
    if (board == null) return;
    _announce('Saving changes to ${tasks.length} selected tasks.');
    final result = await _tasks.bulkUpdate(
      board.id,
      targets: tasks
          .map(
            (task) =>
                TaskBulkTarget(id: task.id, expectedVersion: task.version),
          )
          .toList(growable: false),
      changes: changes,
    );
    if (!mounted) return;
    if (result is TaskApiSuccess<List<TaskRow>>) {
      for (final row in result.data) _putRow(row);
      _announce('Changes saved for ${result.data.length} tasks.');
    } else if (result is TaskApiUnavailable<List<TaskRow>>) {
      _evict(result.message);
    } else {
      if (result is TaskApiConflictResult<List<TaskRow>>) _conflict = true;
      _announce(result.message ?? 'No selected tasks were changed.');
      setState(() {});
    }
  }

  Future<void> _createBoard(String name) async {
    final result = await _tasks.createBoard(
      name: name,
      visibility: TaskBoardVisibility.scope,
    );
    if (!mounted) return;
    if (result is TaskApiSuccess<TaskBoard>) {
      setState(() {
        _creating = false;
        _boards = [..._boards, result.data];
      });
      await _loadBoard(result.data);
    } else if (result is TaskApiUnavailable<TaskBoard>) {
      _evict(result.message);
    } else {
      setState(() => _error = result.message);
    }
  }

  void _evict(String? message) {
    _tasks.clearCache();
    if (!mounted) return;
    setState(() {
      _loading = false;
      _boards = const [];
      _board = null;
      _rows = const [];
      _detail = null;
      _selectedTaskId = null;
      _error = null;
      _unavailable = true;
    });
  }

  @override
  Widget build(BuildContext context) {
    final width = MediaQuery.sizeOf(context).width;
    final wide = width >= 900;
    final mobile = width < 700;
    return ColoredBox(
      color: Theme.of(context).colorScheme.surfaceContainerLowest,
      child: Column(
        children: [
          Semantics(
            liveRegion: true,
            label: _announcement ?? '',
            child: const SizedBox.shrink(),
          ),
          _titleBar(context),
          Expanded(
            child: AsyncBody(
              loading: _loading,
              error: _error,
              onRetry: _loadDirectory,
              child: _unavailable
                  ? const Center(child: Text('This task board is unavailable.'))
                  : Column(
                      children: [
                        if (_conflict)
                          MaterialBanner(
                            content: const Text(
                              'This task changed while you were editing it.',
                            ),
                            actions: [
                              TextButton(
                                onPressed: () {
                                  setState(() => _conflict = false);
                                  if (_board != null) _loadBoard(_board!);
                                },
                                child: const Text('Refresh board'),
                              ),
                            ],
                          ),
                        Expanded(child: _content(mobile, wide)),
                      ],
                    ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _content(bool mobile, bool wide) => _creating
      ? _NewBoardPane(
          onCancel: () => setState(() => _creating = false),
          onCreate: _createBoard,
        )
      : _showDirectory || _board == null
      ? _directoryOnly()
      : mobile
      ? Column(
          children: [
            Expanded(
              child: TaskMobileList(
                board: _board!,
                rows: _rows,
                onSelected: _selectTask,
              ),
            ),
            _pagination(),
          ],
        )
      : _workspace(wide);

  Widget _pagination() => _lastPage <= 1
      ? const SizedBox.shrink()
      : Padding(
          padding: const EdgeInsets.all(8),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.end,
            children: [
              Text('Page $_page of $_lastPage'),
              const SizedBox(width: 8),
              OutlinedButton(
                onPressed: _page > 1 ? () => _goToPage(_page - 1) : null,
                child: const Text('Previous'),
              ),
              const SizedBox(width: 8),
              OutlinedButton(
                onPressed: _page < _lastPage
                    ? () => _goToPage(_page + 1)
                    : null,
                child: const Text('Next'),
              ),
            ],
          ),
        );

  Widget _titleBar(BuildContext context) => Material(
    color: Theme.of(context).colorScheme.surface,
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 14, 16, 4),
          child: Row(
            children: [
              const Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Tasks',
                      style: TextStyle(
                        fontSize: 25,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    SizedBox(height: 3),
                    Text('Plan, assign, and finish work in this view.'),
                  ],
                ),
              ),
              if (_board != null)
                IconButton(
                  tooltip: _detailOpen ? 'Hide details' : 'Show details',
                  icon: Icon(
                    _detailOpen ? Icons.close_fullscreen : Icons.open_in_full,
                  ),
                  onPressed: () => setState(() => _detailOpen = !_detailOpen),
                ),
            ],
          ),
        ),
        TabBarLike(
          labels: const [('Boards', Icons.view_list_outlined)],
          selected: 0,
          onSelected: (_) => setState(() => _showDirectory = true),
        ),
      ],
    ),
  );

  Widget _directoryOnly() => _boards.isEmpty
      ? _EmptyDirectory(onCreate: () => setState(() => _creating = true))
      : BoardDirectory(
          boards: _boards,
          selectedBoardId: null,
          onSelected: (board) {
            setState(() => _showDirectory = false);
            _loadBoard(board);
          },
          onCreate: () => setState(() => _creating = true),
        );
  Widget _workspace(bool wide) => LayoutBuilder(
    builder: (context, constraints) {
      final showDirectory = wide && constraints.maxWidth >= 1100;
      final showDetail = _detailOpen && constraints.maxWidth >= 800;
      if (_detailOpen && !showDetail && _detail != null) {
        return TaskDetailPanel(
          task: _detail,
          fullScreen: true,
          onClose: () => setState(() => _detailOpen = false),
        );
      }
      return Row(
        children: [
          if (showDirectory) ...[
            SizedBox(
              width: 210,
              child: BoardDirectory(
                boards: _boards,
                selectedBoardId: _board!.id,
                onSelected: (board) {
                  setState(() => _showDirectory = false);
                  _loadBoard(board);
                },
                onCreate: () => setState(() => _creating = true),
              ),
            ),
            const VerticalDivider(width: 1),
          ],
          Expanded(
            child: Column(
              children: [
                Expanded(
                  child: TaskTable(
                    board: _board!,
                    rows: _rows,
                    selectedTaskId: _selectedTaskId,
                    onSelected: _selectTask,
                    onTitleChanged: _rename,
                    onCoreChanged: _saveCore,
                    onCustomChanged: _saveCustom,
                    onBulkChanged: _bulk,
                    owners: _owners,
                    people: _people,
                    onSelectionChanged: (count) => _announce(
                      count == 0
                          ? 'Selection cleared.'
                          : '$count tasks selected.',
                    ),
                    onSelectionCleared: () => setState(() {
                      _selectedTaskId = null;
                      _detailOpen = false;
                    }),
                  ),
                ),
                _pagination(),
              ],
            ),
          ),
          if (showDetail)
            TaskDetailDivider(
              onResize: (delta) => setState(
                () => _detailWidth = (_detailWidth + delta).clamp(300, 640),
              ),
            ),
          Offstage(
            offstage: !showDetail,
            child: SizedBox(
              width: _detailWidth,
              child: TaskDetailPanel(
                task: _detail,
                onClose: () => setState(() => _detailOpen = false),
              ),
            ),
          ),
        ],
      );
    },
  );
}

class TaskDetailDivider extends StatelessWidget {
  const TaskDetailDivider({super.key, required this.onResize});
  final ValueChanged<double> onResize;
  @override
  Widget build(BuildContext context) => FocusableActionDetector(
    autofocus: true,
    shortcuts: <ShortcutActivator, Intent>{
      SingleActivator(LogicalKeyboardKey.arrowLeft): _ResizeDetailIntent(-24),
      SingleActivator(LogicalKeyboardKey.arrowRight): _ResizeDetailIntent(24),
    },
    actions: <Type, Action<Intent>>{
      _ResizeDetailIntent: CallbackAction<_ResizeDetailIntent>(
        onInvoke: (intent) {
          onResize(intent.delta);
          return null;
        },
      ),
    },
    child: Semantics(
      label: 'Resize task details',
      slider: true,
      child: GestureDetector(
        onHorizontalDragUpdate: (event) => onResize(-event.delta.dx),
        child: const SizedBox(
          width: 16,
          child: Center(child: VerticalDivider(width: 8, thickness: 2)),
        ),
      ),
    ),
  );
}

class _ResizeDetailIntent extends Intent {
  const _ResizeDetailIntent(this.delta);
  final double delta;
}

class _EmptyDirectory extends StatelessWidget {
  const _EmptyDirectory({required this.onCreate});
  final VoidCallback onCreate;
  @override
  Widget build(BuildContext context) => Center(
    child: ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 460),
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              Icons.view_list_outlined,
              size: 46,
              color: Theme.of(context).colorScheme.primary,
            ),
            const SizedBox(height: 14),
            Text(
              'No task boards yet',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 7),
            const Text(
              'Create a board for the work handled in this view. Groups, owners, and due dates stay together.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 18),
            FilledButton.icon(
              onPressed: onCreate,
              icon: const Icon(Icons.add),
              label: const Text('New board'),
            ),
          ],
        ),
      ),
    ),
  );
}

class _NewBoardPane extends StatefulWidget {
  const _NewBoardPane({required this.onCancel, required this.onCreate});
  final VoidCallback onCancel;
  final ValueChanged<String> onCreate;
  @override
  State<_NewBoardPane> createState() => _NewBoardPaneState();
}

class _NewBoardPaneState extends State<_NewBoardPane> {
  final _controller = TextEditingController();
  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Align(
    alignment: Alignment.topCenter,
    child: ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 540),
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('New board', style: Theme.of(context).textTheme.headlineSmall),
            const SizedBox(height: 8),
            const Text(
              'This board will be filed in the view you are looking at.',
            ),
            const SizedBox(height: 20),
            TextField(
              controller: _controller,
              autofocus: true,
              textInputAction: TextInputAction.done,
              decoration: const InputDecoration(labelText: 'Board name'),
              onSubmitted: widget.onCreate,
            ),
            const SizedBox(height: 16),
            Row(
              children: [
                TextButton(
                  onPressed: widget.onCancel,
                  child: const Text('Cancel'),
                ),
                const Spacer(),
                FilledButton(
                  onPressed: () => widget.onCreate(_controller.text),
                  child: const Text('Create board'),
                ),
              ],
            ),
          ],
        ),
      ),
    ),
  );
}
