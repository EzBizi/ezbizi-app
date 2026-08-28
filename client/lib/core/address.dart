import 'address_io.dart'
    if (dart.library.js_interop) 'address_web.dart'
    as impl;

/// A canonical Tasks route before it has been authorized. The shell may retain
/// these opaque IDs for navigation, but only Tasks turns them into displayed
/// board/task state after its own access-checked fetch succeeds.
final class TaskAddressIntent {
  const TaskAddressIntent({
    required this.boardId,
    required this.taskId,
    this.navigationEpoch = 0,
  });

  final String boardId;
  final String taskId;

  /// Distinguishes repeated foreground navigation to the same canonical URL.
  /// It is deliberately absent from [Address.taskPath]: browser history names
  /// the record, while this process-local value asks an already-open Tasks
  /// screen to perform its authorized reads again.
  final int navigationEpoch;

  @override
  bool operator ==(Object other) =>
      other is TaskAddressIntent &&
      other.boardId == boardId &&
      other.taskId == taskId &&
      other.navigationEpoch == navigationEpoch;

  @override
  int get hashCode => Object.hash(boardId, taskId, navigationEpoch);
}

/// Where the signed-in shell is, as a URL path.
///
/// nginx already serves `index.html` for unknown paths so a refresh of
/// `/marketing` does not 404 (see `deploy/nginx-app.ezbizi.com.conf`). The
/// shell used to keep the selection only in memory, so that same refresh
/// opened Dashboard.
///
/// The address bar is the memory: tapping Marketing writes `/marketing`,
/// and opening that URL selects Marketing. Back and forward move the
/// selection because they change the path.
class Address {
  Address({
    this.path = '/',
    void Function(String path, {required bool replace})? write,
  }) : _write = write;

  String path;
  final void Function(String path, {required bool replace})? _write;
  final List<void Function(String path)> _listeners = [];
  void Function()? _unbind;

  /// The live address bar on web; an in-memory path everywhere else.
  factory Address.platform() {
    final bar = Address(
      path: impl.currentPath(),
      write: (path, {required replace}) => impl.setPath(path, replace: replace),
    );
    bar._unbind = impl.listen(bar.popped);
    return bar;
  }

  /// First path segment, or null when the URL does not name a place in the
  /// app — the root, a reset/invite link, or a real file.
  static String? idOf(String path) {
    final uri = Uri.tryParse(path);
    if (uri == null || uri.pathSegments.isEmpty) return null;

    final id = uri.pathSegments.first;
    if (id.isEmpty || _reserved.contains(id) || id.contains('.')) return null;

    return id;
  }

  static String pathOf(String id) => '/$id';

  static String taskPath(TaskAddressIntent intent) =>
      '/tasks/boards/${intent.boardId}/tasks/${intent.taskId}';

  /// Parses only the complete canonical route. It intentionally does not
  /// validate reach or expose its IDs as UI state; that check belongs to the
  /// authorized Tasks fetch that consumes this pending intent.
  static TaskAddressIntent? taskIntent(String path) {
    final uri = Uri.tryParse(path);
    if (uri == null) return null;
    final segments = uri.pathSegments;
    if (segments.length != 5 ||
        segments[0] != 'tasks' ||
        segments[1] != 'boards' ||
        segments[3] != 'tasks' ||
        segments[2].isEmpty ||
        segments[4].isEmpty) {
      return null;
    }
    return TaskAddressIntent(boardId: segments[2], taskId: segments[4]);
  }

  void push(String next) => _set(next, replace: false);

  void replace(String next) => _set(next, replace: true);

  void _set(String next, {required bool replace}) {
    if (next == path) return;
    path = next;
    _write?.call(next, replace: replace);
  }

  /// Browser back/forward, or a test faking one.
  void popped(String next) {
    path = next;
    for (final listener in List<void Function(String)>.from(_listeners)) {
      listener(next);
    }
  }

  void Function() listen(void Function(String path) onPop) {
    _listeners.add(onPop);
    return () => _listeners.remove(onPop);
  }

  void dispose() {
    _unbind?.call();
    _unbind = null;
    _listeners.clear();
  }

  static const _reserved = {
    'reset',
    'invite',
    'signup',
    'api',
    'assets',
    'canvaskit',
    'icons',
    'fonts',
  };
}
