import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/address.dart';
import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/side_panel.dart';
import '../widgets/tab_bar_like.dart';
import 'tasks/board_directory.dart';
import 'tasks/my_tasks_view.dart';
import 'tasks/task_api.dart';
import 'tasks/task_board_administration.dart';
import 'tasks/task_board_members.dart';
import 'tasks/task_detail_panel.dart';
import 'tasks/task_duplicate_flow.dart';
import 'tasks/task_filters_panel.dart';
import 'tasks/task_folder_administration.dart';
import 'tasks/task_mobile_list.dart';
import 'tasks/task_models.dart';
import 'tasks/task_quick_search.dart';
import 'tasks/task_table.dart';
import 'tasks/task_transfer_flow.dart';
import 'tasks/task_views_bar.dart';

/// Dense Grid Tasks shell. Address IDs are held as opaque intents until their
/// own authorized board/task reads complete.
class TasksScreen extends StatefulWidget {
  const TasksScreen({
    super.key,
    required this.api,
    required this.session,
    this.pendingIntent,
    this.onIntentConsumed,
    this.onOpenRecordRoute,
    this.pollInterval = const Duration(seconds: 30),
  });
  final ApiClient api;
  final Session session;
  final TaskAddressIntent? pendingIntent;
  final VoidCallback? onIntentConsumed;
  final ValueChanged<String>? onOpenRecordRoute;
  final Duration pollInterval;
  @override
  State<TasksScreen> createState() => _TasksScreenState();
}

class _TasksScreenState extends State<TasksScreen> with WidgetsBindingObserver {
  late final TaskApi _tasks = TaskApi(widget.api);
  bool _loading = true;
  String? _error;
  List<TaskBoard> _boards = const [];
  List<String> _directoryActions = const [];
  List<TaskFolder> _folders = const [];
  TaskBoard? _board;
  List<TaskRow> _rows = const [];
  TaskDetailSnapshot? _detail;
  final ValueNotifier<TaskDetailSnapshot?> _mobileDetail =
      ValueNotifier<TaskDetailSnapshot?>(null);
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
  final Map<String, Future<void>> _mutationQueues = <String, Future<void>>{};
  bool _bulkBusy = false;
  Set<String> _bulkLockedTaskIds = const <String>{};
  int _intentGeneration = 0;
  int _requestGeneration = 0;
  int _page = 1;
  int _lastPage = 1;
  int _total = 0;
  List<TaskStatusSummary> _statusSummaries = const [];
  List<TaskGroupCount> _groupCounts = const [];
  List<TaskViewDefinition> _views = const [];
  bool _canCreatePersonalView = false;
  bool _canCreateSharedView = false;
  String? _activeViewId;
  int _activeViewVersion = 1;
  TaskViewConfiguration _configuration = TaskViewConfiguration.empty;
  String? _boardEtag;
  TaskMyTasksPage? _myTasks;
  final Map<int, TaskMyTasksPage> _myTaskPages = <int, TaskMyTasksPage>{};
  String? _myTasksError;
  bool _myTasksLoading = false;
  bool _myTasksLoadingMore = false;
  bool _showMyTasks = false;
  bool _showFilters = false;
  bool _showViewEditor = false;
  bool _viewDirty = false;
  final Set<String> _dirtyDraftIds = <String>{};
  bool _foreground = true;
  int _queryToken = 0;
  Timer? _poller;
  Timer? _queryDebounce;
  int _privacyGeneration = 0;
  String? _actorUserId;

  @override
  void dispose() {
    final draft = TaskDuplicateDraftStore.pending;
    if (draft != null && draft.actorUserId != widget.session.me?.id) {
      TaskDuplicateDraftStore.pending = null;
    }
    WidgetsBinding.instance.removeObserver(this);
    _poller?.cancel();
    _queryDebounce?.cancel();
    _mobileDetail.dispose();
    super.dispose();
  }

  @override
  void initState() {
    super.initState();
    _actorUserId = widget.session.me?.id;
    final draft = TaskDuplicateDraftStore.pending;
    if (draft != null && draft.actorUserId != _actorUserId) {
      TaskDuplicateDraftStore.pending = null;
    }
    WidgetsBinding.instance.addObserver(this);
    _poller = Timer.periodic(widget.pollInterval, (_) => _pollVisible());
    _loadDirectory();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _foreground = state == AppLifecycleState.resumed;
    if (_foreground) _refreshVisible();
  }

  @override
  void didUpdateWidget(covariant TasksScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    final actorUserId = widget.session.me?.id;
    if (_actorUserId != actorUserId) {
      TaskDuplicateDraftStore.pending = null;
      _actorUserId = actorUserId;
    }
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
    final privacy = _privacyGeneration;
    final result = await _tasks.boardDirectorySnapshot();
    if (!_isCurrentPrivacy(privacy)) return;
    if (result is TaskApiSuccess<TaskBoardDirectory>) {
      final folders = await _tasks.folders();
      if (!_isCurrentPrivacy(privacy)) return;
      if (folders is TaskApiUnavailable<List<TaskFolder>>) {
        _evict(folders.message);
        return;
      }
      setState(() {
        _loading = false;
        _boards = result.data.boards;
        _directoryActions = result.data.actions;
        if (folders is TaskApiSuccess<List<TaskFolder>>) {
          _folders = folders.data;
        }
      });
      if (widget.pendingIntent case final intent?) {
        await _consumeIntent(intent, _intentGeneration);
      }
    } else if (result is TaskApiUnavailable<TaskBoardDirectory>) {
      _evict(result.message);
    } else {
      setState(() {
        _loading = false;
        _error = result.message;
      });
    }
  }

