import 'files_io.dart' if (dart.library.js_interop) 'files_web.dart' as impl;

/// Getting a file out of the browser and back into it.
///
/// Behind the same conditional import as TokenStore, and for the same reason:
/// `package:web` will not compile off the web, which takes `flutter test` down
/// with it. The stubs make the whole app testable without pretending the
/// non-web build can do these things.
class Files {
  /// Offer [content] to the user as a download named [filename].
  static void download(String filename, List<int> content) =>
      impl.download(filename, content);

  /// Remove the query string from the address bar, on web. A no-op elsewhere.
  static void clearQuery() => impl.clearQuery();

  /// Ask for a file and return its text, or null if they cancelled.
  ///
  /// Uses the browser's own file input rather than a picker package — one
  /// `<input type="file">` against a dependency, its transitive tree, and its
  /// permission plumbing.
  static Future<PickedFile?> pickText() => impl.pickText();
}

class PickedFile {
  const PickedFile(this.name, this.text);

  final String name;
  final String text;
}
