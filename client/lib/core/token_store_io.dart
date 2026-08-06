/// Session persistence off the web — currently a no-op.
///
/// The web build is the only one shipping (§12 release order: web, then
/// desktop, then mobile). When desktop arrives this becomes the platform
/// keychain — `flutter_secure_storage` or equivalent — because a token in a
/// plain file on disk is worse than one that does not survive a restart.
///
/// It exists now so the test runner and any future non-web target can compile
/// `token_store.dart` at all: `package:web` imports `dart:js_interop`, which is
/// unavailable on the VM, and the whole suite failed to compile the moment the
/// store was added.
String? readItem(String key) => null;

void writeItem(String key, String? value) {}