  Future<void> _consumeIntent(TaskAddressIntent intent, int generation) async {
    final privacy = _privacyGeneration;
    final result = await _tasks.board(intent.boardId);
    if (!_isCurrentPrivacy(privacy) || !_isCurrentIntent(intent, generation)) {
      return;
    }
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
    if (_bulkBusy) return;
    final request = ++_requestGeneration;
    setState(() {
      _loading = true;
      _error = null;
      _board = board;
      _rows = const [];
      _detail = null;
      _selectedTaskId = null;
      _page = 1;
      _owners = const [];
      _people = const [];
      _views = const [];
      _canCreatePersonalView = false;
      _canCreateSharedView = false;
      _activeViewId = null;
      _activeViewVersion = 1;
      _configuration = TaskViewConfiguration.empty;
      _boardEtag = null;
      _total = 0;
      _statusSummaries = const [];
      _groupCounts = const [];
      _privacyGeneration++;
      _viewDirty = false;
      _dirtyDraftIds.clear();
      _showMyTasks = false;
      _showFilters = false;
    });
    _mobileDetail.value = null;
    final privacy = _privacyGeneration;
    final result = await _tasks.boardRows(board.id, page: _page);
    if (!_isCurrentPrivacy(privacy) || request != _requestGeneration) return;
    if (result is TaskApiUnavailable<TaskRowsPage>) {
      _evict(result.message);
      if (consumeIntent && _isCurrentIntent(intent!, generation!)) {
        widget.onIntentConsumed?.call();
      }
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
      _total = result.data.total;
      _statusSummaries = result.data.statusSummaries;
      _groupCounts = result.data.groupCounts;
      _activeViewId = result.data.viewId;
      _activeViewVersion = result.data.viewVersion;
      _configuration = result.data.configuration;
      _boardEtag = result.data.etag;
    });
    // A board becomes "last opened" only after its authorized rows completed.
    // Rendering a directory entry is not an open.
    unawaited(_tasks.saveBoardPreference(board.id, markOpened: true));
    _loadViews(board.id);
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

  Future<void> _loadViews(String boardId) async {
    final privacy = _privacyGeneration;
    final result = await _tasks.views(boardId);
    if (!_isCurrentPrivacy(privacy) || _board?.id != boardId) return;
    if (result is TaskApiUnavailable<TaskViewsSnapshot>) {
      _evict(result.message);
      return;
    }
    if (result is TaskApiSuccess<TaskViewsSnapshot>) {
      setState(() {
        _views = result.data.views;
        _canCreatePersonalView = result.data.canCreatePersonal;
        _canCreateSharedView = result.data.canCreateShared;
        final active = result.data.views
            .where((view) => view.id == _activeViewId)
            .firstOrNull;
        if (active != null) _activeViewVersion = active.version;
      });
    }
  }

  Future<void> _loadParticipants(String boardId) async {
    final privacy = _privacyGeneration;
    final owners = await _tasks.participants(
      boardId,
      purpose: TaskParticipantPurpose.owner,
    );
    if (!_isCurrentPrivacy(privacy) || _board?.id != boardId) return;
    if (owners is TaskApiUnavailable<List<TaskParticipant>>) {
      _evict(owners.message);
      return;
    }
    final people = await _tasks.participants(
      boardId,
      purpose: TaskParticipantPurpose.people,
    );
    if (!_isCurrentPrivacy(privacy) || _board?.id != boardId) return;
    if (people is TaskApiUnavailable<List<TaskParticipant>>) {
      _evict(people.message);
      return;
    }
    if (owners is TaskApiSuccess<List<TaskParticipant>>) {
      setState(() => _owners = owners.data);
    }
    if (people is TaskApiSuccess<List<TaskParticipant>>) {
      setState(() => _people = people.data);
    }
  }

  Future<void> _pollVisible() async {
    if (!_foreground || !mounted || _loading) return;
    await _refreshVisible(conditional: true);
  }

  Future<void> _refreshVisible({bool conditional = false}) async {
    if (_showMyTasks) {
      await _loadMyTasks(conditional: conditional);
      return;
    }
    final board = _board;
    if (board == null) {
      await _loadDirectory();
      return;
    }
    await _refreshBoardRows(board, conditional: conditional);
    if (!mounted || _board?.id != board.id) return;
    final detail = _detail;
    if (detail != null) {
      final privacy = _privacyGeneration;
      final result = await _tasks.taskDetail(
        board.id,
        detail.task.id,
        etag: conditional ? detail.etag : null,
      );
      if (!_isCurrentPrivacy(privacy) || _board?.id != board.id) return;
      if (result is TaskApiUnavailable<TaskDetailSnapshot>) {
        _evict(result.message);
      } else if (result is TaskApiSuccess<TaskDetailSnapshot>) {
        if (_dirtyDraftIds.isNotEmpty || _mutationQueues.isNotEmpty) {
          setState(() => _conflict = true);
        } else {
          _replaceDetail(result.data);
        }
      }
    }
  }

  Future<void> _refreshBoardRows(
    TaskBoard board, {
    bool conditional = false,
    int? requestedPage,
  }) async {
    final token = ++_queryToken;
    final privacy = _privacyGeneration;
    final viewId = _activeViewId;
    final targetPage = requestedPage ?? _page;
    final result = await _tasks.boardRows(
      board.id,
      viewId: viewId,
      configuration: _viewDirty ? _configuration : null,
      page: targetPage,
      etag: conditional ? _boardEtag : null,
    );
    if (!_isCurrentPrivacy(privacy) ||
        token != _queryToken ||
        _board?.id != board.id ||
        _activeViewId != viewId) {
      return;
    }
    if (result is TaskApiUnavailable<TaskRowsPage>) {
      _evict(result.message);
      return;
    }
    if (result is TaskApiNotModified<TaskRowsPage>) return;
    if (result is! TaskApiSuccess<TaskRowsPage>) {
      if (!conditional) setState(() => _error = result.message);
      return;
    }
    final page = result.data;
    if (page.page != targetPage || page.viewId != viewId) return;
    if (_dirtyDraftIds.isNotEmpty || _mutationQueues.isNotEmpty) {
      setState(() => _conflict = true);
      return;
    }
    if (_viewDirty && page.viewVersion != _activeViewVersion) {
      setState(() => _conflict = true);
      return;
    }
    setState(() {
      _rows = page.rows;
      _page = page.page;
      _lastPage = page.lastPage;
      _total = page.total;
      _statusSummaries = page.statusSummaries;
      _groupCounts = page.groupCounts;
      _boardEtag = page.etag;
      _activeViewVersion = page.viewVersion;
      if (!_viewDirty) _configuration = page.configuration;
    });
  }

