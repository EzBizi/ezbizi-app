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
import 'tasks/name_entry_panel.dart';
import 'tasks/task_api.dart';
import 'tasks/task_board_administration.dart';
import 'tasks/task_board_members.dart';
import 'tasks/task_calendar_view.dart';
import 'tasks/task_detail_panel.dart';
import 'tasks/task_duplicate_flow.dart';
import 'tasks/task_filters_panel.dart';
import 'tasks/task_folder_administration.dart';
import 'tasks/task_kanban_view.dart';
import 'tasks/task_mobile_list.dart';
import 'tasks/task_models.dart';
import 'tasks/task_presentation_bar.dart';
import 'tasks/task_quick_search.dart';
import 'tasks/task_table.dart';
import 'tasks/task_timeline_view.dart';
import 'tasks/task_transfer_flow.dart';
import 'tasks/task_views_bar.dart';
import 'tasks/task_workload_view.dart';

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
  // House rule 4: the client shows the SERVER's refusal sentence, never its
  // own. `_evict` is called from every kind of access loss (403, 404,
  // revoked reach mid-poll, etc.) across every screen and flow that can
  // discover a board is gone — including quick search, which used to
  // throw its `result.message` away before calling this with `null`. As
  // of this fix every call site forwards the server's own sentence; none
  // currently passes `null` on purpose. The fallback below stays only
  // because the type is `String?` and a future call site with genuinely
  // nothing to quote (there is no such site today) should still render
  // something instead of a blank screen — it is not, right now, load-
  // bearing for any real refusal.
  String? _unavailableMessage;
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
  TaskKanbanField _kanbanField = TaskKanbanField.status;
  DateTime _calendarMonth = DateTime.utc(
    DateTime.now().year,
    DateTime.now().month,
  );
  DateTime _timelineMonth = DateTime.utc(
    DateTime.now().year,
    DateTime.now().month,
  );
  DateTime _workloadMonth = DateTime.utc(
    DateTime.now().year,
    DateTime.now().month,
  );
  List<TaskWorkloadRow> _workloadRows = const [];
  TaskOmitted _omitted = TaskOmitted.zero;
  String? _boardEtag;
  TaskMyTasksPage? _myTasks;
  final Map<int, TaskMyTasksPage> _myTaskPages = <int, TaskMyTasksPage>{};
  String? _myTasksError;
  bool _myTasksLoading = false;
  bool _myTasksLoadingMore = false;
  bool _showMyTasks = false;
  // Task 14: Calendar, Timeline and Workload opened ACROSS the current view
  // rather than one board — reachable the way My Tasks itself is, so this
  // rides inside `_showMyTasks` as a presentation switch rather than a
  // fourth top-level nav destination. READ-ONLY throughout: every widget
  // these feed is opened with `readOnly: true`, which removes every drag
  // surface from the tree rather than merely disabling one (task-14 brief).
  TaskPresentation _myTasksPresentation = TaskPresentation.table;
  DateTime _myTasksCalendarMonth = DateTime.utc(
    DateTime.now().year,
    DateTime.now().month,
  );
  DateTime _myTasksTimelineMonth = DateTime.utc(
    DateTime.now().year,
    DateTime.now().month,
  );
  DateTime _myTasksWorkloadMonth = DateTime.utc(
    DateTime.now().year,
    DateTime.now().month,
  );
  List<TaskMyTask> _myTasksCalendarItems = const [];
  List<TaskMyTask> _myTasksTimelineItems = const [];
  List<TaskWorkloadRow> _myTasksWorkloadRows = const [];
  TaskOmitted _myTasksPresentationOmitted = TaskOmitted.zero;
  bool _myTasksPresentationLoading = false;
  String? _myTasksPresentationError;
  String? _myTasksPresentationEtag;
  bool _showFilters = false;
  bool _showViewEditor = false;
  NameEntryRequest? _nameRequest;
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
      _workloadRows = const [];
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
      _omitted = TaskOmitted.zero;
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
      _workloadRows = result.data.workloadRows;
      _page = result.data.page;
      _lastPage = result.data.lastPage;
      _total = result.data.total;
      _statusSummaries = result.data.statusSummaries;
      _groupCounts = result.data.groupCounts;
      _omitted = result.data.omitted;
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
      switch (_myTasksPresentation) {
        case TaskPresentation.table:
          await _loadMyTasks(conditional: conditional);
        case TaskPresentation.calendar:
          await _loadMyTasksPresentation(
            TaskPresentation.calendar,
            TaskDateRangeWindow(
              from: DateTime.utc(
                _myTasksCalendarMonth.year,
                _myTasksCalendarMonth.month,
                1,
              ),
              to: DateTime.utc(
                _myTasksCalendarMonth.year,
                _myTasksCalendarMonth.month + 1,
                0,
              ),
            ),
            conditional: conditional,
          );
        case TaskPresentation.timeline:
          await _loadMyTasksPresentation(
            TaskPresentation.timeline,
            TaskDateRangeWindow(
              from: DateTime.utc(
                _myTasksTimelineMonth.year,
                _myTasksTimelineMonth.month,
                1,
              ),
              to: DateTime.utc(
                _myTasksTimelineMonth.year,
                _myTasksTimelineMonth.month + 1,
                0,
              ),
            ),
            conditional: conditional,
          );
        case TaskPresentation.workload:
          await _loadMyTasksPresentation(
            TaskPresentation.workload,
            _myTasksWorkloadWindowFor(_myTasksWorkloadMonth),
            conditional: conditional,
          );
        case TaskPresentation.kanban:
          break;
      }
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
      _workloadRows = page.workloadRows;
      _page = page.page;
      _lastPage = page.lastPage;
      _total = page.total;
      _statusSummaries = page.statusSummaries;
      _groupCounts = page.groupCounts;
      _omitted = page.omitted;
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
      _myTasksPresentation = TaskPresentation.table;
      _myTasksCalendarItems = const [];
      _myTasksTimelineItems = const [];
      _myTasksWorkloadRows = const [];
      _myTasksPresentationOmitted = TaskOmitted.zero;
      _myTasksPresentationError = null;
      _myTasksPresentationEtag = null;
    });
    _loadMyTasks();
  }

  /// Switches My Tasks' active presentation — the same shape
  /// `_changePresentation` already uses for a board, restricted to the
  /// three that make sense across every board in view (task-14 brief):
  /// Table stays owner-filtered (`myTasks()`, unchanged); Calendar,
  /// Timeline and Workload each re-window and reload through
  /// `TaskApi.myTasksPresentation`. Kanban is never reachable here — it is
  /// absent from the bar's `availablePresentations` this screen passes.
  void _changeMyTasksPresentation(TaskPresentation presentation) {
    setState(() => _myTasksPresentation = presentation);
    switch (presentation) {
      case TaskPresentation.table:
        if (_myTasks == null) _loadMyTasks();
      case TaskPresentation.calendar:
        _applyMyTasksCalendarWindow(_myTasksCalendarMonth);
      case TaskPresentation.timeline:
        _applyMyTasksTimelineWindow(_myTasksTimelineMonth);
      case TaskPresentation.workload:
        _applyMyTasksWorkloadWindow(_myTasksWorkloadMonth);
      case TaskPresentation.kanban:
        break;
    }
  }

  void _applyMyTasksCalendarWindow(DateTime month) {
    final start = DateTime.utc(month.year, month.month);
    final from = DateTime.utc(start.year, start.month, 1);
    final to = DateTime.utc(start.year, start.month + 1, 0);
    setState(() => _myTasksCalendarMonth = start);
    _loadMyTasksPresentation(
      TaskPresentation.calendar,
      TaskDateRangeWindow(from: from, to: to),
    );
  }

  void _changeMyTasksCalendarMonth(DateTime month) =>
      _applyMyTasksCalendarWindow(month);

  void _applyMyTasksTimelineWindow(DateTime month) {
    final start = DateTime.utc(month.year, month.month);
    final from = DateTime.utc(start.year, start.month, 1);
    final to = DateTime.utc(start.year, start.month + 1, 0);
    setState(() => _myTasksTimelineMonth = start);
    _loadMyTasksPresentation(
      TaskPresentation.timeline,
      TaskDateRangeWindow(from: from, to: to),
    );
  }

  void _changeMyTasksTimelineMonth(DateTime month) =>
      _applyMyTasksTimelineWindow(month);

  TaskWorkloadWindow _myTasksWorkloadWindowFor(DateTime month) =>
      TaskWorkloadWindow(
        from: DateTime.utc(month.year, month.month, 1),
        to: DateTime.utc(month.year, month.month + 1, 0),
        unit: TaskWorkloadUnit.day,
      );

  void _applyMyTasksWorkloadWindow(DateTime month) {
    final start = DateTime.utc(month.year, month.month);
    setState(() => _myTasksWorkloadMonth = start);
    _loadMyTasksPresentation(
      TaskPresentation.workload,
      _myTasksWorkloadWindowFor(start),
    );
  }

  void _changeMyTasksWorkloadMonth(DateTime month) =>
      _applyMyTasksWorkloadWindow(month);

  Future<void> _loadMyTasksPresentation(
    TaskPresentation presentation,
    TaskWindow window, {
    bool conditional = false,
  }) async {
    if (!conditional) {
      setState(() {
        _myTasksPresentationLoading = true;
        _myTasksPresentationError = null;
      });
    }
    final privacy = _privacyGeneration;
    final result = await _tasks.myTasksPresentation(
      presentation: presentation,
      window: window,
      etag: conditional ? _myTasksPresentationEtag : null,
    );
    if (!_isCurrentPrivacy(privacy) ||
        !_showMyTasks ||
        _myTasksPresentation != presentation) {
      return;
    }
    if (result is TaskApiUnavailable<TaskCrossBoardPage>) {
      _evict(result.message);
      return;
    }
    if (result is TaskApiNotModified<TaskCrossBoardPage>) {
      if (mounted) setState(() => _myTasksPresentationLoading = false);
      return;
    }
    if (result is! TaskApiSuccess<TaskCrossBoardPage>) {
      if (!conditional) {
        setState(() {
          _myTasksPresentationError = result.message;
          _myTasksPresentationLoading = false;
        });
      }
      return;
    }
    final page = result.data;
    setState(() {
      _myTasksCalendarItems = presentation == TaskPresentation.calendar
          ? page.items
          : _myTasksCalendarItems;
      _myTasksTimelineItems = presentation == TaskPresentation.timeline
          ? page.items
          : _myTasksTimelineItems;
      _myTasksWorkloadRows = presentation == TaskPresentation.workload
          ? page.workloadRows
          : _myTasksWorkloadRows;
      _myTasksPresentationOmitted = page.omitted;
      _myTasksPresentationEtag = page.etag;
      _myTasksPresentationLoading = false;
      _myTasksPresentationError = null;
    });
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
    final request = NameEntryRequest(
      title: 'Add task',
      label: 'Task title',
      submitLabel: 'Add task',
    );
    setState(() => _nameRequest = request);
    final title = await request.future;
    if (mounted) setState(() => _nameRequest = null);
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
      _unavailableMessage = message;
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
      _myTasksPresentation = TaskPresentation.table;
      _myTasksCalendarItems = const [];
      _myTasksTimelineItems = const [];
      _myTasksWorkloadRows = const [];
      _myTasksPresentationOmitted = TaskOmitted.zero;
      _myTasksPresentationError = null;
      _myTasksPresentationEtag = null;
      _total = 0;
      _statusSummaries = const [];
      _groupCounts = const [];
      _omitted = TaskOmitted.zero;
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
                  ? Align(
                      alignment: Alignment.topLeft,
                      // The refusal sentence is the entire payload of this
                      // screen — it does not sit inside a Center the way a
                      // short status message elsewhere might. Center both
                      // starved it of the left/right margin every sibling
                      // screen gives its content (directory_mobile ~16-20,
                      // detail_mobile 20, board_mobile 16) AND stranded it
                      // in the middle of the remaining height, leaving a
                      // void between the chip row above and the sentence.
                      // Top-left, padded like those siblings, puts it where
                      // a user's eye actually lands: directly under the
                      // chip row, flush with everything else on this
                      // screen.
                      child: Padding(
                        padding: const EdgeInsets.fromLTRB(20, 16, 16, 16),
                        child: Text(
                          _unavailableMessage ??
                              'This task board is unavailable.',
                        ),
                      ),
                    )
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

  Widget _content(bool mobile, bool wide) => _nameRequest != null
      ? NameEntryPanel(
          request: _nameRequest!,
          fieldKey: const ValueKey('task-create-title'),
        )
      : _creating
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
      ? _myTasksContent()
      : _showDirectory || _board == null
      ? _directoryOnly()
      : _presentationBody(mobile, wide);

  /// My Tasks (task-14 brief): Table stays the owner-filtered list it
  /// always was; Calendar, Timeline and Workload open the SAME three
  /// presentations a board offers, but across every board the current view
  /// lets this viewer open — READ-ONLY throughout (`readOnly: true` on
  /// every view below removes drag surfaces from the tree entirely, it does
  /// not merely disable them).
  static const _myTasksPresentations = {
    TaskPresentation.table,
    TaskPresentation.calendar,
    TaskPresentation.timeline,
    TaskPresentation.workload,
  };

  Widget _myTasksContent() => Column(
    children: [
      TaskPresentationBar(
        presentation: _myTasksPresentation,
        kanbanField: _kanbanField,
        onPresentationChanged: _changeMyTasksPresentation,
        onKanbanFieldChanged: (_) {},
        availablePresentations: _myTasksPresentations,
      ),
      if (_myTasksPresentationError case final message?)
        MaterialBanner(
          content: Text(message),
          actions: [
            TextButton(
              onPressed: () => setState(() => _myTasksPresentationError = null),
              child: const Text('Dismiss'),
            ),
          ],
        ),
      Expanded(child: _myTasksBody()),
    ],
  );

  /// Cross-board only (task-14 brief, fix round 1): a `task.id -> board
  /// name` map so a Calendar/Timeline card can say which board it belongs
  /// to — the same `TaskMyTask.boardName` Table's `MyTasksView` already
  /// shows per row, surfaced here too rather than only there. A single
  /// board's own Calendar/Timeline (`_calendarContent()`/`_timelineContent()`)
  /// never builds this map, so its cards are unaffected.
  Map<String, String> _boardNamesByTaskId(List<TaskMyTask> items) => {
    for (final item in items) item.task.id: item.boardName,
  };

  Widget _myTasksBody() => switch (_myTasksPresentation) {
    TaskPresentation.calendar => _myTasksPresentationLoading &&
            _myTasksCalendarItems.isEmpty
        ? const Center(child: CircularProgressIndicator())
        : TaskCalendarView(
            month: _myTasksCalendarMonth,
            rows: _myTasksCalendarItems
                .map((item) => item.task)
                .toList(growable: false),
            omitted: _myTasksPresentationOmitted,
            onMonthChanged: _changeMyTasksCalendarMonth,
            readOnly: true,
            boardNamesByTaskId: _boardNamesByTaskId(_myTasksCalendarItems),
          ),
    TaskPresentation.timeline => _myTasksPresentationLoading &&
            _myTasksTimelineItems.isEmpty
        ? const Center(child: CircularProgressIndicator())
        : TaskTimelineView(
            month: _myTasksTimelineMonth,
            rows: _myTasksTimelineItems
                .map((item) => item.task)
                .toList(growable: false),
            omitted: _myTasksPresentationOmitted,
            onMonthChanged: _changeMyTasksTimelineMonth,
            readOnly: true,
            boardNamesByTaskId: _boardNamesByTaskId(_myTasksTimelineItems),
          ),
    TaskPresentation.workload => TaskWorkloadView(
        month: _myTasksWorkloadMonth,
        window: _myTasksWorkloadWindowFor(_myTasksWorkloadMonth),
        rows: _myTasksWorkloadRows,
        onMonthChanged: _changeMyTasksWorkloadMonth,
      ),
    TaskPresentation.table || TaskPresentation.kanban => MyTasksView(
        page: _myTasks,
        loading: _myTasksLoading,
        error: _myTasksError,
        onRetry: _loadMyTasks,
        onOpen: _openMyTask,
        onLoadMore: _loadMoreMyTasks,
        loadingMore: _myTasksLoadingMore,
      ),
  };

  /// A generic switch on `TaskPresentation` (Ruling 1, progress.md) — not a
  /// Kanban-specific branch off `_content`. Tasks 11-13 each added their own
  /// case here for Calendar/Timeline/Workload rather than restructuring this
  /// dispatcher; every enum value now has one. `TaskPresentation` itself has
  /// no `unknown` case (see `_presentationFromWire`) — an unrecognised wire
  /// value degrades to `table` before it ever reaches this switch, so this
  /// one stays exhaustive rather than needing a defensive fallback arm.
  /// Kanban/Calendar/Timeline need a task's detail panel reachable exactly
  /// the same way Table's `_workspace()` already makes one reachable
  /// (whole-branch review finding 2) — a side panel on a wide-enough
  /// desktop pane, otherwise the SAME mobile full-screen route
  /// `_selectTaskId` already pushes for every presentation regardless of
  /// which one is active (see its own `MediaQuery.sizeOf(context).width <
  /// 700` branch). On mobile that push is the whole story, so these three
  /// content builders are used unwrapped there — wrapping them too would
  /// leave a STALE in-place fullscreen panel behind after the pushed
  /// route is popped (its own `onClose` only pops the route, it never
  /// resets `_detailOpen`), exactly mirroring how `_mobileTableContent()`
  /// also never wraps itself in anything detail-panel-related. Workload
  /// rows are per-owner aggregates, not tasks, so it is deliberately left
  /// alone here — there is nothing on it to open.
  Widget _presentationBody(bool mobile, bool wide) =>
      switch (_configuration.presentation) {
        TaskPresentation.kanban =>
          mobile ? _kanbanContent() : _withDetailPanel(_kanbanContent()),
        TaskPresentation.calendar =>
          mobile ? _calendarContent() : _withDetailPanel(_calendarContent()),
        TaskPresentation.timeline =>
          mobile ? _timelineContent() : _withDetailPanel(_timelineContent()),
        TaskPresentation.workload => _workloadContent(),
        TaskPresentation.table => mobile
            ? _mobileTableContent()
            : _workspace(wide),
      };

  /// Adds the same side-by-side (or narrow-pane fullscreen swap) detail
  /// panel treatment `_workspace()` gives Table, to any other presentation
  /// [content]. A standalone `LayoutBuilder`, not a refactor of
  /// `_workspace()` itself: that method also has to reserve room for the
  /// board directory and pass `TaskTable` an exact `paneWidth`, neither of
  /// which any of these three presentations need, and folding this into
  /// that one risks changing ITS width math (Task 15's own floor) for a
  /// change this finding never asked for.
  Widget _withDetailPanel(Widget content) => LayoutBuilder(
    builder: (context, constraints) {
      // Same 1100 threshold `_workspace()` uses, for the same reason: the
      // detail panel's default 390px (+16px divider) needs to leave a
      // content pane wide enough that `TaskPresentationBar`/
      // `TaskViewsBar` don't wrap onto multiple lines.
      final showDetail = _detailOpen && constraints.maxWidth >= 1100;
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
          Expanded(child: content),
          if (showDetail) ...[
            TaskDetailDivider(
              onResize: (delta) => setState(
                () => _detailWidth = (_detailWidth + delta).clamp(300, 640),
              ),
            ),
            SizedBox(
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
          ],
        ],
      );
    },
  );

  Widget _mobileTableContent() => _boardScaffold(
    view: TaskMobileList(
      board: _board!,
      rows: _rows,
      onSelected: _selectTask,
      onAddTask: _board!.can('create_task') ? _addTaskToGroup : null,
    ),
    trailing: _pagination(),
  );

  Widget _kanbanContent() {
    final board = _board!;
    return _boardScaffold(
      view: TaskKanbanView(
        board: board,
        field: _kanbanField,
        buckets: TaskKanbanBuckets.forField(_kanbanField, board, _owners),
        rows: _rows,
        groupCounts: _groupCounts,
        owners: _owners,
        hiddenColumns: _configuration.hiddenColumns,
        onMove: _kanbanMove,
        onOpen: _selectTask,
      ),
    );
  }

  Widget _calendarContent() => _boardScaffold(
    view: TaskCalendarView(
      month: _calendarMonth,
      rows: _rows,
      omitted: _omitted,
      onMove: _calendarMove,
      onMonthChanged: _changeCalendarMonth,
      onOpen: _selectTask,
    ),
  );

  Widget _timelineContent() => _boardScaffold(
    view: TaskTimelineView(
      month: _timelineMonth,
      rows: _rows,
      omitted: _omitted,
      onMove: _timelineMove,
      onMonthChanged: _changeTimelineMonth,
      onOpen: _selectTask,
    ),
  );

  Widget _workloadContent() => _boardScaffold(
    view: TaskWorkloadView(
      month: _workloadMonth,
      window: _workloadWindowFor(_workloadMonth),
      rows: _workloadRows,
      onMonthChanged: _changeWorkloadMonth,
    ),
  );

  /// Reserved for a visible `trailing` (in practice, only `_pagination()`'s
  /// "Page X of Y / Previous / Next" row) whenever [minViewHeight] is
  /// real-floor-enforced below — see that doc for why. `_pagination()`'s
  /// height never varies with content or width (a fixed row, never a
  /// wrapping one), so a single conservative constant safely covers it
  /// whether or not it is actually showing (`_lastPage <= 1` renders a
  /// `SizedBox.shrink()` instead), rather than needing to be measured.
  static const _paginationReserve = 56.0;

  /// Every presentation's content area: the chrome (`_boardControls()`,
  /// plus an optional filters panel), and the presentation's own [view].
  ///
  /// [minViewHeight], when given, is a REAL floor [view] cannot be pushed
  /// under (whole-branch review finding 3 — an earlier version of this
  /// method claimed exactly that but did not deliver it: `Expanded` gives
  /// its child a TIGHT constraint, `minHeight == maxHeight == whatever
  /// space is left`, and `BoxConstraints.enforce` can only ever CLAMP a
  /// `ConstrainedBox`'s requested minimum DOWN into that already-decided
  /// amount, never raise it — `(280).clamp(H, H) == H` for any `H`,
  /// including one under 280. No amount of nesting `ConstrainedBox` around
  /// [view] can fix that; the floor has to come from the OTHER side:
  /// capping how much of the total available height the CHROME above is
  /// allowed to consume, so whatever is "left" for [view] can never be
  /// less than [minViewHeight] in the first place. The chrome is wrapped
  /// in a `SingleChildScrollView` so, capped, it becomes scrollable rather
  /// than clipped or (worse) still somehow stealing the table's floor —
  /// turning the earlier regression this guards against (Task 15: three
  /// wrapped chrome lines squeezed `TaskTable` to a 64px viewport that
  /// silently dropped rows) into a visible, scrollable chrome instead,
  /// exactly the trade Ruling 57 (progress.md) meant to accept but, before
  /// this fix, did not actually get. In the ordinary case — chrome's
  /// natural height comfortably under the budget — `SingleChildScrollView`
  /// shrink-wraps to it exactly as a plain `Column` child already did, so
  /// every existing desktop golden is unaffected pixel-for-pixel.
  /// `_workspace()` is the one caller that passes it, because it is the
  /// one place this was ever actually reported broken
  /// (`board_desktop.png`/`conflict_desktop.png`, a wide window with a
  /// narrow content pane).
  ///
  /// Kanban/Calendar/Timeline/Workload deliberately do NOT get a floor
  /// here: measured, a floor on top of THIS chrome at a truly narrow 375px
  /// screen — where three bars plus Kanban's extra "Group by" row can
  /// legitimately need more height than a short phone viewport has at all
  /// — would make the now-capped chrome scroll on every phone-sized
  /// screen, not just a squeezed desktop pane. `Expanded` alone (their
  /// current, unfloored behaviour) never errors that way; it just clamps
  /// to 0, exactly the pre-existing behaviour every one of these four
  /// views already had before this fix touched anything, and no report
  /// exists of it dropping data the way `TaskTable` did. Widening this
  /// floor to all five is a real, separate piece of work (each view's own
  /// true minimum content size, and where the extra height should come
  /// from on a short phone), not a same-pattern copy-paste — flagged
  /// rather than guessed at here.
  Widget _boardScaffold({
    required Widget view,
    Widget? trailing,
    double? minViewHeight,
    double? paneWidth,
  }) {
    final chrome = Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        _boardControls(paneWidth: paneWidth),
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
      ],
    );
    if (minViewHeight == null) {
      return Column(
        children: [chrome, Expanded(child: view), ?trailing],
      );
    }
    return LayoutBuilder(
      builder: (context, constraints) {
        final chromeBudget = constraints.maxHeight.isFinite
            ? (constraints.maxHeight - minViewHeight - _paginationReserve)
                  .clamp(0.0, double.infinity)
            : double.infinity;
        return Column(
          children: [
            ConstrainedBox(
              constraints: BoxConstraints(maxHeight: chromeBudget),
              child: SingleChildScrollView(child: chrome),
            ),
            // No ConstrainedBox(minHeight:) around [view] here — removed
            // (coordinator follow-up B on the whole-branch review's own
            // fix wave). This method's own doc above already explained
            // why nesting one around a tight-fitted Expanded child cannot
            // work; leaving a provably-inert widget in the tree anyway is
            // the exact pattern that misled two prior reviews into
            // believing the OLD version of this method enforced a floor
            // it did not. The real floor is the chromeBudget cap above:
            // by construction, whatever height reaches Expanded here can
            // never be less than minViewHeight.
            Expanded(child: view),
            ?trailing,
          ],
        );
      },
    );
  }

  /// Switches the active view's presentation. Kanban additionally needs a
  /// `window` (`bucket_field` + the current field's buckets) and a matching
  /// `group_by`, so `meta.group_counts` — which Kanban headers read their
  /// complete accessible total from — is computed for the same field the
  /// columns bucket by, not the board's default grouping. Calendar needs a
  /// `window` too (`{from, to}` bounding the visible month) but no
  /// `group_by` — `TaskDateRangeWindow` (schema:2) is what carries it.
  void _changePresentation(TaskPresentation presentation) {
    if (presentation == TaskPresentation.kanban) {
      _applyKanbanField(_kanbanField);
      return;
    }
    if (presentation == TaskPresentation.calendar) {
      _applyCalendarWindow(_calendarMonth);
      return;
    }
    if (presentation == TaskPresentation.timeline) {
      _applyTimelineWindow(_timelineMonth);
      return;
    }
    if (presentation == TaskPresentation.workload) {
      _applyWorkloadWindow(_workloadMonth);
      return;
    }
    _changeConfiguration(
      _configuration.copyWith(presentation: presentation, window: null),
    );
  }

  /// Re-windows Calendar to [month] — called both when the tab is first
  /// selected and whenever `TaskCalendarView` asks to page to another month.
  /// The server bounds any calendar window at 400 days and refuses wider
  /// (task-11 brief); a single month is always far under that, so there is
  /// nothing to pre-validate here.
  void _applyCalendarWindow(DateTime month) {
    final start = DateTime.utc(month.year, month.month);
    final from = DateTime.utc(start.year, start.month, 1);
    final to = DateTime.utc(start.year, start.month + 1, 0);
    setState(() => _calendarMonth = start);
    _changeConfiguration(
      _configuration.copyWith(
        presentation: TaskPresentation.calendar,
        window: TaskDateRangeWindow(from: from, to: to),
      ),
    );
  }

  void _changeCalendarMonth(DateTime month) => _applyCalendarWindow(month);

  /// Re-windows Timeline to [month] — same shape as [_applyCalendarWindow],
  /// sharing `TaskDateRangeWindow` (schema:2) with Calendar (task-12 brief).
  void _applyTimelineWindow(DateTime month) {
    final start = DateTime.utc(month.year, month.month);
    final from = DateTime.utc(start.year, start.month, 1);
    final to = DateTime.utc(start.year, start.month + 1, 0);
    setState(() => _timelineMonth = start);
    _changeConfiguration(
      _configuration.copyWith(
        presentation: TaskPresentation.timeline,
        window: TaskDateRangeWindow(from: from, to: to),
      ),
    );
  }

  void _changeTimelineMonth(DateTime month) => _applyTimelineWindow(month);

  /// Re-windows Workload to [month] — same month-bounded shape as
  /// [_applyCalendarWindow] / [_applyTimelineWindow], but `unit: day` always
  /// (task-13 brief scopes this view to a day axis; a week toggle is a
  /// later, additive change to this one method, not a redesign).
  void _applyWorkloadWindow(DateTime month) {
    final start = DateTime.utc(month.year, month.month);
    setState(() => _workloadMonth = start);
    _changeConfiguration(
      _configuration.copyWith(
        presentation: TaskPresentation.workload,
        window: _workloadWindowFor(start),
      ),
    );
  }

  TaskWorkloadWindow _workloadWindowFor(DateTime month) => TaskWorkloadWindow(
    from: DateTime.utc(month.year, month.month, 1),
    to: DateTime.utc(month.year, month.month + 1, 0),
    unit: TaskWorkloadUnit.day,
  );

  void _changeWorkloadMonth(DateTime month) => _applyWorkloadWindow(month);

  void _changeKanbanField(TaskKanbanField field) {
    setState(() => _kanbanField = field);
    _applyKanbanField(field);
  }

  void _applyKanbanField(TaskKanbanField field) {
    final board = _board;
    if (board == null) return;
    final buckets = TaskKanbanBuckets.forField(field, board, _owners)
        .map((bucket) => bucket.id)
        .toList(growable: false);
    _changeConfiguration(
      _configuration.copyWith(
        presentation: TaskPresentation.kanban,
        window: TaskKanbanWindow(bucketField: field.wire, buckets: buckets),
        groupBy: field.wire,
      ),
    );
  }

  /// Drags a Kanban card between columns. This performs THE WRITE THE FIELD
  /// ALREADY HAS — the exact same `PATCH tasks/{id}` core-field write
  /// `_saveCoreNow` issues for the table, with the same `expected_version`
  /// carried on `task.version`. Phase 2 adds no new write path for Kanban;
  /// a field with no write path (read-only or archived) is refused by that
  /// same endpoint, and its message — never a second, invented copy of the
  /// reason — is what this returns for `TaskKanbanView` to display.
  Future<String?> _kanbanMove(
    TaskRow task,
    TaskKanbanField field,
    String bucketId,
  ) async {
    if (_bulkLockedTaskIds.contains(task.id)) {
      return 'Finish the bulk change before editing this task.';
    }
    final current = _rows.where((row) => row.id == task.id).firstOrNull ?? task;
    final privacy = _privacyGeneration;
    final result = await _tasks.updateTask(
      current.id,
      expectedTaskVersion: current.version,
      changes: <String, Object?>{field.writeField: bucketId},
    );
    if (!_isCurrentPrivacy(privacy)) return null;
    if (result is TaskApiSuccess<TaskRow>) {
      _putRow(_mergeServerRow(current, result.data));
      return null;
    }
    if (result is TaskApiUnavailable<TaskRow>) {
      _evict(result.message);
      return result.message;
    }
    if (result is TaskApiConflictResult<TaskRow>) setState(() => _conflict = true);
    return result.message ?? 'The change could not be saved.';
  }

  /// Drags a Calendar card to another day. THE PHASE 1 DUE-DATE WRITE — the
  /// same `PATCH tasks/{id}` core-field write `_saveCoreNow`/`_kanbanMove`
  /// already issue, carrying `task.version` as `expected_version`. Phase 2
  /// adds no new write path for Calendar; [day] is only ever the calendar
  /// date the card was dropped on, formatted the same `YYYY-MM-DD` way
  /// `_Date` in `task_table.dart` already writes `due_on` as.
  Future<String?> _calendarMove(TaskRow task, DateTime day) async {
    if (_bulkLockedTaskIds.contains(task.id)) {
      return 'Finish the bulk change before editing this task.';
    }
    final current = _rows.where((row) => row.id == task.id).firstOrNull ?? task;
    final privacy = _privacyGeneration;
    final result = await _tasks.updateTask(
      current.id,
      expectedTaskVersion: current.version,
      changes: <String, Object?>{'due_on': _wireDate(day)},
    );
    if (!_isCurrentPrivacy(privacy)) return null;
    if (result is TaskApiSuccess<TaskRow>) {
      _putRow(_mergeServerRow(current, result.data));
      return null;
    }
    if (result is TaskApiUnavailable<TaskRow>) {
      _evict(result.message);
      return result.message;
    }
    if (result is TaskApiConflictResult<TaskRow>) setState(() => _conflict = true);
    return result.message ?? 'The change could not be saved.';
  }

  /// Drags a Timeline bar/marker/edge. THE PHASE 1 CORE-FIELD WRITE — same
  /// `PATCH tasks/{id}` core-field write `_saveCoreNow`/`_kanbanMove`/
  /// `_calendarMove` already issue, carrying `task.version` as
  /// `expected_version`. An edge drag sends only the one field being moved
  /// (the other stays `null` — omitted from `changes` entirely, not sent as
  /// unchanged); a whole-bar or marker drag sends both in the SAME PATCH
  /// call — one command, one version, exactly as task-12's brief requires.
  /// This never reorders or swaps what it is told to write: a `start_on`
  /// that lands after `due_on` is refused by the server, and
  /// `TaskTimelineView` shows that refusal sentence verbatim.
  Future<String?> _timelineMove(
    TaskRow task, {
    DateTime? startOn,
    DateTime? dueOn,
  }) async {
    if (_bulkLockedTaskIds.contains(task.id)) {
      return 'Finish the bulk change before editing this task.';
    }
    final current = _rows.where((row) => row.id == task.id).firstOrNull ?? task;
    final privacy = _privacyGeneration;
    final result = await _tasks.updateTask(
      current.id,
      expectedTaskVersion: current.version,
      changes: <String, Object?>{
        if (startOn != null) 'start_on': _wireDate(startOn),
        if (dueOn != null) 'due_on': _wireDate(dueOn),
      },
    );
    if (!_isCurrentPrivacy(privacy)) return null;
    if (result is TaskApiSuccess<TaskRow>) {
      _putRow(_mergeServerRow(current, result.data));
      return null;
    }
    if (result is TaskApiUnavailable<TaskRow>) {
      _evict(result.message);
      return result.message;
    }
    if (result is TaskApiConflictResult<TaskRow>) setState(() => _conflict = true);
    return result.message ?? 'The change could not be saved.';
  }

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
                  onUnavailable: _evict,
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

  /// The three chrome strips above every presentation — saved views, the
  /// presentation switcher, and this totals/filters/actions row — wrap onto
  /// more lines when THEIR OWN space is narrow, never merely because the
  /// window is. `LayoutBuilder` measures the real pane once here and passes
  /// it down as `width`, rather than each bar reading `MediaQuery` for
  /// itself (see `TaskPresentationBar.width` for the full account of why
  /// that changed and how the starvation risk it used to dodge is closed
  /// instead — a `ConstrainedBox` floor around `TaskTable` in
  /// `_workspace()`).
  ///
  /// This row specifically had NO narrow-width branch at all before Task
  /// 15 — it always scrolled — which is what actually clipped in
  /// `board_desktop.png`/`conflict_desktop.png` (1440px window, ~823px
  /// pane: `MediaQuery` would have said "wide" here too, so even restoring
  /// it to the other two bars' old pattern would not have fixed this one).
  /// [paneWidth], when given, is what `TaskViewsBar`/`TaskPresentationBar`/
  /// `_actionsRow` measure "narrow" against, instead of `MediaQuery` (Task
  /// 15 — see `TaskPresentationBar.width` for the full account). `null`
  /// (every caller except `_workspace()`) falls back to `MediaQuery`,
  /// which is the CORRECT width for them: none of those callers sit beside
  /// a sibling pane that can squeeze this column's real width below the
  /// window's. Deliberately NOT this method's own `LayoutBuilder` — an
  /// earlier version wrapped this whole method in one, and a widget-level
  /// test relying on a fixed, short `pump()` count (not `pumpAndSettle()`)
  /// to land inside a specific debounce/poll race window started failing:
  /// one more `LayoutBuilder` in the tree is one more build pass some of
  /// that fixed pump budget goes to, silently changing how much real
  /// (virtual) time had actually elapsed by the same pump count. Reading
  /// [paneWidth] from `_workspace()`'s OWN, pre-existing `LayoutBuilder`
  /// instead avoids adding a build boundary at all.
  Widget _boardControls({double? paneWidth}) {
    final statusNames = <String?, String>{
      for (final status in _board?.statuses ?? const <TaskStatusOption>[])
        status.id: status.name,
      null: 'No status',
    };
    // This State's own `context`, not a new `Builder`/`LayoutBuilder` — see
    // the doc comment above for why an extra build boundary here is not
    // free. `_boardControls()` only ever runs synchronously inside this
    // State's own `build()` call tree, so `context` is always valid here.
    final width = paneWidth ?? MediaQuery.sizeOf(context).width;
    return Column(
      key: const ValueKey('task-board-controls'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_views.isNotEmpty)
          TaskViewsBar(
            width: width,
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
        TaskPresentationBar(
          width: width,
          presentation: _configuration.presentation,
          kanbanField: _kanbanField,
          onPresentationChanged: _changePresentation,
          onKanbanFieldChanged: _changeKanbanField,
        ),
        _actionsRow(width, statusNames),
      ],
    );
  }

  /// This row is chrome (a task count, status chips, and action buttons),
  /// not data, so it always wraps rather than ever clipping behind an
  /// un-affordanced horizontal scroll (Task 15) — unconditionally, no
  /// narrow-width breakpoint to calibrate: `Wrap` already renders as one
  /// line whenever its content fits the space it is actually given, at
  /// ANY pane width, which is exactly what a breakpoint here was trying
  /// (and, at the reported pane width of ~823px against a 700px
  /// breakpoint tuned for `TaskPresentationBar`'s narrower five-tab
  /// content, failing) to approximate.
  ///
  /// `board.can('update')`/`manage_structure`/`manage_members`/
  /// `duplicate`/`import`/`export`/`archive_manifest` — the five secondary
  /// actions those permissions unlock (Admin, Duplicate, Transfer) —
  /// collapse into ONE overflow button rather than up to three separate
  /// `OutlinedButton`s. Not primarily a space-saving trick: it turns out to
  /// be one (removing up to three wide buttons is what keeps this row's
  /// wrapped height from starving the view below it on a 375px phone with
  /// every permission granted — the same board fixture these goldens
  /// already use), but the actual reason is that these three are
  /// board-administration actions a reader reaches for far less often than
  /// Filters, which stays its own always-visible button.
  /// Below this, the per-status chips are dropped from this row entirely —
  /// each is a legitimate summary a reader can still get from the status
  /// column or `TaskFiltersPanel`, and at a genuinely narrow width they
  /// were the single biggest, most variable (0-N chips) contributor to how
  /// many lines this row wraps onto — see `_actionsRow`.
  static const _statusChipsBreakpoint = 700.0;

  Widget _actionsRow(double width, Map<String?, String> statusNames) {
    final board = _board!;
    final secondaryActions = <PopupMenuEntry<VoidCallback>>[
      if (board.can('update') ||
          board.can('archive') ||
          board.can('restore') ||
          board.can('manage_structure') ||
          board.can('manage_members'))
        PopupMenuItem<VoidCallback>(
          value: _openAdministration,
          child: const ListTile(
            leading: Icon(Icons.settings_outlined),
            title: Text('Admin'),
          ),
        ),
      if (board.can('duplicate'))
        PopupMenuItem<VoidCallback>(
          value: _openDuplicate,
          child: const ListTile(
            leading: Icon(Icons.copy_all_outlined),
            title: Text('Duplicate'),
          ),
        ),
      if (_activeViewId != null &&
          (board.can('import') ||
              board.can('export') ||
              board.can('archive_manifest')))
        PopupMenuItem<VoidCallback>(
          value: _openTransfer,
          child: const ListTile(
            leading: Icon(Icons.import_export),
            title: Text('Transfer'),
          ),
        ),
    ];
    final items = <Widget>[
      Text('$_total ${_total == 1 ? 'task' : 'tasks'}'),
      if (width >= _statusChipsBreakpoint)
        for (final summary in _statusSummaries)
          Chip(
            visualDensity: VisualDensity.compact,
            label: Text(
              '${statusNames[summary.statusOptionId] ?? 'Status'} ${summary.count}',
            ),
          ),
      OutlinedButton.icon(
        onPressed: () => setState(() => _showFilters = !_showFilters),
        icon: const Icon(Icons.filter_list),
        label: const Text('Filters'),
      ),
      if (secondaryActions.isNotEmpty)
        PopupMenuButton<VoidCallback>(
          key: const ValueKey('board-secondary-actions'),
          tooltip: 'More board actions',
          icon: const Icon(Icons.more_horiz),
          itemBuilder: (_) => secondaryActions,
          onSelected: (action) => action(),
        ),
      if (_viewDirty) const Text('Unsaved view changes'),
    ];
    return Material(
      color: Theme.of(context).colorScheme.surfaceContainerLowest,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        child: Wrap(
          spacing: 8,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: items,
        ),
      ),
    );
  }

  Widget _workspace(bool wide) => LayoutBuilder(
    builder: (context, constraints) {
      final showDirectory = wide && constraints.maxWidth >= 1100;
      // Task 15: was 800. Below ~1100, reserving the detail panel's default
      // 390px (+16px divider) leaves a content pane under 700px — exactly
      // the width `TaskPresentationBar`/`TaskViewsBar` treat as narrow and
      // wrap onto multiple lines. Measured: at a 394px pane (an 800px
      // window, `_detailOpen`'s true-by-default value reserving an empty
      // detail panel for nothing selected), that wrapped chrome starved
      // `TaskTable` enough to silently lose rows out of an EDITING draft —
      // the exact regression this file's own `ConstrainedBox` floor exists
      // to guard against, just not something a fixed-height floor alone
      // can fix: nothing can promise a table a floor the SIBLING chrome
      // above it has already spent. 1100 matches `showDirectory`'s own bar
      // (a 3-pane-or-detail layout needs the same real room either way)
      // and keeps a comfortable margin above 700 for the default 390px
      // detail width; a detail panel manually resized toward its 640px
      // maximum can still re-enter the same danger zone at the widths this
      // guards — a real, narrower residual case, flagged rather than
      // silently accepted (task-15 report).
      final showDetail = _detailOpen && constraints.maxWidth >= 1100;
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
      // The actual width the middle column — chrome and `TaskTable` alike —
      // has to work with, once the board directory and detail panel (each
      // real siblings in the `Row` below, not merely visual insets) take
      // their share of `constraints.maxWidth`. Board_desktop.png/
      // conflict_desktop.png (1440px window, ~823px pane once the
      // default-open detail panel reserves ~406px) is exactly the gap
      // between this number and `MediaQuery`'s own width — see
      // `TaskPresentationBar.width` (Task 15).
      final contentPaneWidth =
          constraints.maxWidth -
          (showDirectory ? 211 : 0) -
          (showDetail ? _detailWidth + 16 : 0);
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
            child: _boardScaffold(
              view: TaskTable(
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
              trailing: _pagination(),
              minViewHeight: 280,
              paneWidth: contentPaneWidth,
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

/// Same `YYYY-MM-DD` shape `_dateOnly` in `task_models.dart` writes and
/// `_Date` in `task_table.dart` submits — kept local rather than exported
/// since only this file's Calendar and Timeline drag writes need it
/// client-side.
String _wireDate(DateTime date) =>
    '${date.year.toString().padLeft(4, '0')}-'
    '${date.month.toString().padLeft(2, '0')}-'
    '${date.day.toString().padLeft(2, '0')}';

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
