import 'app_window.dart';

/// Stubs for every non-web target. See address.dart. A second desktop window
/// starts where it was opened (AppWindow).
String currentPath() => AppWindow.start?.path ?? '/';

void setPath(String path, {required bool replace}) {}

void Function() listen(void Function(String path) onPop) => () {};