  Future<void> _loadMyTasks({
    bool conditional = false,
    int? requestedPage,
  }) async {
    final privacy = _privacyGeneration;
    final pages = requestedPage == null
        ? (_myTaskPages.isEmpty
              ? const <int>[1]
              : (_myTaskPages.keys.toList()..sort()))
        : <int>[requestedPage];
    if (!conditional) {
      setState(() {
        _myTasksLoading = _myTasks == null;
        _myTasksLoadingMore = requestedPage != null && requestedPage > 1;
        _myTasksError = null;
      });
    }
    for (final page in pages) {
      if (!_isCurrentPrivacy(privacy)) return;
      final result = await _tasks.myTasks(
        page: page,
        etag: conditional ? _myTaskPages[page]?.etag : null,
      );
      if (!_isCurrentPrivacy(privacy)) return;
      if (result is TaskApiUnavailable<TaskMyTasksPage>) {
        _evict(result.message);
        return;
      }
      if (result is TaskApiNotModified<TaskMyTasksPage>) continue;
      if (result is TaskApiSuccess<TaskMyTasksPage>) {
        _myTaskPages[page] = result.data;
        _myTaskPages.removeWhere((loaded, _) => loaded > result.data.lastPage);
        _rebuildMyTasks();
      } else if (!conditional) {
        setState(() => _myTasksError = result.message);
        break;
      }
    }
    if (mounted) {
      setState(() {
        _myTasksLoading = false;
        _myTasksLoadingMore = false;
      });
    }
  }

  void _rebuildMyTasks() {
    final pages = _myTaskPages.values.toList()
      ..sort((left, right) => left.page.compareTo(right.page));
    if (pages.isEmpty) {
      _myTasks = null;
      return;
    }
    final first = pages.first;
    final last = pages.last;
    _myTasks = TaskMyTasksPage(
      items: pages.expand((page) => page.items).toList(growable: false),
      total: first.total,
      page: last.page,
      perPage: first.perPage,
      lastPage: first.lastPage,
      etag: first.etag,
    );
    _myTasksError = null;
  }

  Future<void> _loadMoreMyTasks() async {
    final current = _myTasks;
    if (current == null || current.page >= current.lastPage) return;
    await _loadMyTasks(requestedPage: current.page + 1);
  }

  void _openMyTasks() {
    setState(() {
      _privacyGeneration++;
      _showMyTasks = true;
      _showDirectory = false;
      _myTaskPages.clear();
      _myTasks = null;
    });
    _loadMyTasks();
  }

  Future<void> _openMyTask(TaskMyTask item) async {
    final privacy = _privacyGeneration;
    final result = await _tasks.board(item.boardId);
    if (!_isCurrentPrivacy(privacy)) return;
    if (result is TaskApiUnavailable<TaskBoard>) {
      _evict(result.message);
    } else if (result is TaskApiSuccess<TaskBoard>) {
      await _loadBoard(result.data, selectedTaskId: item.task.id);
    }
  }

