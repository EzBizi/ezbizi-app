import 'address_io.dart'
    if (dart.library.js_interop) 'address_web.dart'
    as impl;

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
    'join',
    'signup',
    'api',
    'assets',
    'canvaskit',
    'icons',
    'fonts',
  };
}
