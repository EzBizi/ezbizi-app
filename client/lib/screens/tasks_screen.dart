import 'package:flutter/material.dart';

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

  @override
  void initState() {
    super.initState();
    _loadDirectory();
  }

  @override
  void didUpdateWidget(covariant TasksScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.pendingIntent != oldWidget.pendingIntent &&
        widget.pendingIntent != null) {
      _consumeIntent(widget.pendingIntent!);
    }
  }

  Future<void> _loadDirectory() async {
    setState(() {
      _loading = _boards.isEmpty;
      _error = null;
    });
    final result = await _tasks.boardDirectory();
    if (!mounted) return;
    if (result is TaskApiSuccess<List<TaskBoard>>) {
      setState(() {
        _loading = false;
        _boards = result.data;
      });
      if (widget.pendingIntent case final intent?) await _consumeIntent(intent);
    } else if (result is TaskApiUnavailable<List<TaskBoard>>) {
      _evict(result.message);
    } else {
      setState(() {
        _loading = false;
        _error = result.message;
      });
    }
  }

  Future<void> _consumeIntent(TaskAddressIntent intent) async {
    final result = await _tasks.board(intent.boardId);
    if (!mounted) return;
    if (result is! TaskApiSuccess<TaskBoard>) {
      _evict(result.message);
      widget.onIntentConsumed?.call();
      return;
    }
    await _loadBoard(
      result.data,
      selectedTaskId: intent.taskId,
      consumeIntent: true,
    );
  }

  Future<void> _loadBoard(
    TaskBoard board, {
    String? selectedTaskId,
    bool consumeIntent = false,
  }) async {
    setState(() {
      _loading = true;
      _error = null;
      _board = board;
      _rows = const [];
      _detail = null;
      _selectedTaskId = null;
    });
    final result = await _tasks.boardRows(board.id);
    if (!mounted) return;
    if (result is TaskApiUnavailable<TaskRowsPage>) {
      _evict(result.message);
      if (consumeIntent) widget.onIntentConsumed?.call();
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
    });
    if (selectedTaskId != null) {
      await _selectTaskId(selectedTaskId, consumeIntent: consumeIntent);
    }
  }

  Future<void> _selectTask(TaskRow task) => _selectTaskId(task.id);
  Future<void> _selectTaskId(
    String taskId, {
    bool consumeIntent = false,
  }) async {
    final board = _board;
    if (board == null) return;
    setState(() {
      _selectedTaskId = taskId;
      _detail = null;
    });
    final result = await _tasks.taskDetail(board.id, taskId);
    if (!mounted) return;
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
      setState(
        () => _error =
            'This task changed while you were editing it. Refresh the board and try again.',
      );
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
      _error = message ?? 'This task board is unavailable.';
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
          _titleBar(context),
          Expanded(
            child: AsyncBody(
              loading: _loading,
              error: _error,
              onRetry: _loadDirectory,
              child: _creating
                  ? _NewBoardPane(
                      onCancel: () => setState(() => _creating = false),
                      onCreate: _createBoard,
                    )
                  : _board == null
                  ? _directoryOnly()
                  : mobile
                  ? TaskMobileList(
                      board: _board!,
                      rows: _rows,
                      onSelected: _selectTask,
                    )
                  : _workspace(wide),
            ),
          ),
        ],
      ),
    );
  }

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
          labels: const [
            ('Boards', Icons.view_list_outlined),
            ('My tasks', Icons.person_outline),
          ],
          selected: 0,
          onSelected: (_) {},
        ),
      ],
    ),
  );

  Widget _directoryOnly() => _boards.isEmpty
      ? _EmptyDirectory(onCreate: () => setState(() => _creating = true))
      : BoardDirectory(
          boards: _boards,
          selectedBoardId: null,
          onSelected: (board) => _loadBoard(board),
          onCreate: () => setState(() => _creating = true),
        );
  Widget _workspace(bool wide) => Row(
    children: [
      if (wide) ...[
        SizedBox(
          width: 210,
          child: BoardDirectory(
            boards: _boards,
            selectedBoardId: _board!.id,
            onSelected: (board) => _loadBoard(board),
            onCreate: () => setState(() => _creating = true),
          ),
        ),
        const VerticalDivider(width: 1),
      ],
      Expanded(
        child: TaskTable(
          board: _board!,
          rows: _rows,
          selectedTaskId: _selectedTaskId,
          onSelected: _selectTask,
          onTitleChanged: _rename,
        ),
      ),
      if (_detailOpen) ...[
        GestureDetector(
          onHorizontalDragUpdate: (event) => setState(
            () =>
                _detailWidth = (_detailWidth - event.delta.dx).clamp(300, 640),
          ),
          child: const VerticalDivider(width: 8, thickness: 2),
        ),
        SizedBox(
          width: _detailWidth,
          child: TaskDetailPanel(
            task: _detail,
            onClose: () => setState(() {
              _detailOpen = false;
              _selectedTaskId = null;
              _detail = null;
            }),
          ),
        ),
      ],
    ],
  );
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