  Future<void> _openSearchResult(TaskQuickSearchResult result) async {
    final privacy = _privacyGeneration;
    final board = await _tasks.board(result.boardId);
    if (!_isCurrentPrivacy(privacy)) return;
    if (board is TaskApiUnavailable<TaskBoard>) {
      _evict(board.message);
    } else if (board is TaskApiSuccess<TaskBoard>) {
      await _loadBoard(board.data, selectedTaskId: result.taskId);
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
    _mobileDetail.value = null;
    final privacy = _privacyGeneration;
    final result = await _tasks.taskDetail(board.id, taskId);
    if (consumeIntent
        ? (!_isCurrentPrivacy(privacy) ||
              !_isCurrentIntent(intent!, generation!) ||
              request != _requestGeneration)
        : (!_isCurrentPrivacy(privacy) || request != _requestGeneration)) {
      return;
    }
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
    _mobileDetail.value = result.data;
    if (consumeIntent) widget.onIntentConsumed?.call();
    if (!mounted) return;
    if (MediaQuery.sizeOf(context).width < 700) {
      await Navigator.of(context).push<void>(
        MaterialPageRoute<void>(
          builder: (_) => Scaffold(
            body: ValueListenableBuilder<TaskDetailSnapshot?>(
              valueListenable: _mobileDetail,
              builder: (context, detail, _) => TaskDetailPanel(
                task: detail,
                taskApi: _tasks,
                onTaskChanged: _replaceDetail,
                onOpenRecordRoute: _openRecordRoute,
                onDraftChanged: _draftChanged,
                privacyEpoch: _privacyGeneration,
                onUnavailable: _evict,
                fullScreen: true,
                onClose: () => Navigator.of(context).pop(),
              ),
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

  bool _isCurrentPrivacy(int privacy) =>
      mounted && privacy == _privacyGeneration;

  void _replaceDetail(TaskDetailSnapshot detail) {
    if (!mounted) return;
    setState(() => _detail = detail);
    _mobileDetail.value = detail;
  }

  Future<void> _openRecordRoute(String route) async {
    final onOpenRecordRoute = widget.onOpenRecordRoute;
    if (MediaQuery.sizeOf(context).width < 700) {
      await Navigator.of(context).maybePop();
    }
    onOpenRecordRoute?.call(route);
  }

  Future<void> _goToPage(int page) async {
    if (_bulkBusy) return;
    final board = _board;
    if (board == null || page < 1 || page > _lastPage) return;
    await _refreshBoardRows(board, requestedPage: page);
  }

  void _changeConfiguration(TaskViewConfiguration configuration) {
    setState(() {
      _configuration = configuration;
      _viewDirty = true;
      _page = 1;
      _queryToken++;
    });
    _queryDebounce?.cancel();
    _queryDebounce = Timer(const Duration(milliseconds: 300), () {
      final board = _board;
      if (board != null) _refreshBoardRows(board);
    });
  }

  Future<void> _selectView(TaskViewDefinition view) async {
    final board = _board;
    if (board == null || view.id == _activeViewId) return;
    final privacy = _privacyGeneration;
    setState(() {
      _activeViewId = view.id;
      _activeViewVersion = view.version;
      _configuration = view.configuration;
      _viewDirty = false;
      _conflict = false;
      _page = 1;
      _boardEtag = null;
    });
    await _refreshBoardRows(board);
    if (_isCurrentPrivacy(privacy) && _board?.id == board.id) {
      final preference = await _tasks.saveViewPreference(
        board.id,
        view.id,
        TaskViewPreferenceMutation(
          collapsedGroupIds:
              view.preference?.collapsedGroupIds ?? const <String>[],
          density: view.preference?.density ?? TaskViewDensity.compact,
          isLastActive: true,
          scrollLeft: view.preference?.scrollLeft ?? 0,
          scrollTop: view.preference?.scrollTop ?? 0,
        ),
      );
      if (!_isCurrentPrivacy(privacy) || _board?.id != board.id) return;
      if (preference is TaskApiUnavailable<TaskViewPreference>) {
        _evict(preference.message);
      }
    }
  }

  Future<void> _saveView(TaskViewDefinition view) async {
    final board = _board;
    if (board == null || !_viewDirty || !view.can('update')) return;
    final privacy = _privacyGeneration;
    final result = await _tasks.updateView(
      board.id,
      view.id,
      expectedViewVersion: view.version,
      changes: <String, Object?>{'configuration': _configuration.toJson()},
    );
    if (!_isCurrentPrivacy(privacy) || _board?.id != board.id) return;
    if (result is TaskApiUnavailable<TaskViewMutationResult>) {
      _evict(result.message);
    } else if (result is TaskApiConflictResult<TaskViewMutationResult> ||
        result is TaskApiViewConflict<TaskViewMutationResult>) {
      setState(() => _conflict = true);
    } else if (result is TaskApiSuccess<TaskViewMutationResult>) {
      setState(() {
        _views = _views
            .map(
              (current) =>
                  current.id == view.id ? result.data.definition : current,
            )
            .toList(growable: false);
        _activeViewVersion = result.data.definition.version;
        _viewDirty = false;
      });
    }
  }

  Future<void> _createView(
    String name,
    TaskViewVisibility visibility,
    bool locked,
  ) async {
    final board = _board;
    if (board == null ||
        name.trim().isEmpty ||
        (visibility == TaskViewVisibility.personal &&
            !_canCreatePersonalView) ||
        (visibility == TaskViewVisibility.shared && !_canCreateSharedView)) {
      return;
    }
    final privacy = _privacyGeneration;
    final result = await _tasks.createView(
      board.id,
      name: name.trim(),
      visibility: visibility,
      isLocked: locked,
      configuration: _configuration,
      expectedStructureVersion: visibility == TaskViewVisibility.shared
          ? board.structureVersion
          : null,
    );
    if (!_isCurrentPrivacy(privacy) || _board?.id != board.id) return;
    if (result is TaskApiUnavailable<TaskViewMutationResult>) {
      _evict(result.message);
    } else if (result is TaskApiConflictResult<TaskViewMutationResult>) {
      setState(() => _conflict = true);
    } else if (result is TaskApiSuccess<TaskViewMutationResult>) {
      setState(() {
        _showViewEditor = false;
        _views = [..._views, result.data.definition];
        _activeViewId = result.data.definition.id;
        _activeViewVersion = result.data.definition.version;
        _viewDirty = false;
      });
      if (visibility == TaskViewVisibility.shared) {
        final refreshed = await _tasks.board(board.id);
        if (!_isCurrentPrivacy(privacy) || _board?.id != board.id) return;
        if (refreshed is TaskApiUnavailable<TaskBoard>) {
          _evict(refreshed.message);
        } else if (refreshed is TaskApiSuccess<TaskBoard>) {
          setState(() => _board = refreshed.data);
        }
      }
    }
  }

  Future<void> _duplicateView(TaskViewDefinition view) async {
    final board = _board;
    if (board == null || !view.can('duplicate')) return;
    final privacy = _privacyGeneration;
    final result = await _tasks.duplicateView(
      board.id,
      view.id,
      name: '${view.name} copy',
      expectedViewVersion: view.version,
    );
    if (!_isCurrentPrivacy(privacy) || _board?.id != board.id) return;
    if (result is TaskApiUnavailable<TaskViewDefinition>) {
      _evict(result.message);
    } else if (result is TaskApiConflictResult<TaskViewDefinition>) {
      setState(() => _conflict = true);
    } else if (result is TaskApiSuccess<TaskViewDefinition>) {
      setState(() => _views = [..._views, result.data]);
      await _selectView(result.data);
    }
  }

  Future<void> _deleteView(TaskViewDefinition view) async {
    final board = _board;
    if (board == null || !view.can('delete')) return;
    final privacy = _privacyGeneration;
    final result = await _tasks.deleteView(
      board.id,
      view.id,
      expectedViewVersion: view.visibility == TaskViewVisibility.personal
          ? view.version
          : null,
      expectedStructureVersion: view.visibility == TaskViewVisibility.shared
          ? board.structureVersion
          : null,
    );
    if (!_isCurrentPrivacy(privacy) || _board?.id != board.id) return;
    if (result is TaskApiUnavailable<bool>) {
      _evict(result.message);
    } else if (result is TaskApiConflictResult<bool>) {
      setState(() => _conflict = true);
    } else if (result is TaskApiSuccess<bool>) {
      if (view.visibility == TaskViewVisibility.shared) {
        final refreshed = await _tasks.board(board.id);
        if (!_isCurrentPrivacy(privacy) || _board?.id != board.id) return;
        if (refreshed is TaskApiUnavailable<TaskBoard>) {
          _evict(refreshed.message);
          return;
        }
        if (refreshed is! TaskApiSuccess<TaskBoard>) {
          setState(() => _conflict = true);
          return;
        }
        setState(() => _board = refreshed.data);
      }
      setState(() {
        _views = _views.where((current) => current.id != view.id).toList();
      });
      final next = _views
          .where((candidate) => candidate.isPermanentMain)
          .firstOrNull;
      if (next != null) await _selectView(next);
    }
  }

  Future<void> _rename(TaskRow task, String title) async {
    final trimmed = title.trim();
    if (trimmed.isEmpty || trimmed == task.title) return;
    final privacy = _privacyGeneration;
    final result = await _tasks.updateTask(
      task.id,
      expectedTaskVersion: task.version,
      changes: <String, Object?>{'title': trimmed},
    );
    if (!_isCurrentPrivacy(privacy)) return;
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

  void _draftChanged(String draftId, bool dirty) {
    dirty ? _dirtyDraftIds.add(draftId) : _dirtyDraftIds.remove(draftId);
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
    description: source.description,
    descriptionPlain: source.descriptionPlain,
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

  Future<void> _enqueue(String taskId, Future<void> Function() operation) {
    final prior = _mutationQueues[taskId] ?? Future<void>.value();
    final next = prior.catchError((_) {}).then((_) => operation());
    _mutationQueues[taskId] = next;
    return next.whenComplete(() {
      if (identical(_mutationQueues[taskId], next)) {
        _mutationQueues.remove(taskId);
      }
    });
  }

  TaskRow _mergeServerRow(TaskRow prior, TaskRow server) => TaskRow(
    id: server.id,
    boardId: server.boardId,
    groupId: server.groupId,
    parentTaskId: server.parentTaskId,
    number: server.number,
    title: server.title,
    ownerUserId: server.ownerUserId,
    collaboratorUserIds: server.collaboratorUserIds,
    statusOptionId: server.statusOptionId,
    priorityOptionId: server.priorityOptionId,
    startOn: server.startOn,
    dueOn: server.dueOn,
    position: server.position,
    version: server.version,
    detailVersion: server.detailVersion,
    values: prior.values,
    groupByValue: server.groupByValue,
    subtasks: prior.subtasks,
  );

  Future<void> _blockedEdit() async =>
      _announce('Finish the bulk change before editing this task.');

  Future<void> _saveCore(TaskRow task, String field, Object? value) =>
      _bulkLockedTaskIds.contains(task.id)
      ? _blockedEdit()
      : _enqueue(task.id, () => _saveCoreNow(task.id, field, value));

  Future<void> _saveCoreNow(String taskId, String field, Object? value) async {
    final task = _rows.where((row) => row.id == taskId).firstOrNull;
    if (task == null) return;
    final optimistic = switch (field) {
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
      _ => task,
    };
    if (!identical(optimistic, task)) _putRow(optimistic);
    _announce('Saving ${field.replaceAll('_', ' ')}.');
    final privacy = _privacyGeneration;
    final result = await _tasks.updateTask(
      task.id,
      expectedTaskVersion: task.version,
      changes: <String, Object?>{field: value},
    );
    if (!_isCurrentPrivacy(privacy)) return;
    if (result is TaskApiSuccess<TaskRow>) {
      _putRow(_mergeServerRow(task, result.data));
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
  ) => _bulkLockedTaskIds.contains(task.id)
      ? _blockedEdit()
      : _enqueue(task.id, () => _saveCustomNow(task.id, column, value));

  Future<void> _saveCustomNow(
    String taskId,
    TaskColumnDefinition column,
    Object? value,
  ) async {
    final task = _rows.where((row) => row.id == taskId).firstOrNull;
    if (task == null) return;
    _announce('Saving ${column.name}.');
    final privacy = _privacyGeneration;
    final result = await _tasks.updateColumnValue(
      task.id,
      column.id,
      expectedTaskVersion: task.version,
      value: value,
    );
    if (!_isCurrentPrivacy(privacy)) return;
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
      if (result is TaskApiConflictResult<TaskColumnValueMutation>) {
        _conflict = true;
      }
      _announce(result.message ?? 'The change could not be saved.');
      setState(() {});
    }
  }

  Future<void> _bulk(List<TaskRow> tasks, Map<String, Object?> changes) async {
    final board = _board;
    if (board == null || _bulkBusy) return;
    final privacy = _privacyGeneration;
    final selectedIds = tasks.map((task) => task.id).toSet();
    setState(() {
      _bulkBusy = true;
      _bulkLockedTaskIds = selectedIds;
    });
    try {
      await Future.wait(
        selectedIds.map((id) => _mutationQueues[id] ?? Future<void>.value()),
      );
      if (!_isCurrentPrivacy(privacy) || _board?.id != board.id) return;
      final latest = selectedIds
          .map((id) => _rows.where((row) => row.id == id).firstOrNull)
          .whereType<TaskRow>()
          .toList(growable: false);
      if (latest.length != selectedIds.length) {
        _announce('One or more selected tasks are unavailable.');
        return;
      }
      _announce('Saving changes to ${latest.length} selected tasks.');
      final result = await _tasks.bulkUpdate(
        board.id,
        targets: latest
            .map(
              (task) =>
                  TaskBulkTarget(id: task.id, expectedVersion: task.version),
            )
            .toList(growable: false),
        changes: changes,
      );
      if (!_isCurrentPrivacy(privacy) || _board?.id != board.id) return;
      if (result is TaskApiSuccess<List<TaskRow>>) {
        for (final row in result.data) {
          final previous = _rows
              .where((current) => current.id == row.id)
              .firstOrNull;
          _putRow(previous == null ? row : _mergeServerRow(previous, row));
        }
        _announce('Changes saved for ${result.data.length} tasks.');
      } else if (result is TaskApiUnavailable<List<TaskRow>>) {
        _evict(result.message);
      } else {
        if (result is TaskApiConflictResult<List<TaskRow>>) _conflict = true;
        _announce(result.message ?? 'No selected tasks were changed.');
        setState(() {});
      }
    } finally {
      if (mounted) {
        setState(() {
          _bulkBusy = false;
          _bulkLockedTaskIds = const <String>{};
        });
      }
    }
  }

  Future<void> _createBoard(String name) async {
    final privacy = _privacyGeneration;
    final result = await _tasks.createBoard(
      name: name,
      visibility: TaskBoardVisibility.scope,
    );
    if (!_isCurrentPrivacy(privacy)) return;
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

  Future<void> _addTaskToGroup(String? groupId) async {
    final board = _board;
    if (board == null || !board.can('create_task')) return;
    final status =
        board.statuses
            .where((item) => !item.archived)
            .where((item) => item.category == TaskStatusCategory.notStarted)
            .firstOrNull ??
        board.statuses.where((item) => !item.archived).firstOrNull;
    if (status == null) {
      _announce('Add a status before creating a task.');
      return;
    }
    final controller = TextEditingController();
    final title = await showDialog<String>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Add task'),
        content: TextField(
          key: const ValueKey('task-create-title'),
          controller: controller,
          autofocus: true,
          decoration: const InputDecoration(labelText: 'Task title'),
          onSubmitted: (value) {
            if (value.trim().isNotEmpty) {
              Navigator.of(dialogContext).pop(value.trim());
            }
          },
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () {
              final value = controller.text.trim();
              if (value.isNotEmpty) Navigator.of(dialogContext).pop(value);
            },
            child: const Text('Add task'),
          ),
        ],
      ),
    );
    controller.dispose();
    if (title == null || !mounted || _board?.id != board.id) return;
    final privacy = _privacyGeneration;
    final result = await _tasks.createTask(
      board.id,
      title: title,
      groupId: groupId,
      statusOptionId: status.id,
    );
    if (!_isCurrentPrivacy(privacy) || _board?.id != board.id) return;
    if (result is TaskApiUnavailable<TaskRow>) {
      _evict(result.message);
    } else if (result is TaskApiSuccess<TaskRow>) {
      setState(() {
        _rows = [..._rows, result.data];
        _total++;
      });
      _announce('$title added.');
    } else {
      _announce(result.message ?? 'The task could not be added.');
    }
  }

  Future<void> _reloadBoardDefinition() async {
    final boardId = _board?.id;
    if (boardId == null) return;
    setState(() => _conflict = false);
    final privacy = _privacyGeneration;
    final result = await _tasks.board(boardId);
    if (!_isCurrentPrivacy(privacy) || _board?.id != boardId) return;
    if (result is TaskApiUnavailable<TaskBoard>) {
      _evict(result.message);
    } else if (result is TaskApiSuccess<TaskBoard>) {
      await _loadBoard(result.data);
    } else {
      setState(() => _error = result.message);
    }
  }

  Future<void> _openTaskSurface(String title, Widget child) async {
    if (MediaQuery.sizeOf(context).width < 700) {
      await Navigator.of(context).push<void>(
        MaterialPageRoute(
          builder: (_) => Scaffold(
            appBar: AppBar(title: Text(title)),
            body: SafeArea(
              child: Padding(padding: const EdgeInsets.all(20), child: child),
            ),
          ),
        ),
      );
      return;
    }
    await showSidePanel<void>(
      context,
      builder: (_) =>
          SidePanel(title: title, width: 620, scrollable: false, child: child),
    );
  }

  void _surfaceUnavailable(String? message) {
    Navigator.of(context, rootNavigator: true).maybePop();
    _evict(message);
  }

  void _replaceBoardDefinition(TaskBoard changed) {
    if (!mounted || _board?.id != changed.id) return;
    setState(() {
      _board = changed;
      _boards = [
        for (final board in _boards)
          if (board.id == changed.id) changed else board,
      ];
    });
    if (changed.archived) {
      Navigator.of(context, rootNavigator: true).maybePop();
      setState(() {
        _board = null;
        _showDirectory = true;
      });
      _loadDirectory();
    }
  }

  void _openAdministration() {
    final board = _board;
    if (board == null) return;
    _openTaskSurface(
      'Board administration',
      TaskBoardAdministration(
        api: _tasks,
        board: board,
        folders: _folders,
        onBoardChanged: _replaceBoardDefinition,
        onUnavailable: _surfaceUnavailable,
      ),
    );
  }

  void _openDuplicate() {
    final board = _board;
    final actorId = widget.session.me?.id;
    if (board == null || actorId == null || !board.can('duplicate')) return;
    TaskDuplicateDraftStore.pending = TaskDuplicateDraft(
      sourceBoardId: board.id,
      actorUserId: actorId,
    );
    _continueDuplicate();
  }

  TaskDuplicateDraft? get _pendingDuplicate {
    final draft = TaskDuplicateDraftStore.pending;
    return draft?.actorUserId == widget.session.me?.id ? draft : null;
  }

  void _continueDuplicate() {
    final draft = _pendingDuplicate;
    if (draft == null) return;
    _openTaskSurface(
      'Duplicate board',
      TaskDuplicateContinuation(
        api: _tasks,
        draft: draft,
        destinationLabel: widget.session.me?.scope.fullPath ?? 'Current view',
        onChooseAnotherDestination: () {
          Navigator.of(context, rootNavigator: true).maybePop();
          _announce('Choose the destination with the top-bar switcher.');
        },
        onDuplicated: (duplicated) {
          TaskDuplicateDraftStore.pending = null;
          Navigator.of(context, rootNavigator: true).maybePop();
          _loadDirectory();
        },
        onInvalid: (_) {
          TaskDuplicateDraftStore.pending = null;
          Navigator.of(context, rootNavigator: true).maybePop();
          _announce('The source board is no longer available.');
        },
      ),
    );
  }

  void _openTransfer() {
    final board = _board;
    final viewId = _activeViewId;
    if (board == null || viewId == null) return;
    _openTaskSurface(
      'Import and export',
      TaskTransferFlow(
        api: _tasks,
        board: board,
        activeViewId: viewId,
        onUnavailable: _surfaceUnavailable,
        onImported: () => _refreshBoardRows(board),
      ),
    );
  }

  void _openRecovery() {
    _openTaskSurface(
      'Board recovery',
      TaskBoardRecoveryList(api: _tasks, onUnavailable: _surfaceUnavailable),
    );
  }

  void _openFolderAdministration() {
    _openTaskSurface(
      'Board folders',
      TaskFolderAdministration(
        api: _tasks,
        directoryActions: _directoryActions,
        onChanged: (folders) {
          if (mounted) setState(() => _folders = folders);
        },
        onUnavailable: _surfaceUnavailable,
      ),
    );
  }

  void _evict(String? message) {
    _tasks.clearCache();
    TaskDuplicateDraftStore.pending = null;
    if (!mounted) return;
    setState(() {
      _loading = false;
      _boards = const [];
      _directoryActions = const [];
      _folders = const [];
      _board = null;
      _rows = const [];
      _detail = null;
      _selectedTaskId = null;
      _error = null;
      _unavailable = true;
      _views = const [];
      _canCreatePersonalView = false;
      _canCreateSharedView = false;
      _activeViewId = null;
      _configuration = TaskViewConfiguration.empty;
      _boardEtag = null;
      _myTasks = null;
      _myTaskPages.clear();
      _myTasksError = null;
      _showMyTasks = false;
      _total = 0;
      _statusSummaries = const [];
      _groupCounts = const [];
      _dirtyDraftIds.clear();
      _privacyGeneration++;
    });
    _mobileDetail.value = null;
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
                              'This task or saved view changed while you were editing it.',
                            ),
                            actions: [
                              TextButton(
                                onPressed: _reloadBoardDefinition,
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
      : _showViewEditor
      ? _NewViewPane(
          canShare: _canCreateSharedView,
          onCancel: () => setState(() => _showViewEditor = false),
          onCreate: _createView,
        )
      : _showMyTasks
      ? MyTasksView(
          page: _myTasks,
          loading: _myTasksLoading,
          error: _myTasksError,
          onRetry: _loadMyTasks,
          onOpen: _openMyTask,
          onLoadMore: _loadMoreMyTasks,
          loadingMore: _myTasksLoadingMore,
        )
      : _showDirectory || _board == null
      ? _directoryOnly()
      : mobile
      ? Column(
          children: [
            _boardControls(),
            if (_showFilters)
              SizedBox(
                height: 330,
                child: TaskFiltersPanel(
                  board: _board!,
                  configuration: _configuration,
                  people: _people,
                  onChanged: _changeConfiguration,
                  onClose: () => setState(() => _showFilters = false),
                ),
              ),
            Expanded(
              child: TaskMobileList(
                board: _board!,
                rows: _rows,
                onSelected: _selectTask,
                onAddTask: _board!.can('create_task') ? _addTaskToGroup : null,
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
          padding: const EdgeInsets.fromLTRB(20, 14, 16, 8),
          child: LayoutBuilder(
            builder: (context, constraints) {
              final heading = const Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Tasks',
                    style: TextStyle(fontSize: 25, fontWeight: FontWeight.w700),
                  ),
                  SizedBox(height: 3),
                  Text('Plan, assign, and finish work in this view.'),
                ],
              );
              final search = SizedBox(
                width: constraints.maxWidth < 700 ? constraints.maxWidth : 320,
                child: TaskQuickSearch(
                  key: ValueKey('task-search-$_privacyGeneration'),
                  api: _tasks,
                  onOpen: _openSearchResult,
                  onUnavailable: () => _evict(null),
                ),
              );
              final actions = Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (_pendingDuplicate != null)
                    IconButton(
                      tooltip: 'Continue board duplication',
                      onPressed: _continueDuplicate,
                      icon: const Icon(Icons.copy_all_outlined),
                    ),
                  IconButton(
                    tooltip: 'Refresh tasks',
                    onPressed: _refreshVisible,
                    icon: const Icon(Icons.refresh),
                  ),
                  if (_board != null)
                    IconButton(
                      tooltip: _detailOpen ? 'Hide details' : 'Show details',
                      icon: Icon(
                        _detailOpen
                            ? Icons.close_fullscreen
                            : Icons.open_in_full,
                      ),
                      onPressed: () =>
                          setState(() => _detailOpen = !_detailOpen),
                    ),
                ],
              );
              if (constraints.maxWidth < 700) {
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      children: [
                        Expanded(child: heading),
                        actions,
                      ],
                    ),
                    const SizedBox(height: 8),
                    search,
                  ],
                );
              }
              return Row(
                children: [
                  Expanded(child: heading),
                  search,
                  const SizedBox(width: 8),
                  actions,
                ],
              );
            },
          ),
        ),
        TabBarLike(
          labels: const [
            ('Boards', Icons.view_list_outlined),
            ('My Tasks', Icons.assignment_ind_outlined),
          ],
          selected: _showMyTasks ? 1 : 0,
          onSelected: (index) {
            if (index == 1) {
              _openMyTasks();
            } else {
              setState(() {
                _showMyTasks = false;
                _showDirectory = true;
              });
            }
          },
        ),
      ],
    ),
  );

  Widget _directoryOnly() => _boards.isEmpty
      ? _EmptyDirectory(
          onCreate: _directoryActions.contains('create_board')
              ? () => setState(() => _creating = true)
              : null,
          onRecover: _directoryActions.contains('recover_private_boards')
              ? _openRecovery
              : null,
          onManageFolders:
              _directoryActions.contains('create_folder') ||
                  _directoryActions.contains('view_archived')
              ? _openFolderAdministration
              : null,
        )
      : BoardDirectory(
          boards: _boards,
          folders: _folders,
          selectedBoardId: null,
          onSelected: (board) {
            setState(() => _showDirectory = false);
            _loadBoard(board);
          },
          onCreate: _directoryActions.contains('create_board')
              ? () => setState(() => _creating = true)
              : null,
          onRecover: _directoryActions.contains('recover_private_boards')
              ? _openRecovery
              : null,
          onManageFolders:
              _directoryActions.contains('create_folder') ||
                  _directoryActions.contains('view_archived')
              ? _openFolderAdministration
              : null,
        );

  Widget _boardControls() {
    final statusNames = <String?, String>{
      for (final status in _board?.statuses ?? const <TaskStatusOption>[])
        status.id: status.name,
      null: 'No status',
    };
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_views.isNotEmpty)
          TaskViewsBar(
            views: _views,
            activeViewId: _activeViewId,
            canCreatePersonal: _canCreatePersonalView,
            dirty: _viewDirty,
            onSelected: _selectView,
            onCreate: () => setState(() => _showViewEditor = true),
            onSave: _saveView,
            onDuplicate: _duplicateView,
            onDelete: _deleteView,
          ),
        Material(
          color: Theme.of(context).colorScheme.surfaceContainerLowest,
          child: SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              child: Row(
                children: [
                  Text('$_total ${_total == 1 ? 'task' : 'tasks'}'),
                  for (final summary in _statusSummaries) ...[
                    const SizedBox(width: 8),
                    Chip(
                      visualDensity: VisualDensity.compact,
                      label: Text(
                        '${statusNames[summary.statusOptionId] ?? 'Status'} ${summary.count}',
                      ),
                    ),
                  ],
                  const SizedBox(width: 8),
                  OutlinedButton.icon(
                    onPressed: () =>
                        setState(() => _showFilters = !_showFilters),
                    icon: const Icon(Icons.filter_list),
                    label: const Text('Filters'),
                  ),
                  if (_board case final board?) ...[
                    if (board.can('update') ||
                        board.can('archive') ||
                        board.can('restore') ||
                        board.can('manage_structure') ||
                        board.can('manage_members')) ...[
                      const SizedBox(width: 8),
                      OutlinedButton.icon(
                        onPressed: _openAdministration,
                        icon: const Icon(Icons.settings_outlined),
                        label: const Text('Admin'),
                      ),
                    ],
                    if (board.can('duplicate')) ...[
                      const SizedBox(width: 8),
                      OutlinedButton.icon(
                        onPressed: _openDuplicate,
                        icon: const Icon(Icons.copy_all_outlined),
                        label: const Text('Duplicate'),
                      ),
                    ],
                    if (_activeViewId != null &&
                        (board.can('import') ||
                            board.can('export') ||
                            board.can('archive_manifest'))) ...[
                      const SizedBox(width: 8),
                      OutlinedButton.icon(
                        onPressed: _openTransfer,
                        icon: const Icon(Icons.import_export),
                        label: const Text('Transfer'),
                      ),
                    ],
                  ],
                  if (_viewDirty) ...[
                    const SizedBox(width: 8),
                    const Text('Unsaved view changes'),
                  ],
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }

  Widget _workspace(bool wide) => LayoutBuilder(
    builder: (context, constraints) {
      final showDirectory = wide && constraints.maxWidth >= 1100;
      final showDetail = _detailOpen && constraints.maxWidth >= 800;
      if (_detailOpen && !showDetail && _detail != null) {
        return TaskDetailPanel(
          task: _detail,
          taskApi: _tasks,
          onTaskChanged: _replaceDetail,
          onOpenRecordRoute: _openRecordRoute,
          onDraftChanged: _draftChanged,
          privacyEpoch: _privacyGeneration,
          onUnavailable: _evict,
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
                folders: _folders,
                selectedBoardId: _board!.id,
                onSelected: (board) {
                  setState(() => _showDirectory = false);
                  _loadBoard(board);
                },
                onCreate: _directoryActions.contains('create_board')
                    ? () => setState(() => _creating = true)
                    : null,
                onRecover: _directoryActions.contains('recover_private_boards')
                    ? _openRecovery
                    : null,
                onManageFolders:
                    _directoryActions.contains('create_folder') ||
                        _directoryActions.contains('view_archived')
                    ? _openFolderAdministration
                    : null,
              ),
            ),
            const VerticalDivider(width: 1),
          ],
          Expanded(
            child: Column(
              children: [
                _boardControls(),
                if (_showFilters)
                  SizedBox(
                    height: 330,
                    child: TaskFiltersPanel(
                      board: _board!,
                      configuration: _configuration,
                      people: _people,
                      onChanged: _changeConfiguration,
                      onClose: () => setState(() => _showFilters = false),
                    ),
                  ),
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
                    bulkBusy: _bulkBusy,
                    interactionLocked: _bulkBusy,
                    owners: _owners,
                    people: _people,
                    configuration: _configuration,
                    groupCounts: _groupCounts,
                    onDraftChanged: _draftChanged,
                    onAddTask: _board!.can('create_task')
                        ? _addTaskToGroup
                        : null,
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
                taskApi: _tasks,
                onTaskChanged: _replaceDetail,
                onOpenRecordRoute: _openRecordRoute,
                onDraftChanged: _draftChanged,
                privacyEpoch: _privacyGeneration,
                onUnavailable: _evict,
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

extension _TasksFirstOrNull<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}

class _EmptyDirectory extends StatelessWidget {
  const _EmptyDirectory({this.onCreate, this.onRecover, this.onManageFolders});
  final VoidCallback? onCreate;
  final VoidCallback? onRecover;
  final VoidCallback? onManageFolders;
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
            if (onCreate != null)
              FilledButton.icon(
                onPressed: onCreate,
                icon: const Icon(Icons.add),
                label: const Text('New board'),
              ),
            if (onRecover != null) ...[
              const SizedBox(height: 8),
              TextButton.icon(
                onPressed: onRecover,
                icon: const Icon(Icons.admin_panel_settings_outlined),
                label: const Text('Recover private boards'),
              ),
            ],
            if (onManageFolders != null) ...[
              const SizedBox(height: 8),
              TextButton.icon(
                onPressed: onManageFolders,
                icon: const Icon(Icons.folder_outlined),
                label: const Text('Manage folders'),
              ),
            ],
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

class _NewViewPane extends StatefulWidget {
  const _NewViewPane({
    required this.canShare,
    required this.onCancel,
    required this.onCreate,
  });

  final bool canShare;
  final VoidCallback onCancel;
  final Future<void> Function(String, TaskViewVisibility, bool) onCreate;

  @override
  State<_NewViewPane> createState() => _NewViewPaneState();
}

class _NewViewPaneState extends State<_NewViewPane> {
  final _name = TextEditingController();
  TaskViewVisibility _visibility = TaskViewVisibility.personal;
  bool _locked = false;
  bool _saving = false;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_saving || _name.text.trim().isEmpty) return;
    setState(() => _saving = true);
    await widget.onCreate(_name.text, _visibility, _locked);
    if (mounted) setState(() => _saving = false);
  }

  @override
  Widget build(BuildContext context) => Align(
    alignment: Alignment.topCenter,
    child: ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 540),
      child: ListView(
        padding: const EdgeInsets.all(24),
        shrinkWrap: true,
        children: [
          Text(
            'New saved view',
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          const SizedBox(height: 8),
          const Text(
            'Filters, sorting, grouping, columns, and widths stay with this view.',
          ),
          const SizedBox(height: 20),
          TextField(
            controller: _name,
            autofocus: true,
            decoration: const InputDecoration(labelText: 'View name'),
            onSubmitted: (_) => _save(),
          ),
          const SizedBox(height: 12),
          SegmentedButton<TaskViewVisibility>(
            segments: [
              const ButtonSegment(
                value: TaskViewVisibility.personal,
                label: Text('Personal'),
                icon: Icon(Icons.person_outline),
              ),
              ButtonSegment(
                value: TaskViewVisibility.shared,
                enabled: widget.canShare,
                label: const Text('Shared'),
                icon: const Icon(Icons.groups_outlined),
              ),
            ],
            selected: <TaskViewVisibility>{_visibility},
            onSelectionChanged: (selection) => setState(() {
              _visibility = selection.single;
              if (_visibility == TaskViewVisibility.personal) _locked = false;
            }),
          ),
          if (_visibility == TaskViewVisibility.shared)
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('Lock this shared view'),
              subtitle: const Text(
                'Only task managers can change a locked view.',
              ),
              value: _locked,
              onChanged: (value) => setState(() => _locked = value),
            ),
          const SizedBox(height: 16),
          Row(
            children: [
              TextButton(
                onPressed: _saving ? null : widget.onCancel,
                child: const Text('Cancel'),
              ),
              const Spacer(),
              FilledButton(
                onPressed: _saving ? null : _save,
                child: Text(_saving ? 'Saving…' : 'Save view'),
              ),
            ],
          ),
        ],
      ),
    ),
  );
}
