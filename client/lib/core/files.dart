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

  /// Ask for an image and return its bytes.
  static Future<PickedBytes?> pickImage() => impl.pickImage();

  /// Whether [takePhoto] opens a camera here: the browser on a phone, where
  /// `<input capture>` hands the pick to the camera, and the Android and iOS
  /// apps, through image_picker. Desktop browsers ignore `capture` and the
  /// desktop apps have no camera flow, so both are false and offer the file
  /// picker alone.
  static bool get canTakePhoto => impl.canTakePhoto;

  /// A photo straight from the phone's camera, or null if they cancelled.
  static Future<PickedBytes?> takePhoto() => impl.takePhoto();

  /// Ask for any file the OS picker will give us, as bytes.
  ///
  /// Papers on a period, visit or payment are PDFs as often as pictures, and
  /// reading them as text would corrupt them the same way a photo would.
  static Future<PickedBytes?> pickBytes({String accept = ''}) =>
      impl.pickBytes(accept: accept);

  /// A URL the browser can play or show, for bytes we already hold.
  ///
  /// Web only, and null everywhere else. On web this is an object URL over a
  /// Blob — the same mechanism [download] already uses — so a video the person
  /// just picked can be played back BEFORE it is uploaded anywhere. Nothing
  /// leaves the machine to make this work, which is the point: the file is
  /// being reviewed precisely because nobody is sure it is the right one yet.
  ///
  /// Off the web it answers null and the caller shows what it knows instead.
  /// A native player would need the bytes written to a real file and a
  /// different controller, which is a platform's worth of work for a preview
  /// the desktop and mobile builds can live without today.
  static String? previewUrl(List<int> bytes, String mime) =>
      impl.previewUrl(bytes, mime);

  /// Hand back a URL from [previewUrl]. Blobs are held until revoked, so a
  /// composer that picked ten videos would otherwise keep all ten in memory.
  static void releasePreviewUrl(String url) => impl.releasePreviewUrl(url);
}

class PickedFile {
  const PickedFile(this.name, this.text);

  final String name;
  final String text;
}

class PickedBytes {
  const PickedBytes(this.name, this.bytes);

  final String name;
  final List<int> bytes;
}
