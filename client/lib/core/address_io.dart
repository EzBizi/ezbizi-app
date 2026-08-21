/// Stubs for every non-web target. See address.dart.
String currentPath() => '/';

void setPath(String path, {required bool replace}) {}

void Function() listen(void Function(String path) onPop) => () {};
